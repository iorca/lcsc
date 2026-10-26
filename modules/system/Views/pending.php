<?php
declare(strict_types=1);
/** 待处理工作台页面 — 异常物料统一管理 */

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

use App\Core\Edition;

$activePage = 'pending';
$pageTitle = '待处理工作台';
$pageDesc = '自动检测异常物料，统一归集处理';

use Modules\System\Service\SystemReadService;

// 获取统计摘要（查询在 SystemReadService）
$summary = (new SystemReadService($db, $uid, $dataUid))->getPendingSummary();

$reasonLabels = [
    'param_missing'   => '参数缺失',
    'negative_stock'  => '库存为负',
    'duplicate'       => '重复物料',
    'no_flow'         => '长期无流动',
    'alt_unconfirmed' => '替代料待确认',
];

$useLayoutFoot = true;

\App\Core\AssetManager::addCss('modules/system/Assets/css/pending.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>

<div class="page-header">
    <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
    <p class="page-desc"><?= htmlspecialchars($pageDesc) ?></p>
</div>

<!-- 统计卡片 -->
<div class="stat-grid pd-grid">
    <?php foreach ($reasonLabels as $key => $label): ?>
    <div class="stat-card pd-card" data-reason="<?= $key ?>">
        <div class="pd-card-num"><?= $summary['by_reason'][$key] ?? 0 ?></div>
        <div class="pd-card-label"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 操作栏 -->
<div class="pd-actions">
    <button id="btn-scan" class="btn btn-primary">扫描异常物料</button>
    <button id="btn-fix" class="btn btn-success" disabled>批量修正</button>
    <button id="btn-ignore" class="btn btn-secondary" disabled>批量忽略</button>
    <select id="filter-reason" class="pd-select">
        <option value="">全部类型</option>
        <?php foreach ($reasonLabels as $key => $label): ?>
        <option value="<?= $key ?>"><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <span id="scan-result" class="pd-scan-result"></span>
</div>

<!-- 待处理列表 -->
<div class="table-wrapper">
    <table class="data-table" id="pending-table">
        <thead>
            <tr>
                <th class="pd-th-check"><input type="checkbox" id="check-all"></th>
                <th>物料型号</th>
                <th>品牌</th>
                <th>封装</th>
                <th>库存</th>
                <th>异常类型</th>
                <th>详情</th>
                <th>发现时间</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody id="pending-body">
            <tr><td colspan="9" class="pd-td-empty">暂无待处理物料，点击"扫描异常物料"开始检测</td></tr>
        </tbody>
    </table>
</div>

<div id="pending-pagination" class="pd-pager"></div>

<script>
window.PendingPageConfig = {
    apiBase: 'api.php?action=pending.',
    reasonLabels: <?= json_encode($reasonLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    csrf: '<?= csrf() ?>'
};
</script>
<script src="modules/system/Assets/js/pending.js?v=<?= APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/js/pending.js') ?>"></script>

<?php require __DIR__ . '/../../../ui/layouts/foot.php'; ?>
