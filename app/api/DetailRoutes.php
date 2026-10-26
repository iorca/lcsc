<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Inventory\Service\AttachmentService;

// 详情 / 替代料 / 成本取数由 PartManager 提供
require_once __DIR__ . '/../../modules/inventory/Service/PartManager.php';

/** 物料详情 API 路由 */
final class DetailRoutes
{
    public static function handle(string $method, array $user): void
    {
        switch ($method) {
            case 'detail':
                self::handleDetail($user);
                break;

            case 'version_check':
                self::handleVersionCheck();
                break;

            default:
                Response::error('未知 detail 方法：' . $method, 404);
        }
    }

    /** GET detail.detail — 物料详情抽屉数据 */
    private static function handleDetail(array $user): void
    {
        $id = \safeInt($_GET['id'] ?? 0, 0);
        if ($id <= 0) Response::error('缺少 id 参数', 4);

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = \getDataUserId();

        try {
            $pm   = new \PartManager($db, $uid, $dataUid);
            $data = $pm->getPartFullData($id);
        } catch (\PartException $e) {
            Response::error($e->getMessage(), $e->errCode);
        } catch (\Throwable $e) {
            error_log('DetailRoutes error: ' . $e->getMessage());
            Response::error('服务器内部错误', 1);
        }

        $part = $data['part'];

        // 构造 PartDetail 渲染上下文
        $stock     = (int)($part['stock'] ?? 0);
        $threshold = (int)($part['low_stock_threshold'] ?? 0);
        $effThr    = $threshold > 0 ? $threshold : $data['globalThr'];
        $sc        = $stock <= 0 ? 's-zero' : ($stock < $effThr ? 's-low' : 's-ok');

        $typeLabel = '';
        $platType  = $data['platType'] ?? 'standard';
        if ($platType === 'loose')  $typeLabel = '散料';
        if ($platType === 'custom') $typeLabel = '自定义';

        $attachments = [];
        try {
            $attachments = (new AttachmentService($db, $uid, $dataUid))->listForPart($id);
        } catch (\Throwable $e) {
            error_log('[DetailRoutes] 附件查询失败: ' . $e->getMessage());
        }

        $ctx = [
            'part'        => $part,
            'platType'    => $platType,
            'linkedPart'  => $data['linkedPart'] ?? null,
            'bulkParts'   => $data['bulkParts'] ?? [],
            'altParts'    => $data['altParts'] ?? [],
            'cats'        => $data['cats'] ?? [],
            'prices'      => $data['prices'] ?? [],
            'stockHist'   => $data['stockHist'] ?? [],
            'costHist'    => $data['costHist'] ?? [],
            'logs'        => $data['logs'] ?? [],
            'logTotal'    => $data['logTotal'] ?? 0,
            'totalAsset'  => $data['totalAsset'] ?? 0,
            'latestCost'  => $data['latestCost'] ?? 0,
            'globalThr'   => $data['globalThr'] ?? 10,
            'typeLabel'   => $typeLabel,
            'sc'          => $sc,
            'canViewCost' => \can_view_cost(),
            'id'          => $id,
            'boms'        => self::partBomProjects($db, $id),
            'traceLogs'   => self::partTraceLogs($db, $id),
            'attachments' => $attachments,
        ];

        // 渲染详情 HTML
        $html = \Modules\Inventory\Components\PartDetail::render($ctx);

        Response::ok([
            'model'      => $part['model'] ?? '',
            'html'       => $html,
            'stock_data' => $data['stockData'] ?? null,
            'price_data' => $data['priceData'] ?? null,
            'cost_data'  => $data['costData'] ?? null,
        ]);
    }

    /** GET detail.version_check — 线上版本检测 */
    private static function handleVersionCheck(): void
    {
        $result = \checkRemoteVersion();
        Response::ok($result);
    }

    /** 物料所在 BOM 项目（仅读展示，无权限分支） */
    private static function partBomProjects(\PDO $db, int $partId): array
    {
        try {
            $stmt = $db->prepare("SELECT bp.id AS project_id, bp.name AS project_name, bi.qty
                FROM bom_items bi
                JOIN bom_projects bp ON bp.id = bi.project_id
                WHERE bi.part_id = ? ORDER BY bp.updated_at DESC LIMIT 10");
            $stmt->execute([$partId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // 静默返回空数组会让「SQL 写错」与「本就无记录」无法区分，故记日志
            error_log('[DetailRoutes] partBomProjects 查询失败: ' . $e->getMessage());
            return [];
        }
    }

    /** 物料操作日志（trace_log 仅读展示） */
    private static function partTraceLogs(\PDO $db, int $partId): array
    {
        try {

            $stmt = $db->prepare("SELECT tl.action, tl.detail, tl.created_at, u.username
                FROM trace_log tl LEFT JOIN users u ON u.id = tl.user_id
                WHERE tl.target_type = 'part' AND tl.target_id = ?
                ORDER BY tl.id DESC LIMIT 5");
            $stmt->execute([$partId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[DetailRoutes] partTraceLogs 查询失败: ' . $e->getMessage());
            return [];
        }
    }
}
