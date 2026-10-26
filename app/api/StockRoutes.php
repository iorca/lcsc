<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

require_once __DIR__ . '/../../modules/stock/Service/StockService.php';

require_once __DIR__ . '/../../modules/assets/Service/AssetsService.php';

/** 库存管理 API 路由 */
final class StockRoutes
{
    /** 需校验 CSRF 的方法表（写操作 + scan_resolve 预检） */
    private const WRITE_METHODS = [
        'change', 'scan_in', 'scan_out', 'scan_undo', 'scan_resolve',
        'batch_in', 'batch_set_threshold', 'check_import', 'review_undo',
        'session_create', 'session_count', 'session_skip', 'session_unskip',
        'session_confirm', 'session_cancel', 'stale_mark', 'stale_unmark',

        'order_columns_save', 'order_field_add', 'order_field_toggle', 'order_field_delete',
    ];

    public static function handle(string $method, array $user): void
    {
        // 写操作统一 CSRF 校验（安全底线：所有 POST 状态变更必须校验）
        if (in_array($method, self::WRITE_METHODS, true)) {
            \verifyCsrfSafe();
        }

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'change':
                self::handleStockChange($db, $uid, $dataUid);
                break;

            case 'scan_in':
                self::handleScanIn($db, $uid, $dataUid);
                break;

            case 'scan_out':
                self::handleScanOut($db, $uid, $dataUid);
                break;

            case 'scan_undo':
                self::handleScanUndo($db, $uid, $dataUid);
                break;

            case 'scan_resolve':
                self::handleScanResolve($db, $uid, $dataUid);
                break;

            case 'batch_in':
                self::requireEditBatch();
                self::handleBatchIn($db, $uid, $dataUid);
                break;

            case 'batch_set_threshold':
                self::requireEditBatch();
                self::handleBatchSetThreshold($db, $uid, $dataUid);
                break;

            case 'check_import':
                self::handleCheckImport($db, $uid, $dataUid);
                break;

            case 'review_undo':
                self::handleReviewUndo($db, $uid, $dataUid);
                break;

            case 'export_checklist':
                self::handleExportChecklist($db, $uid, $dataUid);
                break;

            case 'checklist_export_json':

                $params = [
                    'category_id' => $_GET['category_id'] ?? 0,
                    'location'    => $_GET['location'] ?? '',
                    'platform_id' => $_GET['platform_id'] ?? 0,
                ];
                Response::ok(self::queryChecklist($db, $uid, $dataUid, $params));
                break;

            case 'flow_region':

                self::handleFlowRegion($db, $uid, $dataUid);
                break;

            case 'log_query':
                // 取数：统一流水查询
                $logParams = [
                    'filter'    => $_GET['filter'] ?? 'all',
                    'part_id'   => (int)($_GET['part_id'] ?? 0),
                    'date_from' => $_GET['date_from'] ?? '',
                    'date_to'   => $_GET['date_to'] ?? '',
                    'keyword'   => $_GET['keyword'] ?? '',
                    'page'      => (int)($_GET['page'] ?? 1),
                    'per_page'  => (int)($_GET['per_page'] ?? 25),
                ];
                Response::ok(self::queryStockLog($db, $uid, $dataUid, $logParams));
                break;

            // ── P7 在线盘点（盘点单 → 录数 → 差异确认 → 应用调整）──
            case 'session_create':
                self::handleSessionCreate($db, $uid, $dataUid);
                break;
            case 'session_list':
                self::handleSessionList($db, $uid, $dataUid);
                break;
            case 'session_detail':
                self::handleSessionDetail($db, $uid, $dataUid);
                break;
            case 'session_count':
                self::handleSessionCount($db, $uid, $dataUid);
                break;
            case 'session_skip':
                self::handleSessionSkip($db, $uid, $dataUid, false);
                break;
            case 'session_unskip':
                self::handleSessionSkip($db, $uid, $dataUid, true);
                break;
            case 'session_preview':
                self::handleSessionPreview($db, $uid, $dataUid);
                break;
            case 'session_confirm':
                self::handleSessionConfirm($db, $uid, $dataUid);
                break;
            case 'session_cancel':
                self::handleSessionCancel($db, $uid, $dataUid);
                break;

            // ── P8 呆滞物料专区 ──
            case 'stale_list':
                self::handleStaleList($db, $uid, $dataUid);
                break;
            case 'stale_mark':
                self::handleStaleMark($db, $uid, $dataUid);
                break;
            case 'stale_unmark':
                self::handleStaleUnmark($db, $uid, $dataUid);
                break;
            case 'stale_group':
                self::handleStaleGroup($db, $uid, $dataUid);
                break;
            case 'stale_export':
                self::handleStaleExport($db, $uid, $dataUid);
                break;

            // ── 订单信息（流水中心）：详情弹窗 / 列显隐配置 / 自定义字段 ──
            case 'order_detail':
                self::handleOrderDetail($db, $uid, $dataUid);
                break;
            case 'order_columns_get':
                self::handleOrderColumnsGet($db, $uid, $dataUid);
                break;
            case 'order_columns_save':
                self::requireEditBatch();
                self::handleOrderColumnsSave($db, $uid, $dataUid);
                break;
            case 'order_field_add':
                self::requireEditBatch();
                self::handleOrderFieldAdd($db, $uid, $dataUid);
                break;
            case 'order_field_toggle':
                self::requireEditBatch();
                self::handleOrderFieldToggle($db, $uid, $dataUid);
                break;
            case 'order_field_delete':
                self::requireEditBatch();
                self::handleOrderFieldDelete($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 stock 方法：' . $method, 404);
        }
    }

