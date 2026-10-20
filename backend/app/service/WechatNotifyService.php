<?php
// =====================================================================
// booking 后端 · 微信消息真实推送驱动（message.wechat 功能落地）
//
// 背景：MessageService::deliver() 此前是「只落日志」的桩 —— 消息记录里写着
//       「成功」，客户却什么也没收到。本驱动把发送出口接到微信官方接口：
//         · 小程序订阅消息 POST /cgi-bin/message/subscribe/send
//         · 公众号模板消息 POST /cgi-bin/message/template/send
//       access_token 走 client_credential 拉取并缓存（提前 5 分钟过期）。
//
// 配置（setting 组 notify，敏感字段 AES-256-GCM 加密，对外只回 has_* 布尔）：
//   wechat_notify_appid / wechat_notify_secret / wechat_notify_gateway
//   wechat_notify_mini_page / wechat_notify_mp_url
//
// 降级（重要）：未配置 appid/secret 时 configured()=false，
//   MessageService 回落「日志模式」（保持既有行为，不阻塞业务、不报错）。
// 版本门禁：真实推送为授权版功能（EditionGate::FEATURE_MIN_EDITION['message.wechat']=pro），
//   由 MessageService 在投递前断言；未升级 → 回落日志模式。
// =====================================================================

namespace app\service;

use app\service\pay\PayCrypto;
use think\facade\Db;

class WechatNotifyService
{
    /** 默认网关（可经 setting 覆盖，便于对接测试桩） */
    const GATEWAY_DEFAULT = 'https://api.weixin.qq.com';

    /** setting 键映射 */
    const KEYS = [
        'appid'     => 'wechat_notify_appid',
        'secret'    => 'wechat_notify_secret',
        'gateway'   => 'wechat_notify_gateway',
        'mini_page' => 'wechat_notify_mini_page',
        'mp_url'    => 'wechat_notify_mp_url',
    ];

    /** access_token 缓存文件（runtime，避免频繁请求触发频率限制） */
    private const TOKEN_CACHE = 'wechat_notify_token.json';

    /**
     * 读取配置（secret 解密后原文，供驱动使用）
     * @return array {appid,secret,gateway,mini_page,mp_url}
     */
    public function raw(): array
    {
        $key = $this->masterKey();
        $secretStored = $this->getSetting(self::KEYS['secret'], '');
        $gateway = trim($this->getSetting(self::KEYS['gateway'], ''));
        return [
            'appid'     => trim($this->getSetting(self::KEYS['appid'], '')),
            'secret'    => $secretStored === '' ? '' : PayCrypto::decryptSecret($secretStored, $key),
            'gateway'   => $gateway !== '' ? rtrim($gateway, '/') : self::GATEWAY_DEFAULT,
            'mini_page' => trim($this->getSetting(self::KEYS['mini_page'], '')),
            'mp_url'    => trim($this->getSetting(self::KEYS['mp_url'], '')),
        ];
    }

    /**
     * 脱敏视图（对外；secret 只回 has_secret）
     * @return array
     */
    public function publicView(): array
    {
        $raw = $this->raw();
        return [
            'appid'       => $raw['appid'],
            'has_secret'  => $raw['secret'] !== '',
            'gateway'     => $raw['gateway'],
            'mini_page'   => $raw['mini_page'],
            'mp_url'      => $raw['mp_url'],
            'configured'  => $this->configured(),
        ];
    }

