<?php
declare(strict_types=1);
/** print.php — 标签打印页面（独立入口，与v1.1.0保持一致） */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
initDB();
$user = requireLogin();

if (\App\Core\Migration\MigrationGate::pending(getDB())) {
    header('Location: ' . siteUrl('index.php?v=migration'));
    exit;
}

if (!hasFeature('print_label')) { header('Location: ' . siteUrl('index.php')); exit; }
if (!hasPermission('can_print')) { header('Location: ' . siteUrl('index.php')); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$ids = array_map('intval', $_GET['ids'] ?? []);
if (empty($ids)) {
    header('Location: ' . siteUrl('index.php'));
    exit;
}

// 获取选中元件信息（含平台 code 用于二维码 pid 标识）
$in    = implode(',', array_fill(0, count($ids), '?'));
$parts = $db->prepare("SELECT p.*, pl.name AS pname, pl.code AS pcode, pl.url_template, pl.platform_type FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id IN ($in) AND p.user_id=? ORDER BY p.id");
$parts->execute([...$ids, $dataUid]);
$parts = $parts->fetchAll(PDO::FETCH_ASSOC);

if (empty($parts)) {
    header('Location: ' . siteUrl('index.php'));
    exit;
}

// ── 读取打印配置参数 ──
$labelType = $_GET['label_type'] ?? 'in';
if (!in_array($labelType, ['in', 'out'], true)) $labelType = 'in';
$qtyParam  = $_GET['qty'] ?? '1';
$remark    = trim($_GET['remark'] ?? '');
$labelTypeLabel = $labelType === 'out' ? '出库标签' : '入库标签';
$labelTypeColor = $labelType === 'out' ? '#ef4444' : '#22c55e';

// ── 标签模板：支持弹窗传参 tpl（mini/default），缺省回退网站设置 ──
$tplParam = (string)($_GET['tpl'] ?? '');
if (!in_array($tplParam, ['mini', 'default', ''], true)) $tplParam = '';
$labelTemplate = $tplParam !== '' ? $tplParam : getSetting('label_template', 'default');
if (!in_array($labelTemplate, ['mini', 'default', 'large'], true)) $labelTemplate = 'default';

// ── 打印字段：支持弹窗传参 fields（逗号分隔），缺省全部显示 ──
$allowedFields = ['model', 'part_no', 'stock', 'location', 'qrcode', 'category', 'brand'];
$showFields = [];
$fieldsParam = trim((string)($_GET['fields'] ?? ''));
if ($fieldsParam === '') {
    foreach ($allowedFields as $f) $showFields[$f] = true;
} else {
    foreach (explode(',', $fieldsParam) as $f) {
        $f = trim($f);
        if (in_array($f, $allowedFields, true)) $showFields[$f] = true;
    }
    if (empty($showFields)) $showFields['model'] = true; // 至少保留一个字段
}
$showQrcode = !empty($showFields['qrcode']);

$labelQrContent = getSetting('label_qr_content', 'internal_id');
if (!in_array($labelQrContent, ['internal_id', 'model', 'platform_part_no'], true)) $labelQrContent = 'internal_id';

$pageTitle = '打印标签';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>打印标签</title>
<?php $__qrjs = __DIR__ . '/third_party/js/qrcode.js'; ?>

<script src="third_party/js/qrcode.js?v=<?=defined('APP_VERSION') ? APP_VERSION.'.'.(is_file($__qrjs) ? filemtime($__qrjs) : '0') : '1.2.0'?>"></script>
<link rel="stylesheet" href="modules/system/Assets/css/print.css?v=<?=defined('APP_VERSION') ? APP_VERSION : '1.2.0'?>">
</head>
<body class="tpl-<?=h($labelTemplate)?>">

<div class="no-print">
    <button class="primary" onclick="window.print()">🖨 立即打印</button>
    <button onclick="window.close()">关闭</button>
    <span class="hint">
        共 <?=count($parts)?> 个标签 |
        <span style="color:<?=$labelTypeColor?>;font-weight:600"><?=$labelTypeLabel?></span>
        <?php if ($remark): ?> | 备注：<?=h($remark)?><?php endif; ?>
    </span>
</div>

<div class="labels" id="labelsBox">
<?php foreach ($parts as $p):
    $partNo = $p['platform_part_no'] ?: $p['customer_part_no'] ?: '';

    $labelQty = ($qtyParam === 'all') ? max(1, (int)$p['stock']) : max(1, (int)$qtyParam);
    // 系统QR码内容：

    $safeModel  = str_replace([',', '{', '}', ':'], ' ', (string)$p['model']);
    $safePcode  = str_replace([',', '{', '}', ':'], ' ', (string)$p['pcode']);
    $internalId = (int)($p['internal_id'] ?? 0);
    $qrValue = '{id:' . $internalId . ',pid:' . trim($safePcode) . ',model:' . trim($safeModel) . ',qty:' . $labelQty . ',type:' . $labelType . '}';

    $displayNo = match ($labelQrContent) {
        'model'            => (string)$p['model'],
        'platform_part_no' => $partNo,
        default            => \partCode($internalId),
    };
    $showModel    = !empty($showFields['model']);
    $showPartNo   = !empty($showFields['part_no']);
    $showStock    = !empty($showFields['stock']);
    $showLocation = !empty($showFields['location']);
    $showCategory = !empty($showFields['category']);
    $showBrand    = !empty($showFields['brand']);
?>
<div class="label-card">
    <?php if ($showModel): ?>
    <div class="label-header">
        <div class="label-model"><?=h($p['model'])?></div>
        <span class="label-usertype" style="background:<?=$labelTypeColor?>22;color:<?=$labelTypeColor?>;border:1px solid <?=$labelTypeColor?>;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:600"><?=$labelTypeLabel?></span>
    </div>
    <?php endif; ?>
    <div class="label-mid">
        <?php if ($showQrcode): ?>
        <div class="label-qr-wrap">
            <div class="label-qr" id="qr<?=$p['id']?>" data-value="<?=h($qrValue)?>"></div>
            <div class="label-qr-cap">系统码</div>
        </div>
        <?php endif; ?>
        <div class="label-mid-info">
            <?php if ($showQrcode && $displayNo !== ''): ?>
            <div class="part-no"><?=h($displayNo)?></div>
            <?php endif; ?>
            <?php if ($showPartNo && $partNo !== ''): ?>
            <div class="row"><span class="k">编号</span><span class="v"><?=h($partNo)?></span></div>
            <?php endif; ?>
            <?php if ($showStock): ?>
            <div class="row"><span class="k">库存</span><span class="v"><?=$p['stock']?><?php if((int)$p['damaged']>0):?> (不良:<?=$p['damaged']?>)<?php endif; ?></span></div>
            <?php endif; ?>
            <div class="row"><span class="k">数量</span><span class="v" style="font-weight:600;color:<?=$labelTypeColor?>"><?=$labelQty?> (<?=$labelType==='out'?'出':'入'?>库)</span></div>
        </div>
    </div>
    <?php
          ?>
    <?php
    $underRows = [];
    if ($showModel && $p['package'] !== '')    $underRows['封装'] = $p['package'];
    if ($showBrand && $p['brand'] !== '')      $underRows['品牌'] = $p['brand'];
    if ($showCategory && $p['product_type'] !== '') $underRows['分类'] = $p['product_type'];
    if ($showModel && $p['product_name'] !== '')    $underRows['名称'] = $p['product_name'];
    ?>
    <?php if ($underRows): ?>
    <div class="label-mid-info label-under">
        <?php foreach ($underRows as $uk => $uv): ?>
        <div class="row"><span class="k"><?=h($uk)?></span><span class="v"><?=h($uv)?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($remark): ?>
    <div class="label-remark" style="font-size:10px;color:#444;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px">备注：<?=h($remark)?></div>
    <?php endif; ?>
    <div class="label-footer">
        <?php
              ?>
        <span><?=h($p['pname'])?></span>
        <?php if ($showLocation && $p['location']): ?>
        <span class="label-location">📍<?=h($p['location'])?></span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<script src="modules/system/Assets/js/print.js?v=<?=defined('APP_VERSION') ? APP_VERSION : '1.2.0'?>"></script>

</body>
</html>
