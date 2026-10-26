<?php
declare(strict_types=1);

namespace App\Service;

use App\Helper\Spec\SpecNormalizer;

/** AlternativeScorer — 替代料加权评分引擎 */
final class AlternativeScorer
{
    public const W_MODEL   = 0.30;
    public const W_PACKAGE = 0.20;
    public const W_PARAM   = 0.35;
    public const W_BRAND   = 0.05;
    public const W_STOCK   = 0.10;

    /** 自动候选阈值 */
    public const THRESHOLD_AUTO = 0.90;
    /** 展示阈值（低于此分不展示） */
    public const THRESHOLD_REVIEW = 0.75;

    /** 评估单个候选物料 */
    public static function evaluate(array $q, array $c): ?array
    {
        $cmp = SpecNormalizer::compare($q['specs'] ?? [], $c['specs'] ?? []);
        if (!$cmp['compatible']) return null;

        $modelScore  = self::modelSimilarity((string)($q['model'] ?? ''), (string)($c['model'] ?? ''));
        $packageScore = (float)$cmp['package_score'];
        $paramScore   = (float)$cmp['param_score'];
        $brandScore   = self::brandScore((string)($q['brand'] ?? ''), (string)($c['brand'] ?? ''));
        $stockScore   = self::stockScore((int)($c['stock'] ?? 0), (string)($c['status'] ?? ''));

        $score = self::W_MODEL * $modelScore
               + self::W_PACKAGE * $packageScore
               + self::W_PARAM * $paramScore
               + self::W_BRAND * $brandScore
               + self::W_STOCK * $stockScore;
        $score = round($score, 4);

        if ($score < self::THRESHOLD_REVIEW) return null;

        $level = ($score >= self::THRESHOLD_AUTO && !$cmp['review']) ? 'auto' : 'review';

        // 理由标签：先规格结论（封装/参数），再品牌与库存可得性
        $reasons = $cmp['reasons'];
        $qBrand = trim((string)($q['brand'] ?? ''));
        $cBrand = trim((string)($c['brand'] ?? ''));
        if ($brandScore >= 1.0) {
            $reasons[] = ['type' => 'info', 'text' => "同品牌 {$cBrand}"];
        } elseif ($qBrand !== '' && $cBrand !== '') {
            $reasons[] = ['type' => 'info', 'text' => "品牌不同：{$qBrand} vs {$cBrand}"];
        }
        $reasons[] = $stockScore >= 1.0
            ? ['type' => 'info', 'text' => '库存 ' . (int)$c['stock']]
            : ['type' => 'warn', 'text' => '无库存，需采购'];
        if ($stockScore > 0.2 && $stockScore < 1.0) {
            $reasons[] = ['type' => 'warn', 'text' => '物料状态异常（停产/归档）'];
        }

        return [
            'score'     => $score,
            'score_pct' => (int)round($score * 100),
            'level'     => $level,
            'reasons'   => $reasons,
            'breakdown' => [
                'model'   => round($modelScore, 3),
                'package' => round($packageScore, 3),
                'param'   => round($paramScore, 3),
                'brand'   => round($brandScore, 3),
                'stock'   => round($stockScore, 3),
            ],
        ];
    }

    /** 型号相似度（0~1） */
    public static function modelSimilarity(string $a, string $b): float
    {
        $a = self::normalizeModel($a);
        $b = self::normalizeModel($b);
        if ($a === '' || $b === '') return 0.4;      // 型号缺失：中性偏低，但不淘汰
        if ($a === $b) return 1.0;
        if (str_starts_with($b, $a) || str_starts_with($a, $b)) return 0.85;

        $lev = 1.0 - (levenshtein($a, $b) / max(strlen($a), strlen($b)));
        $jac = self::tokenJaccard($a, $b);
        return max(0.0, min(1.0, max($lev, $jac * 0.9)));
    }

    private static function normalizeModel(string $model): string
    {
        $model = mb_substr(trim($model), 0, 64);
        return preg_replace('/[\s\-_\.\/]/', '', mb_strtoupper($model)) ?? '';
    }

    private static function tokenJaccard(string $a, string $b): float
    {
        preg_match_all('/[A-Z]+|\d+/', $a, $ma);
        preg_match_all('/[A-Z]+|\d+/', $b, $mb);
        $setA = $ma[0] ?: [];
        $setB = $mb[0] ?: [];
        if (!$setA || !$setB) return 0.0;
        $inter = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));
        return $union > 0 ? $inter / $union : 0.0;
    }

    /** 品牌维度：同品牌 1.0；跨品牌 0.8（电子料跨品牌替代属常态，不重罚）；一方缺失 0.5 */
    private static function brandScore(string $a, string $b): float
    {
        $a = self::normalizeBrand($a);
        $b = self::normalizeBrand($b);
        if ($a === '' || $b === '') return 0.5;
        return $a === $b ? 1.0 : 0.8;
    }

    private static function normalizeBrand(string $brand): string
    {
        return preg_replace('/[\s\-_\.]/', '', mb_strtoupper(trim($brand))) ?? '';
    }

    /** 库存可得性：有库存 1.0；无库存 0.2；非在售状态（停产/归档）再打 4 折 */
    private static function stockScore(int $stock, string $status): float
    {
        $score = $stock > 0 ? 1.0 : 0.2;
        $status = strtolower(trim($status));
        if ($status !== '' && $status !== 'active') $score *= 0.4;
        return $score;
    }
}
