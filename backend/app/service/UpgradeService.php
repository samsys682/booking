<?php
// =====================================================================
// booking 后端 · 在线升级服务（在线升级机制的「执行」半边）
//
// 此前 upgrade_record 只是一张可手工补录的 CRUD 表 —— 没有检查、没有迁移、
// 没有备份、没有回滚。本服务把它升级为真实升级引擎：
//
//   检查更新（VersionService）→ 升级前全量备份 → 按清单执行迁移步骤
//     → 推进 setting.app_version → 写 upgrade_record（成功/失败均留痕）
//     → 失败自动留失败记录；成功可回滚到「升级前备份」
//
// 步骤类型（data/upgrade/steps/）：
//   · *.php  返回 callable，由本服务调用（可做幂等判断，如 guarded ALTER）
//   · *.sql  以 ; 切分逐条执行（适合无守卫的 DDL/DML）
//
// 安全：步骤文件名 basename 化 + 目录白名单，防路径穿越；
//       备份失败即中止（不冒「无备份升级」的风险）。
// =====================================================================

namespace app\service;

use think\facade\Db;

class UpgradeService
{
    /**
     * 执行在线升级
     * @param string $toVersion 目标版本（空 = 清单最新）
     * @param string $operator  操作人（写 upgrade_record.operator）
     * @return array {ok, from, to, duration, backup, record_id?, applied[], error?}
     * @throws \RuntimeException 已是最新 / 备份失败（无备份不升级）
     */
    public function run(string $toVersion, string $operator): array
    {
        $vs = new VersionService();
        $from = $vs->installed();
        $pending = $vs->pending($toVersion);
        if (!$pending) {
            throw new \RuntimeException('当前已是最新版本（' . $vs->display($from) . '），无需升级');
        }
        $to = ltrim((string)$pending[count($pending) - 1]['version'], 'vV');

        // ① 升级前全量备份（失败即中止 —— 无备份不升级）
        $backup = (new BackupService())->run('manual', $operator !== '' ? $operator : 'system');
        $backupName = (string)($backup['name'] ?? '');

        $start = microtime(true);
        $applied = [];
        try {
            foreach ($pending as $rel) {
                foreach ((array)($rel['steps'] ?? []) as $step) {
                    $this->applyStep((string)$step);
                    $applied[] = (string)$step;
                }
                $applied[] = 'release:' . ltrim((string)$rel['version'], 'vV');
            }
            // ② 推进已安装版本
            $vs->setInstalled($to);
        } catch (\Throwable $e) {
            $dur = $this->humanDuration($start);
            $note = '升级失败：' . $e->getMessage()
                . '；已应用 ' . count($applied) . ' 步；升级前备份 ' . $backupName;
            $this->record($from, $to, $operator, $dur, '失败', $note);
            return [
                'ok' => false, 'from' => $from, 'to' => $to, 'duration' => $dur,
                'backup' => $backupName, 'applied' => $applied, 'error' => $e->getMessage(),
            ];
        }

        $dur = $this->humanDuration($start);
        $note = '在线升级：' . $this->notesOf($pending) . '；升级前备份 ' . $backupName;
        $recordId = $this->record($from, $to, $operator, $dur, '成功', $note);
        return [
            'ok' => true, 'from' => $from, 'to' => $to, 'duration' => $dur,
            'backup' => $backupName, 'record_id' => $recordId, 'applied' => $applied,
        ];
    }

