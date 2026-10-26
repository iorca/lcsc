<?php
declare(strict_types=1);

use Modules\Stock\Service\CheckSessionService;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

if (!hasFeature('checklist')) {
    header('Location: ' . siteUrl('index.php'));
    exit;
}

if (!hasPermission('can_edit')) {
    header('Location: ' . siteUrl('index.php'));
    exit;
}

$pageTitle = '库存盘点';
$activePage = 'stock';

\App\Core\AssetManager::addCss('modules/stock/Assets/css/checklist.css');
require __DIR__ . '/../../../ui/layouts/head.php';

require_once __DIR__ . '/../Service/CheckSessionService.php';
$filterOpts = (new CheckSessionService($db, $uid, $dataUid))->getFilterOptions();
$allCats  = $filterOpts['allCats'];
$allPlats = $filterOpts['allPlats'];
$allLocs  = $filterOpts['allLocs'];
?>

<div class="main">
<div class="page-header">
    <h2>📋 库存盘点</h2>
    <div class="page-desc">在线盘点（发起盘点单 → 在线录数 → 差异确认）或 Excel 导入模式</div>
</div>


<div class="view-tabs">
    <button type="button" class="view-tab active" id="tabOnline" onclick="switchMode('online')">🖥 在线盘点</button>
    <button type="button" class="view-tab" id="tabExcel" onclick="switchMode('excel')">📄 Excel 导入模式</button>
</div>

<!-- ═══ 在线盘点模式 ═══ -->
<div id="onlineMode" class="view-panel active">
    <!-- 发起盘点 -->
    <div class="check-section">
        <div class="step-title"><span class="step-num">1</span> 发起在线盘点</div>
        <p>选择盘点范围后发起，系统对当前库存做快照。盘点期间业务照常运行，确认时若库存有变动将自动跳过冲突行。</p>
        <div class="check-filter-row">
            <label>标题：
                <input type="text" id="csTitle" placeholder="如：2026Q3 全面盘点" class="cs-w160">
            </label>
            <label>分类：
                <select id="csCat">
                    <option value="0">全部分类</option>
                    <?php foreach($allCats as $c): ?>
                    <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>库位：
                <input type="text" id="csLoc" placeholder="库位关键词" class="cs-w120">
            </label>
            <label>平台：
                <select id="csPlat">
                    <option value="0">全部平台</option>
                    <?php foreach($allPlats as $p): ?>
                    <option value="<?=$p['id']?>"><?=h($p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" onclick="CS.createSession()">▶ 发起盘点</button>
        </div>
    </div>

    <!-- 盘点单列表 -->
    <div class="check-section">
        <div class="step-title"><span class="step-num">2</span> 盘点单</div>
        <div id="csSessionList" class="cs-session-list"></div>
    </div>

    <!-- 盘点明细 / 差异确认（由 JS 填充） -->
    <div id="csDetailView"></div>
</div>

<!-- ═══ Excel 导入模式 ═══ -->
<div id="excelMode" class="view-panel">

<!-- 步骤1：导出盘点清单 -->
<div class="check-section">
    <div class="step-title"><span class="step-num">1</span> 导出盘点清单</div>
    <p>导出当前库存的盘点清单，包含型号、编号、系统库存等信息。打印或在设备上打开，进行线下盘点。</p>
    <div class="check-filter-row">
        <label>分类：
            <select id="exportCat">
                <option value="0">全部分类</option>
                <?php foreach($allCats as $c): ?>
                <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>库位：
            <input type="text" id="exportLoc" placeholder="输入库位关键词" class="cs-w140">
        </label>
        <label>平台：
            <select id="exportPlat">
                <option value="0">全部平台</option>
                <?php foreach($allPlats as $p): ?>
                <option value="<?=$p['id']?>"><?=h($p['name'])?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary" onclick="exportChecklist()">📥 导出盘点清单 CSV</button>
    </div>
</div>

<!-- 步骤2：导入盘点结果 -->
<div class="check-section">
    <div class="step-title"><span class="step-num">2</span> 导入盘点结果</div>
    <p>在盘点清单中填写实际库存数量（actual_qty 列），保存为 CSV 文件后导入。系统会自动计算差异并生成调整记录。</p>
    <div id="dropZone" onclick="document.getElementById('checkFile').click()">
        <div class="drop-icon">📁</div>
        <div class="drop-title">点击选择或拖拽文件到此处</div>
        <div class="drop-desc">支持 CSV 格式，需包含 id（或 part_id）和 actual_qty 列</div>
        <input type="file" id="checkFile" accept=".csv" class="cs-file-hidden" onchange="handleFileSelect(this)">
    </div>
    <div class="check-result" id="importResult"></div>
</div>


<div class="check-section check-section-info">
    <h3>💡 使用说明</h3>
    <p class="check-info-line"><strong>导出的 CSV 包含以下列：</strong></p>
    <p class="check-info-line">id, internal_id, platform_part_no, model, product_name, brand, package, location, system_qty, actual_qty, snapshot_time</p>
    <p class="check-info-line"><strong>盘点步骤：</strong></p>
    <p class="check-info-line is-last">1. 导出盘点清单 CSV<br>
       2. 在 actual_qty 列填写实际盘点数量（其他列不要修改）<br>
       3. 保存为 CSV 文件后导入<br>
       4. 系统自动生成库存调整记录</p>
</div>
</div><!-- /excelMode -->

<script src="modules/stock/Assets/js/check-session.js?v=<?=filemtime(__DIR__.'/../Assets/js/check-session.js')?>"></script>
<script src="modules/stock/Assets/js/checklist.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/checklist.js')?>"></script>

</body></html>
