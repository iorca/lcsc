<?php
declare(strict_types=1);
/** partials/flow_orders.php — 流水中心 · 订单信息视图 */

const FALLBACK_COLS = [
    'order_no' => '订单号', 'order_status' => '订单状态', 'receiver' => '收货人',
    'customer_rep' => '客户代表', 'express_company' => '快递公司', 'express_no' => '快递单号',
    'ship_date' => '发货日期', 'sign_date' => '签收日期', 'invoice_no' => '发票号',
    'invoice_time' => '开票时间', 'origin_order_no' => '归属原订单',
    'bom_flag' => 'BOM标识', 'contract_no' => '合同编号',
];

$rows        = (array)($orders['rows'] ?? []);
$total       = (int)($orders['total'] ?? 0);
$curPage     = (int)($orders['page'] ?? 1);
$totalPages  = (int)($orders['total_pages'] ?? 1);
$perPage     = (int)($orders['per_page'] ?? 25);
$keyword     = (string)($orders['keyword'] ?? '');

// 列定义：优先用 Service 给的；为空则用兜底
$defs = (array)($orderCols ?? []);
if (!$defs) $defs = FALLBACK_COLS;

$showCols = (array)($orders['cols'] ?? []);
if (!$showCols) {
    $showCols = (array)($orderVisibleCols ?? array_keys($defs));
    $showCols = array_values(array_intersect($showCols, array_keys($defs)));
}
if (!$showCols) $showCols = ['order_no'];

$hasCustom  = !empty($orderCustomFields);
$canEdit    = \hasPermission('can_edit');
// 等宽字体列（编号类）
$monoCols   = ['express_no', 'invoice_no', 'origin_order_no', 'contract_no'];
$colCount   = count($showCols) + 1;
?>

<!-- 筛选工具条 -->
<form method="get" action="index.php" class="flow-toolbar" data-flow-filter data-skip-iframe-nav>
    <input type="hidden" name="v" value="stock">
    <input type="hidden" name="view" value="orders">
    <input type="hidden" name="per_page" value="<?=h((string)$perPage)?>">
    <input name="keyword" type="text" class="flow-grow" placeholder="订单号 / 收货人 / 快递单号 / 发票号…" value="<?=h($keyword)?>" autocomplete="off">
    <button type="submit" class="btn btn-primary btn-sm">查询</button>
    <a href="index.php?v=stock&view=orders" class="btn btn-ghost btn-sm" data-flow data-skip-iframe-nav>重置</a>
    <?php if ($canEdit): ?>
    <button type="button" class="btn btn-ghost btn-sm" id="orderColsBtn"
            title="自定义要显示的列">显示列</button>
    <?php endif; ?>
    <span class="flow-hint">订单扩展信息来自导入时手动绑定的订单级字段；点击订单号看完整详情</span>
</form>

<!-- 数据表格 -->
<div class="table-wrap">
    <table class="flow-table" id="orderTable">
        <thead>
            <tr>
                <?php foreach ($showCols as $k): ?>
                <th><?=h((string)($defs[$k] ?? $k))?></th>
                <?php endforeach; ?>
                <th>更新时间</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="<?=$colCount?>">
                <div class="empty-state">
                    <?= $keyword !== '' ? '未找到匹配的订单信息' : '暂无订单信息（导入时手动绑定订单级字段后即可在此查询）' ?>
                </div>
            </td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <?php foreach ($showCols as $k): $v = trim((string)($r[$k] ?? '')); ?>
                <td class="<?= in_array($k, $monoCols, true) ? 'flow-mono' : '' ?>">
                    <?php if ($k === 'order_no'): ?>
                        <span class="flow-part order-no-link" data-order-no="<?=h($v)?>"
                              role="button" tabindex="0" title="点击查看订单完整详情"><?= $v !== '' ? h($v) : '—' ?></span>
                    <?php else: ?>
                        <?= $v !== '' ? h($v) : '<span class="flow-empty">—</span>' ?>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="flow-time"><?=h(substr((string)($r['updated_time'] ?? ''), 0, 16))?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- 订单详情弹窗 -->
<div class="overlay" id="orderDetailModal">
    <div class="modal modal-lg">
        <h3>订单详情</h3>
        <div id="orderDetailBody" class="order-detail-body">
            <div class="order-detail-loading">加载中…</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('.overlay').classList.remove('open')">关闭</button>
        </div>
    </div>
</div>

<!-- 列设置弹窗 -->
<div class="overlay" id="orderColsModal">
    <div class="modal">
        <h3>自定义显示列</h3>
        <p class="order-cols-hint">取消勾选即隐藏该列；订单号恒显示。设置仅对当前账号生效。</p>
        <div id="orderColsList" class="order-cols-list" data-defs="<?=h(json_encode($defs, JSON_UNESCAPED_UNICODE))?>" data-always="<?=h(json_encode(array_values((array)($orderAlwaysCols ?? array("order_no")))))?>"></div>
        <div class="order-cols-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="orderColsAll">全选</button>
            <button type="button" class="btn btn-ghost btn-sm" id="orderColsNone">仅订单号</button>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('.overlay').classList.remove('open')">取消</button>
            <button type="button" class="btn btn-primary" id="orderColsSave">保存</button>
        </div>
    </div>
</div>



<!-- 分页 -->
<?php if ($totalPages > 1 || $total > 0): ?>
<?php
$base = 'index.php?v=stock&view=orders&per_page=' . $perPage . ($keyword !== '' ? '&keyword=' . urlencode($keyword) : '');
?>
<div class="pagination" data-flow-base="<?=h($base)?>">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPages?>" value="<?=$curPage?>" data-flow-jump data-flow-base="<?=h($base)?>"> 页</span>
    <?php if ($totalPages > 1): ?>
    <a href="<?=$base?>&page=<?=max(1,$curPage-1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$curPage<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1, $curPage - 2); $e = min($totalPages, $curPage + 2);
    if ($s > 1) echo '<a href="'.$base.'&page=1" data-flow data-skip-iframe-nav class="page-btn">1</a>';
    if ($s > 2) echo '<span class="page-info">…</span>';
    for ($i = $s; $i <= $e; $i++) echo '<a href="'.$base.'&page='.$i.'" data-flow data-skip-iframe-nav class="page-btn '.($i === $curPage ? 'active' : '').'">'.$i.'</a>';
    if ($e < $totalPages - 1) echo '<span class="page-info">…</span>';
    if ($e < $totalPages) echo '<a href="'.$base.'&page='.$totalPages.'" data-flow data-skip-iframe-nav class="page-btn">'.$totalPages.'</a>';
    ?>
    <a href="<?=$base?>&page=<?=min($totalPages,$curPage+1)?>" data-flow data-skip-iframe-nav class="page-btn <?=$curPage>=$totalPages?'disabled':''?>">›</a>
    <?php endif; ?>
    <span class="page-info">共 <?=$total?> 条</span>
    <select class="per-page-select" data-flow-perpage data-cookie="per_page_orders">
        <?php foreach ([25,50,100] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage === $pp ? 'selected' : ''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
