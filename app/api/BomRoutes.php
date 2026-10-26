<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

require_once __DIR__ . '/../../modules/bom/Service/BomService.php';

/** BOM 管理 API 路由 */
final class BomRoutes
{
    /** 版本命令统一入口：BomVersionService 组合 BomManager 的共享访问器（getVersion / resolveVersionId 等） */
    private static function versionService(\PDO $db, int $uid, int $dataUid): \Modules\Bom\Service\BomVersionService
    {
        return new \Modules\Bom\Service\BomVersionService($db, $uid, $dataUid, new \BomManager($db, $uid, $dataUid));
    }

    public static function handle(string $method, array $user): void
    {

        self::requireBomPerm();

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            // ── 读操作（登录即可）──
            case 'projects':
                Response::ok(self::queryProjects($db, $uid, $dataUid));
                break;

            case 'shortage':
                $projectId = (int)($_GET['project_id'] ?? 0);
                $quantity  = max(1, (int)($_GET['quantity'] ?? 1));
                if ($projectId <= 0) Response::error('缺少project_id', 1);
                Response::ok(self::queryShortage($db, $uid, $dataUid, $projectId, $quantity, self::reqVersionId()));
                break;

            case 'items':
                Response::ok(self::queryItems($db, $uid, $dataUid, intval($_GET['id'] ?? 0), $_GET));
                break;

            case 'cost_summary':
                // 成本数据按 can_view_cost() 收口（与视图侧渲染条件成对）
                if (!\can_view_cost()) Response::error('当前版本不支持成本查看', 403);
                Response::ok(self::queryCostSummary($db, $uid, $dataUid, intval($_GET['project_id'] ?? 0)));
                break;

            case 'list_versions':
                self::requireBomPerm();
                self::requireBomVersion();
                $projectId = (int)($_GET['project_id'] ?? 0);
                if ($projectId <= 0) Response::error('缺少project_id', 1);
                Response::ok(self::queryListVersions($db, $uid, $dataUid, $projectId));
                break;

            // BOM 缺料采购清单 CSV 导出：保持 CSV 流语义（非 JSON 信封）
            case 'shortage_csv':
                // 导出属数据外带；BOM 页本身即以此为进入权限
                self::requireBomPerm();
                $projectId = (int)($_GET['project_id'] ?? 0);
                $quantity  = max(1, (int)($_GET['quantity'] ?? 1));
                $category  = (string)($_GET['category'] ?? '');
                if (!in_array($category, ['incomplete', 'stock'], true)) $category = '';
                if ($projectId <= 0) Response::error('缺少project_id', 1);
                $csv = self::queryShortageCsv($db, $uid, $dataUid, $projectId, $quantity, self::reqVersionId(), $category);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="shortage_' . $projectId . '.csv"');
                echo "\xEF\xBB\xBF" . $csv; // UTF-8 BOM
                exit;

            case 'create_project':
                self::requireBomPerm();
                self::handleCreateProject($db, $uid, $dataUid);
                break;

            case 'update_project':
                self::requireBomPerm();
                self::handleUpdateProject($db, $uid, $dataUid);
                break;

            case 'delete_project':
                self::requireBomPerm();
                self::handleDeleteProject($db, $uid, $dataUid);
                break;

            case 'import_bom':
                self::requireBomPerm();
                self::handleImportBom($db, $uid, $dataUid);
                break;

            case 'create_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleCreateVersion($db, $uid, $dataUid);
                break;

            case 'release_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleReleaseVersion($db, $uid, $dataUid);
                break;

            case 'clone_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleCloneVersion($db, $uid, $dataUid);
                break;

            case 'set_current_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleSetCurrentVersion($db, $uid, $dataUid);
                break;

            case 'diff_versions':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleDiffVersions($db, $uid, $dataUid);
                break;

            case 'update_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleUpdateVersion($db, $uid, $dataUid);
                break;

            case 'archive_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleArchiveVersion($db, $uid, $dataUid);
                break;

            case 'delete_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleDeleteVersion($db, $uid, $dataUid);
                break;

            case 'rollback_version':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleRollbackVersion($db, $uid, $dataUid);
                break;

            case 'version_change':
                self::requireBomPerm();
                self::requireBomVersion();
                self::handleVersionChange($db, $uid, $dataUid);
                break;

            case 'add_item':
                self::requireBomPerm();
                self::handleAddItem($db, $uid, $dataUid);
                break;

            case 'add_item_by_part_id':
                self::requireBomPerm();
                self::handleAddItemByPartId($db, $uid, $dataUid);
                break;

            case 'complete_pending_item':
            case 'batch_complete_pending':
                self::requireBomPerm();
                Response::error('BOM 内不再新建物料，请用「替代料 → 替换」绑定主库物料并拉取其参数；如需新建请到物料列表或导入中心建档（含单价与入库流水）', 403);
                break;

            // 替代料弹窗的「相似候选 · 仅供参考」：只读推荐，不写任何表
            case 'pending_suggest':
                self::requireBomPerm();
                self::handlePendingSuggest($db, $uid, $dataUid);
                break;

            case 'update_item':
                self::requireBomPerm();
                self::handleUpdateItem($db, $uid, $dataUid);
                break;

            // BOM 补料清单 CSV 导出：保持 CSV 流语义（非 JSON 信封）
            case 'pending_csv':
                $projectId = (int)($_GET['project_id'] ?? 0);
                if ($projectId <= 0) Response::error('缺少project_id', 1);
                $csv = (new \BomManager($db, $uid, $dataUid))->exportPendingCsv($projectId, self::reqVersionId());
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="bom_pending_' . $projectId . '.csv"');
                echo "\xEF\xBB\xBF" . $csv;   // UTF-8 BOM
                exit;

            case 'batch_add_to_bom':
                self::requireBomPerm();
                self::handleBatchAddToBom($db, $uid, $dataUid);
                break;

            case 'delete_item':
                self::requireBomPerm();
                self::handleDeleteItem($db, $uid, $dataUid);
                break;

            case 'batch_delete_items':
                self::requireBomPerm();
                self::handleBatchDeleteItems($db, $uid, $dataUid);
                break;

            case 'checkout':
                self::requireBomPerm();
                self::handleCheckout($db, $uid, $dataUid);
                break;

            case 'save_profit_margin':
                self::requireBomPerm();
                self::handleSaveProfitMargin($db, $uid, $dataUid);
                break;

            case 'update_status':
                self::requireBomPerm();
                self::handleUpdateStatus($db, $uid, $dataUid);
                break;

            case 'use_alt':
                self::requireBomPerm();
                self::handleUseAlt($db, $uid, $dataUid);
                break;

            case 'replace_alt':
                self::requireBomPerm();
                self::handleReplaceAlt($db, $uid, $dataUid);
                break;

            case 'rebuild_all_cost':
                self::handleRebuildAllCost($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 bom 方法：' . $method, 404);
        }
    }

