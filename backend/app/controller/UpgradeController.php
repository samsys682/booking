<?php
// =====================================================================
// booking 后端 · 在线升级控制器
//   GET  /api/v1/upgrade/check              检查更新（已安装 vs 清单最新）
//   POST /api/v1/upgrade/run                执行升级（备份 → 迁移 → 推进版本 → 留痕）
//   POST /api/v1/upgrade/rollback           回滚到某次升级前的备份
//   GET  /api/v1/upgrade/status             当前版本 + 最近升级记录
// 仅管理端 + system.edit 权限；升级/回滚写审计日志。
// ⚠️ 须注册在通用 {resource} CRUD 之前（'upgrade/*' 会被前缀吞掉，坑 C）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\UpgradeService;
use app\service\VersionService;
use think\facade\Db;
use think\Request;
use think\Response;

class UpgradeController
{
    /** 仅管理端可操作 */
    private function guardAdmin(Request $request): ?Response
    {
        if ((string)($request->tokenType ?? '') !== 'admin') {
            return err(403, '仅管理端可操作在线升级', [], 403);
        }
        return null;
    }

    /**
     * 检查更新
     * GET /api/v1/upgrade/check
     */
    public function check(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        return ok((new VersionService())->check());
    }

    /**
     * 当前版本 + 最近升级记录
     * GET /api/v1/upgrade/status
     */
    public function status(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $vs = new VersionService();
        $check = $vs->check();
        $records = Db::name('upgrade_record')->where('deleted_at', 0)
            ->order('id', 'desc')->limit(10)
            ->field('id,from_version,to_version,at,operator,duration,status,note')
            ->select()->toArray();
        return ok([
            'installed'      => $check['installed'],
            'installed_text' => $vs->display($check['installed']),
            'latest'         => $check['latest'],
            'latest_text'    => $vs->display($check['latest']),
            'hasUpdate'      => $check['hasUpdate'],
            'pending'        => $check['pending'],
            'channel'        => $check['channel'],
            'records'        => $records,
        ]);
    }

    /**
     * 执行升级
     * POST /api/v1/upgrade/run  {to_version?}
     */
    public function run(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $to = clean_input($request->post('to_version', ''), 15);
        $operator = $this->operatorName((int)($request->adminId ?? 0));
        try {
            $res = (new UpgradeService())->run($to, $operator);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        (new AuditService())->log(
            'upgrade.run',
            'v' . $res['from'] . '→v' . $res['to'],
            ($res['ok'] ? '升级成功' : '升级失败：' . ($res['error'] ?? '')) . '（' . $res['duration'] . '）',
            (int)($request->adminId ?? 0)
        );
        return ok($res, $res['ok'] ? ('已升级到 v' . $res['to']) : ('升级失败：' . ($res['error'] ?? '')));
    }

    /**
     * 回滚
     * POST /api/v1/upgrade/rollback  {record_id}
     */
    public function rollback(Request $request): Response
    {
        if ($resp = $this->guardAdmin($request)) return $resp;
        $recordId = (int)$request->post('record_id', 0);
        if ($recordId <= 0) {
            return err(400, 'record_id 不能为空');
        }
        $operator = $this->operatorName((int)($request->adminId ?? 0));
        try {
            $res = (new UpgradeService())->rollback($recordId, $operator);
        } catch (\RuntimeException $e) {
            return err(400, $e->getMessage());
        }
        (new AuditService())->log('upgrade.rollback', (string)$recordId, '回滚到 ' . $res['version'], (int)($request->adminId ?? 0));
        return ok($res, '已回滚到 ' . $res['version']);
    }

    /** 操作人姓名 */
    private function operatorName(int $adminId): string
    {
        if ($adminId <= 0) return '系统';
        $name = (string)Db::name('admin_account')->where('id', $adminId)->where('deleted_at', 0)->value('name');
        return $name !== '' ? $name : '系统';
    }
}
