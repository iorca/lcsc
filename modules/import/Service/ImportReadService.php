<?php
declare(strict_types=1);

namespace Modules\Import\Service;

use PDO;

/** ImportReadService — 导入订单页读模型 */
final class ImportReadService
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

    /** 平台列表 + 默认平台 code */
    public function getPlatformsWithDefault(): array
    {
        $platforms   = [];
        $defaultPlat = 'lcsc';
        try {
            $stmt = $this->db->prepare("SELECT * FROM platforms WHERE user_id=? AND workspace_id=? ORDER BY id");
            $stmt->execute([$this->dataUid, $this->wsId]);
            $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($platforms as $pp) {
                if (($pp['is_default'] ?? 0) == 1) { $defaultPlat = $pp['code']; break; }
            }
        } catch (\Throwable $e) {
            error_log('ImportReadService::getPlatformsWithDefault: ' . $e->getMessage());
        }
        return [$platforms, $defaultPlat];
    }

    /** 最近 5 条导入错误的汇总（按 import_id 聚合，取最早发生时间排序） */
    public function getRecentErrors(): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare("SELECT import_id,COUNT(*) AS cnt,MIN(created_at) AS t FROM import_errors WHERE user_id=? AND workspace_id=? GROUP BY import_id ORDER BY t DESC LIMIT 5");
            $stmt->execute([$this->uid, $this->wsId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('ImportReadService::getRecentErrors: ' . $e->getMessage());
        }
        return $rows;
    }

    /** 已导入文件列表（全字段，按创建时间倒序取 10 条） */
    public function getImportedFiles(): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare("SELECT * FROM imported_files WHERE user_id=? AND workspace_id=? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$this->uid, $this->wsId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('ImportReadService::getImportedFiles: ' . $e->getMessage());
        }
        return $rows;
    }

    /** 最近导入批次（含状态，可撤销/查看详情） */
    public function getRecentBatches(): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare("SELECT id, import_id, file_name, platform, total_rows, inserted, updated, skipped, errors, status, import_mode, created_at, rolled_back_at
                                        FROM imported_files WHERE user_id=? AND workspace_id=? ORDER BY id DESC LIMIT 10");
            $stmt->execute([$this->uid, $this->wsId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('ImportReadService::getRecentBatches: ' . $e->getMessage());
        }
        return $rows;
    }

    /** 某次导入的全部失败明细（导入错误详情页 `Views/errors.php`） */
    public function getImportErrors(string $importId): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare("SELECT * FROM import_errors WHERE user_id=? AND import_id=? ORDER BY row_num ASC");
            $stmt->execute([$this->dataUid, $importId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('ImportReadService::getImportErrors: ' . $e->getMessage());
        }
        return $rows;
    }
}
