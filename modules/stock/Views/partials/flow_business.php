<?php
declare(strict_types=1);
/** partials/flow_business.php — 流水中心 · 业务流水视图 */

$typeLabels = [
    'scan_in' => '扫码入库', 'scan_out' => '扫码出库',
    'manual_in' => '手动入库', 'manual_out' => '手动出库',
    'import' => '订单导入', 'import_in' => '订单导入', 'adjust' => '库存调整',
    'damaged' => '报损', 'repair' => '修复',
    'scan_undo_in' => '撤销入库', 'scan_undo_out' => '撤销出库',
    'import_undo_out' => '导入回退',
    'bom_checkout' => 'BOM出库', 'bom_out' => 'BOM出库', 'batch_stock_in' => '批量入库',
    'checklist_adjust' => '盘点调整',
];

$typeColors = [
    'scan_in' => 'is-green', 'scan_out' => 'is-red',
    'manual_in' => 'is-green', 'manual_out' => 'is-red',
    'import' => 'is-blue', 'import_in' => 'is-blue', 'adjust' => 'is-amber',
    'damaged' => 'is-red', 'repair' => 'is-green',
    'scan_undo_in' => 'is-dim', 'scan_undo_out' => 'is-dim',
    'import_undo_out' => 'is-dim',
    'bom_checkout' => 'is-red', 'bom_out' => 'is-red', 'batch_stock_in' => 'is-green',
    'checklist_adjust' => 'is-amber',
];

$showReview = ($tab === 'undo' && hasPermission('can_edit'));
?>

<!-- 筛选工具条 -->
<form method="get" action="index.php" class="flow-toolbar" data-flow-filter data-skip-iframe-nav>
    <input type="hidden" name="v" value="stock">
    <input type="hidden" name="tab" value="<?=h($tab)?>">
    <input type="hidden" name="per_page" value="<?=h((string)$params['per_page'])?>">
    <input name="part_id" type="number" min="0" placeholder="物料ID" value="<?=h((string)($params['part_id'] ?: ''))?>">
    <input name="model" type="text" class="flow-grow" placeholder="型号 / 编号" value="<?=h($params['keyword'])?>" autocomplete="off">
    <input name="date_from" type="date" value="<?=h($params['date_from'])?>" title="起始日期">
    <span class="flow-hint">至</span>
    <input name="date_to" type="date" value="<?=h($params['date_to'])?>" title="结束日期">
    <button type="submit" class="btn btn-primary btn-sm" id="flowQueryBtn">查询</button>
    <?php if (hasPermission('can_export')): ?>
    <a href="?v=stock&tab=<?=$tab?>&part_id=<?=$params['part_id']?>&model=<?=urlencode($params['keyword'])?>&date_from=<?=$params['date_from']?>&date_to=<?=$params['date_to']?>&export=1"
       class="btn btn-ghost btn-sm flow-export">📥 导出CSV</a>
    <?php endif; ?>
    <?php if ($tab === 'undo' && hasPermission('can_edit')): ?>
    <?php ?>
    <button type="button" class="btn btn-ghost btn-sm" id="flowReviewAllBtn">✓ 复核本页</button>
    <?php endif; ?>
</form>

<!-- 数据表格 -->
<div class="table-wrap">
<table class="flow-table">
    <thead>
        <tr>
            <th>时间</th>
            <th>物料</th>
            <th>类型</th>
            <th class="num">变动</th>
            <th class="num">前→后</th>
            <th>备注</th>
            <?php if ($showReview): ?><th class="flow-review-col">复核</th><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($result['items'])): ?>
        <tr><td colspan="<?= $showReview ? 7 : 6 ?>">
            <div class="empty-state">暂无流水记录</div>
        </td></tr>
    <?php else: ?>
    <?php foreach($result['items'] as $row):
        $chg = (int)$row['qty_change'];
        $typeLabel = $typeLabels[$row['change_type']] ?? $row['change_type'];
    ?>
        <tr>
            <td class="flow-time"><?=h(substr((string)$row['create_time'], 0, 16))?></td>
            <td>
                <a class="flow-part" href="index.php?v=home&search=<?=urlencode($row['model'] ?? $row['platform_part_no'])?>"><?=h($row['model'] ?? $row['platform_part_no'])?></a>
            </td>
            <td><span class="flow-tag <?=$typeColors[$row['change_type']] ?? 'is-dim'?>"><?=h($typeLabel)?></span></td>
            <td class="num"><span class="<?=$chg>0?'flow-chg-in':'flow-chg-out'?>"><?=($chg>0?'+':'').$chg?></span></td>
            <td class="num flow-mono"><?=(int)$row['qty_before']?>→<?=(int)$row['qty_after']?></td>
            <td class="flow-remark"><?=h($row['remark'] ?? '')?></td>
            <?php if ($showReview): ?>
            <?php ?>
            <td class="flow-review-col">
                <?php if (($row['reviewed_at'] ?? '') !== ''): ?>
                    <span class="flow-reviewed" title="复核于 <?=h(substr((string)$row['reviewed_at'], 0, 16))?>">已复核</span>
                <?php else: ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-review-id="<?=(int)$row['id']?>">复核</button>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- 分页（统一 .pagination 组件；链接交由 flow.js 局部刷新） -->
<?php if($result['total_page'] > 1 || $result['total'] > 0): ?>
<?php
$bp = http_build_query(array_filter([
    'part_id'   => (int)$params['part_id'] ?: '',
    'model'     => $params['keyword'],
    'date_from' => $params['date_from'],
    'date_to'   => $params['date_to'],
    'per_page'  => (int)$params['per_page'],
], fn($v) => $v !== '' && $v !== 0));
$baseUrl   = "?v=stock&tab={$tab}&" . $bp;
$curPage   = (int)$result['page'];
$totalPage = (int)$result['total_page'];
?>
<div class="pagination" data-flow-base="<?=h($baseUrl)?>">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$curPage?>" data-flow-jump data-flow-base="<?=h($baseUrl)?>"> 页</span>
    <?php if($totalPage > 1): ?>
    <a href="<?=$baseUrl?>&page=<?=max(1,$curPage-1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$curPage<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1,$curPage-2); $e = min($totalPage,$curPage+2);
    if($s > 1) echo '<a href="'.$baseUrl.'&page=1" data-flow data-skip-iframe-nav class="page-btn">1</a>';
    if($s > 2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="'.$baseUrl.'&page='.$i.'" data-flow data-skip-iframe-nav class="page-btn '.($i===$curPage?'active':'').'">'.$i.'</a>';
    if($e < $totalPage-1) echo '<span class="page-info">…</span>';
    if($e < $totalPage) echo '<a href="'.$baseUrl.'&page='.$totalPage.'" data-flow data-skip-iframe-nav class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="<?=$baseUrl?>&page=<?=min($totalPage,$curPage+1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$curPage>=$totalPage?'disabled':''?>">›</a>
    <?php endif; ?>
    <span class="page-info">共 <?=$result['total']?> 条</span>
    <select class="per-page-select" data-flow-perpage data-cookie="per_page_stock">
        <?php foreach ([25,50,100] as $pp): ?>
        <option value="<?=$pp?>" <?=(int)$params['per_page']===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
