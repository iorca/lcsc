<?php
declare(strict_types=1);
/** 分类管理页面 */
require_once __DIR__ . '/../../../config.php';

use App\Core\AssetManager;
use Modules\Category\Controller\CategoryController;

$user = requireLogin();
$db   = getDB();
$uid  = (int)$user['id'];
$dataUid = getDataUserId();

try {
    $viewData = CategoryController::prepareViewData($db, $uid, $dataUid, $_GET);
} catch (\Throwable $e) {
    error_log('CategoryController prepareViewData: ' . $e->getMessage());
    $viewData = CategoryController::emptyViewData();
}
extract($viewData);

$items        = $list['items'];
$totalAll     = (int)$list['total_all'];
$unboundCount = (int)$list['unbound_count'];
$page         = (int)$list['page'];
$perPage      = (int)$list['per_page'];
$totalPage    = (int)$list['total_page'];
$total        = (int)$list['total'];
$topCats      = $list['top_cats'];
$allCats      = $list['all_cats'];

/** 分类层级缩进：按 depth 输出前缀（最深 5 级），让树形层级在列表中直观可见 */
function catIndentHtml(int $depth): string {
    $level = max(0, min(4, $depth - 2));
    $html = '<span class="cat-indent" aria-hidden="true">';
    for ($i = 0; $i < $level; $i++) $html .= '<i class="cat-indent-step"></i>';
    return $html . '</span>';
}

/** 构建「上级分类」下拉的分组选项 */
function catParentOptionsHtml(array $topCats, array $allCats): string {
    $byTop = [];
    $unbound = [];
    foreach ($allCats as $c) {
        $pid = (int)$c['parent_id'];
        if ($pid > 0) $byTop[$pid][] = $c;
        else $unbound[] = $c;
    }
    $option = function(int $id, string $name, int $depth): string {
        return '<option value="' . $id . '" data-path="' . h($name) . '" data-depth="' . $depth . '">'
            . catIndentHtml($depth) . h($name) . '</option>';
    };

    $html = '<option value="0" data-path="" data-depth="0">— 无（顶级分类）—</option>';
    foreach ($topCats as $tc) {
        $tid = (int)$tc['id'];
        $tname = (string)$tc['name'];
        $inner = $option($tid, $tname, 1);   // 大类本身可作为上级
        foreach ($byTop[$tid] ?? [] as $c) {
            $inner .= $option((int)$c['id'], (string)$c['name'], (int)($c['depth'] ?? 2));
        }
        $html .= '<optgroup label="' . h($tname) . '">' . $inner . '</optgroup>';
    }
    if ($unbound) {
        $inner = '';
        foreach ($unbound as $c) {
            $inner .= $option((int)$c['id'], (string)$c['name'], (int)($c['depth'] ?? 2));
        }
        $html .= '<optgroup label="未归类">' . $inner . '</optgroup>';
    }
    return $html;
}

/** 「批量设置上级」专用：只列一级大类 + 解除绑定 */
function catTopOnlyOptionsHtml(array $topCats): string {
    $html = '<option value="0" data-path="" data-depth="0">— 无（解除绑定，回到未归类）—</option>';
    foreach ($topCats as $tc) {
        $html .= '<option value="' . (int)$tc['id'] . '" data-path="' . h((string)$tc['name']) . '" data-depth="1">'
            . h((string)$tc['name']) . '</option>';
    }
    return $html;
}

/** 「合并分类」目标联想列表：一级大类 + 全部细分类 */
function catMergeDatalistHtml(array $topCats, array $allCats): string {
    $html = '';
    foreach ($topCats as $tc) {
        $name = (string)$tc['name'];
        $html .= '<option value="' . h($name) . '" label="' . h($name) . '"></option>';
    }
    foreach ($allCats as $c) {
        $name = (string)$c['name'];
        $path = (string)($c['parent_path'] ?? '');
        $html .= '<option value="' . h($name) . '" label="' . h($path !== '' ? $path . ' / ' . $name : $name) . '"></option>';
    }
    return $html;
}

$parentOptionsHtml = catParentOptionsHtml($topCats, $allCats);
$topOnlyOptionsHtml = catTopOnlyOptionsHtml($topCats);
$mergeDatalistHtml  = catMergeDatalistHtml($topCats, $allCats);

$pageTitle = '分类管理';
$activePage = 'categories';

