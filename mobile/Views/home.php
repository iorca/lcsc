<?php
declare(strict_types=1);
/** mobile/Views/home.php — 移动端「工作台」 */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

use Modules\Inventory\Service\DashboardReadService;

$svc  = new DashboardReadService(getDB(), (int)$user['id'], (int)getDataUserId());
$data = $svc->getDashboardData();
DashboardReadService::fillDerived($data);

$stats       = $data['stats'];
$anomaly     = $data['anomaly'];
$lowStock    = (int)$data['lowStockCount'];
$zeroCount   = (int)($stats['zero_count'] ?? 0);
$damaged     = (int)($anomaly['damaged'] ?? 0);
$negative    = (int)($anomaly['negative'] ?? 0);
$staleCount  = (int)$data['staleCount'];
$undoPending = (int)$data['undoPending'];
$todoCount   = (int)$data['todoCount'];
$totalValue  = (float)($stats['total_value'] ?? 0);
$canViewCost = can_view_cost();

$healthRate  = (int)($data['healthRate'] ?? 100);   // 库存完好率 %
$lowParts    = (array)($data['lowStockParts'] ?? []);  // 低库存清单（最紧张若干条）
$recentParts = (array)($data['recentParts'] ?? []);    // 最近添加的物料
$weekBrief   = (array)($data['week'] ?? []);           // 本周出入库笔数与数量
$weekNet     = (int)($data['weekNet'] ?? 0);           // 本周净增数量（入 - 出）

/** 数量简写：超过 1 万显示「x.x万」（与 PC 工作台 wbNum 同口径） */
function mHomeNum(int|float $n): string {
    $n = (float)$n;
    return $n >= 10000 ? rtrim(rtrim(number_format($n / 10000, 1), '0'), '.') . '万' : number_format($n);
}

/** 读取用户选定的统计卡片（`user_settings.mobile_home_cards`，JSON 数组） */
function mHomeCardSelection(int $uid, array $catalog): array {
    $raw = (string)getUserSetting($uid, 'mobile_home_cards', '');
    $picked = [];
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $k) { if (is_string($k) && isset($catalog[$k])) $picked[$k] = true; }
        }
    }
    $sel = [];
    foreach ($catalog as $k => $_) { if (isset($picked[$k])) $sel[] = $k; }
    if (empty($sel)) {
        foreach (['kinds', 'low', 'zero'] as $k) { if (isset($catalog[$k])) $sel[] = $k; }
    }
    return array_slice($sel, 0, 6);
}

$cardCatalog = [
    'kinds'    => ['label' => '物料种类', 'value' => number_format((int)($stats['total'] ?? 0)), 'sub' => '在库 ' . number_format((int)($stats['stock_kinds'] ?? 0)) . ' 种', 'tone' => ''],
    'low'      => ['label' => '库存不足', 'value' => number_format($lowStock),   'sub' => '',          'tone' => 'warn'],
    'zero'     => ['label' => '已用完',   'value' => number_format($zeroCount), 'sub' => '',          'tone' => 'danger'],
    'damaged'  => ['label' => '不良品',   'value' => number_format($damaged),   'sub' => '含报废',     'tone' => 'danger'],
    'negative' => ['label' => '负库存',   'value' => number_format($negative),  'sub' => '',          'tone' => 'danger'],
];
if ($canViewCost) {
    $cardCatalog['value'] = [
        'label' => '在库资产总价值',
        'value' => '¥' . ($totalValue >= 10000 ? mHomeNum($totalValue) : number_format($totalValue, 0)),
        'sub'   => '',
        'tone'  => 'accent',
    ];
}
$cardCatalog += [
    // 完好率副标按实况给（不写死「无低库存」—— 有低库存时那话就是错的）
    'health'   => ['label' => '库存完好率', 'value' => $healthRate . '%', 'sub' => $lowStock + $negative > 0 ? ($lowStock + $negative) . ' 种待处理' : '无低库存 / 负库存', 'tone' => $healthRate >= 80 ? 'accent' : 'warn'],
    'week_in'  => ['label' => '本周入库',   'value' => number_format((int)($weekBrief['in_qty'] ?? 0)),  'sub' => (int)($weekBrief['in_count'] ?? 0) . ' 笔', 'tone' => 'accent'],
    'week_out' => ['label' => '本周出库',   'value' => number_format((int)($weekBrief['out_qty'] ?? 0)), 'sub' => (int)($weekBrief['out_count'] ?? 0) . ' 笔', 'tone' => 'accent'],
    'stale'    => ['label' => '呆滞物料', 'value' => number_format($staleCount), 'sub' => '长期未动销', 'tone' => 'warn'],
];

$selectedKeys = mHomeCardSelection((int)$user['id'], $cardCatalog);

// 前端编辑面板需要重建网格，故把目录 + 当前选择一并注入配置
$listCatalog = [];
foreach ($cardCatalog as $k => $c) {
    $listCatalog[] = ['key' => $k, 'label' => $c['label'], 'value' => $c['value'], 'sub' => $c['sub'], 'tone' => $c['tone']];
}

