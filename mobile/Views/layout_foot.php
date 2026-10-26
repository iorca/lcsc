<?php
declare(strict_types=1);
/** mobile/Views/layout_foot.php — 移动端布局闭合 */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$__jsMobile  = __DIR__ . '/../Assets/js/mobile.js';
$__jsPages   = __DIR__ . '/../Assets/js/pages.js';
$__jsHome    = __DIR__ . '/../Assets/js/m-home.js';
$__jsNetguard = __DIR__ . '/../../modules/system/Assets/js/netguard.js';
// 扫码页专用：共享根级解码脚本（非 ui/ 资源）+ 移动端扫码交互

$__jsDecoder = __DIR__ . '/../../scan_decoder.js';
$__jsScan    = __DIR__ . '/../Assets/js/m-scan.js';
$__jsSearch  = __DIR__ . '/../Assets/js/m-search.js';
?>
</main><!-- /m-main -->

<?php if (!empty($isTabPage)): ?>
<!-- 底部导航（4 个页签；二级页不显示） -->
<nav class="m-tabbar">
    <a href="index.php?v=home" class="m-tab-item <?= $activePage === 'home' ? 'active' : '' ?>">
        <span class="m-tab-icon"><span class="m-ico m-ico--box" aria-hidden="true"></span></span>
        <span class="m-tab-label">工作台</span>
    </a>
    <a href="index.php?v=scan" class="m-tab-item <?= $activePage === 'scan' ? 'active' : '' ?>">
        <span class="m-tab-icon"><span class="m-ico m-ico--camera" aria-hidden="true"></span></span>
        <span class="m-tab-label">扫码</span>
    </a>
    <a href="index.php?v=search" class="m-tab-item <?= $activePage === 'search' ? 'active' : '' ?>">
        <span class="m-tab-icon"><span class="m-ico m-ico--search" aria-hidden="true"></span></span>
        <span class="m-tab-label">查询</span>
    </a>
    <a href="index.php?v=profile" class="m-tab-item <?= $activePage === 'profile' ? 'active' : '' ?>">
        <span class="m-tab-icon"><span class="m-ico m-ico--user" aria-hidden="true"></span></span>
        <span class="m-tab-label">我的</span>
    </a>
</nav>
<?php endif; ?>

<!-- 网络断开 / 服务器异常 本地提示层（浏览器侧，自带样式不依赖主站主题） -->
<script src="../modules/system/Assets/js/netguard.js?v=<?= APP_VERSION.'.'.(is_file($__jsNetguard) ? filemtime($__jsNetguard) : '0') ?>"></script>
<script src="Assets/js/mobile.js?v=<?= APP_VERSION.'.'.(is_file($__jsMobile) ? filemtime($__jsMobile) : '0') ?>"></script>
<script src="Assets/js/pages.js?v=<?= APP_VERSION.'.'.(is_file($__jsPages) ? filemtime($__jsPages) : '0') ?>"></script>
<?php if (($activePage ?? '') === 'home'): ?>
<script src="Assets/js/m-home.js?v=<?= APP_VERSION.'.'.(is_file($__jsHome) ? filemtime($__jsHome) : '0') ?>"></script>
<?php endif; ?>
<?php if (($activePage ?? '') === 'scan'): ?>
<script src="../scan_decoder.js?v=<?= APP_VERSION.'.'.(is_file($__jsDecoder) ? filemtime($__jsDecoder) : '0') ?>"></script>
<script src="Assets/js/m-scan.js?v=<?= APP_VERSION.'.'.(is_file($__jsScan) ? filemtime($__jsScan) : '0') ?>"></script>
<?php endif; ?>
<?php if (($activePage ?? '') === 'search'): ?>
<script src="Assets/js/m-search.js?v=<?= APP_VERSION.'.'.(is_file($__jsSearch) ? filemtime($__jsSearch) : '0') ?>"></script>
<?php endif; ?>
</body>
</html>