    /**
     * 保存配置（部分更新：secret 传空=清空、缺省=保持）
     * @param array $in {appid?,secret?,gateway?,mini_page?,mp_url?}
     * @return array publicView()
     */
    public function save(array $in): array
    {
        $key = $this->masterKey();
        foreach (self::KEYS as $field => $settingKey) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $value = is_string($in[$field]) ? trim($in[$field]) : (string)$in[$field];
            if ($field === 'secret') {
                if ($value === '') {
                    $this->putSetting($settingKey, '', 'secret', '微信通知 Secret');
                    continue;
                }
                $this->putSetting($settingKey, PayCrypto::encryptSecret($value, $key), 'secret', '微信通知 Secret');
                continue;
            }
            $this->putSetting($settingKey, $value, 'text', $this->label($field));
        }
        return $this->publicView();
    }

    /**
     * 配置是否完整（appid + secret）
     * @return bool
     */
    public function configured(): bool
    {
        $raw = $this->raw();
        return $raw['appid'] !== '' && $raw['secret'] !== '';
    }

    /**
     * 获取 access_token（缓存；提前 300 秒过期）
     * @return string
     * @throws \RuntimeException 拉取失败
     */
    public function accessToken(): string
    {
        $cache = $this->readTokenCache();
        if ($cache && (int)($cache['expires_at'] ?? 0) > time() + 300 && (string)($cache['token'] ?? '') !== '') {
            return (string)$cache['token'];
        }
        $raw = $this->raw();
        if ($raw['appid'] === '' || $raw['secret'] === '') {
            throw new \RuntimeException('微信通知参数未配置（appid/secret）');
        }
        $url = $raw['gateway'] . '/cgi-bin/token?grant_type=client_credential&appid='
            . urlencode($raw['appid']) . '&secret=' . urlencode($raw['secret']);
        $json = $this->httpGet($url);
        $token = (string)($json['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('获取微信 access_token 失败：' . (string)($json['errmsg'] ?? '未知错误'));
        }
        $this->writeTokenCache([
            'token'      => $token,
            'expires_at' => time() + (int)($json['expires_in'] ?? 7200),
        ]);
        return $token;
    }

    /**
     * 真实发送（按模板通道分流）
     * @param array  $tpl   message_template 行（含 channel / wx_template_id / wx_data_map）
     * @param string $content 已渲染文本（用于日志/回执摘要）
     * @param array  $order 订单行（用于解析 openid 与字段映射）
     * @return array {ok:bool, reason:string, ext:string, mode:string}
     */
    public function send(array $tpl, string $content, array $order): array
    {
        $channel = (string)($tpl['channel'] ?? '');
        $tplId = trim((string)($tpl['wx_template_id'] ?? ''));
        if ($tplId === '') {
            return ['ok' => false, 'reason' => '消息模板未配置微信模板 ID（wx_template_id）', 'ext' => '', 'mode' => 'wechat'];
        }
        $openid = $this->openidOf($order);
        if ($openid === '') {
            return ['ok' => false, 'reason' => '会员未绑定微信 openid，无法推送', 'ext' => '', 'mode' => 'wechat'];
        }
        $data = $this->buildData($tpl, $order);
        if (!$data) {
            return ['ok' => false, 'reason' => '消息模板未配置字段映射（wx_data_map）', 'ext' => '', 'mode' => 'wechat'];
        }
        try {
            $token = $this->accessToken();
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'ext' => '', 'mode' => 'wechat'];
        }
        $raw = $this->raw();
        if ($channel === '公众号模板消息') {
            $body = [
                'touser'      => $openid,
                'template_id' => $tplId,
                'url'         => $raw['mp_url'] !== '' ? $raw['mp_url'] : '',
                'data'        => $data,
            ];
            $path = '/cgi-bin/message/template/send';
        } else {
            // 默认按小程序订阅消息
            $body = [
                'touser'            => $openid,
                'template_id'       => $tplId,
                'page'              => $raw['mini_page'] !== '' ? $raw['mini_page'] : 'pages/index/index',
                'miniprogram_state' => 'formal',
                'lang'              => 'zh_CN',
                'data'              => $data,
            ];
            $path = '/cgi-bin/message/subscribe/send';
        }
        try {
            $json = $this->httpPost($raw['gateway'] . $path . '?access_token=' . urlencode($token), $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => '微信接口请求失败：' . $e->getMessage(), 'ext' => '', 'mode' => 'wechat'];
        }
        $errcode = (int)($json['errcode'] ?? -1);
        $msgid = (string)($json['msgid'] ?? '');
        if ($errcode === 0) {
            return ['ok' => true, 'reason' => '', 'ext' => 'msgid=' . $msgid, 'mode' => 'wechat'];
        }
        return [
            'ok'     => false,
            'reason' => '微信推送被拒：' . (string)($json['errmsg'] ?? '未知错误') . '（errcode ' . $errcode . '）',
            'ext'    => 'errcode=' . $errcode,
            'mode'   => 'wechat',
        ];
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 解析会员 openid（order.member_id → member.wechat_openid；或 order.openid 直传）
     * @param array $order
     * @return string
     */
    private function openidOf(array $order): string
    {
        $direct = trim((string)($order['openid'] ?? ($order['wechat_openid'] ?? '')));
        if ($direct !== '') {
            return $direct;
        }
        $memberId = (int)($order['member_id'] ?? 0);
        if ($memberId <= 0) {
            return '';
        }
        try {
            return trim((string)Db::name('member')->where('id', $memberId)->where('deleted_at', 0)->value('wechat_openid'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 按 wx_data_map 组装微信 data 报文
     * @param array $tpl
     * @param array $order
     * @return array 形如 ['thing1'=>['value'=>'上门保洁'], ...]；无映射返回 []
     */
    private function buildData(array $tpl, array $order): array
    {
        $map = json_decode((string)($tpl['wx_data_map'] ?? ''), true);
        if (!is_array($map) || !$map) {
            return [];
        }
        $vars = (new MessageService())->variables($order);
        $data = [];
        foreach ($map as $wxKey => $varName) {
            $wxKey = trim((string)$wxKey);
            $varName = trim((string)$varName);
            if ($wxKey === '' || $varName === '') {
                continue;
            }
            $value = (string)($vars[$varName] ?? '');
            // 微信模板字段值长度限制 20 字符，超出截断（官方要求）
            $data[$wxKey] = ['value' => mb_substr($value, 0, 20)];
        }
        return $data;
    }

    /** setting 键中文名 */
    private function label(string $field): string
    {
        $map = [
            'appid'     => '微信通知 AppID',
            'secret'    => '微信通知 Secret',
            'gateway'   => '微信接口网关',
            'mini_page' => '小程序跳转页面',
            'mp_url'    => '公众号跳转链接',
        ];
        return $map[$field] ?? $field;
    }

    /** 主密钥（与支付配置同源：security.pay_config_key 优先，回落 jwt_secret） */
    private function masterKey(): string
    {
        $k = (string)env('security.pay_config_key', '');
        if ($k === '') {
            $k = (string)env('security.jwt_secret', '');
        }
        if ($k === '') {
            $k = 'booking-pay-fallback-key';
        }
        return $k;
    }

    /** access_token 缓存路径 */
    private function tokenCachePath(): string
    {
        $dir = runtime_path();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . self::TOKEN_CACHE;
    }

    private function readTokenCache(): array
    {
        $file = $this->tokenCachePath();
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string)@file_get_contents($file), true);
        return is_array($json) ? $json : [];
    }

    private function writeTokenCache(array $data): void
    {
        @file_put_contents($this->tokenCachePath(), json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** HTTP GET（curl） */
    private function httpGet(string $url): array
    {
        return $this->request('GET', $url, null);
    }

    /** HTTP POST JSON（curl） */
    private function httpPost(string $url, array $body): array
    {
        return $this->request('POST', $url, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 统一 curl 请求
     * @param string      $method GET|POST
     * @param string      $url
     * @param string|null $body
     * @return array 解析后的 JSON
     * @throws \RuntimeException 网络异常
     */
    private function request(string $method, string $url, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未安装 curl 扩展');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = (string)$body;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0) {
            throw new \RuntimeException('网络异常：' . $error);
        }
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            $v = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->value('value');
            return $v === null ? $default : (string)$v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** 幂等 upsert（setting UNIQUE(key, deleted_at)） */
    private function putSetting(string $key, string $value, string $type, string $remark): void
    {
        $now = date('Y-m-d H:i:s');
        $row = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->find();
        if ($row) {
            Db::name('setting')->where('id', (int)$row['id'])->update([
                'value' => $value, 'type' => $type, 'remark' => $remark, 'updated_at' => $now,
            ]);
            return;
        }
        Db::name('setting')->insert([
            'group' => 'notify', 'key' => $key, 'value' => $value, 'type' => $type, 'remark' => $remark,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