    /**
     * 回滚到某次升级前的备份
     * @param int    $recordId upgrade_record.id（须为「成功」记录）
     * @param string $operator 操作人
     * @return array {ok, restored_from, version, backup, record_id}
     * @throws \RuntimeException 记录不存在 / 非成功记录 / 备份缺失
     */
    public function rollback(int $recordId, string $operator): array
    {
        $row = Db::name('upgrade_record')->where('id', $recordId)->where('deleted_at', 0)->find();
        if (!$row) {
            throw new \RuntimeException('升级记录不存在');
        }
        if ((string)$row['status'] !== '成功') {
            throw new \RuntimeException('仅「成功」的升级记录可回滚（当前：' . $row['status'] . '）');
        }
        if (!preg_match('/升级前备份\s+(\S+)/u', (string)$row['note'], $m)) {
            throw new \RuntimeException('该记录未关联升级前备份，无法回滚（早期手工补录记录无备份）');
        }
        $backupName = $m[1];
        $backup = Db::name('backup')->where('name', $backupName)->where('deleted_at', 0)->find();
        if (!$backup) {
            throw new \RuntimeException('升级前备份已不存在（可能已被清理）：' . $backupName);
        }

        // 全量恢复（表结构与数据一并回退到升级前）
        (new BackupService())->restore($backup);
        // 恢复后 setting.app_version 已回到升级前值；此处兜底写一次，保证口径一致
        $target = ltrim((string)$row['from_version'], 'vV');
        (new VersionService())->setInstalled($target);

        $dur = '—';
        $note = '回滚：恢复备份 ' . $backupName . '（由 ' . $row['to_version'] . ' 回退至 ' . $row['from_version'] . '）';
        $newId = $this->record(
            (string)$row['to_version'],
            (string)$row['from_version'],
            $operator,
            $dur,
            '成功',
            $note
        );
        return [
            'ok' => true,
            'restored_from' => $backupName,
            'version' => (new VersionService())->display($target),
            'backup' => $backupName,
            'record_id' => $newId,
        ];
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * 执行单个迁移步骤文件
     * @param string $step 文件名（相对 data/upgrade/steps）
     * @return void
     * @throws \RuntimeException 文件缺失 / 类型不支持 / 执行失败
     */
    private function applyStep(string $step): void
    {
        $step = basename(trim($step));
        if ($step === '' || strpos($step, '..') !== false) {
            throw new \RuntimeException('非法步骤名：' . $step);
        }
        $file = rtrim(VersionService::STEP_DIR, '/\\') . DIRECTORY_SEPARATOR . $step;
        if (!is_file($file)) {
            throw new \RuntimeException('迁移步骤缺失：' . $step);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            $fn = require $file;
            if (is_callable($fn)) {
                $fn();
            }
            return;
        }
        if ($ext === 'sql') {
            $this->execSql((string)file_get_contents($file));
            return;
        }
        throw new \RuntimeException('不支持的步骤类型：' . $step);
    }

    /**
     * 逐条执行 SQL（以 ; 切分；跳过空语句与纯注释行）
     * @param string $sql
     * @return void
     */
    private function execSql(string $sql): void
    {
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            // 去掉整行注释后仍为空则跳过
            $lines = array_filter(explode("\n", $stmt), function ($l) {
                $l = trim($l);
                return $l !== '' && strpos($l, '--') !== 0;
            });
            if (!$lines) {
                continue;
            }
            Db::execute($stmt);
        }
    }

    /**
     * 写升级记录
     * @return int 记录 id
     */
    private function record(string $from, string $to, string $operator, string $duration, string $status, string $note): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::name('upgrade_record')->insertGetId([
            'from_version' => $from !== '' ? 'v' . ltrim($from, 'vV') : '',
            'to_version'   => $to !== '' ? 'v' . ltrim($to, 'vV') : '',
            'at'           => $now,
            'operator'     => mb_substr($operator !== '' ? $operator : '系统', 0, 20),
            'duration'     => $duration,
            'status'       => $status,
            'note'         => mb_substr($note, 0, 200),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /** 待升级发布说明汇总 */
    private function notesOf(array $pending): string
    {
        $notes = [];
        foreach ($pending as $r) {
            $v = ltrim((string)$r['version'], 'vV');
            $n = trim((string)($r['note'] ?? ''));
            $notes[] = 'v' . $v . ($n !== '' ? '（' . $n . '）' : '');
        }
        return implode('、', $notes);
    }

    /** 秒 → 「X 分 Y 秒」 */
    private function humanDuration(float $start): string
    {
        $sec = (int)round(microtime(true) - $start);
        if ($sec < 60) {
            return $sec . ' 秒';
        }
        return intdiv($sec, 60) . ' 分 ' . ($sec % 60) . ' 秒';
    }
}