    /** 取数：统一流水查询 */
    public static function queryStockLog(\PDO $db, int $uid, int $dataUid, array $params): array
    {
        return (new \StockManager($db, $uid, $dataUid))->queryStockLog($params);
    }

    /** 取数：盘点清单行数据（stock.checklist_export_json 与 stock.export_checklist 共用； */
    public static function queryChecklist(\PDO $db, int $uid, int $dataUid, array $params): array
    {
        return (new \StockManager($db, $uid, $dataUid))->exportChecklist($params);
    }

    /** 权限校验：需编辑 + 批量权限 */
    private static function requireEditBatch(): void
    {
        if (!\hasPermission('can_edit') || !\hasPermission('can_batch')) {
            Response::error('权限不足', 403);
        }
    }

    /** 权限校验：编辑权限（手动出入库 / 盘点导入等库存写操作） */
    private static function requireEdit(): void
    {
        if (!\hasPermission('can_edit')) {
            Response::error('权限不足', 403);
        }
    }

    /** 非 AJAX 模式下设置 flash 后跳转 */
    private static function redirectWithFlash(bool $ok, string $okUrl, string $errUrl, string $errMsg = ''): void
    {
        if ($ok) {
            Response::redirect($okUrl);
        }
        if ($errMsg !== '') {
            $_SESSION['action_error'] = $errMsg;
        }
        Response::redirect($errUrl);
    }

    /** GET stock.flow_region — 流水中心「内容区」局部刷新（只读，无副作用） */
    private static function handleFlowRegion(\PDO $db, int $uid, int $dataUid): void
    {
        $viewData = \Modules\Stock\Controller\StockController::prepareViewData($db, $uid, $dataUid);
        $view     = (string)($viewData['view'] ?? 'business');

        Response::ok([
            'view'        => $view,
            'tab'         => $view === 'business' ? (string)($viewData['tab'] ?? 'all') : '',
            'can_finance' => \can_view_cost(),
            'html'        => \Modules\Stock\Controller\StockController::renderRegion($viewData),
        ]);
    }

    /** POST stock.change — 出入库操作（manual_in / manual_out / adjust / damaged / repair） */
    private static function handleStockChange(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $sm     = new \StockManager($db, $uid, $dataUid);
        $isAjax = \isAjaxRequest();
        try {
            $result = $sm->stockChange($_POST);
            if ($isAjax) Response::ok($result, '操作成功');
            self::redirectWithFlash(true, 'index.php?flash=ok', 'index.php?flash=err');
        } catch (\StockException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            self::redirectWithFlash(false, '', 'index.php?flash=err', $e->getMessage());
        }
    }

    /** 扫码接口统一守卫：扫码权限（与扫码页 PageRouter::GUARDS['scan'] 成对） */
    private static function requireScanPerm(): void
    {
        if (!\hasPermission('can_scan')) {
            Response::forbidden('权限不足');
        }
    }

