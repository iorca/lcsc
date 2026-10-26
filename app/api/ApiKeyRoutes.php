<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

/** API Key 管理 API 路由 */
final class ApiKeyRoutes
{
    /** 有效期天数上限（防误输：10 年） */
    private const MAX_EXPIRE_DAYS = 3650;

    public static function handle(string $method, array $user): void
    {
        $db  = \getDB();
        $uid = (int)$user['id'];

        switch ($method) {
            case 'create':
                self::handleCreate($db, $uid);
                break;

            case 'delete':
                self::handleDelete($db, $uid);
                break;

            case 'list':
                self::handleList($db, $uid);
                break;

            case 'update_expiry':
                self::handleUpdateExpiry($db, $uid);
                break;

            default:
                Response::error('未知 apikey 方法：' . $method, 404);
        }
    }

    /** 过期时间参数 → DATETIME */
    private static function expireAtFromDays(int $days): ?string
    {
        if ($days <= 0) {
            return null;
        }
        $days = min($days, self::MAX_EXPIRE_DAYS);
        return date('Y-m-d H:i:s', time() + $days * 86400);
    }

    /** POST apikey.create — 创建 API Key */
    private static function handleCreate(\PDO $db, int $uid): void
    {
        $name  = trim((string)($_POST['name'] ?? ''));
        $scope = trim((string)($_POST['scope'] ?? 'read_stock,read_part'));
        if ($name === '') {
            Response::error('请输入名称');
        }
        $days      = (int)($_POST['expires_days'] ?? 0);
        $expiresAt = self::expireAtFromDays($days);
        $key = bin2hex(random_bytes(32));
        $db->prepare("INSERT INTO api_keys (user_id,api_key,name,scope,expires_at) VALUES (?,?,?,?,?)")
           ->execute([$uid, $key, $name, $scope, $expiresAt]);
        \traceLog($uid, 'apikey_create', 'api_key', 0,
            "创建API Key「{$name}」；范围={$scope}；有效期=" . ($expiresAt ?? '永不过期'));
        Response::ok([
            'api_key'    => $key,
            'name'       => $name,
            'scope'      => $scope,
            'expires_at' => $expiresAt,
        ], '创建成功');
    }

    /** POST apikey.delete — 删除 API Key */
    private static function handleDelete(\PDO $db, int $uid): void
    {
        $keyId = (int)($_POST['id'] ?? 0);
        if ($keyId <= 0) {
            Response::error('参数无效');
        }

        $nameStmt = $db->prepare("SELECT name FROM api_keys WHERE id=? AND user_id=?");
        $nameStmt->execute([$keyId, $uid]);
        $name = (string)$nameStmt->fetchColumn();
        $db->prepare("DELETE FROM api_keys WHERE id=? AND user_id=?")->execute([$keyId, $uid]);
        \traceLog($uid, 'apikey_delete', 'api_key', $keyId,
            '删除API Key「' . ($name !== '' ? $name : '不存在或非本人') . '」');
        Response::ok([], '已删除');
    }

    /** POST apikey.update_expiry — 调整过期时间 */
    private static function handleUpdateExpiry(\PDO $db, int $uid): void
    {
        $keyId = (int)($_POST['id'] ?? 0);
        if ($keyId <= 0) {
            Response::error('参数无效');
        }
        $nameStmt = $db->prepare("SELECT name FROM api_keys WHERE id=? AND user_id=?");
        $nameStmt->execute([$keyId, $uid]);
        $name = (string)$nameStmt->fetchColumn();
        if ($name === '') {
            Response::error('API Key 不存在或非本人所有');
        }
        $days      = (int)($_POST['expires_days'] ?? 0);
        $expiresAt = self::expireAtFromDays($days);
        // user_id 再过滤一道（SELECT 已验过归属，此处防并发下被移花接木）
        $db->prepare("UPDATE api_keys SET expires_at=? WHERE id=? AND user_id=?")
           ->execute([$expiresAt, $keyId, $uid]);
        \traceLog($uid, 'apikey_update_expiry', 'api_key', $keyId,
            "调整API Key「{$name}」有效期=" . ($expiresAt ?? '永不过期'));
        Response::ok(['expires_at' => $expiresAt], '有效期已更新');
    }

    /** POST apikey.list — 列出当前用户的 API Key */
    private static function handleList(\PDO $db, int $uid): void
    {
        $stmt = $db->prepare("SELECT id, name, CONCAT(LEFT(api_key,8),'...',RIGHT(api_key,4)) AS api_key_mask, scope, last_used, last_used_ip, expires_at, created_at FROM api_keys WHERE user_id=? ORDER BY id DESC");
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_expired'] = !empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time();
        }
        unset($row);
        Response::ok(['keys' => $rows]);
    }
}
