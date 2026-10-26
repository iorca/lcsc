<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use PDO;

/** DashboardReadService — 团队版工作台（`Views/dashboard.php`）读模型 */
final class DashboardReadService
{
    /** 趋势图天数（工作台固定近 7 天） */
    private const TREND_DAYS = 7;

    /** 低库存清单条数 */
    private const LOW_STOCK_LIMIT = 6;

    /** 库存结构明细条数上限（超出部分合并为「其他」一档，避免图例与柱子溢出） */
    private const CAT_DIST_LIMIT = 6;

    /** 最近动态流条数：出入库 / 物料 / BOM 项目 */
    private const RECENT_LOGS_LIMIT  = 8;
    private const RECENT_PARTS_LIMIT = 6;
    private const RECENT_BOM_LIMIT   = 5;

    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
        $this->wsId    = (int)\getWorkspaceId();
    }

    /** 一次取齐工作台首屏全部数据 */
    public function getDashboardData(): array
    {
        $stats     = $this->getStats();
        $warnings  = $this->getWarnings();
        $anomaly   = $warnings['anomaly'] ?? ['damaged' => 0, 'zero_with_threshold' => 0, 'negative' => 0, 'total' => 0];
        $lowStockCount = (int)($warnings['low_stock_count'] ?? 0);

        // 数据质量类预警（读快照，无快照则为空数组 —— 不拖累首屏）
        $dqAlerts = $this->getDataQualityAlerts();

        // 本周简报与趋势共用 `stock_log`，先取简报（供指标卡副标题）
        $week = $this->getWeekBriefing();

        return [
            'stats'            => $stats,
            'warnings'         => $warnings,
            'staleTop5'        => $warnings['stale_top5'] ?? [],
            'anomaly'          => $anomaly,
            'staleCount'       => (int)($warnings['stale_count'] ?? 0),
            'lowStockCount'    => $lowStockCount,
            'lowStockParts'    => $this->getLowStockParts(),
            'recentLogs'       => $this->getRecentLogs(),
            'recentParts'      => $this->getRecentParts(),
            'bomProjects'      => $this->getRecentBomProjects(),
            'week'             => $week,
            'weekNet'          => (int)$week['in_qty'] - (int)$week['out_qty'],
            'trend'            => $this->getTrend(),
            'trendInTotal'     => null, // 由下方 fillDerived() 依trend 填充
            'trendOutTotal'    => null,
            'catDist'          => $this->getCategoryDistribution(),
            'catDistQtyTotal'  => null, // 由下方 fillDerived() 依 catDist 填充
            'undoPending'      => $this->getUndoPendingCount(),
            'dqAlerts'         => $dqAlerts,
            'dqAlertCount'     => count($dqAlerts),
            'dqIssueCount'     => array_sum(array_column($dqAlerts, 'count')),
            'todoCount'        => null, // 由下方 fillDerived() 依上述计数填充
            'stockKinds'       => (int)($stats['stock_kinds'] ?? 0),
            'healthRate'       => null, // 由下方 fillDerived() 依 stockKinds 填充
        ];
    }

    /** 物料总数 / 库存总量 / 零库存数 / 不良品数 / 库存总值 / 在库种类 */
    public function getStats(): array
    {
        $stats = [
            'total'         => 0,
            'total_stock'   => 0,
            'zero_count'    => 0,
            'total_damaged' => 0,
            'total_value'   => 0,
            'stock_kinds'   => 0,
        ];
        try {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(stock), 0) AS total_stock,
                    COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS zero_count,
                    COALESCE(SUM(damaged), 0) AS total_damaged,
                    COALESCE(SUM(stock * avg_cost), 0) AS total_value,
                    COALESCE(SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END), 0) AS stock_kinds
                FROM parts p WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted'
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $stats = $row;
        } catch (\Throwable $e) { error_log('DashboardReadService::getStats: ' . $e->getMessage()); }
        return $stats;
    }

    /** 低库存 / 呆滞 / 库存异常汇总 */
    public function getWarnings(): array
    {
        $fallback = [
            'low_stock_count' => 0,
            'stale_count'     => 0,
            'stale_days'      => 90,
            'stale_top5'      => [],
            'anomaly'         => ['damaged' => 0, 'zero_with_threshold' => 0, 'negative' => 0, 'total' => 0],
        ];
        try {

            require_once __DIR__ . '/../../assets/Service/AssetsService.php';
            return (new \AssetManager($this->db, $this->uid, $this->dataUid))->getWarnings();
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getWarnings: ' . $e->getMessage());
            return $fallback;
        }
    }

    /** 低库存物料清单（按「库存 / 有效阈值」升序，取最紧张的若干条） */
    public function getLowStockParts(): array
    {
        try {
            $eff   = $this->getEffectiveThresholdExpr();
            $limit = self::LOW_STOCK_LIMIT;
            $stmt = $this->db->prepare("
                SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock, {$eff} AS eff_threshold
                FROM parts p
                WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted'
                  AND p.stock > 0 AND p.stock <= {$eff}
                ORDER BY (p.stock / {$eff}) ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getLowStockParts: ' . $e->getMessage());
            return [];
        }
    }

    /** 有效低库存阈值 SQL 片段（单品 > 分类 > 全局） */
    private function getEffectiveThresholdExpr(): string
    {
        $globalThr = (int)\getGlobalThreshold();
        return "COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),{$globalThr})";
    }

    /** 最近出入库流水（带物料信息与来源） */
    public function getRecentLogs(): array
    {
        try {
            $limit = self::RECENT_LOGS_LIMIT;
            $stmt = $this->db->prepare("
                SELECT s.id, s.qty_change, s.change_type, s.source_type, s.create_time,
                       p.model, p.platform_part_no, p.product_name
                FROM stock_log s
                LEFT JOIN parts p ON p.id = s.part_id
                WHERE s.user_id=? AND s.workspace_id=?
                ORDER BY s.create_time DESC, s.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getRecentLogs: ' . $e->getMessage());
            return [];
        }
    }

    /** 最近添加的物料 */
    public function getRecentParts(): array
    {
        try {
            $limit = self::RECENT_PARTS_LIMIT;
            $stmt = $this->db->prepare("
                SELECT id, model, platform_part_no, product_name, stock, create_time
                FROM parts
                WHERE user_id=? AND workspace_id=? AND status!='deleted'
                ORDER BY create_time DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getRecentParts: ' . $e->getMessage());
            return [];
        }
    }

    /** 最近更新的 BOM 项目 */
    public function getRecentBomProjects(): array
    {
        try {
            $limit = self::RECENT_BOM_LIMIT;
            $stmt = $this->db->prepare("
                SELECT id, name, description, created_at
                FROM bom_projects
                WHERE user_id=? AND workspace_id=?
                ORDER BY updated_at DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getRecentBomProjects: ' . $e->getMessage());
            return [];
        }
    }

    /** 本周（周一起算）出入库笔数与数量 */
    public function getWeekBriefing(): array
    {
        $week = ['in_count' => 0, 'in_qty' => 0, 'out_count' => 0, 'out_qty' => 0];
        try {
            $monday = date('Y-m-d 00:00:00', strtotime('monday this week'));
            $stmt = $this->db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN qty_change > 0 THEN 1 ELSE 0 END), 0) AS in_count,
                    COALESCE(SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END), 0) AS in_qty,
                    COALESCE(SUM(CASE WHEN qty_change < 0 THEN 1 ELSE 0 END), 0) AS out_count,
                    COALESCE(SUM(CASE WHEN qty_change < 0 THEN ABS(qty_change) ELSE 0 END), 0) AS out_qty
                FROM stock_log WHERE user_id=? AND workspace_id=? AND create_time >= ?
            ");
            $stmt->execute([$this->dataUid, $this->wsId, $monday]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $week = $row;
        } catch (\Throwable $e) { error_log('DashboardReadService::getWeekBriefing: ' . $e->getMessage()); }
        return $week;
    }

    /** 近 7 天出入库趋势，按天聚合并**补齐缺口为 0** */
    public function getTrend(): array
    {
        $trend = ['labels' => [], 'in' => [], 'out' => [], 'in_count' => [], 'out_count' => []];
        try {
            $from = date('Y-m-d 00:00:00', strtotime('-' . (self::TREND_DAYS - 1) . ' days'));
            $stmt = $this->db->prepare("
                SELECT DATE(create_time) AS d,
                    COALESCE(SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END), 0) AS in_qty,
                    COALESCE(SUM(CASE WHEN qty_change < 0 THEN ABS(qty_change) ELSE 0 END), 0) AS out_qty,
                    COALESCE(SUM(CASE WHEN qty_change > 0 THEN 1 ELSE 0 END), 0) AS in_cnt,
                    COALESCE(SUM(CASE WHEN qty_change < 0 THEN 1 ELSE 0 END), 0) AS out_cnt
                FROM stock_log
                WHERE user_id=? AND workspace_id=? AND create_time >= ?
                GROUP BY DATE(create_time)
            ");
            $stmt->execute([$this->dataUid, $this->wsId, $from]);
            $byDay = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $byDay[(string)$r['d']] = $r;
            }
            for ($i = self::TREND_DAYS - 1; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-" . $i . " days"));
                $row = $byDay[$day] ?? null;
                $trend['labels'][]    = date('n/j', strtotime($day));
                $trend['in'][]        = (int)($row['in_qty'] ?? 0);
                $trend['out'][]       = (int)($row['out_qty'] ?? 0);
                $trend['in_count'][]  = (int)($row['in_cnt'] ?? 0);
                $trend['out_count'][] = (int)($row['out_cnt'] ?? 0);
            }
        } catch (\Throwable $e) { error_log('DashboardReadService::getTrend: ' . $e->getMessage()); }
        return $trend;
    }

    /** 库存结构：按**一级分类**聚合（横向条形 / 竖直柱形图的数据源） */
    public function getCategoryDistribution(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT CASE
                           -- ⚠️ 必须同时判 `id IS NOT NULL`：LEFT JOIN 未命中时整行都是 NULL，
                           -- 只判 `parent_id IS NULL` 会把「没有这一级」误当成「这一级是根」，
                           -- 结果整张图只剩一条 name=NULL（2026-10-22 用户反馈验证）。
                           WHEN c4.id IS NOT NULL AND c4.parent_id IS NULL THEN c4.name
                           WHEN c3.id IS NOT NULL AND c3.parent_id IS NULL THEN c3.name
                           WHEN c2.id IS NOT NULL AND c2.parent_id IS NULL THEN c2.name
                           WHEN c1.id IS NOT NULL AND c1.parent_id IS NULL THEN c1.name
                           WHEN c0.id IS NOT NULL AND c0.parent_id IS NULL THEN c0.name
                           ELSE '未分类'
                       END AS cat_name,
                       COUNT(DISTINCT p.id) AS kinds,
                       COALESCE(SUM(p.stock), 0) AS qty
                FROM parts p
                LEFT JOIN part_categories pc ON pc.part_id = p.id
                LEFT JOIN categories c0 ON c0.id = pc.category_id
                LEFT JOIN categories c1 ON c1.id = c0.parent_id AND c0.parent_id > 0
                LEFT JOIN categories c2 ON c2.id = c1.parent_id AND c1.parent_id > 0
                LEFT JOIN categories c3 ON c3.id = c2.parent_id AND c2.parent_id > 0
                LEFT JOIN categories c4 ON c4.id = c3.parent_id AND c3.parent_id > 0
                WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted'
                -- 别名不能叫 name：categories 表本身有 name 列，GROUP BY name 会歧义
                GROUP BY cat_name
                HAVING qty > 0
                ORDER BY qty DESC, cat_name ASC
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$r) {
                $r['name'] = trim((string)($r['cat_name'] ?? ''));
                if ($r['name'] === '') $r['name'] = '未分类';
                unset($r['cat_name']);
            }
            unset($r);

            if (count($rows) <= self::CAT_DIST_LIMIT) {
                return $rows;
            }
            $top    = array_slice($rows, 0, self::CAT_DIST_LIMIT);
            $others = array_slice($rows, self::CAT_DIST_LIMIT);
            $otherQty = 0;
            $otherKinds = 0;
            foreach ($others as $r) {
                $otherQty   += (int)$r['qty'];
                $otherKinds += (int)$r['kinds'];
            }
            $top[] = ['name' => '其他', 'kinds' => $otherKinds, 'qty' => $otherQty];
            return $top;
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getCategoryDistribution: ' . $e->getMessage());
            return [];
        }
    }

    /** 近 7 天**未复核**的撤销流水条数 */
    public function getUndoPendingCount(): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM stock_log
                WHERE user_id=? AND workspace_id=? AND change_type IN ('scan_undo_in','scan_undo_out')
                  AND reviewed_at IS NULL
                  AND create_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$this->dataUid, $this->wsId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getUndoPendingCount: ' . $e->getMessage());
            return 0;
        }
    }

    /** 严重级数据质量问题（缺型号 / 库存缓存不一致 / 库位引用失效 / 版本落后） */
    public function getDataQualityAlerts(): array
    {
        try {
            require_once __DIR__ . '/DashboardAlertAggregator.php';
            return (new DashboardAlertAggregator($this->db, $this->uid, $this->dataUid))->getCriticalAlerts();
        } catch (\Throwable $e) {
            error_log('DashboardReadService::getDataQualityAlerts: ' . $e->getMessage());
            return [];
        }
    }

    /** 补齐依赖其他取数的派生指标 */
    public static function fillDerived(array &$data): void
    {
        $trend = $data['trend'];
        $data['trendInTotal']  = array_sum($trend['in']);
        $data['trendOutTotal'] = array_sum($trend['out']);

        $data['catDistQtyTotal'] = array_sum(array_map(
            static fn($r) => (int)$r['qty'],
            $data['catDist']
        ));

        $data['todoCount'] = $data['lowStockCount']
            + (int)($data['stats']['zero_count'] ?? 0)
            + $data['undoPending']
            + (int)($data['anomaly']['total'] ?? 0)
            + (int)($data['dqIssueCount'] ?? 0);

        $stockKinds   = $data['stockKinds'];
        $healthyKinds = max(0, $stockKinds - $data['lowStockCount'] - (int)($data['anomaly']['negative'] ?? 0));
        $data['healthRate'] = $stockKinds > 0 ? (int)round($healthyKinds / $stockKinds * 100) : 100;
    }
}
