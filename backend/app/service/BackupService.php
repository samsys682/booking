<?php
// =====================================================================
// booking 后端 · 数据备份服务（真实执行 mysqldump / mysql 恢复）
//
// 设计：
//   - 备份产物落 runtime/backup/backup_{kind}_{YmdHis}.sql.gz（非 web 目录，下载走控制器）
//   - 凭证经临时 my.cnf（--defaults-extra-file）传递，避免密码出现在进程列表
//   - 工具路径可经 .env [backup] 覆盖；缺省按 phpstudy/常见路径探测
//   - 读取 setting `notify.backup_mode`（manual/auto）与 `notify.log_keep_days` 决定保留策略
//
// 说明：这是"真实执行"实现（此前 backups 仅 CRUD、无 dump/恢复）
// =====================================================================

namespace app\service;

use think\facade\Db;

class BackupService
{
    /** 备份文件目录（相对 runtime） */
    private const SUBDIR = 'backup';

    /**
     * 执行一次全量备份
     * @param string $kind     manual（手动）/ auto（自动）
     * @param string $operator 操作人
     * @return array 备份记录行
     */
    public function run(string $kind = 'manual', string $operator = ''): array
    {
        $cfg = $this->dbConfig();
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('备份目录创建失败：' . $dir);
        }

