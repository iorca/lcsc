<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构归一化：workspace_id 列归一 */
function schemaWorkspaceIsolation(\PDO $db): void
{
    $workspaceIdTables = ['parts', 'categories', 'platforms', 'stock_log', 'scan_log',
        'bom_projects', 'bom_items', 'price_history', 'trace_log', 'attachments',
        'import_history', 'imported_files', 'import_errors', 'backup_log', 'api_keys'];
    foreach ($workspaceIdTables as $tbl) {
        try {
            $col = $db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'workspace_id'")->fetchAll();
            if (empty($col)) {
                $db->exec("ALTER TABLE `{$tbl}` ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 COMMENT '工作空间ID' AFTER id");
                $db->exec("ALTER TABLE `{$tbl}` ADD INDEX idx_workspace (workspace_id)");
            }
        } catch (Throwable $e) {}
    }
    // users 表加 workspace_id
    try {
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'workspace_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE users ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 COMMENT '所属工作空间' AFTER id");
            $db->exec("ALTER TABLE users ADD INDEX idx_workspace (workspace_id)");
        }
    } catch (Throwable $e) {}

    $afterWsColumns = [
        ['bom_items', 'customer_part_no',    "VARCHAR(100) DEFAULT '' COMMENT '客户料号'", 'workspace_id'],
        ['bom_items', 'is_incomplete_part',  "TINYINT DEFAULT 0 COMMENT '残缺物料占位标记'", 'customer_part_no'],
    ];
    foreach ($afterWsColumns as [$tbl, $col, $ddl, $after]) {
        try {
            if (empty($db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'")->fetchAll())) {
                $db->exec("ALTER TABLE `{$tbl}` ADD COLUMN {$col} {$ddl} AFTER `{$after}`");
            }
        } catch (Throwable $e) {}
    }

    $wsIndexes = [
        ['imported_files', 'idx_impfiles_user_ws', '(user_id, workspace_id, id)'],
        ['import_history', 'idx_imphist_user_ws',  '(user_id, workspace_id)'],
    ];
    foreach ($wsIndexes as [$tbl, $idx, $cols]) {
        try {
            if (empty($db->query("SHOW INDEX FROM `{$tbl}` WHERE Key_name='{$idx}'")->fetchAll())) {
                $db->exec("ALTER TABLE `{$tbl}` ADD INDEX {$idx} {$cols}");
            }
        } catch (Throwable $e) {}
    }
}
