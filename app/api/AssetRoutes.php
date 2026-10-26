<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Inventory\Service\AttachmentService;
use Modules\Inventory\Service\DatasheetBinder;
use Modules\Inventory\Service\PartDatasheetLocalizer;
use Modules\Inventory\Service\ThumbnailService;

// AssetManager 提供出入库流水查询（listLogs）
require_once __DIR__ . '/../../modules/assets/Service/AssetsService.php';

/** 资产管理 API 路由 */
final class AssetRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'logs':
                // 取数：出入库流水查询
                Response::ok(self::queryLogs($db, $uid, $dataUid, $_GET));
                break;

            case 'stats':
                Response::ok(self::queryStats($db, $uid, $dataUid));
                break;

            case 'charts':
                Response::ok(self::queryCharts($db, $uid, $dataUid));
                break;

            case 'filter_options':
                Response::ok(self::queryFilterOptions($db, $uid, $dataUid));
                break;

            case 'value':
                Response::ok(self::queryTotalAssetValue($db, $uid, $dataUid));
                break;

            case 'category_dist':
                Response::ok(self::queryCategoryAssetDistribution($db, $uid, $dataUid));
                break;

            case 'top_high':
                Response::ok(self::queryTopHighValueParts($db, $uid, $dataUid, intval($_GET['limit'] ?? 10)));
                break;

            case 'stale':
                Response::ok(self::queryStaleParts($db, $uid, $dataUid, intval($_GET['days'] ?? 90), intval($_GET['limit'] ?? 50)));
                break;

            case 'warnings':
                Response::ok(self::queryWarnings($db, $uid, $dataUid));
                break;

            case 'thumb':
                self::handleThumb();
                break;

            case 'upload_attachment':
                self::handleUploadAttachment($db, $uid, $dataUid);
                break;

            case 'delete_attachment':
                self::handleDeleteAttachment($db, $uid, $dataUid);
                break;

            case 'datasheet_download':
                self::handleDatasheetDownload($db, $uid, $dataUid);
                break;

            case 'datasheet_scan':
                self::handleDatasheetScan($db, $uid, $dataUid);
                break;

            case 'datasheet_file':
                self::handleDatasheetFile($db, $uid, $dataUid);
                break;

            case 'duplicate_part':
                self::handleDuplicatePart($db, $uid, $dataUid);
                break;

            case 'add_purchase':
                self::handleAddPurchase($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 asset 方法：' . $method, 404);
        }
    }

    /** 权限校验：编辑物料（附件 / 复制 / 采购记录都是物料写操作） */
    private static function requireEdit(): void
    {
        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);
    }

    /** 取数：出入库流水查询 */
    public static function queryLogs(\PDO $db, int $uid, int $dataUid, array $f): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->listLogs($f);
    }

    /** 按需重算脏标记物料的成本缓存（限制最多 100 个，避免超时） */
    private static function recalcDirtyCosts(\PDO $db, int $dataUid): void
    {
        $dirtyStmt = $db->prepare("SELECT id FROM parts WHERE user_id=? AND cost_dirty=1 ORDER BY last_cost_recalc ASC LIMIT 100");
        $dirtyStmt->execute([$dataUid]);
        foreach ($dirtyStmt->fetchAll(\PDO::FETCH_COLUMN) as $dirtyId) {
            \recalcAvgCost($db, (int)$dirtyId);
        }
    }

    /** 取数：资产统计卡片（含脏成本重算） */
    public static function queryStats(\PDO $db, int $uid, int $dataUid): array
    {
        self::recalcDirtyCosts($db, $dataUid);
        return (new \AssetManager($db, $uid, $dataUid))->getStats();
    }

    /** 取数：资产图表（含脏成本重算） */
    public static function queryCharts(\PDO $db, int $uid, int $dataUid): array
    {
        self::recalcDirtyCosts($db, $dataUid);
        return (new \AssetManager($db, $uid, $dataUid))->getChartData();
    }

    /** 取数：筛选下拉选项 */
    public static function queryFilterOptions(\PDO $db, int $uid, int $dataUid): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getFilterOptions();
    }

    /** 取数：物料总资产价值 */
    public static function queryTotalAssetValue(\PDO $db, int $uid, int $dataUid): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getTotalAssetValue();
    }

    /** 取数：分类资产分布 */
    public static function queryCategoryAssetDistribution(\PDO $db, int $uid, int $dataUid): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getCategoryAssetDistribution();
    }

    /** 取数：高价值物料 TOP N */
    public static function queryTopHighValueParts(\PDO $db, int $uid, int $dataUid, int $limit = 10): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getTopHighValueParts($limit);
    }

    /** 取数：呆滞料识别 */
    public static function queryStaleParts(\PDO $db, int $uid, int $dataUid, int $days = 90, int $limit = 50): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getStaleParts($days, $limit);
    }

    /** 取数：首页预警中心汇总 */
    public static function queryWarnings(\PDO $db, int $uid, int $dataUid): array
    {
        return (new \AssetManager($db, $uid, $dataUid))->getWarnings();
    }

    /** GET asset.thumb — 输出缩略图二进制 */
    private static function handleThumb(): void
    {
        $path = (string)($_GET['path'] ?? '');
        $size = (int)($_GET['size'] ?? ThumbnailService::SIZE_LIST);

        $size = $size === ThumbnailService::SIZE_DETAIL
            ? ThumbnailService::SIZE_DETAIL
            : ThumbnailService::SIZE_LIST;

        $svc  = new ThumbnailService();
        $file = $svc->resolve($path, $size);

        if ($file === null) {
            // 降级：直跳原图（外链 http(s) 由前端直连，不会走到这里）
            $rel = \str_replace('\\', '/', \trim($path));
            if (\str_starts_with($rel, 'uploads/') && !\str_contains($rel, '..')) {
                \header('Cache-Control: private, max-age=300');
                \header('Location: ' . $rel);
                exit;
            }
            \http_response_code(404);
            exit;
        }

        $etag = '"' . \md5($path . '|' . $size . '|' . \filemtime($file) . '|' . \filesize($file)) . '"';
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            \http_response_code(304);
            exit;
        }

        \header('Content-Type: image/webp');
        \header('Content-Length: ' . \filesize($file));
        \header('Cache-Control: public, max-age=31536000, immutable');
        \header('ETag: ' . $etag);
        \header('Last-Modified: ' . \gmdate('D, d M Y H:i:s', \filemtime($file)) . ' GMT');
        \readfile($file);
        exit;
    }

    /** POST asset.upload_attachment — 上传物料附件 */
    private static function handleUploadAttachment(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId = (int)($_POST['part_id'] ?? 0);
        $kind   = ($_POST['kind'] ?? 'image') === 'pdf' ? 'pdf' : 'image';
        $file   = $_FILES['file'] ?? null;

        if ($partId <= 0) Response::error('缺少物料ID');
        if (!is_array($file)) Response::error('请选择文件');

        $svc  = new AttachmentService($db, $uid, $dataUid);
        $data = $svc->upload($partId, $file, $kind);

        Response::ok($data, $kind === 'pdf' ? '手册上传成功' : '图片上传成功');
    }

    /** POST asset.delete_attachment — 删除物料附件（图片 / PDF 手册通用） */
    private static function handleDeleteAttachment(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) Response::error('参数无效');

        (new AttachmentService($db, $uid, $dataUid))->delete($id);
        Response::ok([], '删除成功');
    }

    /**
     * POST asset.datasheet_download — 从立创下载规格书到本地并自动绑定
     *
     * @param part_id  指定物料；不给则批量处理全部立创物料
     */
    private static function handleDatasheetDownload(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId = (int)($_POST['part_id'] ?? 0);
        $limit  = (int)($_POST['limit'] ?? 0);
        $overwrite = (string)($_POST['overwrite'] ?? '') === '1';

        // 批量下载必须显式给 limit：不给上限会遍历全部物料，
        // 每个都要访问立创（~2s/个），几十个就超过 nginx fastcgi_read_timeout(60s)
        // → 504，前端只看到网关超时，看不到真实进度。
        // 这里兜一个默认上限，保证单次请求能在超时窗口内跑完。
        if ($partId <= 0 && $limit <= 0) $limit = 20;

        $ids    = $partId > 0 ? [$partId] : [];

        $svc   = new PartDatasheetLocalizer($db, $dataUid, (int)\getWorkspaceId());
        $stats = $svc->download($ids, ['overwrite' => $overwrite, 'limit' => $limit]);

        Response::ok($stats, '已下载 ' . (int)$stats['downloaded'] . ' 份规格书');
    }

    /** POST asset.datasheet_scan — 扫描目录，按文件名里的立创编号自动绑定 */
    private static function handleDatasheetScan(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $dir   = trim((string)($_POST['dir'] ?? ''));
        $limit = (int)($_POST['limit'] ?? 0);
        $overwrite = (string)($_POST['overwrite'] ?? '') === '1';

        $svc   = new DatasheetBinder($db, $dataUid, (int)\getWorkspaceId());
        $stats = $svc->scan($dir, ['overwrite' => $overwrite, 'limit' => $limit]);

        Response::ok($stats, '已绑定 ' . (int)$stats['bound'] . ' 份规格书');
    }

    /**
     * GET asset.datasheet_file — 输出规格书文件
     *
     * 规格书可能存在应用目录之外（设置里配的绝对路径），
     * 而且桌面版 nginx 把整个 uploads/ 禁了（deny all），
     * 所以静态链接打不开 —— 统一走这个接口输出。
     */
    private static function handleDatasheetFile(\PDO $db, int $uid, int $dataUid): void
    {
        $path = trim((string)($_GET['path'] ?? ''));
        if ($path === '') Response::error('缺少 path 参数');

        $abs = self::resolveDatasheetPath($path);
        if ($abs === null) Response::error('文件不存在或无权访问', 404);

        $size = (int)filesize($abs);
        $etag = '"' . md5($abs . '|' . $size . '|' . filemtime($abs)) . '"';
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            \http_response_code(304);
            exit;
        }

        \header('Content-Type: application/pdf');
        \header('Content-Length: ' . $size);
        \header('Content-Disposition: inline; filename="' . rawurlencode(basename($abs)) . '"');
        \header('Cache-Control: private, max-age=600');
        \header('ETag: ' . $etag);
        \header('X-Content-Type-Options: nosniff');
        \readfile($abs);
        exit;
    }

    /**
     * 把规格书路径解析为绝对路径，并确保它在允许的目录内
     *
     * 允许范围：应用根下（相对路径）与规格书保存目录（绝对路径）。
     */
    private static function resolveDatasheetPath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) return null;

        $abs = $path;
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $path)) {
            // 相对路径 → 相对应用根
            $abs = PartDatasheetLocalizer::appRoot() . '/' . ltrim(str_replace('\\', '/', $path), '/');
        }
        $real = @realpath($abs);
        if ($real === false || !is_file($real)) return null;

        // 必须在允许目录内：规格书目录 或 应用根下的 uploads
        $allowed = [];
        $dsDir = @realpath(PartDatasheetLocalizer::resolveDir());
        if ($dsDir !== false) $allowed[] = $dsDir;
        $upDir = @realpath(PartDatasheetLocalizer::appRoot() . '/uploads');
        if ($upDir !== false) $allowed[] = $upDir;

        foreach ($allowed as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/');
            $realN = str_replace('\\', '/', $real);
            if (str_starts_with($realN, $base . '/')) return $real;
        }
        return null;
    }

    /** POST asset.duplicate_part — 复制物料（stock=0 不复制库存） */
    private static function handleDuplicatePart(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId = (int)($_POST['part_id'] ?? 0);
        if ($partId <= 0) Response::error('缺少物料ID');

        // 读取原物料
        $stmt = $db->prepare("SELECT * FROM parts WHERE id=? AND user_id=? AND status!='deleted'");
        $stmt->execute([$partId, $dataUid]);
        $src = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$src) Response::error('物料不存在');

        $newInternalId = ($src['internal_id'] ?? '') . '-COPY';
        $stmt = $db->prepare(
            "INSERT INTO parts (user_id,platform_id,internal_id,platform_part_no,model,product_name,brand,package,parameters,parameters_text,location,stock,avg_cost,last_purchase_price,cost_dirty,remark,low_stock_threshold,customer_part_no,product_type,update_time,status) "
            . "VALUES (?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?, NOW(),'active')"
        );
        $stmt->execute([
            $dataUid, $src['platform_id'], $newInternalId, $src['platform_part_no'],
            $src['model'], $src['product_name'], $src['brand'], $src['package'],
            $src['parameters'], (string)($src['parameters_text'] ?? ''),
            $src['location'], 0, 0, 0, 0,
            $src['remark'], $src['low_stock_threshold'],
            $src['customer_part_no'], $src['product_type'],
        ]);
        $newId = $db->lastInsertId();
        \traceLog($uid, 'duplicate_part', 'part', $newId, "复制自#{$partId}");
        Response::ok(['new_id' => $newId, 'internal_id' => $newInternalId], '复制成功');
    }

    /** POST asset.add_purchase — 手动添加采购记录（不入库） */
    private static function handleAddPurchase(\PDO $db, int $uid, int $dataUid): void
    {
        self::requireEdit();
        $partId    = (int)($_POST['part_id'] ?? 0);
        $unitPrice = (float)($_POST['unit_price'] ?? 0);
        $qty       = (int)($_POST['qty'] ?? 0);
        $orderNo   = trim((string)($_POST['order_no'] ?? ''));
        $orderTime = trim((string)($_POST['order_time'] ?? ''));
        if ($partId <= 0 || $unitPrice <= 0 || $qty <= 0) Response::error('参数无效');
        if ($orderTime === '') $orderTime = date('Y-m-d');

        $stmt = $db->prepare(
            "INSERT INTO price_history (part_id,order_no,unit_price,qty,order_time,import_time) VALUES (?,?,?,?,?,NOW())"
        );
        $stmt->execute([$partId, $orderNo, $unitPrice, $qty, $orderTime]);
        // 更新 last_purchase_price
        $db->prepare("UPDATE parts SET last_purchase_price=?, update_time=NOW() WHERE id=?")
           ->execute([$unitPrice, $partId]);
        \markCostDirty($db, $partId);
        \traceLog($uid, 'add_purchase_record', 'part', $partId, "手动添加采购记录：{$qty}个@{$unitPrice}");
        Response::ok(['id' => $db->lastInsertId()]);
    }
}