$trend     = $data['trend'];
$catDist   = $data['catDist'];
$__chartjs = __DIR__ . '/../../third_party/js/chart.umd.js';
$chartUrl  = '../third_party/js/chart.umd.js?v=' . APP_VERSION . '.' . (is_file($__chartjs) ? filemtime($__chartjs) : '0');

$trendHasData   = array_sum(array_map('intval', (array)($trend['in'] ?? [])))
               + array_sum(array_map('intval', (array)($trend['out'] ?? []))) > 0;
$catDistHasData = !empty($catDist) && array_sum(array_map(static fn($r) => (int)($r['qty'] ?? 0), $catDist)) > 0;
?>
<!-- ══ 概览卡片（可编辑：行内面板勾选展示项，零子弹窗）══ -->
<div class="m-hstat-head">
    <span class="m-hstat-title">概览</span>
    <button type="button" class="m-ghost-btn" id="mStatEditBtn" aria-expanded="false" aria-controls="mStatEditor">
        <span class="m-ico m-ico--gear" aria-hidden="true"></span> 编辑
    </button>
</div>
<div class="m-stat-grid m-hstat-grid" id="mStatGrid">
<?php foreach ($selectedKeys as $k): $c = $cardCatalog[$k]; ?>
    <div class="m-stat<?= $c['tone'] !== '' ? ' m-stat-' . $c['tone'] : '' ?>">
        <div class="m-stat-value"><?= h($c['value']) ?></div>
        <div class="m-stat-label"><?= h($c['label']) ?></div>
        <?php if ($c['sub'] !== ''): ?><div class="m-stat-sub"><?= h($c['sub']) ?></div><?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<div class="m-card m-hstat-editor is-hidden" id="mStatEditor">
    <div class="m-hstat-editor-title">选择要显示的卡片（最多 6 项，按下方顺序排列）</div>
    <div class="m-hstat-checks">
    <?php foreach ($cardCatalog as $k => $c): ?>
        <label class="m-check-row">
            <input type="checkbox" value="<?= h($k) ?>"<?= in_array($k, $selectedKeys, true) ? ' checked' : '' ?>>
            <span><?= h($c['label']) ?></span>
        </label>
    <?php endforeach; ?>
    </div>
    <div class="m-hstat-editor-acts">
        <button type="button" class="m-btn m-btn-sm m-btn--inline" id="mStatCancel">取消</button>
        <button type="button" class="m-btn m-btn-sm m-btn--inline m-btn-primary" id="mStatSave">保存</button>
    </div>
</div>

<!-- ══ 低库存清单（最紧张的若干条；点击跳查询页按「库存不足」筛选）══ -->
<?php if (!empty($lowParts)): ?>
<section class="m-card" id="mLowSection">
    <div class="m-card-title">
        低库存待补货
        <span class="m-card-title-num"><?= number_format($lowStock) ?> 种</span>
    </div>
    <div class="m-low-list">
        <?php foreach ($lowParts as $lp):
            $cur = (int)($lp['stock'] ?? 0);
            $thr = max(1, (int)($lp['eff_threshold'] ?? 0));
            $pct = min(100, (int)round($cur / $thr * 100));
            $name = (string)($lp['model'] ?: ($lp['platform_part_no'] ?? ''));
        ?>
        <a class="m-low-item" href="index.php?v=search&amp;filter=low">
            <span class="m-low-main">
                <span class="m-low-name"><?= h($name !== '' ? $name : ('#' . (int)($lp['id'] ?? 0))) ?></span>
                <span class="m-low-desc"><?= h((string)($lp['product_name'] ?? '')) ?></span>
            </span>
            <progress class="m-low-bar<?= $pct <= 30 ? ' is-bad' : ' is-warn' ?>" value="<?= $cur ?>" max="<?= $thr ?>"></progress>
            <span class="m-low-qty"><?= $cur ?> / <?= $thr ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>


