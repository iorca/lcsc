<?php
declare(strict_types=1);

// ── 统一时区（跨部署一致性）──

date_default_timezone_set(getenv('LCSC_TZ') ?: 'Asia/Shanghai');

// ── 数据库版本常量（Migration Engine 使用）──

if (!defined('DATABASE_VERSION')) define('DATABASE_VERSION', '1.2.0');

$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_needInstall = (!file_exists(__DIR__ . '/.installed') || !file_exists(__DIR__ . '/config.db.php'));
if (!defined('INSTALL_MODE') && $_needInstall && $script !== 'install.php') {
    header('Location: install.php');
    exit;
}

// ── 错误处理（生产环境安全配置）─────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// 隐藏 PHP 版本号
header_remove('X-Powered-By');

// ── 加载数据库配置（安装向导写入的独立配置文件，优先于下方默认值）──
if (file_exists(__DIR__ . '/config.db.php')) {
    require_once __DIR__ . '/config.db.php';
}

// ── Composer Autoload（PhpSpreadsheet 等第三方依赖）──
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── PSR-4 自动加载（/app + /modules）──
// 命名空间映射：
// App\Core\Xxx → /app/core/Xxx.php

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\'      => __DIR__ . '/app/',
        'Modules\\'  => __DIR__ . '/modules/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) === 0) {
            $relative = substr($class, $len);
            $path = str_replace('\\', '/', $relative);
            // Linux 大小写敏感适配：项目目录约定不完全统一——

            $candidates = [$path];
            $slashPos = strpos($path, '/');
            if ($slashPos !== false) {
                $candidates[] = strtolower(substr($path, 0, $slashPos)) . substr($path, $slashPos);
            } else {
                $candidates[] = strtolower($path);
            }
            foreach ($candidates as $cand) {
                $file = $baseDir . $cand . '.php';
                if (is_file($file)) {

                    require_once $file;
                    return;
                }
            }
        }
    }
});

if (is_file(__DIR__ . '/dev/update_test/local_source.php')) {
    require_once __DIR__ . '/dev/update_test/local_source.php';
}

// ── 在线升级维护锁（P2）──────────────────────────────────
// 逐文件替换代码期间，并发请求可能读到「一半新一半旧」的代码。故替换期间在根目录放

if (!defined('INSTALL_MODE') && \App\Core\Update\UpdateLock::active(__DIR__)) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<title>系统正在升级</title></head><body>'
       . '<h1>系统正在升级</h1>'
       . '<p>管理员正在应用新版本，请稍候刷新页面（通常在数分钟内完成）。</p>'
       . '</body></html>';
    exit;
}

// ── 数据结构定义（initDB 的实现体，按业务域拆分）──

foreach (['System', 'Part', 'Stock', 'Bom', 'Import', 'Audit', 'Workspace'] as $_schemaFile) {
    require_once __DIR__ . "/app/core/Schema/{$_schemaFile}.php";
}
unset($_schemaFile);

// ── 全局异常处理器注册 ──
\App\Core\ExceptionHandler::register();

// ── 数据库配置 ──────────────────────────────────────────
// 以下为默认值，安装向导生成的 config.db.php 会覆盖
if (!defined('DB_HOST'))    define('DB_HOST',    '127.0.0.1');
if (!defined('DB_NAME'))    define('DB_NAME',    'lcsc');
if (!defined('DB_USER'))    define('DB_USER',    'lcsc');
if (!defined('DB_PASS'))    define('DB_PASS',    'admin');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// 当前系统版本号（用于线上版本校验 & 静态资源缓存破坏）

if (!defined('APP_VERSION')) define('APP_VERSION', '1.2.0');

// ── Session 安全启动 ────────────────────────────────────
/** 全局完整销毁会话（4步完整清理） */
function destroySession(): void {
    // 步骤1：清空所有会话变量
    $_SESSION = [];
    // 步骤2：销毁服务端 session 文件
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // 步骤3：清除浏览器 PHPSESSID Cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    // 步骤4：csrf令牌已在$_SESSION=[]中清空，无需额外操作

}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // 会话安全配置
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $timeout = (int)(getSetting('session_timeout', '1800') ?: '1800');
        // 上限保护：最长 86400 秒（24 小时），0 视为默认 1800
        if ($timeout <= 0 || $timeout > 86400) $timeout = 1800;

        $rememberLifetime = 0;
        if (!empty($_COOKIE['remember_me'])) {
            $rememberLifetime = 30 * 86400; // 30天
            $timeout = $rememberLifetime;   // 同步延长超时
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
        session_set_cookie_params([
            'lifetime' => $rememberLifetime,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // 会话超时检查
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            // 超时处理：区分 GET 和 POST/AJAX
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {

                destroySession();
                session_set_cookie_params([
                    'lifetime' => $rememberLifetime,
                    'path'     => '/',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                session_start();
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
            } else {

                unset($_SESSION['user_id'], $_SESSION['user']);
                $_SESSION['timeout_notice'] = true;
            }
        }
        $_SESSION['last_activity'] = time();
    }
}

// ── 数据库单例 ──────────────────────────────────────────
function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        try {
            $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
            $db  = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->exec("SET CHARACTER SET utf8mb4");
            $db->exec("SET character_set_connection=utf8mb4");
            $db->exec("SET character_set_client=utf8mb4");
            $db->exec("SET character_set_results=utf8mb4");
        } catch (Throwable $e) {
            // 数据库连接失败：未安装→跳转安装向导；已安装→显示可逃出的错误页
            if (!file_exists(__DIR__ . '/.installed') || !file_exists(__DIR__ . '/config.db.php')) {
                header('Location: install.php');
                exit;
            }
            // 已安装但连不上库，记录错误并显示友好提示
            error_log('getDB connection failed: ' . $e->getMessage());
            http_response_code(503);

            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if (isAjaxRequest() || str_contains($scriptName, '/app/api/') || basename($scriptName) === 'api.php') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'code' => 503,
                    'msg'  => '数据库连接失败：请检查 MySQL 服务是否启动、config.db.php 配置是否正确',
                    'data' => null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 普通页面：给出**能真正逃出**的恢复页。

            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>数据库连接失败</title></head>'
               . '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0"><div style="text-align:center;max-width:520px;padding:40px">'
               . '<h2 style="color:#dc2626">数据库连接失败</h2>'
               . '<p style="color:#666">请检查 MySQL 服务是否启动，以及 <code>config.db.php</code> 中的数据库配置（主机 / 库名 / 账号 / 密码）是否正确。</p>'
               . '<p style="margin-top:10px">'
               . '<button type="button" onclick="location.reload()" style="cursor:pointer;margin:4px;padding:8px 20px;background:#475569;color:#fff;border:0;border-radius:6px;font-size:14px">重试</button>'
               . '<a href="install.php?reconfig=1" style="display:inline-block;margin:4px;padding:8px 20px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-size:14px">重新配置数据库</a>'
               . '</p>'
               . '<p style="color:#94a3b8;font-size:12px;margin-top:14px">MySQL 未启动 → 先启动数据库服务再点「重试」；凭据错误 → 点「重新配置数据库」重新填写。</p>'
               . '</div></body></html>';
            exit;
        }
    }
    return $db;
}

// ── 初始化数据库 ──
/** 编排表结构对齐（幂等）+ 触发惰性定时任务。 */
function initDB(bool $force = false): void
{
    $db = getDB();

    if (!$force && schemaAligned($db)) {
        maybeRunScheduledBackup($db);
        maybeStartBackupKeeper();
        maybeRunScheduledCachePurge($db);
        return;
    }

    // 待迁移分支：库版本落后**且** Versions/ 有对应步骤文件时，

    if (!$force && \App\Core\Migration\MigrationGate::pending($db)) {
        maybeRunScheduledBackup($db);
        maybeStartBackupKeeper();
        maybeRunScheduledCachePurge($db);
        return;
    }

    \App\Core\Schema\schemaSystem($db);
    \App\Core\Schema\schemaPart($db);
    \App\Core\Schema\schemaStock($db);
    \App\Core\Schema\schemaBom($db);
    \App\Core\Schema\schemaImport($db);
    \App\Core\Schema\schemaAudit($db);
    \App\Core\Schema\schemaWorkspaceIsolation($db);

    maybeRunScheduledBackup($db);
    // 定时备份：schedule 模式抢守候资格（响应交还后驻留，到点自动执行）
    maybeStartBackupKeeper();

    maybeRunScheduledCachePurge($db);
}

