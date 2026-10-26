<?php
declare(strict_types=1);
/** mobile/index.php — 移动端统一入口（V1.2 双端严格分离架构） */
define('MOBILE_FRAME', true);

require_once __DIR__ . '/../config.php';

use App\Core\Middleware;

// 回到移动端：清除「桌面版」通道选择，恢复 UA 自动分流
// （用户从移动端入口进入即视为放弃桌面版偏好）
if (isset($_COOKIE['ui_channel']) && $_COOKIE['ui_channel'] === 'desktop') {
    setcookie('ui_channel', '', ['path' => '/', 'expires' => time() - 3600, 'samesite' => 'Lax']);
}

// 复用后端登录/安装守卫（与 PC 入口同一套鉴权，非 UI 串通；

Middleware::pageGuard('../login.php');

// ── 封印态拦截（P1 独立迁移页）：移动端不重复实现迁移页，一律回 PC 迁移页 ──

if (\App\Core\Migration\MigrationGate::pending(getDB())) {
    header('Location: ../index.php?v=migration');
    exit;
}

// ── 移动端路由分发 ──
$routes = require __DIR__ . '/routes.php';
$page   = isset($_GET['v']) && is_string($_GET['v']) ? $_GET['v'] : 'home';

if (!isset($routes[$page])) {
    $page = 'home';
}

// 路由项决定页面形态：页签页（渲染底栏）还是二级页（顶栏带返回、不渲染底栏）
$route      = $routes[$page];
$pageTitle  = $route['title'];
$activePage = $page;
$isTabPage  = !empty($route['tab']);
$backPage   = (string)($route['back'] ?? 'home');

require __DIR__ . '/Views/layout_head.php';
require __DIR__ . '/Views/' . $route['view'];
require __DIR__ . '/Views/layout_foot.php';
