<?php
declare(strict_types=1);

namespace App\Core\Migration;

use PDO;
use Throwable;

/** 迁移历史读取（页面「历史记录」区用） */
final class MigrationHistory
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 最近 N 条迁移记录 */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT id, source_version, target_version, status, start_time, end_time, result
                 FROM migration_history ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute();
            return array_map(function (array $r): array {
                return [
                    'id'             => (int)$r['id'],
                    'source_version' => (string)$r['source_version'],
                    'target_version' => (string)$r['target_version'],
                    'status'         => (string)$r['status'],
                    'start_time'     => substr((string)$r['start_time'], 0, 19),
                    'end_time'       => $r['end_time'] ? substr((string)$r['end_time'], 0, 19) : null,
                    'result'         => (string)($r['result'] ?? ''),
                ];
            }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** 是否有正在执行中的迁移（用于页面提示「上次迁移疑似中断」） */
    public function hasPending(): bool
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM migration_history WHERE status='pending'")->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
