<?php
// =====================================================================
// booking 后端 · 站点与品牌服务（四杠杆之「品牌权益」）
//
// 背景：站点名/品牌名/Logo/版权标识此前散落在前端硬编码
//       （AdminLayout「Powered by 预约上门服务系统 · 开源版」、Login 页脚同款），
//       setting 里的 basic/brand 分组键从未被读取 —— 后台改了也不生效。
//
// 版本口径（《开发计划》§2.6 四杠杆 · 品牌权益 / 06_seed.sql brand 组注释）：
//   · open（开源版）：品牌名不生效（回落站点名）；Powered by 强制保留
//   · pro（授权版）：可自定义品牌名、可关闭 Powered by
//   · src（源码版）：白标（同 pro，且品牌名完全替换系统标识）
//
// 站点信息（site_name/company_name/service_phone/logo）三版本皆可改。
// =====================================================================

namespace app\service;

use think\facade\Db;

class BrandService
{
    /** 站点信息键（basic 组，三版本可写） */
    const SITE_KEYS = ['site_name', 'company_name', 'service_phone', 'logo'];

    /** 系统默认站点名（setting 为空时回落） */
    const DEFAULT_SITE_NAME = '预约上门服务系统';

    /**
     * 站点/品牌信息（按版本解析「生效值」）
     * @return array
     */
    public function info(): array
    {
        $edition = (new LicenseService())->effectiveEdition();
        $rank = EditionGate::RANK[$edition] ?? 1;
        $canCustom = $rank >= (EditionGate::RANK['pro'] ?? 2);

        $siteName = $this->setting('site_name') ?: self::DEFAULT_SITE_NAME;
        $brandName = $this->setting('brand_name');
        $poweredBy = $this->setting('powered_by', '1') === '1';

        // 开源版：品牌名不生效、版权强制保留（前端据此渲染，后端也兜底）
        if (!$canCustom) {
            $brandName = '';
            $poweredBy = true;
        }

        return [
            'site_name'        => $siteName,
            'brand_name'       => $brandName,
            'display_name'     => $brandName !== '' ? $brandName : $siteName,
            'company_name'     => $this->setting('company_name'),
            'service_phone'    => $this->setting('service_phone'),
            'logo'             => $this->setting('logo'),
            'powered_by'       => $poweredBy,
            'powered_text'     => 'Powered by ' . self::DEFAULT_SITE_NAME,
            'edition'          => $edition,
            'edition_name'     => EditionGate::EDITION_NAME[$edition] ?? '开源版',
            'can_custom_brand' => $canCustom,
        ];
    }

    /**
     * 保存站点/品牌设置（部分更新）
     * @param array $in 形如 {site_name?, company_name?, service_phone?, logo?, brand_name?, powered_by?}
     * @return array 保存后的 info()
     * @throws \RuntimeException 版本不足（开源版试图自定义品牌/去版权）
     */
    public function save(array $in): array
    {
        $gate = new EditionGate();
        $canCustom = $gate->assertFeature('brand.custom');

        // —— 站点信息（basic 组，三版本可写）——
        foreach (self::SITE_KEYS as $key) {
            if (array_key_exists($key, $in)) {
                $this->putSetting('basic', $key, $this->str($in[$key]));
            }
        }

        // —— 品牌权益（brand 组，付费能力）——
        $wantsBrand = array_key_exists('brand_name', $in) && $this->str($in['brand_name']) !== '';
        $wantsNoPowered = array_key_exists('powered_by', $in) && !$this->truthy($in['powered_by']);
        if (($wantsBrand || $wantsNoPowered) && !$canCustom) {
            throw new \RuntimeException('自定义品牌名 / 去除版权标识为授权版功能，升级后即可使用');
        }
        if (array_key_exists('brand_name', $in)) {
            $this->putSetting('brand', 'brand_name', $this->str($in['brand_name']));
        }
        if (array_key_exists('powered_by', $in)) {
            $this->putSetting('brand', 'powered_by', $this->truthy($in['powered_by']) ? '1' : '0');
        }

        return $this->info();
    }

    private function str($v): string
    {
        return trim((string)$v);
    }

    private function truthy($v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function setting(string $key, string $default = ''): string
    {
        try {
            $value = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->value('value');
            return $value === null ? $default : (string)$value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** 幂等 upsert（setting 表 UNIQUE(key, deleted_at)） */
    private function putSetting(string $group, string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');
        $row = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->find();
        if ($row) {
            Db::name('setting')->where('id', (int)$row['id'])->update(['value' => $value, 'updated_at' => $now]);
            return;
        }
        Db::name('setting')->insert([
            'group'      => $group,
            'key'        => $key,
            'value'      => $value,
            'type'       => $key === 'powered_by' ? 'bool' : 'text',
            'remark'     => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
