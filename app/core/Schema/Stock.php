<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：库存与盘点域 */
function schemaStock(\PDO $db): void
{
    // ── 备份日志 ──
    $db->exec("CREATE TABLE IF NOT EXISTS backup_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        file_name  VARCHAR(255),
        file_size  BIGINT DEFAULT 0,
        action     ENUM('backup','restore') DEFAULT 'backup',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── locations 表（库位/仓库管理）──
    $db->exec("CREATE TABLE IF NOT EXISTS locations (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id  INT NOT NULL DEFAULT 1,
        name          VARCHAR(200) NOT NULL COMMENT '库位名称',
        type          VARCHAR(30) NOT NULL DEFAULT 'shelf' COMMENT 'warehouse/shelf/bench/lab',
        parent_id     INT DEFAULT NULL COMMENT '父级库位',
        description   VARCHAR(500) DEFAULT '',
        created_time  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_workspace (workspace_id),
        INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── stock 独立表（库存与物料分离）──
    $db->exec("CREATE TABLE IF NOT EXISTS stock (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id  INT NOT NULL DEFAULT 1,
        part_id       INT NOT NULL COMMENT '物料ID',
        location_id   INT DEFAULT NULL COMMENT '库位ID',
        quantity      INT NOT NULL DEFAULT 0 COMMENT '良品数量',
        damaged       INT NOT NULL DEFAULT 0 COMMENT '不良品数量',
        update_time   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_part_location (part_id, location_id),
        INDEX idx_workspace (workspace_id),
        INDEX idx_part (part_id),
        INDEX idx_location (location_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── P7 在线盘点：盘点单 + 盘点明细 ──
    // 盘点单（快照发起 → 在线录数 → 差异确认 → 应用调整，全程留痕）
    $db->exec("CREATE TABLE IF NOT EXISTS check_sessions (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id  INT NOT NULL DEFAULT 1,
        user_id       INT NOT NULL COMMENT '发起人用户ID',
        title         VARCHAR(100) NOT NULL DEFAULT '' COMMENT '盘点单标题',
        scope_json    TEXT DEFAULT NULL COMMENT '范围筛选条件JSON（分类/库位/平台）',
        status        VARCHAR(20) NOT NULL DEFAULT 'counting' COMMENT 'counting=盘点中 confirmed=已确认 cancelled=已取消',
        total_items   INT NOT NULL DEFAULT 0 COMMENT '盘点物料总数',
        counted_items INT NOT NULL DEFAULT 0 COMMENT '已盘点数量',
        diff_items    INT NOT NULL DEFAULT 0 COMMENT '差异数量（确认后回填）',
        adjusted_items INT NOT NULL DEFAULT 0 COMMENT '实际应用调整行数（确认后回填）',
        remark        VARCHAR(500) DEFAULT '' COMMENT '备注',
        create_time   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        confirm_time  DATETIME DEFAULT NULL COMMENT '确认时间',
        confirm_uid   INT DEFAULT NULL COMMENT '确认人用户ID',
        INDEX idx_workspace (workspace_id),
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS check_session_items (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        session_id    INT NOT NULL COMMENT '盘点单ID',
        part_id       INT NOT NULL COMMENT '物料ID',
        snapshot_qty  INT NOT NULL DEFAULT 0 COMMENT '发起盘点时的系统库存快照',
        counted_qty   INT DEFAULT NULL COMMENT '实盘数量（NULL=未盘）',
        item_status   VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending=未盘 counted=已盘 skipped=跳过',
        diff          INT NOT NULL DEFAULT 0 COMMENT '差异 = 实盘 - 快照（已盘时回填）',
        remark        VARCHAR(500) DEFAULT '' COMMENT '行备注',
        count_time    DATETIME DEFAULT NULL COMMENT '录数时间',
        INDEX idx_session (session_id),
        INDEX idx_part (part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── stock_log 增强字段 ──

    try {
        $col = $db->query("SHOW COLUMNS FROM stock_log LIKE 'location_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN location_id INT DEFAULT NULL COMMENT '库位ID' AFTER part_id");
        }
    } catch (Throwable $e) {}

    try {
        $col = $db->query("SHOW COLUMNS FROM stock_log LIKE 'source_type'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN source_type VARCHAR(30) DEFAULT NULL COMMENT '来源类型' AFTER is_sample");
        }
    } catch (Throwable $e) {}
    // source_id：来源ID
    try {
        $col = $db->query("SHOW COLUMNS FROM stock_log LIKE 'source_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN source_id INT DEFAULT NULL COMMENT '来源ID' AFTER source_type");
        }
    } catch (Throwable $e) {}
    // operator：操作人
    try {
        $col = $db->query("SHOW COLUMNS FROM stock_log LIKE 'operator'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN operator VARCHAR(100) DEFAULT NULL COMMENT '操作人' AFTER source_id");
        }
    } catch (Throwable $e) {}
    // ip：操作IP（流水中心-操作日志视图用）
    try {
        $col = $db->query("SHOW COLUMNS FROM stock_log LIKE 'ip'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN ip VARCHAR(45) DEFAULT NULL COMMENT '操作IP' AFTER operator");
        }
    } catch (Throwable $e) {}

}