/** 判断库结构是否已与代码声明对齐（initDB 的版本短路判据） */
function schemaAligned(PDO $db): bool
{
    try {
        $stmt = $db->query("SELECT database_version FROM system_meta ORDER BY id DESC LIMIT 1");
        $val  = $stmt === false ? false : $stmt->fetchColumn();
        return $val !== false && $val !== null && (string)$val === (string)DATABASE_VERSION;
    } catch (Throwable $e) {
        return false;   // 表不存在 / 无权限 / 任何异常 → 当作未对齐
    }
}

/** 定时备份的执行判定：**现在该不该跑一次** */
function backupDueGap(int $now): int
{
    $frequency = getSetting('backup_frequency', 'disabled');
    if ($frequency !== 'daily' && $frequency !== 'weekly') return 0;
    $gap    = $frequency === 'daily' ? 86400 : 604800;
    $lastAt = (int)getSetting('backup_last_at', '0');
    $hhmm   = (string)getSetting('backup_time', '');
    $at     = backupTimeToMinutes($hhmm);

    // ①未限定时刻：满一个间隔即可（从未执行 → 立即给一次）
    if ($at === null) {
        return ($lastAt <= 0 || ($now - $lastAt) >= $gap) ? $gap : 0;
    }
    // ②从未执行：过当天设定点才给机会（保持「设 03:00 不会一启用就在白天跑」）
    if ($lastAt <= 0) {
        $dayStart = strtotime(date('Y-m-d', $now) . ' 00:00:00');
        return ($dayStart !== false && $now >= $dayStart + $at * 60) ? $gap : 0;
    }

    $due = backupNextOccurrence($hhmm, $lastAt, $frequency === 'weekly' ? 6 : 0);
    return ($due !== null && $now >= $due) ? $gap : 0;
}

/** 下一个严格晚于基准时刻的「HH:MM」设定点（Unix 时间戳） */
function backupNextOccurrence(string $time, int $after, int $extraDays = 0): ?int
{
    $at = backupTimeToMinutes($time);
    if ($at === null) return null;
    $from     = $after + $extraDays * 86400;
    $dayStart = strtotime(date('Y-m-d', $from) . ' 00:00:00');
    if ($dayStart === false) return null;
    for ($i = 0; $i < 370; $i++) {          // 一年足够；设定点每天一次，最多顺延 1 天
        $t = $dayStart + $at * 60;
        if ($t > $from) return $t;          // 当天已过点 → 下面顺到明天
        $dayStart += 86400;
    }
    return null;
}

/** 下一次应当执行的时刻（Unix 时间戳） */
function backupNextDueAt(int $now): int
{
    if (backupDueGap($now) !== 0) return $now;
    $frequency = getSetting('backup_frequency', 'disabled');
    if ($frequency !== 'daily' && $frequency !== 'weekly') return 0;

    $gap    = $frequency === 'daily' ? 86400 : 604800;
    $lastAt = (int)getSetting('backup_last_at', '0');
    $hhmm   = (string)getSetting('backup_time', '');
    $at     = backupTimeToMinutes($hhmm);

    if ($at === null) {                                   // 未限定时刻 → 满间隔即可
        return $lastAt > 0 ? max($now, $lastAt + $gap) : $now;
    }
    if ($lastAt <= 0) {                                   // 从未执行 → 今天设定点
        $dayStart = strtotime(date('Y-m-d', $now) . ' 00:00:00');
        $t = ($dayStart !== false) ? $dayStart + $at * 60 : $now;
        return max($now, $t);
    }
    $due = backupNextOccurrence($hhmm, $lastAt, $frequency === 'weekly' ? 6 : 0);
    if ($due === null) return max($now, $lastAt + $gap);
    return max($now, $due);
}

/** 备份模式：schedule=到点自动执行（自持守候）；on_request=周期内首次请求执行 */
function backupMode(): string
{
    return getSetting('backup_mode', 'schedule') === 'on_request' ? 'on_request' : 'schedule';
}

/** 执行一次定时备份（抢占 → 导出 → 滚动清理 → 补记日志） */
function runScheduledBackupOnce(PDO $db): bool
{
    $now = time();
    // 原子抢占：条件更新把「检查 + 占位」合成一条语句，并发下只有一个请求能成功

    $db->prepare("INSERT IGNORE INTO settings (k,v) VALUES ('backup_last_at','0')")->execute();
    $claim = $db->prepare("UPDATE settings SET v=? WHERE k='backup_last_at' AND CAST(v AS UNSIGNED) < ?");
    $claim->execute([(string)$now, (string)$now]);
    if ($claim->rowCount() !== 1) return false;

    \clearSettingCache('backup_last_at');

    $lock = @fopen(__DIR__ . '/backups/.backup.lock', 'c');
    if ($lock === false) return false;                   // 目录不可写则放弃（时间戳已占位，等下个窗口）
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }

    try {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        gc_collect_cycles();

        $tables = array_values(array_filter($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
        $sql = "-- LCSC Auto Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n"
             . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $t) {
            try {
                $create = $db->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
                $sql .= "DROP TABLE IF EXISTS `{$t}`;\n" . $create[1] . ";\n\n";
                $s = $db->query("SELECT * FROM `{$t}`");
                while ($r = $s->fetch(PDO::FETCH_NUM)) {
                    $vals = array_map(fn($x) => $x === null ? 'NULL' : $db->quote((string)$x), $r);
                    $sql .= "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n";
                }
                $sql .= "\n";
            } catch (Throwable $e) {
                $sql .= "-- Table {$t} skipped: " . $e->getMessage() . "\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $dir = __DIR__ . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'auto_backup_' . date('Ymd_His') . '.sql';
        file_put_contents($dir . '/' . $filename, $sql);

        // 保留份数清理
        $keep  = max(1, min(30, (int)getSetting('backup_keep_count', '7')));
        $files = glob($dir . '/auto_backup_*.sql') ?: [];
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($files, $keep) as $old) @unlink($old);

        // 写入备份记录，使自动备份在「备份操作记录」中可见并可点击恢复
        recordBackupLog(0, $filename, strlen($sql));

        traceLog(0, 'backup_auto', 'system', 0, "自动备份 {$filename} 保留{$keep}份");
        unset($sql);                                  // 正文可能上百 MB，用完即放
        gc_collect_cycles();
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** 定时备份的惰性触发器（由 initDB() 末尾调用） */
function maybeRunScheduledBackup(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        if (backupDueGap(time()) === 0) return;
        runScheduledBackupOnce($db);
    } catch (Throwable $e) {
        error_log('maybeRunScheduledBackup: ' . $e->getMessage());
    }
}

/** 守候单次睡眠秒数（也是到点的时间粒度） */
const BACKUP_KEEPER_TICK = 10;
/** 守候单次生命周期上限（秒）：到点自动退出，由下一次访问重新拉起 */
const BACKUP_KEEPER_MAX_LIFE = 43200;

/** 守候能否在当前 SAPI 下驻留 */
function backupKeeperSupported(): bool
{
    if (PHP_SAPI === 'cli') return false;
    if (PHP_SAPI === 'cli-server') {
        $workers = getenv('PHP_CLI_SERVER_WORKERS');
        return $workers !== false && (int)$workers > 1;
    }
    return true;
}

/** 抢占守候资格（由 initDB() 末尾调用） */
function maybeStartBackupKeeper(): void
{
    static $started = null;
    if ($started !== null) return;
    $started = false;

    try {
        if (!backupKeeperSupported()) return;
        if (backupMode() !== 'schedule') return;
        $frequency = getSetting('backup_frequency', 'disabled');
        if ($frequency !== 'daily' && $frequency !== 'weekly') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;

        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'healthcheck.php') return;

        $dir = __DIR__ . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $lock = @fopen($dir . '/.keeper.lock', 'c');
        if ($lock === false) return;
        if (!flock($lock, LOCK_EX | LOCK_NB)) {          // 已有守候在跑
            fclose($lock);
            return;
        }
        $GLOBALS['__lcscKeeperLock'] = $lock;
        $started = true;
        register_shutdown_function('lcscRunBackupKeeper');
    } catch (Throwable $e) {
        error_log('maybeStartBackupKeeper: ' . $e->getMessage());
        $lock = $GLOBALS['__lcscKeeperLock'] ?? null;
        if ($lock !== null) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            unset($GLOBALS['__lcscKeeperLock']);
        }
    }
}

