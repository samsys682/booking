<?php
// =====================================================================
// 【开源发行版·禁用桩 · StoreService】
// 打包时覆盖 backend/app/service/StoreService.php。开源版=单店：
//   · scopeId→0 / scoped→false：通用 CRUD 不做门店隔离（开源只有 1 家门店）；
//   · assertMultiStore / assertCrossStore：命中即拒绝（多门店/跨店为授权版能力）。
// 不含任何多门店隔离算法。
// =====================================================================

namespace app\service;

use think\Request;

class StoreService
{
    public function scopeId(Request $request): int
    {
        return 0; // 开源版不隔离
    }

    public function scoped(string $resource): bool
    {
        return false;
    }

    public function scopeColumn(string $resource): string
    {
        return '';
    }

    public function assertMultiStore(): void
    {
        throw new \RuntimeException('多门店为授权版功能，升级后可添加更多门店');
    }

    public function crossStoreAllowed(int $storeId): bool
    {
        return false;
    }

    public function assertCrossStore(int $ownerStoreId, int $opStoreId, int $entityCrossStore, string $entity = '该卡'): void
    {
        throw new \RuntimeException('跨门店通用为授权版功能，升级后即可使用');
    }

    public function overview(Request $request): array
    {
        return [];
    }
}
