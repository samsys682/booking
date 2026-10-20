<?php
// =====================================================================
// booking 后端 · 认证控制器（登录/资料/改密/敏感操作二次校验）
// 接口规范：token 鉴权 + 敏感操作二次校验；密码只进不出（password_hash）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\JwtService;
use app\service\LicenseService;
use app\service\QuotaService;
use app\service\SecurityService;
use app\service\WechatAuthService;
use think\facade\Cache;
use think\facade\Db;
use think\Request;
use think\Response;

class AuthController
{
    /**
     * 登录（账号 + 密码；连续失败保护由 security_event 留痕）
     * POST /api/v1/auth/login  {account, password}
     */
    public function login(Request $request): Response
    {
        $account = clean_input($request->post('account', ''), 50);
        $password = (string)$request->post('password', '');
        if ($account === '' || $password === '') {
            return err(400, '账号和密码不能为空');
        }

        $admin = Db::name('admin_account')->where('account', $account)->where('deleted_at', 0)->find();
        $sec = SecurityService::config();
        $ip = clean_input((string)($_SERVER['REMOTE_ADDR'] ?? ''), 45);

        // 锁定期检查：账号存在且处于锁定期直接拒绝（剩余分钟数提示）
        if ($admin && SecurityService::isLocked($admin['locked_until'] ?? null)) {
            $remain = max(1, (int)ceil((strtotime((string)$admin['locked_until']) - time()) / 60));
            (new AuditService())->log('auth.login', $account, "登录被拒（账号锁定中，剩余 {$remain} 分钟）", 0);
            return err(423, "账号已锁定，请 {$remain} 分钟后再试");
        }

        // 统一提示语：不区分「账号不存在」与「密码错误」，防账号枚举
        if (!$admin || !password_verify($password, (string)$admin['password'])) {
            (new AuditService())->log('auth.login', $account, '登录失败（账号或密码错误）', 0);
            // 失败计数与自动锁定（仅对存在的账号计数；lock_threshold=0 表示不启用锁定）
            if ($admin && $sec['lockThresh'] > 0) {
                $fail = (int)$admin['fail_count'] + 1;
                $upd = ['fail_count' => $fail];
                if ($fail >= $sec['lockThresh']) {
                    $upd['locked_until'] = date('Y-m-d H:i:s', time() + $sec['lockMinutes'] * 60);
                    $upd['fail_count'] = 0;
                    (new AuditService())->log('auth.login', $account, "连续失败 {$fail} 次，账号锁定 {$sec['lockMinutes']} 分钟", 0);
                }
                Db::name('admin_account')->where('id', $admin['id'])->update($upd);
            }
            return err(401, '账号或密码错误');
        }
        if ((int)$admin['status'] !== 1) {
            return err(403, '账号已停用，请联系管理员');
        }

        $perms = $this->permsOf((int)$admin['role_id']);

        // 异地登录提醒（不阻断，仅留痕：IP 网段变化写审计日志）
        $oldIp = (string)($admin['last_login_ip'] ?? '');
        if ($sec['remoteAlert'] && SecurityService::isRemoteLogin($oldIp, $ip)) {
            (new AuditService())->log('auth.login-remote', (string)$admin['id'], "异地登录提醒：上次 {$oldIp} → 本次 {$ip}", (int)$admin['id']);
        }

        // 更新登录痕迹（成功即清零失败计数与锁定）
        Db::name('admin_account')->where('id', $admin['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'fail_count'    => 0,
            'locked_until'  => null,
        ]);

        $token = (new JwtService())->encode([
            'aid'   => (int)$admin['id'],
            'role'  => (int)$admin['role_id'],
            'perms' => $perms,
        ]);
        (new AuditService())->log('auth.login', (string)$admin['id'], '登录成功', (int)$admin['id']);

        return ok([
            'token'   => $token,
            'admin'   => $this->profileData($admin, $perms),
            'license' => (new LicenseService())->info(),
        ], '登录成功');
    }

