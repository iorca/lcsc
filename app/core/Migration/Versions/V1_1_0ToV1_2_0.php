<?php
declare(strict_types=1);

namespace App\Core\Migration\Versions;

use App\Core\Migration\MigrationInterface;
use App\Core\Migration\MigrationResult;
use App\Core\Migration\MigrationStepResult;
use PDO;
use Throwable;

/** V1.1.0 → V1.2.0 迁移步骤 */
class V1_1_0ToV1_2_0 implements MigrationInterface
{
    private PDO $db;
    private array $warnings = [];
    private int $recordsMigrated = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getSourceVersion(): string { return '1.1.0'; }
    public function getTargetVersion(): string { return '1.2.0'; }

    public function getDescription(): string
    {
        return '1.2.0 架构升级：Workspace 隔离 + Stock 独立 + BOM 版本化 + Import Engine + 参数双存储';
    }

    /** 声明本步骤要求的结构（供引擎 Dry Run 与真实库比对） */
    public function describeStructure(): array
    {
        return [
            'tables' => [
                'workspaces', 'locations', 'stock', 'part_alternatives',
                'bom_versions', 'import_document', 'import_raw_data', 'import_mapping',
                'field_keywords', 'normalized_import_items', 'user_mapping_rules',
                'system_meta', 'migration_history',
            ],
            'columns' => [
                'parts'          => ['workspace_id', 'parameters_text', 'datasheet', 'image'],
                'stock_log'      => ['workspace_id', 'location_id', 'source_type', 'source_id', 'operator'],
                'bom_items'      => ['workspace_id', 'version_id', 'reference', 'remark'],
                'attachments'    => ['workspace_id', 'target_type', 'target_id'],
                'price_history'  => ['workspace_id', 'cost_type', 'supplier', 'platform', 'source'],
            ],
            'indexes' => [
                'attachments' => ['idx_target'],
                'stock'       => ['uq_part_location'],
                'parts'       => ['idx_workspace'],
            ],
            'migrations' => [
                'parts.stock → stock 表'          => 'parts',
                'parameters → parameters_text'   => 'parts',
                'alternatives TEXT → 替代料表'    => 'parts',
            ],
        ];
    }

