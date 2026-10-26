<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use App\Core\Response;

/** 待处理物料服务 */
final class PendingItemService
{
    private \PDO $db;
    private int $uid;
    private int $dataUid;

    public function __construct(\PDO $db, int $uid, int $dataUid)
    {
        $this->db = $db;
        $this->uid = $uid;
        $this->dataUid = $dataUid;
    }

    /** 全量扫描异常物料并更新 pending_items 表 */
    public function scan(): array
    {
        $new = 0;
        $already = 0;

        // 1. 参数缺失（parameters_text 为空）
        $stmt = $this->db->prepare(
            "SELECT id FROM parts WHERE user_id = ? AND (parameters_text IS NULL OR parameters_text = '') AND status NOT IN ('deleted','scrapped')"
        );
        $stmt->execute([$this->dataUid]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $pid) {
            $r = $this->upsertPending((int)$pid, 'param_missing', json_encode(['field' => 'parameters_text']));
            $r === 'new' ? $new++ : $already++;
        }

        // 2. 库存为负
        $stmt = $this->db->prepare(
            "SELECT id, stock FROM parts WHERE user_id = ? AND stock < 0 AND status NOT IN ('deleted','scrapped')"
        );
        $stmt->execute([$this->dataUid]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $r = $this->upsertPending((int)$row['id'], 'negative_stock', json_encode(['stock' => (int)$row['stock']]));
            $r === 'new' ? $new++ : $already++;
        }

        // 3. 重复型号（同 model+brand）
        $stmt = $this->db->prepare(
            "SELECT model, brand, GROUP_CONCAT(id) AS ids, COUNT(*) AS cnt
             FROM parts WHERE user_id = ? AND model != '' AND status NOT IN ('deleted','scrapped')
             GROUP BY model, brand HAVING cnt > 1"
        );
        $stmt->execute([$this->dataUid]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids = explode(',', $row['ids']);
            foreach ($ids as $pid) {
                $r = $this->upsertPending((int)$pid, 'duplicate', json_encode([
                    'model' => $row['model'],
                    'brand' => $row['brand'],
                    'count' => (int)$row['cnt'],
                ]));
                $r === 'new' ? $new++ : $already++;
            }
        }

        // 4. 长期无流动（超过 N 天无出入库记录）

        require_once __DIR__ . '/../../assets/Service/AssetsService.php';
        $stagnantDays = \AssetManager::getStaleDays();
        $stmt = $this->db->prepare(
            "SELECT p.id, p.model
             FROM parts p
             LEFT JOIN stock_log sl ON sl.part_id = p.id AND sl.create_time > DATE_SUB(NOW(), INTERVAL ? DAY)
             WHERE p.user_id = ? AND p.stock > 0 AND p.status NOT IN ('deleted','scrapped')
             GROUP BY p.id HAVING COUNT(sl.id) = 0"
        );
        $stmt->execute([$stagnantDays, $this->dataUid]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $r = $this->upsertPending((int)$row['id'], 'no_flow', json_encode(['days' => $stagnantDays]));
            $r === 'new' ? $new++ : $already++;
        }

        return [
            'scanned' => $new + $already,
            'new'     => $new,
            'already' => $already,
        ];
    }

    /** 获取待处理物料列表（分页） */
    public function list(int $page = 1, int $perPage = 25, string $reason = '', string $status = 'pending'): array
    {
        $where = ["pi.status = ?"];
        $params = [$status];

        if ($reason !== '') {
            $where[] = "pi.reason = ?";
            $params[] = $reason;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM pending_items pi WHERE " . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT pi.*, p.model, p.brand, p.package, p.stock, p.product_type, p.platform_part_no
             FROM pending_items pi
             INNER JOIN parts p ON p.id = pi.part_id
             WHERE {$whereSql}
             ORDER BY pi.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** 批量修正（标记为已修复） */
    public function batchFix(array $ids): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE pending_items SET status = 'fixed', resolved_at = NOW() WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /** 标记忽略 */
    public function ignore(array $ids): int
    {
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "UPDATE pending_items SET status = 'ignored', resolved_at = NOW() WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    /** 统计摘要 */
    public function summary(): array
    {
        $stmt = $this->db->query(
            "SELECT reason, COUNT(*) AS cnt FROM pending_items WHERE status = 'pending' GROUP BY reason ORDER BY cnt DESC"
        );
        $byReason = [];
        $total = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byReason[$row['reason']] = (int)$row['cnt'];
            $total += (int)$row['cnt'];
        }
        return ['total' => $total, 'by_reason' => $byReason];
    }

    // ── 私有辅助 ──

    private function upsertPending(int $partId, string $reason, string $detail): string
    {
        $chk = $this->db->prepare("SELECT id, status FROM pending_items WHERE part_id = ? AND reason = ?");
        $chk->execute([$partId, $reason]);
        $existing = $chk->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {

            if ($existing['status'] !== 'pending') {
                $this->db->prepare("UPDATE pending_items SET status = 'pending', detail = ?, resolved_at = NULL WHERE id = ?")
                         ->execute([$detail, $existing['id']]);
            }
            return 'already';
        }

        $this->db->prepare(
            "INSERT INTO pending_items (part_id, reason, detail, status, created_at) VALUES (?, ?, ?, 'pending', NOW())"
        )->execute([$partId, $reason, $detail]);
        return 'new';
    }
}
