<?php
declare(strict_types=1);

namespace App\Core\Migration;

use PDO;
use Throwable;

/** 数据库迁移引擎 */
class MigrationEngine
{
    // 校验项三档状态
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_WARNING = 'WARNING';
    public const STATUS_ERROR   = 'ERROR';

    private PDO $db;
    private string $versionsDir;
    private array $log = [];
    private string $currentVersion;
    private string $targetVersion;

    /** 迁移互斥锁名（MySQL GET_LOCK；连接断开自动释放） */
    private ?string $runLockName = null;

    /** 本次迁移在 migration_history 的行 id（开始即写 pending，结束更新状态） */
    private int $historyId = 0;

    public function __construct(PDO $db, ?string $sourceVersion = null)
    {
        $this->db           = $db;
        $this->versionsDir  = __DIR__ . '/Versions';
        $this->currentVersion = ($sourceVersion !== null && $sourceVersion !== '')
            ? $sourceVersion
            : $this->detectVersion();
        $this->targetVersion  = $this->getTargetVersion();
    }

    // ── 版本检测 ──────────────────────────────────────

    /** 检测当前数据库版本 */
    public function detectVersion(): string
    {
        try {
            $stmt = $this->db->query("SELECT database_version FROM system_meta ORDER BY id DESC LIMIT 1");
            if ($stmt && $row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                return (string)$row['database_version'];
            }
        } catch (Throwable $e) {
            // system_meta 不存在（未装或老库），走结构推断
        }
        return $this->inferVersionFromSchema();
    }

    /** 按表结构推断版本（无 system_meta 时的兜底） */
    private function inferVersionFromSchema(): string
    {
        $tables = $this->getTableList();
        if (empty($tables)) return '0.0';

        // 1.2.0 引入的标志性结构，命中任一即视为 1.2.0
        foreach (['system_meta', 'workspaces', 'bom_versions', 'field_keywords', 'stock', 'locations'] as $marker) {
            if (in_array($marker, $tables, true)) return '1.2.0';
        }
        // 1.1.0 结构
        foreach (['attachments', 'price_history', 'part_alternatives'] as $marker) {
            if (in_array($marker, $tables, true)) return '1.1.0';
        }
        if (in_array('parts', $tables, true) && in_array('stock_log', $tables, true)) return '1.1.0';
        if (in_array('parts', $tables, true)) return '1.0';
        return '0.0';
    }

