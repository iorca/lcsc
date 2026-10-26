<?php
declare(strict_types=1);

namespace App\Service;

use App\Helper\Spec\SpecNormalizer;

/** 替代料兼容性规则引擎 */
final class AlternativeRuleEngine
{
    /** 校验两物料是否兼容（可作为替代料绑定） */
    public static function checkCompatibility(array $source, array $target, ?array $rule = null): array
    {
        $rule = $rule ?? self::defaultRule();
        $warnings = [];

        $cmp = SpecNormalizer::compare(self::specsOf($source), self::specsOf($target));
        foreach (array_merge($cmp['conflicts'], $cmp['downgrades']) as $warning) {
            if (empty($rule['package_match']) && str_contains($warning, '封装')) continue;
            $warnings[] = $warning;
        }

        // 物料类型（product_type 字段）单独校验
        if (!empty($rule['type_match'])) {
            $srcType = (string)($source['product_type'] ?? '');
            $tgtType = (string)($target['product_type'] ?? '');
            if ($srcType !== '' && $tgtType !== '' && $srcType !== $tgtType) {
                $warnings[] = "物料类型不一致：原 {$srcType} vs 替代 {$tgtType}";
            }
        }

        return [
            'compatible' => count($warnings) === 0,
            'warnings'   => $warnings,
            'rule'       => $rule,
        ];
    }

    /** 物料行 → 规格结构（兼容性判定输入） */
    private static function specsOf(array $part): array
    {
        return SpecNormalizer::extract([
            'parameters_text' => (string)($part['parameters_text'] ?? ''),
            'product_name'    => (string)($part['product_name'] ?? ''),
            'category'        => (string)($part['category'] ?? $part['category_name'] ?? ''),
            'package'         => (string)($part['package'] ?? ''),
        ]);
    }

    /** 构建兼容性规则 JSON */
    public static function buildRule(bool $packageMatch = true, bool $typeMatch = true, array $paramTolerance = []): array
    {
        return [
            'package_match'   => $packageMatch,
            'type_match'      => $typeMatch,
            'param_tolerance' => $paramTolerance,
        ];
    }

    /** 默认兼容性规则 */
    public static function defaultRule(): array
    {
        return self::buildRule(true, true, [
            'resistance' => ['ratio' => 0.2],
            'capacitance' => ['ratio' => 0.2],
            'voltage'    => ['min_ratio' => 1.0],
        ]);
    }

    /** BOM 缺料自动匹配替代料（按优先级排序） */
    public static function findAlternativesForBom(\PDO $db, int $partId, int $qty, int $userId): array
    {
        // 查询已确认的替代料，按优先级排序
        $stmt = $db->prepare(
            "SELECT pa.target_part_id AS part_id, pa.level, pa.priority, pa.compatibility_rule,
                    p.model, p.package, p.product_type, p.parameters_text, p.product_name,
                    (SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id = pc.category_id
                      WHERE pc.part_id = p.id ORDER BY c.parent_id DESC LIMIT 1) AS category_name,
                    p.stock, p.platform_part_no, p.status
             FROM part_alternatives pa
             INNER JOIN parts p ON p.id = pa.target_part_id AND p.user_id = ?
             WHERE pa.source_part_id = ? AND pa.status = 'confirmed'
             ORDER BY pa.priority ASC, pa.level ASC"
        );
        $stmt->execute([$userId, $partId]);
        $alternatives = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 获取原物料信息用于兼容性校验
        $srcStmt = $db->prepare(
            "SELECT p.model, p.package, p.product_type, p.parameters_text, p.product_name,
                    (SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id = pc.category_id
                      WHERE pc.part_id = p.id ORDER BY c.parent_id DESC LIMIT 1) AS category_name
             FROM parts p WHERE p.id = ? AND p.user_id = ?"
        );
        $srcStmt->execute([$partId, $userId]);
        $source = $srcStmt->fetch(\PDO::FETCH_ASSOC);

        $results = [];
        foreach ($alternatives as $alt) {
            $compatCheck = self::checkCompatibility(
                $source ?? [],
                $alt,
                $alt['compatibility_rule'] ? json_decode($alt['compatibility_rule'], true) : null
            );

            $partStatus = $alt['status'] ?? 'active';
            $available = (int)$alt['stock'] >= $qty && self::isPartAvailable($partStatus);

            $results[] = [
                'part_id'          => (int)$alt['part_id'],
                'model'            => $alt['model'],
                'platform_part_no' => $alt['platform_part_no'],
                'stock'            => (int)$alt['stock'],
                'priority'         => (int)$alt['priority'],
                'level'            => $alt['level'],
                'compatible'       => $compatCheck['compatible'],
                'warnings'         => $compatCheck['warnings'],
                'available'        => $available,
            ];
        }

        return $results;
    }

