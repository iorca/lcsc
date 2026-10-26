<?php
declare(strict_types=1);

$_GET['action'] = 'detail.' . ($_GET['version_check'] ? 'version_check' : 'detail');
require __DIR__ . '/api.php';
