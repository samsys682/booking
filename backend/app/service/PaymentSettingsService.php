<?php
// =====================================================================
// booking 后端 · 支付渠道配置服务
//
// 背景：后台「在线支付」面板此前只写在前端 pinia store（save() 仅置 savedAt），
//       后端完全不知道商户号/私钥/APIv3 密钥 —— 无法真正对接微信/支付宝。
//
// 本服务把渠道凭证落到 setting 表（group=payment，key=pay_{渠道}_{字段}）：
//   · 敏感字段（私钥 / APIv3 密钥 / 公钥）落库前 AES-256-GCM 加密（PayCrypto::encryptSecret）
//   · 对外（接口/前端）一律不回显明文，仅回 has_{字段} 布尔
//   · 主密钥取自 security.pay_config_key（缺失回落 security.jwt_secret）
//
// 版本门禁：保存配置属付费能力（EditionGate::FEATURE_MIN_EDITION['pay.online']=pro），
//           由 PaymentController 拦截；读取一律放行（入口可见，升级有动机）。
// =====================================================================

namespace app\service;

use app\service\pay\PayCrypto;
use think\facade\Db;

class PaymentSettingsService
{
    /** setting 分组 */
    const GROUP = 'payment';

    /**
     * 渠道字段定义
     * type：bool（开关）/ text（明文）/ secret（加密存储，不回显）
     * required：判定「已配置」的必备字段（齐备才走真实渠道）
     */
    const SCHEMA = [
        'wechat' => [
            'label'  => '微信支付',
            'fields' => [
                'enabled'       => ['type' => 'bool',   'label' => '启用微信支付'],
                'mch_id'        => ['type' => 'text',   'label' => '商户号'],
                'app_id'        => ['type' => 'text',   'label' => 'AppID'],
                'serial_no'     => ['type' => 'text',   'label' => '商户证书序列号'],
                'api_v3_key'    => ['type' => 'secret', 'label' => 'APIv3 密钥'],
                'private_key'   => ['type' => 'secret', 'label' => '商户 API 证书私钥'],
                'platform_cert' => ['type' => 'secret', 'label' => '微信支付平台证书 / 公钥'],
                'notify_url'    => ['type' => 'text',   'label' => '支付回调地址'],
                'gateway'       => ['type' => 'text',   'label' => '接口网关'],
            ],
            'required' => ['mch_id', 'app_id', 'serial_no', 'api_v3_key', 'private_key', 'platform_cert'],
        ],
        'alipay' => [
            'label'  => '支付宝',
            'fields' => [
                'enabled'     => ['type' => 'bool',   'label' => '启用支付宝'],
                'app_id'      => ['type' => 'text',   'label' => 'AppID'],
                'pid'         => ['type' => 'text',   'label' => '商户 PID'],
                'private_key' => ['type' => 'secret', 'label' => '应用私钥（RSA2）'],
                'public_key'  => ['type' => 'secret', 'label' => '支付宝公钥'],
                'notify_url'  => ['type' => 'text',   'label' => '支付回调地址'],
                'return_url'  => ['type' => 'text',   'label' => '支付返回地址'],
                'gateway'     => ['type' => 'text',   'label' => '接口网关'],
            ],
            'required' => ['app_id', 'private_key', 'public_key'],
        ],
        'offline' => [
            'label'  => '线下收款',
            'fields' => [
                'enabled' => ['type' => 'bool', 'label' => '启用线下收款'],
            ],
            'required' => [],
        ],
    ];

    /**
     * 渠道配置（解密后原文，供驱动使用）
     * @param string $channel wechat|alipay|offline
     * @return array
     */
    public function raw(string $channel): array
    {
        $schema = self::SCHEMA[$channel] ?? null;
        if ($schema === null) {
            return [];
        }
        $out = [];
        $key = $this->masterKey();
        foreach ($schema['fields'] as $field => $def) {
            $stored = $this->getSetting($this->settingKey($channel, $field), '');
            if ($def['type'] === 'bool') {
                $out[$field] = in_array($stored, ['1', 'true', 'yes', 'on'], true);
            } elseif ($def['type'] === 'secret') {
                $out[$field] = $stored === '' ? '' : PayCrypto::decryptSecret($stored, $key);
            } else {
                $out[$field] = $stored;
            }
        }
        return $out;
    }

