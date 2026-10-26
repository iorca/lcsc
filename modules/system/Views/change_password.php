<?php
declare(strict_types=1);
/** 修改密码页面（薄壳） */
use Modules\System\Controller\SystemController;

require_once __DIR__ . '/../../../config.php';
startSession();
$user = currentUser();
if (!$user) { header('Location: login.php'); exit; }

// ── 访问限制：独立改密页两版一致 ──

$db = getDB();

// ── 通过 Controller 获取视图数据 + 处理 POST ──
try {
    $result   = SystemController::handleChangePassword($db, $user);
    $error    = $result['error'] ?? '';
    $success  = $result['success'] ?? '';
    $viewData = SystemController::prepareChangePasswordData();
    extract($viewData);
} catch (\Throwable $e) {
    error_log('change_password view: ' . $e->getMessage());
    $error = $success = '';
}

// ── AJAX 弹窗提交：返回统一 JSON 格式，不渲染 HTML ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['code' => $success !== '' ? 0 : 1, 'msg' => $success !== '' ? $success : ($error ?: '操作失败')], JSON_UNESCAPED_UNICODE);
    exit;
}

sendSecurityHeaders();
$siteTitle = getSetting('site_title','LCSC 硬件资产管理');
$themeDefault = getSetting('theme_default','light');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>修改密码 — <?=h($siteTitle)?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="modules/system/Assets/css/change_password.css?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/css/change_password.css')?>">
<script src="modules/system/Assets/js/change_password_frame.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/change_password_frame.js')?>"></script>
</head>
<body>
<div class="box">
<h2>修改密码</h2>
<?php if($user['must_change_pw']): ?><p class="sub">首次登录请修改默认密码</p><?php endif; ?>
<?php if($error) echo '<div class="error">'.h($error).'</div>'; ?>
<?php if($success) echo '<div class="success">'.h($success).'<br><span id="countdown" class="cd-countdown">3 秒后自动跳转库存页...</span></div>'; ?>
<form method="post">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-group"><label>当前密码</label><input name="old_password" type="password" required></div>
    <div class="form-group"><label>新密码（至少8位，含大小写/数字/特殊字符3种）</label><input name="new_password" type="password" required></div>
    <div class="form-group"><label>确认新密码</label><input name="confirm" type="password" required></div>
    <button type="submit" class="btn">更新密码</button>
</form>
<?php if(!$user['must_change_pw']): ?>
<div class="back"><a href="index.php">← 返回首页</a></div>
<?php endif; ?>
</div>
<div class="cd-foot">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">LCSC 硬件资产管理 v<?= APP_VERSION ?></a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">xiaoxu798</a>
</div>
<?php if($success): ?>
<script src="modules/system/Assets/js/change_password_countdown.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/change_password_countdown.js')?>"></script>
<?php endif; ?>
</body></html>
