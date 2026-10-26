<?php
declare(strict_types=1);
/** modules/inventory/Views/index.php — 物料列表页 */
use Modules\Inventory\Controller\InventoryController;

require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$globalThr = getGlobalThreshold();

// ── 通过 Controller 获取视图数据 ──
try {
    $viewData = InventoryController::prepareViewData($db, $uid, $dataUid);
} catch (\Throwable $e) {
    error_log('InventoryController::prepareViewData failed: ' . $e->getMessage());
    $viewData = [
        'q' => '', 'page' => 1, 'perPage' => 25, 'filter' => '',
        'catParam' => '', 'catId' => 0, 'catIds' => [], 'noCat' => false,
        'platId' => 0, 'locFilter' => '', 'searchKeywordCount' => 0,
        'rows' => [], 'total' => 0, 'totalPage' => 0, 'catMap' => [],
        'stats' => ['total' => 0, 'total_stock' => 0, 'low_count' => 0, 'zero_count' => 0, 'total_damaged' => 0],
        'allCats' => [], 'topCats' => [], 'subCats' => [], 'unboundSubCats' => [],
        'noCatCount' => 0, 'allPlats' => [], 'ptypeCats' => [], 'allLocs' => [],
        'topCatsJson' => '[]', 'subCatsJson' => '[]', 'unboundSubCatsJson' => '[]',
        'noticeContent' => '', 'noticeMode' => 'off', 'showNotice' => false,
        'subNoticeContent' => '', 'subNoticeMode' => 'off', 'showSubNotice' => false,
        'flash' => '', 'loginFlash' => '',
    ];
}
extract($viewData);
$pageTitle = '库存总览';
$activePage = 'index';
$extraTopbarRight = isPrimaryAdmin()
    ? '<a href="index.php?v=admin&tab=settings" class="icon-btn" title="进入网站设置">'.h($user['username']).'</a>'
    : '';

$showImages = getSetting('parts_show_images', '0') === '1';

// ── 立创式多维分类筛选：按一级维度（品牌 / 商品类型 / 封装格式）分组二级值 ──
// 三个维度**并列**，各占一行；每个物料在每个维度下挂一个值节点。
$dimOrder  = ['品牌', '商品类型', '封装格式'];
$subsByPid = [];
foreach ((array)$subCats as $sc) {
    $pid = (int)($sc['parent_id'] ?? 0);
    if ($pid > 0) $subsByPid[$pid][] = $sc;
}
$topByName = [];
foreach ((array)$topCats as $tc) { $topByName[(string)$tc['name']] = $tc; }
$dimRows = [];
foreach ($dimOrder as $dn) {
    if (!isset($topByName[$dn])) continue;
    $tc = $topByName[$dn];
    $dimRows[] = ['id' => (int)$tc['id'], 'name' => $dn, 'subs' => $subsByPid[(int)$tc['id']] ?? []];
}
// 其余一级分类（手工建的等）也作为附加维度列出
foreach ((array)$topCats as $tc) {
    $nm = (string)$tc['name'];
    if (in_array($nm, $dimOrder, true)) continue;
    $dimRows[] = ['id' => (int)$tc['id'], 'name' => $nm, 'subs' => $subsByPid[(int)$tc['id']] ?? []];
}
$dimLabels = array_column($dimRows, 'name');

// ── 桌面端表格的列定义（**全项目唯一来源**）────────────────────────────

$listCols = array_values(array_filter([
    ['cls' => 'col-cb',       'cond' => isAdmin()],
    ['cls' => 'col-thumb',    'cond' => $showImages],
    ['cls' => 'col-cover',    'cond' => null],
    ['cls' => 'col-code',     'cond' => null],
    ['cls' => 'col-model',    'cond' => null],
    ['cls' => 'col-desc',     'cond' => null],
    ['cls' => 'col-pkg',      'cond' => null],
    ['cls' => 'col-cat',      'cond' => null],
    ['cls' => 'col-brand',    'cond' => null],
    ['cls' => 'col-stock',    'cond' => null],
    // 库位列：恒显示（location 是普通文本字段，库位树只是录入辅助）。

    ['cls' => 'col-location', 'cond' => null],
    ['cls' => 'col-ctime',    'cond' => null],
    ['cls' => 'col-actions',  'cond' => null],
], static fn(array $c): bool => $c['cond'] === null || (bool)$c['cond']));

$listColCount = count($listCols);

$platById = [];
foreach ($allPlats as $pl) {
    $platById[(int)$pl['id']] = [
        'type' => (string)($pl['platform_type'] ?? 'standard'),
        'url'  => (string)($pl['url_template'] ?? ''),
    ];
}

$ppnUrlOf = static function (array $r) use ($platById): string {
    $plat = $platById[(int)($r['platform_id'] ?? 0)] ?? null;
    if (!$plat || $plat['type'] !== 'standard' || $plat['url'] === '') return '';
    return platformUrl($plat['url'], (string)($r['platform_part_no'] ?? ''));
};

