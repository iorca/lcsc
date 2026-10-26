<?php
declare(strict_types=1);
/** 独立数据库迁移页（P1）—— **独立整页** */
use App\Core\Migration\MigrationEngine;
use App\Core\Migration\MigrationHistory;

require_once __DIR__ . '/../../../config.php';

$user      = requireLogin();
$isPrimary = isPrimaryAdmin();

sendSecurityHeaders();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$siteTitle    = getSetting('site_title', 'LCSC 硬件资产管理');
$themeDefault = getSetting('theme_default', 'light');

$currentVersion = '-';
$targetVersion  = '-';
$pending        = false;
$stalled        = false;
$hasPending     = false;   // 上次迁移是否留下「进行中」记录（进程中断）
$dry            = ['path' => [], 'scan' => [], 'diff' => [], 'warnings' => []];
$history        = [];

if ($isPrimary) {
    $db     = getDB();
    $engine = new MigrationEngine($db);
    $dry    = $engine->dryRun();

    $pending        = ($dry['needs_migration'] === true) && ($dry['path'] !== []);

    $stalled        = ($dry['needs_migration'] === true) && ($dry['path'] === []);
    $history        = (new MigrationHistory($db))->recent(50);
    $hasPending     = (new MigrationHistory($db))->hasPending();
    $currentVersion = $engine->getCurrentVersion();
    $targetVersion  = $engine->getTargetVersionString();
}

// 数据规模字段 → 中文标签（未列出的键不展示）
$scanLabels = [
    'parts_total'              => '物料总数',
    'parts_with_stock'         => '有库存物料',
    'parts_stock_pending_sync' => '待建库存行（parts → stock）',
    'stock_total'              => 'stock 库存行',
    'stock_log_total'          => '库存流水',
    'bom_projects_total'       => 'BOM 项目',
    'bom_items_total'          => 'BOM 条目',
    'attachments_total'        => '附件',
    'price_history_total'      => '价格历史',
    'part_alternatives_total'  => '替代料关联',
];

$statusBadge = static fn(string $s): string => match ($s) {
    'success' => 'badge-green',
    'failed'  => 'badge-red',
    'skipped' => 'badge-yellow',
    default   => 'badge-blue',
};

$statusText = static fn(string $s): string => match ($s) {
    'success' => '成功',
    'failed'  => '失败',
    'skipped' => '跳过',
    'pending' => '进行中',
    default   => $s,
};

$historySummary = static function (string $resultJson): string {
    $msgs = json_decode($resultJson, true);
    return (is_array($msgs) && isset($msgs[0]['message'])) ? (string)$msgs[0]['message'] : '';
};
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>数据库迁移 — <?=h($siteTitle)?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="ui/themes/common.css?v=<?=filemtime(__DIR__ . '/../../../ui/themes/common.css')?>">
<link rel="stylesheet" href="ui/themes/components.css?v=<?=filemtime(__DIR__ . '/../../../ui/themes/components.css')?>">
<link rel="stylesheet" href="modules/system/Assets/css/migration.css?v=<?=APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/css/migration.css')?>">
</head>
<body>
<script>
// 防嵌套：封印由 iframe 内重定向触发时，本页会落在工作室 iframe 里；
if (window.self !== window.top) {
    var base = location.pathname.replace(/\/[^\/]*$/, '/');
    window.top.location.href = base + 'index.php?v=migration';
}
</script>

<div class="main">
<div class="glass-box mig-box">

    <div class="page-header">
        <h2>数据库迁移</h2>
        <div class="page-desc">大版本升级（数据结构 / 数据表达方式变化）的独立执行页。系统检测到「待迁移」时会冻结整个应用并把所有页面引导到本页；执行前自动备份整库，失败不提交版本号，可修复后重试或用备份回退。</div>
    </div>

<?php if (!$isPrimary): ?>


    <div class="flash warn">⚠ 数据库迁移为系统级操作，仅主管理员可执行。</div>
    <div class="mig-hint">若系统提示需要迁移，请联系主管理员完成；迁移完成前，各业务页面将暂停使用。</div>

