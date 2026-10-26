<?php
declare(strict_types=1);
/** mobile/Views/about.php — 移动端「关于」（二级页） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$dbVersion = '—';
try {
    $dbVersion = (new \App\Core\Migration\MigrationEngine(getDB()))->getCurrentVersion();
} catch (\Throwable $e) {
    error_log('mobile about: read db version failed: ' . $e->getMessage());
}

$rows = [
    ['站点名称',   (string)getSetting('site_title', 'LCSC 硬件资产管理')],
    ['版本形态',   \App\Core\Edition::displayName()],
    ['当前版本号', APP_VERSION],
    ['数据库版本', $dbVersion],
];
?>
<div class="m-card m-kv">
<?php foreach ($rows as [$kvKey, $kvVal]): ?>
    <div class="m-kv-row">
        <span class="m-kv-k"><?= h($kvKey) ?></span>
        <span class="m-kv-v"><?= h($kvVal) ?></span>
    </div>
<?php endforeach; ?>
</div>

<div class="m-card m-note-card">
    <div class="m-note-text">移动端与电脑端是两套独立界面，共用同一份数据。移动端专注日常高频操作（扫码出入库 / 查询物料 / 我的），完整功能请在电脑端使用。</div>
</div>
