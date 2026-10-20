<?php
// =====================================================================
// booking 后端 · 权限中间件（RBAC）
// 路由用法（TP8 中间件参数走可变参数，不支持 'Class:param' 串语法）：
//   静态权限码  ->middleware(PermissionMiddleware::class, 'booking.edit')
//   资源动态码  ->middleware(PermissionMiddleware::class, 'auto:view')
//               按路由变量 {resource} 查 app/config/resources.php 的 perm 前缀
//               组合成 {perm}.view / {perm}.edit / {perm}.delete 校验
//   特定路由（无 {resource} 变量）须显式传资源名：
//               ->middleware(PermissionMiddleware::class, 'auto:edit', 'reviews')
// 权限码集合随登录签发进 JWT（perms），权限变更需重新登录生效
// =====================================================================

namespace app\middleware;

use think\Request;
use think\Response;

class PermissionMiddleware
{
    /** 公开只读资源（用户端/技师端浏览目录需要）：view 动作对任意已登录端放行，
     *  写操作仍走 RBAC。仅限无隐私数据的目录类资源（会员/订单/财务等一律不开） */
    private const PUBLIC_VIEW_RESOURCES = ['services', 'service-categories', 'service-specs', 'technicians', 'marketing-activities', 'member-tags', 'gifts'];

    /**
     * 权限校验
     * @param Request  $request
     * @param \Closure $next
     * @param string   $perm     需要的权限码（booking.view）或 auto:view|edit|delete
     * @param string   $resource 显式资源名（auto: 模式用；缺省取路由变量 {resource}）
     * @return Response
     */
    public function handle(Request $request, \Closure $next, string $perm = '', string $resource = '')
    {
        if ($perm === '') {
            return $next($request);
        }

        // auto:member-edit = auto:edit + 会员 token 放行（归属校验由控制器兜底，
        // 如会员评价本人订单：ReviewController 校验 booking.member_id）
        $allowMember = false;
        if ($perm === 'auto:member-edit') {
            $perm = 'auto:edit';
            $allowMember = true;
        }

        // auto:tech-edit = auto:edit + 技师 token 放行（归属校验由控制器兜底，
        // 如技师排班休息开关：TechController 校验 schedule.technician_id）
        $allowTech = false;
        if ($perm === 'auto:tech-edit') {
            $perm = 'auto:edit';
            $allowTech = true;
        }

        // 资源路由动态解析：auto:view → {资源perm前缀}.view
        if (strpos($perm, 'auto:') === 0) {
            $action = substr($perm, 5);
            // 特定路由无 {resource} 变量，须由路由显式传入（如 reviews）
            if ($resource === '') {
                $resource = (string)$request->route('resource', '');
            }
            $config = (array)(include app()->getAppPath() . 'config/resources.php');
            $meta = $config[$resource] ?? null;
            if (!$meta || empty($meta['perm'])) {
                return err(404, '资源不存在', [], 404);
            }
            if ($action === 'view' && in_array($resource, self::PUBLIC_VIEW_RESOURCES, true)) {
                return $next($request);
            }
            $perm = (string)$meta['perm'] . '.' . $action;
            // 只读资源禁写（隐藏/只读资源由 ResourceController 再兜一层）
            if (($action === 'edit' || $action === 'delete') && !empty($meta['readonly'])) {
                return err(405, '该资源为只读，不支持修改', [], 405);
            }
        }

        // 会员 token 在 member-edit 路由放行（归属校验在控制器）
        if ($allowMember && (string)($request->tokenType ?? '') === 'member') {
            return $next($request);
        }

        // 技师 token 在 tech-edit 路由放行（归属校验在控制器）
        if ($allowTech && (string)($request->tokenType ?? '') === 'tech') {
            return $next($request);
        }

        $perms = (array)($request->adminPerms ?? []);
        // 超级权限码（owner 全量）
        if (in_array('*', $perms, true) || in_array($perm, $perms, true)) {
            return $next($request);
        }
        return err(403, '无权限执行该操作（需要权限：' . $perm . '）', [], 403);
    }
}
