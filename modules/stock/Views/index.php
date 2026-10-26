<?php
declare(strict_types=1);
/** 流水中心页面 */
require_once __DIR__ . '/../../../config.php';
use Modules\Stock\Controller\StockController;

$user = requireLogin();
$db = getDB();
$uid = $user['id'];
$dataUid = getDataUserId();

// ── CSV 导出（业务流水/财务资产视图委托控制器输出文件流；日志视图走 export.logs API）──
if (isset($_GET['export']) && hasPermission('can_export')) {
    StockController::exportCsv($db, $uid, $dataUid);
}

// ── 正常页面数据（通过控制器）──
try {
    $viewData = StockController::prepareViewData($db, $uid, $dataUid);
} catch (\Throwable $e) {
    error_log('StockController prepareViewData: ' . $e->getMessage());
    $viewData = [
        'view'    => 'business',
        'today'   => ['in_count' => 0, 'in_qty' => 0, 'out_count' => 0, 'out_qty' => 0],
        'canCost' => false,
    ];
}
extract($viewData);

// ── 统一导航所需：二级筛选 Tab（仅业务流水）+ 保留当前筛选条件的查询串 ──
// 「调整与报损」而非「盘点调整」：该 Tab 命中 change_type IN

$flowTabs = ['all' => '全部', 'in' => '入库', 'out' => '出库', 'adjust' => '调整与报损', 'undo' => '撤销'];
$navTab   = ($view === 'business') ? (string)($tab ?? 'all') : 'all';
$flowKeepQs = '';
if ($view === 'business' && isset($params) && is_array($params)) {
    $flowKeepQs = http_build_query(array_filter([
        'part_id'   => (int)$params['part_id'] ?: '',
        'model'     => (string)$params['keyword'],
        'date_from' => (string)$params['date_from'],
        'date_to'   => (string)$params['date_to'],
        'per_page'  => (int)$params['per_page'],
    ], static fn($v) => $v !== '' && $v !== 0));
}

$canShowFinance = $canCost;

$canShowLog = hasFeature('feature_log_view');

// ── 当前视图的一句话说明（三个视图同表stock_log，差异只在「关心什么」）──

$viewDesc = [
    'business' => '数量视角：每次变动多少、变动前后各多少。金额与含税小计见「财务资产」。',
    'log'      => '审计视角：谁在何时、从哪个 IP 做的这次变动，支持批量导出与删除。',
    'finance'  => '财务视角：按分类 / 采购平台看单价与含税小计，样品单独标记。',
    'orders'   => '订单级字段：导入时绑定的收货人 / 快递 / 发票等，与库存变动分开维护。',
][$view] ?? '';

$pageTitle = '流水中心';
$activePage = 'stock';

\App\Core\AssetManager::addCss('modules/stock/Assets/css/flow.css');
require_once __DIR__ . '/../../../ui/layouts/head.php';
?>
<!-- 流水中心专属 CSS（统一导航 + 内容区无感刷新） -->

<div class="main">
<div class="glass-box">

<div class="page-header">
    <div class="flow-head">
        <h2>流水中心</h2>
        <div class="page-desc">今日 <?= h(date('Y-m-d')) ?> 概览</div>
    </div>
</div>

<!-- 今日统计（固定区：不参与内容区局部刷新；点击卡片切换到业务流水对应筛选） -->
<?php $scActiveTab = ($view === 'business') ? $navTab : ''; ?>
<div class="sc-stats" id="scStats">
    <a class="sc-stat c-green<?= $scActiveTab === 'in' ? ' filter-active' : '' ?>" data-tab="in" data-flow data-skip-iframe-nav
       href="?v=stock&view=business&tab=in">
        <div class="stat-label">入库次数</div>
        <div class="stat-value"><?= (int)$today['in_count'] ?></div>
    </a>
    <a class="sc-stat c-red<?= $scActiveTab === 'out' ? ' filter-active' : '' ?>" data-tab="out" data-flow data-skip-iframe-nav
       href="?v=stock&view=business&tab=out">
        <div class="stat-label">出库次数</div>
        <div class="stat-value"><?= (int)$today['out_count'] ?></div>
    </a>
    <a class="sc-stat c-blue<?= $scActiveTab === 'in' ? ' filter-active' : '' ?>" data-tab="in" data-flow data-skip-iframe-nav
       href="?v=stock&view=business&tab=in">
        <div class="stat-label">入库数量</div>
        <div class="stat-value"><?= number_format((int)$today['in_qty']) ?></div>
    </a>
    <a class="sc-stat c-yellow<?= $scActiveTab === 'out' ? ' filter-active' : '' ?>" data-tab="out" data-flow data-skip-iframe-nav
       href="?v=stock&view=business&tab=out">
        <div class="stat-label">出库数量</div>
        <div class="stat-value"><?= number_format((int)$today['out_qty']) ?></div>
    </a>
</div>

<!-- 统一导航（吸顶固定：一级视图 + 二级筛选；均不参与内容区局部刷新） -->
<div class="flow-nav" id="flowNav">
    <div class="flow-tabs1">
        <a class="flow-tab1<?= $view === 'business' ? ' active' : '' ?>" data-view="business" data-flow data-skip-iframe-nav
           href="?v=stock&view=business" title="数量视角：每次变动多少、前后多少。财务口径见「财务资产」">业务流水</a>
        <?php if ($canShowLog): ?>
        <a class="flow-tab1<?= $view === 'log' ? ' active' : '' ?>" data-view="log" data-flow data-skip-iframe-nav
           href="?v=stock&view=log" title="审计视角：谁、何时、从哪个 IP 做的这次变动">操作日志</a>
        <?php endif; ?>
        <?php if ($canShowFinance): ?>
        <a class="flow-tab1<?= $view === 'finance' ? ' active' : '' ?>" data-view="finance" data-flow data-skip-iframe-nav
           href="?v=stock&view=finance" title="财务视角：按分类 / 平台看金额与含税小计">财务资产</a>
        <?php endif; ?>
        <a class="flow-tab1<?= $view === 'orders' ? ' active' : '' ?>" data-view="orders" data-flow data-skip-iframe-nav
           href="?v=stock&view=orders" title="导入时手动绑定的订单级字段（收货人/快递/发票等）">订单信息</a>
    </div>
    <div class="flow-tabs2<?= $view === 'business' ? '' : ' is-hidden' ?>" id="flowSubNav">
        <?php foreach ($flowTabs as $k => $label): ?>
        <a class="flow-tab2<?= $navTab === $k ? ' active' : '' ?>" data-tab="<?= $k ?>" data-flow data-skip-iframe-nav
           href="?v=stock&tab=<?= $k ?><?= $flowKeepQs !== '' ? '&' . h($flowKeepQs) : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <p class="flow-view-desc"><?= h($viewDesc) ?></p>
</div>

<!-- 内容区：无感刷新的唯一变化区域（筛选栏 + 表格 + 分页） -->
<div class="flow-region" id="flowRegion">
<?php require __DIR__ . '/partials/flow_region.php'; ?>
</div>

</div>
</div>

<script>
window.FlowCenterConfig = {
    api: 'app/api/router.php?action=stock.flow_region',
    view: <?= json_encode($view, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    tab: <?= json_encode($navTab, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
window.LogPageConfig = <?= json_encode(['csrf' => csrf(), 'isAdmin' => isAdmin()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="modules/stock/Assets/js/flow.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__ . '/../Assets/js/flow.js')?>"></script>
<script src="modules/system/Assets/js/log.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__ . '/../../../modules/system/Assets/js/log.js')?>"></script>

</body>
</html>
