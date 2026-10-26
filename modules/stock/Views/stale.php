<?php
declare(strict_types=1);
/** modules/stock/Views/stale.php — 呆滞物料专区（P8） */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../modules/assets/Service/AssetsService.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$pageTitle = '呆滞物料';
$activePage = 'stock';

\App\Core\AssetManager::addCss('modules/stock/Assets/css/checklist.css');

$useLayoutFoot = true;
require __DIR__ . '/../../../ui/layouts/head.php';

$staleDays = AssetManager::getStaleDays();
$canCost = can_view_cost();

$canExport = hasPermission('can_export');
$canEdit   = hasPermission('can_edit');
?>

<div class="main">
<div class="page-header">
    <h2>🗄 呆滞物料专区</h2>
    <div class="page-desc">超过 N 天无出入库且有库存的物料（N=<?= $staleDays ?>，可在系统设置调整）；可按库位 / 分类看积压压在哪里，物料发生动销后已处理标记自动清除</div>
</div>

<div class="check-section">
    <!-- 视图切换：同一批物料的三种看法（清单=哪些料；库位/分类=积压压在哪里） -->
    <div class="stale-viewseg" id="staleViewSeg" role="tablist" aria-label="呆滞物料视图">
        <button type="button" class="stale-seg is-on" data-view="list" role="tab" aria-selected="true">📋 呆滞清单</button>
        <button type="button" class="stale-seg" data-view="location" role="tab" aria-selected="false">📍 按库位</button>
        <button type="button" class="stale-seg" data-view="category" role="tab" aria-selected="false">🗂 按分类</button>
    </div>

    <!-- 下钻过滤提示：从聚合行点进来时显示，✕ 可清掉 -->
    <div id="staleDrill" class="stale-drill is-hidden"></div>

    <!-- 工具行：天数 + 严重度 + 已处理开关 + 搜索 + 排序 + 导出 + 标记 -->
    <div class="check-filter-row">
        <label>呆滞≥
            <select id="staleDays">
                <?php
                $presetDays = [30, 60, 90, 180, 365];
                if (!in_array($staleDays, $presetDays, true)) $presetDays[] = $staleDays;
                sort($presetDays);
                foreach ($presetDays as $d): ?>
                <option value="<?=$d?>" <?=$d === $staleDays ? 'selected' : ''?>><?=$d?> 天</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>严重度
            <select id="staleMinDays">
                <option value="0">全部</option>
                <option value="90">≥ 90 天</option>
                <option value="180">≥ 180 天</option>
                <option value="365">≥ 365 天</option>
            </select>
        </label>
        <label class="stale-switch-label">
            <input type="checkbox" id="staleShowProcessed"> 显示已处理
        </label>
        <input type="text" id="staleSearch" placeholder="搜索型号/编号/品名" class="cs-w170">
        <button class="btn btn-ghost" onclick="Stale.load(1)">🔍 查询</button>
        <label class="stale-list-only">排序
            <select id="staleSort">
                <option value="stale|asc">呆滞最久优先</option>
                <option value="stale|desc">呆滞最短优先</option>
                <?php if ($canCost): ?><option value="value|desc">占用金额最多</option><?php endif; ?>
                <option value="stock|desc">库存数量最多</option>
                <option value="name|asc">型号 A→Z</option>
            </select>
        </label>
        <span class="toolbar-spacer"></span>
        <?php if ($canExport): ?>
        <button class="btn btn-ghost stale-list-only" onclick="Stale.exportCsv()">📄 导出 CSV</button>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <button class="btn btn-ghost stale-list-only" onclick="Stale.markAll()">✓ 全部标记已处理</button>
        <button class="btn btn-primary stale-list-only" onclick="Stale.markProcessed()">✓ 标记勾选为已处理</button>
        <?php endif; ?>
    </div>

    <!-- 汇总条 -->
    <div id="staleSummary" class="stale-summary"></div>

    <!-- 分档统计：多久没动销的三档分布（数量 + 占用资金） -->
    <div id="staleBuckets" class="stale-buckets"></div>

    <!-- 清单表 / 聚合表（同容器，由 Stale.setView 决定渲染哪一种） -->
    <div id="staleTableWrap" class="cs-table-wrap">
        <div class="stale-loading">⏳ 加载中…</div>
    </div>

    <!-- 分页 -->
    <div id="stalePager" class="stale-pager"></div>
</div>
</div>

<script>
window.StalePageConfig = <?= json_encode([
    'defaultDays' => (int)$staleDays,
    'canCost'     => (bool)$canCost,
    'canEdit'     => (bool)$canEdit,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="modules/stock/Assets/js/stale.js?v=<?=filemtime(__DIR__.'/../Assets/js/stale.js')?>"></script>
<?php require __DIR__ . '/../../../ui/layouts/foot.php'; ?>