    /** POST stock.scan_in — 扫码入库 */
    private static function handleScanIn(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireScanPerm();
        $sm       = new \StockManager($db, $uid, $dataUid);
        $scanAjax = self::isScanAjax();
        try {
            $result = $sm->scanIn($_POST);
            if ($scanAjax) Response::scanOk($result);
            $_SESSION['scan_result'] = $result;
            Response::redirect('index.php?v=scan&flash=ok&type=scan_in');
        } catch (\StockException $e) {
            if ($scanAjax) Response::scanError($e->getMessage());
            $_SESSION['scan_error'] = $e->getMessage();
            Response::redirect('index.php?v=scan');
        }
    }

    /** POST stock.scan_out — 扫码出库 */
    private static function handleScanOut(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireScanPerm();
        $sm       = new \StockManager($db, $uid, $dataUid);
        $scanAjax = self::isScanAjax();
        try {
            $result = $sm->scanOut($_POST);
            if ($scanAjax) Response::scanOk($result);
            $_SESSION['scan_result'] = $result;
            Response::redirect('index.php?v=scan&flash=ok&type=scan_out');
        } catch (\StockException $e) {
            if ($scanAjax) Response::scanError($e->getMessage());
            $_SESSION['scan_error'] = $e->getMessage();
            Response::redirect('index.php?v=scan');
        }
    }

    /** POST stock.scan_resolve — 扫码预检（只查物料，不改库存） */
    private static function handleScanResolve(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireScanPerm();
        $sm = new \StockManager($db, $uid, $dataUid);
        try {
            Response::scanOk($sm->scanResolve($_POST));
        } catch (\StockException $e) {
            Response::scanError($e->getMessage());
        }
    }

    /** POST stock.scan_undo — 撤销扫码操作 */
    private static function handleScanUndo(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireScanPerm();
        $sm         = new \StockManager($db, $uid, $dataUid);
        $scanAjax   = self::isScanAjax();
        $scanLogId  = (int)($_POST['scan_log_id'] ?? 0);
        try {
            $result = $sm->scanUndo($scanLogId);
            if ($scanAjax) Response::scanOk($result);
            Response::redirect('index.php?v=scan&flash=ok');
        } catch (\StockException $e) {
            if ($scanAjax) Response::scanError($e->getMessage());
            $_SESSION['scan_error'] = $e->getMessage();
            Response::redirect('index.php?v=scan');
        }
    }