<div id="mCollapseBody">
    <div class="m-card<?= $trendHasData ? '' : ' is-hidden' ?>" id="mTrendCard">
        <div class="m-card-title">近 7 天出入库趋势</div>
        <div class="m-chart-box"><canvas id="mTrendChart"></canvas></div>
    </div>

    <div class="m-card<?= $catDistHasData ? '' : ' is-hidden' ?>" id="mCatCard">
        <div class="m-card-title">库存结构 · 按一级分类</div>
        <div class="m-chart-box"><canvas id="mCatChart"></canvas></div>
    </div>

    <div class="m-card<?= $todoCount > 0 ? '' : ' is-hidden' ?>" id="mTodoCard">
        <div class="m-card-title">待处理业务<?php if ($todoCount > 0): ?> <span class="m-todo-total"><?= number_format($todoCount) ?> 项</span><?php endif; ?></div>
        <?php if ($todoCount > 0): ?>
            <div class="m-todo-list">
                <?php ?>
                <?php if ($lowStock > 0): ?>
                <a class="m-todo-item" href="index.php?v=search&amp;filter=low">
                    <span class="m-todo-dot is-orange"></span>
                    <span class="m-todo-main"><span class="m-todo-name">低库存待补货</span><span class="m-todo-desc">低于安全阈值 · 点击查看清单</span></span>
                    <span class="m-todo-cnt is-orange"><?= number_format($lowStock) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($zeroCount > 0): ?>
                <a class="m-todo-item" href="index.php?v=search&amp;filter=zero">
                    <span class="m-todo-dot is-red"></span>
                    <span class="m-todo-main"><span class="m-todo-name">已用完待补货</span><span class="m-todo-desc">库存为 0 · 点击查看清单</span></span>
                    <span class="m-todo-cnt is-red"><?= number_format($zeroCount) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($staleCount > 0): ?>
                <a class="m-todo-item" href="index.php?v=stale">
                    <span class="m-todo-dot is-orange"></span>
                    <span class="m-todo-main"><span class="m-todo-name">呆滞物料</span><span class="m-todo-desc">长期未动销 · 点击查看清单</span></span>
                    <span class="m-todo-cnt is-orange"><?= number_format($staleCount) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($undoPending > 0): ?>
                <?php // 移动端无撤销复核页：只提示去电脑端，不跨端跳转?>
                <div class="m-todo-item is-pc-hint" role="button" tabindex="0"
                     data-pc-hint="撤销流水复核请在电脑端「流水中心 · 业务流水 · 撤销」中处理">
                    <span class="m-todo-dot is-blue"></span>
                    <span class="m-todo-main"><span class="m-todo-name">撤销流水待复核</span><span class="m-todo-desc">近 7 天撤销记录 · 需在电脑端处理</span></span>
                    <span class="m-todo-cnt is-blue"><?= number_format($undoPending) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($damaged > 0): ?>
                <a class="m-todo-item" href="index.php?v=search&amp;filter=damaged">
                    <span class="m-todo-dot is-orange"></span>
                    <span class="m-todo-main"><span class="m-todo-name">不良品待处置</span><span class="m-todo-desc">存在报废数量 · 点击查看清单</span></span>
                    <span class="m-todo-cnt is-orange"><?= number_format($damaged) ?></span>
                </a>
                <?php endif; ?>
                <?php if ($negative > 0): ?>
                <a class="m-todo-item" href="index.php?v=search&amp;filter=negative">
                    <span class="m-todo-dot is-red"></span>
                    <span class="m-todo-main"><span class="m-todo-name">负库存数据异常</span><span class="m-todo-desc">建议盘点修正 · 点击查看清单</span></span>
                    <span class="m-todo-cnt is-red"><?= number_format($negative) ?></span>
                </a>
                <?php endif; ?>
                <?php


                foreach ((array)$data['dqAlerts'] as $dq): ?>
                <div class="m-todo-item is-pc-hint" role="button" tabindex="0"
                     data-pc-hint="数据质量请在电脑端「数据质量」页查看与处理">
                    <span class="m-todo-dot is-red"></span>
                    <span class="m-todo-main"><span class="m-todo-name"><?= h($dq['label']) ?></span><span class="m-todo-desc"><?= h($dq['hint']) ?> · 需在电脑端处理</span></span>
                    <span class="m-todo-cnt is-red"><?= number_format((int)$dq['count']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ 最近添加的物料（点击跳查询页按型号检索；放在图表之后）══ -->
<?php if (!empty($recentParts)): ?>
<section class="m-card" id="mRecentSection">
    <div class="m-card-title">最近添加</div>
    <div class="m-low-list">
        <?php foreach ($recentParts as $rp):
            $rname = (string)($rp['model'] ?: ($rp['platform_part_no'] ?? ''));
        ?>
        <a class="m-low-item" href="index.php?v=search&amp;q=<?= h(urlencode($rname !== '' ? $rname : '')) ?>">
            <span class="m-low-main">
                <span class="m-low-name"><?= h($rname !== '' ? $rname : ('#' . (int)($rp['id'] ?? 0))) ?></span>
                <span class="m-low-desc"><?= h(mb_substr((string)($rp['product_name'] ?? ''), 0, 24)) ?></span>
            </span>
            <span class="m-low-qty"><?= number_format((int)($rp['stock'] ?? 0)) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="m-card m-note-card">
    <div class="m-note-title"><span class="m-ico m-ico--monitor" aria-hidden="true"></span> 更多功能请在电脑端使用</div>
    <div class="m-note-text">移动端专注日常高频操作（扫码出入库 / 查询物料 / 呆滞物料 / 我的）；BOM、导入导出、分类管理、库位管理、后台设置等功能请在电脑端访问。</div>
</div>

<script>
window.MobileHomeConfig = <?= json_encode([
    'catalog'  => $listCatalog,
    'selected' => $selectedKeys,
    'max'      => 6,
    'chartUrl' => $chartUrl,
    'trend'    => [
        'labels' => $trend['labels'],
        'in'     => $trend['in'],
        'out'    => $trend['out'],
    ],
    'catDist'  => array_map(static fn($r) => [
        'name'  => (string)$r['name'],
        'qty'   => (int)$r['qty'],
        'kinds' => (int)$r['kinds'],
    ], $catDist),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
