<?php
// =====================================================================
// booking 后端 · 门店作用域控制器（多门店数据隔离的联调/自检入口）
// GET /api/v1/stores/scope  当前管理员的门店作用域概览
//   → {store_id, store_name, cross_store, scoped, scoped_resources}
// 只读，不改变任何数据；供前端门店切换器与 E2E 断言使用。
// ⚠️ 须注册在通用 {resource} CRUD 之前（'stores/scope' 会被 POST/GET {resource} 前缀吞掉，坑 C）
// =====================================================================

namespace app\controller;

use app\service\StoreService;
use think\Request;
use think\Response;

class StoreController
{
    /**
     * 门店作用域概览
     * GET /api/v1/stores/scope
     */
    public function scope(Request $request): Response
    {
        return ok((new StoreService())->overview($request));
    }
}
