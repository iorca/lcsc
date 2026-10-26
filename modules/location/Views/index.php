<?php
declare(strict_types=1);
/** modules/location/Views/index.php — 库位管理页面（?v=locations） */
require_once __DIR__ . '/../../../config.php';

use App\Core\Edition;
use Modules\Location\Controller\LocationController;
use Modules\Location\Service\LocationService;

if (!hasFeature('location_manage')) {
    header('Location: index.php');
    exit;
}

$user     = requireLogin();
$db       = getDB();
$uid      = (int)$user['id'];
$dataUid  = getDataUserId();
$locationSvc = new LocationService($db, $uid, $dataUid);

// ── CSV 导出（文件流，由 Controller 输出后终止）──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    LocationController::exportCsv($locationSvc);
}

$data = LocationController::prepareViewData($db, $uid, $dataUid);
$tree            = $data['tree'];
$pendingBackfill = $data['pendingBackfill'];
$maxDepth        = $data['maxDepth'];

$canManageLoc    = hasPermission('can_manage_categories');
$canEditLoc      = hasPermission('can_edit');
$canExportLoc    = hasPermission('can_export');

$canBatchLoc     = $canEditLoc && hasPermission('can_batch');

$pageTitle  = '库位管理';
$activePage = 'locations';

$useLayoutFoot = true;
require __DIR__ . '/../../../ui/layouts/head.php';
?>

<div class="main">
<div class="glass-box loc-frame">

    <div class="loc-head page-header">
        <h2>库位管理</h2>
        <div class="toolbar">
            <?php if ($canManageLoc): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="LocTree.addNode(0)">＋ 新增库位</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="LocPage.importCsv(this)">📥 批量导入 CSV</button>
            <?php endif; ?>
            <?php if ($canExportLoc): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="location.href='index.php?v=locations&export=csv'">📤 导出 CSV</button>
            <?php endif; ?>
            <?php if ($canManageLoc && $pendingBackfill > 0): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="locBackfillBtn" onclick="LocPage.backfill()">📦 存量回填（<span id="locPendingCnt"><?= (int)$pendingBackfill ?></span>）</button>
            <?php endif; ?>
        </div>
        <div class="page-desc">仓库 → 库区 → 库位，最深 <?= (int)$maxDepth ?> 级 · 点击左侧节点查看详情</div>
    </div>


    <div class="loc-search">
        <select class="loc-search-scope" id="locSearchScope" onchange="LocSearch.setScope(this.value)" title="选择搜索范围">
            <option value="find">寻物</option>
            <option value="tree">库位</option>
            <option value="parts">当前物料</option>
        </select>
        <div class="search-box loc-search-input">
            <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" id="locSearchInput" autocomplete="off"
                   placeholder="输入物料编号 / 型号，或用扫码枪扫物料码，回车定位库位"
                   oninput="LocSearch.onInput(this.value)"
                   onkeydown="if(event.key==='Enter'){LocSearch.go();}">
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="locSearchBtn" onclick="LocSearch.go()">寻物</button>
        <span class="loc-search-hint" id="locSearchHint">支持内部编号（#123）、立创编号、型号关键词；结果可播报并在左侧库位树中高亮</span>
    </div>
    <!-- 寻物结果面板（默认隐藏，搜到结果后由 LocFind 渲染） -->
    <div id="locFindResult" class="loc-find-result"></div>

    <div class="loc-cols">
        <!-- 左：三级树形导航 -->
        <div class="loc-side">
            <div class="loc-side-head">
                <div id="locSideMeta" class="loc-side-meta"></div>
            </div>
            <div id="locTreeBox" class="loc-tree-box"></div>
        </div>
        <!-- 右：库位详情 + 物料列表 -->
        <div id="locDetailPane" class="loc-pane">
            <div id="locEmptyTree" class="empty-state loc-pane-empty">
                <div class="icon">🏬</div>
                <div class="es-title">还没有库位</div>
                <div class="es-desc">创建仓库后即可按 仓库→库区→库位 三级管理物料存放位置</div>
                <?php if ($canManageLoc): ?>
                <div class="es-actions">
                    <button type="button" class="btn btn-primary btn-sm" onclick="LocTree.addNode(0)">＋ 新增第一个仓库</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="LocPage.backfill()">📦 存量回填历史库位</button>
                </div>
                <?php endif; ?>
            </div>
            <div id="locPickHint" class="empty-state loc-pane-empty">
                <div class="icon">🗂️</div>
                <div class="es-title">从左侧选择一个库位</div>
                <div class="es-desc">查看该库位下的物料与统计信息</div>
            </div>
            <div id="locDetailContent" class="loc-pane-content"></div>
        </div>
    </div>
</div>
</div>

<!-- 库位详情内置出入库弹窗 -->
<div class="overlay" id="locStockModal">
<div class="modal modal-sm">
    <h3>出入库操作</h3>
    <div class="loc-ctx">
        <span id="lskName" class="loc-ctx-name"></span>
        <span id="lskModel" class="loc-ctx-model"></span>
    </div>
    <div class="form-group">
        <label>操作类型</label>
        <select id="lskType" onchange="LocStock.onTypeChange()">
            <option value="manual_in" class="opt-in">入库（增加良品）</option>
            <option value="manual_out" class="opt-out">出库（减少良品）</option>
            <option value="adjust" class="opt-adj">盘点调整（直接设定数量）</option>
        </select>
    </div>
    <div class="form-group">
        <label>数量</label>
        <div class="qty-wrap">
            <div class="qty-stepper">
                <button type="button" onclick="LocStock.step(-1)">−</button>
                <input type="number" id="lskQty" min="1" value="1" oninput="LocStock.onQty()">
                <button type="button" onclick="LocStock.step(1)">＋</button>
            </div>
        </div>
        <span class="fld-err" id="lskQtyErr"></span>
    </div>
    <p class="loc-cur-line">当前库存：<span id="lskCur" class="loc-cur-val"></span></p>
    <?php if (Edition::enforceUnitPrice()): ?>
    <div class="form-group" id="lskCostWrap">
        <label>采购单价 <span class="loc-req-star">*</span> <span class="loc-label-note">（入库必填，用于加权平均成本）</span></label>
        <input type="number" id="lskUnitCost" step="0.0001" min="0" placeholder="0.00">
        <span class="fld-err" id="lskCostErr"></span>
    </div>
    <?php endif; ?>
    <div class="form-group"><label>备注</label><input type="text" id="lskRemark" placeholder="用途/项目..."></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('locStockModal')">取消</button>
        <button type="button" class="btn btn-primary" id="lskBtn" onclick="LocStock.submit()">确认</button>
    </div>
</div></div>

<?php ?>
<script>window.LocPageConfig = <?= json_encode([
    'tree'        => $tree,
    'maxDepth'    => (int)$maxDepth,
    'pending'     => (int)$pendingBackfill,
    'canViewCost' => Edition::canViewCost() ? 1 : 0,
    'canManage'   => $canManageLoc ? 1 : 0,
    'canEdit'     => $canEditLoc ? 1 : 0,
    'canBatch'    => $canBatchLoc ? 1 : 0,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="modules/location/Assets/js/locations.js?v=<?= APP_VERSION . '.' . filemtime(__DIR__ . '/../Assets/js/locations.js') ?>"></script>
<?php require __DIR__ . '/../../../ui/layouts/foot.php'; ?>
