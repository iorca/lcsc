<?php
declare(strict_types=1);

namespace App\Helper;

/** 操作回滚服务 */
final class RollbackService
{
    private \PDO $db;
    private int $uid;

    public function __construct(\PDO $db, int $uid)
    {
        $this->db = $db;
        $this->uid = $uid;
    }

    /** 回滚单条 trace_log 记录 */
    public function rollback(int $traceId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM trace_log WHERE id = ?");
        $stmt->execute([$traceId]);
        $trace = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$trace) {
            return ['success' => false, 'message' => '日志记录不存在'];
        }

        $snapshotBefore = $trace['snapshot_before'] ? json_decode($trace['snapshot_before'], true) : null;
        if (!$snapshotBefore) {
            return ['success' => false, 'message' => '该记录无操作前快照，无法回滚'];
        }

        $targetType = $trace['target_type'] ?? '';
        $targetId = (int)($trace['target_id'] ?? 0);

        if ($targetId <= 0) {
            return ['success' => false, 'message' => '目标记录ID无效'];
        }

        // 根据目标类型执行回滚
        return match ($targetType) {
            'part'       => $this->rollbackPart($targetId, $snapshotBefore, $traceId),
            'category'   => $this->rollbackCategory($targetId, $snapshotBefore, $traceId),
            'platform'   => $this->rollbackGeneric('platforms', $targetId, $snapshotBefore, $traceId),
            'user'       => $this->rollbackGeneric('users', $targetId, $snapshotBefore, $traceId),
            'bom_project' => $this->rollbackGeneric('bom_projects', $targetId, $snapshotBefore, $traceId),
            default      => ['success' => false, 'message' => "不支持回滚类型：{$targetType}"],
        };
    }

    /** 获取可回滚操作列表（最近 N 条有快照的记录） */
    public function listRollable(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, user_id, action, target_type, target_id, detail, snapshot_before IS NOT NULL AS has_snapshot, create_time
             FROM trace_log
             WHERE snapshot_before IS NOT NULL
             ORDER BY id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── 私有方法 ──

    private function rollbackPart(int $partId, array $snapshot, int $traceId): array
    {
        // 检查物料是否存在
        $chk = $this->db->prepare("SELECT id FROM parts WHERE id = ?");
        $chk->execute([$partId]);
        if (!$chk->fetch()) {
            // 物料已被删除，尝试恢复
            return $this->restoreDeletedPart($partId, $snapshot, $traceId);
        }

        $setClauses = [];
        $params = [];
        $allowedFields = [
            'model', 'product_name', 'brand', 'package', 'product_type',
            'stock', 'defective', 'location', 'remark', 'low_stock_threshold',
            'platform_part_no', 'purchase_url', 'parameters_text',
            'status', 'avg_cost',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $snapshot)) {
                $setClauses[] = "`{$field}` = ?";
                $params[] = $snapshot[$field];
            }
        }

        if (empty($setClauses)) {
            return ['success' => false, 'message' => '快照中无可回滚字段'];
        }

        $params[] = $partId;
        $sql = "UPDATE parts SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $this->db->prepare($sql)->execute($params);

        // 记录回滚操作
        \traceLog($this->uid, 'rollback', 'part', $partId, '回滚 trace_log#' . $traceId);

        return ['success' => true, 'message' => '物料数据已回滚'];
    }

    private function restoreDeletedPart(int $partId, array $snapshot, int $traceId): array
    {

        $fields = [];
        $values = [];
        $placeholders = [];

        $snapshot['id'] = $partId;
        $snapshot['status'] = $snapshot['status'] ?? 'active';

        foreach ($snapshot as $k => $v) {
            if (in_array($k, ['id', 'model', 'product_name', 'brand', 'package', 'product_type',
                'stock', 'defective', 'location', 'remark', 'low_stock_threshold',
                'platform_part_no', 'purchase_url', 'parameters_text',
                'status', 'avg_cost', 'user_id'])) {
                $fields[] = "`{$k}`";
                $values[] = $v;
                $placeholders[] = '?';
            }
        }

        if (empty($fields)) {
            return ['success' => false, 'message' => '快照数据不足，无法恢复'];
        }

        $sql = "INSERT INTO parts (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        try {
            $this->db->prepare($sql)->execute($values);
            \traceLog($this->uid, 'rollback_restore', 'part', $partId, '回滚恢复已删除物料 trace_log#' . $traceId);
            return ['success' => true, 'message' => '已删除物料已恢复'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '恢复失败：' . $e->getMessage()];
        }
    }

    private function rollbackCategory(int $catId, array $snapshot, int $traceId): array
    {
        $chk = $this->db->prepare("SELECT id FROM categories WHERE id = ?");
        $chk->execute([$catId]);
        if (!$chk->fetch()) {
            return ['success' => false, 'message' => '分类已删除，无法回滚（删除分类影响范围大，建议手动重建）'];
        }

        return $this->rollbackGeneric('categories', $catId, $snapshot, $traceId);
    }

    private function rollbackGeneric(string $table, int $id, array $snapshot, int $traceId): array
    {
        $setClauses = [];
        $params = [];

        foreach ($snapshot as $field => $value) {
            if ($field === 'id') continue;
            $setClauses[] = "`{$field}` = ?";
            $params[] = $value;
        }

        if (empty($setClauses)) {
            return ['success' => false, 'message' => '快照中无可回滚字段'];
        }

        $params[] = $id;
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setClauses) . " WHERE id = ?";
        try {
            $this->db->prepare($sql)->execute($params);
            \traceLog($this->uid, 'rollback', $table, $id, '回滚 trace_log#' . $traceId);
            return ['success' => true, 'message' => '数据已回滚'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '回滚失败：' . $e->getMessage()];
        }
    }
}
