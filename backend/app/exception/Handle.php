<?php
// =====================================================================
// booking 后端 · API 统一异常处理（JSON 契约 {code, msg, data}）
// 继承 think\exception\Handle，覆盖 render 把所有异常渲染成 JSON：
//   1. API 后端异常必须是 JSON，前端 fetch 统一按 {code,msg,data} 解析
//   2. 根治 PHP8.2 下 think_exception.tpl htmlentities(null) deprecation
//      被 ErrorException 化后递归渲染异常页直至 fatal 的问题（HTML 模板不再走）
// 通过 app/provider.php 容器绑定替换框架默认 Handle
// =====================================================================

namespace app\exception;

use think\exception\Handle as ThinkHandle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Request;
use think\Response;

class Handle extends ThinkHandle
{
    /**
     * 异常 → JSON 响应（HTTP 状态码保留，body 统一 {code,msg,data}）
     * @param Request    $request
     * @param \Throwable $e
     * @return Response
     */
    public function render(Request $request, \Throwable $e): Response
    {
        // 框架/业务主动抛出的响应异常：原样透传
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidateException) {
            $payload = ['code' => 400, 'msg' => $e->getMessage(), 'data' => []];
            return Response::create($payload, 'json')->code(400);
        }

        if ($e instanceof HttpException) {
            $status  = $e->getStatusCode();
            $payload = ['code' => $status, 'msg' => $this->httpMsg($status), 'data' => []];
            return Response::create($payload, 'json')->code($status);
        }

        // 其余异常：500 + 调试期带定位信息（生产环境不泄露内部细节）
        $msg = '服务器内部错误';
        if ($this->app->isDebug()) {
            $msg = $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
        }
        $payload = ['code' => 500, 'msg' => $msg, 'data' => []];
        return Response::create($payload, 'json')->code(500);
    }

    /**
     * HTTP 状态码 → 业务提示语
     * @param int $status
     * @return string
     */
    protected function httpMsg(int $status): string
    {
        $map = [
            401 => '未登录或 token 已过期',
            403 => '没有操作权限',
            404 => '接口不存在',
            405 => '请求方法不允许',
            429 => '请求过于频繁，请稍后再试',
        ];
        return $map[$status] ?? '请求失败（HTTP ' . $status . '）';
    }
}
