<?php
declare(strict_types=1);
/* foot.php — 页面底部，与 head.php 配对使用 */
$_isStudioIframe = $_isStudioIframe ?? false;
if (empty($useLayoutFoot)) {

    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'foot.php 契约错误：本页 require foot.php，必须在 require head.php 之前设 $useLayoutFoot = true。'
       . '（否则 head.php 的向后兼容 footer 会与 foot.php 的输出重复。详见 ui/layouts/foot.php 头部注释。）';
    exit;
}
?>
<footer class="site-footer">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">LCSC 硬件资产管理 v1.2.0</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">xiaoxu798</a>
    &middot; <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">GitHub</a>
</footer>
<?php require_once __DIR__ . '/../components/scripts.php'; ?>
</body>
</html>
