<?php
// =====================================================================
// booking 后端 · 账号安全策略服务（密码复杂度 / 登录失败锁定 / 异地提醒）
// 设计：所有阈值走 setting（禁止写死代码），无配置时用保守默认值；
//       默认「长度≥8 + 单类字符」等价于历史行为，保证存量账号与演示数据不受影响。
// =====================================================================

namespace app\service;

use think\facade\Db;

class SecurityService
{
    /**
     * 读取安全策略配置（setting 缺失时回落默认值）
     * @return array{minLen:int,charTypes:int,lockThresh:int,lockMinutes:int,remoteAlert:bool}
     */
    public static function config(): array
    {
        $keys = ['pwd_min_length', 'pwd_char_types', 'login_lock_threshold', 'login_lock_minutes', 'login_remote_alert'];
        $rows = Db::name('setting')->whereIn('key', $keys)->column('value', 'key');
        $int = static function (string $k, int $d) use ($rows): int {
            return isset($rows[$k]) && $rows[$k] !== '' ? (int)$rows[$k] : $d;
        };
        return [
            'minLen'      => max(6, $int('pwd_min_length', 8)),          // 下限 6，防误配成 1 位
            'charTypes'   => min(4, max(1, $int('pwd_char_types', 1))),  // 需含几类字符（小写/大写/数字/符号）
            'lockThresh'  => max(0, $int('login_lock_threshold', 5)),    // 连续失败几次锁定；0=不启用锁定
            'lockMinutes' => max(1, $int('login_lock_minutes', 15)),     // 锁定时长（分钟）
            'remoteAlert' => (string)($rows['login_remote_alert'] ?? '1') === '1',
        ];
    }

    /**
     * 校验密码强度：通过返回 null，否则返回中文错误提示
     * @param string $pwd 明文密码
     * @return string|null
     */
    public static function checkPassword(string $pwd): ?string
    {
        $cfg = self::config();
        if (strlen($pwd) < $cfg['minLen']) {
            return "密码长度至少 {$cfg['minLen']} 位";
        }
        // 若要求单一类型（charTypes<=1）则不检查字符类别，保持与历史「仅长度」行为一致
        if ($cfg['charTypes'] > 1) {
            $types = 0;
            if (preg_match('/[a-z]/', $pwd)) {
                $types++;
            }
            if (preg_match('/[A-Z]/', $pwd)) {
                $types++;
            }
            if (preg_match('/\d/', $pwd)) {
                $types++;
            }
            if (preg_match('/[^a-zA-Z0-9]/', $pwd)) {
                $types++;
            }
            if ($types < $cfg['charTypes']) {
                return "密码需包含至少 {$cfg['charTypes']} 类字符（小写、大写、数字、符号）";
            }
        }
        return null;
    }

    /**
     * 是否处于锁定期（locked_until 晚于当前时间）
     * @param string|null $lockedUntil
     * @return bool
     */
    public static function isLocked(?string $lockedUntil): bool
    {
        return $lockedUntil !== null && $lockedUntil !== '' && $lockedUntil > date('Y-m-d H:i:s');
    }

    /**
     * 判定是否异地登录：比较新旧 IPv4 的前两段网段（前两段不同视为跨城/异地）
     * 非 IPv4、旧 IP 为空、格式非法一律返回 false（不阻断，仅用于提醒）
     * @param string $oldIp 上次登录 IP
     * @param string $newIp 本次登录 IP
     * @return bool
     */
    public static function isRemoteLogin(string $oldIp, string $newIp): bool
    {
        if ($oldIp === '' || $newIp === '' || $oldIp === $newIp) {
            return false;
        }
        $seg = static function (string $ip): ?string {
            if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $ip, $m)) {
                return null;
            }
            return $m[1] . '.' . $m[2];
        };
        $o = $seg($oldIp);
        $n = $seg($newIp);
        return $o !== null && $n !== null && $o !== $n;
    }
}
