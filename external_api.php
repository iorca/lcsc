<?php
declare(strict_types=1);
/** 外部 API 中转入口（第三方对接专用） */
require_once __DIR__ . '/config.php';
initDB();
apiBootstrap();

// ── 强制 API Key 鉴权（此入口不接受 Session） ──
$apiKey = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
if ($apiKey === '') {
    \App\Core\Response::error('缺少 X-API-Key 请求头', 401);
}

$stmt = getDB()->prepare("SELECT ak.*, u.id AS uid, u.role, u.parent_id FROM api_keys ak JOIN users u ON u.id=ak.user_id WHERE ak.api_key=? LIMIT 1");
$stmt->execute([$apiKey]);
$keyRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$keyRow) {
    \App\Core\Response::error('API Key 无效', 401);
}

if (!empty($keyRow['expires_at']) && strtotime($keyRow['expires_at']) <= time()) {
    \App\Core\Response::error('API Key 已过期（到期 ' . $keyRow['expires_at'] . '），请在后台续期或重新生成', 401);
}

// ── 转发至 router.php 统一路由（携带 X-API-Key） ──

require __DIR__ . '/app/api/router.php';
