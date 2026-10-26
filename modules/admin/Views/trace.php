<?php
declare(strict_types=1);
/** 溯源日志 Tab */
$traceFrag = !empty($isTraceFragment);
?>
<?php if (!$traceFrag): ?>
<div id="tracePanel" class="trace-panel">
<?php endif; ?>
<div class="card card-pad trace-card">
<div class="trace-head">
    <div class="trace-title">🔍 溯源日志（共 <?=number_format($traceData['total'])?> 条）</div>
    <div class="trace-retention">留存期：<?=getRetentionDays()?> 天 · 未到期不可删除</div>
</div>
<p class="backup-info">
    全系统所有写操作（增删改、出入库、扫码、备份、用户管理、设置变更等）均完整写入溯源日志用于追责。<br>
    列表展示最新 100 条，可上下滑动浏览；如需查看完整历史数据，请使用日期筛选后导出 CSV。
</p>


<div class="trace-filter-box">
<div class="trace-presets">
    <span class="trace-presets-label">快捷时段</span>
    <button type="button" class="trace-chip" data-trace-days="0">今日</button>
    <button type="button" class="trace-chip" data-trace-days="6">近7天</button>
    <button type="button" class="trace-chip" data-trace-days="29">近30天</button>
</div>
<div class="trace-filters">
        <label class="trace-field"><span>起始日期</span>
            <input type="date" id="traceDateFrom" name="trace_date_from" value="<?=h($traceDateFrom)?>">
        </label>
        <label class="trace-field"><span>结束日期</span>
            <input type="date" id="traceDateTo" name="trace_date_to" value="<?=h($traceDateTo)?>">
        </label>
        <label class="trace-field"><span>动作</span>
            <select id="traceActionSel" name="trace_action">
                <option value="">全部</option>
                <?php


                $actionLabels = \TraceManager::actionLabels();
                $cur = $traceAction;
                $found = in_array($cur, $traceActionOptions, true);
                foreach ($traceActionOptions as $val):
                    $label = $actionLabels[$val] ?? $val;
                ?>
                <option value="<?=h($val)?>" <?=$cur===$val?'selected':''?>><?=h($label)?></option>
                <?php endforeach;

                if ($cur !== '' && !$found): ?>
                <option value="<?=h($cur)?>" selected><?=h($actionLabels[$cur] ?? $cur)?></option>
                <?php endif; ?>
            </select>
        </label>
        <label class="trace-field"><span>目标类型</span>
            <select id="traceTypeSel" name="trace_target_type">
                <option value="">全部</option>
                <?php
                // 目标类型选项：同样从库中 DISTINCT 动态生成

                $typeLabels = \TraceManager::targetTypeLabels();
                $curT = $traceTargetType;
                $foundT = in_array($curT, $traceTypeOptions, true);
                foreach ($traceTypeOptions as $val):
                    $label = $typeLabels[$val] ?? $val;
                ?>
                <option value="<?=h($val)?>" <?=$curT===$val?'selected':''?>><?=h($label)?></option>
                <?php endforeach;
                if ($curT !== '' && !$foundT): ?>
                <option value="<?=h($curT)?>" selected><?=h($typeLabels[$curT] ?? $curT)?></option>
                <?php endif; ?>
            </select>
        </label>
        <label class="trace-field"><span>操作人</span>
            <input type="text" id="traceOperator" name="trace_operator" value="<?=h($traceOperator)?>" placeholder="用户名">
        </label>
        <div class="trace-filter-actions">
        <button type="button" class="btn btn-primary btn-sm" data-trace-apply>🔍 筛选</button>
        <button type="button" class="btn btn-ghost btn-sm" data-trace-reset>重置</button>
    <form method="post" action="api.php" id="traceExportForm">
        <input type="hidden" name="action" value="export.trace">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="date_from" value="<?=h($traceDateFrom)?>">
        <input type="hidden" name="date_to" value="<?=h($traceDateTo)?>">
        <button type="submit" class="btn btn-ghost btn-sm">📥 导出 CSV</button>
    </form>
        </div>
</div>
</div>

<?php if (!empty($traceData['logs'])): ?>
<div class="table-wrap trace-table-wrap">
<table>
<thead><tr>
    <th class="trace-w-time">时间</th>
    <th class="trace-w-user">操作用户</th>
    <th class="trace-w-action">动作</th>
    <th class="trace-w-type">目标类型</th>
    <th class="trace-w-id">目标ID</th>
    <th>操作详情</th>
    <th class="trace-w-ip">IP</th>
    <th class="trace-w-ops">操作</th>
