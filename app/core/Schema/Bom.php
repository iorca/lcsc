<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：BOM 与替代料域 */
function schemaBom(\PDO $db): void
{
    // ── BOM项目表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS bom_projects (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        name        VARCHAR(200) NOT NULL,
        description VARCHAR(500) DEFAULT '',
        plat_id     INT DEFAULT 1,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $col = $db->query("SHOW COLUMNS FROM bom_projects LIKE 'current_version_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_projects ADD COLUMN current_version_id INT DEFAULT NULL COMMENT '当前编辑草稿版本ID' AFTER description");
        }
    } catch (Throwable $e) {}

    try {
        $col = $db->query("SHOW COLUMNS FROM bom_projects LIKE 'version_managed'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_projects ADD COLUMN version_managed TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用版本管理' AFTER current_version_id");
        }
    } catch (Throwable $e) {}
    // bom_projects 增加 start_version（初始版本号预设）
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_projects LIKE 'start_version'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_projects ADD COLUMN start_version VARCHAR(20) NOT NULL DEFAULT 'V1.0' COMMENT '初始版本号' AFTER version_managed");
        }
    } catch (Throwable $e) {}

    // ── BOM明细表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS bom_items (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        project_id      INT NOT NULL,
        part_id         INT DEFAULT NULL,
        platform_part_no VARCHAR(100),
        model           VARCHAR(200),
        qty             INT NOT NULL DEFAULT 1,
        matched         TINYINT DEFAULT 0,
        sort_order      INT DEFAULT 0,
        INDEX idx_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── BOM出库记录 ──
    $db->exec("CREATE TABLE IF NOT EXISTS bom_exports (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        total_rows  INT DEFAULT 0,
        matched     INT DEFAULT 0,
        not_found   INT DEFAULT 0,
        insufficient INT DEFAULT 0,
        total_qty   INT DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── part_alternative 替代料独立表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS part_alternatives (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        source_part_id  INT NOT NULL COMMENT '原物料ID',
        target_part_id  INT NOT NULL COMMENT '替代物料ID',
        level           VARCHAR(20) NOT NULL DEFAULT 'full' COMMENT 'full/partial/reference',
        remark          VARCHAR(500) DEFAULT '',
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_alt_pair (source_part_id, target_part_id),
        INDEX idx_source (source_part_id),
        INDEX idx_target (target_part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── bom_versions BOM版本表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS bom_versions (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id  INT NOT NULL DEFAULT 1,
        project_id    INT NOT NULL COMMENT '项目ID',
        version       VARCHAR(50) NOT NULL DEFAULT '1.0' COMMENT '版本号',
        status        VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/released/archived',
        description   VARCHAR(500) DEFAULT '',
        created_time  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_project (project_id),
        INDEX idx_workspace (workspace_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $col = $db->query("SHOW COLUMNS FROM bom_versions LIKE 'base_version_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_versions ADD COLUMN base_version_id INT DEFAULT NULL COMMENT '迭代来源版本ID' AFTER status");
        }
    } catch (Throwable $e) {}
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_versions LIKE 'created_by'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_versions ADD COLUMN created_by INT DEFAULT NULL COMMENT '创建人ID' AFTER base_version_id");
        }
    } catch (Throwable $e) {}
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_versions LIKE 'released_at'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_versions ADD COLUMN released_at DATETIME DEFAULT NULL COMMENT '发布时间' AFTER created_by");
        }
    } catch (Throwable $e) {}
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_versions LIKE 'released_by'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_versions ADD COLUMN released_by INT DEFAULT NULL COMMENT '发布人ID' AFTER released_at");
        }
    } catch (Throwable $e) {}
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_versions LIKE 'change_note'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_versions ADD COLUMN change_note VARCHAR(1000) DEFAULT '' COMMENT '发布变更说明' AFTER released_by");
        }
    } catch (Throwable $e) {}

    // bom_items 增加 version_id 字段（BOM版本化）
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_items LIKE 'version_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_items ADD COLUMN version_id INT DEFAULT NULL COMMENT 'BOM版本ID' AFTER project_id");
            $db->exec("ALTER TABLE bom_items ADD INDEX idx_version (version_id)");
        }
    } catch (Throwable $e) {}
    // bom_items 增加 reference 位号
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_items LIKE 'reference'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_items ADD COLUMN reference VARCHAR(500) DEFAULT '' COMMENT '位号' AFTER qty");
        }
    } catch (Throwable $e) {}
    // bom_projects 利润率 / 项目属性
    try { $db->exec("ALTER TABLE bom_projects ADD COLUMN profit_margin DECIMAL(5,2) DEFAULT 0 COMMENT '利润率百分比'"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE bom_projects ADD COLUMN project_type VARCHAR(20) NOT NULL DEFAULT 'bom' COMMENT 'bom/production/prototype'"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE bom_projects ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/testing/production/finished/archived'"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE bom_projects ADD COLUMN priority TINYINT NOT NULL DEFAULT 0 COMMENT '0=normal,1=high,2=urgent'"); } catch(Throwable $e) {}

    // bom_items 增加 remark 备注
    try {
        $col = $db->query("SHOW COLUMNS FROM bom_items LIKE 'remark'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_items ADD COLUMN remark VARCHAR(500) DEFAULT '' COMMENT '备注' AFTER reference");
        }
    } catch (Throwable $e) {}

    try {
        $col = $db->query("SHOW COLUMNS FROM bom_items LIKE 'pending_json'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE bom_items ADD COLUMN pending_json TEXT DEFAULT NULL COMMENT '待补物料解析字段暂存(JSON)'");
        }
    } catch (Throwable $e) {}

    // ── v1.2.0 替代料兼容性规则扩展 ──
    try { $db->exec("ALTER TABLE part_alternatives ADD COLUMN compatibility_rule JSON DEFAULT NULL COMMENT '兼容性规则JSON:package/param_range/type' AFTER level"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE part_alternatives ADD COLUMN priority INT NOT NULL DEFAULT 0 COMMENT '优先级:0=最高' AFTER compatibility_rule"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE part_alternatives ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'confirmed' COMMENT 'pending/confirmed/ignored' AFTER priority"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE part_alternatives ADD COLUMN confirmed_at DATETIME DEFAULT NULL COMMENT '确认时间' AFTER status"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE part_alternatives ADD INDEX idx_status (status)"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE part_alternatives ADD INDEX idx_priority (source_part_id, priority)"); } catch (Throwable $e) {}

    // ── v1.2.0 待处理物料表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS pending_items (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        part_id         INT NOT NULL COMMENT '关联物料ID',
        reason          VARCHAR(50) NOT NULL COMMENT '异常原因:param_missing/negative_stock/duplicate/no_flow/alt_unconfirmed',
        detail          TEXT DEFAULT NULL COMMENT '详细信息JSON',
        status          VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/fixed/ignored',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at     DATETIME DEFAULT NULL,
        UNIQUE KEY uq_part_reason (part_id, reason),
        INDEX idx_status (status),
        INDEX idx_reason (reason)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

}
