<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：物料与分类域 */
function schemaPart(\PDO $db): void
{
    // ── 平台表 ──
    // UNIQUE (user_id, code)：同一用户下平台代码唯一。

    $db->exec("CREATE TABLE IF NOT EXISTS platforms (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL DEFAULT 1,
        code          VARCHAR(50) NOT NULL,
        name          VARCHAR(100) NOT NULL,
        url_template  VARCHAR(500) DEFAULT '',
        is_default    TINYINT(1) NOT NULL DEFAULT 0,
        platform_type VARCHAR(20) NOT NULL DEFAULT 'standard',
        UNIQUE KEY uq_user_platform_code (user_id, code),
        INDEX idx_plat_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 分类表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        user_id   INT NOT NULL,
        parent_id INT DEFAULT NULL,
        name      VARCHAR(100) NOT NULL,
        low_stock_threshold INT DEFAULT NULL,
        UNIQUE KEY uq_user_cat (user_id, name),
        INDEX idx_user (user_id),
        INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 元件主表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS parts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        user_id             INT NOT NULL,
        platform_id         INT DEFAULT 1,
        internal_id         INT NOT NULL DEFAULT 0,
        platform_part_no    VARCHAR(100),
        customer_part_no    VARCHAR(100),
        model               VARCHAR(200),
        product_name        VARCHAR(500),
        product_type        VARCHAR(200),
        package             VARCHAR(100),
        brand               VARCHAR(100),
        unit                VARCHAR(20) NOT NULL DEFAULT '' COMMENT '商品单位（个/片/卷…）',
        stock               INT DEFAULT 0,
        damaged             INT DEFAULT 0,
        avg_cost            DECIMAL(10,4) DEFAULT 0,
        last_purchase_price DECIMAL(12,4) DEFAULT 0.0000,
        cost_dirty          TINYINT DEFAULT 0,
        last_cost_recalc    DATETIME DEFAULT NULL,
        low_stock_threshold INT DEFAULT 10,
        location            VARCHAR(200) DEFAULT '',
        parameters          VARCHAR(500) DEFAULT '',
        purchase_url        VARCHAR(500) DEFAULT '',
        linked_part_id      INT DEFAULT NULL,
        remark              TEXT,
        alternatives        TEXT DEFAULT NULL,
        create_time         DATETIME DEFAULT CURRENT_TIMESTAMP,
        update_time         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_platform_part (user_id, platform_id, platform_part_no),
        UNIQUE KEY uq_user_internal (user_id, internal_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 元件-分类 多对多 ──
    $db->exec("CREATE TABLE IF NOT EXISTS part_categories (
        part_id     INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (part_id, category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS price_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT NOT NULL,
        platform_part_no VARCHAR(100),
        order_no         VARCHAR(100),
        unit_price       DECIMAL(10,4) DEFAULT 0,
        qty              INT DEFAULT 0,
        order_time       DATETIME,
        import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        cost_type        VARCHAR(20) NOT NULL DEFAULT 'purchase' COMMENT 'purchase/manual/estimated/unknown',
        supplier         VARCHAR(200) DEFAULT '' COMMENT '供应商',
        platform         VARCHAR(50) DEFAULT '' COMMENT '平台',
        source           VARCHAR(50) DEFAULT NULL COMMENT '数据来源(import/manual)',
        INDEX idx_part (part_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 出入库日志（资产统计核心表）──

    $db->exec("CREATE TABLE IF NOT EXISTS stock_log (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT,
        platform_part_no VARCHAR(100),
        change_type      VARCHAR(30) NOT NULL,
        qty_change       INT NOT NULL,
        qty_before       INT NOT NULL,
        qty_after        INT NOT NULL,
        unit_cost        DECIMAL(10,4) DEFAULT 0,
        is_sample        TINYINT(1) NOT NULL DEFAULT 0,
        subtotal         DECIMAL(12,4) DEFAULT 0,
        order_time       DATETIME DEFAULT NULL,
        remark           VARCHAR(500),
        create_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        location_id      INT DEFAULT NULL COMMENT '库位ID',
        source_type      VARCHAR(30) DEFAULT NULL COMMENT '来源类型',
        source_id        INT DEFAULT NULL COMMENT '来源ID',
        operator         VARCHAR(100) DEFAULT NULL COMMENT '操作人',
        ip               VARCHAR(45) DEFAULT NULL COMMENT '操作IP',
        import_batch     VARCHAR(32) NOT NULL DEFAULT '' COMMENT '导入批次号',
        operator_id      INT DEFAULT NULL COMMENT '操作人ID',
        reviewed_at      DATETIME DEFAULT NULL COMMENT '撤销复核时间（NULL=待复核）',
        INDEX idx_part (part_id),
        INDEX idx_user (user_id),
        INDEX idx_stock_log_part_created (part_id, create_time),
        INDEX idx_import_batch (import_batch),
        INDEX idx_operator (operator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        if (empty($db->query("SHOW COLUMNS FROM stock_log LIKE 'reviewed_at'")->fetchAll())) {
            $db->exec("ALTER TABLE stock_log ADD COLUMN reviewed_at DATETIME DEFAULT NULL COMMENT '撤销复核时间（NULL=待复核）' AFTER operator_id");
        }
    } catch (Throwable $e) {}

    // ── 扫码日志 ──

    $db->exec("CREATE TABLE IF NOT EXISTS scan_log (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT NOT NULL,
        platform_part_no VARCHAR(100),
        scan_type        ENUM('in','out') DEFAULT 'out',
        qty              INT DEFAULT 1,
        qty_before       INT DEFAULT 0,
        qty_after        INT DEFAULT 0,
        remark           VARCHAR(200) DEFAULT '',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        operator_id      INT DEFAULT NULL COMMENT '操作人ID',
        INDEX idx_user (user_id),
        INDEX idx_part (part_id),
        INDEX idx_operator (operator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── v3.2 价格体系字段迁移 ──
    $cols32 = $db->query("SHOW COLUMNS FROM parts LIKE 'last_purchase_price'")->fetchAll();
    if (empty($cols32)) {
        $db->exec("ALTER TABLE parts ADD COLUMN last_purchase_price DECIMAL(12,4) DEFAULT 0.0000 AFTER avg_cost");
    }
    $cols32b = $db->query("SHOW COLUMNS FROM parts LIKE 'cost_dirty'")->fetchAll();
    if (empty($cols32b)) {
        $db->exec("ALTER TABLE parts ADD COLUMN cost_dirty TINYINT DEFAULT 0 AFTER last_purchase_price");
    }
    $cols32c = $db->query("SHOW COLUMNS FROM parts LIKE 'last_cost_recalc'")->fetchAll();
    if (empty($cols32c)) {
        $db->exec("ALTER TABLE parts ADD COLUMN last_cost_recalc DATETIME DEFAULT NULL AFTER cost_dirty");
    }

    // ── API Key 表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        api_key VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        scope TEXT COMMENT 'JSON数组：read_stock,read_part,write_stock,write_part',
        permissions TEXT,
        last_used DATETIME NULL,
        expires_at DATETIME NULL DEFAULT NULL COMMENT '过期时间，NULL=永不过期',
        last_used_ip VARCHAR(45) NULL COMMENT '最近使用的来源IP（IPv6最长45位）',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_key (api_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        if (empty($db->query("SHOW COLUMNS FROM api_keys LIKE 'scope'")->fetchAll())) {
            $db->exec("ALTER TABLE api_keys ADD COLUMN scope TEXT COMMENT 'JSON数组：read_stock,read_part,write_stock,write_part'");
        }
    } catch(Throwable $e) {}

    try {
        if (empty($db->query("SHOW COLUMNS FROM api_keys LIKE 'expires_at'")->fetchAll())) {
            $db->exec("ALTER TABLE api_keys ADD COLUMN expires_at DATETIME NULL DEFAULT NULL COMMENT '过期时间，NULL=永不过期' AFTER last_used");
        }
    } catch(Throwable $e) {}
    // api_keys 增加最近使用来源IP（幂等：SHOW 判存）
    try {
        if (empty($db->query("SHOW COLUMNS FROM api_keys LIKE 'last_used_ip'")->fetchAll())) {
            $db->exec("ALTER TABLE api_keys ADD COLUMN last_used_ip VARCHAR(45) NULL COMMENT '最近使用的来源IP（IPv6最长45位）' AFTER expires_at");
        }
    } catch(Throwable $e) {}

    // ── trace_log 增加业务关联字段（审计日志可回溯业务数据）──

    try {
        $col = $db->query("SHOW COLUMNS FROM trace_log LIKE 'business_table'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE trace_log ADD COLUMN business_table VARCHAR(50) DEFAULT NULL COMMENT '关联业务表名' AFTER ip");
        }
    } catch (Throwable $e) {}
    // business_id：关联业务记录ID
    try {
        $col = $db->query("SHOW COLUMNS FROM trace_log LIKE 'business_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE trace_log ADD COLUMN business_id INT DEFAULT NULL COMMENT '关联业务记录ID' AFTER business_table");
        }
    } catch (Throwable $e) {}

    // ── 性能优化索引（呆滞料查询加速，幂等：SHOW INDEX 判存）──
    try {
        if (empty($db->query("SHOW INDEX FROM stock_log WHERE Key_name='idx_stock_log_part_created'")->fetchAll())) {
            $db->exec("ALTER TABLE stock_log ADD INDEX idx_stock_log_part_created (part_id, create_time)");
        }
        if (empty($db->query("SHOW INDEX FROM parts WHERE Key_name='idx_parts_user_stock'")->fetchAll())) {
            $db->exec("ALTER TABLE parts ADD INDEX idx_parts_user_stock (user_id, stock)");
        }
    } catch(Throwable $e) {}

    // ── 物料状态字段（软删除+生命周期管理）──
    $colsStatus = $db->query("SHOW COLUMNS FROM parts LIKE 'status'")->fetchAll();
    if (empty($colsStatus)) {
        $db->exec("ALTER TABLE parts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/frozen/scrapped/archived/deleted'");
    }

    try { $db->exec("UPDATE parts SET status='active' WHERE status IN ('discontinued','pending')"); } catch(Throwable $e) {}

    // ── 附件表（物料图片管理）──
    $db->exec("CREATE TABLE IF NOT EXISTS attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        part_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        filepath VARCHAR(512) NOT NULL,
        filetype VARCHAR(50) NOT NULL DEFAULT 'image',
        filesize INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_part (part_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── workspaces 表（单工作空间，恒 1 行）──

    $db->exec("CREATE TABLE IF NOT EXISTS workspaces (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(200) NOT NULL DEFAULT '默认工作空间',
        type          VARCHAR(20) NOT NULL DEFAULT 'studio' COMMENT 'personal/studio（历史留存，不参与版本判定）',
        created_time  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 播种 1 行（仅首次创建）
    $wsCount = (int)$db->query("SELECT COUNT(*) FROM workspaces")->fetchColumn();
    if ($wsCount === 0) {
        $db->exec("INSERT INTO workspaces (name, type) VALUES ('默认工作空间', 'studio')");
    }

    // ── parts 表新增字段 ──

    try {
        $col = $db->query("SHOW COLUMNS FROM parts LIKE 'parameters_text'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE parts ADD COLUMN parameters_text VARCHAR(500) DEFAULT '' COMMENT '原始参数文本' AFTER parameters");
        }
    } catch (Throwable $e) {}
    try {
    } catch (Throwable $e) {}
    // datasheet：数据手册
    try {
        $col = $db->query("SHOW COLUMNS FROM parts LIKE 'datasheet'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE parts ADD COLUMN datasheet VARCHAR(500) DEFAULT '' COMMENT '数据手册URL' AFTER purchase_url");
        }
    } catch (Throwable $e) {}
    // image：物料主图（独立于attachments的快捷引用）
    try {
        $col = $db->query("SHOW COLUMNS FROM parts LIKE 'image'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE parts ADD COLUMN image VARCHAR(500) DEFAULT '' COMMENT '物料主图路径' AFTER datasheet");
        }
    } catch (Throwable $e) {}

    try {
        $col = $db->query("SHOW COLUMNS FROM parts LIKE 'location_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE parts ADD COLUMN location_id INT DEFAULT NULL COMMENT '结构化库位ID（与location文本双写）' AFTER location");
        }
    } catch (Throwable $e) {}
    try {
        $idx = $db->query("SHOW INDEX FROM parts WHERE Key_name='idx_location_id'")->fetchAll();
        if (empty($idx)) {
            $db->exec("ALTER TABLE parts ADD INDEX idx_location_id (location_id)");
        }
    } catch (Throwable $e) {}

    try {
        $db->exec("UPDATE parts SET parameters_text = parameters WHERE parameters_text = '' AND parameters != ''");
    } catch (Throwable $e) {}

    // ── 附件多态改造 — 添加 target_type + target_id ──
    try {
        $col = $db->query("SHOW COLUMNS FROM attachments LIKE 'target_type'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE attachments ADD COLUMN target_type VARCHAR(30) NOT NULL DEFAULT 'part' COMMENT 'Part/BOM/Project' AFTER user_id");
        }
    } catch (Throwable $e) {}
    try {
        $col = $db->query("SHOW COLUMNS FROM attachments LIKE 'target_id'")->fetchAll();
        if (empty($col)) {
            $db->exec("ALTER TABLE attachments ADD COLUMN target_id INT DEFAULT NULL COMMENT '目标ID' AFTER target_type");
            $db->exec("ALTER TABLE attachments ADD INDEX idx_target (target_type, target_id)");
        }
    } catch (Throwable $e) {}

    try {
        $db->exec("UPDATE attachments SET target_id = part_id WHERE target_id IS NULL AND part_id IS NOT NULL");
        $db->exec("UPDATE attachments SET target_type = 'part' WHERE target_type IS NULL OR target_type = ''");
    } catch (Throwable $e) { error_log('migration failed [attachments target backfill]: ' . $e->getMessage()); }

    // ── 价格备注字段 ──
    try { $db->exec("ALTER TABLE parts ADD COLUMN price_note VARCHAR(50) DEFAULT '' COMMENT '含税/未税' AFTER last_purchase_price"); } catch (Throwable $e) {}

    try {
        $hasUq = $db->query("SHOW INDEX FROM platforms WHERE Key_name='uq_user_platform_code'")->fetchAll();
        if (!$hasUq) {
            $dupCnt = (int)$db->query(
                "SELECT COUNT(*) FROM (SELECT 1 FROM platforms GROUP BY user_id, code HAVING COUNT(*) > 1) d"
            )->fetchColumn();
            if ($dupCnt === 0) {
                $db->exec("ALTER TABLE platforms ADD UNIQUE KEY uq_user_platform_code (user_id, code)");
            } else {
                error_log('migration skipped [platforms.uq_user_platform_code]: 存在 ' . $dupCnt . " 组重复平台代码，请先清理");
            }
        }
    } catch (Throwable $e) { error_log('migration failed [platforms.uq_user_platform_code]: ' . $e->getMessage()); }

    // ── 默认平台（仅首次初始化时插入）──

    $platCount = (int)$db->query("SELECT COUNT(*) FROM platforms")->fetchColumn();
    if ($platCount === 0) {
        $db->exec("INSERT INTO platforms (user_id,code,name,url_template,is_default,platform_type) VALUES
            (1,'lcsc','立创商城','https://so.szlcsc.com/global.html?k={part_no}',1,'standard'),
            (1,'other','散货渠道','',0,'loose')");
    }

    // ── 内置平台「其他」更名为「散货渠道」并改判为散货渠道类型（幂等）──
    try {
        $db->exec("UPDATE platforms SET name='散货渠道', platform_type='loose' WHERE code='other' AND (name='其他' OR platform_type<>'loose')");
    } catch (Throwable $e) { error_log('migration failed [platform other→散货渠道]: ' . $e->getMessage()); }
}
