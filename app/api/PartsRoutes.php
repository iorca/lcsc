<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Inventory\Service\PartService;

require_once __DIR__ . '/../../modules/inventory/Service/PartManager.php';

/** Part 模块 API 路由 */
final class PartsRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db = \getDB();
        $uid = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            // 读操作
            case 'list':
                self::handleList($db, $uid, $dataUid);
                break;

            case 'stats':
                self::handleStats($db, $uid, $dataUid);
                break;

            case 'detail':
                Response::ok(self::queryPartDetail($db, $uid, $dataUid, intval($_GET['part_id'] ?? 0)));
                break;

            case 'alt_lookup':
                self::handleAltLookup($db, $uid, $dataUid);
                break;

            case 'cost_summary':
                self::handleCostSummary($db, $uid, $dataUid);
                break;

            case 'edit_detail':
                self::handleEditDetail($db, $uid, $dataUid);
                break;

            case 'search_paged':
                self::handleSearchPaged($db, $uid, $dataUid);
                break;

            case 'alt_search':
                self::handleAltSearch($db, $uid, $dataUid);
                break;

            case 'alt_add':
                self::handleAltAdd($db, $uid, $dataUid);
                break;

            case 'location_options':
                self::handleLocationOptions($db, $dataUid);
                break;

            // 写操作
            case 'create':
                self::handleCreate($db, $uid, $dataUid);
                break;

            case 'update':
                self::handleUpdate($db, $uid, $dataUid);
                break;

            case 'delete':
                self::handleDelete($db, $uid, $dataUid);
                break;

            case 'batch_delete':
                self::handleBatchDelete($db, $uid, $dataUid);
                break;

            case 'update_status':
                self::handleUpdateStatus($db, $uid, $dataUid);
                break;

            case 'batch_set_category':
                self::handleBatchSetCategory($db, $uid, $dataUid);
                break;

            case 'batch_set_location':
                self::handleBatchSetLocation($db, $uid, $dataUid);
                break;

            case 'batch_set_remark':
                self::handleBatchSetRemark($db, $uid, $dataUid);
                break;

            case 'advanced_search':
                self::handleAdvancedSearch($db, $dataUid);
                break;

            // ── P10 批量合并 ──
            case 'duplicate_groups':
                self::handleDuplicateGroups($db, $uid, $dataUid);
                break;

            case 'batch_merge':
                self::handleBatchMerge($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 parts 方法：' . $method, 404);
        }
    }

    /** 取数：物料列表 */
    public static function queryList(\PDO $db, int $uid, int $dataUid, array $params): array
    {
        return (new PartService($db, $uid, $dataUid))->getList($params);
    }

    /** 取数：物料统计 */
    public static function queryStats(\PDO $db, int $uid, int $dataUid): array
    {
        return (new PartService($db, $uid, $dataUid))->getStats();
    }

    /** 取数：物料详情摘要 */
    public static function queryPartDetail(\PDO $db, int $uid, int $dataUid, int $partId): array
    {
        \recalcAvgCost($db, $partId);
        return (new \PartManager($db, $uid, $dataUid))->getPartDetail($partId);
    }

    /** GET parts.list — 物料列表（参数名对齐 PartService::getList） */
    private static function handleList(\PDO $db, int $uid, int $dataUid): void
    {
        $params = [
            'q'        => $_GET['q'] ?? '',
            'cat'      => $_GET['cat'] ?? '',
            'cats'     => $_GET['cats'] ?? '',
            'plat'     => $_GET['plat'] ?? '',
            'loc'      => $_GET['loc'] ?? '',
            'filter'   => $_GET['filter'] ?? '',
            'status'   => $_GET['status'] ?? '',

            'sort'     => $_GET['sort'] ?? 'update_time',
            'dir'      => $_GET['dir'] ?? 'desc',
            'page'     => (int)($_GET['page'] ?? 1),
            'per_page' => (int)($_GET['per_page'] ?? 25),
            // 库位匹配模式：exact=精确库位，fuzzy=库位名包含（默认）
            'location_match' => $_GET['location_match'] ?? 'fuzzy',
        ];
        Response::ok(self::queryList($db, $uid, $dataUid, $params));
    }

    /** GET parts.stats — 物料统计（首页用） */
    private static function handleStats(\PDO $db, int $uid, int $dataUid): void
    {
        Response::ok(self::queryStats($db, $uid, $dataUid));
    }

    /** 取数：编辑弹窗完整字段 */
    public static function queryEditDetail(\PDO $db, int $uid, int $dataUid, int $partId): array
    {
        return (new \PartManager($db, $uid, $dataUid))->getPartEditData($partId);
    }

    /** GET parts.edit_detail — 编辑弹窗完整字段（统一数据源） */
    private static function handleEditDetail(\PDO $db, int $uid, int $dataUid): void
    {
        Response::ok(self::queryEditDetail($db, $uid, $dataUid, intval($_GET['part_id'] ?? 0)));
    }

    /** 取数：全物料分页模糊搜索 */
    public static function queryPartsSearch(\PDO $db, int $uid, int $dataUid, string $q, int $page, int $perPage = 15): array
    {
        return (new \PartManager($db, $uid, $dataUid))->searchPartsPaged($q, $page, $perPage);
    }

    /** GET parts.search_paged — BOM 替代料绑定弹窗：全物料分页模糊搜索（固定每页 15 条） */
    private static function handleSearchPaged(\PDO $db, int $uid, int $dataUid): void
    {
        Response::ok(self::queryPartsSearch(
            $db,
            $uid,
            $dataUid,
            (string)($_GET['q'] ?? ''),
            intval($_GET['page'] ?? 1),
            15
        ));
    }

    /** GET parts.alt_lookup — 替代料批量查询（ids=1,2,3） */
    private static function handleAltLookup(\PDO $db, int $uid, int $dataUid): void
    {
        $idsParam = trim((string)($_GET['ids'] ?? ''));
        $ids = array_filter(array_map('intval', explode(',', $idsParam)), fn($v) => $v > 0);
        $pm = new \PartManager($db, $uid, $dataUid);
        Response::ok($pm->altLookup($ids));
    }

    /** GET parts.cost_summary — 成本摘要（part_id=123，受 can_view_cost 控制） */
    private static function handleCostSummary(\PDO $db, int $uid, int $dataUid): void
    {
        $partId = (int)($_GET['part_id'] ?? 0);
        $pm = new \PartManager($db, $uid, $dataUid);
        Response::ok($pm->getCostSummary($partId));
    }

    /** 取数：去重后的库位文本列表 */
    public static function queryLocationOptions(\PDO $db, int $dataUid, int $limit = 200): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $db->prepare(
            "SELECT location, COUNT(*) AS cnt
             FROM parts
             WHERE user_id=? AND status!='deleted' AND location IS NOT NULL AND location<>''
             GROUP BY location
             ORDER BY cnt DESC, location ASC
             LIMIT " . $limit
        );
        $stmt->execute([$dataUid]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = ['location' => (string)$r['location'], 'cnt' => (int)$r['cnt']];
        }
        return ['options' => $out];
    }

    /** GET parts.location_options — 去重后的库位文本列表（库位输入框历史值补全） */
    private static function handleLocationOptions(\PDO $db, int $dataUid): void
    {
        Response::ok(self::queryLocationOptions($db, $dataUid, (int)($_GET['limit'] ?? 50)));
    }

    /** GET parts.alt_search — 替代料搜索（q=LM358，支持 #internal_id 精确匹配） */
    private static function handleAltSearch(\PDO $db, int $uid, int $dataUid): void
    {
        $q = (string)($_GET['q'] ?? '');
        $pm = new \PartManager($db, $uid, $dataUid);
        Response::ok($pm->altSearch($q));
    }

    /** POST parts.alt_add — 快捷添加替代料（part_id, alt_ids[]，双向互绑写入 part_alternatives） */
    private static function handleAltAdd(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId = (int)($_POST['part_id'] ?? 0);
        $altIds = $_POST['alt_ids'] ?? [];
        if (!is_array($altIds)) {
            $decoded = json_decode((string)$_POST['alt_ids'], true);
            $altIds  = is_array($decoded) ? $decoded : [];
        }
        $altIds = array_values(array_filter(array_map('intval', $altIds), fn($v) => $v > 0));
        if ($partId <= 0 || !$altIds) Response::error('参数无效');

        $pm  = new \PartManager($db, $uid, $dataUid);
        $ok  = $pm->addAlternatives($partId, $altIds);
        if (!$ok) Response::error('添加替代料失败');
        Response::ok(['part_id' => $partId, 'count' => count($altIds)], '已添加 ' . count($altIds) . ' 个替代料');
    }

    /** 权限校验：编辑 */
    private static function requireEdit(): void
    {
        if (!\hasPermission('can_edit')) Response::error('无编辑权限', 403);
    }

    /** 权限校验：删除 */
    private static function requireDelete(): void
    {
        if (!\hasPermission('can_delete')) Response::error('无删除权限', 403);
    }

    /** 权限校验：编辑 + 批量 */
    private static function requireEditBatch(): void
    {
        if (!\hasPermission('can_edit') || !\hasPermission('can_batch')) Response::error('权限不足', 403);
    }

    /** GET parts.duplicate_groups — 疑似重复物料分组（model+brand+package 聚合） */
    private static function handleDuplicateGroups(\PDO $db, int $uid, int $dataUid): void
    {
        $pm = new \PartManager($db, $uid, $dataUid);
        Response::ok($pm->duplicateGroups(trim((string)($_GET['q'] ?? ''))));
    }

    /** POST parts.batch_merge — 批量合并（can_edit+can_batch，需 confirm='1' 二次确认） */
    private static function handleBatchMerge(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEditBatch();
        if (($_POST['confirm'] ?? '') !== '1') {
            Response::error('缺少二次确认（confirm=1），合并操作不可执行', 400);
        }
        $targetId  = (int)($_POST['target_id'] ?? 0);
        $sourceIds = json_decode((string)($_POST['source_ids'] ?? '[]'), true);
        if (!is_array($sourceIds) || !$sourceIds) Response::error('请选择要合并的源物料');

        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $result = $pm->mergeParts($targetId, $sourceIds);
        } catch (\PartException $e) {
            Response::error($e->getMessage(), $e->errCode === 4 ? 413 : 400);
        }
        Response::ok($result, "合并完成：{$result['merged']} 个源物料并入主物料 #{$result['target_id']}");
    }

    /** P6 树形库位绑定：表单携带 location_id（数字，0=解绑）且团队版开启时， */
    private static function bindLocationIfProvided(\PDO $db, int $uid, int $dataUid, int $partId): void
    {
        if ($partId <= 0) return;
        if (!isset($_POST['location_id']) || $_POST['location_id'] === '') return;
        if (!ctype_digit((string)$_POST['location_id'])) return;
        if (!\hasFeature('location_manage')) return;

        $svc = new \Modules\Location\Service\LocationService($db, $uid, $dataUid);
        $svc->bindPart($partId, (int)$_POST['location_id']);
    }

    /** POST parts.create — 新增物料 */
    private static function handleCreate(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $result = $pm->addPart($_POST);

            if (!empty($result['part_id'])) self::bindLocationIfProvided($db, $uid, $dataUid, (int)$result['part_id']);
            if ($isAjax) Response::ok($result, '添加成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.update — 编辑物料 */
    private static function handleUpdate(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $result = $pm->editPart($_POST);

            if (!empty($result['part_id'])) self::bindLocationIfProvided($db, $uid, $dataUid, (int)$result['part_id']);
            if ($isAjax) Response::ok($result, '编辑成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.delete — 删除物料 */
    private static function handleDelete(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireDelete();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $result = $pm->deletePart((int)($_POST['id'] ?? 0));
            if ($isAjax) Response::ok($result, '删除成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.batch_delete — 批量删除 */
    private static function handleBatchDelete(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireDelete();
        if (!\hasPermission('can_batch')) Response::error('权限不足', 403);
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $ids = array_map('intval', $_POST['ids'] ?? []);
            $ids = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重）
            $result = $pm->batchDelete($ids);
            if ($isAjax) Response::ok($result, '批量删除成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.update_status — 修改物料状态 */
    private static function handleUpdateStatus(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId   = (int)($_POST['part_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $allowed = ['active', 'frozen', 'scrapped', 'archived'];
        if ($partId <= 0 || !in_array($newStatus, $allowed, true)) {
            Response::error('参数无效');
        }
        $stmt = $db->prepare("UPDATE parts SET status=?, update_time=NOW() WHERE id=? AND user_id=?");
        $stmt->execute([$newStatus, $partId, $dataUid]);
        \traceLog($uid, 'update_status', 'part', $partId, "状态改为{$newStatus}");
        Response::ok(['affected' => $stmt->rowCount()]);
    }

    /** POST parts.batch_set_category — 批量设置分类 */
    private static function handleBatchSetCategory(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEditBatch();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $ids    = array_map('intval', $_POST['ids'] ?? []);
            $ids    = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重）
            $catId  = (int)($_POST['category_id'] ?? 0);
            $newCat = trim((string)($_POST['new_category'] ?? ''));
            $result = $pm->batchSetCategory($ids, $catId, $newCat);
            if ($isAjax) Response::ok($result, '分类设置成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.batch_set_location — 批量设置库位 */
    private static function handleBatchSetLocation(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEditBatch();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $ids      = array_map('intval', $_POST['ids'] ?? []);
            $ids      = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重；库位绑定按 id 循环，重复会重复绑定）
            $location = trim((string)($_POST['location'] ?? ''));

            if (isset($_POST['location_id']) && ctype_digit((string)$_POST['location_id'])
                && \hasFeature('location_manage')) {
                $locId  = (int)$_POST['location_id'];
                $svc    = new \Modules\Location\Service\LocationService($db, $uid, $dataUid);
                $bound  = 0;
                foreach ($ids as $pid) {
                    if ($pid <= 0) continue;
                    $r = $svc->bindPart($pid, $locId);
                    if ($r['ok']) $bound++;
                }
                if ($bound === 0) Response::error('批量绑定库位失败');
                if ($isAjax) Response::ok(['bound' => $bound, 'total' => count($ids)], "库位设置成功（{$bound} 项）");
                Response::redirect('index.php?flash=ok');
            }
            $result = $pm->batchSetLocation($ids, $location);
            if ($isAjax) Response::ok($result, '库位设置成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.batch_set_remark — 批量设置备注 */
    private static function handleBatchSetRemark(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEditBatch();
        $isAjax = \isAjaxRequest();
        $pm = new \PartManager($db, $uid, $dataUid);
        try {
            $ids    = array_map('intval', $_POST['ids'] ?? []);
            $ids    = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重）
            $remark = trim((string)($_POST['remark'] ?? ''));
            $result = $pm->batchSetRemark($ids, $remark);
            if ($isAjax) Response::ok($result, '备注设置成功');
            Response::redirect('index.php?flash=ok');
        } catch (\PartException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST parts.advanced_search — 参数化精准筛选 */
    private static function handleAdvancedSearch(\PDO $db, int $dataUid): void
    {
        $page     = max(1, (int)($_POST['page'] ?? 1));
        $perPage  = max(1, min(100, (int)($_POST['per_page'] ?? 25)));
        $package  = trim((string)($_POST['package'] ?? ''));
        $brand    = trim((string)($_POST['brand'] ?? ''));
        $productType = trim((string)($_POST['product_type'] ?? ''));
        $stockMin = $_POST['stock_min'] ?? null;
        $stockMax = $_POST['stock_max'] ?? null;
        $paramKey = trim((string)($_POST['param_key'] ?? ''));
        $paramVal = trim((string)($_POST['param_value'] ?? ''));
        $model    = trim((string)($_POST['model'] ?? ''));

        $where = ["p.user_id = ?", "p.status NOT IN ('deleted','scrapped')"];
        $params = [$dataUid];

        if ($model !== '') {
            $where[] = "p.model LIKE ?";
            $params[] = "%{$model}%";
        }
        if ($package !== '') {
            $where[] = "p.package LIKE ?";
            $params[] = "%{$package}%";
        }
        if ($brand !== '') {
            $where[] = "p.brand LIKE ?";
            $params[] = "%{$brand}%";
        }
        if ($productType !== '') {
            $where[] = "p.product_type = ?";
            $params[] = $productType;
        }
        if ($stockMin !== null && $stockMin !== '') {
            $where[] = "p.stock >= ?";
            $params[] = (int)$stockMin;
        }
        if ($stockMax !== null && $stockMax !== '') {
            $where[] = "p.stock <= ?";
            $params[] = (int)$stockMax;
        }
        if ($paramKey !== '' && $paramVal !== '') {
            // JSON 参数字段模糊匹配
            $where[] = "p.parameters_text LIKE ?";
            $params[] = "%{$paramKey}%{$paramVal}%";
            $params[] = "%{$paramKey}%{$paramVal}%";
        }

        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM parts p WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT p.id, p.model, p.brand, p.package, p.product_type, p.stock, p.location,
                    p.platform_part_no, p.parameters_text, p.status, p.low_stock_threshold
             FROM parts p
             WHERE {$whereSql}
             ORDER BY p.update_time DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::ok([
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }
}
