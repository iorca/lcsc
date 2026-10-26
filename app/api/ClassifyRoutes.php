<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Category\Service\CategoryAutoClassifier;
use Modules\Inventory\Service\AiClassifierService;
use Modules\Inventory\Service\LcscEnrichService;

/**
 * 分类相关 API
 *
 *   classify.auto        规则自动归类（品牌/商品类型 → 封装格式 建两级分类树）
 *   classify.lcsc_enrich 按立创编号批量补全商品类型（及缺失的品牌/封装）
 *   classify.ai           调用大模型自动判定商品类型/品牌/封装
 */
final class ClassifyRoutes
{
    public static function handle(string $method, array $user): void
    {
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();
        $db      = \getDB();

        switch ($method) {
            case 'auto':
                self::handleAuto($db, $uid, $dataUid);
                break;
            case 'lcsc_enrich':
                self::handleLcscEnrich($db, $uid, $dataUid);
                break;
            case 'ai':
                self::handleAi($db, $uid, $dataUid);
                break;
            default:
                Response::error('未知 classify 方法：' . $method, 404);
        }
    }

    // ── 规则自动归类 ────────────────────────────────────────────

    private static function handleAuto(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('无编辑权限', 403);

        $ids        = self::readIds();
        $dims       = self::readDims();
        $overwrite  = !empty($_POST['overwrite']);

        $svc = new CategoryAutoClassifier($db, $dataUid);
        if (empty($ids)) {
            $scope = (string)($_POST['scope'] ?? $_GET['scope'] ?? '');
            if ($scope === 'all') {
                // 全量重跑：给所有物料补齐分类链
                // （例如后补了「商品类型」维度、或已按品牌归类过需要再挂类型链时）
                $limit = max(1, (int)($_POST['limit'] ?? 5000));
                $st = $db->prepare("SELECT id FROM parts WHERE user_id=? AND status<>'deleted' ORDER BY id LIMIT ?");
                $st->execute([$dataUid, $limit]);
                $ids = array_map(static fn(array $r): int => (int)$r['id'], $st->fetchAll(\PDO::FETCH_ASSOC));
                $res = $svc->classify($ids, ['dims' => $dims, 'overwrite' => $overwrite]);
            } else {
                $res = $svc->classifyUnclassified((int)($_POST['limit'] ?? 2000));
            }
        } else {
            $res = $svc->classify($ids, ['dims' => $dims, 'overwrite' => $overwrite]);
        }
        \traceLog($uid, 'auto_classify', 'part', 0,
            "规则归类 关联:{$res['linked']} 新建一级:{$res['new_top']} 新建二级:{$res['new_leaf']}");
        Response::ok($res, '自动归类完成：关联 ' . $res['linked'] . ' 项，新建分类 ' . ($res['new_top'] + $res['new_leaf']) . ' 个');
    }

    // ── 立创补全商品类型 ────────────────────────────────────────

    private static function handleLcscEnrich(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('无编辑权限', 403);

        $ids       = self::readIds();
        $overwrite = !empty($_POST['overwrite']);
        $limit     = (int)($_POST['limit'] ?? 200);

        $svc = new LcscEnrichService($db, $dataUid, (int)\getWorkspaceId());
        if (empty($ids)) {
            // 未指定 ids → 取所有缺商品类型（或缺品牌/封装）且是立创编号的物料
            $st = $db->prepare(
                "SELECT id FROM parts
                 WHERE user_id=? AND status<>'deleted'
                   AND platform_part_no LIKE 'C%'
                   AND (product_type IS NULL OR product_type='' OR brand IS NULL OR brand='' OR package IS NULL OR package='')
                 ORDER BY id ASC LIMIT ?"
            );
            $st->execute([$dataUid, max(1, $limit)]);
            $ids = array_map(static fn(array $r): int => (int)$r['id'], $st->fetchAll(\PDO::FETCH_ASSOC));
        }

        $res = $svc->enrich($ids, ['overwrite' => $overwrite, 'limit' => $limit]);
        \traceLog($uid, 'lcsc_enrich', 'part', 0,
            "立创补全 检查:{$res['checked']} 更新:{$res['updated']} 失败:{$res['failed']}");
        Response::ok($res, '立创补全完成：更新 ' . $res['updated'] . ' / ' . $res['checked'] . ' 项');
    }

    // ── AI 自动分类 ──────────────────────────────────────────────

    private static function handleAi(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\hasPermission('can_edit')) Response::error('无编辑权限', 403);
        if (!\hasPermission('can_batch')) Response::error('无批量操作权限', 403);
        if ((string)\getSetting('ai_enabled', '0') !== '1') {
            Response::error('AI 智能分类未启用（请到「系统设置 → AI 智能分类」开启）', 403);
        }

        $ids = self::readIds();
        if (empty($ids)) Response::error('请先选择要识别的物料', 2);

        $svc = new AiClassifierService($db, $dataUid, (int)\getWorkspaceId());
        $res = $svc->classify($ids, [
            'overwrite' => !empty($_POST['overwrite']),
            'batch'      => (int)($_POST['batch'] ?? 15),
        ]);
        \traceLog($uid, 'ai_classify', 'part', 0,
            "AI分类 识别:{$res['checked']} 成功:{$res['updated']} 失败:{$res['failed']}");
        Response::ok($res, 'AI 识别完成：成功 ' . $res['updated'] . ' / ' . $res['checked'] . ' 项');
    }

    // ── 公共：读 POST 里的 ids[] ────────────────────────────────

    private static function readIds(): array
    {
        $ids = $_POST['ids'] ?? $_GET['ids'] ?? [];
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }
        if (!is_array($ids)) return [];
        $out = [];
        foreach ($ids as $v) {
            $i = (int)$v;
            if ($i > 0) $out[$i] = $i;
        }
        return array_values($out);
    }

    /** 一级分类维度：brand（品牌）/ product_type（商品类型） */
    private static function readDims(): array
    {
        $raw = $_POST['dims'] ?? $_GET['dims'] ?? [];
        if (is_string($raw)) $raw = array_filter(explode(',', $raw));
        if (!is_array($raw)) $raw = [];
        $allow = [CategoryAutoClassifier::DIM_BRAND, CategoryAutoClassifier::DIM_TYPE];
        $dims  = array_values(array_intersect($allow, $raw));
        return empty($dims) ? $allow : $dims;
    }
}
