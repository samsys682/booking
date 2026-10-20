<?php
// =====================================================================
// booking 后端 · CORS 跨域中间件（链首，先于鉴权）
// 三端 dev server 经 vite proxy 同源转发不触发跨域；直连后端（支付回调调试/
// 静态部署/第三方）需跨域头。OPTIONS 预检在此直接应答 204，不进鉴权
// =====================================================================

namespace app\middleware;

use think\Request;
use think\Response;

class CorsMiddleware
{
    /** 预检允许的请求头（JSON body + Bearer token） */
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With';

    /**
     * 补 CORS 响应头；OPTIONS 预检直接 204 放行
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        if (strtoupper((string)$request->method(true)) === 'OPTIONS') {
            $response = Response::create('', 'html', 204);
        } else {
            $response = $next($request);
        }
        return $response->header([
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
            'Access-Control-Max-Age'       => '86400',
        ]);
    }
}
