<?php
declare(strict_types=1);
/** 数据健康中心页面 */

use Modules\System\Service\DataHealthService;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

// ── 通过 Service 取概览（缓存优先）──
$healthSvc = new DataHealthService($db, $uid, $dataUid);
try {
    $summary = $healthSvc->summary();
} catch (\Throwable $e) {
    error_log('data_health summary: ' . $e->getMessage());
    $summary = [
            'total' => 0, 'score' => 100, 'level' => 'ok', 'checks' => [], 'groups' => [],
            'by_group' => [], 'issue_total' => 0, 'todo_count' => 0, 'ref_count' => 0,
            'must_checks' => 0, 'warn_checks' => 0, 'ref_checks' => 0,
            'check_total' => 0, 'large_scale' => false,
            'can_cost' => false, 'can_edit' => false, 'can_cost_fix' => false,
            'cached' => false, 'generated_at' => time(), 'error' => $e->getMessage(),
        ];
}

$pageTitle  = '数据质量';
$activePage = 'data_health';

// ── 页面配置注入（供外置 JS 读取）──
$healthConfig = [
    'total'        => (int)$summary['total'],
    'score'        => (int)$summary['score'],
    'level'        => (string)$summary['level'],
    'canEdit'      => !empty($summary['can_edit']),
    'canCostFix'   => !empty($summary['can_cost_fix']),
    'largeScale'   => !empty($summary['large_scale']),
    'groups'       => $summary['groups'] ?? [],
    'generatedAt'  => (int)($summary['generated_at'] ?? time()),
];

