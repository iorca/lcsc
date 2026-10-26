<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use App\Service\AlternativeRuleEngine;

require_once __DIR__ . '/../../modules/inventory/Service/PartManager.php';

/** 替代料管理 API 路由 */
final class AlternativeRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db  = \getDB();
        $uid = (int)$user['id'];

        switch ($method) {
            case 'suggest':
                // 取数：替代料差异化推荐
                Response::ok(self::querySuggest(
                    $db,
                    $uid,
                    (int)\getDataUserId(),
                    (string)($_GET['model'] ?? ''),
                    (string)($_GET['package'] ?? ''),
                    (string)($_GET['category'] ?? ''),
                    intval($_GET['exclude'] ?? 0),
                    10
                ));
                break;

            case 'suggest_by_fields':
                // 取数：按封装近似推荐替代料
                Response::ok(self::querySuggestByPackage(
                    $db,
                    $uid,
                    (int)\getDataUserId(),
                    trim((string)($_GET['package'] ?? '')),
                    max(0, (int)($_GET['exclude_id'] ?? 0))
                ));
                break;

            case 'bind':      self::bind($db, $uid); break;
            case 'unbind':    self::unbind($db, $uid); break;
            case 'confirm':   self::confirm($db, $uid); break;
            case 'check':     self::check($db, $uid); break;
            case 'list':      self::listAlternatives($db, $uid); break;
            case 'bom_match': self::bomMatch($db, $uid); break;
            case 'reorder':   self::reorder($db, $uid); break;
            default:          Response::error('未知 alt 方法：' . $method, 404);
        }
    }

    /** 权限校验：替代料写操作与 BOM 管理同权限域（can_export） */
    private static function requireBomPerm(): void
    {
        if (!\hasPermission('can_export')) {
            Response::error('权限不足', 403);
        }
    }

    /** 取数：替代料差异化推荐 */
    public static function querySuggest(\PDO $db, int $uid, int $dataUid, string $model, string $package, string $category, int $excludeId = 0, int $limit = 10): array
    {
        return (new \PartManager($db, $uid, $dataUid))->suggestAlternatives($model, $package, $category, $excludeId, $limit);
    }

    /** 取数：按封装近似推荐替代料 */
    public static function querySuggestByPackage(\PDO $db, int $uid, int $dataUid, string $package, int $excludeId = 0): array
    {
        if ($package === '') return ['alternatives' => []];
        return ['alternatives' => (new \PartManager($db, $uid, $dataUid))->suggestAlternativesByPackage($package, $excludeId)];
    }

    /** 绑定替代料 */
    private static function bind(\PDO $db, int $uid): void
    {
        self::requireBomPerm();
        $sourceId  = (int)($_POST['source_part_id'] ?? 0);
        $targetId  = (int)($_POST['target_part_id'] ?? 0);
        $level     = trim((string)($_POST['level'] ?? 'full'));
        $priority  = (int)($_POST['priority'] ?? 0);
        $autoConfirm = !empty($_POST['auto_confirm']);

        if ($sourceId <= 0 || $targetId <= 0) {
            Response::error('请指定原物料和替代物料ID');
        }

        if (!in_array($level, ['full', 'partial', 'reference'], true)) {
            $level = 'full';
        }

        $dataUid = \getDataUserId();
        $result = AlternativeRuleEngine::bindAlternative($db, $sourceId, $targetId, $dataUid, $level, $priority, $autoConfirm);

        if ($result['success']) {
            \traceLog($uid, 'alt_bind', 'part', $sourceId, "绑定替代料 #{$targetId} level={$level} status={$result['status']}");
        }

        Response::ok($result);
    }

    /** 解绑替代料 */
    private static function unbind(\PDO $db, int $uid): void
    {
        self::requireBomPerm();
        $sourceId = (int)($_POST['source_part_id'] ?? 0);
        $targetId = (int)($_POST['target_part_id'] ?? 0);

        if ($sourceId <= 0 || $targetId <= 0) {
            Response::error('请指定原物料和替代物料ID');
        }

        // 双向删除
        $db->prepare("DELETE FROM part_alternatives WHERE (source_part_id = ? AND target_part_id = ?) OR (source_part_id = ? AND target_part_id = ?)")
           ->execute([$sourceId, $targetId, $targetId, $sourceId]);

        \traceLog($uid, 'alt_unbind', 'part', $sourceId, "解绑替代料 #{$targetId}");
        Response::ok(['message' => '替代料已解绑']);
    }

    /** 确认待确认替代料 */
    private static function confirm(\PDO $db, int $uid): void
    {
        self::requireBomPerm();
        $altId = (int)($_POST['alt_id'] ?? 0);
        if ($altId <= 0) Response::error('请指定替代料关联ID');

        $ok = AlternativeRuleEngine::confirmAlternative($db, $altId, $uid);
        if ($ok) {
            \traceLog($uid, 'alt_confirm', 'part_alternatives', $altId, '确认替代料绑定');
            Response::ok(['message' => '替代料已确认']);
        } else {
            Response::error('确认失败：记录不存在或已确认');
        }
    }

    /** 校验兼容性 */
    private static function check(\PDO $db, int $uid): void
    {
        $sourceId = (int)($_POST['source_part_id'] ?? 0);
        $targetId = (int)($_POST['target_part_id'] ?? 0);

        if ($sourceId <= 0 || $targetId <= 0) Response::error('请指定物料ID');

        $dataUid = \getDataUserId();
        $partSelect = "SELECT p.model, p.package, p.product_type, p.parameters_text, p.product_name,
                    (SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id = pc.category_id
                      WHERE pc.part_id = p.id ORDER BY c.parent_id DESC LIMIT 1) AS category_name
             FROM parts p WHERE p.id = ? AND p.user_id = ?";
        $srcStmt = $db->prepare($partSelect);
        $srcStmt->execute([$sourceId, $dataUid]);
        $source = $srcStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$source) Response::error('原物料不存在');

        $tgtStmt = $db->prepare($partSelect);
        $tgtStmt->execute([$targetId, $dataUid]);
        $target = $tgtStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$target) Response::error('替代物料不存在');

        $result = AlternativeRuleEngine::checkCompatibility($source, $target);
        Response::ok($result);
    }

    /** 列出物料替代料 */
    private static function listAlternatives(\PDO $db, int $uid): void
    {
        $partId = (int)($_POST['part_id'] ?? 0);
        if ($partId <= 0) Response::error('请指定物料ID');

        $dataUid = \getDataUserId();
        $stmt = $db->prepare(
            "SELECT pa.id, pa.target_part_id, pa.level, pa.priority, pa.status, pa.compatibility_rule,
                    p.model, p.brand, p.package, p.stock, p.platform_part_no, p.status AS part_status
             FROM part_alternatives pa
             INNER JOIN parts p ON p.id = pa.target_part_id
             WHERE pa.source_part_id = ?
             ORDER BY pa.priority ASC, pa.level ASC"
        );
        $stmt->execute([$partId]);
        $alternatives = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::ok(['part_id' => $partId, 'alternatives' => $alternatives]);
    }

    /** BOM缺料自动匹配替代料 */
    private static function bomMatch(\PDO $db, int $uid): void
    {
        $partId = (int)($_POST['part_id'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 1);

        if ($partId <= 0) Response::error('请指定物料ID');

        $dataUid = \getDataUserId();
        $results = AlternativeRuleEngine::findAlternativesForBom($db, $partId, $qty, $dataUid);
        Response::ok(['part_id' => $partId, 'qty' => $qty, 'alternatives' => $results]);
    }

    /** 调整优先级 */
    private static function reorder(\PDO $db, int $uid): void
    {
        self::requireBomPerm();
        $orders = json_decode($_POST['orders'] ?? '[]', true);
        if (!is_array($orders)) Response::error('参数格式错误');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE part_alternatives SET priority = ? WHERE id = ?");
            foreach ($orders as $item) {
                $id = (int)($item['id'] ?? 0);
                $priority = (int)($item['priority'] ?? 0);
                if ($id > 0) $stmt->execute([$priority, $id]);
            }
            $db->commit();
            \traceLog($uid, 'alt_reorder', 'part_alternatives', 0, '调整替代料优先级');
            Response::ok(['message' => '优先级已更新']);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('更新失败：' . $e->getMessage());
        }
    }
}