<?php else: ?>

    <!-- ── 版本状态条 ── -->
    <div class="mig-versions">
        <div class="mig-ver-item">
            <span class="mig-ver-label">当前库版本</span>
            <span class="mono mig-ver-no"><?=h($currentVersion)?></span>
        </div>
        <div class="mig-ver-arrow">→</div>
        <div class="mig-ver-item">
            <span class="mig-ver-label">目标版本</span>
            <span class="mono mig-ver-no"><?=h($targetVersion)?></span>
        </div>
        <span class="badge <?=$pending ? 'badge-yellow' : ($stalled ? 'badge-red' : 'badge-green')?> mig-state">
            <?=$pending ? '待迁移' : ($stalled ? '落后·缺步骤文件' : '已是最新')?>
        </span>
    </div>

<?php if ($stalled): ?>

    <div class="flash warn">
        ⚠ 库版本 <b><?=h($currentVersion)?></b> 落后于代码 <b><?=h($targetVersion)?></b>，
        但<b>找不到对应的迁移步骤文件</b>（<code>app/core/Migration/Versions/</code> 里没有匹配的 <code>V*.php</code>）。
    </div>
    <div class="mig-hint">
        如果你是用「只覆盖变更文件」的方式升级，请确认 <code>app/core/Migration/Versions/</code> 已一并覆盖 ——
        漏掉它会让<b>数据迁移被静默跳过</b>：结构会被 <code>initDB()</code> 对齐、版本号会被改写成新值，
        而数据搬迁从未发生，且<b>不会报错</b>。<br>
        若本次发布只是<b>纯加列</b>（无数据搬迁），则属正常，无需处理。
    </div>
<?php elseif (!$pending): ?>
    <!-- ── 封印态：无待迁移 ── -->
    <div class="flash ok">✓ 当前数据库与代码版本一致，无待迁移步骤。本页在执行迁移时开放，平时无需操作。</div>
