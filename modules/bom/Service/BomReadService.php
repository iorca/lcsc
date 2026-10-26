<?php
declare(strict_types=1);

namespace Modules\Bom\Service;

use PDO;

/** BomReadService — BOM 管理页读模型 */
final class BomReadService
{
    private PDO $db;
    private int $dataUid;

    public function __construct(PDO $db, int $dataUid)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
    }

    /** 平台列表 + 默认平台 ID */
    public function getPlatformsWithDefault(): array
    {
        $platforms    = [];
        $defaultPlatId = 1;
        try {
            $stmt = $this->db->prepare("SELECT id,code,name,url_template,is_default FROM platforms WHERE user_id=? ORDER BY id");
            $stmt->execute([$this->dataUid]);
            $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($platforms as $pp) {
                if (($pp['is_default'] ?? 0) == 1) { $defaultPlatId = (int)$pp['id']; break; }
            }
        } catch (\Throwable $e) {
            error_log('BomReadService::getPlatformsWithDefault: ' . $e->getMessage());
        }
        return [$platforms, $defaultPlatId];
    }

    /** BOM 项目列表（含物料数 / 当前版本 / 已发布版本数） */
    public function getProjectList(): array
    {
        $rows = [];
        try {
            $stmt = $this->db->prepare("SELECT bp.id,bp.name,bp.description,bp.plat_id,bp.version_managed,bp.start_version,bp.project_type,bp.status,bp.priority,bp.created_at,bp.updated_at,
                (SELECT COUNT(*) FROM bom_items bi WHERE bi.project_id=bp.id) AS item_count,
                (SELECT v.version FROM bom_versions v WHERE v.id=bp.current_version_id) AS current_version,
                (SELECT COUNT(*) FROM bom_versions v WHERE v.project_id=bp.id AND v.workspace_id=bp.workspace_id AND v.status='released') AS released_count
                FROM bom_projects bp
                WHERE bp.user_id=?
                GROUP BY bp.id
                ORDER BY bp.updated_at DESC");
            $stmt->execute([$this->dataUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('BomReadService::getProjectList: ' . $e->getMessage());
        }
        return $rows;
    }

    /** 选中项目的表头（项目基本信息） */
    public function getProjectHeader(int $projectId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT bp.id,bp.name,bp.description,bp.plat_id,bp.current_version_id,bp.version_managed,bp.start_version,bp.created_at,bp.updated_at
                FROM bom_projects bp
                WHERE bp.id=? AND bp.user_id=?");
            $stmt->execute([$projectId, $this->dataUid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('BomReadService::getProjectHeader: ' . $e->getMessage());
            return null;
        }
    }
}
