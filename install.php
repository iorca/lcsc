<?php
declare(strict_types=1);
/** install.php - 系统安装向导 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// 输出缓冲：确保错误信息能显示
ob_start();

if (!defined('APP_VERSION')) define('APP_VERSION', '1.2.0');

date_default_timezone_set(getenv('LCSC_TZ') ?: 'Asia/Shanghai');

/** 静态资源 URL（带缓存戳 = APP_VERSION + 文件 mtime） */
function installAssetUrl(string $relPath): string
{
    $abs = __DIR__ . '/' . $relPath;
    $ver = APP_VERSION . (is_file($abs) ? '.' . filemtime($abs) : '');
    return $relPath . '?v=' . $ver;
}

/** 从 config.db.php 源码里读一个 `define('KEY', 'value')` 常量值（不存在返回 ''）。 */
function dbConfigConst(string $raw, string $key): string
{
    $re = '/define\(\s*\'' . preg_quote($key, '/') . '\'\s*,\s*\'([^\']*)\'\s*\)/';
    return preg_match($re, $raw, $m) ? (string)$m[1] : '';
}

// PHP 版本前置检查
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>环境错误</title>'
       . '<link rel="stylesheet" href="' . installAssetUrl('modules/system/Assets/css/install.css') . '"></head>'
       . '<body><div class="box">'
       . '<div class="brand"><div class="brand-logo">⚠️</div><div class="brand-txt"><h1>PHP 版本过低</h1>'
       . '<div class="sub">当前 ' . PHP_VERSION . '</div></div></div>'
       . '<div class="err">本系统要求 PHP <strong>8.0+</strong>（使用了 match 表达式、str_starts_with()、str_ends_with() 等 8.0+ 特性）。请升级 PHP 版本后重试。</div>'
       . '<div class="foot-note">LCSC 硬件资产管理系统 v' . APP_VERSION . '</div>'
       . '</div></body></html>';
    exit;
}

$lockFile = __DIR__ . '/.installed';
$isUpgrade = false;
$upgradeEdition = '';

// ── 非交互安装（Docker / Windows 一键部署）──

if ((getenv('LCSC_AUTOINSTALL') ?: '') === '1' && !file_exists($lockFile)) {
    runAutoInstall($lockFile);
}

// ── 安装后访问门控 ──

$alreadyInstalled = file_exists($lockFile) && file_exists(__DIR__ . '/config.db.php');

if ($alreadyInstalled && !isset($_GET['reset']) && !isset($_GET['reconfig'])) {
    header('Location: index.php');
    exit;
}
if ($alreadyInstalled && isset($_GET['reset'])) {
    define('INSTALL_MODE', true);
    require_once __DIR__ . '/config.php';
    startSession();
    $resetUser = currentUser();
    if (empty($resetUser) || !isAdmin()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403 无权限</title>'
           . '<link rel="stylesheet" href="' . installAssetUrl('modules/system/Assets/css/install.css') . '"></head><body><div class="box">'
           . '<div class="brand"><div class="brand-logo">🔒</div><div class="brand-txt"><h1>无权限</h1>'
           . '<div class="sub">HTTP 403</div></div></div>'
           . '<div class="err">系统已安装，重初始化入口仅对已登录的管理员开放。请先以管理员身份登录。</div>'
           . '<a href="login.php" class="btn btn-p">前往登录</a>'
           . '<div class="foot-note">LCSC 硬件资产管理系统 v' . APP_VERSION . '</div>'
           . '</div></body></html>';
        exit;
    }
}

