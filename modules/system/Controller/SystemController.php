<?php
declare(strict_types=1);

namespace Modules\System\Controller;

use Modules\System\Service\AccountService;
use Modules\System\Service\SystemReadService;
use PDO;

/** 系统模块页控制器（薄壳） */
final class SystemController
{
    /** 修改密码页视图数据（极简，仅权限校验标记） */
    public static function prepareChangePasswordData(): array
    {
        return [];
    }

    /** 处理修改密码 POST 请求 */
    public static function handleChangePassword(PDO $db, array $user): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return ['error' => '', 'success' => ''];
        }

        $stored   = $_SESSION['csrf'] ?? '';
        $supplied = $_POST['_csrf'] ?? '';
        if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
            return ['error' => '请求无效，请刷新页面重试', 'success' => ''];
        }

        return (new AccountService($db))->changePassword(
            (int)$user['id'],
            (string)($_POST['old_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm'] ?? '')
        );
    }

    /** 导出页视图数据 */
    public static function prepareExportData(PDO $db, int $uid, int $dataUid): array
    {
        $read = new SystemReadService($db, $uid, $dataUid);

        $canUseBom       = \hasFeature('bom');
        $canUseChecklist = \hasFeature('checklist');

        return [
            'canViewCost'     => \can_view_cost(),
            'stats'           => $read->getExportStats(),
            'lowStockCount'   => $read->countLowStock(),
            'stockLogCount'   => $read->countStockLogs(),
            'bomProjects'     => $canUseBom ? $read->getBomProjects() : [],
            'allCats'         => $canUseChecklist ? $read->getTopCategories() : [],
            'allPlats'        => $canUseChecklist ? $read->getPlatforms() : [],
            'canUseBom'       => $canUseBom,
            'canUseChecklist' => $canUseChecklist,
        ];
    }

    /** 导出行按「勾选的 ids」过滤（物料列表「导出CSV」POST ids[]） */
    private static function filterExportIds(array $rows): array
    {
        $sel = array_filter(array_map('intval', $_POST['ids'] ?? []), fn($v) => $v > 0);
        if (!$sel) return $rows;
        $sel = array_flip($sel);
        return array_values(array_filter($rows, fn($r) => isset($sel[(int)($r['id'] ?? 0)])));
    }

    /** 处理导出动作（CSV 下载），若无导出请求则直接返回 */
    public static function handleExportAction(PDO $db, int $uid, int $dataUid): void
    {
        $type = $_GET['type'] ?? '';

        // 个人版降级：无成本查看权限时不允许导出成本列
        if (!\can_view_cost()) {
            if ($type === 'csv') {
                $type = 'csv_simple';
            } elseif ($type === 'stock_full') {
                $type = '';
            }
        }

        $read = new SystemReadService($db, $uid, $dataUid);

        // 简易版库存导出（个人版）
        if ($type === 'csv_simple') {
            $rows = $read->getInventorySimpleRows();
            $rows = self::filterExportIds($rows);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="inventory_simple_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['型号', '数量', '库位']);
            foreach ($rows as $r) {
                fputcsv($out, [\csvSafe($r['model'] ?? ''), $r['stock'], \csvSafe($r['location'] ?? '')]);
            }
            fclose($out);
            exit;
        }

        // 完整版库存导出
        if ($type === 'csv') {
            $data  = $read->getInventoryFullRows();
            $rows  = $data['rows'];
            $catMap = $data['catMap'];
            $rows  = self::filterExportIds($rows);   // 物料列表「勾选后导出」：POST ids[] 只导选中行，空=全量

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="inventory_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['平台', '商品编号', '客户料号', '型号', '商品名称', '商品类型', '封装', '品牌', '库存', '低库存阈值', '库位', '分类', '最近采购价', '加权平均成本', '备注', '最近更新']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    \csvSafe($r['platform_name'] ?? ''),
                    \csvSafe($r['platform_part_no'] ?? ''),
                    \csvSafe($r['customer_part_no'] ?? ''),
                    \csvSafe($r['model'] ?? ''),
                    \csvSafe($r['product_name'] ?? ''),
                    \csvSafe($r['product_type'] ?? ''),
                    \csvSafe($r['package'] ?? ''),
                    \csvSafe($r['brand'] ?? ''),
                    $r['stock'],
                    $r['low_stock_threshold'],
                    \csvSafe($r['location'] ?? ''),
                    \csvSafe(implode(';', $catMap[$r['id']] ?? [])),
                    $r['last_purchase_price'] ?? 0,
                    $r['avg_cost'] ?? 0,
                    \csvSafe($r['remark'] ?? ''),
                    substr((string)$r['update_time'], 0, 16),
                ]);
            }
            fclose($out);
            exit;
        }

        // 补货清单导出
        if (($_GET['export'] ?? '') === 'replenish' || $type === 'replenish') {
            $rows = $read->getReplenishRows();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="replenish_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['商品编号', '型号', '品牌', '当前库存', '预警阈值', '建议补货数量', '平台', '库位']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    \csvSafe($r['platform_part_no'] ?? ''),
                    \csvSafe($r['model'] ?? ''),
                    \csvSafe($r['brand'] ?? ''),
                    $r['stock'],
                    (int)$r['eff_threshold'],
                    $r['replenish_qty'],
                    \csvSafe($r['platform_code'] ?? ''),
                    \csvSafe($r['location'] ?? ''),
                ]);
            }
            fclose($out);
            exit;
        }

        // 完整出入库记录导出
        if ($type === 'stock_full') {
            $rows = $read->getStockLogRows();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="stock_log_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, ['记录ID', '操作时间', '操作人', '商品编号', '型号', '品牌', '库位', '变动类型', '变动数量', '变动前', '变动后', '单价', '是否样品', '小计', '订单时间', '备注']);
            $typeMap = [
                'import'    => '导入入库',
                'manual_in' => '手动入库',
                'manual_out'=> '手动出库',
                'scan_in'   => '扫码入库',
                'scan_out'  => '扫码出库',
                'bom_out'   => 'BOM出库',
            ];
            foreach ($rows as $r) {
                fputcsv($out, [
                    (int)$r['id'],
                    substr((string)$r['create_time'], 0, 19),
                    \csvSafe($r['op_username'] !== null && $r['op_username'] !== '' ? $r['op_username'] : 'user_id:' . $r['user_id']),
                    \csvSafe($r['platform_part_no'] ?? ''),
                    \csvSafe($r['model'] ?? ''),
                    \csvSafe($r['brand'] ?? ''),
                    \csvSafe($r['location'] ?? ''),
                    $typeMap[$r['change_type']] ?? $r['change_type'],
                    (int)$r['qty_change'],
                    (int)$r['qty_before'],
                    (int)$r['qty_after'],
                    $r['unit_cost'],
                    ((int)$r['is_sample'] === 1) ? '是' : '否',
                    $r['subtotal'],
                    $r['order_time'] ? substr((string)$r['order_time'], 0, 19) : '',
                    \csvSafe($r['remark'] ?? ''),
                ]);
            }
            fclose($out);
            \traceLog($uid, 'export_stock_full', 'stock_log', 0, '导出完整出入库记录 count:' . count($rows));
            exit;
        }
    }

    /** 出入库记录页视图数据 */
    public static function prepareLogData(PDO $db, int $uid, int $dataUid): array
    {
        require_once __DIR__ . '/../Service/LogsService.php';
        $lm = new \LogManager($db, $uid, $dataUid);

        $logsData = ['logs' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'per_page' => 25, 'type_labels' => [], 'q' => ''];
        try {
            $logsData = $lm->listLogs($_GET);
        } catch (\Throwable $e) {
            error_log('SystemController logData: ' . $e->getMessage());
        }

        return [
            'flash'     => $_GET['flash'] ?? null,
            'logs'      => $logsData['logs'] ?? [],
            'total'     => $logsData['total'] ?? 0,
            'page'      => $logsData['page'] ?? 1,
            'totalPage' => $logsData['total_pages'] ?? 1,
            'perPage'   => $logsData['per_page'] ?? 25,
            'typeInfo'  => $logsData['type_labels'] ?? [],
            'searchKw'  => $logsData['q'] ?? '',
        ];
    }
}
