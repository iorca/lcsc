<?php
/** modules/import/Views/partials/batch_list.php — 「最近导入」区块 */
declare(strict_types=1);
use App\Core\Enum\ImportStatus;
?>

<div class="imp-batch-list">
    <?php if (empty($importedFiles)): ?>
    <div class="empty-block">
        <div class="empty-icon">📥</div>
        <div class="empty-title">暂无导入记录</div>
        <div class="empty-desc">完成一次导入后，批次会显示在这里，可查看详情或一键撤销</div>
        <button type="button" class="empty-action" onclick="ImportWizard.scrollToStep1()">选择文件开始导入</button>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table imp-tb-sm">
        <thead><tr>
            <th>文件名</th><th class="imp-th-90">平台</th>
            <th class="imp-th-60">总行</th><th class="imp-th-60">新增</th><th class="imp-th-60">叠加</th>
            <th class="imp-th-60" title="重复行（同订单+同编号已导入过）+ 非物料行（无商品编号且无型号，如配送费/说明行）">跳过</th><th class="imp-th-60">失败</th>
            <th class="imp-th-90">状态</th><th class="imp-th-110">时间</th><th class="imp-th-170">操作</th>
        </tr></thead>
        <tbody id="batchBody">
        <?php foreach ($importedFiles as $f):
            $st = (string)($f['status'] ?? ImportStatus::DONE);

            [$stTxt, $stCls] = ImportStatus::batchLabel($st) ?: [$st, 'badge-gray'];
            $isProfileRow = ((string)($f['import_mode'] ?? 'stock')) === 'profile';
        ?>
        <tr data-batch="<?=(int)$f['id']?>">
            <td class="imp-file-cell" title="<?=h($f['file_name'])?>"><?=h($f['file_name'])?></td>
            <td><?=h($f['platform'])?></td>
            <td><?=(int)$f['total_rows']?></td>
            <td class="imp-v-ins"><?=(int)$f['inserted']?></td>
            <td class="imp-v-upd" title="<?=$isProfileRow ? '建档模式：本次补充字段的物料数' : '采购入库：本次叠加库存的物料数'?>"><?=(int)$f['updated']?></td>
            <td class="imp-v-skip"><?=(int)$f['skipped']?></td>
            <td class="<?=(int)$f['errors'] > 0 ? 'imp-v-err' : 'imp-v-idle'?>"><?=(int)$f['errors']?></td>
            <td><span class="badge <?=$stCls?>"><?=$stTxt?></span></td>
            <td class="imp-time-cell"><?=h(substr((string)$f['created_at'], 0, 16))?></td>
            <td class="td-actions">
                <div class="actions">
                    <?php if ((int)$f['errors'] > 0): ?>
                    <button type="button" class="btn btn-danger btn-xs" onclick="ImportWizard.detail(<?=(int)$f['id']?>, false, true)" title="有 <?=(int)$f['errors']?> 行导入失败，点击查看失败明细">失败 <?=(int)$f['errors']?> 行</button>
                    <?php else: ?>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.detail(<?=(int)$f['id']?>)">详情</button>
                    <?php endif; ?>
                    <?php if (\App\Core\Enum\ImportStatus::isBatchRollbackable($st) && (int)$f['total_rows'] > 0): ?>
                    <button type="button" class="btn btn-danger btn-xs" onclick="ImportWizard.rollback(<?=(int)$f['id']?>)">撤销</button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
