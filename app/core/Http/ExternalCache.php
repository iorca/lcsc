<?php
declare(strict_types=1);

namespace App\Core\Http;

use PDO;
use Throwable;

/** 外部服务缓存与降级 */
final class ExternalCache
{
    /** 读缓存 */
    public static function read(PDO $db, string $cacheKey): ?array
    {
        try {
            $stmt = $db->prepare(
                "SELECT payload, fetched_at, expires_at FROM external_cache
                 WHERE cache_key = ? AND payload IS NOT NULL AND payload <> ''"
            );
            $stmt->execute([$cacheKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[ExternalCache] 读缓存失败，退化为每次直连：' . $e->getMessage());
            return null;
        }

        if (!$row) return null;

        $payload = json_decode((string)$row['payload'], true);
        if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
            return null;   // 缓存内容已损坏，等同于没缓存
        }

        return [
            'payload'    => $payload,
            'fetched_at' => (string)$row['fetched_at'],
            'expires_at' => (string)$row['expires_at'],
            'fresh'      => strtotime((string)$row['expires_at']) > time(),
        ];
    }

    /** 写缓存 */
    public static function write(PDO $db, string $cacheKey, mixed $payload, int $ttl): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) return;

        try {
            $stmt = $db->prepare(
                "INSERT INTO external_cache (cache_key, payload, fetched_at, expires_at)
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE
                    payload = VALUES(payload),
                    fetched_at = VALUES(fetched_at),
                    expires_at = VALUES(expires_at)"
            );
            $stmt->execute([$cacheKey, $json, $ttl]);
        } catch (Throwable $e) {
            error_log('[ExternalCache] 写缓存失败，本次仍正常返回：' . $e->getMessage());
        }
    }

    /** 取缓存值并按降级口径决定是否回源 */
    public static function remember(
        PDO $db,
        string $cacheKey,
        int $ttl,
        callable $fetcher
    ): array {
        $cached = self::read($db, $cacheKey);

        // 未过期 → 直接返回，**完全不发起外部请求**（这是缓存的主要价值）
        if ($cached !== null && $cached['fresh']) {
            return [
                'payload'    => $cached['payload'],
                'source'     => 'fresh',
                'fetched_at' => $cached['fetched_at'],
                'expires_at' => $cached['expires_at'],
            ];
        }

        $fresh = $fetcher();

        // 外部成功 → 刷新缓存后返回
        if ($fresh !== null) {
            self::write($db, $cacheKey, $fresh, $ttl);
            $now = date('Y-m-d H:i:s');
            return [
                'payload'    => $fresh,
                'source'     => 'remote',
                'fetched_at' => $now,
                'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            ];
        }

        // 外部失败 + 手上有旧值 → 返回旧值并忽略过期
        if ($cached !== null) {
            return [
                'payload'    => $cached['payload'],
                'source'     => 'stale',
                'fetched_at' => $cached['fetched_at'],
                'expires_at' => $cached['expires_at'],
            ];
        }

        // 从未成功过 + 外部失败 → 空结果（页面显示占位）
        return [
            'payload'    => null,
            'source'     => 'empty',
            'fetched_at' => '',
            'expires_at' => '',
        ];
    }

    /** 清理过期条目（供健康检查 / 计划任务调用） */
    public static function purgeExpired(PDO $db): int
    {
        try {
            return $db->exec("DELETE FROM external_cache WHERE expires_at < NOW() - INTERVAL 7 DAY");
        } catch (Throwable $e) {
            error_log('[ExternalCache] 清理过期条目失败：' . $e->getMessage());
            return 0;
        }
    }

    /** 生成缓存键 */
    public static function key(string $service, string $identifier = ''): string
    {
        $suffix = $identifier === '' ? '' : ':' . str_replace(':', '_', $identifier);
        return $service . $suffix;
    }
}
