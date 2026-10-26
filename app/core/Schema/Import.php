<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：导入中间层域 */
function schemaImport(\PDO $db): void
{

    $db->exec("CREATE TABLE IF NOT EXISTS import_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        order_no         VARCHAR(100) NOT NULL,
        platform_part_no VARCHAR(100) NOT NULL,
        import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_order_part (user_id, order_no, platform_part_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 已导入文件记录 ──
    $db->exec("CREATE TABLE IF NOT EXISTS imported_files (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        platform    VARCHAR(50) DEFAULT '',
        total_rows  INT DEFAULT 0,
        inserted    INT DEFAULT 0,
        updated     INT DEFAULT 0,
        skipped     INT DEFAULT 0,
        errors      INT DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 导入错误日志 ──
    $db->exec("CREATE TABLE IF NOT EXISTS import_errors (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        import_id   VARCHAR(36) NOT NULL,
        row_num     INT,
        raw_data    TEXT,
        reason      VARCHAR(500),
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import (import_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Import Engine 数据暂存层 ──
    // import_document：文件记录
    $db->exec("CREATE TABLE IF NOT EXISTS import_document (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id  INT NOT NULL DEFAULT 1,
        filename      VARCHAR(255) NOT NULL COMMENT '文件名',
        source_type   VARCHAR(50) DEFAULT NULL COMMENT 'lcsc/digikey/mouser/taobao/bom/custom',
        hash          VARCHAR(64) DEFAULT NULL COMMENT '文件SHA256',
        upload_time   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status        VARCHAR(20) NOT NULL DEFAULT 'uploaded' COMMENT 'uploaded/parsing/mapped/imported/failed',
        file_path     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '上传文件绝对路径（解析/分批处理用）',
        INDEX idx_workspace (workspace_id),
        INDEX idx_hash (hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS import_raw_data (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        document_id   INT NOT NULL COMMENT '文档ID',
        `row_number`  INT NOT NULL COMMENT '行号',
        column_number INT NOT NULL DEFAULT 0 COMMENT '列号（行级快照，恒为 0，不用作单元格定位）',
        value         TEXT COMMENT '原始值（整行 JSON 文本）',
        created_time  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document (document_id),
        INDEX idx_row (document_id, `row_number`),
        INDEX idx_created (created_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        if (empty($db->query("SHOW COLUMNS FROM import_raw_data LIKE 'created_time'")->fetchAll())) {
            $db->exec("ALTER TABLE import_raw_data ADD COLUMN created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER value");
        }

    } catch (Throwable $e) {}

    try {
        $col = $db->query("SHOW FULL COLUMNS FROM import_raw_data WHERE Field='column_number'")->fetch();
        if ($col && ((string)$col['Comment']) !== '列号（行级快照，恒为 0，不用作单元格定位）') {
            $db->exec("ALTER TABLE import_raw_data MODIFY COLUMN column_number INT NOT NULL DEFAULT 0 COMMENT '列号（行级快照，恒为 0，不用作单元格定位）'");
        }
        $col = $db->query("SHOW FULL COLUMNS FROM import_raw_data WHERE Field='value'")->fetch();
        if ($col && ((string)$col['Comment']) !== '原始值（整行 JSON 文本）') {
            $db->exec("ALTER TABLE import_raw_data MODIFY COLUMN value TEXT COMMENT '原始值（整行 JSON 文本）'");
        }
    } catch (Throwable $e) { error_log('migration failed [import_raw_data comment]: ' . $e->getMessage()); }

    // import_mapping：字段映射关系
    $db->exec("CREATE TABLE IF NOT EXISTS import_mapping (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id    INT NOT NULL DEFAULT 1,
        document_id     INT DEFAULT NULL COMMENT '关联 import_document.id',
        source_type     VARCHAR(50) NOT NULL COMMENT '来源类型',
        source_column   VARCHAR(200) NOT NULL COMMENT '原始列索引',
        source_header   VARCHAR(200) DEFAULT '' COMMENT '原始表头文本',
        target_field    VARCHAR(100) NOT NULL COMMENT '标准字段名',
        confidence      DECIMAL(3,2) DEFAULT 1.00 COMMENT '匹配置信度',
        match_rule      VARCHAR(200) DEFAULT '' COMMENT '匹配规则描述',
        is_manual       TINYINT NOT NULL DEFAULT 0 COMMENT '0=自动匹配 1=人工确认',
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document (document_id),
        INDEX idx_source (source_type),
        INDEX idx_workspace (workspace_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mig = [
        ['document_id',   "ALTER TABLE import_mapping ADD COLUMN document_id INT DEFAULT NULL COMMENT '关联 import_document.id'"],
        ['source_header', "ALTER TABLE import_mapping ADD COLUMN source_header VARCHAR(200) DEFAULT '' COMMENT '原始表头文本'"],
        ['match_rule',    "ALTER TABLE import_mapping ADD COLUMN match_rule VARCHAR(200) DEFAULT '' COMMENT '匹配规则描述'"],
    ];
    foreach ($mig as [$col, $sql]) {
        try {
            if (empty($db->query("SHOW COLUMNS FROM import_mapping LIKE '{$col}'")->fetchAll())) {
                $db->exec($sql);
            }
        } catch (\Throwable $e) { \error_log("migration failed [import_mapping.{$col}]: " . $e->getMessage()); }
    }
    try {
        if (empty($db->query("SHOW INDEX FROM import_mapping WHERE Key_name='idx_document'")->fetchAll())) {
            $db->exec("ALTER TABLE import_mapping ADD INDEX idx_document (document_id)");
        }
    } catch (\Throwable $e) { \error_log('migration failed [import_mapping.idx_document]: ' . $e->getMessage()); }
    // 上面的判存后 ADD **不是死代码**：`document_id` 是上面

    // field_keywords：字段识别关键词（数据库化，禁止写死代码）
    $db->exec("CREATE TABLE IF NOT EXISTS field_keywords (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        standard_field  VARCHAR(100) NOT NULL COMMENT '标准字段名(part_number/quantity/unit_price...)',
        keyword         VARCHAR(200) NOT NULL COMMENT '关键词(型号/料号/Part No...)',
        language        VARCHAR(10) NOT NULL DEFAULT 'auto' COMMENT 'zh/en/auto',
        priority        INT NOT NULL DEFAULT 0 COMMENT '优先级(越小越优先)',
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_field_keyword (standard_field, keyword),
        INDEX idx_field (standard_field)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $legacyKw = (int)$db->query("SELECT COUNT(*) FROM field_keywords WHERE standard_field IN ('part_number','category','description','value','supplier')")->fetchColumn();
        $canonKw  = (int)$db->query("SELECT COUNT(*) FROM field_keywords WHERE standard_field='platform_part_no' AND keyword='商品编号'")->fetchColumn();
        if ($legacyKw > 0 || $canonKw === 0) {
            \App\Helper\Parser\FieldRegistry::seedKeywords($db);
        }
    } catch (Throwable $e) {}

    // ── price_history 增强（成本来源分级）──

    try {
        $col = $db->query("SHOW COLUMNS FROM price_history LIKE 'cost_type'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE price_history ADD COLUMN cost_type VARCHAR(20) NOT NULL DEFAULT 'purchase' COMMENT 'purchase/manual/estimated/unknown' AFTER unit_price");
        }
    } catch (Throwable $e) {}
    // supplier：供应商
    try {
        $col = $db->query("SHOW COLUMNS FROM price_history LIKE 'supplier'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE price_history ADD COLUMN supplier VARCHAR(200) DEFAULT '' COMMENT '供应商' AFTER part_id");
        }
    } catch (Throwable $e) {}
    // platform：平台
    try {
        $col = $db->query("SHOW COLUMNS FROM price_history LIKE 'platform'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE price_history ADD COLUMN platform VARCHAR(50) DEFAULT '' COMMENT '平台' AFTER supplier");
        }
    } catch (Throwable $e) {}
    // source：数据来源(import/manual)
    try {
        $col = $db->query("SHOW COLUMNS FROM price_history LIKE 'source'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE price_history ADD COLUMN source VARCHAR(50) DEFAULT NULL COMMENT '数据来源(import/manual)' AFTER platform");
        }
    } catch (Throwable $e) {}

    // ── system_meta 版本管理表（Migration Engine 核心）──
    $db->exec("CREATE TABLE IF NOT EXISTS system_meta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        database_version VARCHAR(20) NOT NULL,
        created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 写入/更新当前版本号

    $metaCount = (int)$db->query("SELECT COUNT(*) FROM system_meta")->fetchColumn();
    if ($metaCount === 0) {
        $db->prepare("INSERT INTO system_meta (database_version) VALUES (?)")->execute([DATABASE_VERSION]);
    } else {
        $db->prepare("UPDATE system_meta SET database_version = ?, updated_time = NOW() ORDER BY id DESC LIMIT 1")
           ->execute([DATABASE_VERSION]);
    }

    // ── 规则2: normalized_import_items — Import Engine 标准中间实体层 ──

    $db->exec("CREATE TABLE IF NOT EXISTS normalized_import_items (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id    INT NOT NULL DEFAULT 1,
        document_id     INT NOT NULL COMMENT 'import_document.id',
        `row_number`    INT NOT NULL COMMENT '原始行号',
        -- 标准化后的字段
        part_number     VARCHAR(200) DEFAULT '',
        name            VARCHAR(500) DEFAULT '',
        brand           VARCHAR(200) DEFAULT '',
        package         VARCHAR(100) DEFAULT '',
        quantity        INT DEFAULT 0,
        unit_price      DECIMAL(12,4) DEFAULT 0,
        parameters_text VARCHAR(500) DEFAULT '',
        category_name   VARCHAR(100) DEFAULT '',
        location_name   VARCHAR(200) DEFAULT '',
        description     TEXT,
        reference       VARCHAR(500) DEFAULT '',
        order_no        VARCHAR(100) DEFAULT '',
        supplier        VARCHAR(200) DEFAULT '',
        -- 匹配状态
        match_status    VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/matched/created/conflict',
        matched_part_id INT DEFAULT NULL COMMENT '匹配到的物料ID',
        match_confidence DECIMAL(3,2) DEFAULT 0 COMMENT '匹配置信度',
        match_rule      VARCHAR(200) DEFAULT '' COMMENT '匹配依据',
        -- 用户确认
        user_confirmed  TINYINT NOT NULL DEFAULT 0 COMMENT '0=未确认 1=已确认',
        -- 原始数据引用
        raw_data_json   TEXT COMMENT '原始行数据JSON快照',
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_document (document_id),
        INDEX idx_workspace (workspace_id),
        INDEX idx_match (match_status),
        INDEX idx_matched_part (matched_part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 规则6: user_mapping_rules — 用户映射学习规则 ──

    $db->exec("CREATE TABLE IF NOT EXISTS user_mapping_rules (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id    INT NOT NULL DEFAULT 1,
        source_type     VARCHAR(50) NOT NULL COMMENT '来源类型 lcsc/taobao/digikey/...',
        source_column   VARCHAR(200) NOT NULL COMMENT '原始列名',
        target_field    VARCHAR(100) NOT NULL COMMENT '标准字段名',
        hit_count       INT NOT NULL DEFAULT 0 COMMENT '命中次数（越高越优先）',
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_time    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rule (workspace_id, source_type, source_column),
        INDEX idx_workspace (workspace_id),
        INDEX idx_source (source_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ─ 规则6.1: 平台导入模板（每个平台一份字段映射模板；导入前必须有模板）──

    $db->exec("CREATE TABLE IF NOT EXISTS import_template (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id    INT NOT NULL DEFAULT 1,
        platform_id     INT NOT NULL,
        platform_code   VARCHAR(50) NOT NULL DEFAULT '',
        profiles_json   TEXT,
        is_builtin      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '系统预置模板（立创商城）',
        revision        INT NOT NULL DEFAULT 1 COMMENT '>1 表示用户改过，内置模板不再自动覆盖',
        updated_by      INT NOT NULL DEFAULT 0,
        created_time    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_time    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_platform (workspace_id, platform_id),
        INDEX idx_workspace (workspace_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS import_batch_rows (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        batch_id        INT NOT NULL COMMENT '批次ID（imported_files.id）',
        row_num         INT NOT NULL DEFAULT 0,
        part_id         INT NOT NULL DEFAULT 0,
        action          VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'insert/update/skip/error（空=待处理）',
        qty_change      INT NOT NULL DEFAULT 0,
        qty_before      INT NOT NULL DEFAULT 0,
        qty_after       INT NOT NULL DEFAULT 0,
        stock_log_id    INT NOT NULL DEFAULT 0,
        payload_json    TEXT COMMENT '归一化后的行数据（分批处理时读取）',
        processed       TINYINT(1) NOT NULL DEFAULT 0,
        undone          TINYINT(1) NOT NULL DEFAULT 0,
        raw_data        VARCHAR(500) DEFAULT '',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch_proc (batch_id, processed),
        INDEX idx_batch_action (batch_id, action),
        INDEX idx_part (part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 批次主表复用 imported_files：补齐批次号/状态/模板/回退留痕列

    try {
        $cols = $db->query("SHOW COLUMNS FROM imported_files")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $addCol = function (string $col, string $ddl) use ($db, $cols): void {
            if (in_array($col, $cols, true)) return;

            try {
                $db->exec("ALTER TABLE imported_files ADD COLUMN " . $ddl);
            } catch (\Throwable $e) {
                \error_log('migration failed [imported_files.' . $col . ']: ' . $e->getMessage());
            }
        };
        $addCol('file_name',      "file_name VARCHAR(255) NOT NULL DEFAULT '' COMMENT '导入文件名'");
        $addCol('platform',       "platform VARCHAR(50) DEFAULT '' COMMENT '来源平台'");
        $addCol('total_rows',     "total_rows INT DEFAULT 0");
        $addCol('inserted',       "inserted INT DEFAULT 0");
        $addCol('updated',        "updated INT DEFAULT 0");
        $addCol('skipped',        "skipped INT DEFAULT 0");
        $addCol('errors',         "errors INT DEFAULT 0");
        $addCol('import_id',      "import_id VARCHAR(32) NOT NULL DEFAULT '' COMMENT '批次号'");
        $addCol('status',         "status VARCHAR(20) NOT NULL DEFAULT 'done' COMMENT 'processing/done/rolled_back/partial_rolled_back'");
        $addCol('template_id',    "template_id INT NOT NULL DEFAULT 0 COMMENT '使用的模板ID'");
        $addCol('import_mode',    "import_mode VARCHAR(20) NOT NULL DEFAULT 'stock' COMMENT 'stock=采购入库/profile=物料建档（不写库存流水）'");
        $addCol('is_sample',      "is_sample TINYINT(1) NOT NULL DEFAULT 0 COMMENT '样品不计资产'");
        $addCol('file_path',      "file_path VARCHAR(500) NOT NULL DEFAULT '' COMMENT '上传文件路径（分批处理用）'");
        $addCol('operator',       "operator INT NOT NULL DEFAULT 0 COMMENT '操作人'");
        $addCol('rolled_back_at', "rolled_back_at DATETIME DEFAULT NULL");
        $addCol('rolled_back_by', "rolled_back_by INT NOT NULL DEFAULT 0");
    } catch (Throwable $e) { \error_log('migration failed [imported_files scan]: ' . $e->getMessage()); }

    try {
        if (empty($db->query("SHOW COLUMNS FROM import_batch_rows LIKE 'claimed_at'")->fetchAll())) {
            $db->exec("ALTER TABLE import_batch_rows ADD COLUMN claimed_at DATETIME DEFAULT NULL COMMENT '行级认领时间（processed=2 时写入，用于崩溃恢复）'");
        }
    } catch (\Throwable $e) { \error_log('migration failed [import_batch_rows.claimed_at]: ' . $e->getMessage()); }
    try {
        if (empty($db->query("SHOW INDEX FROM import_batch_rows WHERE Key_name='idx_batch_claimed'")->fetchAll())) {
            $db->exec("ALTER TABLE import_batch_rows ADD INDEX idx_batch_claimed (batch_id, processed, claimed_at)");
        }
    } catch (\Throwable $e) { \error_log('migration failed [idx_batch_claimed]: ' . $e->getMessage()); }

    try {
        if (empty($db->query("SHOW COLUMNS FROM import_batch_rows LIKE 'reason'")->fetchAll())) {
            $db->exec("ALTER TABLE import_batch_rows ADD COLUMN reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT '行结论说明（跳过/失败原因，供处理详情展示）'");
        }
    } catch (\Throwable $e) { \error_log('migration failed [import_batch_rows.reason]: ' . $e->getMessage()); }

    // stock_log：显式批次列（按批次回退/查询流水用；逐条独立，失败只记日志）

    try {
        $logCols = $db->query("SHOW COLUMNS FROM stock_log")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (!in_array('import_batch', $logCols, true)) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN import_batch VARCHAR(32) NOT NULL DEFAULT '' COMMENT '导入批次号'");
        }
    } catch (\Throwable $e) { \error_log('migration failed [stock_log.import_batch]: ' . $e->getMessage()); }
    try {
        $idxRows = $db->query("SHOW INDEX FROM stock_log WHERE Key_name = 'idx_import_batch'")->fetchAll() ?: [];
        if (empty($idxRows)) {
            $db->exec("ALTER TABLE stock_log ADD INDEX idx_import_batch (import_batch)");
        }
    } catch (\Throwable $e) { \error_log('migration failed [idx_import_batch]: ' . $e->getMessage()); }

    // ── 导入中间表字段迁移（旧表可能缺少 created_time；判存后添加，MySQL 8 下 IF NOT EXISTS 会静默失败）

    $niiAdd = [
        ['normalized_import_items', 'created_time', "ALTER TABLE normalized_import_items ADD COLUMN created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"],
        ['import_document', 'file_path', "ALTER TABLE import_document ADD COLUMN file_path VARCHAR(500) NOT NULL DEFAULT '' COMMENT '上传文件绝对路径（解析/分批处理用）'"],
        ['import_document', 'created_time', "ALTER TABLE import_document ADD COLUMN created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"],
    ];
    foreach ($niiAdd as [$tbl, $col, $sql]) {
        try {
            if (empty($db->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'")->fetchAll())) {
                $db->exec($sql);
            }
        } catch (\Throwable $e) { \error_log("migration failed [{$tbl}.{$col}]: " . $e->getMessage()); }
    }
    $niiIdx = [
        ['normalized_import_items', 'idx_nii_created', "ALTER TABLE normalized_import_items ADD INDEX idx_nii_created (created_time)"],
        ['normalized_import_items', 'uq_nii_doc_row', "ALTER TABLE normalized_import_items ADD UNIQUE KEY uq_nii_doc_row (document_id, `row_number`)"],
        ['import_document', 'idx_impdoc_created', "ALTER TABLE import_document ADD INDEX idx_impdoc_created (created_time)"],
    ];
    foreach ($niiIdx as [$tbl, $idx, $sql]) {
        try {
            if (empty($db->query("SHOW INDEX FROM `{$tbl}` WHERE Key_name='{$idx}'")->fetchAll())) {
                $db->exec($sql);
            }
        } catch (\Throwable $e) { \error_log("migration failed [{$tbl}.{$idx}]: " . $e->getMessage()); }
    }

    // ── 导入中间表过期自动清理（保留7天，释放数据库空间）──
    // 同样必须排在四张中间表建表之后
    try {
        $db->exec("DELETE FROM normalized_import_items WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY) AND user_confirmed = 1");
        $db->exec("DELETE FROM import_raw_data WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $db->exec("DELETE FROM import_mapping WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");

        try {
            $expired = $db->query("SELECT file_path FROM import_document WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY) AND status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','" . \App\Core\Enum\ImportStatus::CANCELLED . "','" . \App\Core\Enum\ImportStatus::UPLOADED . "')")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($expired as $fp) {
                $fp = (string)$fp;
                if ($fp !== '' && \str_starts_with(\basename($fp), 'imp_') && \is_file($fp)) @\unlink($fp);
            }
        } catch (Throwable $e) {}
        $db->exec("DELETE FROM import_document WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY) AND status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','" . \App\Core\Enum\ImportStatus::CANCELLED . "','" . \App\Core\Enum\ImportStatus::UPLOADED . "')");
    } catch(Throwable $e) {}
}
