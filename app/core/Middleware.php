<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Middleware — 统一前置中间件栈 */
final class Middleware
{
    /** 页面路由前置守卫 */
    public static function pageGuard(string $loginUrl = 'login.php'): array
    {
        \initDB();
        $user = \requireLogin($loginUrl);
        return $user;
    }

    /** API 路由前置守卫 */
    public static function apiGuard(): array
    {
        \initDB();
        \apiBootstrap();

        // ── 鉴权：Session + API Key 双模式 ──
        $apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
        $apiAuthUser = null;
        $apiKeyInfo = null;

        if ($apiKey !== '') {
            $stmt = \getDB()->prepare(
                "SELECT ak.*, u.id AS uid, u.role, u.parent_id
                 FROM api_keys ak JOIN users u ON u.id=ak.user_id
                 WHERE ak.api_key=? LIMIT 1"
            );
            $stmt->execute([$apiKey]);
            $keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$keyRow) {
                Response::error('API Key 无效', 401);
            }

            if (!empty($keyRow['expires_at']) && strtotime($keyRow['expires_at']) <= time()) {
                Response::error('API Key 已过期（到期 ' . $keyRow['expires_at'] . '），请在后台续期或重新生成', 401);
            }

            \getDB()->prepare("UPDATE api_keys SET last_used=NOW(), last_used_ip=? WHERE id=?")
                    ->execute([\getClientIP(), $keyRow['id']]);
            $apiAuthUser = [
                'id'        => (int)$keyRow['uid'],
                'role'      => $keyRow['role'],
                'parent_id' => $keyRow['parent_id'],
            ];
            $apiKeyInfo = $keyRow;
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $user = $apiAuthUser;
        } else {
            $user = \requireLogin();
        }

        // ── CSRF 校验：仅非 GET 请求，API Key 豁免 ──
        $httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($httpMethod !== 'GET' && empty($apiKeyInfo)) {
            $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
            if (!hash_equals(\csrf(), $csrfToken)) {
                Response::unauthorized('CSRF 校验失败');
            }
        }

        // ── 安全头 ──
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header_remove('X-Powered-By');

        return ['user' => $user, 'apiKeyInfo' => $apiKeyInfo];
    }

    /** Edition 版本门控校验 */
    public static function checkEdition(string $feature): bool
    {
        return \hasFeature($feature);
    }

    /** 管理员权限校验 */
    public static function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /** 主管理员权限校验 */
    public static function isPrimaryAdmin(array $user): bool
    {
        return \isPrimaryAdmin();
    }
}