/** 交还当前响应，让本进程可以继续驻留 */
function backupKeeperDetachClient(): bool
{
    if (PHP_SAPI === 'cli') return true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return false;
    $status = http_response_code();
    if ((int)$status === 304) return false;

    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return true;
    }
    if (headers_sent()) return false;                    // 已发出：不能再声明长度
    if (ob_get_level() > 1) return false;                // 多层缓冲：取不干净，宁可放弃
    $body = '';
    if (ob_get_level() === 1) {
        $c = ob_get_clean();
        $body = ($c === false) ? '' : $c;
    }
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    flush();
    return true;
}

/** 守候主循环（register_shutdown_function 触发） */
function lcscRunBackupKeeper(): void
{
    $lock = $GLOBALS['__lcscKeeperLock'] ?? null;
    if ($lock === null) return;
    $release = static function () use ($lock): void {
        @flock($lock, LOCK_UN);
        @fclose($lock);
        unset($GLOBALS['__lcscKeeperLock']);
    };

    try {
        // 致命错误的请求：把响应让给全局 fatal 兜底处理器，本次不驻留
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $release();
            return;
        }
        // ① 先把响应交还客户端，再开始驻留
        if (!backupKeeperDetachClient()) { $release(); return; }

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        while (ob_get_level() > 0) { @ob_end_clean(); }
        @set_time_limit(0);
        ignore_user_abort(true);

        $start  = time();
        $pingAt = 0;
        while ((time() - $start) < BACKUP_KEEPER_MAX_LIFE) {
            // 配置可能被别的进程改掉：清缓存后重读，守候才跟得上「改模式/关频率」
            clearSettingCache();
            if (backupMode() !== 'schedule') break;
            $frequency = getSetting('backup_frequency', 'disabled');
            if ($frequency !== 'daily' && $frequency !== 'weekly') break;

            if ((time() - $pingAt) >= 60) {
                $pingAt = time();
                try { getDB()->query('SELECT 1'); } catch (Throwable $e) { break; }
            }

            $now = time();
            if (backupDueGap($now) !== 0) {
                runScheduledBackupOnce(getDB());
                break;                                  // 到点执行完即退出
            }
            $dueAt = backupNextDueAt($now);
            $sleep = ($dueAt > 0) ? max(1, min(BACKUP_KEEPER_TICK, $dueAt - $now)) : BACKUP_KEEPER_TICK;
            sleep($sleep);
        }
    } catch (Throwable $e) {
        error_log('lcscRunBackupKeeper: ' . $e->getMessage());
    } finally {
        $release();
    }
}

/** 外部服务缓存的惰性清理（由 initDB() 末尾调用） */
function maybeRunScheduledCachePurge(PDO $db): void
{

    static $done = false;
    if ($done) return;
    $done = true;

    try {

        if (!$db->query("SHOW TABLES LIKE 'external_cache'")->fetchColumn()) return;

        $gap  = 21600;                       // 6 小时
        $lastAt = (int)getSetting('cache_purge_last_at', '0');
        $now    = time();
        // 首次执行（last_at=0）时**不立即跑**：安装完先让业务跑起来，

        if ($lastAt <= 0 || ($now - $lastAt) < $gap) return;

        // CAS 抢占：条件更新把「判定 + 占位」合成一条语句，并发下只有一个请求成功
        $db->prepare("INSERT IGNORE INTO settings (k,v) VALUES ('cache_purge_last_at','0')")->execute();
        $claim = $db->prepare("UPDATE settings SET v=? WHERE k='cache_purge_last_at' AND CAST(v AS UNSIGNED) < ?");
        $claim->execute([(string)$now, (string)($now - $gap)]);
        if ($claim->rowCount() !== 1) return;

        $n = \App\Core\Http\ExternalCache::purgeExpired($db);
        if ($n > 0) {
            // 写 trace_log：清理条数是可观测指标，出问题时能查「上次清了多少」
            traceLog(0, 'cache_purge', 'system', 0, "清理外部服务缓存过期行 {$n} 条");
        }

        \clearSettingCache('cache_purge_last_at');
    } catch (Throwable $e) {
        // 时间戳已占位（CAS 成功后才执行到这里），失败不影响功能，下个窗口重试
        error_log('maybeRunScheduledCachePurge: ' . $e->getMessage());
    }
}

/** 「HH:MM」转当天第几分钟；格式非法返回 null（调用方按「不限定时刻」处理） */
function backupTimeToMinutes(string $time): ?int
{
    $time = trim($time);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}

/** 记录一条备份/恢复日志（写入失败只记 error_log，不阻断备份主流程） */
function recordBackupLog(int $userId, string $fileName, int $fileSize, string $action = 'backup'): void
{
    try {
        getDB()->prepare("INSERT INTO backup_log (user_id, file_name, file_size, action) VALUES (?, ?, ?, ?)")
            ->execute([$userId, mb_substr($fileName, 0, 255), $fileSize, $action === 'restore' ? 'restore' : 'backup']);
    } catch (Throwable $e) {
        error_log('recordBackupLog: ' . $e->getMessage());
    }
}

// ── API 统一响应函数 ─────────────────────
/** API 入口统一引导：清空缓冲区、固定 JSON 响应头、注册全局异常/错误捕获 */
function apiBootstrap(): void {

    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    header_remove('X-Powered-By');

    if (!isAjaxRequest()) {
        return;
    }
    // AJAX 请求：强制 JSON 响应头 + 全局异常/错误捕获
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    set_exception_handler(function (Throwable $e): void {
        error_log('[API Exception] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode([
            'code' => 1,
            'msg'  => '服务器内部错误，请稍后重试',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    // 全局错误捕获：将 fatal error 转为 JSON
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[API Fatal] ' . $err['message'] . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
            }
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'code' => 1,
                'msg'  => '服务器内部错误，请稍后重试',
                'data' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });
}

/** 统一 JSON 成功响应 */
function jsonResponse(array $data = [], string $msg = '操作成功'): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['code' => 0, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** 统一 JSON 错误响应 */
function jsonError(string $msg = '操作失败', int $code = 1, array $data = []): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** 判断当前请求是否为 AJAX（前端通过 header 或 POST 参数标识） */
function isAjaxRequest(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_POST['ajax'] ?? '') === '1'
        || ($_GET['ajax'] ?? '') === '1';
}

/** CSRF 校验（AJAX 模式返回 JSON 错误，普通模式跳转） */
function verifyCsrfSafe(): void {
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? $_GET['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        if (isAjaxRequest()) {
            jsonError('CSRF校验失败，请刷新页面重试', 403);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: login.php?reason=csrf');
        exit;
    }
}

// ── 安全头（在每个页面输出前调用）─────────────────────
function sendSecurityHeaders(): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
    // 防止点击劫持
    header('X-Frame-Options: SAMEORIGIN');
    // 防止 MIME 类型嗅探
    header('X-Content-Type-Options: nosniff');
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS：仅 HTTPS 下发送，且仅在内网穿透/SSL代理场景下启用

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=3600');
    }
    // 基础 CSP：限制脚本来源

    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com; "
         . "img-src 'self' data: blob:; "
         . "connect-src 'self'; "
         . "frame-src 'self'; "
         . "object-src 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self';";
    header("Content-Security-Policy: $csp");
    // 移除 PHP 版本头
    header_remove('X-Powered-By');
}

// ── 站内导航辅助 ───────────────────────────────────────
/** 检测是否为手机类移动设备 UA（平板走 PC 响应式布局，不判定为移动端） */
function isMobileUA(): bool {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return false;
    return (bool)preg_match('/iPhone|iPod|Android.*Mobile|Mobile.*Android|Windows Phone|webOS|BlackBerry|Opera Mini/i', $ua);
}

/** 获取当前 UI 模式（studio iframe / 普通） */
function getCurrentUiMode(): array {
    $modes = [];
    // 检查 GET
    if (isset($_GET['tab_mode']) && $_GET['tab_mode'] === 'studio') {
        $modes['tab_mode'] = 'studio';
    }
    // 检查 POST
    if (!isset($modes['tab_mode']) && isset($_POST['tab_mode']) && $_POST['tab_mode'] === 'studio') {
        $modes['tab_mode'] = 'studio';
    }
    // 检查 SESSION
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($modes['tab_mode']) && !empty($_SESSION['ui_tab_mode']) && $_SESSION['ui_tab_mode'] === 'studio') {
            $modes['tab_mode'] = 'studio';
        }
    }
    // 存入 session（如果从 GET/POST 检测到）
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($modes['tab_mode'])) {
            $_SESSION['ui_tab_mode'] = $modes['tab_mode'];
        }
    }
    return $modes;
}

