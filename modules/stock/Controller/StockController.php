<?php
declare(strict_types=1);

namespace Modules\Stock\Controller;

use PDO;

/** 流水中心页控制器 */
final class StockController
{
    /** 组装流水中心页视图数据（按 view 分视图装配） */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid): array
    {
        // ── 视图参数解析（business 默认）──
        $view = strtolower(trim((string)($_GET['view'] ?? 'business')));
        if (!in_array($view, ['business', 'log', 'finance', 'orders'], true)) $view = 'business';
        // 财务视图门控：只看成本开关（双版本同一判据；开关关闭则回退业务流水）

        if ($view === 'finance' && !\can_view_cost()) $view = 'business';

        if ($view === 'log' && !\hasFeature('feature_log_view')) $view = 'business';

        $data = [
            'view'   => $view,
            'today'  => (new \Modules\Stock\Service\StockStatsService($db, $dataUid))->getTodayStats(),
            'canCost' => \can_view_cost(),
        ];

        // ── 订单信息视图：委托 Import 模块的 OrderMetaService（只读）──
        if ($view === 'orders') {
            require_once __DIR__ . '/../../import/Service/OrderMetaService.php';
            $svc = new \Modules\Import\Service\OrderMetaService($db, $uid, $dataUid, (int)\getWorkspaceId());
            $data['orders'] = $svc->listOrders($_GET);

            $data['orderCols']         = $svc->columnDefs();
            $data['orderVisibleCols']  = $svc->visibleColumns();
            $data['orderAlwaysCols']   = \Modules\Import\Service\OrderMetaService::ALWAYS_VISIBLE;
            $data['orderCustomFields'] = $svc->customFields();
            return $data;
        }

        if ($view === 'log') {
            $data['log'] = \Modules\System\Controller\SystemController::prepareLogData($db, $uid, $dataUid);
            return $data;
        }

        // ── 财务资产视图：委托 AssetManager（带成本字段，团队版专用）──
        if ($view === 'finance') {
            $data['finance'] = self::fetchFinanceData($db, $uid, $dataUid);
            return $data;
        }

        // ── 业务流水视图（默认视图）──
        require_once __DIR__ . '/../Service/StockService.php';
        $sm = new \StockManager($db, $uid, $dataUid);

        // ── Tab 筛选映射 ──
        $tab = (string)($_GET['tab'] ?? 'all');
        $filterMap = ['all' => '', 'in' => 'in', 'out' => 'out', 'adjust' => 'adjust', 'undo' => 'undo'];
        $changeType = $filterMap[$tab] ?? '';

        // ── 查询参数 ──
        $params = [
            'filter'    => $changeType,
            'part_id'   => (int)($_GET['part_id'] ?? 0),
            'keyword'   => trim((string)($_GET['model'] ?? '')),
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to'   => trim((string)($_GET['date_to'] ?? '')),
            'page'      => max(1, (int)($_GET['page'] ?? 1)),

            'per_page'  => max(10, (int)($_GET['per_page'] ?? ($_COOKIE['per_page_stock'] ?? 25))),
        ];

        // ── 出入库流水查询 ──
        $result = ['items' => [], 'total' => 0, 'page' => 1, 'total_page' => 1];
        try {
            $result = $sm->queryStockLog($params);
        } catch (\Throwable $e) { error_log('StockController queryStockLog: ' . $e->getMessage()); }

        $data['tab']    = $tab;
        $data['params'] = $params;
        $data['result'] = $result;
        return $data;
    }

    /** 业务流水 / 财务资产视图 CSV 导出（直接输出文件流并终止） */
    public static function exportCsv(PDO $db, int $uid, int $dataUid): void
    {

        $exportView = strtolower(trim((string)($_GET['view'] ?? 'business')));
        if ($exportView === 'finance' && !\can_view_cost()) $exportView = 'business';
        if (!in_array($exportView, ['business', 'finance'], true)) $exportView = 'business';
        $_GET['view']     = $exportView;
        $_GET['per_page'] = '10000';

        try {
            $viewData = self::prepareViewData($db, $uid, $dataUid);
        } catch (\Throwable $e) {
            error_log('StockController prepareViewData (export): ' . $e->getMessage());
            $viewData = [
                'view'    => 'business',
                'today'   => ['in_count' => 0, 'in_qty' => 0, 'out_count' => 0, 'out_qty' => 0],
                'canCost' => false,
                'tab'     => 'all',
                'params'  => ['filter' => '', 'part_id' => 0, 'keyword' => '', 'date_from' => '', 'date_to' => '', 'page' => 1, 'per_page' => 25],
                'result'  => ['items' => [], 'total' => 0, 'page' => 1, 'total_page' => 1],
            ];
        }
        extract($viewData);

        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="stock_flow_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");

        if ($exportView === 'finance') {
            // ── 财务资产视图 CSV（含成本字段，与页面筛选一致）──
            $finTypeLabels = \AssetManager::TYPE_LABELS;
            fputcsv($fp, ['时间', '物料编号', '型号', '分类', '采购平台', '操作类型', '变动数量', '入库单价', '含税小计', '是否样品', '备注']);
            foreach (($finance['logs'] ?? []) as $row) {
                $typeLabel = $finTypeLabels[$row['change_type']][0] ?? $row['change_type'];
                $chg = (int)$row['qty_change'];
                fputcsv($fp, [
                    $row['create_time'],
                    $row['platform_part_no'] ?? '',
                    $row['model'] ?? '',
                    $row['cat_names'] ?? '',
                    $row['pname'] ?? '',
                    $typeLabel,
                    ($chg >= 0 ? '+' : '') . $chg,
                    ($row['unit_cost'] ?? 0) > 0 ? number_format((float)$row['unit_cost'], 4) : '',
                    ($row['subtotal'] ?? 0) > 0 ? number_format((float)$row['subtotal'], 2) : '',
                    ((int)($row['is_sample'] ?? 0)) === 1 ? '是' : '否',
                    $row['remark'] ?? '',
                ]);
            }
        } else {
            // ── 业务流水视图 CSV ──

            $headers = ['时间', '物料编号', '型号', '类型', '变动', '前库存', '后库存', '备注'];
            fputcsv($fp, $headers);
            $typeLabels = [
                'scan_in' => '扫码入库', 'scan_out' => '扫码出库',
                'manual_in' => '手动入库', 'manual_out' => '手动出库',
                'import' => '订单导入', 'import_in' => '订单导入', 'adjust' => '库存调整',
                'damaged' => '报损', 'repair' => '修复',
                'scan_undo_in' => '撤销扫码入库', 'scan_undo_out' => '撤销扫码出库',
                'import_undo_out' => '导入回退',
                'bom_checkout' => 'BOM出库', 'bom_out' => 'BOM出库', 'batch_stock_in' => '批量入库',
            ];
            foreach ($result['items'] as $row) {
                fputcsv($fp, [
                    $row['create_time'],
                    $row['platform_part_no'] ?? '',
                    $row['model'] ?? '',
                    $typeLabels[$row['change_type']] ?? $row['change_type'],
                    (int)$row['qty_change'] >= 0 ? '+' . $row['qty_change'] : $row['qty_change'],
                    $row['qty_before'],
                    $row['qty_after'],
                    $row['remark'] ?? '',
                ]);
            }
        }
        fclose($fp);
        exit;
    }

    /** 渲染流水中心「内容区」HTML（筛选栏 + 表格 + 分页） */
    public static function renderRegion(array $viewData): string
    {
        ob_start();
        extract($viewData, EXTR_SKIP);
        require __DIR__ . '/../Views/partials/flow_region.php';
        return (string)ob_get_clean();
    }

    /** 财务资产视图数据（迁移自资产总览流水表格；调用方已保证团队版+成本可见） */
    private static function fetchFinanceData(PDO $db, int $uid, int $dataUid): array
    {
        require_once __DIR__ . '/../../assets/Service/AssetsService.php';
        $am = new \AssetManager($db, $uid, $dataUid);

        $finance = [
            'fKeyword' => '', 'fCatId' => 0, 'fPlatId' => 0, 'fDateFrm' => '', 'fDateTo' => '',
            'logs' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1, 'perPage' => 15,
            'typeLabel' => [], 'categories' => [], 'platforms' => [],
        ];

        try {
            $logsData   = $am->listLogs($_GET);
            $filterOpts = $am->getFilterOptions();
        } catch (\Throwable $e) {
            error_log('StockController finance: ' . $e->getMessage());
            return $finance;
        }

        return [
            'fKeyword'   => trim((string)($_GET['keyword'] ?? '')),
            'fCatId'     => (int)($_GET['cat_id'] ?? 0),
            'fPlatId'    => (int)($_GET['plat_id'] ?? 0),
            'fDateFrm'   => trim((string)($_GET['date_from'] ?? '')),
            'fDateTo'    => trim((string)($_GET['date_to'] ?? '')),
            'logs'       => $logsData['logs'] ?? [],
            'total'      => $logsData['total'] ?? 0,
            'page'       => $logsData['page'] ?? 1,
            'totalPages' => $logsData['total_pages'] ?? 1,
            'perPage'    => $logsData['per_page'] ?? 15,
            'typeLabel'  => $logsData['type_labels'] ?? [],
            'categories' => $filterOpts['categories'] ?? [],
            'platforms'  => $filterOpts['platforms'] ?? [],
        ];
    }
}
