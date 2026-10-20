<?php
// =====================================================================
// booking 后端 · 通用资源控制器（40+ 资源统一 CRUD）
// 由 app/config/resources.php 元数据驱动：字段白名单/搜索列/排序/脱敏
// 接口规范：RESTful + {code,msg,data} + 统一分页 + 软删 + 审计
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\QuotaService;
use app\service\SecurityService;
use app\service\StoreService;
use app\service\WorkflowService;
use think\facade\Db;
use think\Request;
use think\Response;

class ResourceController
{
    /** @var array 资源元数据缓存 */
    private $metaCache = [];

    /** @var array|null 全量资源元数据（级联查找用，惰性加载） */
    private $allCache = null;

    /** 资源 → 限额键（新增前断言配额，开源版杠杆，见 QuotaService；月订单量在 OrderController） */
    private const QUOTA_MAP = [
        'technicians' => 'technicians_max',
        'stores'      => 'stores_max',
    ];

    /** 列表分页上限（防超大响应；前端参考列表常按 200/500 拉全量，见 index 注释） */
    private const MAX_PER_PAGE = 500;

    /**
     * 列表（分页 + 关键词 + 精确过滤 + 排序）
     * GET /api/v1/{resource}?page=1&per_page=20&keyword=xx&status=1&sort=id:desc
     */
    public function index(Request $request, string $resource): Response
    {
        try {
            $meta = $this->meta($resource);
        } catch (\RuntimeException $e) {
            return err(404, $e->getMessage());
        }

        $page = max(1, (int)$request->get('page', 1));
        // 分页大小：缺省 20；非法值回落缺省，超上限「夹到上限」而非回落缺省。
        // 此前 >100 一律回落 20，导致前端 per_page=200/500 的参考列表被静默截断到 20 行。
        $perPage = (int)$request->get('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        } elseif ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $query = Db::name($meta['table'])->where('deleted_at', 0);

        // 关键词搜索（LIKE 多列 OR）
        $keyword = clean_input($request->get('keyword', ''), 50);
        if ($keyword !== '' && !empty($meta['search'])) {
            $query->where(function ($q) use ($meta, $keyword) {
                foreach ($meta['search'] as $col) {
                    $q->whereOr($col, 'like', '%' . $keyword . '%');
                }
            });
        }

        // 精确过滤（只允许白名单列，防 SQL 注入式字段探测）
        $filterCols = array_merge($meta['fields'], ['status']);
        foreach (array_unique($filterCols) as $col) {
            $value = $request->get($col, null);
            if ($value !== null && $value !== '') {
                $query->where($col, clean_input($value, 100));
            }
        }

        // 排序白名单（默认 id desc）
        $sort = (string)$request->get('sort', '');
        $order = $meta['order'];
        if ($sort !== '' && strpos($sort, ':') !== false) {
            list($col, $dir) = explode(':', $sort, 2);
            $allowed = array_merge($meta['fields'], ['id', 'created_at', 'updated_at']);
            if (in_array($col, $allowed, true) && in_array(strtolower($dir), ['asc', 'desc'], true)) {
                $order = $col . ' ' . strtolower($dir);
            }
        }

        // 多门店数据隔离：门店账号（store_id>0）仅可见本门店数据（总部/超管 store_id=0 不隔离）
        $storeSvc = new StoreService();
        $scope = $storeSvc->scopeId($request);
        if ($scope > 0 && $storeSvc->scoped($resource)) {
            $query->where($storeSvc->scopeColumn($resource), $scope);
        }

        $total = (int)$query->count();
        $rows = $query->order($order)->page($page)->limit($perPage)->select()->toArray();
        $rows = array_map([$this, 'mask'], $rows);

        return ok(page_data($rows, $total, $page, $perPage));
    }

    /**
     * 详情
     * GET /api/v1/{resource}/{id}
     */
    public function read(Request $request, string $resource, int $id): Response
    {
        try {
            $meta = $this->meta($resource);
        } catch (\RuntimeException $e) {
            return err(404, $e->getMessage());
        }
        $row = Db::name($meta['table'])->where('id', $id)->where('deleted_at', 0)->find();
        if (!$row) {
            return err(404, $meta['label'] . '不存在');
        }
        // 多门店隔离：门店账号访问非本门店记录 → 按「不存在」处理（不泄露他店数据）
        $storeSvc = new StoreService();
        $scope = $storeSvc->scopeId($request);
        if ($scope > 0 && $storeSvc->scoped($resource)) {
            $col = $storeSvc->scopeColumn($resource);
            if ((int)($row[$col] ?? 0) !== $scope) {
                return err(404, $meta['label'] . '不存在');
            }
        }
        return ok($this->enrich($resource, $this->mask($row)));
    }

    /**
     * 详情读时富化（按资源追加派生字段，不改表结构）
     * services：附带 serviceable_technicians（服务→技师反向视图，10-06 问题报告遗留项）——
     * 反查 technician_service + technician，只排除「停用」（在岗/服务中/休假均可约），
     * 供前台派单参考「这个服务有哪些技师能干」。
     * @param string $resource
     * @param array $row
     * @return array
     */
    private function enrich(string $resource, array $row): array
    {
        if ($resource !== 'services') {
            return $row;
        }
        try {
            $techs = Db::name('technician_service')->alias('ts')
                ->join('technician t', 't.id = ts.technician_id')
                ->where('ts.service_id', (int)$row['id'])
                ->where('ts.deleted_at', 0)
                ->where('t.deleted_at', 0)
                ->where('t.status', '<>', '停用')
                ->field('t.id, t.name, t.phone, t.skills, t.status, t.rating')
                ->order('t.id', 'asc')
                ->select()->toArray();
        } catch (\Throwable $e) {
            return $row; // 反查失败不阻断详情主流程
        }
        foreach ($techs as &$t) {
            $t['skills'] = is_array($t['skills']) ? $t['skills'] : (json_decode((string)$t['skills'], true) ?: []);
        }
        unset($t);
        $row['serviceable_technicians'] = $techs;
        return $row;
    }

    /**
     * 新增
     * POST /api/v1/{resource}
     */
    public function create(Request $request, string $resource): Response
    {
        try {
            $meta = $this->meta($resource);
        } catch (\RuntimeException $e) {
            return err(404, $e->getMessage());
        }
        if (!empty($meta['readonly'])) {
            return err(405, $meta['label'] . '为系统记录，不支持新增');
        }
        // 版本功能门禁（开源版不得配置商业功能；读放行保证入口可见）
        if ($deny = $this->gate($resource, 'write')) {
            return $deny;
        }
        // 多门店能力门禁（store.multi）：新增第 2 家及以上门店需授权版（首店不限）
        if ($resource === 'stores') {
            try {
                (new StoreService())->assertMultiStore();
            } catch (\RuntimeException $e) {
                return err(403, $e->getMessage(), [
                    'upgrade' => ['edition' => (new EditionGate())->capabilities()['edition'], 'need' => 'pro', 'needName' => '授权版', 'subject' => 'store.multi', 'tip' => $e->getMessage()],
                ]);
            }
        }
        // 版本配额断言（超限引导升级，不硬断业务线）
        if (isset(self::QUOTA_MAP[$resource])) {
            try {
                (new QuotaService())->assert(self::QUOTA_MAP[$resource]);
            } catch (\RuntimeException $e) {
                return err(400, $e->getMessage());
            }
        }
        // 功能开关前置校验（如候补排队 setting queue_enabled=0 时不得新增）
        if ($deny = $this->requireSetting($meta)) {
            return $deny;
        }

        $data = $this->filterFields($meta, $request->post());
        if (empty($data)) {
            return err(400, '没有可写入的字段');
        }
        $data = $this->encodeJson($meta, $data);
        if ($deny = $this->assertPassword($data)) {
            return $deny;
        }
        $data = $this->hashPassword($meta, $data);
        $data['created_at'] = date('Y-m-d H:i:s');

        try {
            $id = Db::name($meta['table'])->insertGetId($data);
        } catch (\Throwable $e) {
            return err(500, '新增失败：' . $this->safeDbError($e));
        }
        (new AuditService())->log($resource . '.create', (string)$id, $meta['label'] . '新增', (int)($request->adminId ?? 0));

        // 客户运营工作流：回访/投诉新建时补写时间线首条（workflow_log）
        if (in_array($resource, ['follow-up-tasks', 'complaints'], true)) {
            try {
                WorkflowService::markCreated(
                    $resource,
                    (int)$id,
                    (string)($data['status'] ?? ''),
                    (string)($data['owner'] ?? ''),
                    WorkflowService::operatorName((int)($request->adminId ?? 0))
                );
            } catch (\Throwable $e) {
                // 时间线补写失败不影响主流程（审计已留痕）
            }
        }

        $row = Db::name($meta['table'])->where('id', $id)->find();
        return ok($this->mask($row), '新增成功');
    }

    /**
     * 修改
     * PUT /api/v1/{resource}/{id}
     */
    public function update(Request $request, string $resource, int $id): Response
    {
        try {
            $meta = $this->meta($resource);
        } catch (\RuntimeException $e) {
            return err(404, $e->getMessage());
        }
        if (!empty($meta['readonly'])) {
            return err(405, $meta['label'] . '为系统记录，不支持修改');
        }
        // 版本功能门禁
        if ($deny = $this->gate($resource, 'write')) {
            return $deny;
        }
        $row = Db::name($meta['table'])->where('id', $id)->where('deleted_at', 0)->find();
        if (!$row) {
            return err(404, $meta['label'] . '不存在');
        }

        $data = $this->filterFields($meta, $request->put());
        if (empty($data)) {
            return err(400, '没有可写入的字段');
        }
        $data = $this->encodeJson($meta, $data);
        if ($deny = $this->assertPassword($data)) {
            return $deny;
        }
        $data = $this->hashPassword($meta, $data);

        try {
            Db::name($meta['table'])->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            return err(500, '修改失败：' . $this->safeDbError($e));
        }
        (new AuditService())->log($resource . '.update', (string)$id, $meta['label'] . '修改', (int)($request->adminId ?? 0));

        $row = Db::name($meta['table'])->where('id', $id)->find();
        return ok($this->mask($row), '修改成功');
    }

    /**
     * 删除（软删：deleted_at 写时间戳，保留唯一索引活性）
     * DELETE /api/v1/{resource}/{id}
     */
    public function delete(Request $request, string $resource, int $id): Response
    {
        try {
            $meta = $this->meta($resource);
        } catch (\RuntimeException $e) {
            return err(404, $e->getMessage());
        }
        if (!empty($meta['readonly'])) {
            return err(405, $meta['label'] . '为系统记录，不支持删除');
        }
        // 版本功能门禁
        if ($deny = $this->gate($resource, 'write')) {
            return $deny;
        }
        // 内置数据保护（如 is_system=1 的角色禁删）
        $row = Db::name($meta['table'])->where('id', $id)->where('deleted_at', 0)->find();
        if (!$row) {
            return err(404, $meta['label'] . '不存在');
        }
        if (isset($row['is_system']) && (int)$row['is_system'] === 1) {
            return err(403, '内置' . $meta['label'] . '不可删除');
        }

        Db::name($meta['table'])->where('id', $id)->update(['deleted_at' => time()]);
        // 派生数据级联软删（parent 声明驱动，防子记录孤儿化，如 营销活动→拼团团单→参团记录）
        $cascaded = $this->cascadeDelete($meta['table'], $id);
        (new AuditService())->log(
            $resource . '.delete',
            (string)$id,
            $meta['label'] . '删除' . ($cascaded > 0 ? '（级联软删 ' . $cascaded . ' 条派生记录）' : ''),
            (int)($request->adminId ?? 0)
        );
        return ok(['cascaded' => $cascaded], '删除成功');
    }

    /**
     * 功能开关前置校验：资源可声明 require_setting=['key'=>..., 'msg'=>...]，
     * 对应 setting 值不为真时拒绝写操作（例：booking-queues ← queue_enabled）。
     * @param array $meta
     * @return Response|null null=放行
     */
    private function requireSetting(array $meta): ?Response
    {
        $req = $meta['require_setting'] ?? null;
        if (!is_array($req) || empty($req['key'])) {
            return null;
        }
        $value = Db::name('setting')->where('key', (string)$req['key'])->where('deleted_at', 0)->value('value');
        if (in_array((string)$value, ['1', 'true', 'on', 'yes'], true)) {
            return null;
        }
        return err(403, (string)($req['msg'] ?? '该功能未启用'), ['setting' => (string)$req['key']]);
    }

    /**
     * 级联软删派生记录（递归）
     * 依据资源元数据中的 parent 声明：凡 parent.table === $parentTable 的资源即为其子，
     * 按 parent.fk 匹配子行软删，再以子表为父继续下钻（如 活动→团单→参团记录）。
     * @param string $parentTable 父表名
     * @param int $parentId 父行 id
     * @return int 被级联软删的子记录总数
     */
    private function cascadeDelete(string $parentTable, int $parentId): int
    {
        $count = 0;
        foreach ($this->allResources() as $child) {
            if (($child['parent']['table'] ?? '') !== $parentTable) {
                continue;
            }
            $childTable = (string)($child['table'] ?? '');
            $fk = (string)($child['parent']['fk'] ?? '');
            if ($childTable === '' || $fk === '') {
                continue;
            }
            $ids = Db::name($childTable)->where($fk, $parentId)->where('deleted_at', 0)->column('id');
            if (!$ids) {
                continue;
            }
            Db::name($childTable)->whereIn('id', $ids)->update(['deleted_at' => time()]);
            $count += count($ids);
            foreach ($ids as $cid) {
                $count += $this->cascadeDelete($childTable, (int)$cid);
            }
        }
        return $count;
    }

    // ==================================================================
    // 内部工具
    // ==================================================================

    /**
     * 版本功能门禁（写操作拦截，读放行）
     * 拦截响应带 upgrade 载荷（当前版本/所需版本/引导文案），前端据此弹升级引导，
     * 不做「空白无解释」的拒绝（《开发计划》§2.6 付费转化设计③）
     * @param string $resource
     * @param string $action read|write
     * @return Response|null null=放行
     */
    private function gate(string $resource, string $action = 'write'): ?Response
    {
        $gate = new EditionGate();
        if ($gate->assertResource($resource, $action)) {
            return null;
        }
        $deny = $gate->lastDeny();
        return err(403, $deny['tip'] ?: '当前版本不支持该功能', [
            'upgrade' => [
                'edition'   => $deny['edition'],
                'need'      => $deny['need'],
                'needName'  => $deny['needName'],
                'subject'   => $resource,
                'tip'       => $deny['tip'],
            ],
        ]);
    }

    /**
     * 取资源元数据
     * @param string $resource
     * @return array
     * @throws \RuntimeException 资源不存在
     */
    private function meta(string $resource): array
    {
        if (isset($this->metaCache[$resource])) {
            return $this->metaCache[$resource];
        }
        $all = $this->allResources();
        if (!isset($all[$resource])) {
            throw new \RuntimeException('资源不存在：' . $resource);
        }
        $this->metaCache[$resource] = $all[$resource];
        return $all[$resource];
    }

    /**
     * 全量资源元数据（惰性加载 + 进程内缓存；级联查找需要跨资源遍历）
     */
    private function allResources(): array
    {
        if ($this->allCache === null) {
            $this->allCache = require __DIR__ . '/../config/resources.php';
        }
        return $this->allCache;
    }

    /**
     * 按字段白名单过滤输入（防批量赋值越权）
     * @param array $meta
     * @param array $input
     * @return array
     */
    private function filterFields(array $meta, array $input): array
    {
        $data = [];
        foreach ($meta['fields'] as $col) {
            if (!array_key_exists($col, $input)) {
                continue;
            }
            $value = $input[$col];
            if (is_array($value)) {
                $data[$col] = $value; // JSON 列，encodeJson 处理
            } else {
                // 数值/时间字段原样，字符串统一消毒
                if (is_int($value) || is_float($value)) {
                    $data[$col] = $value;
                } else {
                    $data[$col] = clean_input($value, 2000);
                }
            }
        }
        // 密码空串 = 不修改
        if (isset($data['password']) && $data['password'] === '') {
            unset($data['password']);
        }
        return $data;
    }

    /**
     * JSON 列编码（covers/gallery/skills/images 等数组入参）
     * @param array $meta
     * @param array $data
     * @return array
     */
    private function encodeJson(array $meta, array $data): array
    {
        $jsonCols = ['covers', 'gallery', 'skills', 'certs', 'images', 'service_ids', 'target_ids', 'ladder', 'raw'];
        foreach ($jsonCols as $col) {
            if (isset($data[$col]) && is_array($data[$col])) {
                $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE);
            }
        }
        return $data;
    }

