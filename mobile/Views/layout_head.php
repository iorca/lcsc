<?php
declare(strict_types=1);
/** mobile/Views/layout_head.php — 移动端布局头部（独立框架，不依赖 ui/） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$user      = currentUser();
$siteTitle = getSetting('site_title', 'LCSC 硬件资产管理');
$siteLogo  = getSetting('site_logo', '');

sendSecurityHeaders();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$__css = __DIR__ . '/../Assets/css/mobile.css';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= getSetting('theme_default', 'light') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf" content="<?= h(csrf()) ?>">
<meta name="mobile-frame" content="1">
<title><?= h($pageTitle) ?> — <?= h($siteTitle) ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" media="print" onload="this.media='all';this.onload=null"
      href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&display=swap">
<noscript>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&display=swap">
</noscript>
<!-- 移动端独立样式（自包含设计 tokens，不加载 ui/themes/*） -->
<link rel="stylesheet" href="Assets/css/mobile.css?v=<?= APP_VERSION.'.'.(is_file($__css) ? filemtime($__css) : '0') ?>">
</head>
<body class="m-body">

<!-- 顶栏 -->
<header class="m-header">
<?php if (!empty($isTabPage)): ?>
    <div class="m-header-title">
        <?php if ($siteLogo): ?><img src="<?= h($siteLogo) ?>" alt=""><?php endif; ?>
        <span><?= h($siteTitle) ?></span>
    </div>
<?php else: ?>
    <button type="button" class="m-back-btn" onclick="MUI.back('<?= h($backPage) ?>')" aria-label="返回上一页">‹</button>
    <div class="m-header-title m-header-title-center"><span><?= h($pageTitle) ?></span></div>
<?php endif; ?>
    <button type="button" class="m-icon-btn" onclick="MUI.toggleTheme()" title="切换主题" aria-label="切换主题"><span class="m-ico m-ico--moon" aria-hidden="true"></span></button>
</header>

<!-- 主内容区（二级页无底栏，收窄底部留白） -->
<main class="m-main<?= !empty($isTabPage) ? '' : ' m-main-notab' ?>" id="mMain">
