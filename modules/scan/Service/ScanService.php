<?php
declare(strict_types=1);

namespace Modules\Scan\Service;

use PDO;

/** 扫码页读模型服务 */
final class ScanService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 今日扫码统计（按 in / out 分组取次数与数量） */
    public function getTodayStats(int $uid): array
    {
        $empty = ['in' => 0, 'out' => 0, 'inQty' => 0, 'outQty' => 0];
        try {
            $stmt = $this->db->prepare(
                "SELECT scan_type, COUNT(*) AS cnt, COALESCE(SUM(qty),0) AS total_qty
                 FROM scan_log WHERE user_id=? AND DATE(created_at)=? GROUP BY scan_type"
            );
            $stmt->execute([$uid, date('Y-m-d')]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isIn  = ($row['scan_type'] === 'in');
                $empty[$isIn ? 'in' : 'out']    = (int)$row['cnt'];
                $empty[$isIn ? 'inQty' : 'outQty'] = (int)$row['total_qty'];
            }
        } catch (\Throwable $e) {
            error_log('ScanService::getTodayStats: ' . $e->getMessage());
        }
        return $empty;
    }

    /** 最近扫描记录（仅当日，倒序取 30 条） */
    public function getRecentScans(int $uid, int $limit = 30): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT sl.*, p.model
                 FROM scan_log sl
                 LEFT JOIN parts p ON p.id = sl.part_id
                 WHERE sl.user_id=? AND DATE(sl.created_at)=CURDATE()
                 ORDER BY sl.created_at DESC
                 LIMIT " . max(1, min(100, $limit))
            );
            $stmt->execute([$uid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('ScanService::getRecentScans: ' . $e->getMessage());
            return [];
        }
    }

    /** 平台下拉（新建物料表单选择归属平台） */
    public function getPlatforms(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, code, name, platform_type, is_default
                 FROM platforms WHERE user_id=? AND workspace_id=?
                 ORDER BY is_default DESC, id"
            );
            $stmt->execute([(int)\getDataUserId(), (int)\getWorkspaceId()]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('ScanService::getPlatforms: ' . $e->getMessage());
            return [];
        }
        foreach ($list as &$p) {
            $p['id']            = (int)$p['id'];
            $p['is_default']    = (int)$p['is_default'];
            $p['platform_type'] = in_array($p['platform_type'] ?? '', ['standard', 'loose'], true)
                ? $p['platform_type'] : 'standard';
        }
        unset($p);
        return $list;
    }
}