    /** POST stock.batch_in — 批量入库（内联 SQL，按 parts 表匹配库存） */
    private static function handleBatchIn(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        try {
            $ids = array_map('intval', $_POST['ids'] ?? []);
            $ids = array_filter($ids, fn($v) => $v > 0);
            $ids = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重）：重复 id 会让本循环翻倍计数
            $qty       = max(1, (int)($_POST['qty'] ?? 1));
            $unitCost  = round(max(0, (float)($_POST['unit_cost'] ?? 0)), 4);
            $remark    = trim((string)($_POST['remark'] ?? '批量入库'));
            if (empty($ids)) Response::error('未选择物料');

            $updated = 0;
            foreach ($ids as $pid) {
                $chk = $db->prepare("SELECT stock, platform_part_no FROM parts WHERE id=? AND user_id=?");
                $chk->execute([$pid, $dataUid]);
                $part = $chk->fetch(\PDO::FETCH_ASSOC);
                if (!$part) continue;
                $qtyBefore = (int)$part['stock'];
                $qtyAfter  = $qtyBefore + $qty;
                \applyStockDelta($pid, $qty);
                \insertStockLog($db, [
                    'user_id'          => $uid,
                    'part_id'          => $pid,
                    'platform_part_no' => (string)($part['platform_part_no'] ?? ''),
                    'change_type'      => \App\Core\Enum\StockChangeType::BATCH_IN,
                    'qty_change'       => $qty,
                    'qty_before'       => $qtyBefore,
                    'qty_after'        => $qtyAfter,
                    'unit_cost'        => $unitCost,
                    'subtotal'         => round($qty * $unitCost, 2),
                    'remark'           => $remark,
                    'workspace_id'     => \getWorkspaceId(),
                ]);
                \markCostDirty($db, $pid);
                $updated++;
            }
            \traceLog($uid, 'batch_stock_in', 'part', 0, "批量入库{$updated}个物料,数量:{$qty},单价:{$unitCost}");
            $result = ['updated' => $updated];
            if ($isAjax) Response::ok($result, '批量入库成功');
            Response::redirect('index.php?flash=ok');
        } catch (\Throwable $e) {
            if ($isAjax) Response::error($e->getMessage(), 1);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST stock.batch_set_threshold — 批量设置安全库存阈值 */
    private static function handleBatchSetThreshold(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        try {
            $ids = array_map('intval', $_POST['ids'] ?? []);
            $ids = array_filter($ids, fn($v) => $v > 0);
            $ids = array_values(array_unique($ids));   // 去重兜底（前端已按 value 去重）
            $threshold = max(0, (int)($_POST['threshold'] ?? 0));
            if (empty($ids)) Response::error('未选择物料');

            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("UPDATE parts SET low_stock_threshold=?, update_time=NOW() WHERE id IN ($in) AND user_id=? AND workspace_id=?");
            $stmt->execute([$threshold, ...$ids, $dataUid, \getWorkspaceId()]);
            $updated = $stmt->rowCount();
            \traceLog($uid, 'batch_set_threshold', 'part', 0, "批量设置{$updated}个物料安全库存:{$threshold}");
            $result = ['updated' => $updated];
            if ($isAjax) Response::ok($result, '安全库存设置成功');
            Response::redirect('index.php?flash=ok');
        } catch (\Throwable $e) {
            if ($isAjax) Response::error($e->getMessage(), 1);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?flash=err');
        }
    }

    /** POST stock.check_import — 盘点导入（差异调整，raw JSON body） */
    private static function handleCheckImport(\PDO $db, int $uid, int $dataUid): void
    {
        // 盘点导入会直接写库存调整：与在线盘点同一门控（版本功能 + 编辑权限）
        if (!\hasFeature('checklist')) Response::error('当前版本不支持此功能', 403);
        self::requireEdit();
        $sm    = new \StockManager($db, $uid, $dataUid);
        $items = json_decode(file_get_contents('php://input'), true) ?: [];
        $result = $sm->importCheckResult($items);
        Response::ok($result, '盘点导入完成');
    }

    /** POST stock.review_undo — 复核撤销流水（`reviewed_at` 标记） */
    private static function handleReviewUndo(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
        if (!is_array($ids)) Response::error('参数无效');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) Response::error('请选择要复核的撤销流水');
        if (count($ids) > 500) $ids = array_slice($ids, 0, 500);

        $undoTypes = ['scan_undo_in', 'scan_undo_out', 'import_undo_out'];
        $inIds = implode(',', array_fill(0, count($ids), '?'));
        $inTyp = implode(',', array_fill(0, count($undoTypes), '?'));

        $stmt = $db->prepare(
            "UPDATE stock_log SET reviewed_at = NOW()
             WHERE id IN ($inIds) AND user_id=? AND workspace_id=?
               AND change_type IN ($inTyp) AND reviewed_at IS NULL"
        );
        $stmt->execute(array_merge($ids, [$dataUid, (int)\getWorkspaceId()], $undoTypes));
        $reviewed = $stmt->rowCount();

        \traceLog($uid, 'review_undo', 'stock_log', 0,
            "复核撤销流水 {$reviewed} 条（提交 " . count($ids) . " 条）：" . implode(',', $ids));

        Response::ok(['reviewed' => $reviewed],
            $reviewed > 0 ? "已复核 {$reviewed} 条撤销流水" : '所选流水无需复核（可能已复核或非撤销类）');
    }

    /**
     * GET stock.export_checklist — 盘点清单导出（后端 fputcsv 直出 CSV，UTF-8 BOM；actual_qty 留空供盘点填写）
     */
    private static function handleExportChecklist(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasFeature('checklist')) {
            Response::error('当前版本不支持此功能', 403);
        }
        if (!\hasPermission('can_export')) {
            Response::error('权限不足', 403);
        }
        $params = [
            'category_id' => (int)($_GET['category_id'] ?? 0),
            'location'    => trim((string)($_GET['location'] ?? '')),
            'platform_id' => (int)($_GET['platform_id'] ?? 0),
        ];
        $rows = self::queryChecklist($db, $uid, $dataUid, $params);
        if (!is_array($rows)) $rows = [];

        \traceLog($uid, 'checklist_export', 'export', 0, '导出盘点清单 CSV（' . count($rows) . ' 行）');

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="checklist_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['id', 'internal_id', 'platform_part_no', 'model', 'product_name',
                      'brand', 'package', 'location', 'system_qty', 'actual_qty', 'snapshot_time']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['id'] ?? '',
                $row['internal_id'] ?? '',
                $row['platform_part_no'] ?? '',
                $row['model'] ?? '',
                $row['product_name'] ?? '',
                $row['brand'] ?? '',
                $row['package'] ?? '',
                $row['location'] ?? '',
                $row['system_qty'] ?? '',
                '', // actual_qty 留空，由线下盘点人员填写
                $row['snapshot_time'] ?? date('Y-m-d H:i:s'),
            ]);
        }
        fclose($fp);
        exit;
    }

    /** 在线盘点统一守卫：版本门控 + 编辑权限 */
    private static function requireCheckSession(): void
    {
        if (!\hasFeature('checklist')) {
            Response::error('当前版本不支持此功能', 403);
        }
        if (!\hasPermission('can_edit')) {
            Response::error('权限不足', 403);
        }
    }

    private static function svc(\PDO $db, int $uid, int $dataUid): \Modules\Stock\Service\CheckSessionService
    {
        return new \Modules\Stock\Service\CheckSessionService($db, $uid, $dataUid);
    }

    /** POST stock.session_create — 发起在线盘点（范围筛选 + 库存快照） */
    private static function handleSessionCreate(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireCheckSession();
        $r = self::svc($db, $uid, $dataUid)->createSession($_POST);
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r, '盘点单已创建');
    }

    /** POST stock.session_list — 盘点单列表 */
    private static function handleSessionList(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasFeature('checklist')) Response::error('当前版本不支持此功能', 403);
        $result = self::svc($db, $uid, $dataUid)->listSessions(
            (int)($_POST['page'] ?? 1),
            (int)($_POST['per_page'] ?? 10),
            trim((string)($_POST['status'] ?? '')),
            trim((string)($_POST['sort'] ?? 'status'))
        );
        Response::ok($result);
    }

    /** POST stock.session_detail — 盘点单详情（明细分页 + 筛选 + 汇总） */
    private static function handleSessionDetail(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasFeature('checklist')) Response::error('当前版本不支持此功能', 403);
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) Response::error('参数无效');
        $r = self::svc($db, $uid, $dataUid)->getSession($sessionId, $_POST);
        if (!$r['ok']) Response::error($r['msg'], 404);
        Response::ok($r);
    }

    /** POST stock.session_count — 盘点录数（单项/批量 [{item_id, counted_qty, remark}]） */
    private static function handleSessionCount(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireCheckSession();
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || !$items) Response::error('请填写实盘数量');
        $r = self::svc($db, $uid, $dataUid)->countItems($sessionId, $items);
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r, "已记录 {$r['saved']} 项实盘数" . ($r['skipped'] ? "，{$r['skipped']} 项无效跳过" : ''));
    }

    /** POST stock.session_skip / session_unskip — 跳过 / 恢复盘点行 */
    private static function handleSessionSkip(\PDO $db, int $uid, int $dataUid, bool $unskip): void
    {
        self::requireCheckSession();
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $ids = json_decode((string)($_POST['item_ids'] ?? '[]'), true);
        if (!is_array($ids) || !$ids) Response::error('请选择要' . ($unskip ? '恢复' : '跳过') . '的行');
        $svc = self::svc($db, $uid, $dataUid);
        $r = $unskip ? $svc->unskipItems($sessionId, $ids) : $svc->skipItems($sessionId, $ids);
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r, $unskip ? '已恢复为未盘状态' : '已跳过');
    }

    /** POST stock.session_preview — 差异单预览（差异行 + 冲突行） */
    private static function handleSessionPreview(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasFeature('checklist')) Response::error('当前版本不支持此功能', 403);
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) Response::error('参数无效');
        $r = self::svc($db, $uid, $dataUid)->previewDiff($sessionId);
        if (!$r['ok']) Response::error($r['msg'], 404);
        Response::ok($r);
    }

    /** POST stock.session_confirm — 确认差异单（应用调整，需编辑+批量权限） */
    private static function handleSessionConfirm(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEditBatch();
        if (!\hasFeature('checklist')) Response::error('当前版本不支持此功能', 403);
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) Response::error('参数无效');
        $r = self::svc($db, $uid, $dataUid)->confirmSession($sessionId);
        if (!$r['ok']) Response::error($r['msg']);
        $msg = "盘点确认完成：应用 {$r['adjusted']} 行调整";
        if (!empty($r['conflicts'])) $msg .= "，{$r['conflicts']} 行因盘点期间库存变动跳过";
        Response::ok($r, $msg);
    }

    /** POST stock.session_cancel — 取消盘点单 */
    private static function handleSessionCancel(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireCheckSession();
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) Response::error('参数无效');
        $r = self::svc($db, $uid, $dataUid)->cancelSession($sessionId);
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r, '盘点单已取消');
    }

    /** POST stock.stale_list — 呆滞清单（分页 + 已处理开关 + 关键词 + 排序 + 下钻过滤） */
    private static function handleStaleList(\PDO $db, int $uid, int $dataUid): void
    {
        $svc = new \AssetManager($db, $uid, $dataUid);
        Response::ok($svc->getStaleList([
            'days'           => (int)($_POST['days'] ?? 0),
            'min_days'       => (int)($_POST['min_days'] ?? 0),
            'page'           => (int)($_POST['page'] ?? 1),
            'per_page'       => (int)($_POST['per_page'] ?? 50),
            'show_processed' => ($_POST['show_processed'] ?? '') === '1',
            'q'              => (string)($_POST['q'] ?? ''),
            'sort'           => (string)($_POST['sort'] ?? ''),
            'dir'            => (string)($_POST['dir'] ?? ''),
            'loc'            => (string)($_POST['loc'] ?? ''),
            'cat_key'        => array_key_exists('cat_key', $_POST) ? (string)$_POST['cat_key'] : null,
        ]));
    }

    /** POST stock.stale_group — 呆滞聚合视图（by=location|category，与清单同一套筛选口径） */
    private static function handleStaleGroup(\PDO $db, int $uid, int $dataUid): void
    {
        $svc = new \AssetManager($db, $uid, $dataUid);
        Response::ok($svc->getStaleGroups([
            'days'           => (int)($_POST['days'] ?? 0),
            'min_days'       => (int)($_POST['min_days'] ?? 0),
            'show_processed' => ($_POST['show_processed'] ?? '') === '1',
            'q'              => (string)($_POST['q'] ?? ''),
            'loc'            => (string)($_POST['loc'] ?? ''),
            'cat_key'        => array_key_exists('cat_key', $_POST) ? (string)$_POST['cat_key'] : null,
        ], (string)($_POST['by'] ?? 'location')));
    }

    /** POST stock.stale_mark — 批量标记呆滞已处理（remark 前缀 + traceLog，需编辑权限） */
    private static function handleStaleMark(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $isAll = (($_POST['all'] ?? '') === '1');
        $ids = [];
        if ($isAll) {
            $svc = new \AssetManager($db, $uid, $dataUid);
            $list = $svc->getStaleList([
                'days'           => (int)($_POST['days'] ?? 0),
                'min_days'       => (int)($_POST['min_days'] ?? 0),
                'show_processed' => ($_POST['show_processed'] ?? '') === '1',
                'q'              => (string)($_POST['q'] ?? ''),
                // 下钻过滤必须一起透传：从某个库位/分类点进来再「全部标记」，
                // 圈的应该是**这个下钻范围**，漏了就会把整库呆滞料全标掉
                'loc'            => (string)($_POST['loc'] ?? ''),
                'cat_key'        => array_key_exists('cat_key', $_POST) ? (string)$_POST['cat_key'] : null,
                'page'           => 1,
                'per_page'       => 10000,
            ]);
            $ids = array_map(fn($it) => (int)$it['id'], $list['items'] ?? []);
        } else {
            $ids = json_decode((string)($_POST['part_ids'] ?? '[]'), true);
        }

        if (!is_array($ids) || !$ids) Response::error($isAll ? '当前条件下没有可标记的呆滞物料' : '请选择物料');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if ($ids && count($ids) > 2000) $ids = array_slice($ids, 0, 2000);
        if (!$ids) Response::error('参数无效');

        $today = date('Y-m-d');
        $prefix = "[呆滞已处理 {$today}] ";
        $marked = 0;
        $in = implode(',', array_fill(0, count($ids), '?'));
        // 先读原 remark 再逐条写入（批量≤200，保留原备注；已有前缀仅刷新日期）
        $sel = $db->prepare("SELECT id, remark FROM parts WHERE id IN ($in) AND user_id=? AND status!='deleted'");
        $sel->execute(array_merge($ids, [$dataUid]));
        $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);
        $upd = $db->prepare("UPDATE parts SET remark=?, update_time=NOW() WHERE id=? AND user_id=?");
        foreach ($rows as $r) {
            $old = (string)$r['remark'];

            if (preg_match('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', $old)) {
                $new = preg_replace('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', $prefix, $old);
            } else {
                $new = $prefix . $old;
            }
            $upd->execute([mb_substr($new, 0, 500), (int)$r['id'], $dataUid]);
            $marked++;
        }

        \traceLog($uid, 'stale_mark', 'part', 0, "批量标记呆滞已处理 {$marked} 项（日期 {$today}）：" . implode(',', $ids));
        Response::ok(['marked' => $marked, 'date' => $today], "已标记 {$marked} 项为呆滞已处理");
    }

    /** POST stock.stale_unmark — 取消呆滞已处理标记（去掉 remark 前缀 + traceLog） */
    private static function handleStaleUnmark(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $ids = json_decode((string)($_POST['part_ids'] ?? '[]'), true);
        if (!is_array($ids) || !$ids) Response::error('请选择物料');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) Response::error('参数无效');
        if (count($ids) > 2000) $ids = array_slice($ids, 0, 2000);

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sel = $db->prepare("SELECT id, remark FROM parts WHERE id IN ($in) AND user_id=? AND status!='deleted'");
        $sel->execute(array_merge($ids, [$dataUid]));
        $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);

        $upd = $db->prepare("UPDATE parts SET remark=?, update_time=NOW() WHERE id=? AND user_id=?");
        $unmarked = 0;
        foreach ($rows as $r) {
            $old = (string)$r['remark'];
            if (!preg_match('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', $old)) continue;   // 本来就没标记
            $new = trim(preg_replace('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', '', $old));
            $upd->execute([mb_substr($new, 0, 500), (int)$r['id'], $dataUid]);
            $unmarked++;
        }

        \traceLog($uid, 'stale_unmark', 'part', 0, "取消呆滞已处理标记 {$unmarked} 项：" . implode(',', $ids));
        Response::ok(['unmarked' => $unmarked], "已取消 {$unmarked} 项的标记");
    }

    /** GET stock.stale_export — 呆滞清单 CSV 导出（fputcsv，个人版无成本列） */
    private static function handleStaleExport(\PDO $db, int $uid, int $dataUid): void
    {
        // 导出属数据外带，与其它 CSV 导出同权限（can_export）
        if (!\hasPermission('can_export')) Response::error('权限不足', 403);
        $svc = new \AssetManager($db, $uid, $dataUid);
        $csv = $svc->exportStaleCsv(
            (int)($_GET['days'] ?? 0),
            ($_GET['show_processed'] ?? '') === '1',
            trim((string)($_GET['q'] ?? '')),
            (int)($_GET['min_days'] ?? 0),

            (string)($_GET['loc'] ?? ''),
            array_key_exists('cat_key', $_GET) ? (string)$_GET['cat_key'] : null
        );
        \traceLog($uid, 'stale_export', 'export', 0, "导出呆滞清单 CSV（days=" . (int)($_GET['days'] ?? 0) . " min_days=" . (int)($_GET['min_days'] ?? 0) . "）");
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stale_parts_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        echo $csv;
        exit;
    }

    /** 构造 OrderMetaService（跨模块：读订单级扩展信息） */
    private static function orderSvc(\PDO $db, int $uid, int $dataUid): \Modules\Import\Service\OrderMetaService
    {
        require_once __DIR__ . '/../../modules/import/Service/OrderMetaService.php';
        return new \Modules\Import\Service\OrderMetaService($db, $uid, $dataUid, (int)\getWorkspaceId());
    }

    /** GET stock.order_detail — 单订单完整信息（详情弹窗） */
    private static function handleOrderDetail(\PDO $db, int $uid, int $dataUid): void
    {
        $orderNo = trim((string)($_GET['order_no'] ?? ''));
        if ($orderNo === '') Response::error('缺少订单号');

        $d = self::orderSvc($db, $uid, $dataUid)->orderDetail($orderNo);
        if (!$d) Response::error('未找到该订单的扩展信息', 404);

        // 固定列值 + 自定义扩展值合并为一张「标签 => 值」表，前端直接渲染
        $items = [];
        foreach ($d['cols'] as $k => $label) {
            $items[] = [
                'key'   => $k,
                'label' => $label,
                'value' => trim((string)($d['row'][$k] ?? '')),
                'group' => in_array($k, $d['logistics'], true) ? 'logistics' : 'order',
            ];
        }
        foreach ($d['extLabels'] as $k => $label) {
            $items[] = [
                'key'   => $k,
                'label' => $label,
                'value' => trim((string)($d['ext'][$k] ?? '')),
                'group' => 'custom',
            ];
        }

        Response::ok([
            'order_no'     => $orderNo,
            'items'        => $items,
            'created_time' => (string)($d['row']['created_time'] ?? ''),
            'updated_time' => (string)($d['row']['updated_time'] ?? ''),
        ]);
    }

    /** GET stock.order_columns_get — 取列显隐配置 + 全部可选列（含自定义字段） */
    private static function handleOrderColumnsGet(\PDO $db, int $uid, int $dataUid): void
    {
        $svc  = self::orderSvc($db, $uid, $dataUid);
        $defs = $svc->columnDefs();
        $vis  = $svc->visibleColumns();

        Response::ok([
            'all_columns'    => array_map(
                static fn($k, $label) => ['key' => $k, 'label' => $label],
                array_keys($defs),
                array_values($defs)
            ),
            'visible'        => $vis,
            'always_visible' => \Modules\Import\Service\OrderMetaService::ALWAYS_VISIBLE,
            'custom_fields'  => $svc->customFields(),
        ]);
    }

    /** POST stock.order_columns_save — 保存列显隐配置（需编辑权限，写 trace_log） */
    private static function handleOrderColumnsSave(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $cols = json_decode((string)($_POST['cols'] ?? '[]'), true);
        if (!is_array($cols)) Response::error('参数无效：cols 必须是数组');

        self::orderSvc($db, $uid, $dataUid)->saveVisibleColumns($cols);
        Response::ok(['visible' => self::orderSvc($db, $uid, $dataUid)->visibleColumns()]);
    }

    /** POST stock.order_field_add — 新增自定义字段（需编辑权限，写 trace_log） */
    private static function handleOrderFieldAdd(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $r = self::orderSvc($db, $uid, $dataUid)->addCustomField(
            (string)($_POST['key'] ?? ''),
            (string)($_POST['label'] ?? '')
        );
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r);
    }

    /** POST stock.order_field_toggle — 停用/启用自定义字段（需编辑权限，写 trace_log） */
    private static function handleOrderFieldToggle(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $r = self::orderSvc($db, $uid, $dataUid)->toggleCustomField(
            (string)($_POST['key'] ?? ''),
            ($_POST['enabled'] ?? '') === '1'
        );
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r);
    }

    /** POST stock.order_field_delete — 删除自定义字段（需编辑权限，写 trace_log） */
    private static function handleOrderFieldDelete(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        $r = self::orderSvc($db, $uid, $dataUid)->deleteCustomField(
            (string)($_POST['key'] ?? ''),
            ($_POST['purge'] ?? '') === '1'
        );
        if (!$r['ok']) Response::error($r['msg']);
        Response::ok($r);
    }

    /** 检测扫码 AJAX 模式（前端通过 ajax=1 标识，返回 {ok:true,...} 扁平结构） */
    private static function isScanAjax(): bool
    {
        return ($_POST['ajax'] ?? '') === '1';
    }
}
