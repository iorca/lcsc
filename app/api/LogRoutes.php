<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

require_once __DIR__ . '/../../modules/stock/Service/StockService.php';

require_once __DIR__ . '/../../modules/system/Service/TraceService.php';
// LogManager 提供 listLogs（出入库记录列表查询）
require_once __DIR__ . '/../../modules/system/Service/LogsService.php';

/** 日志管理 API 路由 */
final class LogRoutes
{
    public static function handle(string $method, array $user): void
    {

        if (!\hasFeature('feature_log_view')) {
            Response::error('操作日志未启用，请先在「网站设置 → 功能开关」中开启', 403);
            return;
        }

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'list':
                Response::ok(self::queryList($db, $uid, $dataUid, $_GET));
                break;

            case 'trace_list':
                // 取数逻辑与 queryTraceList() 同源

                $traceFilter = $_GET;
                unset($traceFilter['action']);

                if (isset($traceFilter['trace_action'])) {
                    $traceFilter['action'] = $traceFilter['trace_action'];
                    unset($traceFilter['trace_action']);
                }
                if (isset($traceFilter['trace_target_type'])) {
                    $traceFilter['target_type'] = $traceFilter['trace_target_type'];
                    unset($traceFilter['trace_target_type']);
                }
                Response::ok(self::queryTraceList($db, $uid, $dataUid, $traceFilter));
                break;

            case 'delete':
                self::handleDeleteStockLog($db, $uid, $dataUid);
                break;

            case 'batch_delete':
                self::handleBatchDeleteStockLog($db, $uid, $dataUid);
                break;

            case 'delete_login':
                self::requirePrimaryAdmin();
                self::handleDeleteLoginLog($db, $uid);
                break;

            case 'batch_delete_login':
                self::requirePrimaryAdmin();
                self::handleBatchDeleteLoginLog($db, $uid);
                break;

            case 'delete_trace':
                self::handleDeleteTrace($db, $uid, $dataUid);
                break;

            case 'batch_delete_trace':
                self::handleBatchDeleteTrace($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 log 方法：' . $method, 404);
        }
    }

    /** 取数：出入库记录列表（`log.list` 专用实现） */
    public static function queryList(\PDO $db, int $uid, int $dataUid, array $f): array
    {
        return (new \LogManager($db, $uid, $dataUid))->listLogs($f);
    }

    /** 取数：溯源日志列表（`log.trace_list` 专用实现） */
    public static function queryTraceList(\PDO $db, int $uid, int $dataUid, array $f): array
    {
        return (new \TraceManager($db, $uid, $dataUid))->listLogs($f);
    }

    /** 权限校验：仅主管理员 */
    private static function requirePrimaryAdmin(): void
    {
        if (!\isPrimaryAdmin()) {
            Response::error('无权限', 403);
        }
    }

    /** 权限校验：仅管理员（流水删除属审计能力，普通仓管账号无权操作） */
    private static function requireAdmin(): void
    {
        if (!\isAdmin()) {
            Response::error('无权限删除流水记录', 403);
        }
    }

    /** POST log.delete — 删除单条出入库记录（仅管理员） */
    private static function handleDeleteStockLog(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireAdmin();
        $sm = new \StockManager($db, $uid, $dataUid);
        try {
            $result = $sm->deleteLog((int)($_POST['log_id'] ?? 0));
            Response::ok($result, '删除成功');
        } catch (\StockException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST log.batch_delete — 批量删除出入库记录（仅管理员） */
    private static function handleBatchDeleteStockLog(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireAdmin();
        $sm = new \StockManager($db, $uid, $dataUid);
        try {
            $logIds = array_map('intval', $_POST['log_ids'] ?? []);
            $result = $sm->batchDeleteLogs($logIds);
            Response::ok($result, '批量删除成功');
        } catch (\StockException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST log.delete_login — 删除单条登录记录（仅主管理员） */
    private static function handleDeleteLoginLog(\PDO $db, int $uid): void
    {
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            $db->prepare("DELETE FROM login_attempts WHERE id=?")->execute([$logId]);
            \traceLog($uid, 'delete_login_log', 'login_attempt', $logId, "删除登录记录 log_id:{$logId}");
        }
        Response::ok(['id' => $logId], '记录已删除');
    }

    /** POST log.batch_delete_login — 批量删除登录记录（仅主管理员） */
    private static function handleBatchDeleteLoginLog(\PDO $db, int $uid): void
    {
        $logIds = array_map('intval', $_POST['log_ids'] ?? []);
        if (!empty($logIds)) {
            $in = implode(',', array_fill(0, count($logIds), '?'));
            $db->prepare("DELETE FROM login_attempts WHERE id IN ($in)")->execute($logIds);
            \traceLog($uid, 'batch_delete_login_logs', 'login_attempt', 0, "批量删除登录记录 count:" . count($logIds));
        }
        Response::ok(['deleted' => count($logIds)], '批量删除成功');
    }

    /** POST log.delete_trace — 删除单条溯源日志（受留存时效限制） */
    private static function handleDeleteTrace(\PDO $db, int $uid, int $dataUid): void
    {
        $tm = new \TraceManager($db, $uid, $dataUid);
        try {
            $result = $tm->deleteLog((int)($_POST['id'] ?? 0));
            Response::ok($result, '删除成功');
        } catch (\TraceException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }

    /** POST log.batch_delete_trace — 批量删除溯源日志（受留存时效限制） */
    private static function handleBatchDeleteTrace(\PDO $db, int $uid, int $dataUid): void
    {
        $tm = new \TraceManager($db, $uid, $dataUid);
        try {
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) $ids = [];
            $result = $tm->batchDeleteLogs($ids);
            Response::ok($result, '批量删除成功');
        } catch (\TraceException $e) {
            Response::error($e->getMessage(), $e->errCode);
        }
    }
}
