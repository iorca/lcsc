<?php
declare(strict_types=1);
/** 后台管理页 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../modules/system/Service/TraceService.php';
$user = requireAdmin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
ensureUserPlatforms($uid);
ensureImportTemplates((int)$uid);

use Modules\Admin\Controller\AdminController;

// POST 处理（仅 backup 下载 / restore 上传）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminController::handlePost($db, $uid, $user);
}

// 数据准备
try {
    $viewData = AdminController::prepareViewData($db, $uid, $dataUid, $user);
} catch (\Throwable $e) {
    error_log('Admin prepareViewData: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $viewData = [
        'flash' => '', 'flashType' => 'ok', 'is_primary_admin' => \isPrimaryAdmin(),
        'settings' => [], 'platforms' => [], 'users' => [], 'subUsers' => [],
        'dbAlignment' => ['code_version' => '', 'db_version' => '—', 'state' => 'unknown'],
        'traceData' => ['logs'=>[],'total'=>0,'page'=>1,'total_pages'=>1],
        'traceDateFrom' => '', 'traceDateTo' => '', 'traceAction' => '', 'traceTargetType' => '', 'traceOperator' => '',
        'traceActionOptions' => [], 'traceTypeOptions' => [],
        'monitorData' => null, 'permLabels' => [],
        // 出站自检取不到时按「不可用」渲染：页面已在错误分支里，
        // 显示「环境不可用 + 排查方向」比显示空白更能指向问题
        'outboundEnv' => ['ok' => false, 'reason' => '后台数据准备失败，出站环境自检未执行'],
        'logPerPage' => 20, 'edition' => 'personal', 'edition_locked' => false,
    ];
}
extract($viewData);

// 权限标签和解析函数（子用户管理使用，view 文件中需要）
if (!function_exists('parsePerms')) {
    function parsePerms(?string $json): array {
        if (!$json) return [];
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }
}

$pageTitle  = '后台管理';
$activePage = 'admin';

\App\Core\AssetManager::addCssList([
    'modules/admin/Assets/css/admin.css',
    'modules/admin/Assets/css/settings.css',
    'modules/admin/Assets/css/platforms.css',
    'modules/admin/Assets/css/users.css',
    'modules/admin/Assets/css/monitor.css',
    'modules/admin/Assets/css/backup.css',
    'modules/admin/Assets/css/trace.css',
]);

$_adminTabs = ['settings', 'platforms', 'users', 'monitor', 'backup', 'trace'];
if (!hasFeature('multi_user')) {
    $_adminTabs = array_values(array_filter($_adminTabs, fn($t) => $t !== 'users'));
    // 系统监控同理：面板主体是在线用户 / 登录用户数 / 活跃用户榜，单人场景无意义
    $_adminTabs = array_values(array_filter($_adminTabs, fn($t) => $t !== 'monitor'));
}
if (!hasFeature('feature_log_view')) {
    $_adminTabs = array_values(array_filter($_adminTabs, fn($t) => $t !== 'trace'));
}
$activeTab = in_array($_GET['tab'] ?? '', $_adminTabs, true) ? $_GET['tab'] : 'settings';

// ── 片段渲染（无感刷新）：溯源日志筛选走 AJAX，只回传面板 HTML，不输出整页框架 ──

if (($_GET['fragment'] ?? '') === '1' && $activeTab === 'trace') {
    $isTraceFragment = true;
    require __DIR__ . '/trace.php';
    exit;
}

require __DIR__ . '/../../../ui/layouts/head.php';
?>

<div class="main">
<?php
// Flash messages
if ($flash !== '') {
    if ($flash === 'ok_save') {
        echo '<div class="flash ok">✓ 操作成功</div>';
    } elseif ($flash === 'logo_err') {
        echo '<div class="flash err">✗ Logo 上传失败（仅支持图片，最大 2MB）</div>';
    } elseif ($flash === 'pw_reset') {

        $resetPw = $_SESSION['reset_pw_flash'] ?? '';
        unset($_SESSION['reset_pw_flash']);
        if ($resetPw !== '') {
            echo '<div class="flash warn">⚠ 密码已重置为 <strong class="reset-pw-value">' . h($resetPw) . '</strong>，用户下次登录需修改密码</div>';
        } else {
            echo '<div class="flash warn">⚠ 密码已重置，请通知用户查看（密码仅显示一次）</div>';
        }
    } elseif ($flash === 'backup_err') {
        echo '<div class="flash err">✗ 备份生成失败，请检查服务器日志</div>';
    } elseif ($flash === 'restore_no_file') {
        echo '<div class="flash err">✗ 请选择备份文件</div>';
    } elseif ($flash === 'restore_bad_ext') {
        echo '<div class="flash err">✗ 仅支持 .sql 文件</div>';
    } elseif ($flash === 'restore_empty') {
        echo '<div class="flash err">✗ 文件为空或无法读取</div>';
    } elseif ($flash === 'restore_ok') {
        echo '<div class="flash ok">✓ 备份恢复成功</div>';
    } elseif ($flash === 'plat_empty') {
        echo '<div class="flash err">✗ 平台代码和名称不能为空</div>';
    } elseif ($flash === 'plat_dup') {
        echo '<div class="flash err">✗ 平台代码重复，请更换</div>';
    } elseif ($flash === 'plat_url_invalid') {
        echo '<div class="flash err">✗ URL 模板必须以 http:// 或 https:// 开头</div>';
    } elseif ($flash === 'plat_has_parts') {
        echo '<div class="flash err">✗ 该平台下还有元件，无法删除</div>';
    } elseif ($flash === 'plat_last') {
        echo '<div class="flash err">✗ 至少保留一个平台，无法删除最后一个平台</div>';
    } elseif ($flash === 'plat_del_failed') {
        echo '<div class="flash err">✗ 平台删除失败，请检查日志后重试</div>';
    } elseif ($flash === 'sub_user_err') {
        echo '<div class="flash err">✗ 子用户名至少3位，密码至少8位且含大小写/数字/特殊字符3种</div>';
    } elseif ($flash === 'sub_user_dup') {
        echo '<div class="flash err">✗ 子用户名已存在</div>';
    } elseif ($flash === 'restore_err_generic') {
        echo '<div class="flash err">✗ 恢复失败，请检查备份文件格式是否正确</div>';
    } elseif ($flashType === 'err') {
        echo '<div class="flash err">✗ 操作失败</div>';
    } elseif ($flashType === 'warn') {
        echo '<div class="flash warn">⚠ ' . h($flash) . '</div>';
    }
}
?>

<input type="hidden" id="activeTab" value="<?=h($activeTab)?>">

<?php if(isPrimaryAdmin()): ?>
<!-- 网站设置 -->
<div class="admin-panel <?= $activeTab === 'settings' ? 'active' : '' ?>" id="tab-settings">
<?php require __DIR__ . '/settings.php'; ?>
</div>
<?php endif; ?>

<!-- 平台管理 -->
<div class="admin-panel <?= $activeTab === 'platforms' ? 'active' : '' ?>" id="tab-platforms">
<?php require __DIR__ . '/platforms.php'; ?>
</div>

<?php if(isPrimaryAdmin() && hasFeature('multi_user')): ?>
<!-- 系统监控（团队版专属：面板主体是在线用户 / 登录用户数 / 活跃用户榜） -->
<div class="admin-panel <?= $activeTab === 'monitor' ? 'active' : '' ?>" id="tab-monitor">
<?php require __DIR__ . '/monitor.php'; ?>
</div>
<?php endif; ?>

<?php if(isPrimaryAdmin() && hasFeature('multi_user')): ?>
<!-- 用户管理（子用户：真正的版本分叉） -->
<div class="admin-panel <?= $activeTab === 'users' ? 'active' : '' ?>" id="tab-users">
<?php require __DIR__ . '/users.php'; ?>
</div>
<?php endif; ?>


<div class="admin-panel <?= $activeTab === 'backup' ? 'active' : '' ?>" id="tab-backup">
<?php require __DIR__ . '/backup.php'; ?>
</div>

<?php if(hasFeature('feature_log_view')): ?>
<!-- 溯源日志（受 feature_log_view 开关控制，双版本均可用） -->
<div class="admin-panel <?= $activeTab === 'trace' ? 'active' : '' ?>" id="tab-trace">
<?php require __DIR__ . '/trace.php'; ?>
</div>
<?php endif; ?>

<!-- 子用户权限编辑弹窗（全局弹窗，不在任何面板内） -->
<div class="overlay" id="subPermModal">
<div class="modal">
    <div class="modal-header">
        <h3>⚙ 编辑权限 - <span id="subPermName"></span></h3>
        <button class="btn-close" onclick="closeSubPermModal()">&times;</button>
    </div>
    <div class="modal-body">
        <form method="post" action="api.php" id="subPermForm">
            <input type="hidden" name="action" value="admin.update_sub_user">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="target_id" id="subPermUid">
            <div class="perm-list">
                <?php foreach ($permLabels as $pk => $pl): ?>
                <label class="perm-item">
                    <input type="checkbox" name="sub_perms[]" value="<?=$pk?>" id="sp_<?=$pk?>">
                    <span><?=$pl?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-full">保存权限</button>
        </form>
    </div>
</div>
</div>
</div><!-- /main -->

<!-- 版本更新弹窗 -->
<div class="overlay" id="versionModal">
<div class="modal modal-lg">
    <h3 id="vModalTitle">🔄 有新版本更新</h3>
    <div class="ver-meta">
        当前版本 <span class="mono" id="vCur">—</span>
        → 最新版本 <span class="mono ver-new" id="vNew">—</span>
        <span class="ver-source" id="vSource"></span>
    </div>

    <?php // 二次确认与进度都**内联在本弹窗内**（禁止再叠一层弹窗）：
          // 放在更新日志上方，保证长日志滚动时确认按钮也不会被吸底 footer 遮住。?>
    <div class="ver-confirm" id="vUpgradeConfirm" hidden>
        <div class="ver-confirm-text">
            确认升级到 <b id="vUpgradeVer">—</b>？流程：下载安装包 → SHA-256 校验 → 整库备份 → 覆盖程序文件；
            失败自动回滚，数据库凭据与上传附件不会被覆盖。
        </div>
        <div class="ver-confirm-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="vUpgradeCancel">取消</button>
            <button type="button" class="btn btn-warning btn-sm" id="vUpgradeStart">确认升级</button>
        </div>
    </div>

    <div class="ver-progress" id="vUpgradeProgress" hidden aria-live="polite">
        <div class="ver-progress-head">
            <span id="vUpgradeStage">准备升级…</span>
            <span class="mono" id="vUpgradePct">0%</span>
        </div>
        <div class="ver-progress-track"><div class="ver-progress-bar" id="vUpgradeBar"></div></div>
        <div class="ver-progress-hint">进度按阶段估算，最终以服务端返回为准；升级期间请勿关闭页面、刷新或重复提交。</div>
    </div>

    <div class="sec-title ver-log-title">更新日志</div>
    <div id="vChangelogContent" class="ver-log">加载中...</div>

    <?php
          ?>
    <div class="ver-online">
        <div class="ver-online-hint">
            在线升级会从 <b>代码内白名单</b> 的发布源下载安装包，校验 SHA-256 一致后覆盖程序文件
            （<b>不会</b>覆盖数据库凭据、安装锁、密钥与用户上传数据）；执行前自动做一次整库备份，
            替换失败会自动回滚程序文件（回滚副本留在 <code>updates/rollback_*</code>）。
            若服务器开启了 OPcache 且未开启文件校验，升级后可能需重启 PHP-FPM 才生效。
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="vUpgradeDismiss" onclick="closeOverlay('versionModal')">取消</button>
        <a id="vReleaseBtn" href="#" target="_blank" rel="noopener" class="btn btn-primary">跳转发布页</a>
        <button type="button" class="btn btn-warning" id="vUpgradeBtn">⬇ 下载并升级</button>
    </div>
</div>
</div>

<script>
window.AdminPageConfig = <?= json_encode([
    'isPrimaryAdmin' => isPrimaryAdmin(),
    'edition'        => getEdition(),
    'csrf'           => csrf(),
    'version'        => APP_VERSION,

], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php
// 后台管理页脚本：按功能域拆为 6 个文件。
// **加载顺序不是行号顺序** —— admin.js 有 4 处「页面加载即执行」的 IIFE，它们调用其它
// 文件的函数，故顺序由前置依赖决定：

$adminJsBase = 'modules/admin/Assets/js/';
$adminJsOrder = [
    'admin-util',      // escAdmin / fmtSizeAdmin（须最先）
    'admin-backup',    // 一键备份 / WebDAV / 恢复（须早于 platform）
    'admin-platform',  // 平台与子用户权限 / 版本检测 / 成本重建
    'admin-template',  // 平台导入模板
    'admin-apikey',    // API Key 管理
    'admin-schedule',  // 定时备份 / 一键升级 / 清理过期备份（须最后）
];
foreach ($adminJsOrder as $aj) {
    $ajPath = __DIR__ . '/../Assets/js/' . $aj . '.js';
    echo '<script src="' . h($adminJsBase . $aj . '.js') . '?v='
        . h(APP_VERSION . '.' . filemtime($ajPath)) . '" defer></script>' . "\n";
}
?>
<script src="modules/system/Assets/js/log.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../../system/Assets/js/log.js')?>" defer></script>

</body></html>
