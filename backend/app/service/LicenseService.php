<?php
// =====================================================================
// booking 后端 · 授权与版本服务（四杠杆之一：功能开关/限额的版本依据）
// 版本：开源版(open) / 授权版(pro) / 源码版(src)
// v1.14：激活改走 OA licenses/verify 真实验卡（堵本地自验证防伪漏洞）
//   - card_key 只用于本次验卡请求，绝不落库、绝不落日志
//   - device_id 服务端生成稳定指纹并绑定（禁前端传）
//   - 断网宽限：last_verified_at 超 7 天未成功续验 → 回落 open（不误杀 + 时钟对称）
//   - 授权激活后由本服务按版本覆盖 setting.quota 组的 max 值
// =====================================================================

namespace app\service;

use think\facade\Db;

class LicenseService
{
    /** 版本 → 限额映射（-1 = 不限；激活时写回 setting.quota 组）
     * 2026-10-10 产品决策：消息通知（微信消息）全版本不限条数，不设 wechat_messages_max 限额 */
    const EDITION_QUOTA = [
        'open' => ['stores_max' => 1, 'technicians_max' => 5, 'monthly_orders_max' => 200, 'wechat_messages_max' => -1],
        'pro'  => ['stores_max' => 3, 'technicians_max' => -1, 'monthly_orders_max' => -1, 'wechat_messages_max' => -1],
        'src'  => ['stores_max' => -1, 'technicians_max' => -1, 'monthly_orders_max' => -1, 'wechat_messages_max' => -1],
    ];

    /** 版本中文名 */
    const EDITION_NAME = ['open' => '开源版', 'pro' => '授权版', 'src' => '源码版'];

    /** OA product_spec → 版本键（§1.10：授权版 month/quarter/year，源码版 lifetime） */
    const SPEC_TO_EDITION = [
        'month'    => 'pro',
        'quarter'  => 'pro',
        'year'     => 'pro',
        'lifetime' => 'src',
    ];

    /** 断网宽限天数：last_verified_at 距今超过 N 天未成功续验 → 回落 open */
    const GRACE_DAYS = 7;

    /** OA 错误码 → 用户文案（verify 业务拒绝 HTTP 200 + data.ok=false + data.code，见 feishu licensesApi） */
    const OA_ERROR_MSG = [
        'EXPIRED'         => '卡密已过期，请续费',
        'REVOKED'         => '卡密已被回收（退款或吊销）',
        'INVALID'         => '卡号或卡密无效',
        'DEVICE_REQUIRED' => '缺少设备指纹，请重新激活',
        'DEVICE_MISMATCH' => '设备不符，换绑请联系客服',
    ];

    /**
     * 当前授权信息（字段对齐 license 表）
     * @return array {edition, editionName, status, cardNo, buyer, expiresAt, lastVerifiedAt, limits}
     */
    public function info(): array
    {
        $row = $this->currentRow();
        $edition = 'open';
        $editionName = '';
        $status = '未激活';
        $cardNo = '';
        $buyer = '';
        $expiresAt = '';
        $lastVerifiedAt = '';
        if ($row) {
            $editionName = (string)$row['edition'];
            $edition = array_search($editionName, self::EDITION_NAME, true);
            $edition = $edition === false ? 'open' : $edition;
            $status = (string)$row['status'];
            $cardNo = (string)$row['card_no'];
            $buyer = (string)$row['buyer'];
            $expiresAt = (string)($row['expires_at'] ?? '');
            $lastVerifiedAt = (string)($row['last_verified_at'] ?? '');
            // 到期回落：授权版（按年）到期后功能/限额回落开源版口径，续费激活后恢复；
            // 源码版 expires_at 为空（永久），不受影响
            if ($edition === 'pro' && $expiresAt !== '' && substr($expiresAt, 0, 10) < date('Y-m-d')) {
                $status = '已过期';
                $edition = 'open';
                $editionName = '授权版（已过期）';
            }
        }
        return [
            'edition'        => $edition,
            'editionName'    => $editionName !== '' ? $editionName : (self::EDITION_NAME[$edition] ?? '开源版'),
            'status'         => $status,
            'cardNo'         => $cardNo,
            'buyer'          => $buyer,
            'expiresAt'      => $expiresAt !== '' ? substr($expiresAt, 0, 10) : '',
            'lastVerifiedAt' => $lastVerifiedAt !== '' ? substr($lastVerifiedAt, 0, 10) : '',
            'limits'         => self::EDITION_QUOTA[$edition] ?? self::EDITION_QUOTA['open'],
        ];
    }