/** 清除 session 中的 UI 模式（主框架加载时调用，避免主框架也带 tab_mode） */
function clearUiModeSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    unset($_SESSION['ui_tab_mode']);
}

/** 生成站内导航 URL，自动追加 tab_mode 参数防止 iframe 嵌套 */
function siteUrl(string $url): string {
    $params = getCurrentUiMode();
    if (empty($params)) return $url;
    $anchor = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $anchor = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $sep . http_build_query($params) . $anchor;
}

// ── 安全辅助 ───────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 商品编号渲染：检测 #数字内部 格式，把"内部"用小字号显示（便于区分自动生成编号） */
function formatPpn(string $ppn): string {
    if ($ppn !== '' && preg_match('/^(#\d+)内部$/', $ppn, $m)) {
        return h($m[1]) . '<small style="font-size:10px;color:var(--text3);font-weight:normal;">内部</small>';
    }
    return h($ppn);
}

/** 获取客户端真实IP（仅信任可信反向代理头） */
function getClientIP(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 仅当请求来自可信代理时才信任代理头
    $trustedProxies = ['127.0.0.1', '::1'];
    if (!in_array($remote, $trustedProxies, true)) {
        return $remote;
    }
    // 按优先级检查代理头
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP', // Cloudflare
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            // X-Forwarded-For 可能包含多个IP（逗号分隔），取第一个
            $ips = explode(',', $_SERVER[$h]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $remote;
}

/** 安全验证整数值，返回 int */
function safeInt(mixed $v, int $default = 0): int {
    return is_numeric($v) ? (int)$v : $default;
}

/** 安全验证正整数值（≥1），返回 int */
function safePosInt(mixed $v, int $default = 1): int {
    $i = safeInt($v, $default);
    return $i >= 1 ? $i : $default;
}

/** 验证并清理字符串输入 */
function safeStr(mixed $v, string $default = ''): string {
    if (!is_string($v) && !is_numeric($v)) return $default;
    $s = trim((string)$v);
    // 移除 null 字节和控制字符
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    return $s;
}

/** 验证 MIME 类型（用于文件上传，失败时拒绝） */
function isValidMime(string $tmpPath, array $allowedMimes): bool {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return false; // 无法检测时拒绝（fail-closed）
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return in_array($mime, $allowedMimes, true);
}

/** 验证文件名是否安全（仅允许字母数字、中文、下划线、点、短横线） */
function isValidFileName(string $name): bool {
    return preg_match('/^[\w\x{4e00}-\x{9fa5}.()\- ]+$/u', $name) === 1;
}

function csrf(): string {
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    // 防止空串绕过：hash_equals('', '') 返回 true
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: login.php?reason=csrf');
        exit;
    }
}

/**
 * 是否需要登录鉴权。
 * 返回 false 即「免登录模式」：自动以主管理员身份进入，无需输入密码。
 * 优先级：环境变量 LCSC_LOGIN_ENABLED 强制覆盖（false/0/off 关；true/1/on 开）；
 *         否则读系统设置 auth_enabled（默认 1=启用登录）。
 */
function loginRequired(): bool {
    $env = getenv('LCSC_LOGIN_ENABLED');
    if ($env !== false) {
        $e = strtolower(trim((string)$env));
        if (in_array($e, ['false', '0', 'off'], true)) return false;
        if (in_array($e, ['true', '1', 'on'], true))  return true;
    }
    $v = getSetting('auth_enabled', '1');
    return $v !== '0' && strtolower($v) !== 'false';
}

/** 免登录模式下自动登录的身份：优先主管理员（role=admin 且 parent_id 为空），否则任意管理员，再否则第一个用户。 */
function authBypassUser(): ?array {
    if (loginRequired()) return null;
    if (!function_exists('getDB')) return null;
    try {
        if (!isset($GLOBALS['db'])) initDB();
    } catch (\Throwable $e) {
        return null;
    }
    $db = getDB();
    $u = $db->query("SELECT * FROM users WHERE role='admin' AND parent_id IS NULL ORDER BY id ASC LIMIT 1")->fetch();
    if (!$u) $u = $db->query("SELECT * FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$u) $u = $db->query("SELECT * FROM users ORDER BY id ASC LIMIT 1")->fetch();
    if (!$u) return null;
    return [
        'id'             => (int)$u['id'],
        'username'       => (string)$u['username'],
        'role'           => (string)$u['role'],
        'parent_id'      => $u['parent_id'] ?? null,
        'permissions'    => $u['permissions'] ?? null,
        'must_change_pw' => 0,
    ];
}

function currentUser(): ?array {
    startSession();
    $u = $_SESSION['user'] ?? null;
    if (!$u && !loginRequired()) {
        $u = authBypassUser();
        if ($u) {
            $_SESSION['user'] = $u;
            // 免登录模式下前端仍需 CSRF 令牌做写操作，自动补一个
            if (empty($_SESSION['csrf'])) {
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
            }
        }
    }
    return $u;
}

function requireLogin(string $loginUrl = 'login.php'): array {

    $u = currentUser();
    if (!$u) {
        // 会话超时或未登录
        $isTimeout = isset($_SESSION['timeout_notice']);
        unset($_SESSION['timeout_notice']);
        // AJAX 请求：统一返回 JSON 鉴权失败响应（前端拦截器会弹超时弹窗）

        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                'code' => 403,
                'msg'  => $isTimeout ? '会话已超时，请重新登录' : '请先登录',
                'data' => ['auth' => false, 'timeout' => $isTimeout],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $loginUrl . ($isTimeout ? '?reason=timeout' : ''));
        exit;
    }
    if ($u['must_change_pw']) { header('Location: ' . siteUrl('index.php?v=change_password')); exit; }
    trackVisit();
    updateUserActivity();
    return $u;
}

/** AJAX 接口鉴权失败统一返回 JSON，前端通过全局拦截器弹出超时弹窗 */
function ajaxRequireLogin(): array {
    $u = currentUser();
    if (!$u) {
        $isTimeout = isset($_SESSION['timeout_notice']);
        unset($_SESSION['timeout_notice']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'code' => 403,
            'msg'  => $isTimeout ? '会话已超时，请重新登录' : '请先登录',
            'data' => ['auth' => false, 'timeout' => $isTimeout],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

function requireAdmin(): array {
    $u = requireLogin();
    if ($u['role'] !== 'admin') { header('Location: ' . siteUrl('index.php')); exit; }
    return $u;
}

/** 当前用户是否为管理员 */
function isAdmin(): bool {
    $u = currentUser();
    return $u && $u['role'] === 'admin';
}

/** 获取当前用户的数据归属用户ID */
function getDataUserId(): int {
    $u = currentUser();
    if (!$u) return 0;
    if (is_single_warehouse()) {
        // 个人版：共享第一个管理员的数据
        static $singleWarehouseUid = null;
        if ($singleWarehouseUid === null) {
            $db = getDB();
            $stmt = $db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1");
            $singleWarehouseUid = (int)($stmt->fetchColumn() ?: 1);
        }
        return $singleWarehouseUid;
    }
    return ($u['parent_id'] ?? null) ? (int)$u['parent_id'] : (int)$u['id'];
}

// ── Workspace 工作空间（单空间，恒为 1）──────────────────────

/** 获取当前用户的工作空间ID —— **单空间下恒返回 1** */
function getWorkspaceId(): int {
    static $wsId = null;
    if ($wsId !== null) return $wsId;
    $u = currentUser();
    if (!$u) { $wsId = 1; return 1; }
    // 优先从用户表的 workspace_id 字段读取
    if (!empty($u['workspace_id'])) { $wsId = (int)$u['workspace_id']; return $wsId; }
    // 回退：查询数据库
    try {
        $stmt = getDB()->prepare("SELECT workspace_id FROM users WHERE id=?");
        $stmt->execute([$u['id']]);
        $val = $stmt->fetchColumn();
        $wsId = ($val && (int)$val > 0) ? (int)$val : 1;
    } catch (Throwable $e) {
        $wsId = 1;
    }
    return $wsId;
}

// ── Stock 独立表辅助 ────────────────────────────────────────────

/** 同步 parts.stock 缓存（从独立 stock 表汇总写回 parts） */
function syncPartStockCache(int $partId): void {
    try {
        $db = getDB();
        // 自愈：stock 表无行时先播种（幂等：仅在确实无行时插入）
        $chk = $db->prepare("SELECT COUNT(*) FROM stock WHERE part_id=?");
        $chk->execute([$partId]);
        if ((int)$chk->fetchColumn() === 0) {
            $p = $db->prepare("SELECT stock, damaged, workspace_id FROM parts WHERE id=?");
            $p->execute([$partId]);
            $row = $p->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $db->prepare("INSERT IGNORE INTO stock (workspace_id, part_id, location_id, quantity, damaged) VALUES (?,?,0,?,?)")
                   ->execute([(int)$row['workspace_id'], $partId, (int)$row['stock'], (int)$row['damaged']]);
            }
        }
        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity),0), COALESCE(SUM(damaged),0) FROM stock WHERE part_id=?");
        $stmt->execute([$partId]);
        [$qty, $dmg] = $stmt->fetch(PDO::FETCH_NUM);
        $db->prepare("UPDATE parts SET stock=?, damaged=? WHERE id=?")
           ->execute([(int)$qty, (int)$dmg, $partId]);
    } catch (Throwable $e) {
        error_log("syncPartStockCache failed for part_id={$partId}: " . $e->getMessage());
    }
}