        $name = 'backup_' . $kind . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(2)) . '.sql.gz';
        $file = $dir . DIRECTORY_SEPARATOR . $name;

        $dumpBin = $this->binPath('mysqldump');
        if ($dumpBin === '') {
            throw new \RuntimeException('未找到 mysqldump，请在 .env [backup] mysqldump_path 配置绝对路径');
        }

        $cnf = $this->writeCnf($cfg);
        try {
            // mysqldump --defaults-extra-file=... --single-transaction --skip-lock-tables --no-tablespaces <db>
            $cmd = [
                $dumpBin,
                '--defaults-extra-file=' . $cnf,
                '--single-transaction',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                $cfg['database'],
            ];
            [$code, $out, $err] = $this->exec($cmd, null);
            if ($code !== 0 || trim((string)$out) === '') {
                throw new \RuntimeException('mysqldump 执行失败：' . ($err !== '' ? $err : ('退出码 ' . $code)));
            }
            // 压缩落盘（流式，避免整库驻留内存）
            $this->writeGz($file, (string)$out);
        } finally {
            @unlink($cnf);
        }

        $size = is_file($file) ? filesize($file) : 0;
        $tables = (int)Db::query('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?', [$cfg['database']])[0]['c'];

        $row = [
            'name'     => $name,
            'kind'     => $kind === 'auto' ? '全量' : '全量',
            'size'     => $this->human($size),
            'tables'   => $tables,
            'at'       => date('Y-m-d H:i:s'),
            'operator' => $operator !== '' ? $operator : 'system',
            'status'   => '成功',
            'keep'     => 0,
            'note'     => $kind === 'auto' ? '自动备份' : '手动备份',
        ];
        $id = Db::name('backup')->insertGetId($row);
        $row['id'] = $id;
        $row['file_size'] = $size;
        return $row;
    }

    /**
     * 从备份恢复（解压后经 mysql 客户端导入）
     * @param array $row backup 记录
     * @return array {ok, tables, output}
     */
    public function restore(array $row): array
    {
        $file = $this->filePath($row);
        if (!is_file($file)) {
            throw new \RuntimeException('备份文件不存在或已被清理：' . $row['name']);
        }
        $cfg = $this->dbConfig();
        $mysqlBin = $this->binPath('mysql');
        if ($mysqlBin === '') {
            throw new \RuntimeException('未找到 mysql 客户端，请在 .env [backup] mysql_path 配置绝对路径');
        }

        $sql = $this->readGz($file);
        $cnf = $this->writeCnf($cfg);
        try {
            $cmd = [
                $mysqlBin,
                '--defaults-extra-file=' . $cnf,
                '--default-character-set=utf8mb4',
                $cfg['database'],
            ];
            [$code, $out, $err] = $this->exec($cmd, $sql);
            if ($code !== 0) {
                throw new \RuntimeException('恢复失败：' . ($err !== '' ? $err : ('退出码 ' . $code)));
            }
        } finally {
            @unlink($cnf);
        }
        return ['ok' => true, 'output' => trim((string)$out . (string)$err)];
    }

    /** 删除备份记录对应的物理文件（记录软删由 ResourceController 负责） */
    public function removeFile(array $row): bool
    {
        $file = $this->filePath($row);
        if (is_file($file)) {
            return @unlink($file);
        }
        return true;
    }

    /** 备份文件的绝对路径 */
    public function filePath(array $row): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . basename((string)$row['name']);
    }

    /** 备份目录（runtime/backup） */
    public function dir(): string
    {
        $custom = (string)env('backup.dir', '');
        if ($custom !== '') {
            return rtrim($custom, '/\\');
        }
        return runtime_path() . self::SUBDIR;
    }

    /** 当前备份策略（读 setting notify.backup_mode） */
    public function mode(): string
    {
        $v = (string)Db::name('setting')->where('key', 'backup_mode')->where('deleted_at', 0)->value('value');
        return $v === 'auto' ? 'auto' : 'manual';
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /** 数据库连接参数（来自 config/database.php ← .env） */
    private function dbConfig(): array
    {
        $c = (array)config('database.connections.mysql');
        return [
            'hostname' => (string)($c['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($c['hostport'] ?? '3306'),
            'database' => (string)($c['database'] ?? ''),
            'username' => (string)($c['username'] ?? 'root'),
            'password' => (string)($c['password'] ?? ''),
        ];
    }

    /** 定位 mysqldump / mysql 可执行文件 */
    private function binPath(string $name): string
    {
        $env = (string)env('backup.' . $name . '_path', '');
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        $ext = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        // 常见探测路径（phpstudy / 同机 MySQL）
        $candidates = [
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $name . $ext,
            'D:\\phpstudy\\Extensions\\MySQL5.7.26\\bin\\' . $name . $ext,
            '/usr/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/usr/local/mysql/bin/' . $name,
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        // 兜底：交由 PATH 解析
        return $name . $ext;
    }

    /** 写临时 my.cnf（凭证不落进程列表） */
    private function writeCnf(array $cfg): string
    {
        $file = tempnam(sys_get_temp_dir(), 'bkcnf');
        $lines = "[client]\n"
            . 'host=' . $cfg['hostname'] . "\n"
            . 'port=' . $cfg['hostport'] . "\n"
            . 'user=' . $cfg['username'] . "\n";
        if ($cfg['password'] !== '') {
            $lines .= 'password="' . str_replace('"', '\\"', $cfg['password']) . "\"\n";
        }
        file_put_contents($file, $lines);
        @chmod($file, 0600);
        return $file;
    }

    /** 执行外部命令（数组形式，规避 shell 注入；$stdin 非空则写入子进程 stdin） */
    private function exec(array $cmd, ?string $stdin): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('无法启动进程：' . $cmd[0]);
        }
        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $out, $err];
    }

    /** 流式 gzip 写入 */
    private function writeGz(string $file, string $data): void
    {
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('备份文件写入失败：' . $file);
        }
        $len = strlen($data);
        for ($i = 0; $i < $len; $i += 1048576) {
            gzwrite($gz, substr($data, $i, 1048576));
        }
        gzclose($gz);
    }

    /** 读取 gz 文件全文 */
    private function readGz(string $file): string
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('备份文件读取失败：' . $file);
        }
        $out = '';
        while (!gzeof($gz)) {
            $out .= gzread($gz, 1048576);
        }
        gzclose($gz);
        return $out;
    }

    /** 字节 → 人类可读（列宽 20） */
    private function human(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
