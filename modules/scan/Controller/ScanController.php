<?php
declare(strict_types=1);

namespace Modules\Scan\Controller;

use Modules\Scan\Service\ScanService;
use PDO;

/** 扫码页面控制器 */
final class ScanController
{
    /** 组装扫码页面视图数据 */
    public static function prepareViewData(PDO $db, int $uid): array
    {
        $svc = new ScanService($db);

        $scanResult = $_SESSION['scan_result'] ?? null;
        if ($scanResult !== null) {
            unset($_SESSION['scan_result']);
        }
        $scanError = $_SESSION['scan_error'] ?? null;
        if ($scanError !== null) {
            unset($_SESSION['scan_error']);
        }

        $today = $svc->getTodayStats($uid);

        return [
            'flash'       => $_GET['flash'] ?? null,
            'scanResult'  => $scanResult,
            'scanError'   => $scanError,
            'todayIn'     => $today['in'],
            'todayOut'    => $today['out'],
            'todayInQty'  => $today['inQty'],
            'todayOutQty' => $today['outQty'],
            'recentScans' => $svc->getRecentScans($uid),
            'platforms'   => $svc->getPlatforms(),
        ];
    }
}
