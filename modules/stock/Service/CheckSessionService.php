<?php
declare(strict_types=1);

namespace Modules\Stock\Service;

use PDO;
use Throwable;

/** 在线盘点服务（P7：盘点单 → 在线录数 → 差异单确认 → 应用调整，全程留痕） */
final class CheckSessionService
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid        = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = (int)\getWorkspaceId();
    }

    /** 盘点页筛选项：一级分类 / 平台 / 已有库位文本 */
    public function getFilterOptions(): array
    {
        $out = ['allCats' => [], 'allPlats' => [], 'allLocs' => []];

        try {
            $stmt = $this->db->prepare(
                "SELECT id, name FROM categories
                 WHERE user_id=? AND workspace_id=?
                   AND (parent_id IS NULL OR parent_id = 0) ORDER BY name ASC"
            );
            $stmt->execute([$this->dataUid, $this->workspaceId]);
            $out['allCats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('CheckSessionService::getFilterOptions(categories): ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT id, name FROM platforms
                 WHERE user_id=? AND workspace_id=? ORDER BY is_default DESC, name ASC"
            );
            $stmt->execute([$this->dataUid, $this->workspaceId]);
            $out['allPlats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('CheckSessionService::getFilterOptions(platforms): ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT location FROM parts
                 WHERE user_id=? AND workspace_id=? AND location<>'' ORDER BY location"
            );
            $stmt->execute([$this->dataUid, $this->workspaceId]);
            $out['allLocs'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'location');
        } catch (Throwable $e) {
            error_log('CheckSessionService::getFilterOptions(locations): ' . $e->getMessage());
        }

        return $out;
    }

    /** 发起盘点单（范围筛选 + 库存快照） */
    public function createSession(array $scope): array
    {
        $title  = mb_substr(trim((string)($scope['title'] ?? '')), 0, 100);
        $remark = mb_substr(trim((string)($scope['remark'] ?? '')), 0, 500);
        if ($title === '') $title = '在线盘点 ' . date('m-d H:i');

        $where = "WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted'";
        $binds = [$this->dataUid, $this->workspaceId];
        if (!empty($scope['category_id'])) {
            $where .= " AND EXISTS(SELECT 1 FROM part_categories pc WHERE pc.part_id=p.id AND pc.category_id=?)";
            $binds[] = (int)$scope['category_id'];
        }
        if (!empty($scope['location'])) {
            $where .= " AND p.location LIKE ?";
            $binds[] = '%' . trim((string)$scope['location']) . '%';
        }
        if (!empty($scope['platform_id'])) {
            $where .= " AND p.platform_id=?";
            $binds[] = (int)$scope['platform_id'];
        }

        $stmt = $this->db->prepare("SELECT p.id, p.stock FROM parts p $where ORDER BY p.location, p.model");
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['ok' => false, 'msg' => '所选范围内没有可盘点的物料'];

        $scopeJson = json_encode([
            'category_id' => (int)($scope['category_id'] ?? 0),
            'location'    => trim((string)($scope['location'] ?? '')),
            'platform_id' => (int)($scope['platform_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO check_sessions (workspace_id, user_id, title, scope_json, status, total_items, remark)
                 VALUES (?,?,?,?, 'counting', ?, ?)"
            )->execute([$this->workspaceId, $this->uid, $title, $scopeJson, count($rows), $remark]);
            $sessionId = (int)$this->db->lastInsertId();

            $ins = $this->db->prepare(
                "INSERT INTO check_session_items (session_id, part_id, snapshot_qty) VALUES (?,?,?)"
            );
            foreach ($rows as $r) {
                $ins->execute([$sessionId, (int)$r['id'], (int)$r['stock']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('CheckSessionService createSession: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '盘点单创建失败，已回滚'];
        }

        \traceLog($this->uid, 'check_create', 'check_session', $sessionId, "发起在线盘点 id:{$sessionId} 「{$title}」共 " . count($rows) . " 项");
        return ['ok' => true, 'session_id' => $sessionId, 'total' => count($rows)];
    }

    /** 盘点单列表（分页，含状态筛选） */
    public function listSessions(int $page = 1, int $perPage = 10, string $status = '', string $sort = 'status'): array
    {
        $where = "WHERE s.workspace_id=?";
        $bind  = [$this->workspaceId];
        if ($status !== '' && in_array($status, ['counting', 'confirmed', 'cancelled'], true)) {
            $where .= " AND s.status=?";
            $bind[] = $status;
        }
        $perPage = max(1, min(50, $perPage));
        $page    = max(1, $page);

        // 排序：盘点中置顶（业务未结束的单永远可见），其余按时间倒序
        $orderBy = $sort === 'recent'
            ? "s.id DESC"
            : "CASE s.status WHEN 'counting' THEN 0 WHEN 'confirmed' THEN 1 ELSE 2 END ASC, s.id DESC";

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM check_sessions s $where");
        $cnt->execute($bind);
        $total = (int)$cnt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT s.*, u.username AS creator
             FROM check_sessions s LEFT JOIN users u ON u.id=s.user_id
             $where ORDER BY $orderBy LIMIT " . (($page - 1) * $perPage) . ", $perPage"
        );
        $stmt->execute($bind);
        $items = array_map([$this, 'mapSessionRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'items' => $items, 'total' => $total,
            'page' => $page, 'per_page' => $perPage,
            'total_page' => (int)ceil($total / $perPage),
        ];
    }

    /** 盘点单详情（含明细，分页 + 筛选：all/pending/counted/diff/skipped + 关键词） */
    public function getSession(int $sessionId, array $params = []): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session) return ['ok' => false, 'msg' => '盘点单不存在'];

        $filter  = (string)($params['filter'] ?? 'all');
        $keyword = trim((string)($params['q'] ?? ''));
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(10, min(200, (int)($params['per_page'] ?? 50)));

        $where = "WHERE i.session_id=?";
        $bind  = [$sessionId];
        switch ($filter) {
            case 'pending': $where .= " AND i.item_status='pending'"; break;
            case 'counted': $where .= " AND i.item_status='counted'"; break;
            case 'skipped': $where .= " AND i.item_status='skipped'"; break;
            case 'diff':    $where .= " AND i.item_status='counted' AND i.diff<>0"; break;
        }
        if ($keyword !== '') {
            $where .= " AND (p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ?)";
            $kw = '%' . $keyword . '%';
            array_push($bind, $kw, $kw, $kw);
        }

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM check_session_items i INNER JOIN parts p ON p.id=i.part_id $where");
        $cnt->execute($bind);
        $total = (int)$cnt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT i.id, i.part_id, i.snapshot_qty, i.counted_qty, i.item_status, i.diff, i.remark, i.count_time,
                    p.internal_id, p.platform_part_no, p.model, p.product_name, p.brand, p.package, p.location, p.stock AS current_qty
             FROM check_session_items i INNER JOIN parts p ON p.id=i.part_id
             $where ORDER BY p.location, p.model LIMIT " . (($page - 1) * $perPage) . ", $perPage"
        );
        $stmt->execute($bind);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agg = $this->db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN item_status='counted' THEN 1 ELSE 0 END) AS counted,
                    SUM(CASE WHEN item_status='counted' AND diff<>0 THEN 1 ELSE 0 END) AS diff_cnt,
                    SUM(CASE WHEN item_status='skipped' THEN 1 ELSE 0 END) AS skipped
             FROM check_session_items WHERE session_id=?"
        );
        $agg->execute([$sessionId]);
        $a = $agg->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'ok' => true,
            'session'  => $this->mapSessionRow($session),
            'items'    => $items,
            'stats'    => [
                'total'    => (int)($a['total'] ?? 0),
                'counted'  => (int)($a['counted'] ?? 0),
                'diff'     => (int)($a['diff_cnt'] ?? 0),
                'skipped'  => (int)($a['skipped'] ?? 0),
            ],
            'total'    => $total, 'page' => $page,
            'per_page' => $perPage, 'total_page' => (int)ceil(max(1, $total) / $perPage),
        ];
    }

    /** 录入实盘数（单项或批量） */
    public function countItems(int $sessionId, array $items): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session) return ['ok' => false, 'msg' => '盘点单不存在'];
        if ($session['status'] !== 'counting') return ['ok' => false, 'msg' => '盘点单已结束，不能再录数'];

        $saved = 0; $skipped = 0;
        $upd = $this->db->prepare(
            "UPDATE check_session_items
             SET counted_qty=?, diff=? - snapshot_qty, item_status='counted', remark=?, count_time=NOW()
             WHERE id=? AND session_id=? AND item_status<>'skipped'"
        );
        foreach ($items as $it) {
            $itemId = (int)($it['item_id'] ?? 0);
            $qty    = (int)($it['counted_qty'] ?? 0);
            if ($itemId <= 0 || $qty < 0) { $skipped++; continue; }
            // 两次绑定同一占位符（diff = ? - snapshot_qty）→ 显式传参
            $remark = mb_substr(trim((string)($it['remark'] ?? '')), 0, 500);
            $upd->execute([$qty, $qty, $remark, $itemId, $sessionId]);
            $upd->rowCount() > 0 ? $saved++ : $skipped++;
        }

        $this->refreshCountedTotal($sessionId);
        if ($saved > 0) {
            \traceLog($this->uid, 'check_count', 'check_session', $sessionId, "盘点录数 id:{$sessionId} 本批{$saved}项" . ($skipped ? " 跳过{$skipped}项" : ''));
        }
        return ['ok' => true, 'saved' => $saved, 'skipped' => $skipped];
    }

    /** 跳过盘点行（盘不到实物时标记，不参与差异应用） */
    public function skipItems(int $sessionId, array $itemIds): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session) return ['ok' => false, 'msg' => '盘点单不存在'];
        if ($session['status'] !== 'counting') return ['ok' => false, 'msg' => '盘点单已结束'];

        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if (!$ids) return ['ok' => false, 'msg' => '请选择要跳过的行'];

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("UPDATE check_session_items SET item_status='skipped', counted_qty=NULL, diff=0 WHERE session_id=? AND id IN ($in)");
        $stmt->execute(array_merge([$sessionId], $ids));
        $n = $stmt->rowCount();

        $this->refreshCountedTotal($sessionId);
        \traceLog($this->uid, 'check_skip', 'check_session', $sessionId, "盘点跳过 id:{$sessionId} {$n}项");
        return ['ok' => true, 'skipped' => $n];
    }

    /** 重置跳过行（恢复为未盘） */
    public function unskipItems(int $sessionId, array $itemIds): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session || $session['status'] !== 'counting') return ['ok' => false, 'msg' => '盘点单不存在或已结束'];

        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if (!$ids) return ['ok' => false, 'msg' => '参数无效'];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("UPDATE check_session_items SET item_status='pending' WHERE session_id=? AND id IN ($in)");
        $stmt->execute(array_merge([$sessionId], $ids));
        $this->refreshCountedTotal($sessionId);
        return ['ok' => true, 'reset' => $stmt->rowCount()];
    }

    /** 预览差异单（确认前展示：差异行 + 冲突行） */
    public function previewDiff(int $sessionId): array
    {
        $r = $this->getSession($sessionId, ['filter' => 'diff', 'per_page' => 200]);
        if (!$r['ok']) return $r;

        $stmt = $this->db->prepare(
            "SELECT i.id, i.part_id, i.snapshot_qty, i.counted_qty, i.diff, p.model, p.platform_part_no, p.stock AS current_qty
             FROM check_session_items i INNER JOIN parts p ON p.id=i.part_id
             WHERE i.session_id=? AND i.item_status='counted' AND i.diff<>0"
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $diffs = []; $conflicts = [];
        foreach ($rows as $row) {
            if ((int)$row['current_qty'] !== (int)$row['snapshot_qty']) {
                $conflicts[] = $row;
            } else {
                $diffs[] = $row;
            }
        }
        return ['ok' => true, 'diffs' => $diffs, 'conflicts' => $conflicts, 'session' => $r['session']];
    }

    /** 确认差异单：应用调整（仅无冲突差异行） */
    public function confirmSession(int $sessionId): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session) return ['ok' => false, 'msg' => '盘点单不存在'];
        if ($session['status'] !== 'counting') return ['ok' => false, 'msg' => '盘点单已确认或已取消'];

        $diffRows = $this->previewDiff($sessionId);
        $diffs    = $diffRows['diffs'] ?? [];
        if (!$diffs) {
            // 无可应用差异：仅封单（不发调整流水）
            $this->db->prepare("UPDATE check_sessions SET status='confirmed', confirm_time=NOW(), confirm_uid=?, diff_items=0, adjusted_items=0 WHERE id=?")
                     ->execute([$this->uid, $sessionId]);
            \traceLog($this->uid, 'check_confirm', 'check_session', $sessionId, "确认盘点单 id:{$sessionId}（无差异，直接封单）");
            return ['ok' => true, 'adjusted' => 0, 'conflicts' => 0, 'msg' => '无账实差异，盘点单已封存'];
        }

        $adjusted = 0; $conflictIds = [];
        $this->db->beginTransaction();
        try {
            $lockStmt = $this->db->prepare(
                "SELECT p.stock FROM parts p INNER JOIN check_session_items i ON i.part_id=p.id
                 WHERE i.id=? FOR UPDATE"
            );
            foreach ($diffs as $row) {
                $lockStmt->execute([(int)$row['id']]);
                $cur = $lockStmt->fetchColumn();
                if ($cur === false || (int)$cur !== (int)$row['snapshot_qty']) {
                    $conflictIds[] = (int)$row['id'];
                    continue;
                }
                $itemId  = (int)$row['id'];
                $partId  = (int)$row['part_id'];
                $counted = (int)$row['counted_qty'];
                $before  = (int)$row['snapshot_qty'];
                $diff    = (int)$row['diff'];

                \insertStockLog($this->db, [
                    'user_id'          => $this->uid,
                    'part_id'          => $partId,
                    'platform_part_no' => (string)$row['platform_part_no'],
                    'change_type'      => 'checklist_adjust',
                    'qty_change'       => $diff,
                    'qty_before'       => $before,
                    'qty_after'        => $counted,
                    'remark'           => '在线盘点差异调整（盘点单 #' . $sessionId . '）',
                    'workspace_id'     => $this->workspaceId,
                    'location_id'      => $this->getPartLocationId($partId),
                    'source_type'      => 'checklist',
                    'source_id'        => $sessionId,
                ]);
                // 更新库存（绝对值：以实盘数为准）
                \applyStockSet($partId, $counted);
                // P8 动销清呆滞标记（盘点调整也算动销）
                try { $this->clearStaleProcessedMark($partId); } catch (Throwable $e) {}

                try { \syncPartStockCache($partId); } catch (Throwable $e) {}
                try { \markCostDirty($this->db, $partId); } catch (Throwable $e) {}
                $adjusted++;
            }

            // 封单回填统计
            $diffTotal = count($diffs) + count($diffRows['conflicts'] ?? []);
            $this->db->prepare(
                "UPDATE check_sessions SET status='confirmed', confirm_time=NOW(), confirm_uid=?, diff_items=?, adjusted_items=? WHERE id=?"
            )->execute([$this->uid, $diffTotal, $adjusted, $sessionId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('CheckSessionService confirmSession: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '盘点确认失败，已回滚'];
        }

        \traceLog($this->uid, 'check_confirm', 'check_session', $sessionId,
            "确认盘点单 id:{$sessionId} 应用调整{$adjusted}行" . ($conflictIds ? ' 冲突跳过' . count($conflictIds) . '行' : ''));
        return [
            'ok' => true, 'adjusted' => $adjusted,
            'conflicts' => count($conflictIds), 'conflict_ids' => $conflictIds,
        ];
    }

    /** 取消盘点单（仅盘点中状态可取消；已盘数据保留备查，不应用任何调整） */
    public function cancelSession(int $sessionId): array
    {
        $session = $this->getSessionRow($sessionId);
        if (!$session) return ['ok' => false, 'msg' => '盘点单不存在'];
        if ($session['status'] !== 'counting') return ['ok' => false, 'msg' => '盘点单已结束，不能取消'];

        $this->db->prepare("UPDATE check_sessions SET status='cancelled' WHERE id=?")->execute([$sessionId]);
        \traceLog($this->uid, 'check_cancel', 'check_session', $sessionId, "取消盘点单 id:{$sessionId}");
        return ['ok' => true];
    }

    private function getSessionRow(int $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, u.username AS creator FROM check_sessions s LEFT JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.workspace_id=?"
        );
        $stmt->execute([$sessionId, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function mapSessionRow(array $r): array
    {
        return [
            'id'             => (int)$r['id'],
            'title'          => (string)$r['title'],
            'status'         => (string)$r['status'],
            'total_items'    => (int)$r['total_items'],
            'counted_items'  => (int)$r['counted_items'],
            'diff_items'     => (int)$r['diff_items'],
            'adjusted_items' => (int)$r['adjusted_items'],
            'remark'         => (string)($r['remark'] ?? ''),
            'creator'        => (string)($r['creator'] ?? '?'),
            'create_time'    => substr((string)$r['create_time'], 0, 16),
            'confirm_time'   => $r['confirm_time'] ? substr((string)$r['confirm_time'], 0, 16) : null,
        ];
    }

    private function refreshCountedTotal(int $sessionId): void
    {
        $stmt = $this->db->prepare("UPDATE check_sessions SET counted_items=(SELECT COUNT(*) FROM check_session_items WHERE session_id=? AND item_status='counted') WHERE id=?");
        $stmt->execute([$sessionId, $sessionId]);
    }

    private function getPartLocationId(int $partId): ?int
    {
        try {
            $stmt = $this->db->prepare("SELECT location_id FROM parts WHERE id=?");
            $stmt->execute([$partId]);
            $v = $stmt->fetchColumn();
            return ($v === false || $v === null) ? null : (int)$v;
        } catch (Throwable $e) { return null; }
    }

    /** 清除物料 remark 开头的呆滞已处理前缀（P8 动销清标记） */
    private function clearStaleProcessedMark(int $partId): void
    {
        $stmt = $this->db->prepare("SELECT remark FROM parts WHERE id=? AND remark LIKE '[呆滞已处理%'");
        $stmt->execute([$partId]);
        $remark = $stmt->fetchColumn();
        if ($remark === false) return;
        $clean = preg_replace('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', '', (string)$remark);
        $this->db->prepare("UPDATE parts SET remark=? WHERE id=?")->execute([$clean, $partId]);
    }
}