/** 确保 stock 表有物料记录（首次写入时自动创建行） */
function ensureStockRecord(int $partId, ?int $locationId = null): void {
    $db = getDB();
    $locId = $locationId ?? 0; // 0 = 未指定库位
    $stmt = $db->prepare("SELECT COUNT(*) FROM stock WHERE part_id=? AND location_id=?");
    $stmt->execute([$partId, $locId]);
    if ((int)$stmt->fetchColumn() === 0) {
        // 从 parts 缓存读取初始值
        $p = $db->prepare("SELECT stock, damaged, workspace_id FROM parts WHERE id=?");
        $p->execute([$partId]);
        $row = $p->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $db->prepare("INSERT IGNORE INTO stock (workspace_id, part_id, location_id, quantity, damaged) VALUES (?,?,?,?,?)")
               ->execute([(int)$row['workspace_id'], $partId, $locId, (int)$row['stock'], (int)$row['damaged']]);
        }
    }
}

/** 统一库存数量写入口（全系统唯一） */
function applyStockDelta(int $partId, int $delta, ?int $locationId = null): void {
    $db = getDB();
    $locId = $locationId ?? 0;
    ensureStockRecord($partId, $locId);   // 无行时以 parts.stock 播种
    $db->prepare("UPDATE stock SET quantity=GREATEST(0, quantity+?), update_time=NOW() WHERE part_id=? AND location_id=?")
       ->execute([$delta, $partId, $locId]);
    syncPartStockCache($partId);
}

/** 统一库存绝对值写入口（盘点 / 调整：以实盘数为准） */
function applyStockSet(int $partId, int $qty, ?int $locationId = null): void {
    $db = getDB();
    $locId = $locationId ?? 0;
    ensureStockRecord($partId, $locId);
    $other = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock WHERE part_id=? AND location_id<>?");
    $other->execute([$partId, $locId]);
    $target = max(0, $qty - (int)$other->fetchColumn());
    $db->prepare("UPDATE stock SET quantity=?, update_time=NOW() WHERE part_id=? AND location_id=?")
       ->execute([$target, $partId, $locId]);
    syncPartStockCache($partId);
}

/** 统一不良品数量写入口（报损 / 修复：改 stock.damaged 后回算缓存） */
function applyDamagedDelta(int $partId, int $delta, ?int $locationId = null): void {
    $db = getDB();
    $locId = $locationId ?? 0;
    ensureStockRecord($partId, $locId);
    $db->prepare("UPDATE stock SET damaged=GREATEST(0, damaged+?), update_time=NOW() WHERE part_id=? AND location_id=?")
       ->execute([$delta, $partId, $locId]);
    syncPartStockCache($partId);
}

/** 统一写入 stock_log（全系统唯一库存流水写入口） */
function insertStockLog(PDO $db, array $log): int {
    $partId     = (int)($log['part_id'] ?? 0);
    $locationId = $log['location_id'] ?? null;

    if ($locationId === null && $partId > 0) {
        try {
            $locStmt = $db->prepare("SELECT location_id FROM parts WHERE id=?");
            $locStmt->execute([$partId]);
            $locId = $locStmt->fetchColumn();
            if ($locId !== false && $locId !== null) $locationId = (int)$locId;
        } catch (Throwable $e) {
            error_log('insertStockLog location lookup: ' . $e->getMessage());
        }
    }

    // 操作人：优先取当前登录用户名，无会话（CLI/后台任务）时回退调用方显式传入
    $operator = '';
    try {
        $cur = currentUser();
        $operator = (string)($cur['username'] ?? '');
    } catch (Throwable $e) {
        $operator = '';
    }
    if ($operator === '' && isset($log['operator'])) $operator = (string)$log['operator'];

    $workspaceId = (int)($log['workspace_id'] ?? 0);
    if ($workspaceId <= 0) $workspaceId = getWorkspaceId();

    $changeType = (string)($log['change_type'] ?? '');
    if (!\App\Core\Enum\StockChangeType::isValid($changeType)) {
        error_log('insertStockLog: unknown stock_log.change_type "' . $changeType . '" part_id=' . $partId);
    }

    $stmt = $db->prepare(
        "INSERT INTO stock_log
            (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,
             unit_cost,is_sample,subtotal,order_time,remark,workspace_id,location_id,
             source_type,source_id,operator,ip,import_batch)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        (int)($log['user_id'] ?? 0),
        $partId,
        (string)($log['platform_part_no'] ?? ''),
        (string)($log['change_type'] ?? ''),
        (int)($log['qty_change'] ?? 0),
        (int)($log['qty_before'] ?? 0),
        (int)($log['qty_after'] ?? 0),
        (float)($log['unit_cost'] ?? 0),
        (int)($log['is_sample'] ?? 0),
        (float)($log['subtotal'] ?? 0),
        $log['order_time'] ?? null,
        (string)($log['remark'] ?? ''),
        $workspaceId,
        $locationId,
        $log['source_type'] ?? null,
        isset($log['source_id']) ? (int)$log['source_id'] : null,
        $operator,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        (string)($log['import_batch'] ?? ''),
    ]);
    return (int)$db->lastInsertId();
}

