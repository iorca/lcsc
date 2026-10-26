<?php
declare(strict_types=1);
/** modules/import/Views/errors.php — 导入错误详情页面 */
require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
if (!hasPermission('can_import')) { header('Location: ' . siteUrl('index.php')); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$importId = trim($_GET['id'] ?? '');
if ($importId === '') { header('Location: ' . siteUrl('index.php')); exit; }

use Modules\Import\Service\ImportReadService;

$errors = (new ImportReadService($db, $uid, $dataUid))->getImportErrors($importId);

$pageTitle = '导入错误详情';
$activePage = 'import';
$extraTopbarRight = '<a href="index.php?v=import" class="btn btn-ghost btn-sm">← 返回导入</a>';

\App\Core\AssetManager::addCss('modules/import/Assets/css/import.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<div class="main">
<div class="glass-box">
    <div class="page-header imp-err-head">
        <h2>导入错误详情</h2>
        <div class="page-desc">共 <?=count($errors)?> 条失败记录</div>
    </div>

    <?php if(empty($errors)): ?>
    <div class="empty-state"><div class="icon">✓</div>没有错误记录</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>行号</th><th>失败原因</th><th>原始数据（前8列）</th>
        </tr></thead>
        <tbody>
        <?php foreach($errors as $e): ?>
        <tr>
            <td class="mono imp-err-row"><?=h((string)$e['row_num'])?></td>
            <td class="imp-err-reason"><?=h($e['reason'])?></td>
            <td class="imp-errpage-raw" title="<?=h($e['raw_data'])?>"><?=h($e['raw_data'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

</body></html>
