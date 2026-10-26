<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\System\Service\DataHealthService;

/** 数据健康检测 API 路由 */
final class HealthRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();
        $svc     = new DataHealthService($db, $uid, $dataUid);

        switch ($method) {
            case 'summary':
                self::handleSummary($svc);
                break;

            case 'detail':
                self::handleDetail($svc);
                break;

            case 'refresh':
                self::requireEdit();
                $result = $svc->summary(true);
                \traceLog($uid, 'health_refresh', 'system', 0,
                    '重新检测数据健康：' . $result['check_total'] . ' 项 / 异常 ' . $result['issue_total'] . ' 条');
                Response::ok($result, '检测完成');
                break;

            case 'fix':
                self::requireEdit();
                self::handleFix($db, $svc, $uid);
                break;

            case 'ignore':
                self::handleIgnore($svc, $uid);
                break;

            default:
                Response::error('未知 health 方法：' . $method, 404);
        }
    }

    /** POST health.ignore — 忽略/恢复「仅供参考」检测项（key + on=1|0） */
    private static function handleIgnore(DataHealthService $svc, int $uid): void
    {
        $key = (string)($_POST['key'] ?? '');
        $on  = ((int)($_POST['on'] ?? 1)) === 1;
        if ($key === '') Response::error('缺少检测项标识（参数 key）');

        try {
            $svc->setIgnored($key, $on);
        } catch (\Throwable $e) {
            Response::error($e->getMessage());
        }
        \traceLog($uid, 'health_ignore_' . ($on ? 'on' : 'off'), 'system', 0,
            '数据健康「' . $key . '」' . ($on ? '已忽略' : '已恢复提醒'));
        Response::ok(['key' => $key, 'ignored' => $on], $on ? '已忽略，下次检测前不再提醒' : '已恢复提醒');
    }

    /** GET health.summary — 概览（refresh=1 强制重算） */
    private static function handleSummary(DataHealthService $svc): void
    {
        $force = ($_GET['refresh'] ?? '') === '1';
        Response::ok($svc->summary($force));
    }

    /** GET health.detail — 明细下钻（服务端分页） */
    private static function handleDetail(DataHealthService $svc): void
    {
        $key     = (string)($_GET['key'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 20);

        if ($key === '') Response::error('缺少检测项标识（参数 key）');

        $result = $svc->detail($key, $page, $perPage);
        if ($result['check'] === null) Response::error('未知的检测项：' . $key, 404);

        // 附上列定义，前端无需硬编码表头
        $result['columns'] = \array_keys(DataHealthService::detailColumns($key));
        $result['labels']  = DataHealthService::detailColumns($key);
        Response::ok($result);
    }

    /** POST health.fix — 执行修复动作 */
    private static function handleFix(\PDO $db, DataHealthService $svc, int $uid): void
    {
        $action = (string)($_POST['fix'] ?? '');
        $limit  = (int)($_POST['limit'] ?? 200);

        if ($action === '') Response::error('缺少修复动作（参数 fix）');

        // 成本重算属成本域操作，需在成本门控下才允许
        if ($action === 'recalc_cost' && !\can_view_cost()) {
            Response::error('当前版本不支持成本查看，无法重算成本', 403);
        }

        $result = $svc->fix($action, $limit);

        if ($result['ok'] && $result['fixed'] > 0) {

            DataHealthService::invalidateCache($db);
            \traceLog($uid, 'health_fix_' . $action, 'system', 0,
                "数据健康修复 {$action}：处理 {$result['fixed']} 条");
        }

        if (!$result['ok']) {
            Response::error($result['msg'], 500);
        }
        Response::ok(['fixed' => $result['fixed']], $result['msg']);
    }

    /** 写操作权限校验 */
    private static function requireEdit(): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足：需要编辑权限', 403);
    }
}