/** 确保管理员拥有默认平台（首次访问时自动创建） */
function ensureUserPlatforms(int $adminUid): void {
    if ($adminUid <= 0) return;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
    $stmt->execute([$adminUid]);
    if ((int)$stmt->fetchColumn() === 0) {

        $ins = $db->prepare("INSERT INTO platforms (user_id,code,name,url_template,is_default,platform_type)
                             VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE code=code");
        $ins->execute([$adminUid, 'lcsc', '立创商城', 'https://so.szlcsc.com/global.html?k={part_no}', 1, 'standard']);
        $ins->execute([$adminUid, 'other', '其他', '', 0, 'standard']);
    }
}

/** 确保导入基础设施就绪（幂等，单请求只跑一次） */
function ensureImportTemplates(int $uid): void {
    static $done = false;
    if ($done || $uid <= 0) return;
    $done = true;

    try {
        $db  = getDB();
        $ws  = getWorkspaceId();

        if (getSetting('lcsc_default_migrated', '') !== '1') {
            $lk = $db->prepare("SELECT id FROM platforms WHERE user_id=? AND code='lcsc' LIMIT 1");
            $lk->execute([$uid]);
            $lcscId = (int)$lk->fetchColumn();
            if ($lcscId > 0) {
                $db->prepare("UPDATE platforms SET is_default=0 WHERE user_id=?")->execute([$uid]);
                $db->prepare("UPDATE platforms SET is_default=1 WHERE id=?")->execute([$lcscId]);
            }
            setSetting('lcsc_default_migrated', '1');
        }

        \Modules\Import\Service\ImportTemplateService::seedBuiltin($db, $uid, $ws);

        \Modules\Scan\Service\ScanQrDictService::seedBuiltin($db, $uid, $ws);
    } catch (Throwable $e) {
        error_log('ensureImportTemplates: ' . $e->getMessage());
    }
}

/** 获取当前用户的权限列表（子用户从数据库读取，管理员全权限） */
function getUserPermissions(): array {
    $u = currentUser();
    if (!$u) return [];
    // 管理员拥有所有权限
    if (($u['role'] ?? '') === 'admin') {
        return ['can_edit','can_delete','can_import','can_manage_categories','can_batch','can_export','can_scan','can_print'];
    }
    // 子用户/普通用户从 permissions 字段读取
    if (!empty($u['permissions'])) {
        $perms = json_decode($u['permissions'], true);
        return is_array($perms) ? $perms : [];
    }
    return [];
}

/** 检查当前用户是否有某项权限 */
function hasPermission(string $perm): bool {
    return in_array($perm, getUserPermissions(), true);
}

/** 获取系统版本：personal=个人版, studio=团队版 */
function getEdition(): string {
    $ed = getSetting('edition', '');
    if ($ed !== 'personal' && $ed !== 'studio') $ed = 'studio'; // 默认团队版
    return $ed;
}

/** 功能门控：检查当前版本是否启用某功能 */
function hasFeature(string $feature): bool {
    // 成本查看使用功能开关
    if ($feature === 'cost_view') return hasFeature('feature_cost_view');

    if ($feature === 'location_manage') {
        return \Modules\Location\Service\LocationService::isEnabled();
    }

    $edition = getEdition();

    // ── 版本能力 = 开关，与 edition 无关 ──

    $commonFeatures = [
        'parts', 'categories', 'scan', 'manual_stock',
        'bom_basic', 'bom', 'project',
        'import_smart', 'import', 'import_engine',
        'export_simple', 'print_label', 'batch_basic',

        'warning_low_stock', 'stock_log',

        'parameter_parser',
        'supplier_simple',
    ];

    // ── 功能开关（settings 表；未设定时取 default）──
    // **这是「能力差异」的唯一来源**，版本不参与判定 —— 唯一例外是默认值本身：

    $fd = featureDefaults();
    $switchFeatures = [
        'feature_cost_view'       => $fd['feature_cost_view']   === '1',  // 成本核算与查看
        'feature_asset_stats'     => $fd['feature_asset_stats'] === '1',  // 资产总览与统计
        'feature_bom_version'     => $fd['feature_bom_version'] === '1',  // BOM 多版本
        'feature_log_view'        => $fd['feature_log_view']    === '1',  // 操作日志 / 溯源
    ];
    if (isset($switchFeatures[$feature])) {
        $val = getSetting($feature, '');
        if ($val !== '' && $val !== null) return $val === '1' || $val === 'true';
        return $switchFeatures[$feature];
    }

    if (in_array($feature, $commonFeatures, true)) return true;

    // ── 真正按版本分叉的三项（`price_enforce` / `multi_user` 族/ `checklist`）──

    if ($feature === 'price_enforce') {
        return $edition === 'studio';
    }
    // ② 子用户与权限：个人版无子用户入口，数据统一落到主管理员 —— 这是
    // **数据归属模型不同**（见 is_single_warehouse()），不是「功能隐藏」能表达的，

    if ($feature === 'multi_user' || $feature === 'permission' || $feature === 'team') {
        return $edition === 'studio';
    }
    // ③ 库存盘点（`checklist`）：团队版专属。

    if ($feature === 'checklist') {
        return $edition === 'studio';
    }

    return false;
}

/** 是否启用成本/价格查看（委托给功能开关） */
function can_view_cost(): bool {
    return hasFeature('feature_cost_view');
}

/** 功能开关的「默认值」唯一来源（未设定时取此处的值） */
function featureDefaults(): array
{
    return [
        'feature_cost_view'      => '1',
        // 团队版默认开启；个人版默认关闭（设置页可随时开启）
        'feature_asset_stats'    => getEdition() === 'studio' ? '1' : '0',
        'feature_bom_version'    => '0',
        'feature_log_view'       => '1',
        'enable_location_manage' => '0',
    ];
}

/** 版本是否已锁定（edition一旦设定不可更改） */
function isEditionLocked(): bool {
    return getSetting('edition', '') !== '';
}

/** 团队版入库强制要求单价>0（BOM出库/撤销/不良品/盘点调整豁免） */
function isEnforceUnitPrice(): bool {

    return hasFeature('price_enforce');
}

/** 是否个人版单账户模式 */
function is_single_warehouse(): bool {
    return getEdition() === 'personal';
}

/** 是否为系统主管理员（`role=admin` 且 `id=1`） */
function isPrimaryAdmin(): bool {
    $u = currentUser();
    if (!$u) return false;
    return ($u['role'] ?? '') === 'admin' && (int)$u['id'] === 1;
}

/** 登录失败检查（防暴力破解） */
function checkLoginThrottle(string $username): ?string {
    $db = getDB();
    $ip = getClientIP();
    $cutoff = date('Y-m-d H:i:s', time() - 900); // 15分钟
    // 同一IP 15分钟内失败次数
    $cnt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND created_at>?");
    $cnt->execute([$ip, $cutoff]);
    if ((int)$cnt->fetchColumn() >= 10) return 'IP 登录失败次数过多，请15分钟后再试';
    // 同一用户名 15分钟内失败次数
    $cnt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE username=? AND success=0 AND created_at>?");
    $cnt->execute([$username, $cutoff]);
    if ((int)$cnt->fetchColumn() >= 5) return '该账号登录失败次数过多，请15分钟后再试';
    return null;
}

/** 记录登录尝试 */
function logLoginAttempt(string $username, bool $success): void {
    $ip = getClientIP();
    getDB()->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)")
           ->execute([$username, $ip, $success ? 1 : 0]);
}

/** 读全局设置。 */
function getSetting(string $k, string $default = ''): string {
    $GLOBALS['__lcsc_setting_cache'] ??= [];
    if (!isset($GLOBALS['__lcsc_setting_cache'][$k])) {
        $s = getDB()->prepare("SELECT v FROM settings WHERE k=?");
        $s->execute([$k]);
        $col = $s->fetchColumn();

        $GLOBALS['__lcsc_setting_cache'][$k] = ($col === false || $col === null) ? $default : (string)$col;
    }
    return (string)$GLOBALS['__lcsc_setting_cache'][$k];
}

/** 失效 `getSetting()` 的进程内缓存。 */
function clearSettingCache(?string $k = null): void {
    $GLOBALS['__lcsc_setting_cache'] ??= [];
    if ($k === null) { $GLOBALS['__lcsc_setting_cache'] = []; return; }
    unset($GLOBALS['__lcsc_setting_cache'][$k]);
}

/** 写全局设置。 */
function setSetting(string $k, string $v): void {
    getDB()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")
           ->execute([$k, $v, $v]);
    clearSettingCache($k);
}

// ── 用户级设置（按 user_id 存 k/v，承载子用户公告等按人生效的配置）──
/** 读用户级设置。 */
function getUserSetting(int $uid, string $k, string $default = ''): string {

    $GLOBALS['__lcsc_user_setting_cache'] ??= [];
    $ck = $uid . '|' . $k;
    if (!isset($GLOBALS['__lcsc_user_setting_cache'][$ck])) {
        $s = getDB()->prepare("SELECT v FROM user_settings WHERE user_id=? AND k=?");
        $s->execute([$uid, $k]);
        $col = $s->fetchColumn();
        $GLOBALS['__lcsc_user_setting_cache'][$ck] = ($col === false || $col === null) ? $default : (string)$col;
    }
    return (string)$GLOBALS['__lcsc_user_setting_cache'][$ck];
}

/** 清用户级设置缓存。 */
function clearUserSettingCache(?int $uid = null, ?string $k = null): void
{
    $GLOBALS['__lcsc_user_setting_cache'] ??= [];
    if ($uid === null) { $GLOBALS['__lcsc_user_setting_cache'] = []; return; }
    foreach (array_keys($GLOBALS['__lcsc_user_setting_cache']) as $ck) {
        if ($k !== null && $ck !== $uid . '|' . $k) continue;
        if (strpos($ck, $uid . '|') === 0) unset($GLOBALS['__lcsc_user_setting_cache'][$ck]);
    }
}

/** 写用户级设置（同时使该键的读缓存失效，保证同进程内立即生效） */
function setUserSetting(int $uid, string $k, string $v): void {
    getDB()->prepare("INSERT INTO user_settings (user_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=?")
           ->execute([$uid, $k, $v, $v]);
    clearUserSettingCache($uid, $k);
}

