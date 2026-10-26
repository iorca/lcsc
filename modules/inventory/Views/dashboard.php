<?php
declare(strict_types=1);
/** 团队版工作台首页 */

use Modules\Inventory\Service\DashboardReadService;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

\App\Core\AssetManager::addCss('modules/inventory/Assets/css/dashboard.css');

$pageTitle = '工作台';
$activePage = 'home';
$extraTopbarRight = '';

// 欢迎/公告通知（顶部中间轻提示，仅首次显示；逻辑与物料列表原公告一致）
$welcomeNotices = resolveWelcomeNotices($uid);
// 登录欢迎 flash：登录即弹一次
$welcomeFlash = $_SESSION['login_flash'] ?? '';
if ($welcomeFlash !== '') unset($_SESSION['login_flash']);

// ── 首屏数据（Service 一次取齐；派生指标由 Service 补齐）──
$dashboardData = (new DashboardReadService($db, $uid, $dataUid))->getDashboardData();
DashboardReadService::fillDerived($dashboardData);
extract($dashboardData);

require __DIR__ . '/../../../ui/layouts/head.php';

// ── 成本可见性（统计卡与图表按此门控，个人版 / 关闭成本视图时不渲染）──
$canViewCost = \App\Core\Edition::canViewCost();

// ── 功能入口（按版本门控过滤，供快捷入口与模块卡片共用同一份清单）──
$modules = [
    ['key' => 'parts',      'title' => '物料管理', 'desc' => '元器件档案与库存台账', 'icon' => 'box',       'tone' => 'blue',   'url' => 'index.php?v=parts'],
    ['key' => 'import',     'title' => '导入中心', 'desc' => '对账单批量入库',       'icon' => 'import',    'tone' => 'green',  'url' => 'index.php?v=import', 'perm' => 'can_import'],
    ['key' => 'bom',        'title' => 'BOM 项目', 'desc' => '项目物料清单与核算',   'icon' => 'layers',    'tone' => 'purple', 'url' => 'index.php?v=bom',            'feature' => 'bom_basic', 'perm' => 'can_export'],
    ['key' => 'stock',      'title' => '流水中心', 'desc' => '出入库记录与对账',     'icon' => 'list',      'tone' => 'orange', 'url' => 'index.php?v=stock'],
    ['key' => 'data_health','title' => '数据质量', 'desc' => '完整性与一致性体检',   'icon' => 'activity',  'tone' => 'cyan',   'url' => 'index.php?v=data_health'],
    ['key' => 'assets',     'title' => '资产总览', 'desc' => '库存价值分析',         'icon' => 'pie',       'tone' => 'pink',   'url' => 'index.php?v=assets',         'feature' => 'feature_asset_stats', 'needCost' => true],
];
$modules = array_values(array_filter($modules, static function (array $m) {
    if (!empty($m['feature']) && !hasFeature($m['feature'])) return false;
    if (!empty($m['needCost']) && !\App\Core\Edition::canViewCost()) return false;
    if (!empty($m['perm']) && !hasPermission($m['perm'])) return false;
    return true;
}));

