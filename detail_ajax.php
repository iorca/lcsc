<?php
declare(strict_types=1);

// 注意：这里必须用 ?? 兜底。若 $_GET['version_check'] 未传，PHP 8 会抛
// "Undefined array key" Warning，而 Warning 会先输出到 body 前面，
// 导致后面的 JSON 无法被 JSON.parse 解析（前端报「服务器返回非 JSON 数据」）。
$_GET['action'] = 'detail.' . (!empty($_GET['version_check']) ? 'version_check' : 'detail');
require __DIR__ . '/api.php';
