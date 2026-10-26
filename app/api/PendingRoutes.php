<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Inventory\Service\PendingItemService;

/** 待处理物料 API 路由 */
final class PendingRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db  = \getDB();
        $uid = (int)$user['id'];
        $dataUid = \getDataUserId();

        $service = new PendingItemService($db, $uid, $dataUid);

        switch ($method) {
            case 'scan':    self::scan($service, $uid); break;
            case 'list':    self::listItems($service); break;
            case 'summary': self::summary($service); break;
            case 'fix':     self::fix($service, $db, $uid); break;
            case 'ignore':  self::ignore($service, $db, $uid); break;
            default:        Response::error('未知 pending 方法：' . $method, 404);
        }
    }

    /** 权限校验：待处理记录的生成 / 修正 / 忽略都属编辑操作 */
    private static function requireEdit(): void
    {
        if (!\hasPermission('can_edit')) {
            Response::error('权限不足', 403);
        }
    }

    /** 扫描异常物料 */
    private static function scan(PendingItemService $service, int $uid): void
    {
        self::requireEdit();
        $result = $service->scan();
        \traceLog($uid, 'pending_scan', 'system', 0, "扫描完成：新增{$result['new']}条，已存在{$result['already']}条");
        Response::ok($result);
    }

    /** 获取待处理列表 */
    private static function listItems(PendingItemService $service): void
    {
        $page   = max(1, (int)($_POST['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_POST['per_page'] ?? 25)));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'pending'));

        $result = $service->list($page, $perPage, $reason, $status);
        Response::ok($result);
    }

    /** 统计摘要 */
    private static function summary(PendingItemService $service): void
    {
        Response::ok($service->summary());
    }

    /** 批量修正 */
    private static function fix(PendingItemService $service, \PDO $db, int $uid): void
    {
        self::requireEdit();
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) Response::error('请选择要修正的记录');

        $count = $service->batchFix($ids);
        \traceLog($uid, 'pending_fix', 'pending_items', 0, "批量修正{$count}条待处理记录");
        Response::ok(['fixed_count' => $count]);
    }

    /** 批量忽略 */
    private static function ignore(PendingItemService $service, \PDO $db, int $uid): void
    {
        self::requireEdit();
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids) || empty($ids)) Response::error('请选择要忽略的记录');

        $count = $service->ignore($ids);
        \traceLog($uid, 'pending_ignore', 'pending_items', 0, "忽略{$count}条待处理记录");
        Response::ok(['ignored_count' => $count]);
    }
}
