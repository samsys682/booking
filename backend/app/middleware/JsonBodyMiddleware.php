<?php
// =====================================================================
// booking 后端 · JSON 请求体解析中间件
// ThinkPHP8 的 Request 不会自动把 JSON body 注入 post/put，
// 前端 fetch 统一发 application/json，这里显式解析保证 $request->post()/put() 可读
// =====================================================================

namespace app\middleware;

use think\Request;

class JsonBodyMiddleware
{
    /**
     * 解析 JSON 请求体进 post/put
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        $contentType = (string)$request->contentType();
        if ($contentType !== '' && stripos($contentType, 'json') !== false) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $request->withInput($raw);
            }
        }
        return $next($request);
    }
}
