<?php
declare(strict_types=1);

namespace Modules\System\Service;

use PDO;

/** 系统模块读模型服务 */
final class SystemReadService
{
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

    /** 物料数与库存合计 */
    public function getExportStats(): array
    {
        $stats = ['parts' => 0, 'stock' => 0];
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS parts, COALESCE(SUM(stock),0) AS stock
                 FROM parts WHERE user_id=?"
            );
            $stmt->execute([$this->dataUid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $stats = $row;
        } catch (\Throwable $e) {
            error_log('SystemReadService::getExportStats: ' . $e->getMessage());
        }
        return $stats;
    }

    /** 低库存物料数 */
    public function countLowStock(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM parts p
                 WHERE p.user_id=? AND p.stock > 0 AND p.stock <= COALESCE(p.low_stock_threshold,
                    (SELECT c.low_stock_threshold FROM part_categories pc
                     JOIN categories c ON c.id=pc.category_id
                     WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
                    ?)"
            );
            $stmt->execute([$this->dataUid, \getGlobalThreshold()]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('SystemReadService::countLowStock: ' . $e->getMessage());
            return 0;
        }
    }

    /** 出入库记录总数 */
    public function countStockLogs(): int
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM stock_log WHERE user_id=?");
            $stmt->execute([$this->dataUid]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('SystemReadService::countStockLogs: ' . $e->getMessage());
            return 0;
        }
    }

    /** BOM 项目下拉（与 `BomService::listProjects` 同口径：含明细数、按最近更新排序） */
    public function getBomProjects(): array
    {
        $out = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT bp.id, bp.name,
                        (SELECT COUNT(*) FROM bom_items bi WHERE bi.project_id=bp.id) AS item_count
                 FROM bom_projects bp
                 WHERE bp.user_id=? AND bp.workspace_id=?
                 ORDER BY bp.updated_at DESC"
            );
            $stmt->execute([$this->dataUid, $this->wsId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'id'         => (int)$r['id'],
                    'name'       => (string)$r['name'],
                    'item_count' => (int)$r['item_count'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('SystemReadService::getBomProjects: ' . $e->getMessage());
        }
        return $out;
    }

    /** 一级分类下拉（盘点清单导出筛选用） */
    public function getTopCategories(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name FROM categories
                 WHERE user_id=? AND workspace_id=? AND (parent_id IS NULL OR parent_id = 0)
                 ORDER BY name ASC"
            );
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SystemReadService::getTopCategories: ' . $e->getMessage());
            return [];
        }
    }

    /** 平台下拉（盘点清单导出筛选用，默认平台优先） */
    public function getPlatforms(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, name FROM platforms
                 WHERE user_id=? AND workspace_id=?
                 ORDER BY is_default DESC, name ASC"
            );
            $stmt->execute([$this->dataUid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SystemReadService::getPlatforms: ' . $e->getMessage());
            return [];
        }
    }

    /** 待处理项按 `reason` 分组的计数摘要（待处理工作台 `Views/pending.php`） */
    public function getPendingSummary(): array
    {
        $summary = ['total' => 0, 'by_reason' => []];
        try {
            $stmt = $this->db->query("SELECT reason, COUNT(*) AS cnt FROM pending_items WHERE status = 'pending' GROUP BY reason ORDER BY cnt DESC");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $summary['by_reason'][$row['reason']] = (int)$row['cnt'];
                $summary['total'] += (int)$row['cnt'];
            }
        } catch (\Throwable $e) {
            error_log('SystemReadService::getPendingSummary: ' . $e->getMessage());
        }
        return $summary;
    }

    /** 简易版库存导出数据（个人版降级用：型号 / 数量 / 库位） */
    public function getInventorySimpleRows(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.model, p.stock, p.location FROM parts p
                 WHERE p.user_id=? AND p.stock > 0
                 ORDER BY p.location, p.model"
            );
            $stmt->execute([$this->dataUid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SystemReadService::getInventorySimpleRows: ' . $e->getMessage());
            return [];
        }
    }

    /** 完整版库存导出数据 */
    public function getInventoryFullRows(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.*, pl.name AS platform_name FROM parts p
                 LEFT JOIN platforms pl ON pl.id=p.platform_id
                 WHERE p.user_id=? ORDER BY p.update_time DESC"
            );
            $stmt->execute([$this->dataUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pids = array_column($rows, 'id');
            $catMap = [];
            if ($pids) {
                $in = implode(',', array_fill(0, count($pids), '?'));
                $cRes = $this->db->prepare(
                    "SELECT pc.part_id, c.name FROM part_categories pc
                     INNER JOIN categories c ON c.id=pc.category_id
                     WHERE pc.part_id IN ($in)"
                );
                $cRes->execute($pids);
                foreach ($cRes->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $catMap[$c['part_id']][] = $c['name'];
                }
            }
            return ['rows' => $rows, 'catMap' => $catMap];
        } catch (\Throwable $e) {
            error_log('SystemReadService::getInventoryFullRows: ' . $e->getMessage());
            return ['rows' => [], 'catMap' => []];
        }
    }

    /** 补货清单导出数据（含 `eff_threshold` 有效阈值与建议补货量） */
    public function getReplenishRows(): array
    {
        $globalThr = \getGlobalThreshold();
        try {
            $stmt = $this->db->prepare(
                "SELECT p.*, pl.name AS platform_name, pl.code AS platform_code,
                        COALESCE(p.low_stock_threshold,
                            (SELECT c.low_stock_threshold FROM part_categories pc
                             JOIN categories c ON c.id=pc.category_id
                             WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
                            ?) AS eff_threshold
                 FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id
                 WHERE p.user_id=? AND p.stock > 0 AND p.stock <= COALESCE(p.low_stock_threshold,
                    (SELECT c.low_stock_threshold FROM part_categories pc
                     JOIN categories c ON c.id=pc.category_id
                     WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
                    ?)
                 ORDER BY (eff_threshold - p.stock) DESC, p.platform_part_no ASC"
            );
            $stmt->execute([$globalThr, $this->dataUid, $globalThr]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SystemReadService::getReplenishRows: ' . $e->getMessage());
            return [];
        }

        foreach ($rows as &$r) {
            $effThr = (int)$r['eff_threshold'];
            $qty = max(0, $effThr - (int)$r['stock']) + $effThr;
            $r['replenish_qty'] = $qty < 10 ? 10 : $qty;
        }
        unset($r);
        return $rows;
    }

    /** 完整出入库记录导出数据 */
    public function getStockLogRows(): array
    {
        $scope = \isPrimaryAdmin()
            ? "WHERE sl.user_id IN (SELECT id FROM users WHERE id = ? OR parent_id = ?)"
            : "WHERE sl.user_id = ? OR sl.user_id = ?";
        try {
            $stmt = $this->db->prepare(
                "SELECT sl.*, u.username AS op_username, p.model, p.brand, p.location
                 FROM stock_log sl
                 LEFT JOIN users u ON u.id = sl.user_id
                 LEFT JOIN parts p ON p.id = sl.part_id
                 {$scope}
                 ORDER BY sl.create_time DESC, sl.id DESC"
            );
            $stmt->execute([$this->uid, $this->dataUid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('SystemReadService::getStockLogRows: ' . $e->getMessage());
            return [];
        }
    }
}