// ── 重初始化入口：install.php?reset=1（管理员会话 + 管理员密码 + RESET 文本双因子确认）──
if (isset($_GET['reset'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $confirmCode = trim((string)($_POST['confirm_code'] ?? ''));
        $adminPass   = (string)($_POST['admin_password'] ?? '');
        $okText = ($confirmCode === 'RESET');
        $okPass = false;
        if ($okText && $adminPass !== '') {
            try {
                $pwStmt = getDB()->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
                $pwStmt->execute([(int)($resetUser['id'] ?? 0)]);
                $okPass = password_verify($adminPass, (string)$pwStmt->fetchColumn());
            } catch (Throwable $e) {
                $okPass = false;
            }
        }
        if ($okText && $okPass) {

            if (file_exists($lockFile)) unlink($lockFile);
            traceLog((int)($resetUser['id'] ?? 1), 'edition_reset', 'setting', 0, '重置安装锁，重新进入版本选择');
            header('Location: install.php?step=3');
            exit;
        }
        $resetError = '确认失败：需同时填写文本 RESET 与当前管理员密码';
    }
    // 显示重置确认页面
    showResetPage($resetError ?? '');
    exit;
}

// ── 数据库重配置入口：install.php?reconfig=1──

if (isset($_GET['reconfig'])) {
    $dbReachable = false;
    if (file_exists(__DIR__ . '/config.db.php')) {
        try {
            $rcRaw  = (string)@file_get_contents(__DIR__ . '/config.db.php');
            $rcHost = dbConfigConst($rcRaw, 'DB_HOST');
            $rcName = dbConfigConst($rcRaw, 'DB_NAME');
            $rcUser = dbConfigConst($rcRaw, 'DB_USER');
            $rcPass = dbConfigConst($rcRaw, 'DB_PASS');
            if ($rcHost !== '' && $rcName !== '') {
                new PDO(
                    "mysql:host={$rcHost};dbname={$rcName};charset=utf8mb4",
                    $rcUser, $rcPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $dbReachable = true;
            }
        } catch (Throwable $e) {
            $dbReachable = false;
        }
    }
    // 记录当前凭据用于表单回填（reconfig 时让用户看到并修正现值）
    $_rcPrefill = [
        'host' => $rcHost !== '' ? $rcHost : '127.0.0.1',
        'name' => $rcName,
        'user' => $rcUser,
    ];

    if ($dbReachable) {
        // 站点其实可用 → 必须是已登录管理员（此时应走正常后台，而非本入口）
        define('INSTALL_MODE', true);
        require_once __DIR__ . '/config.php';
        startSession();
        $rcAdmin = currentUser();
        if (empty($rcAdmin) || !isAdmin()) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>403 无权限</title>'
               . '<link rel="stylesheet" href="' . installAssetUrl('modules/system/Assets/css/install.css') . '"></head><body><div class="box">'
               . '<div class="brand"><div class="brand-logo">🔒</div><div class="brand-txt"><h1>无权限</h1><div class="sub">HTTP 403</div></div></div>'
               . '<div class="err">数据库当前可正常连接，站点无需重配置。若确要修改数据库指向，请先以管理员身份登录后再访问本入口。</div>'
               . '<a href="login.php" class="btn btn-p">前往登录</a>'
               . '<div class="foot-note">LCSC 硬件资产管理系统 v' . APP_VERSION . '</div>'
               . '</div></body></html>';
            exit;
        }
    }
}

// ── 已安装检测：允许直接访问，但显示已安装提示 ──
// 用户主动访问 install.php 说明想重装，不应阻止
// 仅在其他页面因缺少配置被跳转来时才需要提示
$alreadyInstalled = file_exists($lockFile) && file_exists(__DIR__ . '/config.db.php');
// 如果已安装且是自动跳转来的（非用户直接访问），回到首页
// 直接访问 install.php 始终放行，页面内显示状态提示

$step = max(1, min(3, (int)($_GET['step'] ?? 1)));

if (isset($_GET['reconfig'])) $step = 2;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $edition  = trim((string)($_POST['edition'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    // 待迁移源版本：优先取 step2 留在根目录的标记（正常流程）。

    $migrateFrom = is_file(__DIR__ . '/.install_migrate_from')
        ? trim((string)@file_get_contents(__DIR__ . '/.install_migrate_from'))
        : '';

    if (!in_array($edition, ['personal', 'studio'], true)) {
        $error = '请选择有效版本';
    } else {
        // 加载config连接数据库（INSTALL_MODE跳过安装拦截）
        define('INSTALL_MODE', true);
        try {
            require_once __DIR__ . '/config.php';

            if ($migrateFrom === '') {
                try {
                    $probe = new \App\Core\Migration\MigrationEngine(getDB());
                    if ($probe->needsMigration() && $probe->getMigrationPath() !== []) {
                        $migrateFrom = $probe->getCurrentVersion();
                    }
                } catch (Throwable $pe) {
                    $migrateFrom = '';
                }
            }

            initDB(true);
            $db = getDB();

            // 检测是否升级（已有管理员）
            $chkAdmin = $db->query("SELECT COUNT(*) FROM users WHERE role='admin'");
            $hasAdmin = (int)$chkAdmin->fetchColumn() > 0;

            if (!$hasAdmin) {
                // 全新安装：校验管理员输入
                if (strlen($username) < 3) { $error = '用户名至少3个字符'; }
                elseif (strlen($password) < 8) { $error = '密码至少8个字符'; }
                elseif ($password !== $confirm) { $error = '两次密码不一致'; }

                if (!$error) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $db->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, 'admin', '{}')")
                       ->execute([$username, $hash]);
                    session_start();
                    $newId = (int)$db->lastInsertId();
                    $_SESSION['user_id'] = $newId;
                    $_SESSION['user_role'] = 'admin';
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            }

            if (!$error) {
                // 写入全局配置
                setSetting('edition', $edition);

                foreach (featureDefaults() as $fk => $fv) {
                    setSetting($fk, $fv);
                }

                $engine    = null;
                $migResult = null;
                if ($migrateFrom !== '' && $migrateFrom !== DATABASE_VERSION) {
                    try {
                        $engine    = new \App\Core\Migration\MigrationEngine($db, $migrateFrom);
                        $migResult = $engine->execute();
                    } catch (Throwable $me) {
                        $engine    = null;
                        $migResult = null;
                        $error     = '数据库迁移执行异常：' . $me->getMessage();
                    }
                    if ($migResult !== null && $migResult->isFailed()) {
                        $error = '数据库迁移失败，升级未完成（已保留迁移前备份，可修复后重试）';
                    }
                    if ($error !== '') {
                        showMigrationResultPage($engine, $migResult, $error);
                        exit;
                    }
                    traceLog($hasAdmin ? 1 : ($newId ?? 1), 'db_migration', 'setting', 0,
                        '安装向导执行数据库迁移：' . $migrateFrom . ' → ' . DATABASE_VERSION
                        . '（' . count($migResult->getSteps()) . ' 步）');
                }
                @unlink(__DIR__ . '/.install_migrate_from');

                // 创建锁定文件
                file_put_contents($lockFile, date('Y-m-d H:i:s') . " edition={$edition}");

                traceLog($hasAdmin ? 1 : ($newId ?? 1), 'system_install', 'setting', 0,
                    ($hasAdmin ? '升级' : '全新安装') . " 版本:{$edition}");

                if ($migResult !== null) {
                    showMigrationResultPage($engine, $migResult);
                    exit;
                }
                header('Location: index.php');
                exit;
            }
        } catch (Throwable $ie) {

            $error = '完成安装失败：' . $ie->getMessage();
        }
    }
    step3_render: // 错误渲染入口（校验/异常时落到此处展示表单与错误）
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = max(1, min(65535, (int)($_POST['db_port'] ?? 3306)));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');

    if ($dbName === '' || $dbUser === '') {
        $error = '数据库名和用户名不能为空';
    } else {
        try {
            // 测试连接
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $testDb = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // 检查数据库是否存在
            $chkDb = $testDb->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{$dbName}'");
            if (!$chkDb->fetch()) {
                // 尝试创建数据库
                $testDb->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $testDb->exec("USE `{$dbName}`");

            // 检测升级 vs 全新安装
            $chkTables = $testDb->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $isUpgrade = in_array('users', $chkTables, true);

            // 写入 config.db.php（后续initDB需要读取）
            $configContent = "<?php\n"
                . "// 数据库配置（安装向导自动生成，请勿手动修改）\n"
                . "// 生成时间：" . date('Y-m-d H:i:s') . "\n"
                . "define('DB_HOST',    " . var_export($dbHost, true) . ");\n"
                . "define('DB_NAME',    " . var_export($dbName, true) . ");\n"
                . "define('DB_USER',    " . var_export($dbUser, true) . ");\n"
                . "define('DB_PASS',    " . var_export($dbPass, true) . ");\n"
                . "define('DB_CHARSET', 'utf8mb4');\n";
            file_put_contents(__DIR__ . '/config.db.php', $configContent);

            if (!$isUpgrade) {
                // 全新安装：用 config.php 的 initDB() 自动创建所有表结构
            } else {

            }

            $migrateFrom = '';
            try {
                if (!defined('INSTALL_MODE')) define('INSTALL_MODE', true);
                require_once __DIR__ . '/config.php';

                if ($isUpgrade) {
                    try {
                        $probe = new \App\Core\Migration\MigrationEngine(getDB());
                        if ($probe->needsMigration() && $probe->getMigrationPath() !== []) {
                            $migrateFrom = $probe->getCurrentVersion();
                        }
                    } catch (Throwable $pe) {
                        $migrateFrom = '';   // 探测失败按「无待迁移」处理，不阻塞安装
                    }
                }

                // 统一调用 initDB()：新建表 + 迁移新字段
                // **传 true 强制完整编排**：本入口的语义就是「按当前代码建/对齐结构」，
                // 若走默认短路，已有库会因版本号相等而跳过，新增列不会被补齐。
                initDB(true);
            } catch (Throwable $ie) {
                $error = '初始化表结构失败：' . $ie->getMessage();
            }

            if (empty($error) && $migrateFrom !== '') {
                @file_put_contents(__DIR__ . '/.install_migrate_from', $migrateFrom);
            }

            if (empty($error)) {
                // reconfig：只修数据库连接，不重走版本选择 → 直接回站内

                if (isset($_GET['reconfig'])) {
                    header('Location: index.php');
                    exit;
                }
                // 连接成功+表结构已初始化，进入下一步
                header("Location: install.php?step=3" . ($isUpgrade ? '&upgrade=1' : ''));
                exit;
            }
        } catch (Throwable $e) {
            $error = '数据库连接失败：' . $e->getMessage();
        }
    }
}

// 检测升级标记
if (isset($_GET['upgrade'])) $isUpgrade = true;

if ($step === 3 && !$isUpgrade && file_exists(__DIR__ . '/config.db.php')) {
    try {

        $content = (string)file_get_contents(__DIR__ . '/config.db.php');
        $dbConfig = [
            'host' => dbConfigConst($content, 'DB_HOST'),
            'name' => dbConfigConst($content, 'DB_NAME'),
            'user' => dbConfigConst($content, 'DB_USER'),
            'pass' => dbConfigConst($content, 'DB_PASS'),
        ];
        if ($dbConfig['host'] !== '' && $dbConfig['name'] !== '') {
            $tmpDsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
            $tmpDb = new PDO($tmpDsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $chk = $tmpDb->query("SELECT COUNT(*) FROM users WHERE role='admin'");
            $isUpgrade = (int)$chk->fetchColumn() > 0;
        }
    } catch (Throwable $e) {
        // 检测失败不阻塞，默认非升级
    }
}

// ── step3 迁移预告（升级分支）──

$installMigrateFrom = '';
$installDryRun      = null;
if ($step === 3 && empty($error)) {
    if (is_file(__DIR__ . '/.install_migrate_from')) {
        $installMigrateFrom = trim((string)@file_get_contents(__DIR__ . '/.install_migrate_from'));
    }
    if (file_exists(__DIR__ . '/config.db.php') && ($installMigrateFrom !== '' || $isUpgrade)) {
        try {
            if (!defined('INSTALL_MODE')) define('INSTALL_MODE', true);
            require_once __DIR__ . '/config.php';
            if ($installMigrateFrom === '') {
                $probe = new \App\Core\Migration\MigrationEngine(getDB());
                if ($probe->needsMigration() && $probe->getMigrationPath() !== []) {
                    $installMigrateFrom = $probe->getCurrentVersion();
                }
            }
            if ($installMigrateFrom !== '' && $installMigrateFrom !== DATABASE_VERSION) {
                $installDryRun = (new \App\Core\Migration\MigrationEngine(getDB(), $installMigrateFrom))->dryRun();
            }
        } catch (Throwable $e) {
            $installDryRun = null;
        }
    }
}

$installCssUrl = installAssetUrl('modules/system/Assets/css/install.css');
$installJsUrl  = installAssetUrl('modules/system/Assets/js/install.js');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 — LCSC 硬件资产管理</title>
<link rel="stylesheet" href="<?=$installCssUrl?>">
</head>
<body>
<div class="box">

<div class="brand">
    <div class="brand-logo">⚙️</div>
    <div class="brand-txt">
        <h1>LCSC 硬件资产管理</h1>
        <div class="sub">安装向导 · 共 3 步</div>
    </div>
</div>

<div class="steps">
    <div class="step <?=$step>=1?'done':''?> <?=$step===1?'on':''?>"><div class="dot">1</div><div class="lb">环境检测</div></div>
    <div class="step <?=$step>=2?'done':''?> <?=$step===2?'on':''?>"><div class="dot">2</div><div class="lb">数据库配置</div></div>
    <div class="step <?=$step>=3?'on':''?>"><div class="dot">3</div><div class="lb">站点设置</div></div>
</div>

<?php if($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>

<?php if($step === 1): ?>
<!-- ── Step 1: 环境检测 ── -->
<h2>环境检测</h2>
<p class="sec-desc">检测运行环境是否满足最低要求。左侧「必需项」全部通过即可继续安装。</p>

<?php if($alreadyInstalled): ?>
<div class="upgrade-notice">
    <strong>系统已安装</strong> —— 正常使用无需重装。如需重装请先删除 <code>.installed</code> 文件，或使用 <code>install.php?reset=1</code> 重置入口。
</div>
<?php endif; ?>
<?php
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$pdoOk = extension_loaded('pdo_mysql');
$mbOk = extension_loaded('mbstring');
$jsonOk = extension_loaded('json');
$sessionOk = extension_loaded('session');
$curlOk = extension_loaded('curl');           // 版本检测 / WebDAV 备份需要（非阻塞项）
$fileinfoOk = extension_loaded('fileinfo');   // 上传 MIME 校验必需（缺失时上传会被拒绝）
$zipOk = extension_loaded('zip');             // xlsx 导入/导出（PhpSpreadsheet）需要
$xmlOk = extension_loaded('simplexml') && extension_loaded('xml');
$gdOk  = extension_loaded('gd');// 缩略图生成（缺失时降级为跳原图，不阻断）
$vendorOk = is_file(__DIR__ . '/vendor/autoload.php');   // 依赖库（xlsx 解析）
$uploadOk = (bool)ini_get('file_uploads');    // 导入上传必需
$writeOk = is_writable(__DIR__);
// 运行期需写入的目录：不存在则尝试创建（全新解压包 / Docker 挂载卷场景）
$dataDirs = [];

foreach (['uploads', 'uploads/import', 'uploads/thumbs', 'backups'] as $d) {
    $p = __DIR__ . '/' . $d;
    if (!is_dir($p)) @mkdir($p, 0775, true);
    $dataDirs[$d] = is_dir($p) && is_writable($p);
}
$dirsOk = !in_array(false, $dataDirs, true);
$allOk = $phpOk && $pdoOk && $mbOk && $jsonOk && $sessionOk && $uploadOk && $fileinfoOk && $writeOk && $dirsOk;

// 检测项分组：必需项 / 可选项（缺失仅降级，不阻断安装）
$required = [
    ['PHP 版本',        $phpOk,      PHP_VERSION . ' （要求 ≥ 8.0）'],
    ['PDO MySQL 扩展',  $pdoOk,      extension_loaded('pdo') ? '已启用' : '未启用'],
    ['MBString 扩展',   $mbOk,       '多字节字符串处理'],
    ['JSON 扩展',       $jsonOk,     'API 数据格式'],
    ['Session 扩展',    $sessionOk,  '登录会话'],
    ['文件上传',        $uploadOk,   'ini file_uploads ' . (ini_get('file_uploads') ? '= On' : '= Off')],
    ['Fileinfo 扩展',   $fileinfoOk, '上传类型校验必需'],
    ['根目录可写',      $writeOk,    '写入配置与安装锁'],
    ['数据目录可写',    $dirsOk,      'uploads / uploads/import / uploads/thumbs / backups'],
];
$optional = [
    ['cURL 扩展',            $curlOk,            '版本检测 / WebDAV 备份'],
    ['Zip / XML 扩展',       $zipOk && $xmlOk,   'xlsx 表格导入导出'],
    ['依赖库 vendor/',       $vendorOk,          'xlsx 解析（composer install）'],

    ['GD 扩展',              $gdOk,              '图片缩略图（缺失时用原图）'],
];
$reqFailed = 0;
foreach ($required as $it) { if (!$it[1]) $reqFailed++; }
$optMissing = 0;
foreach ($optional as $it) { if (!$it[1]) $optMissing++; }
?>
<div class="chk-group">
    <div class="chk-group-head">
        <span>必需项</span>
        <span class="st <?= $reqFailed===0 ? 'pass':'fail' ?>"><?= $reqFailed===0 ? '全部通过' : $reqFailed . ' 项未通过' ?></span>
    </div>
    <?php foreach ($required as [$nm, $ok, $val]): ?>
    <div class="chk <?= $ok ? 'ok':'no' ?>">
        <span class="ico"><?= $ok ? '✓':'✕' ?></span>
        <span class="nm"><?= htmlspecialchars($nm) ?></span>
        <span class="val"><?= htmlspecialchars($val) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<div class="chk-group">
    <div class="chk-group-head">
        <span>可选项</span>
        <span class="st <?= $optMissing===0 ? 'pass':'' ?>" style="<?= $optMissing===0 ? '' : 'color:var(--tx-3)' ?>"><?= $optMissing===0 ? '全部就绪' : $optMissing . ' 项缺失（不影响安装）' ?></span>
    </div>
    <?php foreach ($optional as [$nm, $ok, $val]): ?>
    <div class="chk <?= $ok ? 'ok':'warn' ?>">
        <span class="ico"><?= $ok ? '✓':'!' ?></span>
        <span class="nm"><?= htmlspecialchars($nm) ?></span>
        <span class="val"><?= htmlspecialchars($val) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<?php if($allOk): ?>
<div class="err ok-bar">✓ 环境检测通过，可以继续安装</div>
<form method="post" action="install.php?step=2">
<button type="submit" class="btn btn-p">下一步：数据库配置</button>
</form>
<div class="hint">批量部署：设置环境变量 <code>LCSC_AUTOINSTALL=1</code> + 数据库/版本/管理员变量后直接请求本页，可免向导完成安装（详见 README「部署」章节）。</div>
<?php else: ?>
<div class="err">环境检测未通过，请先安装缺失的扩展或调整目录权限，然后刷新本页重试。</div>
<?php endif; ?>

<?php elseif($step === 2): ?>
<?php

$rcOn  = isset($_GET['reconfig']);
$rcPre = $_rcPrefill ?? ['host' => '127.0.0.1', 'name' => '', 'user' => ''];
?>
<!-- ── Step 2: 数据库配置 ── -->
<h2>数据库配置</h2>
<?php if ($rcOn): ?>
<div class="upgrade-notice">
    <b>修复数据库连接</b><br>
    当前配置连不上数据库（库被删 / MySQL 未启动 / 凭据被改）。此处<b>仅更新数据库连接信息</b>，
    不会删除或改动任何业务数据；表结构会自动对齐。修正后点下方按钮即回到站内。
</div>
<?php endif; ?>
<p class="sec-desc">填写 MySQL 连接信息。连接成功后系统会自动建库（如不存在）、创建或迁移表结构。</p>
<form method="post" id="dbForm">
<div class="row">
<div class="fg"><label>主机地址</label><input name="db_host" value="<?=htmlspecialchars($rcOn ? $rcPre['host'] : '127.0.0.1')?>" autocomplete="off"></div>
<div class="fg"><label>端口</label><input name="db_port" value="3306" inputmode="numeric"></div>
</div>
<div class="fg"><label>数据库名</label><input name="db_name" value="<?=htmlspecialchars($rcOn ? $rcPre['name'] : '')?>" placeholder="如 lcsc" required autocomplete="off"></div>
<div class="row">
<div class="fg"><label>用户名</label><input name="db_user" value="<?=htmlspecialchars($rcOn ? $rcPre['user'] : '')?>" placeholder="如 root" required autocomplete="off"></div>
<div class="fg"><label>密码</label><input name="db_pass" type="password" autocomplete="new-password"></div>
</div>
<button type="submit" class="btn btn-p">连接并初始化表结构</button>
<div class="hint">数据库不存在时将自动创建（字符集 <code>utf8mb4</code>）。已有数据则按「旧版升级」处理，自动补齐新字段。</div>
</form>

<?php elseif($step === 3): ?>
<!-- ── Step 3: 站点设置 ── -->
<h2>站点设置</h2>

<?php if($isUpgrade): ?>
<p class="sec-desc">检测到已有数据，本次为<b>旧版升级</b>。已有管理员账号保留不变，仅需选择版本模式。</p>
<div class="upgrade-notice">
    <b>团队版（推荐）</b>：保留全部现有成本 / 价格数据，功能不变<br>
    <b>个人版</b>：现有成本数据保留但不可见；<b>此后录入的入库单价将不再被保存</b>（成本核算相关功能不可用）
</div>
<?php else: ?>
<p class="sec-desc">创建管理员账号并选择系统版本。</p>
<?php endif; ?>

<?php if($installMigrateFrom !== ''): ?>
<div class="upgrade-notice">
    <b>⚠ 本次升级包含数据库迁移</b><br>
    检测到数据库为旧版本 <code><?=htmlspecialchars($installMigrateFrom)?></code>，
    选定版本并确认后将<b>自动执行数据库数据迁移</b>（大版本结构变化，会搬迁 / 回填数据）。
    <?php if (!empty($installDryRun['path'])): ?>
    <br>迁移路径：<?php
        $__segs = [];
        foreach ($installDryRun['path'] as $__st) { $__segs[] = $__st['from'] . ' → ' . $__st['to']; }
        echo htmlspecialchars(implode('，', $__segs));
    ?>（共 <?=count($installDryRun['path'])?> 步）
    <?php endif; ?>
    <?php if (!empty($installDryRun['scan']['parts_stock_pending_sync'])): ?>
    <br>预计需搬迁的库存行：<?=(int)$installDryRun['scan']['parts_stock_pending_sync']?> 条
    <?php endif; ?>
    <br>执行前会自动导出 <code>backups/pre_migration_*.sql</code> 备份；<b>迁移失败不会写入安装锁</b>，可修复后重试。
</div>
<?php endif; ?>

<form method="post" id="setupForm">
<?php if(!$isUpgrade): ?>
<div class="fg"><label>管理员用户名</label><input name="username" id="username" minlength="3" required placeholder="至少 3 个字符" autocomplete="username"></div>
<div class="row">
<div class="fg">
    <label>登录密码</label>
    <input name="password" id="password" type="password" minlength="8" required placeholder="至少 8 位，含大小写/数字/特殊字符 3 种" autocomplete="new-password">
    <div class="pw-meter" id="pwMeter"><i></i><i></i><i></i><i></i></div>
    <div class="pw-tip" id="pwTip">至少 8 位</div>
</div>
<div class="fg">
    <label>确认密码</label>
    <input name="confirm" id="confirm" type="password" minlength="8" required placeholder="再次输入密码" autocomplete="new-password">
    <div class="pw-tip" id="confirmTip"></div>
</div>
</div>
<?php endif; ?>

<div class="cds">
<div class="cd" data-edition="personal" tabindex="0" onclick="pickEdition('personal',this)" id="cdPersonal">
    <div class="ic">👤</div>
    <div class="nm">个人版</div>
    <div class="ds">轻量单页工具<br>一个人用</div>
    <div class="feat"><b>适合</b>个人工程师 / 爱好者管理自己的元器件库<br><b>含</b>物料 · BOM · 库存 · 扫码 · 导入导出 · 标签打印<br><b>不含</b>成本核算 · 多人协作 · 操作留痕</div>
</div>
<div class="cd sel" data-edition="studio" tabindex="0" onclick="pickEdition('studio',this)" id="cdStudio">
    <div class="ic">👥</div>
    <div class="nm">团队版</div>
    <span class="badge-rec">推荐</span>
    <div class="ds">高信息密度<br>2~5 人共用</div>
    <div class="feat"><b>适合</b>小团队共同管理元器件与项目 BOM<br><b>含</b>个人版全部功能，另加成本核算 · 子用户权限 · 操作溯源 · 资产统计 · 库位树<br><b>不含</b>生产工单 · 销售 · 财务 · 多仓库</div>
</div>
</div>
<p class="sec-desc" style="margin-top:14px">
    本系统是<b>硬件研发场景的元器件资产与成本管理工具</b>，不是 ERP / 生产制造 / 进销存 / 财务系统 —— 无工单排产、无销售订单、无应收应付、无总账。
    两个版本<b>共用同一套数据库</b>，安装后不可切换（唯一途径是重装）。
</p>
<input type="hidden" name="edition" id="editionInput" value="studio">

<button type="submit" class="btn btn-p"><?=$isUpgrade ? '确认并完成升级' : '完成安装'?></button>
<div class="hint">⚠ 版本选定后<b>永久锁定</b>，日常使用无法切换。需更改时请重新执行安装向导。</div>
</form>
<?php endif; ?>

<div class="foot-note">LCSC 硬件资产管理系统 v<?= APP_VERSION ?></div>
</div>
<script src="<?=$installJsUrl?>"></script>
</body>
</html>
<?php


function showResetPage(string $error = ''): void {
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>重新初始化 — LCSC</title>
<link rel="stylesheet" href="<?=installAssetUrl('modules/system/Assets/css/install.css')?>">
</head><body><div class="box">
<div class="brand">
    <div class="brand-logo">⚠️</div>
    <div class="brand-txt">
        <h1>重新初始化</h1>
        <div class="sub">重置安装锁 · 重新选择版本</div>
    </div>
</div>
<div class="upgrade-notice">
    此操作将重置安装锁，重新进入安装向导选择版本。数据库和业务数据<b>不会被删除</b>，仅覆盖版本配置。<br><br>
    需同时提供：<b>文本 RESET</b> + <b>当前管理员密码</b>（与备份恢复同级确认）。
</div>
<?php if($error): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post" id="resetForm">
<div class="fg"><label>确认文本</label><input name="confirm_code" id="confirm_code" placeholder="RESET" autocomplete="off"></div>
<div class="fg"><label>当前管理员密码</label><input name="admin_password" type="password" autocomplete="current-password"></div>
<button type="submit" class="btn btn-danger">确认重新初始化</button></form>
<div class="foot-note">确认后进入安装向导第 3 步选择版本</div>
</div>
<script src="<?=installAssetUrl('modules/system/Assets/js/install.js')?>"></script>
</body></html>
<?php
}

function showMigrationResultPage(
    ?\App\Core\Migration\MigrationEngine $engine = null,
    ?\App\Core\Migration\MigrationResult $result = null,
    string $errorText = ''
): void {
    $ok       = ($errorText === '' && $result !== null && !$result->isFailed());
    $steps    = ($result !== null) ? $result->getSteps() : [];
    $messages = ($result !== null) ? $result->getMessages() : [];
    $checks   = ($result !== null && $result->getValidation() !== null) ? $result->getValidation()->getChecks() : [];
    $backup   = ($result !== null) ? $result->getBackupFile() : null;
?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>数据库迁移 — LCSC</title>
<link rel="stylesheet" href="<?=installAssetUrl('modules/system/Assets/css/install.css')?>">
</head><body><div class="box">
<div class="brand">
    <div class="brand-logo"><?=$ok ? '✅' : '⚠️'?></div>
    <div class="brand-txt">
        <h1>数据库迁移<?=$ok ? '完成' : '失败'?></h1>
        <div class="sub"><?=$ok ? '升级已完成' : '升级未完成，安装锁未写入'?></div>
    </div>
</div>

<?php if ($ok): ?>
<div class="upgrade-notice">
    数据库已从旧版本迁移到当前版本，业务数据已按新结构搬迁 / 回填。<br>
    <?php if ($backup): ?>迁移前备份：<code><?=htmlspecialchars(basename($backup))?></code>（位于 <code>backups/</code>）<br><?php endif; ?>
    安装锁已写入，点击下方按钮进入系统。
</div>
<?php else: ?>
<div class="err"><?=htmlspecialchars($errorText !== '' ? $errorText : '数据库迁移失败')?></div>
<div class="upgrade-notice">
    <b>请勿直接使用系统</b> —— 迁移未完成，安装锁<b>未写入</b>。可修复问题后返回上一步重试。<br>
    <?php if ($backup): ?>迁移前备份已保留：<code><?=htmlspecialchars(basename($backup))?></code>（位于 <code>backups/</code>），必要时可据此还原。<br><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($steps): ?>
<h2>迁移步骤</h2>
<table class="mig-tbl">
    <thead><tr><th>步骤</th><th>状态</th><th>迁移记录数</th></tr></thead>
    <tbody>
    <?php foreach ($steps as $st): ?>
        <tr>
            <td><?=htmlspecialchars($st->getFrom())?> → <?=htmlspecialchars($st->getTo())?></td>
            <td><?=htmlspecialchars($st->getStatus())?></td>
            <td><?=(int)$st->getRecordsMigrated()?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($checks): ?>
<h2>一致性校验</h2>
<table class="mig-tbl">
    <thead><tr><th>项目</th><th>实际</th><th>期望</th><th>状态</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
        <tr>
            <td><?=htmlspecialchars((string)$c['name'])?></td>
            <td><?=htmlspecialchars((string)$c['actual'])?></td>
            <td><?=htmlspecialchars((string)$c['expected'])?></td>
            <td><?=htmlspecialchars((string)$c['status'])?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!$ok && $messages): ?>
<h2>失败详情</h2>
<ul class="mig-msgs">
    <?php foreach ($messages as $m): ?>
    <li>[<?=htmlspecialchars((string)($m['level'] ?? ''))?>] <?=htmlspecialchars((string)($m['message'] ?? ''))?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($engine !== null && $result !== null): ?>
<details>
    <summary class="hint">查看完整迁移报告（纯文本，可复制）</summary>
    <pre class="mig-report"><?=htmlspecialchars($engine->generateReport($result))?></pre>
</details>
<?php endif; ?>

<?php if ($engine !== null): ?>
<p class="sec-desc" style="margin-top:14px">数据库版本：<?=htmlspecialchars($engine->getCurrentVersion())?> → <?=htmlspecialchars($engine->getTargetVersionString())?></p>
<?php endif; ?>

<?php if ($ok): ?>
<a href="index.php" class="btn btn-p">进入系统</a>
<?php else: ?>
<a href="install.php?step=3&amp;upgrade=1" class="btn btn-p">返回上一步重试</a>
<?php endif; ?>

<div class="foot-note">LCSC 硬件资产管理系统 v<?= APP_VERSION ?></div>
</div>
</body></html>
<?php
}

/** 环境变量驱动的免向导安装 */
function runAutoInstall(string $lockFile): void
{
    $env = static fn(string $k, string $d = ''): string => trim((string)(getenv($k) ?: $d));
    $out = static function (string $msg): void { fwrite(STDOUT, "[autoinstall] {$msg}\n"); };
    $die = static function (string $msg) use ($out): void { $out('失败：' . $msg); exit(1); };

    $dbHost  = $env('LCSC_DB_HOST', '127.0.0.1');
    $dbPort  = max(1, min(65535, (int)$env('LCSC_DB_PORT', '3306')));
    $dbName  = $env('LCSC_DB_NAME', 'lcsc');
    $dbUser  = $env('LCSC_DB_USER', 'root');
    $dbPass  = (string)(getenv('LCSC_DB_PASS') ?: '');
    $edition = $env('LCSC_EDITION', 'studio');
    if (!in_array($edition, ['personal', 'studio'], true)) $edition = 'studio';
    if ($dbName === '') $die('LCSC_DB_NAME 为空');

    // 1) 连接并建库
    try {
        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
    } catch (Throwable $e) {
        $die('数据库连接失败：' . $e->getMessage());
    }

    // 2) 写连接配置 + 建表/迁移
    $configContent = "<?php\n"
        . "// 数据库配置（自动安装生成，请勿手动修改）\n"
        . "// 生成时间：" . date('Y-m-d H:i:s') . "\n"
        . "define('DB_HOST',    " . var_export($dbHost, true) . ");\n"
        . "define('DB_NAME',    " . var_export($dbName, true) . ");\n"
        . "define('DB_USER',    " . var_export($dbUser, true) . ");\n"
        . "define('DB_PASS',    " . var_export($dbPass, true) . ");\n"
        . "define('DB_CHARSET', 'utf8mb4');\n";
    if (file_put_contents(__DIR__ . '/config.db.php', $configContent) === false) {
        $die('无法写入 config.db.php（目录不可写）');
    }

    try {
        if (!defined('INSTALL_MODE')) define('INSTALL_MODE', true);
        require_once __DIR__ . '/config.php';
    } catch (Throwable $e) {
        $die('加载配置失败：' . $e->getMessage());
    }

    $migrateFrom = '';
    try {
        $probe = new \App\Core\Migration\MigrationEngine(getDB());
        if ($probe->needsMigration() && $probe->getMigrationPath() !== []) {
            $migrateFrom = $probe->getCurrentVersion();
        }
    } catch (Throwable $e) {
        $migrateFrom = '';
    }

    try {
        initDB(true);
    } catch (Throwable $e) {
        $die('初始化表结构失败：' . $e->getMessage());
    }

    if ($migrateFrom !== '' && $migrateFrom !== DATABASE_VERSION) {
        try {
            $migResult = (new \App\Core\Migration\MigrationEngine(getDB(), $migrateFrom))->execute();
        } catch (Throwable $e) {
            $die('数据库迁移执行异常：' . $e->getMessage());
        }
        if ($migResult->isFailed()) {
            $detail = [];
            foreach ($migResult->getMessages() as $m) { $detail[] = (string)($m['message'] ?? ''); }
            $die('数据库迁移失败（已保留迁移前备份，未写入安装锁）：' . implode('；', $detail));
        }
        $out('数据库迁移完成：' . $migrateFrom . ' → ' . DATABASE_VERSION . '（' . count($migResult->getSteps()) . ' 步）');
    }

    // 3) 管理员（已有则保留）+ 版本与功能开关
    try {
        $db = getDB();
        $hasAdmin = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
        $createdAdmin = false;
        if (!$hasAdmin) {
            $adminUser = $env('LCSC_ADMIN_USER', 'admin');
            $adminPass = (string)(getenv('LCSC_ADMIN_PASS') ?: '');
            if ($adminPass === '') $die('新装需提供 LCSC_ADMIN_PASS');
            if (!isStrongPassword($adminPass)) $die('LCSC_ADMIN_PASS 强度不足：至少 8 位且包含大小写/数字/特殊字符中的 3 类');
            $db->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, 'admin', '{}')")
               ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);
            $createdAdmin = true;
        }

        setSetting('edition', $edition);

        foreach (featureDefaults() as $fk => $fv) {
            setSetting($fk, $fv);
        }
        traceLog(1, 'system_install', 'setting', 0, "自动安装 版本:{$edition}" . ($createdAdmin ? '（新建管理员）' : '（保留既有管理员）'));
        file_put_contents($lockFile, date('Y-m-d H:i:s') . " autoinstall edition={$edition}");
    } catch (Throwable $e) {
        $die('写入站点配置失败：' . $e->getMessage());
    }

    $out("安装完成：edition={$edition} db={$dbName}@{$dbHost}:{$dbPort}" . ($createdAdmin ? " admin={$env('LCSC_ADMIN_USER', 'admin')}" : '（保留既有管理员）'));
    exit(0);
}
