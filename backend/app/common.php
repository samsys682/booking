<?php
// =====================================================================
// booking 后端 · 公共助手函数
// 接口规范：统一响应 {code, msg, data}、统一错误码表、统一分页
// =====================================================================

use think\facade\Db;
use think\response\Json;

// ---------------------------------------------------------------------
// 统一响应：成功
// ---------------------------------------------------------------------
if (!function_exists('ok')) {
    /**
     * 成功响应 {code:0, msg:'ok', data:...}
     * @param mixed  $data 业务数据
     * @param string $msg  提示语
     * @return Json
     */
    function ok($data = [], string $msg = 'ok'): Json
    {
        return Json::create(['code' => 0, 'msg' => $msg, 'data' => $data], 'json', 200);
    }
}

// ---------------------------------------------------------------------
// 统一响应：失败（错误码表见 docs/API.md）
// ---------------------------------------------------------------------
if (!function_exists('err')) {
    /**
     * 失败响应 {code, msg, data:[]}
     * @param int    $code 业务错误码（非 HTTP 状态码）
     * @param string $msg  错误提示
     * @param mixed  $data 附加数据
     * @param int    $http HTTP 状态码
     * @return Json
     */
    function err(int $code, string $msg = 'error', $data = [], int $http = 200): Json
    {
        return Json::create(['code' => $code, 'msg' => $msg, 'data' => $data], 'json', $http);
    }
}

// ---------------------------------------------------------------------
// 统一分页载荷
// ---------------------------------------------------------------------
if (!function_exists('page_data')) {
    /**
     * 分页响应体（接口规范统一分页结构）
     * @param array $rows     当前页记录
     * @param int   $total    总条数
     * @param int   $page     当前页
     * @param int   $perPage  每页条数
     * @return array
     */
    function page_data(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'list'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'pages'     => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }
}

// ---------------------------------------------------------------------
// 输入消毒：防 XSS 存储（输出侧另由前端转义，双保险）
// ---------------------------------------------------------------------
if (!function_exists('clean_input')) {
    /**
     * 清洗字符串输入（去除控制字符与首尾空白，长度截断）
     * @param mixed $value
     * @param int   $max 最大长度
     * @return string
     */
    function clean_input($value, int $max = 255): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        // 去除不可见控制字符（防日志注入/绕过）
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }
        return substr($value, 0, $max);
    }
}

// ---------------------------------------------------------------------
// 金额：分 → 元（前端展示转换，服务端存储/计算一律分）
// ---------------------------------------------------------------------
if (!function_exists('fen_to_yuan')) {
    /**
     * 分转元字符串（保留两位小数）
     * @param int $fen
     * @return string
     */
    function fen_to_yuan(int $fen): string
    {
        return number_format($fen / 100, 2, '.', '');
    }
}

// ---------------------------------------------------------------------
// 幂等键生成（支付/回调场景）
// ---------------------------------------------------------------------
if (!function_exists('trade_no')) {
    /**
     * 生成商户单号（TP + 秒 + 微秒 6 位 + 4 位随机，唯一索引兜底去重）
     * 熵：每秒 10^10 空间，同秒高并发碰撞概率可忽略
     * @return string
     */
    function trade_no(): string
    {
        $micro = explode(' ', microtime())[0]; // "0.12345678"
        return 'TP' . date('YmdHis') . substr($micro, 2, 6) . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}

// ---------------------------------------------------------------------
// 业务单号生成（MC/SV/CPN 等序号，MemberActionController 与 MyController 共用）
// ---------------------------------------------------------------------
if (!function_exists('next_no')) {
    /**
     * 单号生成（MC/SV+序号）。
     * ⚠️ 不能用 max() 聚合：think-orm 对 varchar 列聚合返回 float 0.0（坑 A）；
     *    也不能 order(desc)->value 取首行：历史号宽度不一（MC003 > MC0004 字符串序），
     *    会反复生成同一号撞 uk 唯一键。改为 column() 全量取号按数值取 max（坑 AA）
     * @param string $table  表名（不含前缀）
     * @param string $col    单号列
     * @param string $prefix 前缀，如 MC / SV
     * @return string
     */
    function next_no(string $table, string $col, string $prefix): string
    {
        $max = 0;
        foreach ((array)Db::name($table)->where($col, 'like', $prefix . '%')->column($col) as $no) {
            if (preg_match('/(\d+)$/', (string)$no, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('next_coupon_code')) {
    /**
     * 券码（CPN-XXXX-XXXX 随机，uk_code 唯一；碰撞重试 5 次，兜底 hex）
     * @return string
     */
    function next_coupon_code(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $seg = static function () use ($chars) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $s;
        };
        for ($try = 0; $try < 5; $try++) {
            $code = 'CPN-' . $seg() . '-' . $seg();
            $exists = Db::name('coupon_instance')->where('code', $code)->where('deleted_at', 0)->count();
            if ($exists === 0) {
                return $code;
            }
        }
        return 'CPN-' . strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
