<?php
declare(strict_types=1);
/** partials/flow_finance.php — 流水中心 · 财务资产视图 */
?>
<!-- 筛选栏 -->
<form method="get" action="index.php" class="flow-toolbar" data-flow-filter data-skip-iframe-nav>
    <input type="hidden" name="v" value="stock">
    <input type="hidden" name="view" value="finance">
    <input name="keyword" type="text" class="flow-grow" placeholder="型号/编号/名称" value="<?=h($fKeyword)?>" autocomplete="off">
    <select name="cat_id" title="分类">
        <option value="0">全部分类</option>
        <?php foreach($categories as $c): ?>
        <option value="<?=$c['id']?>" <?=$fCatId===$c['id']?'selected':''?>><?=h($c['name'])?></option>
        <?php endforeach; ?>
    </select>
    <select name="plat_id" title="采购平台">
        <option value="0">全部平台</option>
        <?php foreach($platforms as $p): ?>
        <option value="<?=$p['id']?>" <?=$fPlatId===$p['id']?'selected':''?>><?=h($p['name'])?></option>
        <?php endforeach; ?>
    </select>
    <input name="date_from" type="date" value="<?=h($fDateFrm)?>" title="起始日期">
    <span class="flow-hint">至</span>
    <input name="date_to" type="date" value="<?=h($fDateTo)?>" title="结束日期">
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="index.php?v=stock&view=finance" class="btn btn-ghost btn-sm" data-flow data-skip-iframe-nav>重置</a>
    <?php if (hasPermission('can_export')): ?>
    <a href="?v=stock&view=finance&keyword=<?=urlencode($fKeyword)?>&cat_id=<?=$fCatId?>&plat_id=<?=$fPlatId?>&date_from=<?=$fDateFrm?>&date_to=<?=$fDateTo?>&export=1"
       class="btn btn-ghost btn-sm flow-export">📥 导出CSV</a>
    <?php endif; ?>
</form>

<!-- 数据表格 -->
<div class="table-wrap">
<table class="flow-table">
    <thead>
        <tr>
            <th>时间</th>
            <th>物料</th>
            <th>分类</th>
            <th>采购平台</th>
            <th>类型</th>
            <th class="num">变动</th>
            <th class="num">单价</th>
            <th class="num">含税小计</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($logs)): ?>
        <tr><td colspan="8"><div class="empty-state">暂无数据</div></td></tr>
    <?php else: foreach($logs as $l):
        [$lab, $col] = $typeLabel[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
        $isSmp  = (int)$l['is_sample'] === 1;
        $chg = (int)$l['qty_change'];

        $tagCls = match(strtolower((string)$col)) {
            '#4f8ef7' => 'is-blue', '#22c55e' => 'is-green', '#ef4444' => 'is-red',
            '#f59e0b' => 'is-amber', '#8b5cf6' => 'is-purple', default => 'is-muted',
        };
    ?>
        <tr<?= $isSmp ? ' class="flow-row-smp"' : '' ?>>
            <td class="flow-time"><?=h(substr((string)$l['create_time'],0,16))?></td>
            <td>
                <a class="flow-part" href="index.php?v=home&search=<?=urlencode($l['model'] ?: ($l['platform_part_no'] ?? ''))?>"><?=h($l['platform_part_no'] ?: '—')?></a>
                <div class="flow-time"><?=h($l['model'] ?: ($l['product_name'] ?? ''))?></div>
            </td>
            <td class="flow-time"><?=h($l['cat_names'])?></td>
            <td class="flow-td-text"><?=h($l['pname'] ?? '—')?></td>
            <td>
                <span class="flow-tag <?=$tagCls?>"><?=$lab?></span>
                <?php if($isSmp): ?><span class="flow-star" title="样品不计资产">★</span><?php endif; ?>
            </td>
            <td class="num"><span class="<?=$chg>0?'flow-chg-in':'flow-chg-out'?>"><?=($chg>0?'+':'').$chg?></span></td>
            <td class="num flow-mono"><?=$l['unit_cost']>0?'¥'.number_format((float)$l['unit_cost'],4):'—'?></td>
            <td class="num flow-mono flow-sum"><?=$l['subtotal']>0?'¥'.number_format((float)$l['subtotal'],2):'—'?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- 分页 -->
<?php if($totalPages > 1 || $total > 0): ?>
<?php
$qStr = http_build_query(array_filter([
    'keyword' => $fKeyword, 'cat_id' => $fCatId, 'plat_id' => $fPlatId,
    'date_from' => $fDateFrm, 'date_to' => $fDateTo, 'per_page' => $perPage,
], fn($v) => $v !== '' && $v !== 0));
$base = "index.php?v=stock&view=finance&" . $qStr;
?>
<div class="pagination" data-flow-base="<?=h($base)?>">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPages?>" value="<?=$page?>" data-flow-jump data-flow-base="<?=h($base)?>"> 页</span>
    <?php if($totalPages > 1): ?>
    <a href="<?=$base?>&page=<?=max(1,$page-1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s=max(1,$page-2);$e=min($totalPages,$page+2);
    if($s>1) echo '<a href="'.$base.'&page=1" data-flow data-skip-iframe-nav class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="'.$base.'&page='.$i.'" data-flow data-skip-iframe-nav class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPages-1) echo '<span class="page-info">…</span>';
    if($e<$totalPages) echo '<a href="'.$base.'&page='.$totalPages.'" data-flow data-skip-iframe-nav class="page-btn">'.$totalPages.'</a>';
    ?>
    <a href="<?=$base?>&page=<?=min($totalPages,$page+1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$page>=$totalPages?'disabled':''?>">›</a>
    <?php endif; ?>
    <span class="page-info">共 <?=$total?> 条</span>
</div>
<?php endif; ?>
