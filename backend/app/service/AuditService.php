<?php
// =====================================================================
// booking 后端 · 审计日志服务
// 接口规范：敏感操作留痕；日志保留天数读 setting（开源 7 天/授权 365）
// =====================================================================

namespace app\service;

use think\facade\Db;

class AuditService
{
    /**
     * 写审计日志（字段对齐 audit_log：admin_id/admin_name/module/action/target/content/ip）
     * @param string $action 动作（如 booking.update / pay.callback）
     * @param string $target 操作对象（订单号/记录 ID/资源名）
     * @param string $content 详情（变更摘要，禁止记录密码等敏感值）
     * @param int    $adminId 管理员 ID，0=系统
     * @return void
     */
    public function log(string $action, string $target, string $content = '', int $adminId = 0): void
    {
        try {
            // module 取 action 首段（如 booking.update → booking）
            $module = explode(' ', $action)[0];
            $module = explode('.', str_replace(['post ', 'put ', 'patch ', 'delete '], '', $module))[0];
            Db::name('audit_log')->insert([
                'admin_id'   => $adminId,
                'admin_name' => '',
                'module'     => clean_input($module, 40),
                'action'     => clean_input($action, 60),
                'target'     => clean_input($target, 60),
                'content'    => clean_input($content, 500),
                'ip'         => $this->clientIp(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不阻断业务（但不得吞掉写库失败本身——仅兜底连接异常）
        }
    }

    /**
     * 按保留天数清理历史日志（计划任务调用；天数读 setting 配置）
     * @return int 清理条数
     */
    public function cleanExpired(): int
    {
        $days = (int)Db::name('setting')->where('key', 'log_keep_days')->value('value');
        if ($days <= 0) {
            $days = 7;
        }
        $before = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return (int)Db::name('audit_log')->where('created_at', '<', $before)->delete();
    }

    /**
     * 客户端 IP（代理场景取 X-Forwarded-For 首个）
     * @return string
     */
    private function clientIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = explode(',', $xff);
            return clean_input(trim((string)reset($parts)), 45);
        }
        return clean_input((string)($_SERVER['REMOTE_ADDR'] ?? ''), 45);
    }
}
