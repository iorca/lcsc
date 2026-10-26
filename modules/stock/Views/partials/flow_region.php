<?php
declare(strict_types=1);
/** partials/flow_region.php — 流水中心「内容区」单一渲染源 */

if ($view === 'log' && isset($log) && is_array($log)) {
    extract($log, EXTR_SKIP);
    $logPage = max(1, (int)($log['page'] ?? 1));
}

if ($view === 'log') {
    $flash     = $flash ?? null;
    $logs      = $logs ?? [];
    $total     = $total ?? 0;
    $logPage   = $logPage ?? 1;
    $totalPage = $totalPage ?? 1;
    $perPage   = $perPage ?? 25;
    $typeInfo  = $typeInfo ?? [];
    $searchKw  = $searchKw ?? '';
}

// 财务资产视图：展开 $finance 数据 + 兜底
if ($view === 'finance') {
    if (isset($finance) && is_array($finance)) {
        extract($finance, EXTR_SKIP);
    }
    $fKeyword   = $fKeyword ?? '';
    $fCatId     = $fCatId ?? 0;
    $fPlatId    = $fPlatId ?? 0;
    $fDateFrm   = $fDateFrm ?? '';
    $fDateTo    = $fDateTo ?? '';
    $logs       = $logs ?? [];
    $total      = $total ?? 0;
    $page       = $page ?? 1;
    $totalPages = $totalPages ?? 1;
    $perPage    = $perPage ?? 15;
    $typeLabel  = $typeLabel ?? [];
    $categories = $categories ?? [];
    $platforms  = $platforms ?? [];
}

if ($view === 'orders') {
    $orders = $orders ?? ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'per_page' => 25, 'keyword' => '', 'cols' => []];

    $orderCols         = $orderCols ?? [];
    $orderVisibleCols  = $orderVisibleCols ?? array_keys($orderCols);
    $orderAlwaysCols   = $orderAlwaysCols ?? ['order_no'];
    $orderCustomFields = $orderCustomFields ?? [];
}

if ($view === 'log') {
    require __DIR__ . '/flow_log.php';
} elseif ($view === 'finance') {
    require __DIR__ . '/flow_finance.php';
} elseif ($view === 'orders') {
    require __DIR__ . '/flow_orders.php';
} else {
    require __DIR__ . '/flow_business.php';
}
