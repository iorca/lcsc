<?php
declare(strict_types=1);

namespace Modules\Stock\Service;

use PDO;

/** StockStatsService — 流水中心顶部「今日出入库」统计 */
final class StockStatsService
{
    /** 缓存时长（秒） */
    private const CACHE_TTL = 300;

    private PDO $db;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $dataUid)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId    = (int)\getWorkspaceId();
    }

    /** 今日出入库统计 */
    public function getTodayStats(): array
    {
        $cacheKey = 'stock_today_' . $this->dataUid . '_' . $this->wsId;
        $cached   = $this->cacheFetch($cacheKey);
        if ($cached !== null) return $cached;

        $today = ['in_count' => 0, 'in_qty' => 0, 'out_count' => 0, 'out_qty' => 0];
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN l.qty_change > 0 THEN 1 ELSE 0 END), 0) AS in_count,
                    COALESCE(SUM(CASE WHEN l.qty_change > 0 THEN l.qty_change ELSE 0 END), 0) AS in_qty,
                    COALESCE(SUM(CASE WHEN l.qty_change < 0 THEN 1 ELSE 0 END), 0) AS out_count,
                    COALESCE(SUM(CASE WHEN l.qty_change < 0 THEN ABS(l.qty_change) ELSE 0 END), 0) AS out_qty
                 FROM stock_log l
                 WHERE l.user_id = ? AND l.workspace_id = ? AND DATE(l.create_time) = CURDATE()"
            );
            $stmt->execute([$this->dataUid, $this->wsId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $today = $row;
        } catch (\Throwable $e) {
            error_log('StockStatsService::getTodayStats: ' . $e->getMessage());
        }

        $this->cacheStore($cacheKey, $today);
        return $today;
    }

    /** 读缓存；无 APCu 或未命中返回 null */
    private function cacheFetch(string $key): ?array
    {
        if (!\function_exists('apcu_fetch')) return null;
        try {
            $hit = false;
            $val = apcu_fetch($key, $hit);
            return ($hit && \is_array($val)) ? $val : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 写缓存；无 APCu 时静默跳过 */
    private function cacheStore(string $key, array $value): void
    {
        if (!\function_exists('apcu_store')) return;
        try { apcu_store($key, $value, self::CACHE_TTL); } catch (\Throwable $e) {}
    }
}
