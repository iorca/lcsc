<?php
declare(strict_types=1);
/** index.php — 统一页面路由（V1.2 Feature First） */
require_once __DIR__ . '/config.php';

use App\Core\Middleware;
use App\Core\PageRouter;

Middleware::pageGuard();

if (isset($_COOKIE['layout'])) {
    setcookie('layout', '', ['path' => '/', 'expires' => time() - 3600, 'samesite' => 'Strict']);
}

$page = $_GET['v'] ?? '';

// ── 封印态拦截（P1 独立迁移页）──

if ($page !== 'migration' && \App\Core\Migration\MigrationGate::pending(getDB())) {
    \App\Core\Response::redirect('index.php?v=migration');
}

// ── 页面级版本/权限门控（表驱动，见 PageRouter::GUARDS）──

PageRouter::guard($page);

// ── 移动端自动分流（双端严格分离）──

if (isset($_GET['desktop'])) {
    setcookie('ui_channel', 'desktop', ['path' => '/', 'expires' => time() + 365*86400, 'samesite' => 'Lax']);
} elseif (isMobileUA() && ($_COOKIE['ui_channel'] ?? '') !== 'desktop') {
    $target = 'mobile/index.php';
    if ($page !== '') {
        $target .= '?v=' . rawurlencode($page);
    }
    header('Location: ' . $target);
    exit;
}
$view = PageRouter::dispatch($page);

require __DIR__ . '/' . $view;