    /**
     * 生效版本键（配额检查唯一事实来源）：
     *   license 行存在且授权版未过期 → 实际版本；否则回落 open。
     *   叠加断网宽限判断：pro 且 last_verified_at（缺失时以 activated_at 兜底）
     *   距今超 GRACE_DAYS 天未成功续验 → 回落 open。
     *   不读 setting.quota（那是激活时落库的静态快照，到期/失效不会自动降）。
     */
    public function effectiveEdition(): string
    {
        $row = $this->currentRow();
        if (!$row) return 'open';
        $edition = array_search((string)$row['edition'], self::EDITION_NAME, true);
        $edition = $edition === false ? 'open' : $edition;
        if ($edition !== 'pro') {
            return $edition; // open / src（src 永久）
        }
        $expiresAt = (string)($row['expires_at'] ?? '');
        if ($expiresAt !== '' && substr($expiresAt, 0, 10) < date('Y-m-d')) {
            return 'open'; // 授权版到期 → 实际配额按开源版执行
        }
        // 断网宽限：超过 GRACE_DAYS 天未成功续验 → 回落 open
        // （last_verified_at 为 null 的存量升级数据，以 activated_at 兜底，避免升级即误杀）
        $last = (string)($row['last_verified_at'] ?? '');
        if ($last === '') {
            $last = (string)($row['activated_at'] ?? '');
        }
        if ($last !== '' && strtotime($last) < strtotime('-' . self::GRACE_DAYS . ' days')) {
            return 'open';
        }
        return 'pro';
    }

    /**
     * 激活授权（OA 真实验卡 → 写 license 表 + 覆盖 quota 配置）
     * 校验链：卡号卡密非空 → OA verify（ok===true 才算通过）→ product_spec 推导版本
     * @param string $cardNo  卡号
     * @param string $cardKey 卡密（仅本次请求使用，绝不落库/落日志）
     * @param string $buyer   购买主体（可空）
     * @return array 授权信息（同 info()）
     * @throws \InvalidArgumentException      业务拒绝（卡密无效/过期/回收/设备不符，文案已映射）
     * @throws \RuntimeException              OA 网络不可达（激活时无宽限可言，直接报稍后重试）
     */
    public function activate(string $cardNo, string $cardKey, string $buyer = ''): array
    {
        if ($cardNo === '') {
            throw new \InvalidArgumentException('卡号不能为空');
        }
        if ($cardKey === '') {
            throw new \InvalidArgumentException('卡密不能为空');
        }
        $oa = new OaClient();
        $verify = $oa->verifyCard(clean_input($cardNo, 64), $cardKey, $this->deviceFingerprint());
        return $this->persistVerified($verify, clean_input($buyer, 100), false, clean_input($cardNo, 64));
    }