    /** 取数：BOM 项目列表 */
    public static function queryProjects(\PDO $db, int $uid, int $dataUid): array
    {
        return ['projects' => (new \BomManager($db, $uid, $dataUid))->listProjects()];
    }

    /** 取数：BOM 物料明细列表 */
    public static function queryItems(\PDO $db, int $uid, int $dataUid, int $projectId, array $params): array
    {
        return (new \BomManager($db, $uid, $dataUid))->listItems($projectId, $params);
    }

    /** 取数：BOM 成本汇总 */
    public static function queryCostSummary(\PDO $db, int $uid, int $dataUid, int $projectId): array
    {
        return (new \BomManager($db, $uid, $dataUid))->getCostSummary($projectId);
    }

    /** 取数：BOM 版本列表（版本面板数据源） */
    public static function queryListVersions(\PDO $db, int $uid, int $dataUid, int $projectId): array
    {
        return ['versions' => self::versionService($db, $uid, $dataUid)->listVersions($projectId)];
    }

    /** 取数：BOM 缺料汇总 */
    public static function queryShortage(\PDO $db, int $uid, int $dataUid, int $projectId, int $quantity = 1, $versionId = null): array
    {
        return (new \BomManager($db, $uid, $dataUid))->getShortageSummary($projectId, $quantity, $versionId);
    }

    /** 取数：BOM 缺料采购清单 CSV 正文 */
    public static function queryShortageCsv(\PDO $db, int $uid, int $dataUid, int $projectId, int $quantity = 1, $versionId = null, string $category = ''): string
    {
        return (new \BomManager($db, $uid, $dataUid))->exportShortageCsv($projectId, $quantity, $versionId, $category);
    }

    /** 读取版本参数：version_id（GET/POST）优先，兼容 bom_ver；无则返回 null（=项目当前编辑草稿） */
    private static function reqVersionId(): ?int
    {
        $v = (int)($_GET['version_id'] ?? $_POST['version_id'] ?? $_GET['bom_ver'] ?? 0);
        return $v > 0 ? $v : null;
    }

