<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：溯源与治理域 */
function schemaAudit(\PDO $db): void
{
    // ── migration_history 迁移日志表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS migration_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_version VARCHAR(20) NOT NULL,
        target_version VARCHAR(20) NOT NULL,
        file_name VARCHAR(500) DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        end_time DATETIME DEFAULT NULL,
        operator VARCHAR(100) DEFAULT 'system',
        result TEXT,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 规则1: operator_id 字段 — 业务表操作者规范 ──

    $operatorTables = ['stock_log', 'scan_log', 'trace_log'];
    foreach ($operatorTables as $tbl) {
        try {
            if (empty($db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'operator_id'")->fetchAll())) {
                $db->exec("ALTER TABLE `{$tbl}` ADD COLUMN operator_id INT DEFAULT NULL COMMENT '操作人ID'");
                // 迁移：user_id → operator_id（旧数据）
                $db->exec("UPDATE `{$tbl}` SET operator_id = user_id WHERE operator_id IS NULL");
                error_log("migration applied [{$tbl}.operator_id]: 旧库补列 + user_id 回填");
            }
            if (empty($db->query("SHOW INDEX FROM `{$tbl}` WHERE Key_name='idx_operator'")->fetchAll())) {
                $db->exec("ALTER TABLE `{$tbl}` ADD INDEX idx_operator (operator_id)");
            }
        } catch (Throwable $e) { error_log("migration failed [{$tbl}.operator_id]: " . $e->getMessage()); }
    }

    // ── 规则2.1: order_meta — 订单扩展信息（平台文件里的订单级列）──

    $db->exec("CREATE TABLE IF NOT EXISTS order_meta (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id     INT NOT NULL DEFAULT 1 COMMENT '工作空间ID',
        user_id          INT NOT NULL COMMENT '数据归属用户',
        operator_id      INT DEFAULT NULL COMMENT '写入者ID',
        platform_id      INT NOT NULL DEFAULT 0 COMMENT '平台ID',
        platform_code    VARCHAR(50) DEFAULT '' COMMENT '平台代码',
        order_no         VARCHAR(100) NOT NULL COMMENT '订单编号（归集键）',
        order_status     VARCHAR(50) DEFAULT '' COMMENT '订单状态',
        receiver         VARCHAR(100) DEFAULT '' COMMENT '收货人',
        customer_rep     VARCHAR(100) DEFAULT '' COMMENT '客户代表',
        ship_date        VARCHAR(40) DEFAULT '' COMMENT '发货日期（保留平台原文）',
        express_company  VARCHAR(100) DEFAULT '' COMMENT '快递公司',
        express_no       VARCHAR(100) DEFAULT '' COMMENT '快递单号',
        sign_date        VARCHAR(40) DEFAULT '' COMMENT '签收日期（保留平台原文）',
        invoice_no       VARCHAR(100) DEFAULT '' COMMENT '发票号',
        invoice_time     VARCHAR(40) DEFAULT '' COMMENT '开票时间（保留平台原文）',
        origin_order_no  VARCHAR(100) DEFAULT '' COMMENT '归属原订单',
        bom_flag         VARCHAR(50) DEFAULT '' COMMENT 'bom标识',
        contract_no      VARCHAR(100) DEFAULT '' COMMENT '订单合同编号',
        created_time     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_time     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order (user_id, workspace_id, platform_id, order_no),
        INDEX idx_workspace (workspace_id),
        INDEX idx_order (order_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 规则2.2: order_meta_ext — 订单级用户自定义扩展字段 ──

    $db->exec("CREATE TABLE IF NOT EXISTS order_meta_ext (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id   INT NOT NULL DEFAULT 1 COMMENT '工作空间ID',
        user_id        INT NOT NULL COMMENT '数据归属用户',
        order_meta_id  INT NOT NULL COMMENT 'order_meta.id',
        field_key      VARCHAR(100) NOT NULL COMMENT '自定义字段key（定义见 user_settings 表 k=order_custom_fields）',
        field_value    TEXT,
        created_time   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_time   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order_field (order_meta_id, field_key),
        INDEX idx_workspace (workspace_id),
        INDEX idx_user (user_id),
        INDEX idx_field (field_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // parts.unit（商品单位）

    try {
        if (empty($db->query("SHOW COLUMNS FROM parts LIKE 'unit'")->fetchAll())) {
            $db->exec("ALTER TABLE parts ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT '' COMMENT '商品单位（个/片/卷…）'");
        }
    } catch (\Throwable $e) { \error_log('migration failed [parts.unit]: ' . $e->getMessage()); }

    // ── 数据库审计表 — 废弃字段追踪 ──
    $db->exec("CREATE TABLE IF NOT EXISTS deprecated_fields (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        table_name      VARCHAR(100) NOT NULL COMMENT '表名',
        column_name     VARCHAR(100) NOT NULL COMMENT '字段名',
        deprecated_since VARCHAR(20) NOT NULL COMMENT '废弃于版本',
        replacement     VARCHAR(500) DEFAULT '' COMMENT '替代方案',
        reason          VARCHAR(500) DEFAULT '' COMMENT '废弃原因',
        can_delete      TINYINT NOT NULL DEFAULT 0 COMMENT '0=不可删 1=确认迁移完成后可删',
        marked_time     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_field (table_name, column_name),
        INDEX idx_can_delete (can_delete)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $depExist = [];
    foreach (($db->query("SELECT table_name, column_name FROM deprecated_fields")->fetchAll() ?: []) as $depExistRow) {
        $depExist[$depExistRow['table_name'] . '|' . $depExistRow['column_name']] = true;
    }
    $depSeed = [
        // parts.stock → stock 表
        ['parts', 'stock', '1.2.0', 'stock.quantity', '库存数据已迁移到独立 stock 表，parts.stock 仅作缓存', 0],
        // parts.damaged → stock 表
        ['parts', 'damaged', '1.2.0', 'stock.damaged', '不良品数量已迁移到独立 stock 表', 0],
        // parts.alternatives → part_alternatives 表
        ['parts', 'alternatives', '1.2.0', 'part_alternatives 表', '替代料已迁移到独立关联表', 0],
        // parts.user_id → operator_id（操作者语义）

        ['parts', 'user_id', '1.2.0', 'operator_id', '「谁操作的」语义迁往 operator_id；本列继续承担仓库内数据归属（写入取 getDataUserId()）', 0],
        // parts.parameters → parameters_text（重命名）
        ['parts', 'parameters', '1.2.0', 'parameters_text', '字段重命名为 parameters_text，语义更清晰', 0],
        // stock_log.user_id → operator_id
        ['stock_log', 'user_id', '1.2.0', 'operator_id', '操作者改用 operator_id 字段', 0],
        // scan_log.user_id → operator_id
        ['scan_log', 'user_id', '1.2.0', 'operator_id', '操作者改用 operator_id 字段', 0],
        // trace_log.user_id → operator_id
        ['trace_log', 'user_id', '1.2.0', 'operator_id', '操作者改用 operator_id 字段', 0],
        // attachments.part_id → target_id
        ['attachments', 'part_id', '1.2.0', 'target_type + target_id', '附件已改为多态模型', 0],

        ['parts', 'is_incomplete', '1.2.0', '（无替代，标记语义已消失）', '「残缺物料完善」功能整体废弃，列与 idx_incomplete 索引已 DROP；BOM 待补行走 bom_items.is_incomplete_part', 1],

        ['parts', 'parameters_json', '1.2.0', 'parameters_text', '该列无生成逻辑（两个写入点恒传 null，实际全为 NULL）；参数结构化改由 SpecNormalizer 在读取时即时完成，故连同消费方空判断一并下线', 1],
    ];
    $depStmt = $db->prepare("INSERT IGNORE INTO deprecated_fields (table_name, column_name, deprecated_since, replacement, reason, can_delete) VALUES (?,?,?,?,?,?)");
    foreach ($depSeed as $depRow) {
        if (!isset($depExist[$depRow[0] . '|' . $depRow[1]])) $depStmt->execute($depRow);
    }

    // ── v1.2.0 操作快照字段 ──

    try {
        $col = $db->query("SHOW COLUMNS FROM trace_log LIKE 'snapshot_before'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE trace_log ADD COLUMN snapshot_before JSON DEFAULT NULL COMMENT '操作前数据快照' AFTER business_id");
        }
    } catch (Throwable $e) {}
    // snapshot_after：操作后数据快照
    try {
        $col = $db->query("SHOW COLUMNS FROM trace_log LIKE 'snapshot_after'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE trace_log ADD COLUMN snapshot_after JSON DEFAULT NULL COMMENT '操作后数据快照' AFTER snapshot_before");
        }
    } catch (Throwable $e) {}

}