    public function preCheck(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM parts LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function up(): MigrationStepResult
    {
        $result = new MigrationStepResult('1.1.0', '1.2.0');

        if (!$this->preCheck()) {
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('前置检查失败：核心表不存在');
            return $result;
        }

        try {

            // 1. 创建 workspaces 表
            $this->createWorkspacesTable();
            $result->addMessage('创建 workspaces 表');

            // 2. workspace_id 列 — 全业务表统一添加
            $this->addWorkspaceIdToAllTables();
            $result->addMessage('全业务表添加 workspace_id 列');

            // 3. 创建 locations 表
            $this->createLocationsTable();
            $result->addMessage('创建 locations 表');

            // 4. 创建 stock 独立表
            $this->createStockTable();
            $result->addMessage('创建 stock 独立表');

            // 4.1 stock_log 增强字段：location_id / source_type / source_id / operator

            $this->addStockLogFields();
            $result->addMessage('stock_log 新增 location_id/source_type/source_id/operator');

            // 5. 迁移库存数据：parts.stock → stock 表
            $count = $this->migrateStockData();
            $result->addMessage("库存数据迁移：{$count} 条物料库存");
            $result->setRecordsMigrated($count);

            // 5.1 成本缓存重建：从 stock_log 流水重算 parts.avg_cost

            $costCount = $this->rebuildCostCache();
            $result->addMessage("成本缓存重建：{$costCount} 条物料");

            // 6. parts 表新增字段
            $this->addPartFields();
            $result->addMessage('parts 表新增 parameters_text/datasheet/image');

            $depDropped = $this->dropDeprecatedColumns();
            $result->addMessage("清理废弃字段：DROP {$depDropped} 个历史列");

            // 7. 参数迁移：parameters → parameters_text
            $paramCount = $this->migrateParameters();
            $result->addMessage("参数迁移：{$paramCount} 条 parameters → parameters_text");

            $altCount = $this->migrateAlternatives();
            $result->addMessage("替代料迁移：{$altCount} 条关联记录");

            // 10. 创建 bom_versions 表
            $this->createBomVersionsTable();
            $result->addMessage('创建 bom_versions 表');

            // 11. bom_items 新增字段
            $this->addBomItemFields();
            $result->addMessage('bom_items 新增 version_id/reference/remark');

            // 12. 附件多态
            $this->addAttachmentPolymorphic();
            $result->addMessage('attachments 新增 target_type/target_id（多态）');

            // 13. Import Engine 数据暂存层
            $this->createImportEngineTables();
            $result->addMessage('创建 Import Engine 数据暂存层（import_document/import_raw_data/import_mapping/field_keywords）');

            // 14. price_history 增强
            $this->addPriceHistoryFields();
            $result->addMessage('price_history 新增 cost_type/supplier/platform/source');

            // 15. 创建 system_meta 版本表
            $this->createSystemMeta();
            $result->addMessage('创建 system_meta 版本表');

            // 16. 创建 migration_history 迁移日志表
            $this->createMigrationHistory();
            $result->addMessage('创建 migration_history 迁移日志表');

            $result->setStatus(MigrationResult::SUCCESS);

            // 附加警告
            foreach ($this->warnings as $w) {
                $result->addMessage("[WARNING] {$w}");
            }

        } catch (Throwable $e) {
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('迁移失败：' . $e->getMessage());
        }

        return $result;
    }

    public function down(): MigrationStepResult
    {
        $result = new MigrationStepResult('1.2.0', '1.1.0');
        $result->setStatus(MigrationResult::FAILED);
        $result->addMessage('V1.2.0 → V1.1.0 降级不支持（数据结构变化不可逆）');
        $result->addMessage('建议：使用迁移前备份恢复');
        return $result;
    }

    // ── 具体迁移实现 ──

    private function createWorkspacesTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS workspaces (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL DEFAULT '默认工作空间',
            type VARCHAR(20) NOT NULL DEFAULT 'studio' COMMENT 'personal/studio',
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $count = (int)$this->db->query("SELECT COUNT(*) FROM workspaces")->fetchColumn();
        if ($count === 0) {
            $this->db->exec("INSERT INTO workspaces (name, type) VALUES ('默认工作空间', 'studio')");
        }
    }

    private function addWorkspaceIdToAllTables(): void
    {
        $tables = ['parts', 'categories', 'platforms', 'stock_log', 'scan_log',
            'bom_projects', 'bom_items', 'price_history', 'trace_log', 'attachments',
            'import_history', 'imported_files', 'import_errors', 'backup_log', 'api_keys', 'users'];

        foreach ($tables as $tbl) {
            try {
                $col = $this->db->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'workspace_id'")->fetchAll();
                if (empty($col)) {
                    $this->db->exec("ALTER TABLE `{$tbl}` ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 COMMENT '工作空间ID' AFTER id");
                }
                // 幂等补索引：上次可能在「加完列、加索引前」中断；不能只在缺列时加索引
                $idx = $this->db->query("SHOW INDEX FROM `{$tbl}` WHERE Key_name = 'idx_workspace'")->fetchAll();
                if (empty($idx)) {
                    $this->db->exec("ALTER TABLE `{$tbl}` ADD INDEX idx_workspace (workspace_id)");
                }
            } catch (Throwable $e) {
                $this->warnings[] = "表 {$tbl} 添加 workspace_id 失败：" . $e->getMessage();
            }
        }
    }

    private function createLocationsTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL DEFAULT 1,
            name VARCHAR(200) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'shelf',
            parent_id INT DEFAULT NULL,
            description VARCHAR(500) DEFAULT '',
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_workspace (workspace_id),
            INDEX idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 从 parts.location 去重创建库位记录

        try {
            $existing = array_flip($this->db->query("SELECT name FROM locations")->fetchAll(PDO::FETCH_COLUMN));
            $locations = $this->db->query("SELECT DISTINCT location FROM parts WHERE location IS NOT NULL AND location != ''")->fetchAll(PDO::FETCH_COLUMN);
            $ins = $this->db->prepare("INSERT INTO locations (workspace_id, name, type) VALUES (1, ?, 'shelf')");
            foreach ($locations as $loc) {
                $loc = (string)$loc;
                if ($loc === '' || isset($existing[$loc])) continue;
                $ins->execute([$loc]);
                $existing[$loc] = true;
            }
        } catch (Throwable $e) {
            $this->warnings[] = "库位迁移失败：" . $e->getMessage();
        }
    }