    /** 权限校验：BOM 管理（使用 can_export） */
    private static function requireBomPerm(): void
    {
        if (!\hasPermission('can_export')) Response::error('无BOM管理权限', 403);
    }

    private static function requireBomVersion(): void
    {
        if (!\hasFeature('feature_bom_version')) Response::error('BOM 多版本管理未启用，请先在「网站设置 → 功能开关」中开启', 403);
    }

    /** BOM 筛选参数白名单校验：仅接受 ok / insufficient / not_found，非法值视为不过滤 */
    private static function bomFilterParam(): string
    {
        $bomFilter = $_POST['filter'] ?? '';
        if (!in_array($bomFilter, ['ok', 'insufficient', 'not_found'], true)) $bomFilter = '';
        return $bomFilter !== '' ? '&filter=' . $bomFilter : '';
    }

    /** POST bom.create_project — 创建 BOM 项目 */
    private static function handleCreateProject(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        try {
            $result = $bom->createProject($_POST);
            if ($isAjax) Response::ok($result, '项目已创建');
            Response::redirect('index.php?v=bom&id=' . $result['project_id']);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&flash=err');
        }
    }

    /** POST bom.update_project — 更新 BOM 项目 */
    private static function handleUpdateProject(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->updateProject($_POST);
            if ($isAjax) Response::ok($result, '项目已更新');
            Response::redirect('index.php?v=bom&id=' . $result['project_id'] . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.delete_project — 删除 BOM 项目 */
    private static function handleDeleteProject(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        try {
            $result = $bom->deleteProject((int)($_POST['project_id'] ?? 0));
            if ($isAjax) Response::ok($result, '项目已删除');
            Response::redirect('index.php?v=bom');
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&flash=err');
        }
    }

    /** POST bom.add_item — 添加 BOM 物料 */
    private static function handleAddItem(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->addItem($_POST);
            if ($isAjax) Response::ok($result, '物料已添加');
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.add_item_by_part_id — 通过 part_id 添加库内已有物料 */
    private static function handleAddItemByPartId(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->addItemByPartId($_POST);
            if ($isAjax) Response::ok($result, '物料已添加');
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.update_item — 编辑清单行（数量/位号/备注 + BOM 自有物料参数） */
    private static function handleUpdateItem(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->updateItem($_POST);
            if ($isAjax) Response::ok($result, '清单行已更新');
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** GET bom.pending_suggest — 清单行的相似候选物料（≤3，带匹配总分与理由标签） */
    private static function handlePendingSuggest(\PDO $db, int $uid, int $dataUid): void
    {
        $bom = new \BomManager($db, $uid, $dataUid);
        try {
            Response::ok($bom->pendingSuggest([
                'item_id'     => (int)($_GET['item_id'] ?? 0),
                'project_id'  => (int)($_GET['project_id'] ?? 0),
            ]));
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.batch_add_to_bom — 首页批量加入 BOM 项目 */
    private static function handleBatchAddToBom(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->batchAddByPartIds($_POST);
            $msg = '已添加 ' . $result['added'] . ' 条物料';
            if ($result['skipped'] > 0) $msg .= '，跳过 ' . $result['skipped'] . ' 条（已存在或无效）';
            if ($isAjax) Response::ok($result, $msg);
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            $referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            Response::redirect($referrer . (str_contains($referrer, '?') ? '&' : '?') . 'flash=err');
        }
    }

    /** POST bom.delete_item — 删除 BOM 物料 */
    private static function handleDeleteItem(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->deleteItem((int)($_POST['item_id'] ?? 0));
            if ($isAjax) Response::ok($result, '物料已删除');
            $back = isset($_POST['project_id'])
                ? 'index.php?v=bom&id=' . (int)$_POST['project_id'] . $filter
                : 'index.php?v=bom';
            Response::redirect($back);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            $back = isset($_POST['project_id'])
                ? 'index.php?v=bom&id=' . (int)$_POST['project_id'] . $filter
                : 'index.php?v=bom';
            Response::redirect($back . '&flash=err');
        }
    }

    /** POST bom.batch_delete_items — 批量删除 BOM 物料 */
    private static function handleBatchDeleteItems(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->batchDeleteItems($_POST);
            if ($isAjax) Response::ok($result, '已批量删除 ' . $result['deleted_count'] . ' 条物料');
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.checkout — BOM 一键出库（支持 checkout_mode=all_or_nothing|partial） */
    private static function handleCheckout(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $pid = (int)($_POST['project_id'] ?? 0);
            $mode = trim((string)($_POST['checkout_mode'] ?? 'all_or_nothing'));
            if (!in_array($mode, ['all_or_nothing', 'partial'], true)) $mode = 'all_or_nothing';
            $versionId = (int)($_POST['version_id'] ?? 0);
            $copies = max(1, min(999, (int)($_POST['copies'] ?? 1)));
            $result = $bom->bomCheckout($pid, $mode, $versionId > 0 ? $versionId : null, $copies);
            if ($isAjax) Response::ok($result, $result['message']);
            Response::redirect('index.php?v=bom&id=' . $pid . $filter);
        } catch (\BomException $e) {

            if ($isAjax) Response::error($e->getMessage(), $e->errCode, $e->payload);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.save_profit_margin — 设置利润率 */
    private static function handleSaveProfitMargin(\PDO $db, int $uid, int $dataUid): void
    {
        $bom = new \BomManager($db, $uid, $dataUid);
        $pid    = \safePosInt($_POST['project_id'] ?? 0);
        $margin = round((float)($_POST['profit_margin'] ?? 0), 2);
        $result = $bom->updateProfitMargin($pid, $margin);
        Response::ok($result);
    }

    /** POST bom.update_status — 更新 BOM 项目状态 */
    private static function handleUpdateStatus(\PDO $db, int $uid, int $dataUid): void
    {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $allowed = ['draft', 'testing', 'production', 'finished', 'archived'];
        if ($projectId <= 0 || !in_array($newStatus, $allowed, true)) {
            Response::error('参数无效');
        }
        $db->prepare("UPDATE bom_projects SET status=? WHERE id=? AND user_id=?")
           ->execute([$newStatus, $projectId, $dataUid]);
        \traceLog($uid, 'update_bom_status', 'bom_project', $projectId, "状态改为{$newStatus}");
        Response::ok([]);
    }

    /** POST bom.use_alt — 使用替代料出库 */
    private static function handleUseAlt(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->useAlternative($_POST);
            if ($isAjax) Response::ok($result, $result['message']);
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.replace_alt — 替换 BOM 物料行 */
    private static function handleReplaceAlt(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $bom = new \BomManager($db, $uid, $dataUid);
        $filter = self::bomFilterParam();
        try {
            $result = $bom->replaceWithAlternative($_POST);
            if ($isAjax) Response::ok($result, $result['message']);
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter);
        } catch (\BomException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=bom&id=' . (int)($_POST['project_id'] ?? 0) . $filter . '&flash=err');
        }
    }

    /** POST bom.import_bom — BOM 文件导入（multipart/form-data，统一 JSON 返回） */
    private static function handleImportBom(\PDO $db, int $uid, int $dataUid): void
    {
        $bom = new \BomManager($db, $uid, $dataUid);
        try {
            $projectId = (int)($_POST['project_id'] ?? 0);
            $result = $bom->importBom($_FILES['bom_file'] ?? [], $projectId);
            Response::ok($result, $result['message']);
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.create_version — 创建 BOM 版本（基于当前编辑草稿克隆，兼顾旧调用） */
    private static function handleCreateVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $projectId = (int)($_POST['project_id'] ?? 0);
        $version = trim((string)($_POST['version'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($projectId <= 0 || $version === '') Response::error('参数无效');
        try {
            $result = $vs->cloneVersion($projectId, 0, $version, $description);
            Response::ok(['version_id' => $result['version_id'], 'version' => $version], '新编辑草稿已创建，共复制 ' . $result['copied'] . ' 条明细');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.clone_version — 版本迭代：基于某版本克隆出新草稿并设为当前编辑目标 */
    private static function handleCloneVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $projectId = (int)($_POST['project_id'] ?? 0);
        $baseVersionId = (int)($_POST['base_version_id'] ?? 0);
        $version = trim((string)($_POST['version'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        if ($projectId <= 0 || $version === '') Response::error('参数无效');
        try {
            $result = $vs->cloneVersion($projectId, $baseVersionId, $version, $description);
            Response::ok($result, '版本 ' . $version . ' 已基于来源创建为当前编辑草稿，共复制 ' . $result['copied'] . ' 条明细');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.set_current_version — 将某草稿版本设为当前编辑目标 */
    private static function handleSetCurrentVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $projectId = (int)($_POST['project_id'] ?? 0);
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($projectId <= 0 || $versionId <= 0) Response::error('参数无效');
        try {
            $vs->setCurrentVersion($projectId, $versionId);
            Response::ok([], '已切换到当前编辑草稿版本');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.release_version — 发布 BOM 版本（draft→released，并自动新建下一草稿） */
    private static function handleReleaseVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $versionId = (int)($_POST['version_id'] ?? 0);
        $changeNote = trim((string)($_POST['change_note'] ?? ''));
        if ($versionId <= 0) Response::error('参数无效');
        try {
            $r = $vs->releaseVersion($versionId, $changeNote);
            Response::ok($r, "版本 {$r['released_version']} 已发布（只读留存），已自动创建下一草稿 {$r['next_version']} 供继续编辑");
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.diff_versions — 对比两个版本明细差异 */
    private static function handleDiffVersions(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $va = (int)($_POST['version_a'] ?? 0);
        $vb = (int)($_POST['version_b'] ?? 0);
        if ($va <= 0 || $vb <= 0 || $va === $vb) Response::error('请选择两个不同版本进行对比');
        try {
            Response::ok($vs->diffVersions($va, $vb));
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.update_version — 编辑版本描述 */
    private static function handleUpdateVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $versionId = (int)($_POST['version_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        if ($versionId <= 0) Response::error('参数无效');
        try {
            $vs->updateVersion($versionId, $description);
            Response::ok([], '版本描述已更新');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.archive_version — 归档版本（只读留存） */
    private static function handleArchiveVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) Response::error('参数无效');
        try {
            $vs->archiveVersion($versionId);
            Response::ok([], '版本已归档（只读留存）');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.delete_version — 删除草稿版本 */
    private static function handleDeleteVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) Response::error('参数无效');
        try {
            $vs->deleteVersion($versionId);
            Response::ok([], '草稿版本已删除');
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.rollback_version — 回滚到某版本（克隆为恢复草稿） */
    private static function handleRollbackVersion(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $projectId = (int)($_POST['project_id'] ?? 0);
        $versionId = (int)($_POST['version_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        if ($projectId <= 0 || $versionId <= 0) Response::error('参数无效');
        try {
            $r = $vs->rollbackToVersion($projectId, $versionId, $description);
            Response::ok($r, "已将当前编辑回滚到版本并生成恢复草稿 {$r['version']}");
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.version_change — 获取版本相对迭代来源的变更记录 */
    private static function handleVersionChange(\PDO $db, int $uid, int $dataUid): void
    {
        $vs = self::versionService($db, $uid, $dataUid);
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($versionId <= 0) Response::error('参数无效');
        try {
            Response::ok($vs->getVersionChange($versionId));
        } catch (\BomException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST bom.rebuild_all_cost — 全局成本重建（仅主管理员，分批执行） */
    private static function handleRebuildAllCost(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\isPrimaryAdmin()) Response::error('仅主管理员可执行', 403);
        if (empty($_POST['confirm'])) Response::error('请确认执行成本重建操作', 1);

        @set_time_limit(300);
        $offset    = (int)($_POST['offset'] ?? 0);
        $batchSize = 50;

        // 暂停信号
        if (!empty($_POST['pause'])) {
            Response::ok(['paused' => true, 'offset' => $offset]);
        }

        // 统计总数
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM parts WHERE user_id=?");
        $cntStmt->execute([$dataUid]);
        $total = (int)$cntStmt->fetchColumn();

        // 取本批物料ID
        $idStmt = $db->prepare("SELECT id FROM parts WHERE user_id=? ORDER BY id ASC LIMIT $batchSize OFFSET $offset");
        $idStmt->execute([$dataUid]);
        $ids = $idStmt->fetchAll(\PDO::FETCH_COLUMN);

        $rebuilt = 0;
        foreach ($ids as $pid) {
            $db->prepare("UPDATE parts SET cost_dirty=1 WHERE id=?")->execute([$pid]);
            \recalcAvgCost($db, (int)$pid);
            $rebuilt++;
        }

        $nextOffset = $offset + $batchSize;
        $done = $nextOffset >= $total;
        if ($done) {
            \traceLog($uid, 'rebuild_all_cost', 'system', 0, "全局成本重建完成: {$total}个物料");
        }
        Response::ok([
            'done'    => $done,
            'rebuilt' => $rebuilt,
            'offset'  => $nextOffset,
            'total'   => $total,
        ]);
    }
}
