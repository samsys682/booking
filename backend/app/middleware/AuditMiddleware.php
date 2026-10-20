<?php
// =====================================================================
// booking 后端 · 审计中间件（写操作留痕）
// 接口规范：敏感操作留痕；只记录写方法（POST/PUT/PATCH/DELETE）
// 明细只记录方法+路径+资源，不记录请求体（防密码等敏感值入库）
// =====================================================================

namespace app\middleware;

use app\service\AuditService;
use think\Request;
use think\Response;

class AuditMiddleware
{
    /** 不记录的路径（安装噪音/健康检查） */
    private $skipPaths = [
        '/api/v1/install',
    ];

    /**
     * 写操作审计
     * @param Request  $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next)
    {
        $method = strtoupper($request->method());
        $path = '/' . trim($request->pathinfo(), '/');

        $response = $next($request);

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }
        foreach ($this->skipPaths as $skip) {
            if (strpos($path, $skip) === 0) {
                return $response;
            }
        }

        // 明细不放请求体（防敏感值入库），响应状态码仅作留痕补充
        (new AuditService())->log(
            strtolower($method) . ' ' . $path,
            $path,
            'HTTP ' . (string)$response->getCode(),
            (int)($request->adminId ?? 0)
        );

        return $response;
    }
}