</tr></thead>
<tbody>
<?php foreach ($traceData['logs'] as $tl): ?>
<?php
    $hasSnapshot = !empty($tl['snapshot_before']);
    $rollableTypes = ['part', 'category', 'platform'];
    $canRollback = $hasSnapshot && in_array($tl['target_type'], $rollableTypes, true);
    $snapshotBefore = $hasSnapshot ? $tl['snapshot_before'] : '';
    $snapshotAfter = !empty($tl['snapshot_after']) ? $tl['snapshot_after'] : '';
    // 操作详情折叠：批量操作（如批量改库位）的 detail 可能长达数千字，
    // 整行铺开会让表格行高失控、行数一多就卡。默认只渲染前 120 字，
    // 全文放在隐藏节点里，点「展开」就地查看（不弹窗、不额外请求）。
    $detailRaw  = (string)($tl['detail'] ?? '');
    $detailLong = mb_strlen($detailRaw, 'UTF-8') > 120;
    $detailShow = $detailLong ? mb_substr($detailRaw, 0, 120, 'UTF-8') . '…' : $detailRaw;
?>
<tr>
    <td class="trace-cell-time"><?=h(substr((string)$tl['created_at'], 0, 19))?></td>
    <td class="trace-cell-user"><?=h($tl['username'] !== '' ? $tl['username'] : 'user_id:' . $tl['user_id'])?></td>
    <td><span class="badge badge-blue" title="<?=h($tl['action'])?>"><?=h($tl['action_label'] ?? $tl['action'])?></span></td>
    <td class="trace-cell-plain" title="<?=h($tl['target_type'])?>"><?=h($tl['target_type_label'] ?? $tl['target_type'])?></td>
    <td class="trace-cell-dim"><?=$tl['target_id'] > 0 ? (int)$tl['target_id'] : '—'?></td>
    <td class="trace-cell-detail">
    <?php if ($detailLong): ?>
        <span class="trace-detail-short"><?=h($detailShow)?></span>
        <span class="trace-detail-full" hidden><?=h($detailRaw)?></span>
        <button type="button" class="trace-detail-toggle" aria-expanded="false">展开</button>
    <?php else: ?>
        <?=h($detailRaw !== '' ? $detailRaw : '—')?>
    <?php endif; ?>
    </td>
    <td class="trace-cell-ip"><?=h($tl['ip'] ?? '—')?></td>
    <td class="trace-actions">
        <?php if ($hasSnapshot): ?>
        <button type="button" class="btn btn-ghost btn-sm trace-snapshot-btn"
            data-id="<?=h((string)$tl['id'])?>"
            data-before="<?=h($snapshotBefore)?>"
            data-after="<?=h($snapshotAfter)?>">📋 快照</button>
        <?php endif; ?>
        <?php if ($canRollback): ?>
        <button type="button" class="btn btn-ghost btn-sm trace-rollback-btn"
            data-id="<?=h((string)$tl['id'])?>"
            data-type="<?=h($tl['target_type'])?>"
            data-detail="<?=h($tl['detail'])?>">↩ 回滚</button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>
<div class="empty-state"><div class="icon">🔍</div>暂无溯源日志记录<span class="trace-empty-hint">试试调整时间范围或筛选条件</span></div>
<?php endif; ?>
</div>
<?php if (!$traceFrag): ?>
</div><!-- /#tracePanel -->

<script src="modules/admin/Assets/js/trace.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/trace.js')?>" defer></script>

<!-- 快照 Diff 弹窗 -->
<div class="overlay" id="snapshotModal">
<div class="modal modal-lg">
    <div class="modal-header">
        <h3>📋 数据快照对比</h3>
        <button class="btn-close" onclick="closeSnapshotModal()">&times;</button>
    </div>
    <div class="modal-body trace-diff-body">
        <div id="snapshotDiffContent" class="trace-diff"></div>
    </div>
</div>
</div>

<!-- 回滚确认弹窗 -->
<div class="overlay" id="rollbackConfirmModal">
<div class="modal">
    <div class="modal-header">
        <h3>⚠️ 确认回滚</h3>
        <button class="btn-close" onclick="closeRollbackConfirm()">&times;</button>
    </div>
    <div class="modal-body">
        <p id="rollbackConfirmMsg" class="trace-rollback-msg"></p>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeRollbackConfirm()">取消</button>
            <button type="button" class="btn btn-danger" id="rollbackConfirmBtn">确认回滚</button>
        </div>
    </div>
</div>
</div>
<?php endif; ?>
