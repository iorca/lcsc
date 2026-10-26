<?php
declare(strict_types=1);

namespace Modules\Bom\Controller;

use PDO;

/** BOM 管理页面控制器 */
final class BomController
{
    /** 处理 GET 导出请求（insufficient / not_found CSV，按当前视图版本导出） */
    public static function handleExport(PDO $db, int $uid, int $dataUid, \BomManager $bom): array
    {
        $type      = (string)($_GET['export_bom'] ?? '');
        $projectId = (int)($_GET['pid'] ?? 0);

        if ($projectId <= 0 || !in_array($type, ['insufficient', 'not_found'], true)) {
            return ['msg' => null, 'msgType' => ''];
        }

        try {
            $pr = $bom->loadOwnedProject($projectId);

            $versionId = (int)($_GET['bom_ver'] ?? 0);
            $vs = new \Modules\Bom\Service\BomVersionService($db, $uid, $dataUid, $bom);
            $items = $vs->listVersionItems($projectId, $versionId > 0 ? $versionId : null);
            $exportRows = [];
            foreach ($items as $it) {
                if (($it['status'] ?? '') !== $type) continue;
                $part = $it['part'] ?? null;
                $exportRows[] = [
                    'sort'    => $it['sort_order'],
                    'part_no' => (string)$it['platform_part_no'],
                    'model'   => (string)$it['model'],
                    'qty'     => (int)$it['qty'],
                    'stock'   => (int)($it['stock'] ?? 0),
                    'gap'     => (int)($it['gap'] ?? 0),
                    'name'    => (string)($part['product_name'] ?? ''),
                    'brand'   => (string)($part['brand'] ?? ''),
                    'package' => (string)($part['package'] ?? ''),
                    'type'    => (string)($part['product_type'] ?? ''),
                ];
            }
            $typeLabel = $type === 'not_found' ? '未匹配' : '库存不足';
            \header('Content-Type: text/csv; charset=utf-8');
            \header('Content-Disposition: attachment; filename="bom_' . $type . '_' . $pr['name'] . '_' . date('Ymd_His') . '.csv"');
            \header('Cache-Control: no-cache, no-store, must-revalidate');
            echo "\xEF\xBB\xBF";
            $fp = fopen('php://output', 'w');
            fputcsv($fp, ['序号','商品编号','型号','商品名称','品牌','封装','分类','需求数量','当前库存','缺口数量']);
            foreach ($exportRows as $r) {
                fputcsv($fp, [
                    $r['sort'], $r['part_no'], $r['model'], $r['name'],
                    $r['brand'], $r['package'], $r['type'], $r['qty'],
                    $r['stock'], $r['gap'],
                ]);
            }
            fclose($fp);
            \traceLog($uid, 'bom_export_items', 'bom_project', $projectId, '导出BOM' . $typeLabel . '物料:' . $pr['name'] . ' 共' . count($exportRows) . '条');
            exit;
        } catch (\Throwable $e) {
            return ['msg' => $e->getMessage(), 'msgType' => 'err'];
        }
    }

    /** 组装 BOM 页面视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid, \BomManager $bom): array
    {
        $projectId = (int)($_GET['id'] ?? 0);
        $filter    = (string)($_GET['filter'] ?? '');
        if (!in_array($filter, ['ok', 'insufficient', 'not_found', 'unbound'], true)) $filter = '';

        $read = new \Modules\Bom\Service\BomReadService($db, $dataUid);
        $vs   = new \Modules\Bom\Service\BomVersionService($db, $uid, $dataUid, $bom);

        // ── 平台列表（弹窗使用）──
        [$platforms, $defaultPlatId] = $read->getPlatformsWithDefault();

        // ── BOM 项目列表（带平台信息）──
        $projects = $read->getProjectList();

        // ── 选中项目明细 + 库存预校验 ──
        $selProject = null;
        $selItems   = [];
        $summary    = ['ok' => 0, 'insufficient' => 0, 'not_found' => 0, 'unbound' => 0];
        $bomCost    = ['total_cost' => 0, 'item_count' => 0, 'profit_margin' => 0, 'quote_price' => 0];
        $totalItems = 0;
        $bomPage    = 1;
        $bomPerPage = 25;
        $bomTotalPage = 1;
        $shortage   = ['shortage_count' => 0, 'total_items' => 0, 'shortage_list' => []];
        $viewVersionId = 0;
        $viewVersion   = null;
        $bomReadonly   = false;

        if ($projectId > 0) {
            $selProject = $read->getProjectHeader($projectId);

            if ($selProject) {
                // 版本隔离：读取 URL 指定版本(bom_ver)，缺省=项目当前编辑草稿
                $viewVersionId = (int)($_GET['bom_ver'] ?? 0);
                if ($viewVersionId > 0) {
                    $viewVersionId = $bom->resolveVersionId($projectId, $viewVersionId);
                } else {
                    $viewVersionId = $bom->resolveVersionId($projectId, null);
                }
                $viewVersion = $bom->getVersion($viewVersionId);
                $bomReadonly = $viewVersion && in_array($viewVersion['status'], ['released', 'archived'], true);

                $listResult = $bom->listItems($projectId, [
                    'filter'     => $filter,
                    'page'       => max(1, intval($_GET['bom_page'] ?? 1)),
                    'per_page'   => intval($_GET['bom_per_page'] ?? $_COOKIE['per_page_bom'] ?? 25),
                    'version_id' => $viewVersionId,
                ]);

                $selItems    = $listResult['items'];
                $totalItems  = $listResult['total'];
                $bomPage     = $listResult['page'];
                $bomPerPage  = $listResult['per_page'];
                $bomTotalPage = $listResult['total_page'];
                $summary     = $listResult['summary'];

                // BOM 成本
                if (\can_view_cost()) {
                    $bomCost = $bom->getCostSummary($projectId, $viewVersionId);
                }

                // 缺料分析
                $shortage = $bom->getShortageSummary($projectId, 1, $viewVersionId);
            }
        }

        // BOM 版本列表（打开版本 Tab UI）
        $bomVersions = [];
        if ($projectId > 0) {
            try {
                $bomVersions = $vs->listVersions($projectId);
            } catch (\Throwable $e) { $bomVersions = []; }
        }

        return [
            'selId'          => $projectId,
            'filter'         => $filter,
            'projects'       => $projects,
            'selProject'     => $selProject,
            'selItems'       => $selItems,
            'summary'        => $summary,
            'totalItems'     => $totalItems,
            'bomCost'        => $bomCost,
            'bomPage'        => $bomPage,
            'bomPerPage'     => $bomPerPage,
            'bomTotalPage'   => $bomTotalPage,
            'platforms'      => $platforms,
            'defaultPlatId'  => $defaultPlatId,
            'shortage'       => $shortage,
            'bomVersions'    => $bomVersions,
            'viewVersionId'  => $viewVersionId,
            'viewVersion'    => $viewVersion,
            'bomReadonly'    => $bomReadonly,
            'versionManaged' => $selProject ? (int)($selProject['version_managed'] ?? 1) : 1,
            'startVersion'   => $selProject ? (string)($selProject['start_version'] ?? 'V1.0') : 'V1.0',
            'releasedCount'  => count(array_filter($bomVersions, fn($v) => ($v['status'] ?? '') === 'released')),
        ];
    }
}