<?php else: ?>

    <?php if ($hasPending): ?>
    <div class="flash warn">
        ⚠ 检测到上次迁移未正常结束（历史中有「进行中」记录，可能是刷新 / 断网 / 进程被杀）。
        本次可直接重新执行：迁移步骤全部幂等，已建好的表、已加的列与已迁移的数据会自动跳过，不会重复写入或损坏数据。
    </div>
    <?php endif; ?>

    <!-- ── 预演：迁移路径 ── -->
    <div class="sec-title">迁移路径</div>
    <ol class="mig-path">
        <?php foreach ($dry['path'] as $step): ?>
        <li>
            <span class="mono"><?=h($step['from'])?></span>
            <span class="mig-path-arrow">→</span>
            <span class="mono"><?=h($step['to'])?></span>
            <?php if (empty($step['file'])): ?>
                <span class="badge badge-red">缺少步骤文件</span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>

    <!-- ── 预演：数据规模 ── -->
    <div class="sec-title">数据规模</div>
    <div class="mig-scan">
        <?php foreach ($scanLabels as $k => $label): ?>
            <?php $v = $dry['scan'][$k] ?? null; if ($v === null) continue; ?>
            <div class="mig-scan-item">
                <span class="mig-scan-no"><?=number_format((int)$v)?></span>
                <span class="mig-scan-label"><?=h($label)?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── 预演：结构变更（与库中真实结构比对后的结果）── -->
    <div class="sec-title">结构变更</div>
    <div class="mig-diff">
        <div class="mig-diff-row">
            <span class="mig-diff-key">待新建表</span>
            <span class="mig-diff-val">
                <?php if (($dry['diff']['to_create_tables'] ?? [])): ?>
                    <?php foreach (($dry['diff']['to_create_tables'] ?? []) as $t): ?><code class="mig-code"><?=h($t)?></code><?php endforeach; ?>
                <?php else: ?><span class="mig-muted">无</span><?php endif; ?>
            </span>
        </div>
        <div class="mig-diff-row">
            <span class="mig-diff-key">待新增列</span>
            <span class="mig-diff-val">
                <?php if (($dry['diff']['to_add_columns'] ?? [])): ?>
                    <?php foreach (($dry['diff']['to_add_columns'] ?? []) as $tbl => $cols): ?>
                        <span class="mig-col-group"><code class="mig-code"><?=h($tbl)?></code>：<?=h(implode('、', $cols))?></span>
                    <?php endforeach; ?>
                <?php else: ?><span class="mig-muted">无</span><?php endif; ?>
            </span>
        </div>
        <div class="mig-diff-row">
            <span class="mig-diff-key">待新增索引</span>
            <span class="mig-diff-val">
                <?php if (($dry['diff']['to_add_indexes'] ?? [])): ?>
                    <?php foreach (($dry['diff']['to_add_indexes'] ?? []) as $tbl => $idx): ?>
                        <span class="mig-col-group"><code class="mig-code"><?=h($tbl)?></code>：<?=h(implode('、', $idx))?></span>
                    <?php endforeach; ?>
                <?php else: ?><span class="mig-muted">无</span><?php endif; ?>
            </span>
        </div>
        <div class="mig-diff-row">
            <span class="mig-diff-key">数据转换</span>
            <span class="mig-diff-val">
                <?php if (($dry['diff']['to_migrate_records'] ?? [])): ?>
                    <?php foreach (($dry['diff']['to_migrate_records'] ?? []) as $m): ?>
                        <span class="mig-col-group"><?=h($m['desc'])?><code class="mig-code"><?=h($m['table'])?></code>约 <?=number_format((int)$m['count'])?> 行</span>
                    <?php endforeach; ?>
                <?php else: ?><span class="mig-muted">无</span><?php endif; ?>
            </span>
        </div>
        <?php if (($dry['diff']['existing_tables'] ?? [])): ?>
        <div class="mig-diff-row">
            <span class="mig-diff-key">已存在（幂等跳过）</span>
            <span class="mig-diff-val">
                <?php foreach (($dry['diff']['existing_tables'] ?? []) as $t): ?><code class="mig-code mig-code-dim"><?=h($t)?></code><?php endforeach; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── 预演：告警（复用全局 .flash warn 组件）── -->
    <?php foreach ($dry['warnings'] as $w): ?>
    <div class="flash warn">⚠ <?=h($w)?></div>
    <?php endforeach; ?>

    <!-- ── 执行区 ── -->
    <div class="sec-title">执行</div>
    <div class="mig-run">
        <label class="mig-confirm">
            <input type="checkbox" id="migConfirm">
            <span>我已了解上述变更范围，确认执行本次迁移</span>
        </label>
        <button type="button" class="btn btn-primary" id="migRunBtn" disabled>执行迁移</button>
        <div class="mig-hint">
            执行过程：整库备份 → 逐步迁移 → 一致性校验 → 提交版本号。迁移期间请勿关闭本页或重复提交；
            失败时版本号不会提交，可用 <code>backups/</code> 内的 <code>pre_migration_*.sql</code> 回退，修复后回到本页重试。
        </div>
    </div>

    <div class="mig-result is-hidden" id="migResult"></div>
<?php endif; ?>


    <div class="sec-title">迁移历史</div>
    <?php if (!$history): ?>
        <div class="empty-block">暂无迁移记录。</div>
    <?php else: ?>
        <table class="data-table mig-history">
            <thead>
                <tr><th>开始时间</th><th>版本</th><th>状态</th><th>摘要</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $hRow): ?>
                <tr>
                    <td class="mono"><?=h($hRow['start_time'])?></td>
                    <td class="mono"><?=h($hRow['source_version'])?> → <?=h($hRow['target_version'])?></td>
                    <td><span class="badge <?=$statusBadge((string)$hRow['status'])?>"><?=h($statusText((string)$hRow['status']))?></span></td>
                    <td class="mig-history-summary"><?=h($historySummary((string)$hRow['result']))?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="mig-back"><a href="index.php">← 返回系统</a></div>

<?php endif; ?>
</div>
</div>

<script>
// 页面配置注入（供外置 migration.js 读取）。
window.MigrationPageConfig = <?= json_encode([
    'csrf'    => csrf(),
    'pending' => $pending,
    'current' => $currentVersion,
    'target'  => $targetVersion,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="modules/system/Assets/js/migration.js?v=<?=APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/js/migration.js')?>"></script>
</body></html>