    private function getTableList(): array
    {
        return array_values(array_filter(
            $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN),
            'is_string'
        ));
    }

    private function getTargetVersion(): string
    {
        return defined('DATABASE_VERSION') ? \DATABASE_VERSION : '1.2.0';
    }

    public function getCurrentVersion(): string { return $this->currentVersion; }
    public function getTargetVersionString(): string { return $this->targetVersion; }

    public function needsMigration(): bool
    {
        return version_compare($this->currentVersion, $this->targetVersion, '<');
    }

    // ── 迁移路径 ──────────────────────────────────────

    /** 生成逐步迁移路径（不跳跃版本） */
    public function getMigrationPath(): array
    {
        $path = [];
        $current = $this->currentVersion;
        $available = $this->getAvailableMigrations();
        if (empty($available)) return [];

        // 循环上限防死锁：版本段数不会超过 available 数量
        $guard = 0;
        while (version_compare($current, $this->targetVersion, '<') && $guard++ < 32) {
            $step = $this->findNextStep($current, $available);
            if ($step === null) {
                $this->log("无法找到从 {$current} 到下一版本的迁移路径", self::STATUS_ERROR);
                break;
            }
            $path[] = $step;
            $current = $step['to'];
        }
        return $path;
    }

    /** 在候选迁移中找出能推进 current 的最小一步 */
    private function findNextStep(string $current, array $available): ?array
    {
        // 1. 精确匹配 from == current（版本号写法可能带 .0）
        foreach ($available as $m) {
            if (version_compare($m['from'], $current, '=')) {
                return $this->makeStep($current, $m);
            }
        }

        $candidates = array_filter($available, function ($m) use ($current) {
            return version_compare($m['from'], $current, '>=')
                && version_compare($m['to'], $current, '>');
        });
        if (empty($candidates)) return null;

        usort($candidates, fn($a, $b) => version_compare($a['to'], $b['to']));
        return $this->makeStep($current, $candidates[0]);
    }

    private function makeStep(string $current, array $m): array
    {
        // 同名文件优先（from 写法不同但 to 相同的情况）
        $file = null;
        foreach ($this->getAvailableMigrations() as $cand) {
            if (version_compare($cand['to'], $m['to'], '=') && version_compare($cand['from'], $current, '>=')) {
                $file = $cand['file'];
                break;
            }
        }
        return ['from' => $current, 'to' => $m['to'], 'file' => $file];
    }

    /** 扫描 Versions/ 目录下的迁移文件 */
    private function getAvailableMigrations(): array
    {
        if (!is_dir($this->versionsDir)) return [];

        $migrations = [];
        foreach (glob($this->versionsDir . '/V*.php') ?: [] as $file) {
            if (!preg_match('/^V(\d+)_(\d+)(?:_(\d+))?ToV(\d+)_(\d+)(?:_(\d+))?$/', basename($file, '.php'), $m)) {
                continue;
            }
            $from = isset($m[3]) && $m[3] !== ''
                ? "{$m[1]}.{$m[2]}.{$m[3]}"
                : "{$m[1]}.{$m[2]}";
            $to = isset($m[6]) && $m[6] !== ''
                ? "{$m[4]}.{$m[5]}.{$m[6]}"
                : "{$m[4]}.{$m[5]}";
            $migrations[] = ['from' => $from, 'to' => $to, 'file' => $file];
        }

        usort($migrations, fn($a, $b) => version_compare($a['from'], $b['from']));
        return $migrations;
    }

    // ── Dry Run ───────────────────────────────────────

    /** 迁移预演：只读分析，不修改任何数据 */
    public function dryRun(): array
    {
        $report = [
            'current_version' => $this->currentVersion,
            'target_version'  => $this->targetVersion,
            'needs_migration' => $this->needsMigration(),
            'path'            => $this->needsMigration() ? $this->getMigrationPath() : [],
            'scan'            => [],
            'diff'            => [],
            'warnings'        => [],
        ];

        $report['scan'] = $this->scanData();
        if (!$report['needs_migration']) {
            return $report;   // 已是目标版本：无路径可生成，也无需任何告警
        }

        if (empty($report['path'])) {
            $report['warnings'][] = '未找到可用的迁移步骤文件，请确认 Versions/ 目录是否部署完整';
            return $report;
        }

        try {
            $report['diff'] = $this->buildStructuralDiff($report['path']);
        } catch (Throwable $e) {
            $report['warnings'][] = '结构比对失败：' . $e->getMessage();
        }

        return $report;
    }

    /** 统计数据规模（用于向用户解释「会动多少数据」） */
    private function scanData(): array
    {
        $scan = [];
        $count = function (string $table, string $where = ''): ?int {
            try {
                return (int)$this->db->query("SELECT COUNT(*) FROM `{$table}` {$where}")->fetchColumn();
            } catch (Throwable $e) {
                return null;   // 表不存在：静默跳过，页面按「无此表」处理
            }
        };

        $scan['parts_total']            = $count('parts');
        $scan['parts_with_stock']       = $count('parts', 'WHERE stock > 0 OR damaged > 0');
        $scan['stock_total']            = $count('stock');
        $scan['stock_log_total']        = $count('stock_log');
        $scan['bom_projects_total']     = $count('bom_projects');
        $scan['bom_items_total']        = $count('bom_items');
        $scan['attachments_total']      = $count('attachments');
        $scan['price_history_total']    = $count('price_history');
        $scan['part_alternatives_total'] = $count('part_alternatives');

        // 尚无 stock 行的有库存物料数 = 迁移将新建的库存行数
        $scan['parts_stock_pending_sync'] = $count(
            'parts',
            'WHERE (stock > 0 OR damaged > 0) AND id NOT IN (SELECT part_id FROM stock)'
        );

        return $scan;
    }

    /** 比对「目标版本要求的结构」与「库中真实结构」，得出实际待执行的变更 */
    private function buildStructuralDiff(array $path): array
    {
        $existingTables = array_flip($this->getTableList());

        $diff = [
            'to_create_tables'   => [],   // 待新建的表
            'existing_tables'    => [],   // 目标结构要求、但库里已存在（幂等跳过）
            'to_add_columns'     => [],   // 待新增的列：表 => [列名]
            'to_add_indexes'     => [],   // 待新增的索引：表 => [索引名]
            'to_migrate_records' => [],   // 预计转换的记录：说明 => 数量
        ];

        foreach ($path as $step) {
            $spec = $this->loadStepSpec($step);

            foreach ($spec['tables'] as $table) {
                if (isset($existingTables[$table])) {
                    $diff['existing_tables'][] = $table;
                } else {
                    $diff['to_create_tables'][] = $table;
                }
            }

            foreach ($spec['columns'] as $table => $columns) {
                if (isset($existingTables[$table])) continue;   // 表都没有，跳过列比对
                $missing = $this->missingColumns($table, $columns);
                if ($missing) $diff['to_add_columns'][$table] = $missing;
            }

            foreach ($spec['indexes'] as $table => $indexes) {
                if (isset($existingTables[$table])) continue;
                $missing = $this->missingIndexes($table, $indexes);
                if ($missing) $diff['to_add_indexes'][$table] = $missing;
            }

            // 数据转换量：按 step 声明的说明 + 实际行数
            foreach ($spec['migrations'] as $desc => $table) {
                $diff['to_migrate_records'][] = [
                    'desc'  => $desc,
                    'table' => $table,
                    'count' => $this->safeCount($table),
                ];
            }
        }

        $diff['to_create_tables'] = array_values(array_unique($diff['to_create_tables']));
        $diff['existing_tables']  = array_values(array_unique($diff['existing_tables']));
        return $diff;
    }

    /** 取某个迁移步骤声明的结构要求 */
    private function loadStepSpec(array $step): array
    {
        $empty = ['tables' => [], 'columns' => [], 'indexes' => [], 'migrations' => []];

        if (empty($step['file']) || !is_file($step['file'])) return $empty;

        require_once $step['file'];
        $class = $this->getMigrationClassName($step['file']);
        if (!class_exists($class)) return $empty;

        try {
            $spec = (new $class($this->db))->describeStructure();
            return array_merge($empty, is_array($spec) ? $spec : []);
        } catch (Throwable $e) {
            return $empty;
        }
    }

    private function missingColumns(string $table, array $columns): array
    {
        try {
            $existing = array_flip($this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];   // 表本身还不存在，列会随建表一起落地
        }
        return array_values(array_diff($columns, array_keys($existing)));
    }

    private function missingIndexes(string $table, array $indexes): array
    {
        try {
            $existing = array_flip($this->db->query("SHOW INDEX FROM `{$table}`")->fetchAll(\PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_diff($indexes, array_keys($existing)));
    }

    private function safeCount(string $table): ?int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }

    // ── 执行 ──────────────────────────────────────────

    /** 执行完整迁移流程：抢锁 → 备份 → 写版本标记 → 逐步迁移 → 校验 → 提交版本号 */
    public function execute(): MigrationResult
    {
        if (!$this->needsMigration()) {
            $result = new MigrationResult();
            $result->setStatus(MigrationResult::SKIPPED);
            $result->addMessage('数据库已是最新版本 ' . $this->targetVersion, 'info');
            return $result;
        }

        if (!$this->acquireRunLock()) {
            $result = new MigrationResult();
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('已有另一个迁移进程正在执行，本次未启动。请等待其完成后再试（不要多窗口 / 多人同时提交）。', 'error');
            return $result;
        }

        try {
            return $this->doExecute();
        } catch (Throwable $e) {

            $result = new MigrationResult();
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('迁移执行异常：' . $e->getMessage(), 'error');
            $this->logMigration($this->currentVersion, $this->targetVersion, 'failed', $result->getMessages());
            return $result;
        } finally {
            $this->releaseRunLock();
        }
    }

    /** 真正执行迁移（已被 execute() 加了互斥锁与异常兜底） */
    private function doExecute(): MigrationResult
    {
        $result = new MigrationResult();

        $path = $this->getMigrationPath();
        if (empty($path)) {
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage("无法生成从 {$this->currentVersion} 到 {$this->targetVersion} 的迁移路径", 'error');
            return $result;
        }

        $sourceVersion = $this->currentVersion;
        $this->log("开始迁移：{$sourceVersion} → {$this->targetVersion}", self::STATUS_SUCCESS);

        // Step 1: 备份（失败则中止——没有兜底就不该动结构）
        $backupFile = $this->createBackup();
        if ($backupFile === null) {
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('备份失败，已中止迁移（不提供无备份的结构变更）', 'error');
            $this->logMigration($sourceVersion, $this->targetVersion, 'failed', $result->getMessages());
            return $result;
        }
        $result->setBackupFile($backupFile);
        $this->log("备份完成：{$backupFile}", self::STATUS_SUCCESS);

        $this->beginHistory($sourceVersion, $this->targetVersion);

        // Step 1.5: 写版本标记（源版本），供中断后重新探测；必须早于任何 DDL
        try {
            $this->ensureVersionMarker($sourceVersion);
        } catch (Throwable $e) {
            $result->setStatus(MigrationResult::FAILED);
            $result->addMessage('无法写入迁移版本标记，已中止（否则中断后会被误判为已升级）：' . $e->getMessage(), 'error');
            $this->logMigration($sourceVersion, $this->targetVersion, 'failed', $result->getMessages());
            return $result;
        }

        // Step 2: 逐步执行
        foreach ($path as $step) {
            $stepResult = $this->executeStep($step);
            $result->addStep($stepResult);

            if ($stepResult->isFailed()) {
                $result->setStatus(MigrationResult::FAILED);
                $result->addMessage("迁移失败于步骤：{$step['from']} → {$step['to']}", 'error');
                $this->logMigration($sourceVersion, $this->targetVersion, 'failed', $result->getMessages());
                return $result;
            }

            $this->updateVersion($step['to']);
            $this->currentVersion = $step['to'];
        }

        // Step 3: 校验
        $result->setValidation($this->validate());

        // Step 4: 提交最终版本号
        $this->updateVersion($this->targetVersion);

        $result->setStatus(MigrationResult::SUCCESS);
        $result->addMessage("迁移完成：{$sourceVersion} → {$this->targetVersion}", 'success');
        $this->logMigration($sourceVersion, $this->targetVersion, 'success', $result->getMessages());

        return $result;
    }

    /** 执行单个迁移步骤 */
    private function executeStep(array $step): MigrationStepResult
    {
        if (empty($step['file']) || !is_file($step['file'])) {
            $r = new MigrationStepResult($step['from'], $step['to']);
            $r->setStatus(MigrationResult::FAILED);
            $r->addMessage("缺少迁移步骤文件：{$step['from']} → {$step['to']}，请确认 Versions/ 目录部署完整");
            return $r;
        }

        require_once $step['file'];
        $class = $this->getMigrationClassName($step['file']);
        if (!class_exists($class)) {
            $r = new MigrationStepResult($step['from'], $step['to']);
            $r->setStatus(MigrationResult::FAILED);
            $r->addMessage("迁移类 {$class} 不存在于 {$step['file']}");
            return $r;
        }

        $migration = new $class($this->db);

        if (!$migration->preCheck()) {
            $r = new MigrationStepResult($step['from'], $step['to']);
            $r->setStatus(MigrationResult::FAILED);
            $r->addMessage('前置检查未通过：数据库当前状态不满足本步骤的迁移前提');
            return $r;
        }

        return $migration->up();
    }

    /** 迁移类名推导：**由文件名推导**（与 `MigrationInterface` 的约定「类名与文件名一致」相符） */
    private function getMigrationClassName(string $file): string
    {
        return __NAMESPACE__ . '\\Versions\\' . basename($file, '.php');
    }

    /** 抢迁移互斥锁（MySQL `GET_LOCK`，非阻塞） */
    private function acquireRunLock(): bool
    {
        try {
            $dbName = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
            $this->runLockName = 'lcsc_migration_' . substr(md5($dbName !== '' ? $dbName : 'default'), 0, 24);
            $stmt = $this->db->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute([$this->runLockName]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            $this->log('迁移互斥锁不可用：' . $e->getMessage(), self::STATUS_ERROR);
            return false;
        }
    }

    private function releaseRunLock(): void
    {
        if ($this->runLockName === null) return;
        try {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->runLockName]);
        } catch (Throwable $e) {
            // 连接断开时 MySQL 也会自动释放，这里失败不阻断
            error_log('[migration] release lock failed: ' . $e->getMessage());
        }
        $this->runLockName = null;
    }

    /** 写入「源版本」标记（system_meta），保证中断后不会被结构推断误判成已升级 */
    private function ensureVersionMarker(string $sourceVersion): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS system_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            database_version VARCHAR(20) NOT NULL,
            created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int)$this->db->query("SELECT COUNT(*) FROM system_meta")->fetchColumn();
        if ($count === 0) {
            $this->db->prepare("INSERT INTO system_meta (database_version) VALUES (?)")
                ->execute([$sourceVersion]);
        }
    }

    /** 创建迁移历史表（与实际步骤共用同一结构；先于步骤保证 pending 行可落库） */
    private function ensureHistoryTable(): void
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** 开始即写一条 pending 历史：中断后页面能显示「上次迁移未正常结束」 */
    private function beginHistory(string $from, string $to): void
    {
        try {
            $this->ensureHistoryTable();

            // 上一次若被中断（进程退出 / 刷新 / 断网），pending 行会永久残留；

            $this->db->exec(
                "UPDATE migration_history
                 SET status='failed', end_time=NOW(), result='上次迁移未正常结束（进程中断）'
                 WHERE status='pending'"
            );

            $this->db->prepare(
                "INSERT INTO migration_history (source_version, target_version, status, start_time, result)
                 VALUES (?,?, 'pending', NOW(), '')"
            )->execute([$from, $to]);
            $this->historyId = (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            $this->historyId = 0;
            $this->log('迁移历史写入失败：' . $e->getMessage(), self::STATUS_WARNING);
        }
    }

    // ── 备份 ──────────────────────────────────────────

    /** 创建迁移前备份 */
    private function createBackup(): ?string
    {

        $backupDir = dirname(__DIR__, 3) . '/backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            $this->log('备份目录不可创建：' . $backupDir, self::STATUS_ERROR);
            return null;
        }

        $filepath = $backupDir . '/pre_migration_' . date('Ymd_His') . '.sql';
        $sql = "-- LCSC Pre-Migration Backup\n-- Version: {$this->currentVersion}\n-- Date: " . date('Y-m-d H:i:s') . "\n\n"
             . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        try {
            foreach ($this->getTableList() as $table) {
                try {
                    $create = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM);
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
                    $stmt = $this->db->query("SELECT * FROM `{$table}`");
                    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                        $vals = array_map(
                            fn($v) => $v === null ? 'NULL' : $this->db->quote((string)$v),
                            $row
                        );
                        $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
                    }
                    $sql .= "\n";
                } catch (Throwable $e) {
                    $sql .= "-- Table {$table} skipped: " . $e->getMessage() . "\n\n";
                }
            }
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        } catch (Throwable $e) {
            $this->log('备份导出异常：' . $e->getMessage(), self::STATUS_ERROR);
            return null;
        }

        if (@file_put_contents($filepath, $sql) === false) {
            $this->log('备份文件写入失败：' . $filepath, self::STATUS_ERROR);
            return null;
        }
        return $filepath;
    }

    // ── 校验 ──────────────────────────────────────────

    /** 迁移后数据一致性校验 */
    private function validate(): MigrationValidation
    {
        $v = new MigrationValidation();

        try {
            $parts = (int)$this->db->query("SELECT COUNT(*) FROM parts")->fetchColumn();
            $v->addCheck('物料总数', $parts, '> 0', $parts > 0 ? self::STATUS_SUCCESS : self::STATUS_WARNING);

            $fromParts = (int)$this->db->query("SELECT COALESCE(SUM(stock),0) FROM parts")->fetchColumn();
            $fromStock = (int)$this->db->query("SELECT COALESCE(SUM(quantity),0) FROM stock")->fetchColumn();
            $v->addCheck(
                '库存一致性（parts 缓存 vs stock 事实表）',
                $fromStock,
                $fromParts,
                $fromParts === $fromStock ? self::STATUS_SUCCESS : self::STATUS_WARNING
            );

            $wsTotal = (int)$this->db->query("SELECT COUNT(*) FROM parts")->fetchColumn();
            $wsWith  = (int)$this->db->query("SELECT COUNT(*) FROM parts WHERE workspace_id > 0")->fetchColumn();
            $v->addCheck(
                'workspace_id 覆盖率',
                $wsTotal > 0 ? round($wsWith / $wsTotal * 100, 1) . '%' : '100%',
                '100%',
                $wsTotal === 0 || $wsWith === $wsTotal ? self::STATUS_SUCCESS : self::STATUS_WARNING
            );

            $bom = (int)$this->db->query("SELECT COUNT(*) FROM bom_items")->fetchColumn();
            $v->addCheck('BOM 条目数', $bom, '—', self::STATUS_SUCCESS);

            $log = (int)$this->db->query("SELECT COUNT(*) FROM stock_log")->fetchColumn();
            $v->addCheck('库存流水数', $log, '—', self::STATUS_SUCCESS);

            $orphans = (int)$this->db->query(
                "SELECT COUNT(*) FROM stock s LEFT JOIN parts p ON s.part_id = p.id WHERE p.id IS NULL"
            )->fetchColumn();
            $v->addCheck('孤儿记录（stock 指向不存在的物料）', $orphans, 0,
                $orphans === 0 ? self::STATUS_SUCCESS : self::STATUS_WARNING);

        } catch (Throwable $e) {
            $v->addCheck('校验执行异常', $e->getMessage(), '—', self::STATUS_ERROR);
        }

        return $v;
    }

    // ── 版本号 ────────────────────────────────────────

    private function updateVersion(string $version): void
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS system_meta (
                id INT AUTO_INCREMENT PRIMARY KEY,
                database_version VARCHAR(20) NOT NULL,
                created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $count = (int)$this->db->query("SELECT COUNT(*) FROM system_meta")->fetchColumn();
            if ($count === 0) {
                $this->db->prepare("INSERT INTO system_meta (database_version) VALUES (?)")->execute([$version]);
            } else {
                $this->db->prepare("UPDATE system_meta SET database_version = ?, updated_time = NOW() ORDER BY id DESC LIMIT 1")
                    ->execute([$version]);
            }
        } catch (Throwable $e) {
            $this->log('版本号更新失败：' . $e->getMessage(), self::STATUS_WARNING);
        }
    }

    // ── 日志与报告 ────────────────────────────────────

    private function log(string $message, string $level = self::STATUS_SUCCESS): void
    {
        $this->log[] = ['time' => date('H:i:s'), 'level' => $level, 'message' => $message];
    }

    public function getLog(): array { return $this->log; }

    private function logMigration(string $from, string $to, string $status, array $messages): void
    {
        try {
            $this->ensureHistoryTable();
            $json = json_encode($messages, JSON_UNESCAPED_UNICODE);

            // 有 pending 行就更新（同一次迁移的开始/结束是同一条记录）；

            if ($this->historyId > 0) {
                $this->db->prepare(
                    "UPDATE migration_history SET status=?, end_time=NOW(), result=? WHERE id=?"
                )->execute([$status, $json, $this->historyId]);
                return;
            }

            $this->db->prepare(
                "INSERT INTO migration_history (source_version, target_version, status, end_time, result) VALUES (?,?,?,NOW(),?)"
            )->execute([$from, $to, $status, $json]);
        } catch (Throwable $e) {
            $this->log('迁移历史写入失败：' . $e->getMessage(), self::STATUS_WARNING);
        }
    }

    /** 生成纯文本迁移报告（页面展示 + 供复制） */
    public function generateReport(MigrationResult $result): string
    {
        $r = "=== LCSC 数据库迁移报告 ===\n"
           . "时间：" . date('Y-m-d H:i:s') . "\n"
           . "版本：" . $this->currentVersion . " → " . $this->targetVersion . "\n"
           . "状态：" . $result->getStatusText() . "\n\n";

        if ($result->getBackupFile()) {
            $r .= "备份文件：" . basename($result->getBackupFile()) . "\n\n";
        }

        if ($result->getSteps()) {
            $r .= "--- 迁移步骤 ---\n";
            foreach ($result->getSteps() as $i => $s) {
                $r .= ($i + 1) . ". {$s->getFrom()} → {$s->getTo()} [{$s->getStatus()}]\n";
                foreach ($s->getMessages() as $m) $r .= "     {$m}\n";
            }
            $r .= "\n";
        }

        $r .= "--- 执行消息 ---\n";
        foreach ($result->getMessages() as $m) {
            $r .= "[{$m['level']}] {$m['message']}\n";
        }

        $v = $result->getValidation();
        if ($v) {
            $r .= "\n--- 数据校验 ---\n";
            foreach ($v->getChecks() as $c) {
                $icon = ['SUCCESS' => '✓', 'WARNING' => '⚠', 'ERROR' => '✗'][$c['status']] ?? '?';
                $r .= "[{$icon}] {$c['name']}: {$c['actual']}（期望 {$c['expected']}）\n";
            }
        }

        return $r;
    }
}