    /**
     * 密码字段哈希（明文密码只进不出）
     * @param array $meta
     * @param array $data
     * @return array
     */
    private function hashPassword(array $meta, array $data): array
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        return $data;
    }

    /**
     * 密码策略校验（建号/重置/改号统一走此，与登录改密同源 SecurityService）
     * @param array $data 已 filter 的数据（含明文 password 时校验）
     * @return Response|null 命中返回 err 响应，通过返回 null
     */
    private function assertPassword(array $data): ?Response
    {
        if (isset($data['password']) && $data['password'] !== '') {
            if ($pwdErr = SecurityService::checkPassword((string)$data['password'])) {
                return err(400, $pwdErr);
            }
        }
        return null;
    }

    /**
     * 输出脱敏（隐藏 password 等敏感列）
     * @param array $row
     * @return array
     */
    private function mask(array $row): array
    {
        foreach (['password'] as $col) {
            unset($row[$col]);
        }
        return $row;
    }

    /**
     * 数据库错误安全化（生产不泄露 SQL 细节）
     * @param \Throwable $e
     * @return string
     */
    private function safeDbError(\Throwable $e): string
    {
        if ((bool)env('app_debug', false)) {
            return $e->getMessage();
        }
        // 唯一键冲突友好提示
        if (stripos($e->getMessage(), 'duplicate') !== false) {
            return '存在重复数据（唯一字段冲突）';
        }
        return '数据写入异常';
    }
}