    /**
     * 渠道配置（对外脱敏视图，敏感字段只回 has_ 布尔）
     * @return array {channels:[{key,label,enabled,configured,fields:{...}}]}
     */
    public function publicView(): array
    {
        $channels = [];
        foreach (self::SCHEMA as $channel => $schema) {
            $raw = $this->raw($channel);
            $fields = [];
            foreach ($schema['fields'] as $field => $def) {
                if ($def['type'] === 'secret') {
                    // 敏感字段：只回是否已设置，绝不回显明文
                    $fields['has_' . $field] = ($raw[$field] ?? '') !== '';
                } else {
                    $fields[$field] = $raw[$field] ?? ($def['type'] === 'bool' ? false : '');
                }
            }
            $channels[] = [
                'key'        => $channel,
                'label'      => $schema['label'],
                'enabled'    => (bool)($raw['enabled'] ?? false),
                'configured' => $this->configured($channel),
                'fields'     => $fields,
            ];
        }
        return ['channels' => $channels];
    }

    /**
     * 是否已配置完整（必备字段齐备）
     * @param string $channel
     * @return bool
     */
    public function configured(string $channel): bool
    {
        $schema = self::SCHEMA[$channel] ?? null;
        if ($schema === null) {
            return false;
        }
        if (empty($schema['required'])) {
            return true; // 无必备字段（如线下收款）
        }
        $raw = $this->raw($channel);
        foreach ($schema['required'] as $f) {
            if (trim((string)($raw[$f] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * 保存渠道配置（部分更新）
     * 规则：布尔按开关写；secret/text 传空串=清空、缺省=保持原值、非空=写入（secret 加密）
     * @param array $in 形如 ['wechat'=>['enabled'=>true,'mch_id'=>'...','api_v3_key'=>'...'], 'alipay'=>[...]]
     * @return array 保存后的脱敏视图
     */
    public function save(array $in): array
    {
        $key = $this->masterKey();
        foreach (self::SCHEMA as $channel => $schema) {
            if (!isset($in[$channel]) || !is_array($in[$channel])) {
                continue;
            }
            $payload = $in[$channel];
            foreach ($schema['fields'] as $field => $def) {
                if (!array_key_exists($field, $payload)) {
                    continue; // 未提交 = 保持原值
                }
                $value = $payload[$field];
                if ($def['type'] === 'bool') {
                    $this->putSetting($this->settingKey($channel, $field), $value ? '1' : '0', 'bool', $def['label']);
                    continue;
                }
                $value = is_string($value) ? trim($value) : (string)$value;
                if ($value === '') {
                    $this->putSetting($this->settingKey($channel, $field), '', $def['type'], $def['label']);
                    continue;
                }
                if ($def['type'] === 'secret') {
                    $value = PayCrypto::encryptSecret($value, $key);
                }
                $this->putSetting($this->settingKey($channel, $field), $value, $def['type'], $def['label']);
            }
        }
        return $this->publicView();
    }

    /** setting 键名：pay_{渠道}_{字段} */
    private function settingKey(string $channel, string $field): string
    {
        return 'pay_' . $channel . '_' . $field;
    }

    /** 主密钥（配置加密用）：security.pay_config_key 优先，回落 jwt_secret */
    private function masterKey(): string
    {
        $k = (string)env('security.pay_config_key', '');
        if ($k === '') {
            $k = (string)env('security.jwt_secret', '');
        }
        if ($k === '') {
            $k = 'booking-pay-fallback-key'; // 极端兜底（未配置 .env 时不至于崩）
        }
        return $k;
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

    /** 幂等 upsert（setting 表 UNIQUE(key, deleted_at)） */
    private function putSetting(string $key, string $value, string $type, string $remark): void
    {
        $now = date('Y-m-d H:i:s');
        $row = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->find();
        if ($row) {
            Db::name('setting')->where('id', (int)$row['id'])->update([
                'value'      => $value,
                'type'       => $type,
                'remark'     => $remark,
                'updated_at' => $now,
            ]);
            return;
        }
        Db::name('setting')->insert([
            'group'      => self::GROUP,
            'key'        => $key,
            'value'      => $value,
            'type'       => $type,
            'remark'     => $remark,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
