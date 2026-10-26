<?php
declare(strict_types=1);

namespace Modules\Import\Service;

/** PartMatcher — 物料匹配器 */
final class PartMatcher
{
    /** 高于此值视为自动匹配 */
    public const THRESHOLD_AUTO = 0.95;
    /** 高于此值视为可能匹配（需人工确认） */
    public const THRESHOLD_LIKELY = 0.70;

    private \PDO $db;
    private int $dataUid;
    private int $wsId;

    public function __construct(\PDO $db, int $dataUid, int $wsId = 1)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId    = $wsId;
    }

    /** 匹配单个标准化物料 */
    public function match(array $item): array
    {
        $model  = trim((string)($item['model'] ?? $item['part_number'] ?? ''));
        $brand  = trim((string)($item['brand'] ?? ''));
        $name   = trim((string)($item['product_name'] ?? $item['name'] ?? ''));
        $ppn    = trim((string)($item['platform_part_no'] ?? ''));
        $params = trim((string)($item['parameters_text'] ?? ''));

        // 优先级1：精确型号
        if ($model !== '') {
            $r = $this->matchByModel($model);
            if ($r) return $r;
        }
        // 优先级2：平台编号
        if ($ppn !== '') {
            $r = $this->matchByPlatformPartNo($ppn);
            if ($r) return $r;
        }
        // 优先级3：品牌 + 型号
        if ($model !== '' && $brand !== '') {
            $r = $this->matchByBrandAndModel($brand, $model);
            if ($r) return $r;
        }
        // 优先级4：名称相似度
        if ($name !== '') {
            $r = $this->matchByTextSimilarity('product_name', $name, $brand, '名称相似度');
            if ($r) return $r;
        }
        // 优先级5：参数相似度
        if ($params !== '') {
            $r = $this->matchByTextSimilarity('parameters_text', $params, '', '参数相似度');
            if ($r) return $r;
        }

        return ['status' => 'pending', 'part_id' => null, 'confidence' => 0.0, 'rule' => '未匹配', 'matched' => null];
    }

    /** 批量匹配 */
    public function matchBatch(array $items): array
    {
        $out = [];
        foreach ($items as $it) $out[] = $this->match((array)$it);
        return $out;
    }

    /** 状态标签 */
    public static function getStatusLabel(string $status): string
    {
        return [
            'matched'  => '已匹配',
            'created'  => '已创建',
            'conflict' => '需确认',
            'pending'  => '待匹配',
        ][$status] ?? $status;
    }

    // ── 各级匹配实现 ────────────────────────────────

    /** 优先级1：精确型号（原样 + 去分隔符归一化） */
    private function matchByModel(string $model): ?array
    {
        $norm = self::normalizeModel($model);
        $stmt = $this->db->prepare("
            SELECT id, model, product_name, brand FROM parts
            WHERE user_id = ? AND workspace_id = ? AND status != 'deleted'
              AND (model = ?
                   OR REPLACE(REPLACE(REPLACE(LOWER(model),'-',''),' ',''),'_','') = ?)
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$this->dataUid, $this->wsId, $model, strtolower($norm)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        return $this->hit('matched', $row, 0.98, '精确型号匹配: ' . (string)$row['model']);
    }

    /** 优先级2：平台编号 */
    private function matchByPlatformPartNo(string $ppn): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, model, product_name, brand, platform_part_no FROM parts
            WHERE user_id = ? AND workspace_id = ? AND status != 'deleted'
              AND platform_part_no = ?
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$this->dataUid, $this->wsId, $ppn]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        return $this->hit('matched', $row, 0.92, '平台编号匹配: ' . $ppn);
    }

    /** 优先级3：品牌 + 型号（型号/名称包含） */
    private function matchByBrandAndModel(string $brand, string $model): ?array
    {
        $like = '%' . $model . '%';
        $stmt = $this->db->prepare("
            SELECT id, model, product_name, brand FROM parts
            WHERE user_id = ? AND workspace_id = ? AND status != 'deleted'
              AND brand = ? AND (model LIKE ? OR product_name LIKE ?)
            ORDER BY id ASC LIMIT 1
        ");
        $stmt->execute([$this->dataUid, $this->wsId, $brand, $like, $like]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        return $this->hit('matched', $row, 0.85, "品牌+型号匹配: {$brand} / {$model}");
    }

    /** 优先级4/5：文本相似度（名称 / 参数） */
    private function matchByTextSimilarity(string $column, string $text, string $brand, string $ruleName): ?array
    {
        if (!in_array($column, ['product_name', 'parameters_text'], true)) return null;

        $sql    = "SELECT id, model, product_name, brand, {$column} AS cmp FROM parts
                   WHERE user_id = ? AND workspace_id = ? AND status != 'deleted' AND {$column} != ''";
        $params = [$this->dataUid, $this->wsId];
        if ($brand !== '') {
            $sql .= " AND brand = ?";
            $params[] = $brand;
        }
        $sql .= " ORDER BY id DESC LIMIT 300";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$candidates) return null;

        $needle   = self::normalizeText($text);
        $best     = null;
        $bestScore = 0.0;
        foreach ($candidates as $c) {
            $score = self::similarity($needle, self::normalizeText((string)$c['cmp']));
            if ($score > $bestScore) { $bestScore = $score; $best = $c; }
        }
        if (!$best || $bestScore < self::THRESHOLD_LIKELY) return null;

        $status = $bestScore >= self::THRESHOLD_AUTO ? 'matched' : 'conflict';
        $label  = $ruleName . ': ' . mb_substr((string)$best[$column === 'product_name' ? 'product_name' : 'cmp'], 0, 40);
        return $this->hit($status, $best, round($bestScore, 2), $label);
    }

    // ── 工具 ───────────────────────────────────────

    /** 组装匹配结果 */
    private function hit(string $status, array $row, float $confidence, string $rule): array
    {
        return [
            'status'     => $status,
            'part_id'    => (int)$row['id'],
            'confidence' => $confidence,
            'rule'       => $rule,
            'matched'    => $row,
        ];
    }

    /** 型号归一化：STM32F103 / STM32-F103 / stm32 f103 视为同一 */
    public static function normalizeModel(string $model): string
    {
        return preg_replace('/[\s\-_\.\/]/', '', mb_strtoupper(trim($model))) ?? '';
    }

    /** 文本归一化（相似度比较用） */
    public static function normalizeText(string $text): string
    {
        $s = mb_strtolower(trim($text));
        return preg_replace('/[\s\-_\.\/()（）,，;；:：]/u', '', $s) ?? '';
    }

    /** 相似度（0~1） */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $percent = 0.0;
        similar_text($a, $b, $percent);
        return $percent / 100.0;
    }

    /** 从商品名称中提取型号（原 ImportEngine::extractPartNumberFromName 并入） */
    public static function extractModelFromName(string $name): string
    {
        if (preg_match('/\b([A-Z]{2,}[\d]+[A-Z\d\-]*)\b/', $name, $m)) return $m[1];
        return '';
    }
}
