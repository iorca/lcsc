<?php
declare(strict_types=1);
// head.php — 薄壳分发器

$pageTitle  = $pageTitle  ?? '硬件资产管理';
$activePage = $activePage ?? '';
$user       = currentUser();
$siteTitle  = getSetting('site_title', 'LCSC 硬件资产管理');
$siteLogo   = getSetting('site_logo', '');
$extraTopbarRight = $extraTopbarRight ?? '';
$useLayoutFoot = $useLayoutFoot ?? false;
sendSecurityHeaders();
// 禁止浏览器缓存页面（确保平台删除等操作后显示最新数据）
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 布局分发：**两个版本已统一**。

$_isStudioFrame  = !isset($_GET['tab_mode']);
$_isStudioIframe = ($_GET['tab_mode'] ?? '') === 'studio';

// 移动端已与 PC 端严格分离（mobile/index.php 独立框架），

?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= getSetting('theme_default','light') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf" content="<?=h(csrf())?>">
<title><?= h($pageTitle) ?> — <?= h($siteTitle) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">

<link rel="stylesheet" href="ui/themes/common.css?v=<?=filemtime(__DIR__.'/../themes/common.css')?>">
<!-- 全局统一组件规范（仅新增类名，不改存量选择器，存量页面零位移） -->
<link rel="stylesheet" href="ui/themes/components.css?v=<?=filemtime(__DIR__.'/../themes/components.css')?>">
<link rel="stylesheet" href="ui/themes/studio.css?v=<?=filemtime(__DIR__.'/../themes/studio.css')?>">

<?php \App\Core\AssetManager::renderCss(); ?>
<script>
// 前端版本门控：仅暴露当前确有 JS 消费方的键（未列出的能力由 API 层 hasFeature 拦截兜底）
var _featureMap = {
    cost_view: <?=json_encode(can_view_cost())?>,
    location_manage: <?=json_encode(hasFeature('location_manage'))?>,
};
// 版本展示名：JS 里的提示文案（如「团队版入库必须填写单价」）需要它，
var _editionName = <?=json_encode(\App\Core\Edition::displayName())?>;
</script>
</head>
<?php
// ── 分发到对应布局 ──
if ($_isStudioFrame) {

    require __DIR__ . '/studio_frame.php';
    exit; // studio_frame.php 内已 exit，此处为安全兜底
} elseif ($_isStudioIframe) {
    // iframe 内页面：仅 body 开头，导航由主框架提供
    require __DIR__ . '/studio_iframe.php';
} else {

    ?>
<body class="<?=isAdmin()?'is-admin':''?>">
<?php
    require __DIR__ . '/../components/header.php';
}

// ── 向后兼容：不 require foot.php 的页面在此输出 footer + scripts ──
// （由 scripts.php 内 JS 把 footer 移到 body 末尾）

if (!$useLayoutFoot):
?>
<footer class="site-footer">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">LCSC 硬件资产管理 v1.2.0</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">xiaoxu798</a>
    &middot; <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">GitHub</a>
</footer>
<?php require_once __DIR__ . '/../components/scripts.php'; ?>
<?php
endif;
