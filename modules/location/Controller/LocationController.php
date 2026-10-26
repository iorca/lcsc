<?php
declare(strict_types=1);

namespace Modules\Location\Controller;

use Modules\Location\Service\LocationService;
use PDO;

/** 库位管理页控制器 */
final class LocationController
{
    /** 组装库位管理页视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid): array
    {
        $svc = new LocationService($db, $uid, $dataUid);

        return [
            'tree'            => $svc->getTree(),
            'pendingBackfill' => $svc->countPendingBackfill(),
            'maxDepth'        => LocationService::MAX_DEPTH,
        ];
    }

    /** 导出库位 CSV（库位树递归展平为层级 / 路径 / 类型 / 绑定物料数） */
    public static function exportCsv(LocationService $svc): void
    {
        // 导出属数据外带，与全站 CSV 导出同权限（can_export）
        if (!\hasPermission('can_export')) {
            http_response_code(403);
            echo '无导出权限';
            exit;
        }

        $typeLabel = [
            'warehouse' => '仓库', 'zone' => '库区', 'bin' => '库位',
            'shelf' => '货架', 'bench' => '工作台', 'lab' => '实验室',
        ];

        $rows = [];
        $walk = function (array $nodes, int $level = 1) use (&$walk, &$rows, $typeLabel): void {
            foreach ($nodes as $n) {
                $rows[] = [$level, $n['path'], $typeLabel[$n['type']] ?? $n['type'], (int)($n['part_cnt'] ?? 0)];
                if (!empty($n['children'])) $walk($n['children'], $level + 1);
            }
        };
        $walk($svc->getTree());

        $csv  = "\xEF\xBB\xBF";
        $csv .= implode(',', ['层级', '库位路径', '类型', '绑定物料']) . "\r\n";
        foreach ($rows as $r) {

            $csv .= implode(',', array_map(static fn($v) => self::csvCell($v), $r)) . "\r\n";
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="locations_' . date('Ymd_His') . '.csv"');
        echo $csv;
        exit;
    }

    /** CSV 单元格转义：公式注入防护 + 字段分隔符 / 引号 / 换行的标准 CSV 引用 */
    private static function csvCell(mixed $v): string
    {
        $s = \csvSafe($v);
        return strpbrk($s, ",\"\n\r") !== false
            ? '"' . str_replace('"', '""', $s) . '"'
            : $s;
    }
}