\App\Core\AssetManager::addCss('modules/system/Assets/css/data_health.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>


<div class="main">
    <div class="page-header">
        <div class="dh-head">
            <h2>数据健康中心</h2>
            <div class="page-desc">帮你确认库里的数据靠不靠谱：信息填全没有、库存数字对不对、分类与库位是否有效、BOM 与替代料的引用还在不在。每项都能点开看明细，能修的一键修。</div>
        </div>
        <div class="toolbar dh-toolbar">
            <?php
                  ?>
            <label class="dh-ignored-toggle" title="被忽略的「仅供参考」项默认隐藏；勾选后可查看并恢复提醒">
                <input type="checkbox" id="dhShowIgnored"> 显示已忽略（<?= count($summary['ignored_keys'] ?? []) ?>）
            </label>
            <?php if (!empty($summary['can_edit'])): ?>
            <button type="button" class="btn btn-primary btn-sm" id="btnRefresh">重新检测</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ((int)$summary['total'] === 0): ?>
    <!-- 无物料：引导导入 -->
    <div class="card card-pad">
        <div class="empty-state">
            <div class="icon">📊</div>
            <div class="es-title">暂无物料数据</div>
            <div class="es-desc">导入第一份文件，开始建立你的元器件库</div>
            <div class="es-actions">
                <a href="index.php?v=import" class="btn btn-primary">导入文件</a>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- 健康分总览 -->
    <div class="card card-pad dh-score-card">
        <div class="dh-score-row">
            <div class="dh-score-left">
                <div class="dh-score-label">数据健康分</div>
                <?php // 与工作台的「库存健康度」是两套独立算法：本分是检测项加权扣分，
                // 工作台那张只算在库种类扣除低库存/负库存后的完好率，两者不要求相等。
                // 在此点明一次，免得两边数字不同被当成「算错了」。
                ?>
                <div class="dh-score-hint">只统计「需要马上处理」与「建议处理」的问题；仅供参考的项目不扣分 · 与工作台「库存健康度」口径不同</div>
                <div class="dh-score-value">
                    <?= (int)$summary['score'] ?><span class="dh-score-total"> / 100</span>
                </div>
                <span class="badge dh-level-badge dh-level-<?= h((string)$summary['level']) ?>">
                    <?php
                    $lvIcon = ['ok' => '🟢', 'warn' => '🟡', 'bad' => '🔴'][(string)$summary['level']] ?? '⚪';
                    echo $lvIcon;
                    echo ['ok' => '状态良好', 'warn' => '存在待处理项', 'bad' => '存在严重问题'][(string)$summary['level']] ?? '未知';
                    ?>
                </span>
                <?php // 三级分解：让「分数怎么来的」一眼可读，不用点开每张卡才知道?>
                <div class="dh-score-breakdown">
                    <span class="dh-bd dh-bd-must">需要马上处理 <?= (int)$summary['must_checks'] ?> 项</span>
                    <span class="dh-bd dh-bd-warn">建议处理 <?= (int)$summary['warn_checks'] ?> 项</span>
                    <span class="dh-bd dh-bd-ref">仅供参考 <?= (int)$summary['ref_checks'] ?> 项</span>
                </div>
            </div>
            <div class="dh-score-right">
                <div class="dh-stat-row">
                    <div class="dh-stat">
                        <div class="dh-stat-num"><?= number_format((int)$summary['total']) ?></div>
                        <div class="dh-stat-label">物料总数</div>
                    </div>
                    <div class="dh-stat">
                        <div class="dh-stat-num"><?= (int)$summary['check_total'] ?></div>
                        <div class="dh-stat-label">检测项</div>
                    </div>
                    <div class="dh-stat">
                        <div class="dh-stat-num"><?= number_format((int)$summary['todo_count']) ?></div>
                        <div class="dh-stat-label">需处理条数</div>
                    </div>
                    <div class="dh-stat">
                        <div class="dh-stat-num"><?= number_format((int)$summary['ref_count']) ?></div>
                        <div class="dh-stat-label">仅供参考</div>
                    </div>
                </div>
                <div class="dh-cache-meta" id="cacheMeta">
                    检测于 <?= date('Y-m-d H:i', (int)($summary['generated_at'] ?? time())) ?>
                    <?php if (!empty($summary['cached'])): ?>
                        <span class="badge badge-green">缓存</span>
                    <?php else: ?>
                        <span class="badge badge-blue">本次实时</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!empty($summary['large_scale'])): ?>
        <div class="dh-notice">
            物料数量较大（&gt; <?= DataHealthService::SCAN_THRESHOLD ?>），检测已按分组执行；
            页面优先读取缓存，过期后或点击「重新检测」才会重新扫描。
        </div>
        <?php endif; ?>
    </div>

    <!-- 检测项分组（可折叠） -->
    <?php foreach (($summary['by_group'] ?? []) as $gKey => $gStat): ?>
        <?php
        $gMeta = $summary['groups'][$gKey] ?? ['label' => $gKey, 'icon' => '📁', 'desc' => ''];
        $gChecks = array_values(array_filter($summary['checks'] ?? [], fn($c) => $c['group'] === $gKey));
        $gHasIssue = ($gStat['issue_count'] ?? 0) > 0;
        // 默认：有异常的分组展开，全通过的分组折叠
        $gOpen = $gHasIssue;
        ?>
        <div class="card card-pad dh-group <?= $gOpen ? 'is-open' : '' ?>" data-group="<?= h($gKey) ?>">
            <button class="dh-group-head" type="button" data-toggle-group="<?= h($gKey) ?>">
                <span class="dh-group-caret">▸</span>
                <span class="dh-group-icon"><?= $gMeta['icon'] ?? '📁' ?></span>
                <span class="dh-group-name"><?= h((string)($gMeta['label'] ?? $gKey)) ?></span>
                <span class="dh-group-desc"><?= h((string)($gMeta['desc'] ?? '')) ?></span>
                <span class="dh-group-badges">
                    <?php if (($gStat['critical'] ?? 0) > 0): ?>
                        <span class="badge badge-red">需要马上处理 <?= (int)$gStat['critical'] ?></span>
                    <?php endif; ?>
                    <?php if (($gStat['warn'] ?? 0) > 0): ?>
                        <span class="badge badge-yellow">建议处理 <?= (int)$gStat['warn'] ?></span>
                    <?php endif; ?>
                    <?php if (($gStat['info'] ?? 0) > 0): ?>
                        <span class="badge badge-blue">仅供参考 <?= (int)$gStat['info'] ?></span>
                    <?php endif; ?>
                    <?php if (!$gHasIssue): ?>
                        <span class="badge badge-green">全部通过</span>
                    <?php endif; ?>
                </span>
            </button>
            <div class="dh-group-body">
                <div class="dh-check-grid">
                    <?php foreach ($gChecks as $c): ?>
                    <div class="dh-check dh-sev-<?= h($c['severity']) ?> <?= $c['count'] > 0 ? '' : 'is-clean' ?> <?= !empty($c['ignored']) ? 'is-ignored' : '' ?>"
                         data-key="<?= h($c['key']) ?>"
                         data-fixable="<?= !empty($c['fixable']) ? '1' : '0' ?>"
                         data-fix="<?= h((string)($c['fix'] ?? '')) ?>"
                         data-label="<?= h($c['label']) ?>"
                         data-hint="<?= h((string)($c['hint'] ?? '')) ?>"
                         data-severity="<?= h($c['severity']) ?>"
                         data-ignored="<?= !empty($c['ignored']) ? '1' : '0' ?>"
                         data-count="<?= (int)$c['count'] ?>">
                        <div class="dh-check-top">
                            <span class="dh-check-icon"><?= $c['icon'] ?? '•' ?></span>
                            <span class="dh-check-label"><?= h($c['label']) ?></span>
                            <span class="badge dh-sev-badge"><?= ['critical' => '需要马上处理', 'warn' => '建议处理', 'info' => '仅供参考'][$c['severity']] ?? '' ?></span>
                        </div>
                        <div class="dh-check-num">
                            <?= $c['count'] > 0 ? number_format((int)$c['count']) : '—' ?>
                            <?php if ($c['count'] > 0): ?>
                                <span class="dh-check-pct"><?= number_format((float)$c['percent'], 1) ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="dh-check-hint"><?= h((string)($c['hint'] ?? '')) ?></div>
                        <div class="dh-check-actions">
                            <?php if ((int)$c['count'] > 0): ?>
                            <button class="btn btn-ghost btn-xs" data-action="detail">查看明细</button>
                            <?php endif; ?>
                            <?php if (!empty($c['fixable']) && (int)$c['count'] > 0 && !empty($summary['can_edit'])): ?>
                            <button class="btn btn-primary btn-xs" data-action="fix">一键修复</button>
                            <?php endif; ?>
                            <?php // 仅「仅供参考」级可忽略：critical/warn 的问题必须处理而非隐藏?>
                            <?php if ($c['severity'] === 'info' && (int)$c['count'] > 0): ?>
                            <button class="btn btn-ghost btn-xs" data-action="ignore" title="<?= !empty($c['ignored']) ? '恢复该检测项的提醒' : '忽略后下次检测前不再提醒，可随时恢复' ?>"><?= !empty($c['ignored']) ? '恢复提醒' : '忽略' ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($gChecks)): ?>
                    <div class="empty-state dh-group-empty">
                        <div class="icon">🔒</div>
                        <div class="es-desc">当前版本不提供该组检测</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- 明细下钻面板 -->
    <div class="card card-pad dh-detail is-hidden" id="detailPanel">
        <div class="dh-detail-head">
            <div>
                <h3 class="dh-detail-title" id="detailTitle">明细</h3>
                <div class="dh-detail-hint" id="detailHint"></div>
            </div>
            <div class="toolbar">
                <button class="btn btn-ghost btn-xs is-hidden" id="detailFixBtn">一键修复此项</button>
                <button class="btn btn-ghost btn-xs" id="detailClose">关闭</button>
            </div>
        </div>
        <div class="table-wrap dh-detail-table">
            <table class="data-table" id="detailTable">
                <thead><tr id="detailHead"></tr></thead>
                <tbody id="detailBody"></tbody>
            </table>
        </div>
        <div class="pagination" id="detailPager"></div>
    </div>

    <?php endif; ?>
</div>

<script>
window.HealthPageConfig = <?= json_encode($healthConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="modules/system/Assets/js/data_health.js?v=<?= APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/js/data_health.js') ?>"></script>