/** 线框图标（stroke SVG，随 currentColor 继承语义色，避免 emoji 字体差异） */
function wbIcon(string $name): string {
    static $paths = [
        'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'swap'      => '<path d="m17 4 4 4-4 4M21 8H7"/><path d="m7 20-4-4 4-4M3 16h14"/>',
        'import'    => '<path d="M12 3v10m0 0 4-4m-4 4-4-4"/><path d="M4 17v3h16v-3"/>',
        'export'    => '<path d="M12 13V3m0 0 4 4m-4-4L8 7"/><path d="M4 17v3h16v-3"/>',
        'list'      => '<path d="M9 6h12M9 12h12M9 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
        'pie'       => '<path d="M12 3a9 9 0 1 0 9 9h-9V3z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15V3.5z"/>',
        'activity'  => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a3 3 0 0 1 6 0"/><path d="m9 14 2 2 4-4"/>',
        'archive'   => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/>',
        'alert'     => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 9v5"/><path d="M12 17h.01"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
        'coin'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5h4a1.5 1.5 0 0 1 0 3h-3a1.5 1.5 0 0 0 0 3h4"/>',
        'trend'     => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
        'stale'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    $d = $paths[$name] ?? $paths['box'];
    return '<svg class="wb-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** 流水来源标签（读枚举，不写字符串字面量） */
function wbSourceLabel(?string $src): string {
    if ($src === null || $src === '') return '手动';
    try {
        return \App\Core\Enum\StockLogSource::getLabel($src);
    } catch (\Throwable $e) {
        return $src;
    }
}

/** 数量简写：超过 1 万显示「x.x万」，用于统计卡与图表轴 */
function wbNum(int|float $n): string {
    $n = (float)$n;
    return $n >= 10000 ? rtrim(rtrim(number_format($n / 10000, 1), '0'), '.') . '万' : number_format($n);
}

$weekdays = ['周日','周一','周二','周三','周四','周五','周六'];
$hourNow  = (int)date('G');
$greet    = $hourNow < 6 ? '凌晨好' : ($hourNow < 12 ? '早上好' : ($hourNow < 14 ? '中午好' : ($hourNow < 18 ? '下午好' : '晚上好')));

// ── 一言 / 天气（外部服务）──

?>
<div class="main wb">

    <!-- ══ 顶部：欢迎信息 + 快捷操作 ══ -->
    <div class="wb-hero">
        <div class="wb-greeting">
            <h1 class="wb-title"><?=h($user['username'] ?? '')?>，<?=h($greet)?> <span class="wave">👋</span></h1>
            <div class="wb-date">
                <span><?=date('Y年n月j日')?> · <?=h($weekdays[(int)date('w')])?></span>
                <span class="wb-dot-sep"></span>
                <span class="wb-clock"><span id="wbClock"><?=date('H:i')?></span></span>
                <?php if ($todoCount > 0): ?>
                <span class="wb-dot-sep"></span>
                <span class="wb-todo-hint"><?=$todoCount?> 项待处理</span>
                <?php endif; ?>
                <?php 

                ?>
                <?php if (!\App\Core\Http\WeatherResolver::isIntranetHost()): ?>
                <span class="wb-dot-sep"></span>
                <span class="wb-weather is-loading" id="wbWeatherBox"
                      tabindex="0" role="button"
                      aria-label="查看天气详情"
                      onclick="wbWeatherOpen(event)"
                      onmouseenter="wbWeatherHover(true)"
                      onmouseleave="wbWeatherHover(false)"
                      onfocus="wbWeatherHover(true)"
                      onblur="wbWeatherHover(false)"
                      onkeydown="if(event.key==='Enter'){wbWeatherOpen(event)}"
                ><span id="wbWeatherText">···</span></span>
                <?php endif; ?>
            </div>
            <div class="wb-date wb-slogan-line">
                <span class="wb-slogan is-loading" id="wbSloganText">···</span>
            </div>
        </div>
        <div class="wb-quick-actions">
            <?php if (hasPermission('can_edit')): ?>
            <button type="button" class="wb-btn wb-btn-primary" data-nav="index.php?v=parts">
                <?=wbIcon('box')?><span class="wb-btn-text">物料管理</span>
            </button>
            <?php endif; ?>
            <?php if (hasPermission('can_scan')): ?>
            <button type="button" class="wb-btn wb-btn-ghost" data-nav="index.php?v=scan">
                <?=wbIcon('swap')?><span class="wb-btn-text">扫码出入库</span>
            </button>
            <?php endif; ?>
            <?php if (hasPermission('can_import')): ?>
            <button type="button" class="wb-btn wb-btn-ghost" data-nav="index.php?v=import">
                <?=wbIcon('import')?><span class="wb-btn-text">导入文件</span>
            </button>
            <?php endif; ?>
            <button type="button" class="wb-btn wb-btn-ghost" data-nav="index.php?v=stock">
                <?=wbIcon('list')?><span class="wb-btn-text">出入库流水</span>
            </button>
        </div>
    </div>

    <!-- ══ 核心指标卡 ══ -->
    <div class="wb-stats">
        <div class="wb-stat-card wb-stat-blue" data-nav="index.php?v=parts" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('box')?></div>
            <div class="wb-stat-info">
                <div class="wb-stat-label">物料种类</div>
                <div class="wb-stat-value"><?=number_format((int)($stats['total'] ?? 0))?></div>
                <div class="wb-stat-sub">在库 <?=number_format($stockKinds)?> 种</div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <div class="wb-stat-card wb-stat-green" data-nav="index.php?v=parts" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('trend')?></div>
            <div class="wb-stat-info">
                <div class="wb-stat-label">良品总量</div>
                <div class="wb-stat-value"><?=wbNum((int)($stats['total_stock'] ?? 0))?></div>
                <div class="wb-stat-sub">本周 <span class="wb-delta wb-delta-up">+<?=number_format((int)$week['in_qty'])?></span> / <span class="wb-delta wb-delta-down">-<?=number_format((int)$week['out_qty'])?></span></div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <div class="wb-stat-card wb-stat-yellow" data-nav="index.php?v=parts&filter=low" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('alert')?></div>
            <div class="wb-stat-info">
                <div class="wb-stat-label">库存不足</div>
                <div class="wb-stat-value"><?=$lowStockCount?></div>
                <div class="wb-stat-sub">低于安全阈值</div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <div class="wb-stat-card wb-stat-red" data-nav="index.php?v=parts&filter=zero" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('x')?></div>
            <div class="wb-stat-info">
                <div class="wb-stat-label">已用完</div>
                <div class="wb-stat-value"><?=(int)($stats['zero_count'] ?? 0)?></div>
                <div class="wb-stat-sub">影响领料生产</div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <div class="wb-stat-card wb-stat-neutral" data-nav="index.php?v=data_health" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('activity')?></div>
            <div class="wb-stat-info">
                <?php 

                ?>
                <div class="wb-stat-label">库存健康度</div>
                <div class="wb-stat-value"><?=$healthRate?><span class="wb-stat-unit">%</span></div>
                <div class="wb-stat-sub" title="在库种类中，扣除低库存与负库存后的完好率。数据质量页的「数据健康分」是另一套算法（检测项加权扣分），两者不要求相等。">在库完好率 · 非数据质量分</div>
                <div class="wb-meter" role="img" aria-label="库存健康度 <?=$healthRate?>%">
                    <i class="wb-meter-fill <?=$healthRate >= 80 ? 'is-good' : ($healthRate >= 50 ? 'is-warn' : 'is-bad')?>" data-pct="<?=$healthRate?>"></i>
                </div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <?php if ($canViewCost): ?>
        <div class="wb-stat-card wb-stat-purple" data-nav="index.php?v=assets" role="button" tabindex="0">
            <div class="wb-stat-icon"><?=wbIcon('coin')?></div>
            <div class="wb-stat-info">
                <div class="wb-stat-label">库存总价值</div>
                <div class="wb-stat-value">
                    <?php $tv = (float)($stats['total_value'] ?? 0); ?>
                    <?= $tv >= 10000 ? '¥'.wbNum($tv) : '¥'.number_format($tv, 0) ?>
                </div>
                <div class="wb-stat-sub" title="按加权平均单价（4 位小数）计算；与资产总览的「累计入库总金额」可能有分级舍入尾差，不是数据丢失。">库存 × 加权单价</div>
            </div>
            <div class="wb-stat-arrow">›</div>
        </div>
        <?php endif; ?>
    </div>

    
    <div class="wb-section">
        <div class="wb-section-header">
            <h2 class="wb-section-title">功能模块</h2>
            <span class="wb-section-sub">按当前版本能力显示</span>
        </div>
        <div class="wb-modules">
            <?php foreach ($modules as $m): ?>
            <div class="wb-module-card wb-mi-<?=h($m['tone'])?>" data-nav="<?=h($m['url'])?>" role="button" tabindex="0">
                <div class="wb-module-icon"><?=wbIcon($m['icon'])?></div>
                <div class="wb-module-info">
                    <div class="wb-module-title"><?=h($m['title'])?></div>
                    <div class="wb-module-desc"><?=h($m['desc'])?></div>
                </div>
                <div class="wb-module-arrow">›</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ 趋势 + 库存结构 ══ -->
    <div class="wb-grid-2">
        <div class="wb-card wb-card-chart">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('trend')?> 近 7 天出入库趋势</h3>
                <div class="wb-card-meta">
                    <span class="wb-legend"><i class="wb-legend-dot is-in"></i>入库 <?=wbNum($trendInTotal)?></span>
                    <span class="wb-legend"><i class="wb-legend-dot is-out"></i>出库 <?=wbNum($trendOutTotal)?></span>
                </div>
            </div>
            <div class="wb-card-body">
                <div class="wb-chart-box">
                    <canvas id="wbTrendChart" aria-label="近 7 天出入库数量趋势图" role="img"></canvas>
                    <div class="wb-chart-fallback" id="wbTrendFallback" hidden>暂无趋势数据</div>
                </div>
            </div>
        </div>

        <div class="wb-card wb-card-chart">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('pie')?> 库存结构（按一级分类）</h3>
                <div class="wb-card-meta">
                    <?php if (!empty($catDist)): ?>
                    <?php // 图表形态切换：横向条形 / 竖直柱形。
                    // **不提供环形图** —— 分类数少时环形退化成一个纯色大圆（零信息量），
                    // 分类多时图例与扇区又互相遮挡，而这张图要回答的是「哪几类占多少」。

                    ?>
                    <div class="wb-seg" id="wbCatSeg" role="group" aria-label="库存结构图表类型">
                        <button type="button" class="wb-seg-btn" data-cat-type="hbar" title="横向条形图（分类名易读）">条形</button>
                        <button type="button" class="wb-seg-btn is-on" data-cat-type="vbar" title="竖直柱形图（对比柱高）">柱形</button>
                    </div>
                    <?php endif; ?>
                    <a href="index.php?v=parts" class="wb-card-more" data-nav="index.php?v=parts">分类管理 ›</a>
                </div>
            </div>
            <div class="wb-card-body">
                <?php if (empty($catDist)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon">📦</div>
                        <div class="wb-empty-title">暂无库存数据</div>
                        <div class="wb-empty-text">导入物料后即可看到分类库存分布</div>
                    </div>
                <?php else: ?>
                    <div class="wb-chart-box">
                        <canvas id="wbCatChart" role="img"></canvas>
                    </div>
                    <div class="wb-cat-legend">
                        <?php foreach ($catDist as $i => $c): ?>
                        <div class="wb-cat-item">
                            <i class="wb-cat-dot wb-cat-<?=($i % 6) + 1?>"></i>
                            <span class="wb-cat-name" title="<?=h((string)$c['name'])?>"><?=h((string)$c['name'])?></span>
                            <span class="wb-cat-val"><?=wbNum((int)$c['qty'])?></span>
                            <span class="wb-cat-pct"><?=$catDistQtyTotal > 0 ? round((int)$c['qty'] / $catDistQtyTotal * 100) : 0?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ 预警 + 待处理 ══ -->
    <div class="wb-grid-2">
        <!-- 低库存预警 -->
        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('alert')?> 低库存预警<?php if ($lowStockCount > 0): ?><span class="wb-badge wb-badge-orange"><?=$lowStockCount?></span><?php endif; ?></h3>
                <a href="index.php?v=parts&filter=low" class="wb-card-more" data-nav="index.php?v=parts&filter=low">全部 ›</a>
            </div>
            <div class="wb-card-body">
                <?php if (empty($lowStockParts)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon wb-empty-ok"><?=wbIcon('check')?></div>
                        <div class="wb-empty-title">库存水位健康</div>
                        <div class="wb-empty-text">暂无低于安全阈值的物料</div>
                    </div>
                <?php else: ?>
                    <div class="wb-list">
                        <?php foreach ($lowStockParts as $p):
                            $cur = (int)$p['stock'];
                            $thr = max(1, (int)$p['eff_threshold']);
                            $pct = min(100, (int)round($cur / $thr * 100));
                        ?>
                            <div class="wb-list-item" data-nav="index.php?v=parts&q=<?=h($p['model'] ?: $p['platform_part_no'])?>" role="button" tabindex="0">
                                <div class="wb-list-main">
                                    <div class="wb-list-name"><?=h($p['model'] ?: $p['platform_part_no'])?></div>
                                    <div class="wb-list-desc">
                                        <?php if (!empty($p['product_name'])): ?>
                                            <?=h(mb_substr((string)$p['product_name'], 0, 24))?>
                                        <?php else: ?>
                                            库存 <?=$cur?> / 阈值 <?=$thr?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($p['product_name'])): ?>
                                    <div class="wb-meter wb-meter-sm" role="img" aria-label="库存 <?=$cur?> / 阈值 <?=$thr?>">
                                        <i class="wb-meter-fill <?=$pct <= 30 ? 'is-bad' : 'is-warn'?>" data-pct="<?=$pct?>"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="wb-list-value wb-low"><?=$cur?> / <?=$thr?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 待处理业务 -->
        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('clipboard')?> 待处理业务<?php if ($todoCount > 0): ?><span class="wb-badge wb-badge-red"><?=$todoCount?></span><?php endif; ?></h3>
                <span class="wb-card-sub">按紧急度排序<?php if (!empty($dqAlertCount)): ?> ·含数据质量<?= (int)$dqAlertCount?> 项<?php endif; ?></span>
            </div>
            <div class="wb-card-body">
                <?php if ($todoCount === 0): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon wb-empty-ok"><?=wbIcon('check')?></div>
                        <div class="wb-empty-title">暂无待处理事项</div>
                        <div class="wb-empty-text">库存预警与流水复核均已完成</div>
                    </div>
                <?php else: ?>
                    <div class="wb-list">
                        <?php if ($lowStockCount > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=low" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-orange"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">低库存物料待补货</div>
                                <div class="wb-list-desc">库存低于安全阈值，建议及时补货</div>
                            </div>
                            <span class="wb-badge wb-badge-orange"><?=$lowStockCount?> 项</span>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)($stats['zero_count'] ?? 0) > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=zero" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-red"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">已用完物料待补货</div>
                                <div class="wb-list-desc">库存为 0，影响领料与生产</div>
                            </div>
                            <span class="wb-badge wb-badge-red"><?=(int)$stats['zero_count']?> 项</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($undoPending > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=stock&tab=undo" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-blue"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">撤销流水待复核</div>
                                <div class="wb-list-desc">近 7 天存在撤销扫码操作记录</div>
                            </div>
                            <span class="wb-badge wb-badge-blue"><?=$undoPending?> 条</span>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)($anomaly['damaged'] ?? 0) > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=damaged" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-purple"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">不良品待处置</div>
                                <div class="wb-list-desc">存在报废数量，建议检修或报废处理</div>
                            </div>
                            <span class="wb-badge wb-badge-purple"><?=(int)$anomaly['damaged']?> 项</span>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)($anomaly['negative'] ?? 0) > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=negative" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-red"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">负库存数据异常</div>
                                <div class="wb-list-desc">库存数量为负，建议盘点修正</div>
                            </div>
                            <span class="wb-badge wb-badge-red"><?=(int)$anomaly['negative']?> 项</span>
                        </div>
                        <?php endif; ?>
                        <?php
                        // 数据质量类严重项（读 system 模块快照，无快照则不渲染）

                        foreach ((array)($dqAlerts ?? []) as $dq):
                        ?>
                        <div class="wb-list-item" data-nav="<?= h($dq['url']) ?>" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-red"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name"><?= h($dq['label']) ?></div>
                                <div class="wb-list-desc"><?= h($dq['hint']) ?></div>
                            </div>
                            <span class="wb-badge wb-badge-red"><?= (int)$dq['count']?> 项</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="wb-card-ops">
                    <?php if (hasPermission('can_scan')): ?>
                    <button type="button" class="wb-mini-btn" data-nav="index.php?v=scan"><?=wbIcon('swap')?> 扫码出入库</button>
                    <?php endif; ?>
                    <?php if (hasFeature('checklist') && hasPermission('can_edit')): ?>
                    <button type="button" class="wb-mini-btn" data-nav="index.php?v=checklist"><?=wbIcon('clipboard')?> 发起盘点</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 预警补充：呆滞 + 库存异常 ══ -->
    <div class="wb-grid-2">
        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('archive')?> 呆滞物料<?php if ($staleCount > 0): ?><span class="wb-badge wb-badge-orange"><?=$staleCount?></span><?php endif; ?></h3>
                <a href="index.php?v=stale" class="wb-card-more" data-nav="index.php?v=stale">专区 ›</a>
            </div>
            <div class="wb-card-body">
                <?php if (empty($staleTop5)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon wb-empty-ok"><?=wbIcon('check')?></div>
                        <div class="wb-empty-title">无呆滞物料</div>
                        <div class="wb-empty-text">近 <?=(int)($warnings['stale_days'] ?? 90)?> 天内均有动销</div>
                    </div>
                <?php else: ?>
                    <div class="wb-card-subbar">阈值 <?=(int)($warnings['stale_days'] ?? 90)?> 天未动销</div>
                    <div class="wb-list">
                        <?php foreach ($staleTop5 as $p): ?>
                            <div class="wb-list-item" data-nav="index.php?v=stale" role="button" tabindex="0">
                                <div class="wb-list-main">
                                    <div class="wb-list-name"><?=h($p['model'] ?: $p['platform_part_no'])?></div>
                                    <div class="wb-list-desc">
                                        <?php if (!empty($p['last_move_time'])): ?>
                                            最后动销 <?=h((string)$p['last_move_time'])?>
                                        <?php elseif (!empty($p['location'])): ?>
                                            库位 <?=h(mb_substr((string)$p['location'], 0, 24))?>
                                        <?php else: ?>
                                            长期无出入库记录
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="wb-list-value wb-low"><?= number_format((int)$p['stock']) ?> 件</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('activity')?> 库存异常</h3>
                <a href="index.php?v=data_health" class="wb-card-more" data-nav="index.php?v=data_health">数据质量 ›</a>
            </div>
            <div class="wb-card-body">
                <?php if ((int)($anomaly['total'] ?? 0) === 0): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon wb-empty-ok"><?=wbIcon('check')?></div>
                        <div class="wb-empty-title">库存状态正常</div>
                        <div class="wb-empty-text">未发现不良品 / 负库存等异常</div>
                    </div>
                <?php else: ?>
                    <div class="wb-list">
                        <?php if ((int)$anomaly['damaged'] > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=damaged" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-purple"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">不良品库存</div>
                                <div class="wb-list-desc">存在报废数量（damaged&gt;0），建议检修或报废</div>
                            </div>
                            <div class="wb-list-value wb-low"><?=(int)$anomaly['damaged']?> 件</div>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)$anomaly['zero_with_threshold'] > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=zero" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-red"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">缺货待补</div>
                                <div class="wb-list-desc">库存为 0 但设置了安全阈值，处于断料状态</div>
                            </div>
                            <div class="wb-list-value wb-low"><?=(int)$anomaly['zero_with_threshold']?> 项</div>
                        </div>
                        <?php endif; ?>
                        <?php if ((int)$anomaly['negative'] > 0): ?>
                        <div class="wb-list-item" data-nav="index.php?v=parts&filter=negative" role="button" tabindex="0">
                            <span class="wb-dot wb-dot-red"></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name">负库存异常</div>
                                <div class="wb-list-desc">库存数量为负，数据异常，建议盘点修正</div>
                            </div>
                            <div class="wb-list-value wb-low"><?=(int)$anomaly['negative']?> 项</div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ 动态：最近出入库 / 最近添加 / 最近项目 ══ -->
    <div class="wb-grid-3">
        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('swap')?> 最近出入库</h3>
                <a href="index.php?v=stock" class="wb-card-more" data-nav="index.php?v=stock">流水中心 ›</a>
            </div>
            <div class="wb-card-body">
                <?php if (empty($recentLogs)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon">🔄</div>
                        <div class="wb-empty-title">暂无出入库记录</div>
                        <div class="wb-empty-text">扫码或导入后这里会显示最新动态</div>
                    </div>
                <?php else: ?>
                    <ul class="wb-feed">
                        <?php foreach ($recentLogs as $log):
                            $qty = (int)$log['qty_change'];
                            $in  = $qty > 0;
                        ?>
                        <li class="wb-feed-item" data-nav="index.php?v=stock" role="button" tabindex="0">
                            <span class="wb-feed-badge <?=$in ? 'is-in' : 'is-out'?>"><?=$in ? '入' : '出'?></span>
                            <div class="wb-list-main">
                                <div class="wb-list-name"><?=h($log['model'] ?: $log['platform_part_no'] ?: ('#'.(int)$log['id']))?></div>
                                <div class="wb-list-desc"><?=h(wbSourceLabel($log['source_type'] ?? null))?> · <?=date('m-d H:i', strtotime((string)$log['create_time']))?></div>
                            </div>
                            <div class="wb-list-value <?=$in ? 'wb-in' : 'wb-out'?>"><?=$in ? '+' : ''?><?=$qty?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('box')?> 最近添加</h3>
                <a href="index.php?v=parts&sort=newest" class="wb-card-more" data-nav="index.php?v=parts&sort=newest">全部 ›</a>
            </div>
            <div class="wb-card-body">
                <?php if (empty($recentParts)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon">📦</div>
                        <div class="wb-empty-title">暂无物料数据</div>
                        <div class="wb-empty-text">从导入中心批量导入对账单开始建账</div>
                        <button type="button" class="wb-empty-btn" data-nav="index.php?v=import">去导入</button>
                    </div>
                <?php else: ?>
                    <ul class="wb-feed">
                        <?php foreach ($recentParts as $p): ?>
                        <li class="wb-feed-item" data-nav="index.php?v=parts&q=<?=h($p['model'] ?: $p['platform_part_no'])?>" role="button" tabindex="0">
                            <div class="wb-list-main">
                                <div class="wb-list-name"><?=h($p['model'] ?: $p['platform_part_no'])?></div>
                                <div class="wb-list-desc">
                                    <?=h(mb_substr((string)($p['product_name'] ?: '未填商品描述'), 0, 22))?>
                                    · <?=date('m-d', strtotime((string)$p['create_time']))?>
                                </div>
                            </div>
                            <div class="wb-list-value"><?=number_format((int)$p['stock'])?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="wb-card">
            <div class="wb-card-header">
                <h3 class="wb-card-title"><?=wbIcon('folder')?> 最近项目</h3>
                <?php if (hasFeature('bom_basic')): ?>
                <a href="index.php?v=bom" class="wb-card-more" data-nav="index.php?v=bom">全部 ›</a>
                <?php endif; ?>
            </div>
            <div class="wb-card-body">
                <?php if (empty($bomProjects)): ?>
                    <div class="wb-empty">
                        <div class="wb-empty-icon">📁</div>
                        <div class="wb-empty-title">暂无 BOM 项目</div>
                        <div class="wb-empty-text">创建项目后可批量核算与出库</div>
                        <?php if (hasFeature('bom_basic')): ?>
                        <button type="button" class="wb-empty-btn" data-nav="index.php?v=bom">去创建项目</button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <ul class="wb-feed">
                        <?php foreach ($bomProjects as $proj): ?>
                        <li class="wb-feed-item" data-nav="index.php?v=bom&project_id=<?=(int)$proj['id']?>" role="button" tabindex="0">
                            <div class="wb-list-main">
                                <div class="wb-list-name"><?=h($proj['name'])?></div>
                                <div class="wb-list-desc"><?=h(mb_substr((string)($proj['description'] ?: '未填写说明'), 0, 24))?></div>
                            </div>
                            <div class="wb-list-value"><?=date('m-d', strtotime((string)$proj['created_at']))?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
window.DashboardPageConfig = <?= json_encode([
    'notices' => array_values(array_filter([
        $welcomeFlash !== '' ? ['content' => $welcomeFlash] : null,
        $welcomeNotices['global'], $welcomeNotices['sub']
    ])),
    'trend' => [
        'labels' => $trend['labels'],
        'in'     => $trend['in'],
        'out'    => $trend['out'],
    ],
    'catDist' => array_map(static fn($r) => [
        'name'  => (string)$r['name'],
        'qty'   => (int)$r['qty'],
        'kinds' => (int)$r['kinds'],
    ], $catDist),
    'brief' => [
        'inCount'  => (int)$week['in_count'],
        'outCount' => (int)$week['out_count'],
        'net'      => $weekNet,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php $__chartjs = __DIR__ . '/../../../third_party/js/chart.umd.js'; ?>

<script src="third_party/js/chart.umd.js?v=<?= APP_VERSION . '.' . (is_file($__chartjs) ? filemtime($__chartjs) : '0') ?>" defer></script>
<script src="modules/inventory/Assets/js/dashboard.js?v=<?= APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/js/dashboard.js') ?>" defer></script>