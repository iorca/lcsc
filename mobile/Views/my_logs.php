<?php
declare(strict_types=1);
/** mobile/Views/my_logs.php — 移动端「我的操作记录」（二级页） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

if (!hasFeature('feature_log_view')) {
    ?>
    <div class="m-card m-placeholder">
        <div class="m-ph-icon"><span class="m-ico m-ico--lock" aria-hidden="true"></span></div>
        <div class="m-ph-title">操作日志未启用</div>
        <div class="m-ph-text">请在电脑端「网站设置 → 功能开关」中开启「操作日志」后再查看。</div>
    </div>
    <?php
    return;
}

require_once __DIR__ . '/../../modules/system/Service/TraceService.php';

$filters = ['actions' => [], 'target_types' => []];
try {
    $filters = (new \TraceManager(getDB(), (int)$user['id'], (int)getDataUserId()))->getFilterOptions();
} catch (\Throwable $e) {
    error_log('mobile/my_logs: getFilterOptions failed: ' . $e->getMessage());
}

$actionLabels = \TraceManager::actionLabels();
$typeLabels   = \TraceManager::targetTypeLabels();
?>

<div class="m-log-filter">
    <select id="mLogAction" aria-label="按动作筛选">
        <option value="">全部动作</option>
        <?php foreach (($filters['actions'] ?? []) as $a): ?>
        <option value="<?= h($a) ?>"><?= h($actionLabels[$a] ?? $a) ?></option>
        <?php endforeach; ?>
    </select>
    <select id="mLogTarget" aria-label="按目标类型筛选">
        <option value="">全部类型</option>
        <?php foreach (($filters['target_types'] ?? []) as $t): ?>
        <option value="<?= h($t) ?>"><?= h($typeLabels[$t] ?? $t) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="m-btn m-btn-sm m-btn--inline m-btn-primary" id="mLogFilterBtn">筛选</button>
</div>

<div class="m-logs" id="mLogList">
    <div class="m-card m-empty" id="mLogEmpty">
        <div class="m-empty-ico"><span class="m-ico m-ico--clock" aria-hidden="true"></span></div>
        <div class="m-empty-text">加载中…</div>
    </div>
</div>
<div class="m-log-pager" id="mLogPager" hidden></div>
