<?php
// =====================================================================
// booking 后端 · 安装向导（环境检测 → 测库 → 写 .env → 导入 SQL → 建管理员）
// 安全要点：
//   1. 安装完成后写入 install.lock，/api/v1/install/* 全部 404（防重装）
//   2. jwt_secret / pay_notify_key 安装时随机生成，不接受外部传入
//   3. 管理员密码 password_hash 落库，明文不落日志
//   4. 数据库密码仅写入 .env，响应中脱敏
// =====================================================================

namespace app\controller;

use think\facade\Db;
use think\Request;
use think\Response;

class InstallController
{
    /** SQL 文件目录（backend 同级 data/sql） */
    const SQL_DIR = __DIR__ . '/../../../data/sql';

    /**
     * 安装导入的 SQL 文件（顺序即依赖顺序）
     * 07_demo_seed / 08_demo_business / seed_tech_demo 为演示数据，不在安装范围
     * （演示数据由后台「演示数据」入口单独导入，见 ResourceController 之外的数据工厂脚本）
     */
    const SQL_FILES = [
        '01_system.sql',
        '02_service.sql',
        '03_trade.sql',
        '04_member.sql',
        '05_marketing.sql',
        '06_seed.sql',
        '09_license_oa.sql',     // OA 授权表（license）——此前漏导导致激活接口报「表不存在」
        '10_marketing_engine.sql', // 营销执行引擎（活动/拼团/分销提现）
        '11_pricing_engine.sql',  // 价格规则引擎（订单加价落库：surcharge/price_rule）
        '12_booking_rules.sql',   // 预约规则域（会员爽约计数/冻结 + 门店营业时间）
        '13_message_pipeline.sql', // 消息通知管线（发送内容留痕）
        '14_deposit_settlement.sql', // 定金尾款结算（deposit 订单+类型联合索引）
        '15_workflow_scheduler.sql', // 客户运营工作流（workflow_log）+ 自动化营销同日幂等列
        '16_gift_exchange.sql',   // 积分商城兑换单（收货信息 + 发货履约闭环）
        '17_service_agreement.sql', // 电子服务协议（service_agreement + agreement_sign；此前安装清单遗漏，补上防新装缺表）
        '18_travel_fee.sql',      // 路途费/打车费（service_area 中心坐标费率 + booking.travel_fee）
        '19_security.sql',        // 登录与账号安全（admin_account 失败锁定列 + setting.group=security 策略键）
    ];

    /** 安装锁文件（backend 根目录） */
    const LOCK_FILE = __DIR__ . '/../../install.lock';

    /** .env 文件路径（backend 根目录，ThinkPHP 从应用根读取 .env） */
    const ENV_FILE = __DIR__ . '/../../.env';

