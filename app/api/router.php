<?php
declare(strict_types=1);

/** 统一 API 路由入口 */

require_once __DIR__ . '/../../config.php';

use App\Core\Middleware;

require_once __DIR__ . '/../../modules/inventory/Service/PartManager.php';

// ── 统一前置守卫（鉴权 + CSRF + 安全头） ──
$guardResult = Middleware::apiGuard();
$user       = $guardResult['user'];
$apiKeyInfo = $guardResult['apiKeyInfo'];

$routes = [
    'parts'    => [\App\Api\PartsRoutes::class, 'handle'],
    'apikey'   => [\App\Api\ApiKeyRoutes::class, 'handle'],
    'category' => [\App\Api\CategoryRoutes::class, 'handle'],
    'location' => [\App\Api\LocationRoutes::class, 'handle'],
    'platform' => [\App\Api\PlatformRoutes::class, 'handle'],
    'log'      => [\App\Api\LogRoutes::class, 'handle'],
    'stock'    => [\App\Api\StockRoutes::class, 'handle'],
    'bom'      => [\App\Api\BomRoutes::class, 'handle'],
    'admin'    => [\App\Api\AdminRoutes::class, 'handle'],
    'export'   => [\App\Api\ExportRoutes::class, 'handle'],
    'asset'    => [\App\Api\AssetRoutes::class, 'handle'],
    'backup'   => [\App\Api\BackupRoutes::class, 'handle'],
    'detail'   => [\App\Api\DetailRoutes::class, 'handle'],
    'import'   => [\App\Api\ImportRoutes::class, 'handle'],
    'alt'      => [\App\Api\AlternativeRoutes::class, 'handle'],
    'pending'  => [\App\Api\PendingRoutes::class, 'handle'],
    'health'   => [\App\Api\HealthRoutes::class, 'handle'],

    'account'  => [\App\Api\AccountRoutes::class, 'handle'],
    // 外部服务（天气 / 一言）异步补齐 —— 只读公共信息，非核心功能不阻塞首屏
    'external' => [\App\Api\ExternalRoutes::class, 'handle'],

    'migration' => [\App\Api\MigrationRoutes::class, 'handle'],
    // 在线升级（P2）：预检 / 拉取并替换；仅主管理员，最高危操作
    'update'    => [\App\Api\UpdateRoutes::class, 'handle'],
];

/** API Key scope → 模块映射 */
const API_KEY_SCOPE_MAP = [
    'parts'    => 'read_part',
    'apikey'   => 'manage_api',
    'category' => 'manage_category',
    'location' => 'read_part',
    'platform' => 'manage_platform',
    'log'      => 'read_stock',
    'stock'    => 'write_stock',
    'bom'      => 'read_part',
    'admin'    => 'admin',
    'export'   => 'read_stock',
    'asset'    => 'read_part',
    'backup'   => 'admin',
    'detail'   => 'read_part',
    'import'   => 'write_part',
    'alt'      => 'read_part',
    'pending'  => 'read_part',
    'health'   => 'read_part',
    // 改密属高危自助操作 → 复用最高档 scope，不接受低权限 Key 调用
    'account'  => 'admin',
    // 天气 / 一言是公共信息，不含任何用户数据 → 复用最低只读档
    'external' => 'read_part',
    // 迁移属系统级高危操作 → 最高档
    'migration' => 'admin',
    // 在线升级（覆盖可执行代码）→ 最高档
    'update'    => 'admin',
];

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if ($action === '' || !str_contains($action, '.')) {
    \App\Core\Response::error('缺少 action 参数（格式：action=module.method）', 400);
    exit;
}

[$module, $method] = explode('.', $action, 2);
$module = strtolower($module);
$method = strtolower($method);
if (!isset($routes[$module])) {
    \App\Core\Response::error('未知模块：' . $module, 404);
    exit;
}

/** API Key scope 校验（未携带 API Key 时为 null，直接放行） */
function assertApiKeyScope(string $module, ?array $apiKeyInfo): void
{
    if (empty($apiKeyInfo)) return;
    $scope = json_decode($apiKeyInfo['scope'] ?? '[]', true);
    if (!is_array($scope)) $scope = explode(',', $apiKeyInfo['scope'] ?? '');
    if (isset(API_KEY_SCOPE_MAP[$module]) && !in_array(API_KEY_SCOPE_MAP[$module], $scope, true)) {
        http_response_code(403);
        jsonError('API Key缺少权限: ' . API_KEY_SCOPE_MAP[$module], 403);
        exit;
    }
}

assertApiKeyScope($module, $apiKeyInfo);

// ── 封印态拦截（P1 独立迁移页）：待迁移时，非 migration.* 接口一律 403 ──

if ($module !== 'migration' && \App\Core\Migration\MigrationGate::pending(\getDB())) {
    \App\Core\Response::error('数据库待迁移，请先完成迁移', 403);
}

dispatchRoute($routes[$module], $method, $user);
exit;

function dispatchRoute(array $route, string $method, array $user): void {
    [$class, $handler] = $route;
    try {
        $class::$handler($method, $user);
    } catch (\PartException $e) {
        \App\Core\Response::error($e->getMessage(), $e->errCode);
    } catch (\Throwable $e) {
        \App\Core\Response::error($e->getMessage(), 500);
    }
}
