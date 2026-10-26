<?php
declare(strict_types=1);
/** modules/system/Views/log.php — 操作记录页跳转薄壳 */

$dest = 'index.php?v=stock&view=log';
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '')          $dest .= '&q=' . urlencode($q);
if (!empty($_GET['page']))     $dest .= '&page=' . max(1, (int)$_GET['page']);
if (!empty($_GET['per_page'])) $dest .= '&per_page=' . (int)$_GET['per_page'];
if (!empty($_GET['flash']))    $dest .= '&flash=' . urlencode((string)$_GET['flash']);

header('Location: ' . $dest, true, 302);
exit;
