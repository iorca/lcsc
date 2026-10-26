<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
startSession();

// 已登录用户直接跳转首页
if (currentUser()) { header('Location: ' . siteUrl('index.php')); exit; }

$error = '';
$sessionTimeout = ($_GET['reason'] ?? '') === 'timeout';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证（防止空串绕过）
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        $error = '请求无效，请刷新页面重试';
    } else {
        $username = safeStr($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // 防暴力破解限速
        $throttleMsg = checkLoginThrottle($username);
        if ($throttleMsg !== null) {
            $error = $throttleMsg;
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username=?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            // 防止用户名枚举时序攻击：用户不存在时也执行 password_verify
            $dummyHash = '$2y$10$' . str_repeat('a', 53);
            $hashToCheck = $user ? $user['password_hash'] : $dummyHash;
            $pwOk = password_verify($password, $hashToCheck);
            if ($user && $pwOk) {
                logLoginAttempt($username, true);

                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(16)); // 轮换 CSRF 令牌
                $_SESSION['user'] = [
                    'id'             => $user['id'],
                    'username'       => $user['username'],
                    'role'           => $user['role'],
                    'parent_id'      => $user['parent_id'] ?? null,
                    'permissions'    => $user['permissions'] ?? null,
                    'must_change_pw' => $user['must_change_pw'],
                ];

                // 「记住我」：持久化 session cookie + 标记 cookie
                $rememberMe = !empty($_POST['remember_me']);
                if ($rememberMe) {
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
                    $expiry = time() + 30 * 86400; // 30天
                    // 持久化 PHPSESSID cookie
                    setcookie(session_name(), session_id(), [
                        'expires' => $expiry,
                        'path' => '/',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                    // 标记 cookie（startSession 检测此 cookie 延长超时）
                    setcookie('remember_me', '1', [
                        'expires' => $expiry,
                        'path' => '/',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                }

                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                traceLog((int)$user['id'], 'login', 'user', (int)$user['id'], '用户登录');
                $_SESSION['login_flash'] = '欢迎回来，' . $user['username'] . '！';

                $_SESSION['fresh_login'] = true;

                if ($user['must_change_pw']) { header('Location: ' . siteUrl('index.php?v=change_password')); exit; }
                header('Location: ' . siteUrl('index.php?flash=login_ok')); exit;
            } else {
                logLoginAttempt($username, false);
                $error = '用户名或密码错误';
            }
        }
    }
}

sendSecurityHeaders();
// 防止浏览器缓存登录页：确保每次加载都获取新 CSRF token
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$siteTitle = getSetting('site_title', 'LCSC 硬件资产管理');
$siteLogo  = getSetting('site_logo', '');
$themeDefault = getSetting('theme_default', 'light');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 — <?= h($siteTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="modules/system/Assets/css/login.css?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/modules/system/Assets/css/login.css')?>">
</head>
<body>
<script>
// 如果在 iframe 内，顶层窗口跳转到登录页（防止 session 超时后 iframe 嵌套登录页）
if (window.self !== window.top) {
    window.top.location.href = location.href;
}
</script>
<div class="box">
    <div class="logo">
        <?php if ($siteLogo): ?>
        <img src="<?= h($siteLogo) ?>" style="height:40px;margin-bottom:8px">
        <?php endif; ?>
        <div class="logo-title"><?= h($siteTitle) ?></div>
        <div class="logo-sub">Hardware Asset Management</div>
    </div>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-group"><label>用户名</label><input name="username" required autofocus autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>"></div>
        <div class="form-group"><label>密码</label>
            <div class="pw-wrap">
                <input name="password" type="password" id="loginPw" required autocomplete="current-password" placeholder="请输入密码">
                <button type="button" class="pw-toggle" id="loginPwToggle" onclick="togglePw('loginPw', this)" title="显示密码" aria-label="显示/隐藏密码">👁</button>
            </div>
        </div>
        <div class="form-group" style="display:flex;align-items:center;gap:6px;margin-top:-4px">
            <input type="checkbox" name="remember_me" value="1" id="rememberMe" style="accent-color:var(--accent);width:auto">
            <label for="rememberMe" style="margin:0;cursor:pointer;font-size:12px;color:var(--text2)">记住我（30天内免登录）</label>
        </div>
        <button type="submit" class="btn">登录</button>
    </form>
</div>
<div style="position:fixed;bottom:0;left:0;right:0;text-align:center;padding:8px 12px;font-size:11px;color:var(--text2);background:var(--bg);">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">LCSC 硬件资产管理 v<?= APP_VERSION ?></a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">xiaoxu798</a>
</div>

<?php if ($sessionTimeout): ?>
<!-- 会话超时弹窗（必须手动关闭） -->
<div id="timeoutOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">
<div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;max-width:360px;width:100%;text-align:center;">
    <div style="font-size:36px;margin-bottom:12px">⏱️</div>
    <h3 style="font-size:16px;margin-bottom:10px;color:var(--text)">会话已超时</h3>
    <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px">您的登录会话因长时间无操作已过期，请重新登录以继续使用。</p>
    <button type="button" onclick="document.getElementById('timeoutOverlay').remove()" style="padding:9px 28px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;">知道了</button>
</div>
</div>
<?php endif; ?>

<script>
// 密码可见切换（明暗主题下均清晰）
function togglePw(id, btn) {
    var i = document.getElementById(id);
    if (!i) return;
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁';
    btn.title = show ? '隐藏密码' : '显示密码';
    btn.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
}
(function(){
    var url = new URL(window.location.href);
    if (url.searchParams.has('reason') || url.searchParams.has('tip')) {
        url.searchParams.delete('reason');
        url.searchParams.delete('tip');
        history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }
})();
</script>

</body></html>
