<?php
declare(strict_types=1);
/** 操作日志模块（v1.1.0 正式版） */

class LogException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message);
        $this->errCode = $errCode;
    }
}

/** 操作日志管理器 */
final class LogManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;

    /** 操作类型标签映射（供前端展示） */
    public const TYPE_LABELS = [
        'import'        => ['订单导入',     '#4f8ef7'],
        'manual_in'     => ['手动入库',     '#22c55e'],
        'manual_out'    => ['手动出库',     '#ef4444'],
        'adjust'        => ['库存调整',     '#f59e0b'],
        'scan_in'       => ['扫码入库',     '#22c55e'],
        'scan_out'      => ['扫码出库',     '#ef4444'],
        'damaged'       => ['报损',         '#8b5cf6'],
        'repair'        => ['修复',         '#8b5cf6'],
        'scan_undo_in'  => ['撤销扫码入库', '#f59e0b'],
        'scan_undo_out' => ['撤销扫码出库', '#f59e0b'],
        'bom_out'       => ['BOM出库',      '#ef4444'],
    ];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
    }

    /** 出入库记录列表查询 */
    public function listLogs(array $f): array
    {
        $searchKw = trim((string)($f['q'] ?? ''));
        $perPage  = (int)($f['per_page'] ?? $_COOKIE['per_page_log'] ?? 25);
        $perPage  = max(10, min(50, $perPage));
        $page     = max(1, (int)($f['page'] ?? 1));

        $whereSql = "WHERE l.user_id=?";
        $params = [$this->dataUid];
        if ($searchKw !== '') {
            $whereSql .= " AND (l.platform_part_no LIKE ? OR p.model LIKE ? OR l.remark LIKE ? OR l.change_type LIKE ?)";
            $kw = '%' . $searchKw . '%';
            array_push($params, $kw, $kw, $kw, $kw);
        }

        $cntStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM stock_log l LEFT JOIN parts p ON p.id=l.part_id $whereSql"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // 列表查询（含操作人/操作IP，供流水中心-操作日志视图展示）
        $sql = "SELECT l.id, l.platform_part_no, l.change_type, l.qty_change, l.remark, l.create_time,
                       l.operator, l.ip, p.model
                FROM stock_log l LEFT JOIN parts p ON p.id=l.part_id
                $whereSql
                ORDER BY l.create_time DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($logs as &$l) {
            $l['id']           = (int)$l['id'];
            $l['qty_change']   = (int)$l['qty_change'];
            $l['model']        = $l['model'] ?? '';
            $l['remark']       = $l['remark'] ?? '';
            $l['platform_part_no'] = $l['platform_part_no'] ?? '';
            $l['operator']     = $l['operator'] ?? '';
            $l['ip']           = $l['ip'] ?? '';
        }
        unset($l);

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'type_labels' => self::TYPE_LABELS,
            'q'           => $searchKw,
        ];
    }

    /** 查询指定 ID 的流水记录（用于 CSV 导出） */
    public function getLogsForExport(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) {
            throw new LogException('未选择记录', 4);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT l.id, l.platform_part_no, l.change_type, l.qty_change, l.remark, l.create_time,
                       l.operator, l.ip, p.model
                FROM stock_log l LEFT JOIN parts p ON p.id=l.part_id
                WHERE l.user_id=? AND l.id IN ($in)
                ORDER BY l.create_time DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->dataUid], $ids));
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($logs)) {
            throw new LogException('所选记录不存在或无权访问', 404);
        }

        return $logs;
    }
}
