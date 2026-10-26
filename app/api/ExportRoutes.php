<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

// 三个 Manager 提供 getLogsForExport 方法
require_once __DIR__ . '/../../modules/assets/Service/AssetsService.php';
require_once __DIR__ . '/../../modules/system/Service/LogsService.php';
require_once __DIR__ . '/../../modules/system/Service/TraceService.php';

/** CSV 导出 API 路由 */
final class ExportRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        // 所有导出操作均需 can_export 权限
        if (!\hasPermission('can_export')) {
            self::denyExport($method);
        }

        switch ($method) {
            case 'assets':
                self::handleExportAssets($db, $uid, $dataUid);
                break;

            case 'logs':
                self::handleExportLogs($db, $uid, $dataUid);
                break;

            case 'trace':
                self::handleExportTrace($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 export 方法：' . $method, 404);
        }
    }

    /** 权限拒绝：AJAX 返回 JSON 错误，否则重定向到对应页面 */
    private static function denyExport(string $method): void
    {
        $fallback = match ($method) {
            'assets' => 'index.php?v=assets&flash=forbidden',
            'logs'   => 'index.php?v=stock&view=log&flash=forbidden',
            'trace'  => 'index.php?v=admin&flash=forbidden#tab-trace',
            default  => 'index.php',
        };
        if (\isAjaxRequest()) {
            Response::error('无导出权限', 403);
        }
        Response::redirect($fallback);
    }

    /** 输出 CSV 头（清空缓冲区 + UTF-8 BOM + 附件头） */
    private static function startCsvStream(string $filename): void
    {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM（Excel 兼容）
    }

    /** POST export.assets — 资产流水 CSV 导出 */
    private static function handleExportAssets(\PDO $db, int $uid, int $dataUid): void
    {
        $am = new \AssetManager($db, $uid, $dataUid);
        try {
            $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')), fn($v) => $v > 0);
            $rows = $am->getLogsForExport($ids);
            self::startCsvStream('assets_log_' . date('Ymd_His') . '.csv');
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['物料编号', '型号', '分类', '采购平台', '操作类型', '变动数量', '入库单价', '含税小计', '是否样品', '操作时间', '备注']);
            foreach ($rows as $row) {
                $typeLabel = \AssetManager::TYPE_LABELS[$row['change_type']][0] ?? $row['change_type'];
                fputcsv($fp, [
                    $row['platform_part_no'] ?? '',
                    $row['model'] ?? '',
                    $row['cat_names'] ?? '',
                    $row['pname'] ?? '',
                    $typeLabel,
                    $row['qty_change'],
                    $row['unit_cost'] ?? 0,
                    $row['subtotal'] ?? 0,
                    (int)($row['is_sample'] ?? 0) === 1 ? '是' : '否',
                    $row['create_time'],
                    $row['remark'] ?? '',
                ]);
            }
            fclose($fp);
            exit;
        } catch (\AssetException $e) {
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=assets&flash=err');
        }
    }

    /** POST export.logs — 出入库记录 CSV 导出 */
    private static function handleExportLogs(\PDO $db, int $uid, int $dataUid): void
    {
        $lm = new \LogManager($db, $uid, $dataUid);
        try {
            $ids = $_POST['log_ids'] ?? [];
            if (!is_array($ids)) $ids = [];
            $rows = $lm->getLogsForExport($ids);
            self::startCsvStream('stock_log_' . date('Ymd_His') . '.csv');
            $fp = fopen('php://output', 'w');

            fputcsv($fp, ['时间', '商品编号', '型号', '类型', '变化量', '操作人', '操作IP', '备注']);
            foreach ($rows as $row) {
                $typeLabel = \LogManager::TYPE_LABELS[$row['change_type']][0] ?? $row['change_type'];
                $chg = (int)$row['qty_change'];
                fputcsv($fp, [
                    $row['create_time'],
                    $row['platform_part_no'] ?? '',
                    $row['model'] ?? '',
                    $typeLabel,
                    ($chg >= 0 ? '+' : '') . $chg,
                    $row['operator'] ?? '',
                    $row['ip'] ?? '',
                    $row['remark'] ?? '',
                ]);
            }
            fclose($fp);
            exit;
        } catch (\LogException $e) {
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=stock&view=log&flash=err');
        }
    }

    /** POST export.trace — 溯源日志 CSV 导出（按起止日期筛选） */
    private static function handleExportTrace(\PDO $db, int $uid, int $dataUid): void
    {
        $tm = new \TraceManager($db, $uid, $dataUid);
        try {
            $dateFrom = trim((string)($_POST['date_from'] ?? ''));
            $dateTo   = trim((string)($_POST['date_to'] ?? ''));
            $rows = $tm->getLogsForExport($dateFrom, $dateTo);
            self::startCsvStream('trace_log_' . date('Ymd_His') . '.csv');
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['时间', '操作用户', '动作', '目标类型', '目标ID', '操作详情', 'IP']);
            foreach ($rows as $row) {
                fputcsv($fp, [
                    (string)$row['created_at'],
                    $row['username'] !== '' ? (string)$row['username'] : 'user_id:' . $row['user_id'],
                    (string)($row['action_label'] ?? $row['action']),
                    (string)($row['target_type_label'] ?? $row['target_type']),
                    (int)$row['target_id'],
                    (string)($row['detail'] ?? ''),
                    (string)($row['ip'] ?? ''),
                ]);
            }
            fclose($fp);
            exit;
        } catch (\TraceException $e) {
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin&flash=err#tab-trace');
        }
    }
}