\App\Core\AssetManager::addCss('modules/inventory/Assets/css/parts.css');
App\Core\AssetManager::addCss('modules/inventory/Assets/css/parts-filter.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<div class="main">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      $actionError = $_SESSION['action_error'] ?? '';
      if ($actionError) unset($_SESSION['action_error']);
      if($flash==='err') echo '<div class="flash err">✗ 操作失败' . ($actionError && isAdmin() ? '：'.h($actionError) : '，请重试') . '</div>'; ?>


<!-- 顶部概览条：统计（点击筛选）+ 筛选态 + 显示密度，合并为一行 -->
<div class="inv-statsbar">
    <button type="button" class="inv-stat c-blue stat-filter <?=($filter===''?'active':'')?>" data-filter="" onclick="applyFilter('')" title="查看全部物料"><span class="stat-label">种类</span><span class="stat-value" id="statTotal"><?=number_format((int)$stats['total'])?></span></button>
    <button type="button" class="inv-stat c-yellow stat-filter <?=($filter==='low'?'active':'')?>" data-filter="low" onclick="applyFilter('low')" title="筛选：库存不足"><span class="stat-label">不足</span><span class="stat-value" id="statLow"><?=(int)$stats['low_count']?></span></button>
    <button type="button" class="inv-stat c-red stat-filter <?=($filter==='zero'?'active':'')?>" data-filter="zero" onclick="applyFilter('zero')" title="筛选：用完"><span class="stat-label">用完</span><span class="stat-value" id="statZero"><?=(int)$stats['zero_count']?></span></button>
    <button type="button" class="inv-stat c-purple stat-filter <?=($filter==='damaged'?'active':'')?>" data-filter="damaged" onclick="applyFilter('damaged')" title="筛选：不良品"><span class="stat-label">不良品</span><span class="stat-value" id="statDamaged"><?=number_format((int)$stats['total_damaged'])?></span></button>
    <button type="button" class="inv-stat c-gray stat-filter <?=($filter==='negative'?'active':'')?>" data-filter="negative" onclick="applyFilter('negative')" title="筛选：负库存（库存为负）"><span class="stat-label">负库存</span><span class="stat-value" id="statNegative"><?=(int)($stats['negative_count'] ?? 0)?></span></button>

    <div class="inv-statsbar-right">
        <!-- 筛选状态标签（点击统计卡后自动生成，样式与分类标签统一） -->
        <div class="filter-state-row" id="filterStateRow"></div>
        <!-- 密度切换（舒适/紧凑，存本地偏好） -->
        <div class="inv-dens-row">
            <div class="dens-seg" id="densSeg">
                <button type="button" data-dens="comfort" onclick="toggleDensity('comfort')" title="每行两行信息：型号 + 商品描述">舒适</button>
                <button type="button" data-dens="dense" onclick="toggleDensity('dense')" title="单行高密度：仅显示型号">紧凑</button>
            </div>
        </div>
    </div>
</div>

<!-- 工具栏（第一行：搜索 + 筛选 + 添加） -->
<div class="toolbar filter-row">
    <div class="search-box">
        <svg class="search-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <form method="get" action="index.php" id="searchForm" onsubmit="return ajaxSearch(event)" class="inv-search-form">
            <input type="hidden" name="v" value="parts">
            <input name="q" id="searchInput" value="<?=h($q)?>" placeholder="搜索型号 / 编号 / 封装 / 参数…（多条件用空格或 | , ; 分隔）" autocomplete="off" class="inv-search-input">
            <button type="button" id="clearSearchBtn" onclick="clearSearch()" class="clear-btn" title="清空搜索" aria-label="清空搜索">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <button type="submit" class="search-submit">搜索</button>
            <?php if($filter)  echo '<input type="hidden" name="filter" value="'.h($filter).'">'; ?>
            <?php if(!empty($catIds)) echo '<input type="hidden" name="cat" value="'.h(implode(',',$catIds)).'">'; elseif($catId) echo '<input type="hidden" name="cat" value="'.$catId.'">'; ?>
            <?php if($platId)  echo '<input type="hidden" name="plat" value="'.$platId.'">'; ?>
            <?php if($locFilter) echo '<input type="hidden" name="loc" value="'.h($locFilter).'">'; ?>
        </form>
    </div>
    <?php if(hasPermission('can_edit')): ?>
    <button class="btn btn-success filter-add-btn" onclick="openAddModal()">＋ 添加</button>
    <?php endif; ?>
    <?php if(count($allPlats)>1): ?>
    <select onchange="applyPlatform(this.value)" class="filter-select">
        <option value="0" <?=$platId===0?'selected':''?>>所有平台</option>
        <?php foreach($allPlats as $pl): ?>
        <option value="<?=$pl['id']?>" <?=$platId==$pl['id']?'selected':''?>><?=h($pl['name'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select onchange="changeSort(this.value)" class="filter-select" id="sortSel" title="排序方式">
        <option value="update_time:desc">最近更新</option>
        <option value="create_time:desc">导入时间（新→旧）</option>
        <option value="create_time:asc">导入时间（旧→新）</option>
        <option value="brand:asc">品牌名称</option>
        <option value="brand:desc">品牌名称（倒序）</option>
        <option value="stock:desc">库存（多→少）</option>
        <option value="stock:asc">库存（少→多）</option>
        <option value="platform_part_no:asc">商品编号</option>
        <option value="model:asc">型号</option>
        <option value="location:asc">库位</option>
    </select>
    <span class="view-mode-switch" title="显示模式">
        <button type="button" class="vms-btn" data-mode="comfy" onclick="setViewMode('comfy')">舒适</button>
        <button type="button" class="vms-btn" data-mode="compact" onclick="setViewMode('compact')">紧凑</button>
    </span>
</div>

<!-- 立创式多维分类筛选：三个**并列**维度各占一行，值大号铺开，可点选多选 -->
<?php if ($dimRows): ?>
<div class="lcsc-filter" id="lcscFilter">
    <div class="lcsc-filter-head">
        <span class="lcsc-filter-title">分类筛选</span>
        <button type="button" class="lcsc-clear-all" onclick="clearLcscFilter()">清除全部</button>
    </div>
    <?php foreach ($dimRows as $di => $dim): ?>
    <div class="lcsc-filter-row" data-dim="<?=$di?>">
        <div class="lcsc-filter-label"><?=h($dim['name'])?></div>
        <div class="lcsc-filter-body">
            <div class="lcsc-filter-chips">
                <?php foreach (array_slice($dim['subs'], 0, 14) as $sub): ?>
                <button type="button" class="lcsc-chip" data-dim="<?=$di?>" data-cat-id="<?=(int)$sub['id']?>" data-cat-name="<?=h($sub['name'])?>" onclick="toggleLcscCat(<?=$di?>,<?=(int)$sub['id']?>)">
                    <?=h($sub['name'])?><span class="lcsc-chip-cnt"><?=(int)($sub['cnt']??0)?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <?php if (count($dim['subs']) > 14): ?>
            <div class="lcsc-filter-more" id="lcscMore<?=$di?>" hidden>
                <?php foreach (array_slice($dim['subs'], 14) as $sub): ?>
                <button type="button" class="lcsc-chip" data-dim="<?=$di?>" data-cat-id="<?=(int)$sub['id']?>" data-cat-name="<?=h($sub['name'])?>" onclick="toggleLcscCat(<?=$di?>,<?=(int)$sub['id']?>)">
                    <?=h($sub['name'])?><span class="lcsc-chip-cnt"><?=(int)($sub['cnt']??0)?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <button type="button" class="lcsc-more-btn" id="lcscMoreBtn<?=$di?>" onclick="toggleLcscMore(<?=$di?>)">更多 ∨</button>
            <?php endif; ?>
        </div>
        <div class="lcsc-filter-sel" id="lcscSel<?=$di?>"></div>
        <button type="button" class="lcsc-dim-clear" id="lcscClear<?=$di?>" onclick="clearLcscDim(<?=$di?>)" disabled>清除</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- PC端：分类筛选专区（两行：一级大类 + 二级分类） -->
<?php if($topCats || $allCats || $noCatCount>0): ?>
<div class="cat-filter-box is-hidden" id="catFilterBox">
    <!-- 第一行：一级大类 -->
    <div class="cat-filter-row" id="catRow1">
        <span class="cat-row-label">类目：</span>
        <div class="cat-row-pills" id="topCatPills">
            <?php foreach($topCats as $tc): ?>
            <span class="cat-tag-pill" data-cat-id="<?=$tc['id']?>" data-cat-name="<?=h($tc['name'])?>" onclick="selectTopCat(<?=$tc['id']?>)"><?=h($tc['name'])?><span class="cat-tag-cnt"><?=$tc['cnt']?></span></span>
            <?php endforeach; ?>
            <?php if($noCatCount > 0): ?>
            <span class="cat-tag-pill" data-cat-id="-1" data-cat-name="未归类" onclick="selectTopCat(-1)">未归类<span class="cat-tag-cnt"><?=$noCatCount?></span></span>
            <?php endif; ?>
        </div>
        <div class="cat-row-btns">
            <button type="button" class="cat-act-btn" id="topExpandBtn" onclick="toggleTopExpand()">展开</button>
            <button type="button" class="cat-act-btn" id="topMultiBtn" onclick="openTopMultiPanel()">多选</button>
            <button type="button" class="cat-act-btn cat-clear-btn" id="topClearBtn" onclick="clearTopCat()" disabled>清除 ×</button>
        </div>
    </div>
    <!-- 一级展开内嵌面板（无复选框，点击即选中并关闭） -->
    <div class="cat-inline-panel cat-expand-panel" id="topExpandPanel">
        <div class="cat-inline-list" id="topExpandList"></div>
    </div>
    <!-- 一级多选内嵌面板 -->
    <div class="cat-inline-panel" id="topMultiPanel">
        <div class="cat-inline-list" id="topMultiList"></div>
        <div class="cat-inline-footer">
            <div class="cat-inline-ops">
                <button type="button" class="cat-act-btn" onclick="topMultiSelectAll()">全选</button>
                <button type="button" class="cat-act-btn" onclick="topMultiInvert()">反选</button>
            </div>
            <div class="cat-inline-btns">
                <button type="button" class="btn btn-ghost btn-sm" onclick="cancelTopMulti()">取消</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="applyTopMulti()">确定</button>
            </div>
        </div>
    </div>
    <!-- 第二行：二级分类（默认隐藏，选中一级后显示） -->
    <div class="cat-filter-row is-hidden" id="catRow2">
        <span class="cat-row-label">子类：</span>
        <div class="cat-row-pills" id="subCatPills"></div>
        <div class="cat-row-btns">
            <button type="button" class="cat-act-btn" id="subExpandBtn" onclick="toggleSubExpand()">展开</button>
            <button type="button" class="cat-act-btn" id="subMultiBtn" onclick="openSubMultiPanel()">多选</button>
            <button type="button" class="cat-act-btn cat-clear-btn" id="subClearBtn" onclick="clearSubCat()" disabled>清除 ×</button>
        </div>
    </div>
    <!-- 二级展开内嵌面板（无复选框，点击即选中并关闭） -->
    <div class="cat-inline-panel cat-expand-panel" id="subExpandPanel">
        <div class="cat-inline-list" id="subExpandList"></div>
    </div>
    <!-- 二级多选内嵌面板 -->
    <div class="cat-inline-panel" id="subMultiPanel">
        <div class="cat-inline-list" id="subMultiList"></div>
        <div class="cat-inline-footer">
            <div class="cat-inline-ops">
                <button type="button" class="cat-act-btn" onclick="subMultiSelectAll()">全选</button>
                <button type="button" class="cat-act-btn" onclick="subMultiInvert()">反选</button>
            </div>
            <div class="cat-inline-btns">
                <button type="button" class="btn btn-ghost btn-sm" onclick="cancelSubMulti()">取消</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="applySubMulti()">确定</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 移动端：分类筛选在悬浮按钮内 -->

<!-- 表格区域（毛玻璃容器） -->
<div class="glass-box inv-glass-spaced">

<!-- 桌面端表格 -->
<div class="table-wrap inv-table<?=$showImages ? ' has-img' : ''?>" id="desktopTable">
<table>
    <colgroup>
        <?php foreach ($listCols as $lc): ?><col class="<?=h($lc['cls'])?>"><?php endforeach; ?>
    </colgroup>
    <thead><tr>
        <?php if(isAdmin()): ?><th class="cb-col"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选"></th><?php endif; ?>
        <?php if($showImages): ?><th class="col-thumb">图片</th><?php endif; ?>
        <th class="col-cover">图片</th>
        <th class="sortable" data-sort="platform_part_no" onclick="toggleSort(this)" title="点击排序">商品编号<span class="sort-arrow"></span></th>
        <th class="sortable" data-sort="model" onclick="toggleSort(this)" title="点击排序">型号<span class="sort-arrow"></span></th>
        <th class="col-desc">参数/描述</th>
        <th>封装</th>
        <th>分类</th>
        <th class="sortable" data-sort="brand" onclick="toggleSort(this)" title="点击排序">品牌<span class="sort-arrow"></span></th>
        <th class="sortable" data-sort="stock" onclick="toggleSort(this)" title="点击排序">库存<span class="sort-arrow"></span></th>
        <th class="sortable" data-sort="location" onclick="toggleSort(this)" title="点击排序">库位<span class="sort-arrow"></span></th>
        <th class="sortable" data-sort="create_time" onclick="toggleSort(this)" title="点击排序">导入时间<span class="sort-arrow"></span></th>
        <th>操作</th>
    </tr></thead>
    <tbody id="desktopTbody">
    <?php if(empty($rows)): ?>
<tr><td colspan="<?= $listColCount ?>">
            <div class="empty-state">
                <div class="icon">📦</div>
                <?php if ($q !== '' && $searchKeywordCount > 1): ?>
                    <div class="es-title">未找到匹配「<?=h($q)?>」的元件</div>
                    <div class="es-desc">多个条件之间为 AND 关系（需同时满足）。可用空格 / | , ; 分隔；商品编号等唯一标识按精准匹配，封装与参数按包含匹配</div>
                <?php elseif ($q !== ''): ?>
                    <div class="es-title">未找到匹配「<?=h($q)?>」的元件</div>
                    <div class="es-desc">试试简化关键词，或清除筛选条件（至少输入 2 个字符）</div>
                <?php else: ?>
                    <div class="es-title">暂无数据</div>
                    <div class="es-desc">添加第一个元件开始管理库存</div>
                <?php endif; ?>
                <div class="es-actions">
                    <?php if(hasPermission('can_edit')): ?><button type="button" class="btn btn-primary btn-sm" onclick="openAddModal()">＋ 添加元件</button><?php endif; ?>
                    <?php if($q!==''||$filter!==''||$catId||$platId||$locFilter): ?><button type="button" class="btn btn-ghost btn-sm" onclick="resetFilters()">清除全部筛选</button><?php endif; ?>
                </div>
            </div></td></tr>
    <?php else: foreach($rows as $r):
        $sc = $r['stock']==0?'s-zero':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'s-low':'s-ok');
        $rc = $r['stock']==0?'row-zero':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'row-low':'');
        $partJson = h(json_encode($r, JSON_UNESCAPED_UNICODE));
        $stockInfo = h(json_encode(['id'=>$r['id'],'model'=>$r['model'],'ppn'=>(string)($r['platform_part_no'] ?? $r['customer_part_no'] ?? ''),'location_id'=>(int)($r['location_id'] ?? 0),'stock'=>(int)$r['stock'],'damaged'=>(int)($r['damaged']??0)], JSON_UNESCAPED_UNICODE));
        $ppnUrl = $ppnUrlOf($r);
        $ppnRaw = (string)($r['platform_part_no'] ?? '');
        $modelRaw = (string)$r['model'];
        $nameRaw = (string)$r['product_name'];
        $pkgRaw = (string)$r['package'];
        $brandRaw = (string)$r['brand'];
        $locRaw = (string)$r['location'];
        $catsRaw = implode('、', $catMap[$r['id']] ?? []);
        $ctimeRaw = (string)($r['create_time'] ?? '');
    ?>
    <tr class="<?=$rc?>" data-part-id="<?=$r['id']?>">
        <?php if(isAdmin()): ?>
        <td class="cb-col" onclick="event.stopPropagation()">
            <input type="checkbox" class="part-cb" value="<?=$r['id']?>" data-stock="<?=(int)$r['stock']?>" onchange="onPartCbChange(this)">
        </td>
        <?php endif; ?>
        <?php
        // 封面图与缩略图共用一份解析（外链原样返回，本地文件走缩略图接口）
        $imgRaw   = (string)($r['image'] ?? '');
        $bigUrl   = $imgRaw !== '' ? \Modules\Inventory\Components\PartDetail::thumbUrl($imgRaw, 800) : '';
        // 封面用 260 规格：本地走 asset.thumb 生成 webp，外链原样返回
        $coverUrl = $imgRaw !== '' ? \Modules\Inventory\Components\PartDetail::thumbUrl($imgRaw, 160) : '';
        if ($showImages) {
            $thumbUrl = $imgRaw !== '' ? \Modules\Inventory\Components\PartDetail::thumbUrl($imgRaw, 160) : '';
        }
        ?>
        <?php if($showImages): ?>
        <td class="col-thumb"<?=$imgRaw!==''?' onclick="event.stopPropagation()"':''?>>
            <?php if($thumbUrl!==''): ?>
                <img class="row-thumb" src="<?=h($thumbUrl)?>" alt="" loading="lazy" onclick="event.stopPropagation();attPreview('<?=h($bigUrl)?>')" title="点击放大">
            <?php else: ?>
                <span class="row-thumb-empty"></span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php
              ?>
        <!-- 立创式缩略图 -->
        <td class="col-cover"<?=$imgRaw!==''?' onclick="event.stopPropagation()"':''?>>
            <?php if($coverUrl!==''): ?>
                <div class="lcsc-thumb"><img src="<?=h($coverUrl)?>" alt="" loading="lazy" onclick="event.stopPropagation();attPreview('<?=h($bigUrl)?>')" title="点击放大"></div>
            <?php else: ?>
                <div class="lcsc-thumb"><span class="ph">无图</span></div>
            <?php endif; ?>
        </td>
        <?php
            // 立创式主信息块：型号(黄高亮) + 品牌/封装/类目/编号 竖排
            $catText   = implode('、', $catMap[$r['id']] ?? []);
            $hotBadge  = '';
            $thr       = $r['eff_threshold'] ?? $globalThr;
            if ((int)$r['stock'] === 0)       $hotBadge = '<span class="lcsc-badge">缺货</span>';
            elseif ((int)$r['stock'] <= (int)$thr) $hotBadge = '<span class="lcsc-badge b-blue">库存紧张</span>';
            elseif ((int)$r['stock'] >= 100)  $hotBadge = '<span class="lcsc-badge b-green">现货</span>';
            $codeHtml  = $ppnUrl !== ''
                ? '<a href="'.h($ppnUrl).'" target="_blank" rel="noopener" onclick="event.stopPropagation()">'.h($ppnRaw).'</a>'
                : h($ppnRaw !== '' ? $ppnRaw : '—');
        ?>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($ppnRaw)?>" class="col-code td-ellipsis" data-label="商品编号">
            <?php if($ppnUrl!==''): ?>
            <a href="<?=h($ppnUrl)?>" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="code-blue u-underline"><?=formatPpn($ppnRaw)?></a>
            <?php else: ?>
            <span class="code-blue"><?=formatPpn($ppnRaw)?></span>
            <?php endif; ?>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($modelRaw)?>" class="col-model" data-label="型号">
            <div class="lcsc-main">
                <div class="lcsc-model-line">
                    <span class="lcsc-model" onclick="openDrawer(<?=$r['id']?>)"><?=h($modelRaw !== '' ? $modelRaw : '—')?></span>
                    <?=$hotBadge?>
                </div>
                <div class="lcsc-meta">
                    <div class="lcsc-meta-row"><span class="k">品牌：</span><span class="v"><?=h($brandRaw !== '' ? $brandRaw : '—')?></span></div>
                    <div class="lcsc-meta-row"><span class="k">封装：</span><span class="v"><?=h($pkgRaw !== '' ? $pkgRaw : '—')?></span></div>
                    <div class="lcsc-meta-row"><span class="k">类目：</span><span class="v lcsc-type-link"><?=h($catText !== '' ? $catText : '—')?></span></div>
                    <div class="lcsc-meta-row"><span class="k">编号：</span><span class="v"><?=$codeHtml?></span></div>
                    <div class="lcsc-meta-row"><span class="k">库位：</span><span class="v"><?=h($locRaw !== '' ? $locRaw : '—')?></span></div>
                    <div class="lcsc-meta-row"><span class="k">导入：</span><span class="v"><?=h($ctimeRaw !== '' ? substr(str_replace('T',' ',$ctimeRaw),0,16) : '—')?></span></div>
                </div>
            </div>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)" class="col-desc" data-label="参数/描述">
            <div class="lcsc-desc">
                <?php if($nameRaw!==''): ?><span class="d-title"><?=h($nameRaw)?></span><?php endif; ?>
                <?php $paramRaw = (string)($r['parameters_text'] ?? ''); ?>
                <?php if($paramRaw!==''): ?><span class="d-param">参数：<?=h($paramRaw)?></span><?php endif; ?>
            </div>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($pkgRaw)?>" class="col-pkg td-ellipsis" data-label="封装"><?php if($pkgRaw!==''): ?><span class="pkg-badge"><?=h($pkgRaw)?></span><?php else: ?><span class="ph-dash">—</span><?php endif; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($catsRaw)?>" class="col-cat td-ellipsis" data-label="分类"><?php if($catsRaw!==''): foreach($catMap[$r['id']]??[] as $ct) echo '<span class="cat-tag">'.h($ct).'</span>'; else: ?><span class="ph-dash">—</span><?php endif; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($brandRaw)?>" class="col-brand td-ellipsis" data-label="品牌"><?php if($brandRaw!==''): ?><span class="brand-txt"><?=h($brandRaw)?></span><?php else: ?><span class="ph-dash">—</span><?php endif; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)" class="col-stock" data-label="库存">
            <div class="lcsc-stock">
                <span class="s-num <?=$sc?>"><?=$r['stock']?></span>
                <span class="s-unit">个</span>
            </div>
            <?php if((int)($r['damaged']??0)>0): ?><span class="stk-tag red" title="不良品数量">不良<?=(int)$r['damaged']?></span><?php endif; ?>
            <?php if(!empty($r['is_stale'])): ?><span class="stk-tag gray" title="90天无出入库">呆滞</span><?php endif; ?>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($locRaw)?>" class="col-location td-ellipsis" data-label="库位"><?php if($locRaw!==''): ?><span class="loc-chip" title="<?=h($locRaw)?>（点击可筛选同库位物料）" onclick="event.stopPropagation();filterByLocation('<?=h($locRaw)?>')">📍 <span class="loc-name"><?=h($locRaw)?></span></span><?php else: ?><span class="loc-chip-empty">—</span><?php endif; ?></td>
        <?php $ctimeRaw = (string)($r['create_time'] ?? ''); ?>
        <td onclick="openDrawer(<?=$r['id']?>)" class="col-ctime" data-label="导入时间"><?php if($ctimeRaw!==''): ?><span class="ctime-txt" title="导入时间 <?=h($ctimeRaw)?>"><?=h(substr(str_replace('T',' ',$ctimeRaw),0,16))?></span><?php else: ?><span class="ph-dash">—</span><?php endif; ?></td>
        <td class="td-actions" onclick="event.stopPropagation()">
            <div class="actions">
                <button class="btn btn-ghost btn-sm btn-stock" data-info="<?=$stockInfo?>">出入库</button>
                <?php if(hasPermission('can_edit')): ?>
                <button class="btn btn-ghost btn-sm" data-part="<?=$partJson?>" data-platform-type="<?=h($r['platform_type'] ?? 'standard')?>" onclick="openEditModal(this)">编辑</button>
                <?php endif; ?>
                <?php if(hasPermission('can_delete')): ?>
                <button class="btn btn-danger btn-sm btn-del" data-info="<?=$stockInfo?>">删除</button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php

      ?>