    /** 绑定替代料（含兼容性校验 + 首次匹配人工确认机制） */
    public static function bindAlternative(\PDO $db, int $sourceId, int $targetId, int $userId, string $level = 'full', int $priority = 0, bool $autoConfirm = false): array
    {
        if ($sourceId === $targetId) {
            return ['success' => false, 'message' => '不能将物料绑定为自身的替代料', 'status' => 'rejected'];
        }

        // 检查是否已绑定
        $chk = $db->prepare("SELECT id FROM part_alternatives WHERE source_part_id = ? AND target_part_id = ?");
        $chk->execute([$sourceId, $targetId]);
        if ($chk->fetch()) {
            return ['success' => false, 'message' => '替代关系已存在', 'status' => 'duplicate'];
        }

        // 获取两物料信息
        $partSelect = "SELECT p.model, p.package, p.product_type, p.parameters_text, p.product_name,
                    (SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id = pc.category_id
                      WHERE pc.part_id = p.id ORDER BY c.parent_id DESC LIMIT 1) AS category_name
             FROM parts p WHERE p.id = ? AND p.user_id = ?";
        $srcStmt = $db->prepare($partSelect);
        $srcStmt->execute([$sourceId, $userId]);
        $source = $srcStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$source) {
            return ['success' => false, 'message' => '原物料不存在', 'status' => 'not_found'];
        }

        $tgtStmt = $db->prepare($partSelect);
        $tgtStmt->execute([$targetId, $userId]);
        $target = $tgtStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$target) {
            return ['success' => false, 'message' => '替代物料不存在', 'status' => 'not_found'];
        }

        // 兼容性校验
        $compatCheck = self::checkCompatibility($source, $target);
        $compatRule = json_encode($compatCheck['rule'], JSON_UNESCAPED_UNICODE);

        // 首次匹配状态：非自动确认时标记为 pending
        $status = $autoConfirm ? 'confirmed' : 'pending';
        $confirmedAt = $autoConfirm ? date('Y-m-d H:i:s') : null;

        // 正向绑定
        $db->prepare(
            "INSERT INTO part_alternatives (source_part_id, target_part_id, level, compatibility_rule, priority, status, confirmed_at, remark, created_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$sourceId, $targetId, $level, $compatRule, $priority, $status, $confirmedAt,
            $compatCheck['compatible'] ? '' : '兼容性警告：' . implode('; ', $compatCheck['warnings'])]);

        // 反向绑定（双向互绑）
        $chkRev = $db->prepare("SELECT id FROM part_alternatives WHERE source_part_id = ? AND target_part_id = ?");
        $chkRev->execute([$targetId, $sourceId]);
        if (!$chkRev->fetch()) {
            $db->prepare(
                "INSERT INTO part_alternatives (source_part_id, target_part_id, level, compatibility_rule, priority, status, confirmed_at, remark, created_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([$targetId, $sourceId, $level, $compatRule, $priority, $status, $confirmedAt,
                $compatCheck['compatible'] ? '' : '兼容性警告：' . implode('; ', $compatCheck['warnings'])]);
        }

        // 首次匹配未确认时，写入待处理队列
        if ($status === 'pending') {
            $db->prepare(
                "INSERT INTO pending_items (part_id, reason, detail, status, created_at)
                 VALUES (?, 'alt_unconfirmed', ?, 'pending', NOW())
                 ON DUPLICATE KEY UPDATE detail = VALUES(detail), status = 'pending'"
            )->execute([$sourceId, json_encode([
                'target_part_id' => $targetId,
                'warnings'       => $compatCheck['warnings'],
            ], JSON_UNESCAPED_UNICODE)]);
        }

        $message = $status === 'pending'
            ? '替代料已绑定，需人工确认后生效'
            : '替代料绑定成功';

        return [
            'success'  => true,
            'message'  => $message,
            'status'   => $status,
            'warnings' => $compatCheck['compatible'] ? [] : $compatCheck['warnings'],
        ];
    }

    /** 确认待确认的替代料绑定 */
    public static function confirmAlternative(\PDO $db, int $altId, int $userId): bool
    {
        $stmt = $db->prepare("SELECT source_part_id, target_part_id FROM part_alternatives WHERE id = ? AND status = 'pending'");
        $stmt->execute([$altId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return false;

        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE part_alternatives SET status = 'confirmed', confirmed_at = ? WHERE id = ?")
           ->execute([$now, $altId]);

        // 同步确认反向绑定
        $db->prepare("UPDATE part_alternatives SET status = 'confirmed', confirmed_at = ? WHERE source_part_id = ? AND target_part_id = ? AND status = 'pending'")
           ->execute([$now, $row['target_part_id'], $row['source_part_id']]);

        // 从待处理队列移除
        $db->prepare("UPDATE pending_items SET status = 'fixed', resolved_at = NOW() WHERE part_id = ? AND reason = 'alt_unconfirmed'")
           ->execute([$row['source_part_id']]);

        return true;
    }

    // ── 私有辅助 ──

    private static function isPartAvailable(string $status): bool
    {
        return in_array($status, ['active', ''], true);
    }
}
