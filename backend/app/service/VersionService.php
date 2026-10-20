<?php
// =====================================================================
// booking 后端 · 版本服务（在线升级机制的「检查更新」半边）
//
// 事实来源：
//   · 已安装版本 = setting.app_version（安装向导写入，升级成功后由 UpgradeService 推进）
//   · 可升级版本 = data/upgrade/manifest.json（随产品包分发的发布清单，离线可信）
//     └ 若 .env 配置 oa.upgrade_url（授权中心更新源），可扩展为在线拉取；当前以本地清单为准。
//
// 设计：版本号统一 vX.Y.Z，比较用 version_compare；未安装版本缺失时回落清单基线。
// =====================================================================

namespace app\service;

use think\facade\Db;

class VersionService
{
    /** 发布清单路径（backend/app/service → ../../data/upgrade） */
    private const MANIFEST = __DIR__ . '/../../../data/upgrade/manifest.json';

    /** 迁移步骤目录 */
    public const STEP_DIR = __DIR__ . '/../../../data/upgrade/steps';

    /** 版本号前缀（统一 vX.Y.Z 展示口径） */
    private const PREFIX = 'v';

    /** 无 app_version 时的兜底已安装版本（对齐演示数据最近一次升级记录） */
    const FALLBACK_INSTALLED = '1.3.0';

    /**
     * 发布清单（解析 manifest.json）
     * @return array {product, channel, updated_at, releases:[{version,at,note,steps}]}
     */
    public function manifest(): array
    {
        if (!is_file(self::MANIFEST)) {
            return ['product' => '', 'channel' => '', 'updated_at' => '', 'releases' => []];
        }
        $json = json_decode((string)@file_get_contents(self::MANIFEST), true);
        if (!is_array($json)) {
            return ['product' => '', 'channel' => '', 'updated_at' => '', 'releases' => []];
        }
        $json['releases'] = array_values(array_filter((array)($json['releases'] ?? []), function ($r) {
            return is_array($r) && (string)($r['version'] ?? '') !== '';
        }));
        return $json;
    }

    /**
     * 已安装版本（setting.app_version，缺失回落基线）
     * @return string 形如 1.3.0（不含 v 前缀，便于比较）
     */
    public function installed(): string
    {
        $v = '';
        try {
            $v = (string)Db::name('setting')->where('key', 'app_version')->where('deleted_at', 0)->value('value');
        } catch (\Throwable $e) {
            $v = '';
        }
        $v = ltrim(trim($v), 'vV');
        return $v !== '' ? $v : self::FALLBACK_INSTALLED;
    }

    /**
     * 写入已安装版本（升级成功后推进）
     * @param string $version 不含 v 前缀
     * @return void
     */
    public function setInstalled(string $version): void
    {
        $version = ltrim(trim($version), 'vV');
        $now = date('Y-m-d H:i:s');
        $row = Db::name('setting')->where('key', 'app_version')->where('deleted_at', 0)->find();
        if ($row) {
            Db::name('setting')->where('id', (int)$row['id'])->update(['value' => $version, 'updated_at' => $now]);
            return;
        }
        Db::name('setting')->insert([
            'group'      => 'system',
            'key'        => 'app_version',
            'value'      => $version,
            'type'       => 'text',
            'remark'     => '已安装版本（在线升级推进）',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * 清单中的最新版本
     * @return string 不含 v 前缀；清单为空时回落已安装版本
     */
    public function latest(): string
    {
        $latest = $this->installed();
        foreach ($this->manifest()['releases'] as $r) {
            $v = ltrim((string)$r['version'], 'vV');
            if (version_compare($v, $latest, '>')) {
                $latest = $v;
            }
        }
        return $latest;
    }

    /**
     * 待升级发布（已安装版本 < 版本 <= 目标）
     * @param string $to 目标版本（空 = 最新）
     * @return array 按版本升序的发布项
     */
    public function pending(string $to = ''): array
    {
        $installed = $this->installed();
        $target = $to !== '' ? ltrim(trim($to), 'vV') : $this->latest();
        $list = [];
        foreach ($this->manifest()['releases'] as $r) {
            $v = ltrim((string)$r['version'], 'vV');
            if (version_compare($v, $installed, '>') && version_compare($v, $target, '<=')) {
                $list[] = $r;
            }
        }
        usort($list, function ($a, $b) {
            return version_compare(ltrim((string)$a['version'], 'vV'), ltrim((string)$b['version'], 'vV'));
        });
        return $list;
    }

    /**
     * 检查更新（后台「版本升级 → 检查更新」）
     * @return array {installed, latest, hasUpdate, pending:[{version,at,note,steps}], channel, product}
     */
    public function check(): array
    {
        $installed = $this->installed();
        $latest = $this->latest();
        $pending = $this->pending();
        $mf = $this->manifest();
        return [
            'installed' => $installed,
            'latest'    => $latest,
            'hasUpdate' => version_compare($latest, $installed, '>'),
            'pending'   => array_map(function ($r) {
                return [
                    'version' => ltrim((string)$r['version'], 'vV'),
                    'at'      => (string)($r['at'] ?? ''),
                    'note'    => (string)($r['note'] ?? ''),
                    'steps'   => (array)($r['steps'] ?? []),
                ];
            }, $pending),
            'channel'   => (string)($mf['channel'] ?? ''),
            'product'   => (string)($mf['product'] ?? ''),
        ];
    }

    /**
     * 展示用版本号（含 v 前缀）
     * @param string $version
     * @return string
     */
    public function display(string $version): string
    {
        $version = ltrim(trim($version), 'vV');
        return $version === '' ? '' : self::PREFIX . $version;
    }
}