/** 解析"欢迎/公告"通知（供工作台等页面以轻提示展示）。 */
function resolveWelcomeNotices(int $uid): array {
    $db = getDB();
    $res = ['global' => null, 'sub' => null];

    $content = (string)getSetting('notice_content', '');
    $mode    = (string)getSetting('notice_show_mode', 'off');
    if ($content !== '' && $mode !== 'off') {
        $ver = md5($content);
        if ($mode === 'always') {
            $res['global'] = ['content' => $content];
        } else {
            try {
                $seen = $db->prepare("SELECT 1 FROM notice_seen WHERE user_id=? AND version=?");
                $seen->execute([$uid, $ver]);
                if (!$seen->fetchColumn()) {
                    $db->prepare("INSERT IGNORE INTO notice_seen (user_id,version) VALUES (?,?)")->execute([$uid, $ver]);
                    $res['global'] = ['content' => $content];
                }
            } catch (\Throwable $e) { error_log('resolveWelcomeNotices global: ' . $e->getMessage()); }
        }
    }

    return $res;
}

// ── 线上版本校验（双仓库择优，仅读取版本信息）──

const VERSION_DOMAIN_WHITELIST = \App\Core\Update\UpdateSource::VERSION_HOSTS;

/** 解析 CHANGELOG.md 并按版本范围过滤 */
function filterChangelogByVersion(string $changelog, string $currentVer, string $remoteVer): string {
    if ($changelog === '' || $currentVer === '' || $remoteVer === '') return '';
    // 按版本标题拆分段落（## vX.Y.Z 或 ## X.Y.Z）
    $pattern = '/^##\s+v?(\d+\.\d+\.\d+)\b[^\n]*$/m';
    if (!preg_match_all($pattern, $changelog, $matches, PREG_OFFSET_CAPTURE)) return '';
    $sections = [];
    for ($i = 0; $i < count($matches[0]); $i++) {
        $ver   = $matches[1][$i][0];
        $start = (int)$matches[0][$i][1];
        $end   = ($i + 1 < count($matches[0])) ? (int)$matches[0][$i + 1][1] : strlen($changelog);
        $body  = substr($changelog, $start, $end - $start);
        $sections[] = ['ver' => $ver, 'body' => $body];
    }
    // 过滤：current < version <= remote
    $filtered = [];
    foreach ($sections as $s) {
        if (version_compare($s['ver'], $currentVer, '>') && version_compare($s['ver'], $remoteVer, '<=')) {
            $filtered[] = trim($s['body']);
        }
    }
    return $filtered ? implode("\n\n---\n\n", $filtered) : '';
}

function checkRemoteVersion(): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '服务器未安装 cURL 扩展，无法检测版本'];
    }
    // 双静态仓库：Gitee 国内 + GitHub 海外

    $repos = \App\Core\Update\UpdateSource::repos();
    // 缓存破坏参数
    $cacheBust = '?_t=' . time();
    // 并行请求两个仓库的 version.json
    $mh = curl_multi_init();
    $handles = [];
    $versionHosts = \App\Core\Update\UpdateSource::versionHosts();
    foreach ($repos as $key => $repo) {
        $url = $repo['version'] . $cacheBust;
        // 域名白名单校验
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !in_array($host, $versionHosts, true)) continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LCSC-Inventory/' . APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    if (empty($handles)) {
        return ['ok' => false, 'error' => '无可用版本源（域名白名单校验失败）'];
    }
    // 执行并行请求
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1);
    } while ($active && $status === CURLM_OK);
    // 收集结果，选择延迟更低的仓库
    $bestSource = null;
    $bestData = null;
    $bestTime = PHP_FLOAT_MAX;
    $errors = [];
    foreach ($handles as $key => $ch) {
        $data = curl_multi_getcontent($ch);
        $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        if ($err === '' && $code === 200 && $data !== false) {
            if ($time < $bestTime) {
                $bestTime = $time;
                $bestSource = $key;
                $bestData = $data;
            }
        } else {
            $errors[] = $key . ': ' . ($err ?: 'HTTP ' . $code);
        }
    }
    curl_multi_close($mh);
    if ($bestSource === null) {
        return ['ok' => false, 'error' => '双仓库均访问失败 (' . implode('; ', $errors) . ')'];
    }
    $data = json_decode($bestData, true);
    if (!is_array($data) || empty($data['version'])) {
        return ['ok' => false, 'error' => '版本文件格式无效（需 JSON 含 version 字段）'];
    }
    $remoteVer = (string)$data['version'];
    $hasUpdate = version_compare($remoteVer, APP_VERSION, '>');
    $releaseUrl = $repos[$bestSource]['release'];

    $changelog = '';
    if ($hasUpdate) {
        $clUrl = $repos[$bestSource]['changelog'] . $cacheBust;
        $clCh = curl_init($clUrl);
        curl_setopt_array($clCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LCSC-Inventory/' . APP_VERSION,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $rawChangelog = (string)curl_exec($clCh);
        if (curl_error($clCh)) {
            $changelog = '';
        } else {
            // 仅展示用户当前版本到线上新版本之间的改动
            $changelog = filterChangelogByVersion($rawChangelog, APP_VERSION, $remoteVer);
            if ($changelog === '') {
                // 解析为空时回退为原始日志（容错）
                $changelog = $rawChangelog;
            }
        }
        curl_close($clCh);
    }
    return [
        'ok'          => true,
        'has_update'  => $hasUpdate,
        'current'     => APP_VERSION,
        'remote'      => $remoteVer,
        'changelog'   => $changelog,
        'release_url' => $releaseUrl,
        'source'      => $bestSource,
    ];
}

/** 获取生效的低库存阈值（单品 > 分类 > 用户级/全局） */
function getGlobalThreshold(): int {
    // 非主管理员（子用户进不了后台，其阈值为管理员在全局配置页代设）走用户级配置
    $user = currentUser();
    if ($user && $user['role'] === 'admin' && !isPrimaryAdmin()) {
        $v = getUserSetting($user['id'], 'default_low_stock', '');
        if ($v !== '' && $v !== '0') return (int)$v;
    }
    return (int)getSetting('default_low_stock', '10');
}

// ── 访问统计追踪 ──
function trackVisit(): void {
    static $tracked = false;
    if ($tracked) return;
    $tracked = true;
    try {
        $db = getDB();
        $today = date('Y-m-d');
        $ip = getClientIP();
        $db->prepare("INSERT INTO daily_stats (stat_date,total_visits) VALUES (?,1) ON DUPLICATE KEY UPDATE total_visits=total_visits+1")
           ->execute([$today]);
        $db->prepare("INSERT IGNORE INTO daily_ip_log (stat_date,ip) VALUES (?,?)")->execute([$today, $ip]);
        $db->prepare("UPDATE daily_stats SET unique_ips=(SELECT COUNT(*) FROM daily_ip_log WHERE stat_date=?) WHERE stat_date=?")
           ->execute([$today, $today]);
    } catch (Throwable $e) {}
}

// ── 更新用户最后活动时间 ──
function updateUserActivity(): void {
    static $updated = false;
    if ($updated) return;
    $updated = true;
    try {
        $user = currentUser();
        if ($user) {
            getDB()->prepare("UPDATE users SET last_activity=NOW() WHERE id=?")->execute([$user['id']]);
        }
    } catch (Throwable $e) {}
}

/** 操作溯源日志（全系统写操作留存，用于追责） */
function traceLog(int $userId, string $action, string $targetType, int $targetId, string $detail = ''): void {
    try {
        getDB()->prepare("INSERT INTO trace_log (user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)")
               ->execute([$userId, $action, $targetType, $targetId, $detail, getClientIP()]);
    } catch (\Throwable $e) {
        // 溯源日志写入失败不应影响主业务流程
        error_log('traceLog failed: ' . $e->getMessage());
    }
}

/** 获取操作记录最低留存天数（全系统统一，最小7天） */
function getRetentionDays(): int {
    $days = (int)getSetting('retention_days', '30');
    if ($days < 7) $days = 7;
    return $days;
}

/** 检查某条记录是否已过留存期（允许删除） */
function isRetentionExpired(string $dateTime): bool {
    if ($dateTime === '') return true;
    $days = getRetentionDays();
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    return strcmp($dateTime, $cutoff) < 0;
}