<!-- 分页 -->
<?php if($totalPage>1 || $total>0):
    $qStr=http_build_query(array_filter([
        'v'=>'parts','q'=>$q?:null,'filter'=>$filter?:null,'cat'=>$catId?:null,'plat'=>$platId?:null,'loc'=>$locFilter?:null
    ],fn($v)=>$v!==null));
?>
<div class="pagination" id="paginationArea">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$page?>" onkeydown="pageJumpTo(event,'?<?=$qStr?>&per_page=<?=$perPage?>',<?=$totalPage?>)"> 页</span>
    <a href="?<?=$qStr?>&per_page=<?=$perPage?>&page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s=max(1,$page-2);$e=min($totalPage,$page+2);
    if($s>1) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page=1" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page='.$totalPage.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?<?=$qStr?>&per_page=<?=$perPage?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
    <select onchange="changePerPage(this.value)" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
</div><!-- /glass-box -->
</div><!-- /main -->

<!-- 批量操作栏 -->
<?php if(isAdmin()): ?>
<div class="batch-bar" id="batchBar">
    <span>已选 <span class="batch-count" id="batchCount">0</span> 项</span>
    <button class="btn btn-ghost btn-sm" onclick="batchSetCategory()">设置分类</button>
    <button class="btn btn-ghost btn-sm" onclick="autoClassifyParts()" title="按「品牌 / 商品类型 → 封装格式」自动建两级分类树；未选中时处理全部未分类物料">🏷 规则归类</button>
    <button class="btn btn-ghost btn-sm" onclick="lcscEnrichParts()" title="按立创编号回查并补全商品类型（订单详情表没有「商品类型」列时用）">🔄 立创补全</button>
    <button class="btn btn-ghost btn-sm" onclick="batchAiClassify()" title="调用大模型自动判定商品类型 / 品牌 / 封装">🤖 AI 分类</button>
    <button class="btn btn-ghost btn-sm" onclick="localizeImages()" title="把立创图床的商品图下载到本地，断网也能显示">🖼 图片本地化</button>
    <button class="btn btn-ghost btn-sm" onclick="batchSetLocation()">设置库位</button>
    <button class="btn btn-ghost btn-sm" onclick="batchSetRemark()">设置备注</button>
    <button class="btn btn-ghost btn-sm" onclick="batchSetThreshold()">📉 批量阈值</button>
    <button class="btn btn-ghost btn-sm" onclick="openMergeWizard()">🔀 合并重复</button>
    <button class="btn btn-ghost btn-sm" onclick="batchAddToBom()">📋 加入BOM</button>
    <button class="btn btn-ghost btn-sm" onclick="batchPrint()">🖨 打印标签</button>
    <button class="btn btn-primary btn-sm" onclick="batchExport()">📥 导出选中</button>
    <button class="btn btn-danger btn-sm" onclick="batchDelete()">批量删除</button>
    <button class="btn btn-ghost btn-sm" onclick="clearSelection()">取消选择</button>
