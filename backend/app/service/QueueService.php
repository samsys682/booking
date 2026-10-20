<?php
// =====================================================================
// 【开源发行版·禁用桩 · QueueService】
// 打包时覆盖 backend/app/service/QueueService.php。开源版=候补排队关闭。
// =====================================================================

namespace app\service;

class QueueService
{
    // 状态常量保留（MyController 以 QueueService::STATUS_* 判定）
    const STATUS_WAITING   = '排队中';
    const STATUS_PROMOTED  = '已补位';
    const STATUS_CANCELLED = '已取消';
    const STATUS_EXPIRED   = '已过号';

    public function enabled(): bool
    {
        return false;
    }

    public function join(array $in): array
    {
        throw new \RuntimeException('候补排队为授权版功能，升级后即可使用');
    }

    public function promote(string $date, string $timeSlot, int $limit = 1): array
    {
        return []; // 开源版无排队数据，递补空转
    }

    public function slotOf(array $booking): array
    {
        return ['', ''];
    }

    public function waiting(string $date, string $timeSlot): int
    {
        return 0;
    }
}