    /**
     * 每日续验（{card_no, token, device_id}）：成功刷新 last_verified_at / status / features；
     * 业务拒绝按映射降级（EXPIRED/REVOKED/INVALID → 状态落库，effectiveEdition 自然回落 open）；
     * 网络不可达 → 保持现状不降级（§1.10 不误杀）。
     * @return array {verified: bool, edition: string, message: string}
     */
    public function dailyReverify(): array
    {
        $row = $this->currentRow();
        if (!$row) {
            return ['verified' => false, 'edition' => 'open', 'message' => '本实例未激活授权'];
        }
        $token = (string)($row['oa_token'] ?? '');
        if ($token === '') {
            return ['verified' => false, 'edition' => $this->effectiveEdition(), 'message' => '无续验 token（旧数据），等待下次激活'];
        }
        try {
            $verify = (new OaClient())->reverifyCard((string)$row['card_no'], $token, $this->deviceFingerprint());
            // verify 业务拒绝是 HTTP 200 + data.ok=false + data.code（不抛异常，须主动判）
            if ((bool)($verify['ok'] ?? false) !== true) {
                $code = (string)($verify['code'] ?? '');
                Db::name('license')->where('id', $row['id'])->update([
                    'status'     => in_array($code, ['EXPIRED', 'REVOKED'], true) ? '已过期' : '已禁用',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return ['verified' => false, 'edition' => 'open', 'message' => self::OA_ERROR_MSG[$code] ?? ((string)($verify['msg'] ?? '') ?: '续验被拒')];
            }
            $this->persistVerified($verify, (string)$row['buyer'], true, (string)$row['card_no']);
            return ['verified' => true, 'edition' => $this->effectiveEdition(), 'message' => '续验通过'];
        } catch (OaApiException $e) {
            // 顶层 code!=0（网关/鉴权级失败）：落状态留痕，版本回落
            Db::name('license')->where('id', $row['id'])->update([
                'status'     => '已禁用',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['verified' => false, 'edition' => 'open', 'message' => '续验失败：' . $e->getMessage()];
        } catch (\RuntimeException $e) {
            // 网络不可达：宽限内保持 pro，不误杀
            return ['verified' => false, 'edition' => $this->effectiveEdition(), 'message' => '授权服务暂不可达（宽限期内保持当前版本）'];
        }
    }

    /**
     * 验卡通过后的落库（激活与续验共用；card_key 不入本方法参数）
     * @param array  $verify OA verify 响应 data {ok, license_id, product_spec, expire_at, features, token}
     * @param string $buyer  购买主体
     * @param bool   $isRenew 是否续验（续验保留原 activated_at）
     * @param string $cardNo 卡号（verify 响应不含 card_no，由调用方传入）
     * @return array info()
     */
    private function persistVerified(array $verify, string $buyer, bool $isRenew = false, string $cardNo = ''): array
    {
        if ((bool)($verify['ok'] ?? false) !== true) {
            // verify 业务拒绝（HTTP 200 + data.ok=false + data.code）：按映射给用户文案
            $code = (string)($verify['code'] ?? '');
            $msg = self::OA_ERROR_MSG[$code] ?? ((string)($verify['msg'] ?? '') ?: '卡号或卡密无效');
            throw new \InvalidArgumentException($msg);
        }
        $spec = (string)($verify['product_spec'] ?? '');
        $editionKey = self::SPEC_TO_EDITION[$spec] ?? '';
        if ($editionKey === '') {
            throw new \InvalidArgumentException('卡密规格无效：' . $spec);
        }
        $expireAt = trim((string)($verify['expire_at'] ?? ''));
        $now = date('Y-m-d H:i:s');
        $data = [
            'edition'         => self::EDITION_NAME[$editionKey],
            'buyer'           => $buyer,
            'oa_license_id'   => (string)($verify['license_id'] ?? ''),
            'device_id'       => $this->deviceFingerprint(),
            'oa_token'        => (string)($verify['token'] ?? ''),
            'features'        => json_encode((array)($verify['features'] ?? []), JSON_UNESCAPED_UNICODE),
            'last_verified_at' => $now,
            'expires_at'      => $expireAt !== '' ? $expireAt : null,
            'status'          => '已激活',
            'updated_at'      => $now,
        ];
        $exists = $this->currentRow();
        if ($exists) {
            // 同实例重激活/续验：覆盖原行（uk_card_no 唯一，换卡 = 新行 + 旧行软删）
            if ((string)$exists['card_no'] !== $cardNo) {
                Db::name('license')->where('id', $exists['id'])->update(['deleted_at' => time(), 'updated_at' => $now]);
                $data['card_no'] = $cardNo;
                $data['created_at'] = $now;
                if (!$isRenew) $data['activated_at'] = $now;
                Db::name('license')->insert($data);
            } else {
                if (!$isRenew) $data['activated_at'] = $now;
                Db::name('license')->where('id', $exists['id'])->update($data);
            }
        } else {
            $data['card_no'] = $cardNo;
            $data['created_at'] = $now;
            if (!$isRenew) $data['activated_at'] = $now;
            Db::name('license')->insert($data);
        }
        // 授权生效 → 覆盖限额配置（限额外置，代码不写死）
        $this->applyQuota($editionKey);
        return $this->info();
    }

    /**
     * 按版本覆盖 setting.quota 组限额
     * @param string $editionKey
     * @return void
     */
    public function applyQuota(string $editionKey): void
    {
        $quota = self::EDITION_QUOTA[$editionKey] ?? self::EDITION_QUOTA['open'];
        foreach ($quota as $key => $value) {
            Db::name('setting')->where('key', $key)->update([
                'value'      => (string)$value,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 稳定设备指纹（§1.10：禁止每次随机）：域名 + 首管理员 created_at + 数据库名 哈希。
     * 不用 install.lock（仅锁标志文件，无安装 ID 且可被客户删改）。
     * 进程内静态缓存，同一请求多次调用零开销。
     * @return string 32 位十六进制
     */
    public function deviceFingerprint(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'cli');
        $admin = Db::name('admin_account')->where('deleted_at', 0)->order('id asc')->value('created_at');
        $dbName = '';
        try {
            $rows = Db::query('SELECT DATABASE() AS db_name');
            $dbName = (string)($rows[0]['db_name'] ?? '');
        } catch (\Throwable $e) {
            $dbName = '';
        }
        $cached = hash('sha256', $host . '|' . (string)$admin . '|' . $dbName);
        return $cached;
    }

    /**
     * 当前生效授权行（未软删最新一条）
     * @return array|null
     */
    private function currentRow(): ?array
    {
        $row = Db::name('license')->where('deleted_at', 0)->order('id desc')->find();
        return $row ?: null;
    }
}
