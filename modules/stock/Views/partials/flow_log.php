<?php
declare(strict_types=1);
/** partials/flow_log.php — 流水中心 · 操作日志视图 */
?>
<?php if ($flash === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif ($flash === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php elseif ($flash === 'forbidden'): ?>
<div class="flash err">✗ 无操作权限</div>
<?php endif; ?>

<div class="flow-toolbar">
    <div class="search-box flow-search">
        <svg class="search-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <form method="get" id="logSearchForm" class="flow-search-form" data-flow-filter data-skip-iframe-nav>
            <input type="hidden" name="v" value="stock">
            <input type="hidden" name="view" value="log">
            <input type="text" name="q" id="logSearchInput" value="<?=h($searchKw)?>" placeholder="搜索编号/型号/备注/类型..." autocomplete="off" class="flow-search-input">
            <button type="button" class="clear-btn flow-clear" onclick="clearLogSearch()" title="清空" id="logClearBtn">
                <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </form>
    </div>
    <span class="flow-hint">按编号/型号/备注/类型搜索<?php if (isAdmin()): ?>，支持批量导出与删除<?php endif; ?></span>
    <span class="flow-spacer"></span>
    <span id="selectedCount" class="flow-hint">已选 0 条</span>
    <button type="button" class="btn btn-ghost btn-sm" onclick="batchExportLogs()">📥 导出选中</button>
    <?php if (isAdmin()): ?>
    <button type="submit" form="batchForm" class="btn btn-danger btn-sm" id="batchSubmitBtn">🗑 删除选中</button>
    <?php endif; ?>
</div>

<form method="post" action="api.php" id="batchForm">
    <input type="hidden" name="action" value="log.batch_delete">
    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <?php if($searchKw !== ''): ?>
    <input type="hidden" name="return_url" value="index.php?v=stock&view=log&q=<?=urlencode($searchKw)?>">
    <?php endif; ?>
    <div class="table-wrap">
    <table id="logTable" class="flow-table">
        <thead><tr>
            <th class="flow-th-check"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
            <th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>
            <th class="flow-th-r">变化量</th>
            <th>操作人</th><th>操作IP</th>
            <th>备注</th>
        </tr></thead>
        <tbody>
        <?php if(empty($logs)): ?>
            <tr><td colspan="9"><div class="empty-state"><?= $searchKw !== '' ? '未找到匹配记录' : '暂无记录' ?></div></td></tr>
        <?php else: foreach($logs as $l):
            $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
            $chg  = $l['qty_change'];

            $tagCls = match(strtolower((string)$info[1])) {
                '#4f8ef7' => 'is-blue', '#22c55e' => 'is-green', '#ef4444' => 'is-red',
                '#f59e0b' => 'is-amber', '#8b5cf6' => 'is-purple', default => '',
            };
            $chgCls = $chg >= 0 ? 'is-in' : 'is-out';
        ?>
        <tr>
            <td class="flow-td-check"><input type="checkbox" name="log_ids[]" value="<?=$l['id']?>" class="logCheckbox"></td>
            <td class="flow-td-time"><?=h(substr((string)$l['create_time'],0,16))?></td>
            <td class="code-blue"><?=h($l['platform_part_no'])?></td>
            <td class="flow-td-text"><?=h($l['model'])?></td>
            <td>
                <span class="flow-tag-lg <?=$tagCls?>">
                    <?=h($info[0])?>
                </span>
            </td>
            <td class="flow-td-chg <?=$chgCls?>">
                <?=($chg >= 0 ? '+' : '') . $chg?>
            </td>
            <td class="flow-td-text"><?=h($l['operator'] ?: '-')?></td>
            <td class="flow-td-time"><?=h($l['ip'] ?: '-')?></td>
            <td class="flow-td-remark"><?=h($l['remark'])?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</form>

<?php if($totalPage > 1 || $total > 0): ?>
<?php $pageQ = $searchKw !== '' ? '&q='.urlencode($searchKw) : ''; ?>
<div class="pagination" data-flow-base="?v=stock&view=log&per_page=<?=$perPage?><?=$pageQ?>">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$logPage?>" data-flow-jump data-flow-base="?v=stock&view=log&per_page=<?=$perPage?><?=$pageQ?>"> 页</span>
    <a href="?v=stock&view=log&per_page=<?=$perPage?>&page=<?=$logPage-1?><?=$pageQ?>" data-flow data-skip-iframe-nav class="page-btn <?=$logPage<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1,$logPage-2); $e = min($totalPage,$logPage+2);
    if($s>1) echo '<a href="?v=stock&view=log&per_page='.$perPage.'&page=1'.$pageQ.'" data-flow data-skip-iframe-nav class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?v=stock&view=log&per_page='.$perPage.'&page='.$i.$pageQ.'" data-flow data-skip-iframe-nav class="page-btn '.($i===$logPage?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?v=stock&view=log&per_page='.$perPage.'&page='.$totalPage.$pageQ.'" data-flow data-skip-iframe-nav class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?v=stock&view=log&per_page=<?=$perPage?>&page=<?=$logPage+1?><?=$pageQ?>" data-flow data-skip-iframe-nav class="page-btn <?=$logPage>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
    <select class="per-page-select" data-flow-perpage data-cookie="per_page_log">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
