<?php
declare(strict_types=1);

namespace Modules\Assets\Controller;

use PDO;

/** 资产总览页控制器 */
final class AssetsController
{
    /** 最近流水预览条数 */
    private const RECENT_LOG_LIMIT = 10;

    /** 组装资产总览页视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid): array
    {
        require_once __DIR__ . '/../Service/AssetsService.php';
        $am = new \AssetManager($db, $uid, $dataUid);

        $stats      = [];
        $charts     = ['line' => ['labels' => [], 'values' => []], 'bar' => ['labels' => [], 'in_values' => [], 'out_values' => []]];
        $recentLogs = [];
        $typeLabel  = [];
        $assetValue = ['total_asset_value' => 0, 'total_stock_qty' => 0];
        $catDist    = [];
        $topHigh    = [];

        try {
            $stats      = $am->getStats();
            $charts     = $am->getChartData();
            $logsData   = $am->listLogs(['per_page' => 15]);
            $assetValue = $am->getTotalAssetValue();
            $catDist    = $am->getCategoryAssetDistribution();
            $topHigh    = $am->getTopHighValueParts(10);

            $recentLogs = array_slice($logsData['logs'] ?? [], 0, self::RECENT_LOG_LIMIT);
            $typeLabel  = $logsData['type_labels'] ?? [];
        } catch (\Throwable $e) { error_log('AssetsController prepareViewData: ' . $e->getMessage()); }

        // ── 解构为模板变量 ──
        $totalStockCost = $stats['total_stock_cost'] ?? 0;
        $sampleCount    = $stats['sample_count'] ?? 0;
        $monthInAmount  = $stats['month_in_amount'] ?? 0;
        $inStockTypes   = $stats['in_stock_types'] ?? 0;
        $monthNewParts  = $stats['month_new_parts'] ?? 0;
        $totalAssetValue = $assetValue['total_asset_value'] ?? 0;
        $totalStockQty  = $assetValue['total_stock_qty'] ?? 0;

        return [
            'totalStockCost'  => $totalStockCost,
            'sampleCount'     => $sampleCount,
            'monthInAmount'   => $monthInAmount,
            'inStockTypes'    => $inStockTypes,
            'monthNewParts'   => $monthNewParts,
            'totalAssetValue' => $totalAssetValue,
            'totalStockQty'   => $totalStockQty,
            'assetLabels'     => $charts['line']['labels'] ?? [],
            'assetValues'     => $charts['line']['values'] ?? [],
            'barLabels'       => $charts['bar']['labels'] ?? [],
            'barInValues'     => $charts['bar']['in_values'] ?? [],
            'barOutValues'    => $charts['bar']['out_values'] ?? [],
            'logs'            => $recentLogs,
            'typeLabel'       => $typeLabel,
            'catDist'         => $catDist,
            'topHigh'         => $topHigh,
        ];
    }
}
