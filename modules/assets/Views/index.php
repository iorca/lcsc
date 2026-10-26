<?php
declare(strict_types=1);
/** 资产总览页面 */

use Modules\Assets\Controller\AssetsController;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();

if (!hasFeature('feature_asset_stats')) {
    header('Location: ' . siteUrl('index.php'));
    exit;
}
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$viewData = [
    'totalStockCost' => 0, 'sampleCount' => 0, 'monthInAmount' => 0, 'inStockTypes' => 0,
    'monthNewParts' => 0, 'totalAssetValue' => 0, 'totalStockQty' => 0,
    'assetLabels' => [], 'assetValues' => [], 'barLabels' => [], 'barInValues' => [], 'barOutValues' => [],
    'logs' => [], 'typeLabel' => [],
    'catDist' => [], 'topHigh' => [],
];
try {
    $viewData = AssetsController::prepareViewData($db, $uid, $dataUid);
} catch (\Throwable $e) {
    error_log('AssetsView prepareViewData: ' . $e->getMessage());
}
extract($viewData);

$pageTitle  = '资产总览';
$activePage = 'assets';

\App\Core\AssetManager::addCss('modules/assets/Assets/css/assets.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<div class="main">
    <div class="page-header as-page-head">
        <h2>💰 资产总览</h2>
        <div class="page-desc">成本统计排除样品数据；所有资产核算基于入库采购成本。在库价值按加权平均单价（4 位小数）计算，与累计入库总额在无出库时也可能存在分级舍入尾差（通常不超过几分钱），属正常口径差异。</div>
    </div>

    <!-- 6 个统计卡片 -->
    <div class="stats-grid as-stats-3">
        <div class="stat-card c-blue" title="Σ(库存×加权平均单价)。加权平均单价按 4 位小数存储，逐项相乘后相加可能产生分级舍入尾差。">
            <div class="stat-label">在库资产总价值</div>
            <div class="stat-value">¥<?=number_format($totalAssetValue, 2)?></div>
            <div class="as-stat-note">Σ(库存×加权平均单价，单价 4 位小数)</div>
        </div>
        <div class="stat-card c-green" title="入库流水小计累加（排除样品）。与在库价值的差额来自加权单价 4 位小数舍入，不是数据丢失。">
            <div class="stat-label">累计入库总金额</div>
            <div class="stat-value">¥<?=number_format($totalStockCost, 2)?></div>
            <div class="as-stat-note">入库流水小计累加；与在库价值的尾差来自单价舍入<?php if($sampleCount>0): ?>（已排除<?=$sampleCount?>项样品）<?php endif; ?></div>
        </div>
        <div class="stat-card c-yellow">
            <div class="stat-label">本月新增资产</div>
            <div class="stat-value">¥<?=number_format($monthInAmount, 2)?></div>
            <div class="as-stat-note"><?=date('Y年m月')?>入库小计</div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-label">库存总数量</div>
            <div class="stat-value"><?=number_format($totalStockQty)?></div>
            <div class="as-stat-note">在库物料数量合计</div>
        </div>
        <div class="stat-card c-blue">
            <div class="stat-label">在库物料种类</div>
            <div class="stat-value"><?=$inStockTypes?></div>
            <div class="as-stat-note">库存 &gt; 0</div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-label">本月新增物料</div>
            <div class="stat-value"><?=$monthNewParts?></div>
            <div class="as-stat-note"><?=date('Y年m月')?></div>
        </div>
    </div>

    <?php $reconcileDiff = round($totalStockCost - $totalAssetValue, 2); ?>
    <?php if (abs($reconcileDiff) > 0 && abs($reconcileDiff) < 1): ?>
    <div class="as-reconcile-note">
        口径说明：在库资产总价值与累计入库总金额相差 <b>¥<?=number_format(abs($reconcileDiff), 2)?></b>，
        来自加权平均单价按 4 位小数存储后逐项乘加的舍入尾差，<b>不是数据丢失</b>。
    </div>
    <?php endif; ?>

    <!-- 图表区：双图表 + 饼图 -->
    <div class="asset-charts-grid">
        <div class="card card-pad">
            <div class="sec-title">近12个月总资产折线</div>
            <div class="as-chart-box"><canvas id="assetLineChart"></canvas></div>
        </div>
        <div class="card card-pad">
            <div class="sec-title">月度入库/出库金额对比</div>
            <div class="as-chart-box"><canvas id="assetBarChart"></canvas></div>
        </div>
        <div class="card card-pad">
            <div class="sec-title">分类资产分布</div>
            <div class="as-chart-box"><canvas id="assetPieChart"></canvas></div>
        </div>
    </div>

    <!-- 高价值物料 TOP10 -->
    <?php if(!empty($topHigh)): ?>
    <div class="card card-pad as-mb18">
        <div class="sec-title">高价值物料 TOP10 <span class="as-count-note">（按库存×加权平均成本排序）</span></div>
        <div class="as-scroll-x">
        <table class="data-table as-tb-sm">
            <thead><tr>
                <th class="as-th-pad as-th-c as-th-check">#</th>
                <th class="as-th-pad as-th-l">型号</th>
                <th class="as-th-pad as-th-l">商品编号</th>
                <th class="as-th-pad as-th-r">库存</th>
                <th class="as-th-pad as-th-r">加权平均单价</th>
                <th class="as-th-pad as-th-r">资产价值</th>
            </tr></thead>
            <tbody>
            <?php foreach($topHigh as $i=>$t): ?>
            <tr>
                <td class="as-td-pad as-td-c as-idx"><?=$i+1?></td>
                <td class="as-td-pad as-mono"><?=h($t['model'] ?: $t['product_name'] ?: '—')?></td>
                <td class="as-td-pad as-ppn"><?=h($t['platform_part_no'] ?: '—')?></td>
                <td class="as-td-pad as-td-r as-mono"><?=$t['stock']?></td>
                <td class="as-td-pad as-td-r as-mono">¥<?=number_format($t['avg_cost'], 4)?></td>
                <td class="as-td-pad as-td-r as-mono as-sum">¥<?=number_format($t['asset_value'], 2)?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 最近出入库流水（精简预览，完整流水见流水中心-财务资产视图） -->
    <div class="card card-pad" id="logsTableWrap">
        <div class="sec-title as-sec-row">
            <span>最近出入库流水 <span class="as-count-note">（最近10条）</span></span>
            <a href="index.php?v=stock&view=finance" class="btn btn-ghost btn-sm">查看全部 →</a>
        </div>
        <div class="as-scroll-x">
        <table class="data-table as-tb-sm">
            <thead>
            <tr>
                <th class="as-th-pad">时间</th>
                <th class="as-th-pad">物料</th>
                <th class="as-th-pad">操作类型</th>
                <th class="as-th-pad as-th-r">变动数量</th>
                <th class="as-th-pad as-th-r">入库单价</th>
                <th class="as-th-pad as-th-r">含税小计</th>
            </tr>
            </thead>
            <tbody>
            <?php if(empty($logs)): ?>
            <tr><td colspan="6" class="as-td-empty">暂无数据</td></tr>
            <?php else: foreach($logs as $l):
                [$lab,$col]=$typeLabel[$l['change_type']] ?? [$l['change_type'],''];
                $isSmp = $l['is_sample'] === 1;
                $isUp  = $l['qty_change'] >= 0;

                $tagCls = match(strtolower((string)$col)) {
                    '#4f8ef7' => 'is-blue', '#22c55e' => 'is-green', '#ef4444' => 'is-red',
                    '#f59e0b' => 'is-amber', '#8b5cf6' => 'is-purple', default => '',
                };
            ?>
            <tr<?=$isSmp?' class="as-row-sample"':''?>>
                <td class="as-td-pad as-td-nowrap"><?=h(substr((string)$l['create_time'],0,16))?></td>
                <td class="as-td-pad">
                    <div class="as-ppn"><?=h($l['platform_part_no'] ?: '—')?></div>
                    <div class="as-ppn-sub"><?=h($l['model'] ?: $l['product_name'] ?: '')?></div>
                </td>
                <td class="as-td-pad">
                    <span class="as-tag <?=$tagCls?>"><?=$lab?></span>
                    <?php if($isSmp): ?><span class="as-tag-star" title="样品不计资产">★</span><?php endif; ?>
                </td>
                <td class="as-td-pad as-td-r as-mono as-qty <?=$isUp?'is-up':'is-down'?>"><?=$isUp?'+':''?><?=h((string)$l['qty_change'])?></td>
                <td class="as-td-pad as-td-r as-mono"><?=($l['unit_cost']??0)>0?'¥'.number_format((float)$l['unit_cost'],4):'—'?></td>
                <td class="as-td-pad as-td-r as-mono as-subtotal"><?=($l['subtotal']??0)>0?'¥'.number_format((float)$l['subtotal'],2):'—'?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>


<script>window.AssetsPageConfig = <?= json_encode([
    'assetLabels' => $assetLabels,
    'assetValues' => $assetValues,
    'barLabels' => $barLabels,
    'barInValues' => $barInValues,
    'barOutValues' => $barOutValues,
    'catDist' => $catDist,
    'csrf' => csrf(),

], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php $__chartjs = __DIR__ . '/../../../third_party/js/chart.umd.js'; ?>

<script src="third_party/js/chart.umd.js?v=<?=APP_VERSION.'.'.(is_file($__chartjs) ? filemtime($__chartjs) : '0')?>" defer></script>
<script src="modules/assets/Assets/js/assets.js?v=<?=APP_VERSION.'.'.(is_file(__DIR__.'/../Assets/js/assets.js') ? filemtime(__DIR__.'/../Assets/js/assets.js') : '0')?>" defer></script>

</body></html>
