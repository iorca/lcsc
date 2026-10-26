<?php
declare(strict_types=1);
/** 数据导出中心（全系统导出统一入口） */
use Modules\System\Controller\SystemController;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

// ── 通过 Controller 处理导出动作（CSV 下载，如匹配则直接 exit）──
SystemController::handleExportAction($db, $uid, $dataUid);

// ── 通过 Controller 获取页面视图数据 ──
try {
    $viewData = SystemController::prepareExportData($db, $uid, $dataUid);
    extract($viewData);
} catch (\Throwable $e) {
    error_log('export view: ' . $e->getMessage());
    $canViewCost = false; $stats = ['parts' => 0, 'stock' => 0];
    $lowStockCount = 0; $stockLogCount = 0;
    $bomProjects = []; $allCats = []; $allPlats = [];
    $canUseBom = false; $canUseChecklist = false;
}

// 财务资产导出 = 成本开关（双版本同一判据，与流水中心「财务资产」视图同源）
$canFinance  = $canViewCost;

$pageTitle = '数据导出中心';
$activePage = 'export';

\App\Core\AssetManager::addCss('modules/system/Assets/css/export.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>


<div class="main">
<div class="glass-box">
<div class="page-header">
    <h2>数据导出中心</h2>
    <div class="page-desc">全系统数据导出统一入口，各业务页快捷导出按钮互不影响</div>
</div>

<!-- ── 概览统计 ── -->
<div class="stat-grid">
    <div class="stat-card c-blue">
        <div class="stat-label">元件种类</div>
        <div class="stat-value"><?=(int)$stats['parts']?></div>
    </div>
    <div class="stat-card c-green">
        <div class="stat-label">库存总量</div>
        <div class="stat-value"><?=number_format((int)$stats['stock'])?></div>
    </div>
    <div class="stat-card c-purple">
        <div class="stat-label">出入库记录</div>
        <div class="stat-value"><?=number_format($stockLogCount)?></div>
    </div>
    <div class="stat-card <?=$lowStockCount>0?'c-red':'c-yellow'?>">
        <div class="stat-label">低库存预警</div>
        <div class="stat-value"><?=$lowStockCount?></div>
    </div>
</div>

<!-- ═ 组1：库存与流水 ═ -->
<div class="exp-group">
<div class="exp-group-title">库存与流水</div>
<div class="card-group exp-cards">

    <!-- ── 库存基础数据 ── -->
    <div class="card-item exp-card">
        <div class="card-title">📦 库存基础数据</div>
        <div class="card-desc">导出物料清单，共 <strong class="num-accent"><?=(int)$stats['parts']?></strong> 种元件，库存总量 <strong class="num-green"><?=number_format((int)$stats['stock'])?></strong></div>
        <div class="exp-actions">
            <a href="index.php?v=export&type=csv_simple" class="btn btn-ghost">📥 简易版 CSV</a>
            <?php if ($canViewCost): ?>
            <a href="index.php?v=export&type=csv" class="btn btn-primary">📥 完整版 CSV</a>
            <?php endif; ?>
        </div>
        <p class="exp-meta">简易版：仅型号+数量+库位（日常使用）<?php if ($canViewCost): ?>；完整版：含平台、编号、成本、分类等全部字段<?php endif; ?></p>
    </div>

    <?php if ($canViewCost): ?>
    <!-- ── 出入库流水 ── -->
    <div class="card-item exp-card exp-flow">
        <div class="card-title">📋 出入库流水</div>
        <div class="card-desc">当前账户共有 <strong class="num-accent"><?=number_format($stockLogCount)?></strong> 条出入库记录</div>
        <div class="exp-actions">
            <a href="index.php?v=export&type=stock_full" class="btn btn-primary">📥 导出完整出入库记录 CSV</a>
            <?php if ($canFinance): ?>
            <a href="index.php?v=stock&view=finance" class="btn btn-ghost">查看财务流水（流水中心）</a>
            <?php endif; ?>
        </div>
        <p class="exp-meta">完整记录含记录ID、操作时间、操作人、商品编号、型号、变动类型/数量、变动前后库存、单价、小计、订单时间、备注等全字段<?php if ($canFinance): ?>；带筛选的财务流水导出请前往流水中心「财务资产」视图<?php endif; ?></p>
    </div>
    <?php endif; ?>

    <!-- ─ 库存预警（个人版下本组只剩这张卡，故不另立组；见下方分组说明）── -->
    <div class="card-item exp-card exp-warn">
        <div class="card-title">⚠ 库存预警补货清单</div>
        <div class="card-desc">当前有 <strong class="num-warn"><?=$lowStockCount?></strong> 种物料库存低于预警阈值</div>
        <div class="exp-actions">
            <a href="index.php?v=export&export=replenish" class="btn btn-primary">📥 导出补货清单 CSV</a>
        </div>
        <p class="exp-meta">含商品编号、型号、当前库存、预警阈值、建议补货数量，格式适配嘉立创订单复制下单</p>
    </div>

</div>
</div>

<?php if ($canUseChecklist): ?>

<div class="exp-group">
<div class="exp-group-title">库存盘点</div>
<div class="card-group exp-cards">

    <!-- ── 库存盘点 ── -->
    <div class="card-item exp-card">
        <div class="card-title">🧮 盘点清单</div>
        <div class="card-desc">按筛选条件导出盘点清单（含系统库存快照），用于线下盘点后导入差异调整</div>
        <div class="filter-bar">
            <select id="expChkCat" class="filter-select">
                <option value="0">全部分类</option>
                <?php foreach($allCats as $c): ?>
                <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
                <?php endforeach; ?>
            </select>
            <select id="expChkPlat" class="filter-select">
                <option value="0">全部平台</option>
                <?php foreach($allPlats as $p): ?>
                <option value="<?=$p['id']?>"><?=h($p['name'])?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary" onclick="exportChecklist()">📥 导出盘点清单 CSV</button>
            <a href="index.php?v=checklist" class="btn btn-ghost">前往库存盘点</a>
        </div>
        <p class="exp-meta">列：id、internal_id、platform_part_no、model、product_name、brand、package、location、system_qty、actual_qty、snapshot_time</p>
    </div>

</div>
</div>
<?php endif; ?>

<?php if ($canUseBom): ?>
<!-- ═ 组3：BOM 专项（BOM 门控）══ -->
<div class="exp-group">
<div class="exp-group-title">BOM 专项</div>
<div class="card-group exp-cards">

    <div class="card-item exp-card">
        <div class="card-title">🧾 BOM 缺料采购清单</div>
        <div class="card-desc">按生产数量计算缺料并导出采购清单（型号、编号、品牌、封装、需求/库存/缺口、库位）</div>
        <?php if (empty($bomProjects)): ?>
        <div class="empty-block">
            <div class="empty-icon">🧾</div>
            <div class="empty-title">暂无 BOM 项目</div>
            <div class="empty-desc">创建 BOM 项目并导入物料清单后，可在此导出缺料采购清单</div>
            <div class="empty-action"><a href="index.php?v=bom" class="btn btn-ghost">前往 BOM 管理</a></div>
        </div>
        <?php else: ?>
        <div class="filter-bar">
            <select id="expBomProj" class="filter-select">
                <?php foreach($bomProjects as $bp): ?>
                <option value="<?=$bp['id']?>"><?=h($bp['name'])?>（<?=$bp['item_count']?> 项）</option>
                <?php endforeach; ?>
            </select>
            <input type="number" id="expBomQty" class="filter-input exp-qty" value="1" min="1" max="100000" title="生产数量">
            <a id="expBomLink" href="#" class="btn btn-primary">📥 导出缺料清单 CSV</a>
            <a href="index.php?v=bom" class="btn btn-ghost">前往 BOM 管理</a>
        </div>
        <p class="exp-meta">缺口 = 子物料需求 × 生产数量 − 当前库存；未匹配主库的待补行也会列入缺料清单</p>
        <?php endif; ?>
    </div>

</div>
</div>
<?php endif; ?>

</div>
</div>

<script src="modules/system/Assets/js/export.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/export.js')?>"></script>

</body></html>