// ── 分类辅助 ───────────────────────────────────────────
function getOrCreateCategory(int $userId, string $name): int {
    $name = trim($name);
    if ($name === '') return 0;
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = mb_convert_encoding($name, 'UTF-8', 'auto');
        $name = trim($name);
    }
    if ($name === '') return 0;
    $db   = getDB();
    $hex  = bin2hex($name);
    $stmt = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=UNHEX(?)");
    $stmt->execute([$userId, $hex]);
    $row  = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $db->prepare("INSERT INTO categories (user_id,parent_id,name) VALUES (?,0,UNHEX(?))")->execute([$userId, $hex]);
    return (int)$db->lastInsertId();
}

function linkCategories(int $partId, int $userId, array $names): void {
    $db = getDB();
    foreach (array_unique($names) as $name) {
        $cid = getOrCreateCategory($userId, $name);
        if ($cid > 0) {
            $db->prepare("INSERT IGNORE INTO part_categories (part_id,category_id) VALUES (?,?)")
               ->execute([$partId, $cid]);
        }
    }
}

function parseCategories(string $typeStr): array {
    $name = preg_replace('/[\x{FF08}\x{0028}][^\x{FF09}\x{0029}]*[\x{FF09}\x{0029}]/u', '', $typeStr);
    $name = trim((string)$name, " \t\n\r/,|");
    if ($name === '') $name = trim($typeStr, " \t\n\r/,|");
    return $name !== '' ? [$name] : [];
}

/** 根据平台 URL 模板生成跳转链接（校验 URL 协议） */
function platformUrl(string $urlTemplate, string $partNo): string {
    if ($urlTemplate === '' || $partNo === '') return '';
    // 仅允许 http/https 协议，防止 javascript: 等危险协议
    if (!preg_match('#^https?://#i', $urlTemplate)) return '';
    return str_replace('{part_no}', urlencode($partNo), $urlTemplate);
}

/** 内部编号 → 显示码：P + 5 位补零（P00001） */
function partCode($internalId): string {
    $n = (int)$internalId;
    return $n > 0 ? 'P' . str_pad((string)$n, 5, '0', STR_PAD_LEFT) : '';
}

/** 清理替代料双向关联：从 part_alternatives 表中移除被删除 part_id 的所有关联 */
function cleanupAlternativesReverseLinks(array $deletedIds, int $dataUid): void {
    if (empty($deletedIds)) return;
    try {
        $db = getDB();

        $in = implode(',', array_fill(0, count($deletedIds), '?'));
        $db->prepare("DELETE FROM part_alternatives WHERE source_part_id IN ($in) OR target_part_id IN ($in)")
           ->execute([...$deletedIds, ...$deletedIds]);
    } catch (Throwable $e) {}
}

/** 密码强度校验：至少 8 位，包含大小写字母、数字、特殊字符中的 3 种 */
function isStrongPassword(string $pw): bool {
    if (strlen($pw) < 8) return false;
    $classes = 0;
    if (preg_match('/[a-z]/', $pw)) $classes++;
    if (preg_match('/[A-Z]/', $pw)) $classes++;
    if (preg_match('/[0-9]/', $pw)) $classes++;
    if (preg_match('/[^a-zA-Z0-9]/', $pw)) $classes++;
    return $classes >= 3;
}

/** CSV 安全处理：防止公式注入（= + - @ 开头的值前缀单引号） */
function csvSafe(mixed $v): string {
    $s = (string)($v ?? '');
    // 防止 CSV 公式注入：以 = + - @ \t \r \n 开头的值前缀单引号
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
        $s = "'" . $s;
    }
    return $s;
}

/** 标记物料成本缓存为脏（需要重新计算） */
function markCostDirty(PDO $db, int $partId): void {

    try {
        $db->prepare("UPDATE parts SET cost_dirty=1 WHERE id=?")->execute([$partId]);
    } catch (Throwable $e) {
        // 脏标记失败不影响主流程，仅记录错误
        error_log("markCostDirty failed for part_id={$partId}: " . $e->getMessage());
    }
}

/** 批量重算脏物料的成本缓存（一条 UPDATE，覆盖所有 cost_dirty=1 的行） */
function recalcDirtyCostBatch(PDO $db, int $limit = 500, int $userId = 0, int $wsId = 0): int
{
    $limit = max(1, min(5000, $limit));
    $where = "cost_dirty = 1 AND status != 'deleted'";
    $args  = [];
    if ($userId > 0) { $where .= ' AND user_id = ?'; $args[] = $userId; }
    if ($wsId   > 0) { $where .= ' AND workspace_id = ?'; $args[] = $wsId; }
    try {

        $sql = "UPDATE parts p
                SET p.avg_cost = (
                        SELECT ROUND(
                                   SUM(CASE WHEN s.qty_change > 0 AND s.unit_cost > 0
                                            THEN s.qty_change * s.unit_cost ELSE 0 END)
                                   / NULLIF(SUM(CASE WHEN s.qty_change > 0 AND s.unit_cost > 0
                                                THEN s.qty_change ELSE 0 END), 0), 4)
                        FROM stock_log s WHERE s.part_id = p.id
                    ),
                    p.cost_dirty = 0,
                    p.last_cost_recalc = NOW()
                WHERE p.cost_dirty = 1
                  AND p.status != 'deleted'
                  AND p.id IN (
                        SELECT id FROM (
                              SELECT id FROM parts
                              WHERE {$where}
                              ORDER BY last_cost_recalc IS NULL DESC, last_cost_recalc ASC
                              LIMIT {$limit}
                        ) AS t
                  )";
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        // 成本是缓存，失败不影响导入结果 —— 只记日志
        error_log('recalcDirtyCostBatch failed: ' . $e->getMessage());
        return 0;
    }
}

/** 重算物料的加权平均成本 */
function recalcAvgCost(PDO $db, int $partId): float {
    // 静态缓存：5分钟内同一物料重复触发重算直接跳过
    static $recentCalc = [];
    $cacheKey = $partId;
    if (isset($recentCalc[$cacheKey]) && (time() - $recentCalc[$cacheKey]) < 300) {
        // 5分钟内已重算过，直接返回当前值
        $quick = $db->prepare("SELECT avg_cost FROM parts WHERE id=?");
        $quick->execute([$partId]);
        return round((float)($quick->fetchColumn() ?: 0), 4);
    }
    $recentCalc[$cacheKey] = time();

    if (count($recentCalc) > 500) {
        $cutoff = time() - 300;
        $recentCalc = array_filter($recentCalc, fn($t) => $t > $cutoff);
    }

    // 事务 + 行锁保护并发安全
    $db->beginTransaction();
    try {
        // SELECT FOR UPDATE 行锁，同一物料同时重算串行化
        $chk = $db->prepare("SELECT cost_dirty, avg_cost FROM parts WHERE id=? FOR UPDATE");
        $chk->execute([$partId]);
        $row = $chk->fetch();
        if (!$row) { $db->commit(); return 0.0; }
        if ((int)$row['cost_dirty'] === 0) { $db->commit(); return (float)$row['avg_cost']; }

        // 计算加权平均成本：仅统计入库类操作且 unit_cost > 0 的记录
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN qty_change > 0 AND unit_cost > 0 THEN qty_change ELSE 0 END), 0) AS total_qty,
                    COALESCE(SUM(CASE WHEN qty_change > 0 AND unit_cost > 0 THEN qty_change * unit_cost ELSE 0 END), 0) AS total_cost
             FROM stock_log WHERE part_id=?"
        );
        $stmt->execute([$partId]);
        $calc = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalQty = (float)$calc['total_qty'];
        $totalCost = (float)$calc['total_cost'];
        $avgCost = ($totalQty > 0) ? round($totalCost / $totalQty, 4) : 0.0;

        // 负成本钳位到0，记录异常日志供排查
        if ($avgCost < 0) {
            error_log("recalcAvgCost: negative avg_cost detected for part_id={$partId}, raw={$avgCost}, clamped to 0");
            $avgCost = 0.0;
        }

        // 更新缓存并清除脏标记
        $db->prepare("UPDATE parts SET avg_cost=?, cost_dirty=0, last_cost_recalc=NOW() WHERE id=?")
           ->execute([$avgCost, $partId]);

        $db->commit();
        return $avgCost;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log("recalcAvgCost error for part_id={$partId}: " . $e->getMessage());
        return 0.0;
    }
}
