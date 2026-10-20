<?php
// =====================================================================
// booking 后端 · 鉴权中间件
// 接口规范：token 鉴权（Bearer JWT）；登录/安装/支付回调白名单放行
// 未携带或无效 token 统一返回 {code:401} + HTTP 401
// =====================================================================

namespace app\middleware;

use app\service\JwtService;
use think\Request;
use think\Response;

class AuthMiddleware
{
    /** 白名单路径前缀（无需 token） */
    private $guestPaths = [
        '/api/v1/auth/login',             // 管理员登录
        '/api/v1/auth/member-send-code',  // 会员验证码
        '/api/v1/auth/member-login',      // 会员登录
        '/api/v1/auth/member-wx-login',   // 会员微信授权登录（小程序 wx.login，无需 token）
        '/api/v1/auth/tech-login',        // 技师登录
        '/api/v1/install',                // 安装向导（安装后由 install.lock 禁用）
        '/api/v1/pay/notify',             // 支付回调（验签代替 token）
    ];

    /**
     * 白名单（限定方法 + 路径前缀）：'METHOD /path'
     * 仅放开读（GET），同名路径的写操作仍需 token —— 避免前缀白名单误放行写接口
     */
    private $guestMethodPaths = [
        'GET /api/v1/site',               // 站点/品牌信息（登录页展示，无需 token）
    ];

    /**
     * 鉴权
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        $path = '/' . trim($request->pathinfo(), '/');
        foreach ($this->guestPaths as $guest) {
            if (strpos($path, $guest) === 0) {
                return $next($request);
            }
        }
        // 方法级白名单：'METHOD /path' 前缀匹配（只放行指定方法）
        $method = strtoupper($request->method());
        foreach ($this->guestMethodPaths as $rule) {
            $sp = strpos($rule, ' ');
            $m = substr($rule, 0, $sp);
            $p = substr($rule, $sp + 1);
            if ($m === $method && strpos($path, $p) === 0) {
                return $next($request);
            }
        }

        $header = (string)$request->header('authorization', '');
        $token = '';
        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
        }
        if ($token === '') {
            return err(401, '未登录或 token 缺失', [], 401);
        }

        try {
            $payload = (new JwtService())->decode($token);
        } catch (\RuntimeException $e) {
            return err(401, $e->getMessage(), [], 401);
        }

        // 供控制器/审计中间件读取（多端 token：admin / member / tech）
        // admin token 兼容旧载荷（无 type 字段视为 admin）
        $request->tokenType = (string)($payload['type'] ?? 'admin');
        $request->adminId = (int)($payload['aid'] ?? 0);
        $request->adminRole = (string)($payload['role'] ?? '');
        $request->adminPerms = (array)($payload['perms'] ?? []);
        $request->memberId = (int)($payload['mid'] ?? 0);
        $request->techId = (int)($payload['tid'] ?? 0);

        return $next($request);
    }
}