    /**
     * 会员端 · 发送登录验证码（白名单）
     * POST /api/v1/auth/member-send-code  {phone}
     * 验证码随机生成入 Cache（TTL 5 分钟，一次性，绑定手机号）；
     * 仅当 env security.sms_demo_mode=1 时返回固定演示码出参（生产必须关闭）
     */
    public function memberSendCode(Request $request): Response
    {
        $phone = clean_input($request->post('phone', ''), 20);
        if (!preg_match('/^1\d{10}$/', $phone)) {
            return err(400, '手机号格式不正确');
        }
        // 发送频控：同手机号 60 秒内限发 1 次（防轰炸/刷短信）
        if (Cache::store('file')->get('sms_sent_' . $phone)) {
            return err(429, '发送过于频繁，请稍后再试', null, 429);
        }
        $demo = (string)env('security.sms_demo_mode', '0') === '1';
        $code = $demo
            ? (string)env('security.sms_demo_code', '123456')
            : str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::store('file')->set('sms_code_' . $phone, $code, 300);
        Cache::store('file')->set('sms_try_' . $phone, 0, 300);
        Cache::store('file')->set('sms_sent_' . $phone, 1, 60);
        (new AuditService())->log('auth.member-code', $phone, '会员登录验证码已发送', 0);
        // demo 码出参仅在显式开启演示模式时返回，生产不出参
        return ok($demo ? ['demo_code' => $code] : [], '验证码已发送');
    }