    /**
     * 环境检测
     * GET /api/v1/install/check
     */
    public function check(Request $request): Response
    {
        if ($this->installed()) {
            return err(404, '系统已安装');
        }

        $checks = [
            ['name' => 'PHP 版本 ≥ 8.0', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION],
            ['name' => 'PDO 扩展', 'ok' => extension_loaded('pdo'), 'value' => phpversion('pdo') ?: '未安装'],
            ['name' => 'PDO_MySQL 驱动', 'ok' => extension_loaded('pdo_mysql'), 'value' => phpversion('pdo_mysql') ?: '未安装'],
            ['name' => 'openssl 扩展（JWT/签名）', 'ok' => extension_loaded('openssl'), 'value' => phpversion('openssl') ?: '未安装'],
            ['name' => 'mbstring 扩展', 'ok' => extension_loaded('mbstring'), 'value' => phpversion('mbstring') ?: '未安装'],
            ['name' => 'JSON 扩展', 'ok' => extension_loaded('json'), 'value' => phpversion('json') ?: '未安装'],
            ['name' => 'backend 根目录可写（.env/install.lock）', 'ok' => is_writable(dirname(self::ENV_FILE)), 'value' => dirname(self::ENV_FILE)],
            ['name' => 'SQL 目录可读', 'ok' => is_readable(self::SQL_DIR), 'value' => self::SQL_DIR],
        ];

        $allOk = true;
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $allOk = false;
                break;
            }
        }
        return ok(['pass' => $allOk, 'checks' => $checks]);
    }

    /**
     * 数据库连通性测试
     * POST /api/v1/install/test-db  {db_host, db_port, db_name, db_user, db_pass}
     */
    public function testDb(Request $request): Response
    {
        if ($this->installed()) {
            return err(404, '系统已安装');
        }
        $cfg = $this->dbInput($request);
        if ($cfg === null) {
            return err(400, '数据库配置不完整');
        }
        try {
            $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            return ok(['ok' => true, 'server_version' => $ver], '数据库连接成功');
        } catch (\PDOException $e) {
            // 只回显驱动错误首行，不泄露连接串/密码
            $msg = preg_split('/\n/', (string)$e->getMessage())[0];
            return err(400, '数据库连接失败：' . $msg);
        }
    }

    /**
     * 执行安装（写 .env → 导入 6 个 SQL → 初始化管理员）
     * POST /api/v1/install/run  {db_host?, db_port?, db_name, db_user, db_pass, admin_account, admin_password, site_name?}
     */
    public function run(Request $request): Response
    {
        if ($this->installed()) {
            return err(404, '系统已安装');
        }
        $cfg = $this->dbInput($request);
        if ($cfg === null) {
            return err(400, '数据库配置不完整');
        }
        $account = clean_input($request->post('admin_account', ''), 50);
        $password = (string)$request->post('admin_password', '');
        $siteName = clean_input($request->post('site_name', '预约上门服务系统'), 50);
        if ($account === '' || strlen($password) < 8) {
            return err(400, '管理员账号不能为空，密码至少 8 位');
        }

        // ---- 1. 测试连接 ----
        try {
            $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            return err(400, '数据库连接失败，请检查配置');
        }

        // ---- 2. 写 .env（密钥随机生成，不接受外部传入）----
        $env = $this->buildEnv($cfg, $siteName);
        if (@file_put_contents(self::ENV_FILE, $env, LOCK_EX) === false) {
            return err(500, '.env 写入失败，请检查目录权限');
        }

        // ---- 3. 按序导入 SQL（01→06）----
        $files = self::SQL_FILES;
        foreach ($files as $file) {
            $path = self::SQL_DIR . DIRECTORY_SEPARATOR . $file;
            if (!is_readable($path)) {
                return err(500, 'SQL 文件缺失：' . $file);
            }
            $sql = (string)file_get_contents($path);
            if (!$this->execSqlFile($pdo, $sql, $file)) {
                return err(500, 'SQL 导入失败：' . $file);
            }
        }

        // ---- 4. 初始化管理员（owner 角色，密码哈希落库）----
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM admin_account WHERE account = " . $pdo->quote($account))->fetchColumn();
        if ($exists === 0) {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare('INSERT INTO admin_account (name, account, password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, (SELECT id FROM role WHERE code = \'owner\' LIMIT 1), 1, ?, ?)');
            $stmt->execute([$account, $account, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
        }

        // ---- 5. 写安装锁（此后 /api/v1/install/* 全部 404）----
        @file_put_contents(self::LOCK_FILE, "installed at " . date('Y-m-d H:i:s') . "\n", LOCK_EX);

        return ok(['site_name' => $siteName, 'admin_account' => $account], '安装完成');
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 是否已安装（install.lock 存在即视为已安装）
     * @return bool
     */
    private function installed(): bool
    {
        return is_file(self::LOCK_FILE);
    }

    /**
     * 读取并校验数据库入参（密码不出参）
     * @param Request $request
     * @return array|null
     */
    private function dbInput(Request $request): ?array
    {
        $name = clean_input($request->post('db_name', ''), 64);
        $user = clean_input($request->post('db_user', ''), 64);
        if ($name === '' || $user === '') {
            return null;
        }
        return [
            'host' => clean_input($request->post('db_host', '127.0.0.1'), 64),
            'port' => clean_input($request->post('db_port', '3306'), 5),
            'name' => $name,
            'user' => $user,
            'pass' => (string)$request->post('db_pass', ''),
        ];
    }

    /**
     * 生成 .env 内容（jwt_secret / pay_notify_key 随机 64 位十六进制）
     * @param array $cfg
     * @param string $siteName
     * @return string
     */
    private function buildEnv(array $cfg, string $siteName): string
    {
        $lines = [
            '[app]',
            'site_name = ' . $siteName,
            'debug = false',
            '',
            '[database]',
            'hostname = ' . $cfg['host'],
            'hostport = ' . $cfg['port'],
            'database = ' . $cfg['name'],
            'username = ' . $cfg['user'],
            'password = ' . $cfg['pass'],
            // 表前缀留空：schema（data/sql/*.sql）建表无前缀，写 bk_ 会导致运行时找不到表
            'prefix =',
            '',
            '[security]',
            'jwt_secret = ' . bin2hex(random_bytes(32)),
            'jwt_ttl = 7200',
            'sensitive_ttl = 300',
            'pay_notify_key = ' . bin2hex(random_bytes(32)),
            '',
        ];
        return implode("\n", $lines);
    }

    /**
     * 「良性重复」MySQL 错误码白名单：重复执行幂等 SQL 时应忽略
     *   1050 表已存在 / 1060 列已存在 / 1061 索引已存在 / 1062 唯一键重复
     *   1091 待 DROP 的列或索引不存在 / 1826 外键名重复
     * 理由：安装与升级共用同一套幂等 SQL（CREATE IF NOT EXISTS + ALTER ADD COLUMN），
     *       存量库重跑必然命中「已存在」类错误，若视为失败会导致安装/升级中断。
     *       《10_marketing_engine.sql》文件头亦声明「duplicate column 属正常，可忽略」。
     * 注意：仅放行结构重复；1146 表不存在、1064 语法错误等一律判失败，不静默吞掉。
     */
    private const BENIGN_SQL_ERRORS = [1050, 1060, 1061, 1062, 1091, 1826];

    /**
     * 执行 SQL 文件（剥离 -- 注释后按 ; 切分逐句执行）
     * 幂等：重复表/列/索引/种子数据视为成功，保证安装与升级可反复执行
     * @param \PDO $pdo
     * @param string $sql
     * @param string $file
     * @return bool
     */
    private function execSqlFile(\PDO $pdo, string $sql, string $file): bool
    {
        // 去掉整行注释（-- 开头）与 /* */ 块注释
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // 按 ; 切分（存储过程/触发器等含 ; 的场景本项目未使用，按简单切分即可）
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                // 驱动错误码在 errorInfo[1]（如 1060），$e->getCode() 是 SQLSTATE 串
                $code = (int)($e->errorInfo[1] ?? 0);
                if (in_array($code, self::BENIGN_SQL_ERRORS, true)) {
                    error_log('[install] ' . $file . ' 忽略幂等冲突 #' . $code . '：' . $e->getMessage());
                    continue;
                }
                error_log('[install] ' . $file . ' SQL error #' . $code . '：' . $e->getMessage());
                return false;
            }
        }
        return true;
    }
}
