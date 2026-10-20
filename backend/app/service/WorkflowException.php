<?php
// =====================================================================
// booking 后端 · 工作流异常（流转非法 / 终态必填缺失 / 对象不存在）
// 用途：回访任务、投诉工单的状态机流转校验失败 → 控制器转对应 HTTP 码
//   400 = 业务规则拒绝（非法流转、缺结果）
//   404 = 业务对象不存在
// 依据：《开发规范》§1.2 统一错误码表
// =====================================================================

namespace app\service;

class WorkflowException extends \RuntimeException
{
    /** 建议 HTTP 状态码 */
    public int $http;

    public function __construct(string $message, int $http = 400)
    {
        parent::__construct($message);
        $this->http = $http;
    }
}