    /**
     * 会员端 · 登录（手机号 + 验证码；不存在自动建档——微信授权即注册口径）
     * POST /api/v1/auth/member-login  {phone, code, invite_code?}
     * invite_code：推荐有礼邀请码（M+5位会员号），仅首次注册建档时绑定归属，
     * 老会员登录不更改归属（防恶意改绑）；无效邀请码静默忽略不阻断登录。
     */
    public function memberLogin(Request $request): Response
    {
        $phone = clean_input($request->post('phone', ''), 20);
        $code = clean_input($request->post('code', ''), 10);
        $inviteCode = clean_input($request->post('invite_code', ''), 10);
        if (!preg_match('/^1\d{10}$/', $phone)) {
            return err(400, '手机号格式不正确');
        }
        if ($code === '') {
            return err(401, '验证码错误');
        }
        // 校验：Cache 随机码比对（demo 模式下固定码同样写入 Cache，生命周期一致：
        // 一次性、5 次尝试作废均生效，避免固定码绕过验证码会话机制）
        $tryKey = 'sms_try_' . $phone;
        $stored = (string)Cache::store('file')->get('sms_code_' . $phone, '');
        $valid = $stored !== '' && hash_equals($stored, $code);
        if (!$valid) {
            $tries = (int)Cache::store('file')->get($tryKey, 0) + 1;
            Cache::store('file')->set($tryKey, $tries, 300);
            if ($tries >= 5) {
                Cache::store('file')->delete('sms_code_' . $phone);
            }
            (new AuditService())->log('auth.member-login', $phone, '会员登录失败（验证码错误）', 0);
            return err(401, '验证码错误');
        }
        Cache::store('file')->delete('sms_code_' . $phone);
        Cache::store('file')->delete($tryKey);

        $member = Db::name('member')->where('phone', $phone)->where('deleted_at', 0)->find();
        if (!$member) {
            // 首次登录自动建档（手机号即账号）；邀请码仅此刻可绑归属，无效则不绑
            $inviterId = 0;
            if ($inviteCode !== '' && preg_match('/^M(\d{1,6})$/', $inviteCode, $m)) {
                $inviterId = (int)$m[1];
                $inviter = Db::name('member')->where('id', $inviterId)->where('deleted_at', 0)->where('status', 1)->find();
                if (!$inviter) {
                    $inviterId = 0;
                }
            }
            $id = Db::name('member')->insertGetId([
                'name'       => '',
                'phone'      => $phone,
                'inviter_id' => $inviterId,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $member = Db::name('member')->where('id', $id)->find();
            (new AuditService())->log('auth.member-register', $phone, '会员自动注册' . ($inviterId > 0 ? '（邀请码 ' . $inviteCode . '）' : ''), 0);
        }
        if ((int)$member['status'] !== 1) {
            return err(403, '账号已停用，请联系客服');
        }

        $token = (new JwtService())->encode([
            'type' => 'member',
            'mid'  => (int)$member['id'],
        ]);
        unset($member['wechat_openid']);
        return ok(['token' => $token, 'member' => $member], '登录成功');
    }

    /**
     * 会员端 · 微信一键授权登录（小程序 wx.login code；缺口补齐批次四）
     * POST /api/v1/auth/member-wx-login  {js_code, phone_code?, invite_code?}
     *
     * 口径（全版本可用，否则开源版会员无法登录）：
     *   - openid 已绑会员 → 直接登录；
     *   - 未绑：可选 phone_code 换手机号 → 命中已有手机号会员则把 openid 写入
     *     老会员（合并，不建新档防一人两号）；未命中/未提供手机号授权 → 自动建档，
     *     对齐 memberLogin 的邀请码归属逻辑；无手机号时用占位号
     *     （'wx'+openid 摘要，不参与短信登录，仅占 uk_phone 唯一键）；
     *   - 频控：同 openid 60s 内限一次成功登录（防重放轰炸）；
     *   - 出参与 memberLogin 同构：token + member，openid 永不出参。
     */
    public function memberWxLogin(Request $request): Response
    {
        $jsCode = clean_input((string)$request->post('js_code', ''), 64);
        $phoneCode = clean_input((string)$request->post('phone_code', ''), 64);
        $inviteCode = clean_input((string)$request->post('invite_code', ''), 10);
        if ($jsCode === '') {
            return err(400, '缺少 js_code（小程序 wx.login 凭证）');
        }
        $wx = new WechatAuthService();
        if (!$wx->configured()) {
            return err(400, '微信登录参数未配置，请联系商家管理员');
        }
        try {
            $session = $wx->code2session($jsCode);
        } catch (\Throwable $e) {
            (new AuditService())->log('auth.member-wx-login', 'js_code:' . substr($jsCode, 0, 8), '微信登录失败（' . $e->getMessage() . '）', 0);
            return err(401, $e->getMessage());
        }
        $openid = (string)($session['openid'] ?? '');

        // 频控：同 openid 60s 一次成功登录（code 一次性由微信侧保证，此处防重放轰炸）
        $cdKey = 'wx_login_cd_' . md5($openid);
        if (Cache::store('file')->get($cdKey)) {
            return err(429, '登录过于频繁，请稍后再试');
        }

        $member = Db::name('member')->where('wechat_openid', $openid)->where('deleted_at', 0)->find();
        if (!$member) {
            // 手机号快捷授权：命中老会员 → 合并（openid 写入老会员，不建新档）
            $phone = '';
            if ($phoneCode !== '') {
                try {
                    $phone = $wx->phoneByCode($phoneCode);
                } catch (\Throwable $e) {
                    // 换手机号失败静默降级为纯 openid 登录（不阻断），但留痕便于排查
                    (new AuditService())->log('auth.member-wx-login', 'phone_code', '微信手机号换取失败（' . $e->getMessage() . '）', 0);
                    $phone = '';
                }
            }
            if (preg_match('/^1\d{10}$/', $phone)) {
                $existing = Db::name('member')->where('phone', $phone)->where('deleted_at', 0)->find();
                if ($existing) {
                    if ((int)$existing['status'] !== 1) {
                        return err(403, '账号已停用，请联系客服');
                    }
                    Db::name('member')->where('id', (int)$existing['id'])->update([
                        'wechat_openid' => $openid,
                        'updated_at'    => date('Y-m-d H:i:s'),
                    ]);
                    $member = Db::name('member')->where('id', (int)$existing['id'])->find();
                    (new AuditService())->log('auth.member-wx-login', $phone, '微信授权合并老会员（openid 绑定，未建新档）', 0);
                }
            }
            if (!$member) {
                // 首次登录自动建档（对齐 memberLogin：邀请码仅此刻可绑归属，无效不绑）
                $inviterId = 0;
                if ($inviteCode !== '' && preg_match('/^M(\d{1,6})$/', $inviteCode, $m)) {
                    $inviter = Db::name('member')->where('id', (int)$m[1])->where('deleted_at', 0)->where('status', 1)->find();
                    if ($inviter) {
                        $inviterId = (int)$m[1];
                    }
                }
                $id = Db::name('member')->insertGetId([
                    'name'          => '',
                    // 无手机号授权时占位号：wx+openid 摘要，不以 1 开头不参与短信登录
                    'phone'         => preg_match('/^1\d{10}$/', $phone) ? $phone : ('wx' . substr(md5($openid), 0, 9)),
                    'wechat_openid' => $openid,
                    'inviter_id'    => $inviterId,
                    'status'        => 1,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $member = Db::name('member')->where('id', $id)->find();
                (new AuditService())->log('auth.member-register', (string)$id, '微信授权自动注册（openid 绑定）', 0);
            }
        }
        if ((int)$member['status'] !== 1) {
            return err(403, '账号已停用，请联系客服');
        }

        Cache::store('file')->set($cdKey, 1, 60);
        $token = (new JwtService())->encode([
            'type' => 'member',
            'mid'  => (int)$member['id'],
        ]);
        unset($member['wechat_openid']);
        (new AuditService())->log('auth.member-wx-login', (string)$member['id'], '微信授权登录成功', 0);
        return ok(['token' => $token, 'member' => $member], '登录成功');
    }

    /**
     * 技师端 · 登录（手机号 + 密码；password_hash 校验）
     * POST /api/v1/auth/tech-login  {phone, password}
     */
    public function techLogin(Request $request): Response
    {
        $phone = clean_input($request->post('phone', ''), 20);
        $password = (string)$request->post('password', '');
        if ($phone === '' || $password === '') {
            return err(400, '手机号和密码不能为空');
        }

        $tech = Db::name('technician')->where('phone', $phone)->where('deleted_at', 0)->find();
        // 统一提示语防账号枚举；密码未设置（空哈希）一律拒绝
        if (!$tech || (string)$tech['password'] === '' || !password_verify($password, (string)$tech['password'])) {
            (new AuditService())->log('auth.tech-login', $phone, '技师登录失败（账号或密码错误）', 0);
            return err(401, '手机号或密码错误');
        }
        if ((string)$tech['status'] === '停用') {
            return err(403, '账号已停用，请联系管理员');
        }

        $token = (new JwtService())->encode([
            'type' => 'tech',
            'tid'  => (int)$tech['id'],
        ]);
        unset($tech['password']);
        (new AuditService())->log('auth.tech-login', (string)$tech['id'], '技师登录成功', 0);
        return ok(['token' => $token, 'technician' => $tech], '登录成功');
    }

    /**
     * 当前登录资料（含权限码 + 限额用量，供后台黄条提醒）
     * GET /api/v1/auth/profile
     */
    public function profile(Request $request): Response
    {
        $admin = Db::name('admin_account')->where('id', (int)($request->adminId ?? 0))->where('deleted_at', 0)->find();
        if (!$admin) {
            return err(401, '账号不存在或已删除');
        }
        $perms = $this->permsOf((int)$admin['role_id']);
        return ok([
            'admin'   => $this->profileData($admin, $perms),
            'license' => (new LicenseService())->info(),
            'quota'   => (new QuotaService())->usage(),
            // 版本能力清单（前端菜单「授权版功能」标签 + 升级引导弹窗用）
            'gates'   => (new EditionGate())->capabilities(),
        ]);
    }

    /**
     * 修改密码（必须验旧密码——敏感操作）
     * PUT /api/v1/auth/password  {old_password, new_password}
     */
    public function changePassword(Request $request): Response
    {
        $oldPassword = (string)$request->put('old_password', '');
        $newPassword = (string)$request->put('new_password', '');
        if ($pwdErr = SecurityService::checkPassword($newPassword)) {
            return err(400, $pwdErr);
        }

        $admin = Db::name('admin_account')->where('id', (int)($request->adminId ?? 0))->where('deleted_at', 0)->find();
        if (!$admin) {
            return err(401, '账号不存在');
        }
        if (!password_verify($oldPassword, (string)$admin['password'])) {
            (new AuditService())->log('auth.password', (string)$admin['id'], '修改密码失败（旧密码错误）', (int)$admin['id']);
            return err(403, '旧密码错误');
        }

        Db::name('admin_account')->where('id', $admin['id'])->update([
            'password'   => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new AuditService())->log('auth.password', (string)$admin['id'], '修改密码成功', (int)$admin['id']);
        return ok([], '密码修改成功');
    }

    /**
     * 敏感操作二次校验（删除/结算等动作前 5 分钟内需验密）
     * POST /api/v1/auth/sensitive-verify  {password}
     */
    public function sensitiveVerify(Request $request): Response
    {
        $password = (string)$request->post('password', '');
        $admin = Db::name('admin_account')->where('id', (int)($request->adminId ?? 0))->where('deleted_at', 0)->find();
        if (!$admin || !password_verify($password, (string)$admin['password'])) {
            return err(403, '二次校验失败，密码错误');
        }
        $ttl = (int)env('security.sensitive_ttl', 300);
        $token = (new JwtService())->encode([
            'aid'       => (int)$admin['id'],
            'role'      => (int)$admin['role_id'],
            'perms'     => $this->permsOf((int)$admin['role_id']),
            'sensitive' => 1,
        ], $ttl);
        return ok(['sensitive_token' => $token, 'ttl' => $ttl], '二次校验通过');
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 角色 → 权限码集合（owner 超级权限 *）
     * @param int $roleId
     * @return array
     */
    private function permsOf(int $roleId): array
    {
        $role = Db::name('role')->where('id', $roleId)->where('deleted_at', 0)->find();
        if (!$role) {
            return [];
        }
        if ((string)$role['code'] === 'owner') {
            return ['*'];
        }
        return Db::name('role_permission')->where('role_id', $roleId)->where('deleted_at', 0)->column('perm');
    }

    /**
     * 管理员资料（密码永不出参）
     * @param array $admin
     * @param array $perms
     * @return array
     */
    private function profileData(array $admin, array $perms): array
    {
        unset($admin['password']);
        $admin['perms'] = $perms;
        $admin['roleName'] = (string)Db::name('role')->where('id', (int)$admin['role_id'])->value('name');
        return $admin;
    }
}