    private function createStockTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS stock (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL DEFAULT 1,
            part_id INT NOT NULL,
            location_id INT DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 0,
            damaged INT NOT NULL DEFAULT 0,
            update_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_part_location (part_id, location_id),
            INDEX idx_workspace (workspace_id),
            INDEX idx_part (part_id),
            INDEX idx_location (location_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function migrateStockData(): int
    {
        // 查找有库存的物料
        $stmt = $this->db->query("
            SELECT p.id, p.user_id, p.stock, p.damaged, p.location,
                   COALESCE(p.workspace_id, 1) as ws_id
            FROM parts p
            WHERE p.stock > 0 OR p.damaged > 0
        ");
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $insertStock = $this->db->prepare("
            INSERT IGNORE INTO stock (workspace_id, part_id, location_id, quantity, damaged)
            VALUES (?, ?, ?, ?, ?)
        ");

        $insertLog = $this->db->prepare("
            INSERT INTO stock_log (user_id, part_id, platform_part_no, change_type, qty_change, qty_before, qty_after, unit_cost, is_sample, subtotal, remark, workspace_id, location_id, source_type, source_id, operator)
            SELECT ?, p.id, p.platform_part_no, 'INITIAL', p.stock, 0, p.stock,
                   COALESCE(p.avg_cost, 0), 0, 0, '旧版本迁移初始化库存',
                   ?, NULL, 'migration', 0, 'system'
            FROM parts p
            WHERE p.id = ?
              -- 幂等：中断重跑时不再补第二条 INITIAL（否则流水与成本口径被重复放大）
              AND NOT EXISTS (
                  SELECT 1 FROM stock_log s
                  WHERE s.part_id = p.id AND s.change_type = 'INITIAL' AND s.source_type = 'migration'
              )
        ");

        $locationCache = [];
        foreach ($parts as $p) {
            // 解析 location → location_id
            $locId = 0;
            if (!empty($p['location'])) {
                if (!isset($locationCache[$p['location']])) {
                    $locStmt = $this->db->prepare("SELECT id FROM locations WHERE name=? LIMIT 1");
                    $locStmt->execute([$p['location']]);
                    $locationCache[$p['location']] = (int)$locStmt->fetchColumn() ?: 0;
                }
                $locId = $locationCache[$p['location']];
            }

            $insertStock->execute([$p['ws_id'], $p['id'], $locId, $p['stock'], $p['damaged']]);

            // 生成 INITIAL 流水
            try {
                $insertLog->execute([$p['user_id'], $p['ws_id'], $p['id']]);
            } catch (Throwable $e) {
                $this->warnings[] = "物料 #{$p['id']} 初始流水写入失败";
            }
        }

        return count($parts);
    }

    /** 重建全部物料的平均成本缓存（等价于 `bom.rebuild_all_cost` 跑一次） */
    private function rebuildCostCache(): int
    {
        try {

            $this->db->exec("UPDATE parts SET cost_dirty = 1, last_cost_recalc = NULL WHERE status != 'deleted'");
            $this->db->exec("UPDATE parts p
                SET p.avg_cost = COALESCE(ROUND(
                        (SELECT SUM(CASE WHEN s.qty_change > 0 AND s.unit_cost > 0
                                         THEN s.qty_change * s.unit_cost ELSE 0 END)
                         FROM stock_log s WHERE s.part_id = p.id)
                        / NULLIF((SELECT SUM(CASE WHEN s.qty_change > 0 AND s.unit_cost > 0
                                                 THEN s.qty_change ELSE 0 END)
                                  FROM stock_log s WHERE s.part_id = p.id), 0), 4), 0),
                    p.cost_dirty = 0,
                    p.last_cost_recalc = NOW()
                WHERE p.status != 'deleted'");
            return (int)$this->db->query("SELECT COUNT(*) FROM parts WHERE status != 'deleted'")->fetchColumn();
        } catch (Throwable $e) {
            $this->warnings[] = '成本缓存重建失败：' . $e->getMessage();
            return 0;
        }
    }

    /** DROP 台账里标 `can_delete=1`、且旧库确实还存在的历史列 */
    private function dropDeprecatedColumns(): int
    {
        $dropped = 0;
        try {
            $rows = $this->db->query(
                "SELECT df.table_name, df.column_name FROM deprecated_fields df
                 WHERE df.can_delete=1
                   AND EXISTS (
                       SELECT 1 FROM information_schema.COLUMNS c
                       WHERE c.TABLE_SCHEMA = DATABASE()
                         AND c.TABLE_NAME = df.table_name
                         AND c.COLUMN_NAME = df.column_name
                   )"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows ?: [] as $r) {
                $table  = (string)$r['table_name'];
                $column = (string)$r['column_name'];
                // 只接受「字母数字下划线」形态，杜绝任何注入面（表名/列名来自本地台账）
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    continue;
                }
                try {
                    $this->db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                    $dropped++;
                } catch (Throwable $e) {
                    $this->warnings[] = "删除废弃列 {$table}.{$column} 失败：" . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $this->warnings[] = '清理废弃字段失败：' . $e->getMessage();
        }
        return $dropped;
    }

    private function addPartFields(): void
    {
        $fields = [
            ['parameters_text', "ALTER TABLE parts ADD COLUMN parameters_text VARCHAR(500) DEFAULT '' COMMENT '原始参数文本' AFTER parameters"],
            ['datasheet', "ALTER TABLE parts ADD COLUMN datasheet VARCHAR(500) DEFAULT '' COMMENT '数据手册URL' AFTER purchase_url"],
            ['image', "ALTER TABLE parts ADD COLUMN image VARCHAR(500) DEFAULT '' COMMENT '物料主图路径' AFTER datasheet"],
        ];
        foreach ($fields as [$name, $sql]) {
            try {
                $col = $this->db->query("SHOW COLUMNS FROM parts LIKE '{$name}'")->fetchAll();
                if (empty($col)) $this->db->exec($sql);
            } catch (Throwable $e) {
                $this->warnings[] = "parts.{$name} 添加失败";
            }
        }
    }

    private function migrateParameters(): int
    {
        try {
            $this->db->exec("UPDATE parts SET parameters_text = parameters WHERE parameters_text = '' AND parameters != ''");
            return (int)$this->db->query("SELECT COUNT(*) FROM parts WHERE parameters_text != ''")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function migrateAlternatives(): int
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS part_alternatives (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_part_id INT NOT NULL,
            target_part_id INT NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'full',
            remark VARCHAR(500) DEFAULT '',
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_alt_pair (source_part_id, target_part_id),
            INDEX idx_source (source_part_id),
            INDEX idx_target (target_part_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $stmt = $this->db->query("SELECT id, alternatives FROM parts WHERE alternatives IS NOT NULL AND alternatives != ''");
            $ins = $this->db->prepare("INSERT IGNORE INTO part_alternatives (source_part_id, target_part_id, level) VALUES (?, ?, 'full')");
            $count = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ids = array_filter(array_map('intval', explode(',', $row['alternatives'])));
                foreach ($ids as $targetId) {
                    if ($targetId > 0) {
                        $ins->execute([$row['id'], $targetId]);
                        $count++;
                    }
                }
            }
            return $count;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function addStockLogFields(): void
    {
        $fields = [
            ['location_id', "ALTER TABLE stock_log ADD COLUMN location_id INT DEFAULT NULL COMMENT '库位ID' AFTER part_id"],
            ['source_type', "ALTER TABLE stock_log ADD COLUMN source_type VARCHAR(30) DEFAULT NULL COMMENT '来源类型' AFTER is_sample"],
            ['source_id', "ALTER TABLE stock_log ADD COLUMN source_id INT DEFAULT NULL COMMENT '来源ID' AFTER source_type"],
            ['operator', "ALTER TABLE stock_log ADD COLUMN operator VARCHAR(100) DEFAULT NULL COMMENT '操作人' AFTER source_id"],
        ];
        foreach ($fields as [$name, $sql]) {
            try {
                $col = $this->db->query("SHOW COLUMNS FROM stock_log LIKE '{$name}'")->fetchAll();
                if (empty($col)) $this->db->exec($sql);
            } catch (Throwable $e) {}
        }
    }

    private function createBomVersionsTable(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS bom_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL DEFAULT 1,
            project_id INT NOT NULL,
            version VARCHAR(50) NOT NULL DEFAULT '1.0',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            description VARCHAR(500) DEFAULT '',
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            INDEX idx_workspace (workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function addBomItemFields(): void
    {
        $fields = [
            ['version_id', "ALTER TABLE bom_items ADD COLUMN version_id INT DEFAULT NULL COMMENT 'BOM版本ID' AFTER project_id"],
            ['reference', "ALTER TABLE bom_items ADD COLUMN reference VARCHAR(500) DEFAULT '' COMMENT '位号' AFTER qty"],
            ['remark', "ALTER TABLE bom_items ADD COLUMN remark VARCHAR(500) DEFAULT '' COMMENT '备注' AFTER reference"],
        ];
        foreach ($fields as [$name, $sql]) {
            try {
                $col = $this->db->query("SHOW COLUMNS FROM bom_items LIKE '{$name}'")->fetchAll();
                if (empty($col)) $this->db->exec($sql);
            } catch (Throwable $e) {}
        }
    }

    private function addAttachmentPolymorphic(): void
    {
        try {
            $col = $this->db->query("SHOW COLUMNS FROM attachments LIKE 'target_type'")->fetchAll();
            if (empty($col)) {
                $this->db->exec("ALTER TABLE attachments ADD COLUMN target_type VARCHAR(30) NOT NULL DEFAULT 'part' COMMENT 'Part/BOM/Project' AFTER user_id");
            }
        } catch (Throwable $e) {}

        try {
            $col = $this->db->query("SHOW COLUMNS FROM attachments LIKE 'target_id'")->fetchAll();
            if (empty($col)) {
                $this->db->exec("ALTER TABLE attachments ADD COLUMN target_id INT DEFAULT NULL COMMENT '目标ID' AFTER target_type");

                $this->db->exec("UPDATE attachments SET target_id = part_id WHERE target_id IS NULL");
            }
        } catch (Throwable $e) {}

        // 幂等补索引：上次可能在「加完列、加索引前」中断
        try {
            $idx = $this->db->query("SHOW INDEX FROM attachments WHERE Key_name = 'idx_target'")->fetchAll();
            if (empty($idx)) {
                $this->db->exec("ALTER TABLE attachments ADD INDEX idx_target (target_type, target_id)");
            }
        } catch (Throwable $e) {}
    }

    private function createImportEngineTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS import_document (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL DEFAULT 1,
            filename VARCHAR(255) NOT NULL,
            source_type VARCHAR(50) DEFAULT NULL,
            hash VARCHAR(64) DEFAULT NULL,
            upload_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
            INDEX idx_workspace (workspace_id),
            INDEX idx_hash (hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS import_raw_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            document_id INT NOT NULL,
            `row_number` INT NOT NULL,
            column_number INT NOT NULL,
            value TEXT,
            INDEX idx_document (document_id),
            INDEX idx_row (document_id, `row_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS import_mapping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL DEFAULT 1,
            source_type VARCHAR(50) NOT NULL,
            source_column VARCHAR(200) NOT NULL,
            target_field VARCHAR(100) NOT NULL,
            confidence DECIMAL(3,2) DEFAULT 1.00,
            is_manual TINYINT NOT NULL DEFAULT 0,
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_source (source_type),
            INDEX idx_workspace (workspace_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->exec("CREATE TABLE IF NOT EXISTS field_keywords (
            id INT AUTO_INCREMENT PRIMARY KEY,
            standard_field VARCHAR(100) NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'auto',
            priority INT NOT NULL DEFAULT 0,
            created_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_field_keyword (standard_field, keyword),
            INDEX idx_field (standard_field)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 默认关键词
        $count = (int)$this->db->query("SELECT COUNT(*) FROM field_keywords")->fetchColumn();
        if ($count === 0) {
            $this->db->exec("INSERT INTO field_keywords (standard_field, keyword, language, priority) VALUES
                ('part_number','型号','zh',1),('part_number','料号','zh',2),('part_number','Part No','en',3),
                ('part_number','MPN','en',4),('part_number','商品编号','zh',5),('part_number','编码','zh',6),
                ('quantity','数量','zh',1),('quantity','Qty','en',2),('quantity','购买数量','zh',3),
                ('quantity','采购数量','zh',4),('quantity','库存','zh',5),
                ('unit_price','单价','zh',1),('unit_price','价格','zh',2),('unit_price','Unit Price','en',3),
                ('unit_price','成交价','zh',4),
                ('brand','品牌','zh',1),('brand','Brand','en',2),('brand','制造商','zh',3),
                ('package','封装','zh',1),('package','Package','en',2),('package','Footprint','en',3),
                ('category','分类','zh',1),('category','Category','en',2),
                ('description','描述','zh',1),('description','Description','en',2),
                ('location','库位','zh',1),('location','Location','en',2),
                ('order_no','订单号','zh',1),('order_no','Order No','en',2),
                ('reference','位号','zh',1),('reference','Designator','en',2),
                ('value','规格','zh',1),('value','Value','en',2),
                ('supplier','供应商','zh',1),('supplier','Supplier','en',2)");
        }
    }

    private function addPriceHistoryFields(): void
    {
        $fields = [
            ['cost_type', "ALTER TABLE price_history ADD COLUMN cost_type VARCHAR(20) NOT NULL DEFAULT 'purchase' COMMENT 'purchase/manual/estimated/unknown' AFTER unit_price"],
            ['supplier', "ALTER TABLE price_history ADD COLUMN supplier VARCHAR(200) DEFAULT '' COMMENT '供应商' AFTER part_id"],
            ['platform', "ALTER TABLE price_history ADD COLUMN platform VARCHAR(50) DEFAULT '' COMMENT '平台' AFTER supplier"],
            ['source', "ALTER TABLE price_history ADD COLUMN source VARCHAR(50) DEFAULT NULL COMMENT '数据来源' AFTER platform"],
        ];
        foreach ($fields as [$name, $sql]) {
            try {
                $col = $this->db->query("SHOW COLUMNS FROM price_history LIKE '{$name}'")->fetchAll();
                if (empty($col)) $this->db->exec($sql);
            } catch (Throwable $e) {}
        }
    }

    private function createSystemMeta(): void
    {
        // 只保证表存在。版本号由 MigrationEngine 在所有步骤成功后统一提交

        $this->db->exec("CREATE TABLE IF NOT EXISTS system_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            database_version VARCHAR(20) NOT NULL,
            created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createMigrationHistory(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS migration_history (
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
    }
}