</div>
<?php endif; ?>

<!-- 移动端悬浮筛选按钮 -->
<button class="fab-add" onclick="openMCatDrawer()" title="分类筛选" aria-label="分类筛选">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
</button>

<!-- 批量设置分类 Modal -->
<?php
// P5 树形：构建分类完整路径映射（含祖先链），用于批量分类下拉的层级显示
$_catPathMap = [];
if (!empty($subCats)) {
    $_topNames = [];
    foreach ($topCats as $tc) { $_topNames[(int)$tc['id']] = $tc['name']; }
    $_byId = [];
    foreach ($subCats as $sc) { $_byId[(int)$sc['id']] = $sc; }
    foreach ($subCats as $sc) {
        $parts = [];
        $cur = (int)$sc['parent_id'];
        $guard = 0;
        while ($cur > 0 && $guard++ < 8) {
            if (isset($_topNames[$cur])) { array_unshift($parts, $_topNames[$cur]); break; }
            if (!isset($_byId[$cur])) break;
            array_unshift($parts, $_byId[$cur]['name']);
            $cur = (int)$_byId[$cur]['parent_id'];
        }
        $_catPathMap[(int)$sc['id']] = implode(' / ', $parts);
    }
    unset($_topNames, $_byId, $parts);
}
?>
<div class="overlay" id="modalBatchCat">
<div class="modal modal-sm">
    <h3>批量设置分类</h3>
    <form method="post" action="api.php" id="batchCatForm">
    <input type="hidden" name="action" value="parts.batch_set_category">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchCatIds"></div>
    <div class="form-group"><label>选择分类</label>
        <select name="category_id" required>
            <option value="">-- 请选择 --</option>
            <?php foreach($allCats as $c): $cp = $_catPathMap[(int)$c['id']] ?? ''; ?>
            <option value="<?=$c['id']?>"><?=$cp !== '' ? h($cp.' / ') : ''?><?=h($c['name'])?> (<?=$c['cnt']?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label>或输入新分类名称</label>
        <input name="new_category" placeholder="留空则使用上方已有分类">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchCat')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>

<!-- 批量设置库位 Modal -->
<div class="overlay" id="modalBatchLoc">
<div class="modal modal-sm">
    <h3>批量设置库位</h3>
    <form method="post" action="api.php" id="batchLocForm">
    <input type="hidden" name="action" value="parts.batch_set_location">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchLocIds"></div>
    <div class="form-group"><label>库位/描述</label>
        <?php if (hasFeature('location_manage')): ?>
        <select class="loc-picker" id="b_locpicker" data-text="b_loc"></select>
        <input type="hidden" name="location_id" class="loc-picker-id" value="">
        <?php endif; ?>
        <input name="location" id="b_loc" list="locTextList" placeholder="抽屉A1" <?=hasFeature('location_manage') ? '' : 'required'?>>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchLoc')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>

<!-- 批量设置备注 Modal -->
<div class="overlay" id="modalBatchRem">
<div class="modal modal-sm">
    <h3>批量设置备注</h3>
    <form method="post" action="api.php" id="batchRemForm">
    <input type="hidden" name="action" value="parts.batch_set_remark">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchRemIds"></div>
    <div class="form-group"><label>备注内容</label>
        <textarea name="remark" placeholder="输入备注内容（留空则清空备注）" rows="3"></textarea>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchRem')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>


<div class="overlay" id="modalBatchThreshold">
<div class="modal modal-sm">
    <h3>批量设置安全库存阈值</h3>
    <div class="form-group"><label>已选物料</label>
        <div class="inv-batch-hint">已选 <span id="batchThrCount" class="inv-batch-count">0</span> 项</div>
    </div>
    <div class="form-group"><label>安全库存阈值</label>
        <input type="number" id="batchThrValue" min="0" max="999999" step="1" placeholder="低于该数量触发低库存预警" required>
        <div class="form-hint">设为 0 表示不预警；低于阈值将在工作台低库存预警中提示</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchThreshold')">取消</button>
        <button type="button" class="btn btn-primary" onclick="submitBatchThreshold()">确认设置</button>
    </div>
</div></div>

<!-- 合并重复物料向导 Modal（P10：三步向导 + 强制二次确认） -->
<div class="overlay" id="modalMergeWizard">
<div class="modal inv-modal-wide">
    <h3>🔀 合并重复物料</h3>

    <!-- 步骤1：疑似重复组列表 -->
    <div id="mergeStep1">
        <div class="form-group">
            <div class="inv-merge-search">
                <input type="text" id="mergeSearch" placeholder="按型号搜索重复组" class="inv-input">
                <button type="button" class="btn btn-ghost btn-sm" onclick="MergeWizard.loadGroups()">🔍 查询</button>
            </div>
        </div>
        <div id="mergeGroupList" class="inv-scroll-box inv-merge-list">
            <div class="inv-loading-pad">加载中...</div>
        </div>
    </div>

    <!-- 步骤2：确认合并（预览 + 二次确认） -->
    <div id="mergeStep2" class="inv-merge-step2">
        <div id="mergePreview" class="inv-scroll-box inv-merge-preview"></div>
        <div class="inv-warn-box">
            <strong>⚠ 高风险操作提醒：</strong>合并后源物料将软删（流水/库存/分类/BOM 引用并入主物料），<strong>建议先执行数据备份</strong>。
            <a href="index.php?v=export" target="_blank" class="u-underline">→ 前往数据导出中心</a>
        </div>
        <label class="inv-check-row">
            <input type="checkbox" id="mergeConfirmChk"> 我已知晓合并操作不可自动撤销，并已确认主物料选择
        </label>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="MergeWizard.backToStep1()">← 上一步</button>
            <button type="button" class="btn btn-danger" id="mergeExecBtn" onclick="MergeWizard.execute()" disabled>确认合并</button>
        </div>
    </div>

    <div class="modal-footer" id="mergeStep1Footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalMergeWizard')">关闭</button>
    </div>
</div></div>

<!-- 批量加入 BOM Modal（管理员可见） -->
<?php if(isAdmin()): ?>
<div class="overlay" id="modalBatchBom">
<div class="modal modal-sm">
    <h3>批量加入 BOM 项目</h3>
    <div class="form-group"><label>已选物料</label>
        <div class="inv-batch-hint">已选 <span id="batchBomCount" class="inv-batch-count">0</span> 项，重复项将自动跳过</div>
    </div>
    <div class="form-group"><label>选择 BOM 项目</label>
        <?php

              ?>
        <select id="batchBomProject" required class="inv-fg-dense">
            <option value="">加载中...</option>
        </select>
        <div class="form-hint">如需新建 BOM 项目，请前往 <a href="index.php?v=bom" class="u-underline">BOM 管理页面</a></div>
    </div>
    <div class="form-group"><label>添加数量（每项）</label>
        <input type="number" id="batchBomQty" value="1" min="1" max="9999" class="inv-fg-dense">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchBom')">取消</button>
        <button type="button" class="btn btn-primary" onclick="confirmBatchAddToBom()">确认添加</button>
    </div>
</div></div>
<?php endif; ?>

<!-- 移动端：分类筛选底部抽屉（两级联动+双模式） -->
<div class="m-cat-overlay" id="mCatOverlay" onclick="closeMCatDrawer()"></div>
<div class="m-cat-drawer" id="mCatDrawer">
    <!-- 顶部拖拽条 -->
    <div class="m-cat-handle"></div>
    <!-- 标题栏 -->
    <div class="m-cat-header">
        <span class="m-cat-title">分类筛选</span>
        <button type="button" class="m-cat-mode-btn" id="mCatModeBtn" onclick="toggleMCatMode()">批量多选</button>
        <button type="button" class="m-cat-clear-btn" id="mCatClearBtn" onclick="mCatClear()" disabled>清空筛选</button>
    </div>
    <!-- 搜索框 -->
    <div class="m-cat-search-wrap">
        <input type="text" class="m-cat-search" id="mCatSearch" placeholder="搜索分类名称..." oninput="mCatSearchFilter(this.value)">
    </div>
    <!-- 一级类目栏（横向滚动） -->
    <div class="m-cat-top-row" id="mCatTopRow">
        <div class="m-cat-top-scroll" id="mCatTopScroll"></div>
    </div>
    <!-- 二级分类展示区（纵向滚动） -->
    <div class="m-cat-sub-area" id="mCatSubArea">
        <div class="m-cat-sub-list" id="mCatSubList"></div>
    </div>
    <!-- 底部操作栏（仅多选模式显示） -->
    <div class="m-cat-footer is-hidden" id="mCatFooter">
        <div class="m-cat-footer-ops">
            <button type="button" class="btn btn-ghost btn-sm" onclick="mCatSelectAll()">全选</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="mCatInvert()">反选</button>
        </div>
        <div class="m-cat-footer-btns">
            <button type="button" class="btn btn-ghost btn-sm" onclick="cancelMCatMulti()">取消</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="applyMCatMulti()">应用筛选</button>
        </div>
    </div>
</div>

<!-- 详情抽屉 -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-header">
        <span id="drawerTitle" class="inv-drawer-title"></span>
        <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="empty-state"><div class="icon">⏳</div>加载中...</div>
    </div>
</div>

<!-- 商品类型下拉联想（datalist：全部二级分类，新增/编辑表单共用） -->
<datalist id="ptype_list"><?php foreach($ptypeCats as $cn): ?><option value="<?=h($cn)?>"><?php endforeach; ?></datalist>

<!-- 添加元件 Modal -->
<div class="overlay" id="modalAdd">
<div class="modal inv-modal-wide">
    <h3>＋ 添加元件</h3>
    <form method="post" action="api.php" id="addForm">
    <input type="hidden" name="action" value="parts.create">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">

    <div class="form-sec"><span class="sec-ic">📋</span>基础信息</div>
    <div class="form-grid2">
        <div class="form-group"><label>平台 <span class="q-tip">?<span>标准商城用「编号跳转模板」点击商品编号直达商品页；散货渠道改为逐条记录采购链接</span></span></label>
            <select name="platform_id" id="a_platform" onchange="onAddPlatformChange()">
                <?php foreach($allPlats as $pl): ?>
                <option value="<?=h((string)$pl['id'])?>" data-type="<?=h($pl['platform_type']??'standard')?>" <?=($pl['is_default']??0)?'selected':''?>><?=h($pl['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="a_ppn_group"><label>商品编号 <span id="a_ppn_req" class="req-mark">*</span> <span class="q-tip">?<span>平台商品编号，同一平台内唯一；散货渠道可留空，留空时自动生成「#内部ID内部」编号</span></span></label>
            <input name="platform_part_no" id="a_ppn" placeholder="C123456">
            <span class="fld-err" id="a_ppn_err"></span>
        </div>
        <div class="form-group"><label>型号 <span class="req-mark">*</span></label><input name="model" id="a_model" placeholder="LM358" required></div>
        <div class="form-group"><label>品牌</label><input name="brand"></div>
        <div class="form-group"><label>商品名称 <span class="q-tip">?<span>规格描述，列表中显示在型号下方</span></span></label><input name="product_name" id="a_pname"></div>
        <div class="form-group"><label>封装</label><input name="package" id="a_pkg" placeholder="SOP-8"></div>
        <div class="form-group"><label>商品类型 <span class="req-mark">*</span> <span class="q-tip">?<span>所属二级分类，如「集成电路」；用于分类筛选与统计</span></span></label><input name="product_type" id="a_ptype" list="ptype_list" required placeholder="集成电路" autocomplete="off"></div>
        <div class="form-group"><label>客户料号</label><input name="customer_part_no"></div>
    </div>

    <div class="form-sec"><span class="sec-ic">📦</span>库存配置</div>
    <div class="form-grid2">
        <div class="form-group"><label>初始库存</label><input name="stock" id="a_stock" type="number" value="0" min="0"></div>
        <div class="form-group"><label>低库存阈值 <span class="q-tip">?<span>低于该值时列表标红预警；留空则继承分类／全局阈值</span></span></label><input name="low_stock_threshold" type="number" placeholder="留空=继承全局" min="0"></div>
        <div class="form-group fg-stack inv-fg-full"><label>库位/描述 <span class="q-tip">?<span>下拉选择库位树，选中后自动填入完整路径；也可直接手动输入文本</span></span></label>
            <div class="loc-pair">
            <?php if (hasFeature('location_manage')): ?>
            <select class="loc-picker" id="a_locpicker" data-text="a_loc" aria-label="从库位树选择"></select>
            <input type="hidden" name="location_id" class="loc-picker-id" value="">
            <?php endif; ?>
            <input name="location" id="a_loc" class="loc-pair-text" list="locTextList" placeholder="抽屉A1（可直接输入或从左侧选择）"></div>
            <?php if (hasFeature('location_manage')): ?><div class="form-hint">左侧下拉按库位树选择，右侧文本框同步为完整路径并可微调；不选则纯手动输入</div><?php endif; ?></div>
    </div>

    <div class="form-sec"><span class="sec-ic">💰</span>采购与成本</div>
    <div class="form-grid2">
        <?php if (\App\Core\Edition::canViewCost()): ?>
        <div class="form-group" id="a_unit_cost_group"><label>入库采购单价 <span class="q-tip">?<span>含税单价，资产核算依据；初始入库时将计入物料累计资产成本</span></span></label><input name="unit_cost" id="a_unit_cost" type="number" step="0.0001" min="0" value="0" placeholder="0.0000"></div>
        <div class="form-group fg-stack" id="a_sample_group"><label class="form-check-label">
            <input type="checkbox" name="is_sample" value="1" id="a_is_sample"> 样品不计资产
            <span class="q-tip">?<span>勾选后该物料的入库金额不计入资产总额</span></span>
        </label></div>
        <?php endif; ?>
        <div class="form-group loose-field" id="a_purl_group"><label>采购链接 <span class="q-tip">?<span>淘宝／1688 等非标平台商品链接；散货渠道必填，标准商城不适用</span></span></label>
            <input name="purchase_url" id="a_purl" placeholder="https://item.taobao.com/..." onpaste="setTimeout(extractTitleFromUrl,100)">
            <span class="fld-err" id="a_purl_err"></span>
        </div>
        <div class="form-group fg-stack loose-field" id="a_lpid_group"><label>关联标准物料 <span class="q-tip">?<span>散货关联立创等标准物料，复用其参数信息</span></span></label>
            <div class="fg-inline">
                <input name="linked_part_id" id="a_lpid" type="hidden">
                <button type="button" class="btn btn-ghost btn-sm" onclick="openPartPicker('linked','a')">🔍 选择物料</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="clearLinkedPart('a')">清除</button>
            </div>
            <div id="a_lp_result" class="fg-result"></div>
        </div>
    </div>

    <div class="form-sec"><span class="sec-ic">📝</span>其他信息</div>
    <div class="form-grid2">
        <div class="form-group"><label>数据手册链接 <span class="q-tip">?<span>站外数据手册地址（http/https）。详情页会显示为「站外手册链接」，点击在新窗口打开（不做页内预览）。留空则只能靠上传 PDF 附件</span></span></label>
            <input name="datasheet" id="a_datasheet" placeholder="https://example.com/datasheet.pdf">
        </div>
    </div>
    <div class="form-group"><label>备注</label><textarea name="remark" placeholder="选填"></textarea></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalAdd')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
    </form>
</div></div>

<!-- 编辑元件 Modal -->
<div class="overlay" id="modalEdit">
<div class="modal inv-modal-wide">
    <h3>编辑元件</h3>
    <form method="post" action="api.php" id="editForm">
    <input type="hidden" name="action" value="parts.update">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="e_id">

    <div class="form-sec"><span class="sec-ic">📋</span>基础信息</div>
    <div class="form-grid2">
        <div class="form-group" id="e_ppn_group"><label>商品编号 <span id="e_ppn_req" class="req-mark">*</span> <span class="q-tip">?<span>平台商品编号，同一平台内唯一；散货渠道可留空</span></span></label><input name="platform_part_no" id="e_ppn"><span class="fld-err" id="e_ppn_err"></span></div>
        <div class="form-group"><label>客户料号</label><input name="customer_part_no" id="e_cpn"></div>
        <div class="form-group"><label>型号 <span class="req-mark">*</span></label><input name="model" id="e_model"></div>
        <div class="form-group"><label>品牌</label><input name="brand" id="e_brand"></div>
        <div class="form-group"><label>商品名称 <span class="q-tip">?<span>规格描述，列表中显示在型号下方</span></span></label><input name="product_name" id="e_pname"></div>
        <div class="form-group"><label>封装</label><input name="package" id="e_pkg"></div>
        <div class="form-group"><label>商品类型 <span class="q-tip">?<span>所属二级分类，如「集成电路」；用于分类筛选与统计</span></span></label><input name="product_type" id="e_ptype" list="ptype_list" autocomplete="off"></div>
    </div>

    <div class="form-sec"><span class="sec-ic">📦</span>库存配置</div>
    <div class="form-grid2">
        <div class="form-group fg-stack inv-fg-full"><label>库位/描述 <span class="q-tip">?<span>下拉选择库位树，选中后自动填入完整路径；也可直接手动输入文本</span></span></label>
            <div class="loc-pair">
            <?php if (hasFeature('location_manage')): ?>
            <select class="loc-picker" id="e_locpicker" data-text="e_loc" aria-label="从库位树选择"></select>
            <input type="hidden" name="location_id" class="loc-picker-id" value="">
            <?php endif; ?>
            <input name="location" id="e_loc" class="loc-pair-text" list="locTextList" placeholder="抽屉A1（可直接输入或从左侧选择）"></div>
            <?php if (hasFeature('location_manage')): ?><div class="form-hint">左侧下拉按库位树选择，右侧文本框同步为完整路径并可微调；不选则纯手动输入</div><?php endif; ?></div>
        <div class="form-group"><label>低库存阈值 <span class="q-tip">?<span>低于该值时列表标红预警；留空则继承分类／全局阈值</span></span> <span id="e_thr_hint" class="label-hint"></span></label><input name="low_stock_threshold" id="e_thr" type="number" placeholder="留空=继承" min="0"></div>
    </div>

    <?php if (\App\Core\Edition::canViewCost()): ?>
    <div class="form-sec"><span class="sec-ic">💰</span>成本资产<span class="sec-note">只读，由入库流水自动核算</span></div>
    <div class="form-grid2">
        <div class="form-group"><label>物料累计资产总额 <span class="q-tip">?<span>所有有效入库流水（含税）小计之和；「样品不计资产」的入库记录不计入</span></span></label><input id="e_total_asset" type="text" value="¥0.00" readonly class="cost-readonly"></div>
        <div class="form-group"><label>最新采购含税单价 <span class="q-tip">?<span>最近一次入库流水中的采购单价，用于资产估值与成本核算口径</span></span></label><input id="e_latest_cost" type="text" value="—" readonly class="cost-readonly mono"></div>
    </div>
    <?php endif; ?>

    <div class="form-sec"><span class="sec-ic">🔗</span>其他信息</div>
    <div class="form-grid2">
        <div class="form-group loose-field" id="e_purl_group"><label>采购链接 <span class="q-tip">?<span>淘宝／1688 等非标平台商品链接；散货渠道必填，标准商城不适用</span></span></label>
<input name="purchase_url" id="e_purl" placeholder="https://item.taobao.com/...">
<span class="fld-err" id="e_purl_err"></span>
        </div>
        <div class="form-group loose-field" id="e_lpid_group"><label>关联标准物料 <span class="q-tip">?<span>散货关联立创等标准物料，复用其参数信息</span></span></label>
<div class="fg-inline">
<input name="linked_part_id" id="e_lpid" type="hidden">
<button type="button" class="btn btn-ghost btn-sm" onclick="openPartPicker('linked','e')">🔍 选择物料</button>
<button type="button" class="btn btn-ghost btn-sm" onclick="clearLinkedPart('e')">清除</button>
</div>
<div id="e_lp_result" class="fg-result"></div>
        </div>
        <div class="form-group"><label>数据手册链接 <span class="q-tip">?<span>站外数据手册地址（http/https）。详情页会显示为「站外手册链接」，点击在新窗口打开（不做页内预览）。留空只能靠上传 PDF 附件；此处留空保存不会清掉已上传的手册</span></span> <span class="label-hint" id="e_datasheet_hint"></span></label>
<input name="datasheet" id="e_datasheet" placeholder="https://example.com/datasheet.pdf">
<input type="hidden" name="datasheet_clear" id="e_datasheet_clear" value="">
<div class="fg-inline"><button type="button" class="btn btn-ghost btn-sm" id="e_datasheet_clear_btn" onclick="clearDatasheetUrl()">清除链接</button></div>
<span class="fld-err" id="e_datasheet_err"></span>
        </div>
    </div>

    <div class="form-sec"><span class="sec-ic">🔁</span>替代料<span class="sec-note">双向互绑：保存后对方物料中也同步映射本料</span></div>
    <div class="tip-box">🔁 已绑定的替代料在「添加替代料」弹窗中自动置灰并标注「已添加」</div>
    <input name="alternatives" id="e_alts" type="hidden">
    <div id="e_alt_list"></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="openPartPicker('alt','e')" class="inv-alt-add">＋ 添加替代料</button>

    <div class="form-sec"><span class="sec-ic">📝</span>备注</div>
    <div class="form-group"><textarea name="remark" id="e_rem" placeholder="选填"></textarea></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalEdit')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
    </form>
</div></div>

<!-- ════════ 物料选择弹窗（关联标准物料 / 替代料共用）════════ -->
<div class="overlay" id="modalPartPick">
<div class="modal inv-modal-xwide">
    <h3 id="ppTitle">选择物料</h3>
    <div class="form-group">
        <label>搜索物料（实时匹配 内部编号 / 型号 / 名称 / 品牌 / 封装）</label>
        <input type="text" id="ppSearchInput" placeholder="输入关键词，支持 P00001 或 #1 精确匹配内部编号" autocomplete="off" oninput="ppOnSearch()">
    </div>
    <div id="ppResultBox" class="pp-result-box">
        <div id="ppHead" class="pp-head"></div>
        <div id="ppResultList"></div>
        <div id="ppEmpty" class="pp-empty">
            <div class="pp-empty-ico">🔍</div>
            <div id="ppEmptyText">输入关键词开始搜索<br>支持型号 / 内部编号 / 名称 / 品牌 / 封装模糊匹配</div>
        </div>
        <div id="ppLoading" class="pp-loading">加载中…</div>
    </div>
    <div id="ppSelHint" class="pp-sel-hint"></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="ppClose()">取消</button>
        <button type="button" class="btn btn-primary" id="ppConfirmBtn" onclick="ppConfirm()">确定</button>
    </div>
</div>
</div>

<!-- 出入库 Modal -->
<div class="overlay" id="modalStock">
<div class="modal modal-sm">
    <h3>出入库操作</h3>
    <div class="inv-stk-bar">
        <span id="stkName" class="inv-stk-name"></span>
        <span id="stkPpn" class="inv-stk-ppn"></span>
    </div>
    <form method="post" action="api.php" id="stockForm">
    <input type="hidden" name="action" value="stock.change">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="s_id">
    <div class="form-group"><label>操作类型</label>
        <select name="change_type" id="changeType" onchange="onChangeType()">
            <option value="manual_in">入库（增加良品）</option>
            <option value="manual_out">出库（减少良品）</option>
            <option value="adjust">盘点调整（直接设定数量）</option>
            <option value="damaged">报损（良品 → 不良品）</option>
            <option value="repair">修复（不良品 → 良品）</option>
        </select>
    </div>
    <div class="form-group">
        <label>数量</label>
        <div class="qty-wrap">
            <div class="qty-stepper">
                <button type="button" onclick="qtyStep(-1)" title="减少">−</button>
                <input name="qty" id="s_qty" type="number" min="1" value="1" oninput="onQtyChange()">
                <button type="button" onclick="qtyStep(1)" title="增加">＋</button>
            </div>
        </div>
        <span class="fld-err" id="s_qty_err"></span>
    </div>
    <p class="inv-stock-note">
        当前库存：<span id="s_cur" class="inv-em-accent"></span>
        <span id="s_damaged" class="inv-em-purple is-hidden"></span>
    </p>
    <?php if (hasFeature('location_manage')): ?>
    <div class="form-group">
        <label>目标库位 <span class="inv-label-note">（默认带出物料绑定库位，可修改）</span></label>
        <select class="loc-picker" id="s_locpicker" data-text="s_loc"></select>
        <input type="hidden" name="stock_location_id" class="loc-picker-id" value="">
        <input type="hidden" id="s_orig_locid" value="">
    </div>
    <?php endif; ?>
    <?php if (\App\Core\Edition::canViewCost()): ?>
    <!-- 价格分层：入库时填写采购成本和样品标记 -->
    <div id="s_cost_group" class="inv-cost-group">
        <div class="form-group"><label>入库采购单价 <span class="q-tip">?<span>本次入库单价将计入资产成本与最新采购单价</span></span></label><input name="unit_cost" id="s_unit_cost" type="number" step="0.0001" min="0" value="0" placeholder="0.0000"></div>
        <div class="form-group"><label class="inv-check-row-sm">
            <input type="checkbox" name="is_sample" value="1" id="s_is_sample" class="inv-cb-auto"> 样品不计资产
        </label></div>
    </div>
    <?php endif; ?>
    <div class="form-group"><label>备注</label>
        <input name="remark" id="s_remark" placeholder="用途/项目...">
        <div class="chips">
            <span class="chip" onclick="fillRemark('BOM出库')">BOM出库</span>
            <span class="chip" onclick="fillRemark('采购入库')">采购入库</span>
            <span class="chip" onclick="fillRemark('盘点调整')">盘点调整</span>
            <span class="chip" onclick="fillRemark('样品领用')">样品领用</span>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalStock')">取消</button>
        <button type="button" class="btn btn-primary" id="stockSubmitBtn" onclick="submitStock()">确认</button>
    </div>
    </form>
</div></div>

<!-- 删除确认 Modal -->
<div class="overlay" id="modalDel">
<div class="modal modal-sm">
    <h3>确认删除</h3>
    <p id="delMsg" class="inv-del-msg"></p>
    <form method="post" action="api.php" id="deleteForm">
    <input type="hidden" name="action" value="parts.delete">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="d_id">
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalDel')">取消</button>
        <button type="submit" class="btn btn-danger">确认删除</button>
    </div>
    </form>
</div></div>

<!-- 打印配置 Modal（分层打印：先配置，后预览确认） -->
<div class="overlay" id="modalPrintConfig">
<div class="modal">
    <h3>🖨 打印标签配置</h3>
    <div id="printSelectedInfo" class="inv-print-info"></div>

    <div class="form-sec"><span class="sec-ic">🏷️</span>标签模板</div>
    <div class="print-fields inv-print-fields-2">
        <label><input type="radio" name="printTpl" value="mini" onchange="onPrintTplChange()"> 迷你标签<span class="inv-print-tpl-note">（小巧省纸）</span></label>
        <label><input type="radio" name="printTpl" value="default" checked onchange="onPrintTplChange()"> 标准标签<span class="inv-print-tpl-note">（信息完整）</span></label>
    </div>

    <div class="form-sec"><span class="sec-ic">✓</span>打印字段</div>
    <div class="print-fields" id="printFieldsGroup">
        <label><input type="checkbox" name="pf_model" value="1" checked> 型号</label>
        <label><input type="checkbox" name="pf_part_no" value="1" checked> 商品编号</label>
        <label><input type="checkbox" name="pf_stock" value="1" checked> 库存</label>
        <label><input type="checkbox" name="pf_location" value="1" checked> 库位</label>
        <label><input type="checkbox" name="pf_qrcode" value="1" checked> 二维码</label>
        <label><input type="checkbox" name="pf_category" value="1" checked> 分类</label>
        <label><input type="checkbox" name="pf_brand" value="1" checked> 品牌</label>
    </div>

    <div class="form-sec"><span class="sec-ic">🔄</span>标签用途与数量</div>
    <div class="form-group">
        <label>标签用途</label>
        <div class="inv-tpl-row">
            <label class="inv-tpl-label">
                <input type="radio" name="printLabelType" value="in" checked onchange="onPrintLabelTypeChange()"> 入库标签
            </label>
            <label class="inv-tpl-label">
                <input type="radio" name="printLabelType" value="out" onchange="onPrintLabelTypeChange()"> 出库标签
            </label>
        </div>
    </div>
    <div class="form-group">
        <label>打印数量 <span class="inv-req-star">*</span> <span id="printQtyHint" class="inv-label-note"></span></label>
        <div class="inv-print-qty-row">
            <input type="number" id="printQty" min="1" value="1" class="inv-input" oninput="onPrintQtyInput()">
            <button type="button" class="btn btn-ghost btn-sm" onclick="fillAllStock()" id="fillAllStockBtn">填充全部库存</button>
        </div>
        <div id="printQtyError" class="inv-qty-error"></div>
        <label id="batchAllStockRow" class="inv-print-each-row">
            <input type="checkbox" id="printEachStock" value="1" onchange="onPrintEachStockChange()" class="inv-cb-auto"> 每个物料打印数量 = 当前库存数量
        </label>
    </div>

    <div class="form-sec"><span class="sec-ic">📝</span>备注</div>
    <div class="form-group">
        <input type="text" id="printRemark" maxlength="30" placeholder="打印在标签上，选填">
        <div class="chips">
            <span class="chip" onclick="fillPrintRemark('批次号')">批次号</span>
            <span class="chip" onclick="fillPrintRemark('订单号')">订单号</span>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalPrintConfig')">取消</button>
        <button type="button" class="btn btn-primary" onclick="openPrintPreview()">预览打印</button>
    </div>
</div></div>

<!-- 打印预览 Modal（先预览，确认后发送打印） -->
<div class="overlay" id="modalPrintPreview">
<div class="modal modal-lg">
    <h3>🖨 打印预览</h3>
    <div id="ppPreviewMeta" class="inv-pp-preview-meta"></div>
    <iframe id="ppPreviewFrame" class="preview-frame" src="about:blank"></iframe>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalPrintPreview')">返回修改</button>
        <button type="button" class="btn btn-success" onclick="confirmPrint()">确认打印</button>
    </div>
</div></div>

<script src="modules/location/Assets/js/location-picker.js?v=<?=filemtime(__DIR__.'/../../location/Assets/js/location-picker.js')?>"></script>
<!-- JS 配置注入 + 外部脚本（配置注入必须先于 parts-*.js 加载） -->
<script>
window.PartsPageConfig = <?= json_encode([
    'q'              => (string)($q ?? ''),
    'filter'         => (string)($filter ?? ''),
    'cat'            => (string)($catParam ?? ''),
    'cats'           => (string)($_GET['cats'] ?? ''),
    'dimLabels'      => $dimLabels,
    'plat'           => (int)($platId ?? 0),
    'loc'            => (string)($locFilter ?? ''),
    'page'           => (int)($page ?? 1),
    'perPage'        => (int)($perPage ?? 25),
    'platMeta'       => array_column(array_map(
        static fn(array $pl): array => ['id' => (int)$pl['id'], 'type' => $pl['platform_type'] ?? 'standard', 'url' => $pl['url_template'] ?? ''],
        $allPlats
    ), null, 'id'),
    'globalThr'      => (int)$globalThr,
    'cols'           => $listColCount,   // 桌面端列数，唯一来源见视图上方 $listCols
    'showImages'     => $showImages,     // 图片开关（站点级设置）：JS 重绘 tbody 时据此决定是否出图片格
    'viewMode'       => (string)($_GET['view'] ?? $_COOKIE['lcsc_view_mode'] ?? 'comfy'), // 舒适 / 紧凑

    'chartUrl'       => 'third_party/js/chart.umd.js?v=' . APP_VERSION . '.' . (is_file(__DIR__ . '/../../../third_party/js/chart.umd.js') ? filemtime(__DIR__ . '/../../../third_party/js/chart.umd.js') : '0'),
    'isAdmin'        => isAdmin(),
    'canEdit'        => hasPermission('can_edit'),
    'canDelete'      => hasPermission('can_delete'),
    'noCatCount'     => (int)($noCatCount ?? 0),
    'topCats'        => json_decode((string)($topCatsJson ?: '[]'), true) ?: [],
    'subCats'        => json_decode((string)($subCatsJson ?: '[]'), true) ?: [],
    'unboundSubCats' => json_decode((string)($unboundSubCatsJson ?: '[]'), true) ?: [],

], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php
// 物料列表页脚本：按功能域拆为 7 个文件。
// **加载顺序不可调换**：core 定义全局筛选状态，其余文件依赖它；init 绑定各表单，
// 依赖前面 5 个文件定义的全部函数，必须最后加载。

$partsJsBase = 'modules/inventory/Assets/js/';
$partsJsFiles = ['parts-core', 'parts-list', 'parts-filter', 'parts-modal', 'parts-drawer', 'parts-attachment', 'parts-batch', 'parts-init'];
foreach ($partsJsFiles as $pj) {
    $pjPath = __DIR__ . '/../Assets/js/' . $pj . '.js';
    echo '<script src="' . h($partsJsBase . $pj . '.js') . '?v='
        . h(APP_VERSION . '.' . filemtime($pjPath)) . '"></script>' . "\n";
}
?>

<!-- 回到顶部悬浮按钮 -->
<button type="button" class="to-top-btn" id="toTopBtn" onclick="scrollToTopBtn()" title="回到顶部" aria-label="回到顶部">↑</button>

</body></html>