AssetManager::addCss('modules/category/Assets/css/categories.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>

<div class="main">
<div class="glass-box">
<?php if (($_GET['flash'] ?? '') === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif (($_GET['flash'] ?? '') === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php endif; ?>

<!-- 页头：标题 + 主操作 -->
<div class="page-header">
    <h2>分类管理</h2>
    <div class="page-desc">分类树最深 5 级；一级大类用于对导入生成的细分类分组归类，未归类的分类会单独统计</div>
    <?php if ($canManage): ?>
    <div class="toolbar">
        <button class="btn btn-ghost btn-sm" onclick="openTopCatModal()">大类管理</button>
        <button class="btn btn-primary btn-sm" onclick="openCatCreateModal()">＋ 新增分类</button>
    </div>
    <?php endif; ?>
</div>

<!-- 统计卡（可点击筛选：全部 / 仅一级大类 / 仅未归类） -->
<div class="stat-grid stat-grid-3" id="catStatGrid">
    <div class="stat-card stat-clickable c-blue<?=$filter==='all'?' filter-active':''?>" data-cat-filter="all" role="button" tabindex="0" title="显示全部分类">
        <div class="stat-label">分类总数</div>
        <div class="stat-value" id="catStatTotal"><?=$totalAll?></div>
    </div>
    <div class="stat-card stat-clickable c-purple<?=$filter==='top'?' filter-active':''?>" data-cat-filter="top" role="button" tabindex="0" title="只看一级大类">
        <div class="stat-label">一级大类</div>
        <div class="stat-value" id="catStatTop"><?=count($topCats)?></div>
    </div>
    <div class="stat-card stat-clickable c-yellow<?=$filter==='unbound'?' filter-active':''?>" data-cat-filter="unbound" role="button" tabindex="0" title="只看未归类分类">
        <div class="stat-label">未归类</div>
        <div class="stat-value" id="catStatUnbound"><?=$unboundCount?></div>
    </div>
</div>

<!-- 筛选栏：搜索 + 排序 -->
<div class="filter-bar">
    <div class="search-box<?=$q !== '' ? ' has-value' : ''?>" id="catSearchBox">
        <svg class="search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="catSearch" value="<?=h($q)?>" placeholder="搜索分类名称或上级分类…">
        <button type="button" class="cat-search-clear" id="catSearchClear" aria-label="清除搜索" title="清除搜索">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <select class="filter-select" id="catSort">
        <option value="cnt_desc"  <?=$sort==='cnt_desc'?'selected':''?>>元件数量：从多到少</option>
        <option value="cnt_asc"   <?=$sort==='cnt_asc'?'selected':''?>>元件数量：从少到多</option>
        <option value="name_asc"  <?=$sort==='name_asc'?'selected':''?>>名称：A → Z</option>
        <option value="name_desc" <?=$sort==='name_desc'?'selected':''?>>名称：Z → A</option>
    </select>
    <div class="filter-actions">
        <span class="page-info" id="catCountInfo"><?php if ($q !== ''): ?>匹配 <?=$total?> / <?=$totalAll?> 个分类<?php else: ?>共 <?=$totalAll?> 个分类<?php endif; ?></span>
    </div>
</div>

<!-- 分类列表 -->
<div class="table-wrap">
    <table class="data-table cat-list-table">
        <thead><tr>
            <th class="cb-col"><input type="checkbox" id="catCheckAll" title="全选本页"></th>
            <th>分类名称</th>
            <th>上级分类</th>
            <th class="num">元件数量</th>
            <th>低库存阈值</th>
            <th>主要库位</th>
            <th class="cat-actions-col">操作</th>
        </tr></thead>
        <tbody id="catTbody">
        <?php if (empty($items)): ?>
            <tr><td colspan="7">
                <div class="empty-state">
                    <div class="icon">🏷️</div>
                    <?php if ($q !== ''): ?>
                    <div class="es-title">没有匹配「<?=h($q)?>」的分类</div>
                    <div class="es-desc">试试更短的关键词，或按上级分类名搜索</div>
                    <?php else: ?>
                    <div class="es-title">还没有分类</div>
                    <div class="es-desc">导入订单时会自动生成细分类，也可手动新增</div>
                    <?php endif; ?>
                </div>
            </td></tr>
        <?php else: foreach ($items as $c):
            $cid      = (int)$c['id'];
            $cnt      = (int)$c['cnt'];
            $depth    = (int)$c['depth'];
            $isTop    = !empty($c['is_top']);
            $hasChild = (int)$c['child_cnt'] > 0;
            $path     = (string)$c['parent_path'];
            $locs     = $c['locations'] ?? [];
        ?>
        <tr data-cat-id="<?=$cid?>"
            data-cat-name="<?=h((string)$c['name'])?>"
            data-cat-depth="<?=$depth?>"
            data-cat-parent="<?=(int)$c['parent_id']?>"
            data-cat-path="<?=h($path)?>">
            <td class="cb-col"><input type="checkbox" class="cat-cb" value="<?=$cid?>" aria-label="选择分类 <?=h((string)$c['name'])?>"></td>
            <td>
                <div class="cat-name-cell">
                    <?=catIndentHtml($depth)?>
                    <span class="cat-name"><?=h((string)$c['name'])?></span>
                    <?php if ($isTop): ?>
                    <span class="cat-top-tag">一级大类</span>
                    <?php endif; ?>
                    <?php if ($hasChild): ?>
                    <span class="cat-sub-count" title="含 <?=(int)$c['child_cnt']?> 个子分类"><?=(int)$c['child_cnt']?> 子类</span>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <?php if ($isTop): ?>
                <span class="cat-parent-none">—</span>
                <?php elseif ($path !== ''): ?>
                <span class="cat-parent" title="<?=h($path)?>"><?=h($path)?></span>
                <?php else: ?>
                <span class="cat-parent-none">未归类</span>
                <?php endif; ?>
            </td>
            <td class="num"><?=$cnt?></td>
            <td>
                <?php if ($c['low_stock_threshold'] !== null): ?>
                <span class="badge badge-blue"><?=h((string)$c['low_stock_threshold'])?></span>
                <?php else: ?>
                <span class="cat-muted">继承全局</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (empty($locs)): ?>
                <span class="cat-muted">-</span>
                <?php else: ?>
                <span class="cat-loc" title="<?=h((string)$locs[0]['location'])?>"><?=h((string)$locs[0]['location'])?></span>
                <?php if (count($locs) > 1): ?>
                <span class="cat-loc-more" title="共 <?=count($locs)?> 个库位">+<?=count($locs) - 1?></span>
                <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="cat-actions-col">
                <?php if ($canManage): ?>
                <div class="actions">
                    <button class="btn btn-ghost btn-sm" onclick="openCatRenameModal(<?=$cid?>)">重命名</button>
                    <?php if (!$isTop): ?>
                    <button class="btn btn-ghost btn-sm" onclick="openCatMoveModal(<?=$cid?>)">移动</button>
                    <?php endif; ?>
                    <?php if ($isTop): ?>
                    <button class="btn btn-danger btn-sm" onclick="topCatDelete(<?=$cid?>,<?=h(json_encode((string)$c['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>)">删除</button>
                    <?php elseif (!$hasChild && $cnt === 0): ?>
                    <button class="btn btn-danger btn-sm" onclick="catDelete(<?=$cid?>)">删除</button>
                    <?php else: ?>
                    <button class="btn btn-ghost btn-sm is-disabled" disabled
                        title="<?=$hasChild ? '含 '.(int)$c['child_cnt'].' 个子分类，请先移出或删除子分类' : '仍关联 '.$cnt.' 个元件，请先移出物料'?>">删除</button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <span class="cat-muted">仅查看</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- 分页 -->
<?php if ($totalPage > 1 || $totalAll > 0): ?>
<div class="pagination" id="catPaginationArea">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$page?>" onkeydown="if(event.key==='Enter'){event.preventDefault();catGoPage(parseInt(this.value)||1);}"> 页</span>
    <a href="?per_page=<?=$perPage?>&page=<?=max(1,$page-1)?>" class="page-btn <?=$page<=1?'disabled':''?>" onclick="catGoPage(<?=max(1,$page-1)?>);return false;">‹</a>
    <?php
    $s = max(1,$page-2); $e = min($totalPage,$page+2);
    if($s>1) echo '<a href="?per_page='.$perPage.'&page=1" class="page-btn" onclick="catGoPage(1);return false;">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?per_page='.$perPage.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'" onclick="catGoPage('.$i.');return false;">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?per_page='.$perPage.'&page='.$totalPage.'" class="page-btn" onclick="catGoPage('.$totalPage.');return false;">'.$totalPage.'</a>';
    ?>
    <a href="?per_page=<?=$perPage?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>" onclick="catGoPage(<?=min($totalPage,$page+1)?>);return false;">›</a>
    <select onchange="catChangePerPage(this.value)" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

</div><!-- /glass-box -->
</div><!-- /main -->

<?php if ($canManage): ?>

<div class="batch-bar" id="catBatchBar">
    <span>已选 <span class="batch-count" id="catBatchCount">0</span> 项</span>
    <button class="btn btn-ghost btn-sm" onclick="openCatBindModal()">设置上级</button>
    <button class="btn btn-ghost btn-sm" onclick="openCatMergeModal()">合并分类</button>
    <?php if ($canBatch): ?>
    <button class="btn btn-ghost btn-sm" onclick="openCatThresholdModal()">设置阈值</button>
    <button class="btn btn-ghost btn-sm" onclick="openCatLocationModal()">设置库位</button>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" onclick="clearCatSelection()">取消选择</button>
</div>

<!-- 新增分类弹窗 -->
<?php \App\Components\Modal::begin(['id' => 'catCreateOverlay', 'title' => '新增分类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catCreateForm">
    <input type="hidden" name="action" value="category.create">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-group">
        <label>分类名称</label>
        <div class="cat-clear-wrap">
            <input name="name" id="catCreateName" required maxlength="50" placeholder="如：贴片电阻">
            <button type="button" class="cat-clear-btn" aria-label="清除分类名称" title="清除">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div class="form-group">
        <label>上级分类</label>
        <select name="parent_id" id="catCreateParent"><?=$parentOptionsHtml?></select>
        <div class="form-hint">分类最深 5 级，如：被动元件 → 电阻 → 贴片电阻 → 0402 封装</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catCreateOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">确定</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>

<!-- 重命名分类弹窗 -->
<?php \App\Components\Modal::begin(['id' => 'catRenameOverlay', 'title' => '重命名分类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catRenameForm">
    <input type="hidden" name="action" value="category.rename">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="catRenameId">
    <div class="form-group">
        <label>分类名称</label>
        <div class="cat-clear-wrap">
            <input name="name" id="catRenameInput" required maxlength="50">
            <button type="button" class="cat-clear-btn" aria-label="清除分类名称" title="清除">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catRenameOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>

<!-- 移动分类弹窗（单条改上级） -->
<?php \App\Components\Modal::begin(['id' => 'catMoveOverlay', 'title' => '移动分类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catMoveForm">
    <input type="hidden" name="action" value="category.move">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="catMoveId">
    <div class="cat-move-hint" id="catMoveHint"></div>
    <div class="form-group">
        <label>新的上级分类</label>
        <select name="parent_id" id="catMoveParent"><?=$parentOptionsHtml?></select>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catMoveOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">确定移动</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>

<!-- 批量设置上级弹窗 -->
<?php \App\Components\Modal::begin(['id' => 'catBindOverlay', 'title' => '批量设置上级分类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catBindForm">
    <input type="hidden" name="action" value="category.bind_parent">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="catBindIds"></div>
    <div class="form-group">
        <label>统一设置为</label>
        <select name="parent_id" id="catBindParent"><?=$topOnlyOptionsHtml?></select>
        <div class="form-hint">只列一级大类，避免细分类过多导致下拉过长；所选分类及其子树将一起挂到该大类之下，选「无」表示解除绑定</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catBindOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">确定</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>

<!-- 合并分类弹窗 -->
<?php \App\Components\Modal::begin(['id' => 'catMergeOverlay', 'title' => '合并分类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catMergeForm">
    <input type="hidden" name="action" value="category.merge">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="catMergeIds"></div>
    <div class="form-group">
        <label>合并到目标分类</label>
        <div class="cat-clear-wrap">
            <input type="text" id="catMergeTargetInput" list="catMergeTargetList" autocomplete="off"
                   placeholder="输入分类名称搜索，如：贴片电阻" required>
            <button type="button" class="cat-clear-btn" aria-label="清除目标分类" title="清除">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <input type="hidden" name="target_id" id="catMergeTarget" value="">
        <datalist id="catMergeTargetList"><?=$mergeDatalistHtml?></datalist>
        <div class="form-hint">输入关键词从联想列表中选择目标分类（避免分类过多时长时间翻找）；来源分类下的物料与子分类全部转入目标分类，来源分类本身将被删除</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catMergeOverlay')">取消</button>
        <button type="submit" class="btn btn-warning" onclick="return AdminUIConfirmSubmit(event, '确认合并？来源分类下的物料将全部转入目标分类，来源分类会被删除且不可恢复。', '确认合并')">确认合并</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>

<!-- 批量设置低库存阈值弹窗 -->
<?php if ($canBatch) {
    \App\Components\Modal::begin(['id' => 'catThrOverlay', 'title' => '批量设置低库存阈值', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catThrForm">
    <input type="hidden" name="action" value="category.batch_threshold">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="catThrIds"></div>
    <div class="form-group">
        <label>低库存阈值</label>
        <input name="threshold" id="catThrValue" type="number" min="0" value="10" required>
        <div class="form-hint">分类下物料库存 ≤ 该值时，在物料列表中标记为低库存</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catThrOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">确定</button>
    </div>
</form>
<?php \App\Components\Modal::end(false);
} ?>

<!-- 批量设置库位弹窗 -->
<?php if ($canBatch) {
    \App\Components\Modal::begin(['id' => 'catLocOverlay', 'title' => '批量设置库位', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="catLocForm">
    <input type="hidden" name="action" value="category.batch_set_location">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="catLocIds"></div>
    <div class="form-group">
        <label>库位</label>
        <input name="location" id="catLocValue" list="locTextList" placeholder="如：抽屉A1 / 货架B-2层" required>
        <div class="form-hint">将所选分类下的全部物料统一设置为该库位</div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('catLocOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">确定</button>
    </div>
</form>
<?php \App\Components\Modal::end(false);
} ?>


<?php \App\Components\Modal::begin([
    'id'    => 'topCatOverlay',
    'title' => '一级大类管理',
    'size'  => 'md',
]); ?>
<div class="cat-topcat-hint">一级大类用于对导入生成的细分类分组归类。删除大类不会删除其下细分类，只会将它们解除绑定。</div>

<form method="post" action="app/api/router.php" id="topCatForm" class="topcat-add-form">
    <input type="hidden" name="action" value="category.topcat_add">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="cat-clear-wrap">
        <input name="name" placeholder="输入大类名称，如：被动元件" required maxlength="50">
        <button type="button" class="cat-clear-btn" aria-label="清除大类名称" title="清除">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">添加大类</button>
</form>

<div id="topCatList">
<?php if ($unboundCount > 0): ?>
<!-- 系统默认「未归类」分组（只读，仅在有未归类细分类时显示） -->
<div class="topcat-item topcat-default">
    <span class="topcat-item-name">未归类</span>
    <span class="topcat-item-count"><?=$unboundCount?> 个分类</span>
    <span class="topcat-item-tag">系统默认</span>
</div>
<?php endif; ?>
<?php if (empty($topCats) && $unboundCount === 0): ?>
<div class="empty-state">
    <div class="icon">📁</div>
    <div class="es-title">还没有一级大类</div>
    <div class="es-desc">添加大类后，导入生成的细分类就能按类归类</div>
</div>
<?php else: foreach ($topCats as $tc):
    $tid = (int)$tc['id'];
    $childCnt = 0;
    foreach ($allCats as $c) { if ((int)$c['parent_id'] === $tid) $childCnt++; }
?>
<div class="topcat-item">
    <span class="topcat-item-name"><?=h((string)$tc['name'])?></span>
    <span class="topcat-item-count"><?=$childCnt?> 个分类</span>
    <button class="btn btn-ghost btn-xs" onclick="openTopCatRenameModal(<?=$tid?>,<?=h(json_encode((string)$tc['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>)">重命名</button>
    <button class="btn btn-danger btn-xs" onclick="topCatDelete(<?=$tid?>,<?=h(json_encode((string)$tc['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>)">删除</button>
</div>
<?php endforeach; endif; ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-ghost btn-sm" onclick="closeCatOverlay('topCatOverlay')">关闭</button>
</div>
<?php \App\Components\Modal::end(false); ?>

<!-- 重命名大类弹窗 -->
<?php \App\Components\Modal::begin(['id' => 'topCatRenameOverlay', 'title' => '重命名大类', 'size' => 'sm']); ?>
<form method="post" action="app/api/router.php" id="topCatRenameForm">
    <input type="hidden" name="action" value="category.topcat_rename">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="topCatRenameId">
    <div class="form-group">
        <label>大类名称</label>
        <div class="cat-clear-wrap">
            <input name="name" id="topCatRenameInput" required maxlength="50">
            <button type="button" class="cat-clear-btn" aria-label="清除大类名称" title="清除">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeCatOverlay('topCatRenameOverlay')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
</form>
<?php \App\Components\Modal::end(false); ?>
<?php endif; ?><!-- /canManage -->

<script>window.CategoriesPageConfig = <?= json_encode([
    'page'      => $page,
    'perPage'   => $perPage,
    'q'         => $q,
    'sort'      => $sort,
    'filter'    => $filter,
    'canManage' => $canManage,
    'canBatch'  => $canBatch,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="modules/location/Assets/js/location-picker.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../../location/Assets/js/location-picker.js')?>"></script>
<script src="modules/category/Assets/js/categories.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/categories.js')?>"></script>

</body></html>
