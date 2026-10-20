<?php
// =====================================================================
// 【开源发行版·禁用桩 · WorkflowService】
// 打包时覆盖 backend/app/service/WorkflowService.php。开源版=回访/投诉工单关闭。
// ResourceController 仅对 follow-up-tasks/complaints 调 markCreated/operatorName，
// 而这两类资源在开源版已被 EditionGate 写拦截挡在前面，静态桩仅需保证不致命。
// =====================================================================

namespace app\service;

class WorkflowService
{
    public function resources(): array
    {
        return [];
    }

    public function config(string $resource): array
    {
        return [];
    }

    public function mustFind(string $resource, int $id): array
    {
        throw new \RuntimeException('回访/投诉工单为授权版功能，升级后即可使用');
    }

    public function transition(string $resource, int $id, string $to, array $payload, int $adminId = 0, string $operator = ''): array
    {
        throw new \RuntimeException('回访/投诉工单为授权版功能，升级后即可使用');
    }

    public function assign(string $resource, int $id, string $owner, int $adminId = 0, string $operator = ''): array
    {
        throw new \RuntimeException('回访/投诉工单为授权版功能，升级后即可使用');
    }

    public function overdueScan(string $resource = ''): array
    {
        return [];
    }

    public function logs(string $resource, int $id, int $limit = 50): array
    {
        return [];
    }

    public static function operatorName(int $adminId): string
    {
        return '系统';
    }

    public static function markCreated(string $resource, int $id, string $status, string $owner, string $operator = ''): void
    {
        // 开源版无工单时间线，空操作
    }
}
