<?php
// =====================================================================
// booking 后端 · 数据备份控制器
//
//   POST /api/v1/backups/run                执行备份（真实 mysqldump）
//   GET  /api/v1/backups/{id}/download      下载备份文件（.sql.gz）
//   POST /api/v1/backups/{id}/restore       从备份恢复（需 confirm=1 防误触）
//   DELETE /api/v1/backups/{id}             删除备份（记录软删 + 物理文件清理）
//
// 权限：system.view（下载）/ system.edit（备份/恢复/删除）
// 兑现《功能矩阵》系统章「数据备份」承诺（此前 backups 仅 CRUD，无 dump/恢复）
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\BackupService;
use think\facade\Db;
use think\Request;
use think\Response;

class BackupController
{
    /** 执行备份 */
    public function run(Request $request): Response
    {
        $kind = (string)$request->post('kind', 'manual');
        $kind = $kind === 'auto' ? 'auto' : 'manual';
        $operator = $this->operatorName((int)$request->adminId);
        $svc = new BackupService();

        try {
            $row = $svc->run($kind, $operator);
        } catch (\Throwable $e) {
            // 失败也留痕（backup.status=失败 + note），便于运维排查
            Db::name('backup')->insert([
                'name'     => 'backup_' . $kind . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(2)) . '.sql.gz',
                'kind'     => '全量',
                'size'     => '',
                'tables'   => 0,
                'at'       => date('Y-m-d H:i:s'),
                'operator' => $operator,
                'status'   => '失败',
                'keep'     => 0,
                'note'     => mb_substr($e->getMessage(), 0, 240, 'UTF-8'),
            ]);
            return err(500, '备份失败：' . $e->getMessage());
        }

        (new AuditService())->log('backup.run', (string)$row['name'], '备份成功（' . $row['size'] . '）', (int)$request->adminId);
        return ok($row, '备份完成');
    }

    /** 下载备份文件 */
    public function download(Request $request, $id): Response
    {
        $row = $this->find((int)$id);
        if (!$row) {
            return err(404, '备份记录不存在');
        }
        $svc = new BackupService();
        $file = $svc->filePath($row);
        if (!is_file($file)) {
            return err(404, '备份文件不存在或已被清理');
        }
        $content = file_get_contents($file);
        (new AuditService())->log('backup.download', (string)$row['name'], '下载备份', (int)$request->adminId);
        return response($content, 200)->header([
            'Content-Type'        => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
            'Content-Length'      => (string)strlen($content),
        ]);
    }

    /** 从备份恢复（高风险操作，需 confirm=1） */
    public function restore(Request $request, $id): Response
    {
        if ((string)$request->post('confirm', '') !== '1') {
            return err(400, '恢复操作需显式确认（confirm=1）');
        }
        $row = $this->find((int)$id);
        if (!$row) {
            return err(404, '备份记录不存在');
        }
        try {
            $res = (new BackupService())->restore($row);
        } catch (\Throwable $e) {
            (new AuditService())->log('backup.restore', (string)$row['name'], '恢复失败：' . $e->getMessage(), (int)$request->adminId);
            return err(500, '恢复失败：' . $e->getMessage());
        }
        (new AuditService())->log('backup.restore', (string)$row['name'], '恢复成功', (int)$request->adminId);
        return ok($res, '恢复完成');
    }

    /** 删除备份（记录软删 + 物理文件清理） */
    public function remove(Request $request, $id): Response
    {
        $row = $this->find((int)$id);
        if (!$row) {
            return err(404, '备份记录不存在');
        }
        (new BackupService())->removeFile($row);
        Db::name('backup')->where('id', (int)$id)->update(['deleted_at' => time()]);
        (new AuditService())->log('backup.delete', (string)$row['name'], '删除备份（含文件）', (int)$request->adminId);
        return ok(['id' => (int)$id], '删除成功');
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = Db::name('backup')->where('id', $id)->where('deleted_at', 0)->find();
        return $row ?: null;
    }

    private function operatorName(int $adminId): string
    {
        if ($adminId <= 0) {
            return 'system';
        }
        $name = (string)Db::name('admin_account')->where('id', $adminId)->value('name');
        return $name !== '' ? $name : ('admin#' . $adminId);
    }
}
