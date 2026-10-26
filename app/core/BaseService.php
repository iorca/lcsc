<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/** 业务服务基类 */
abstract class BaseService
{
    protected PDO $db;
    protected int $uid;       // 当前操作用户ID（日志记录用）
    protected int $dataUid;   // 数据所属用户ID（子用户继承父用户数据）

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
    }

    protected function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    protected function commit(): void
    {
        $this->db->commit();
    }

    protected function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** 在事务中执行回调，自动 begin/commit/rollback */
    protected function inTransaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** 通用分页查询 */
    protected function paginate(
        string $countSql,
        string $dataSql,
        array $params = [],
        int $page = 1,
        int $perPage = 25,
        ?string $countCol = null
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        // 总数
        $cntStmt = $this->db->prepare($countSql);
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn($countCol === null ? 0 : 0);
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;

        // 数据
        $stmt = $this->db->prepare($dataSql . " LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_page' => $totalPage,
        ];
    }

    /** 写入溯源日志（薄封装，委托全局 traceLog 函数） */
    protected function writeTrace(
        string $action,
        string $targetType,
        int $targetId,
        string $detail,
        ?string $businessTable = null,
        ?int $businessId = null
    ): void {
        // 先尝试带 business_table/business_id 字段写入
        if ($businessTable !== null && $businessId !== null) {
            try {
                $this->db->prepare(
                    "INSERT INTO trace_log (user_id, action, target_type, target_id, detail, ip, business_table, business_id, create_time)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                )->execute([
                    $this->uid,
                    $action,
                    $targetType,
                    $targetId,
                    $detail,
                    $_SERVER['REMOTE_ADDR'] ?? '',
                    $businessTable,
                    $businessId,
                ]);
                return;
            } catch (Throwable) {
                // 字段不存在时回退到旧格式
            }
        }
        // 回退：委托全局函数（兼容旧表结构）
        if (function_exists('traceLog')) {
            \traceLog($this->uid, $action, $targetType, $targetId, $detail);
        }
    }

    /** 查询单行（返回关联数组或 null） */
    protected function fetchRow(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 查询多行 */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 查询单值（第一行第一列） */
    protected function fetchColumn(string $sql, array $params = [], int $col = 0): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn($col);
    }

    /** 执行写操作（INSERT/UPDATE/DELETE），返回影响行数 */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** 安全字符串转换 */
    protected function safeStr(mixed $v): string
    {
        return trim((string)($v ?? ''));
    }

    /** 安全整数转换 */
    protected function safeInt(mixed $v): int
    {
        return (int)($v ?? 0);
    }

    /** 获取当前用户名（用于 operator 字段） */
    protected function getCurrentUsername(): string
    {
        try {
            if (function_exists('currentUser')) {
                $u = \currentUser();
                return $u['username'] ?? '';
            }
        } catch (Throwable) {}
        return '';
    }

    /** 过滤有效 ID 数组（去重、去零） */
    protected function filterValidIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $out[] = $id;
        }
        return array_values(array_unique($out));
    }
}
