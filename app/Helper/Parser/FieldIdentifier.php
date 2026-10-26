<?php
declare(strict_types=1);

namespace App\Helper\Parser;

/** 字段识别器 */
final class FieldIdentifier
{
    private \PDO $db;
    private int $workspaceId;
    private array $keywordCache = [];

    public function __construct(\PDO $db, int $workspaceId = 1)
    {
        $this->db = $db;
        $this->workspaceId = $workspaceId;
        $this->loadKeywords();
    }

    /** 识别表头行（批量） */
    public function recognizeHeaders(array $headers, string $sourceType = 'custom'): array
    {
        $userRules = $this->loadUserRules($sourceType);
        $result = [];
        $usedFields = [];

        foreach ($headers as $idx => $header) {
            $header = trim((string)$header);
            if ($header === '') {
                $result[$idx] = ['field' => '', 'confidence' => 0, 'rule' => '空列'];
                continue;
            }

            // 优先级1：用户规则
            $match = $this->matchUserRule($header, $userRules);
            if ($match) {
                $result[$idx] = array_merge($match, ['column' => $idx, 'header' => $header]);
                $usedFields[$match['field']] = true;
                continue;
            }

            // 优先级2：系统关键词
            $match = $this->matchKeyword($header);
            if ($match && !isset($usedFields[$match['field']])) {
                $result[$idx] = array_merge($match, ['column' => $idx, 'header' => $header]);
                $usedFields[$match['field']] = true;
                continue;
            }

            // 优先级3：格式推断
            $match = $this->guessByFormat($header);
            if ($match && !isset($usedFields[$match['field']])) {
                $result[$idx] = array_merge($match, ['column' => $idx, 'header' => $header]);
                $usedFields[$match['field']] = true;
                continue;
            }

            $result[$idx] = ['column' => $idx, 'header' => $header, 'field' => '', 'confidence' => 0, 'rule' => '未识别'];
        }

        return $result;
    }

    /** 识别单个列名 → 标准字段 */
    public function identifyField(string $columnName): array
    {
        $col = trim($columnName);

        // 系统关键词匹配：从全部字段里挑「最具体」的候选（精确 > 更长关键词），

        $m = FieldRegistry::bestAlias($col, $this->keywordCache);
        if ($m) {
            return [
                'field'      => (string)$m['field'],
                'confidence' => $this->keywordConfidence($col, (string)$m['keyword'], (bool)$m['exact']),
                'rule'       => ($m['exact'] ? '关键词精确匹配: ' : '关键词包含匹配: ') . $m['keyword'],
            ];
        }

        return ['field' => '', 'confidence' => 0.0, 'rule' => ''];
    }

    /** 仅按用户规则识别（供模板识别在别名/关键词之前优先套用历史人工纠正） */
    public function recognizeByUserRules(array $headers, string $sourceType = 'custom'): array
    {
        $rules = $this->loadUserRules($sourceType);
        if (empty($rules)) return [];
        $out = [];
        foreach ($headers as $idx => $header) {
            $header = trim((string)$header);
            if ($header === '') continue;
            $m = $this->matchUserRule($header, $rules);
            if ($m) $out[(int)$idx] = $m;
        }
        return $out;
    }

    /** 归一化到注册表 canonical 字段；非可写字段（位号等）与无法归一化的旧名统一返回 '' */
    private static function canon(string $field, string $header = ''): string
    {
        $f = FieldRegistry::canonicalField($field, $header);
        return FieldRegistry::isWritableField($f) ? $f : '';
    }

    /** 保存字段映射到数据库 */
    public function saveMapping(int $documentId, array $recognitions): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO import_mapping (document_id, source_column, source_header, target_field, confidence, match_rule)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($recognitions as $rec) {
                $stmt->execute([
                    $documentId,
                    $rec['column'] ?? 0,
                    $rec['header'] ?? '',
                    $rec['field'] ?? $rec['standard_field'] ?? '',
                    $rec['confidence'] ?? 0,
                    $rec['rule'] ?? '',
                ]);
            }
        } catch (\Throwable) {}
    }

    /** 记录用户修正（学习） */
    public function learnUserRule(string $sourceType, string $sourceColumn, string $targetField): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO user_mapping_rules (workspace_id, source_type, source_column, target_field, hit_count)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE hit_count = hit_count + 1
            ");
            $stmt->execute([$this->workspaceId, $sourceType, $sourceColumn, $targetField]);
        } catch (\Throwable) {}
    }

    // ── 内部方法 ──

    private function loadKeywords(): void
    {
        try {
            $stmt = $this->db->query("SELECT standard_field, keyword, priority FROM field_keywords ORDER BY priority ASC");
            $this->keywordCache = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                // 库内字段名同样归一化（旧库未迁移时也不让旧名流向调用方）
                $field = self::canon((string)$row['standard_field'], (string)$row['keyword']);
                if ($field === '') continue;
                if (!isset($this->keywordCache[$field])) {
                    $this->keywordCache[$field] = [];
                }
                $this->keywordCache[$field][] = (string)$row['keyword'];
            }
            if (empty($this->keywordCache)) $this->keywordCache = $this->getBuiltInKeywords();
        } catch (\Throwable) {
            $this->keywordCache = $this->getBuiltInKeywords();
        }
    }

    private function loadUserRules(string $sourceType): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT source_column, target_field
                FROM user_mapping_rules
                WHERE workspace_id = ? AND source_type = ?
                ORDER BY hit_count DESC
            ");
            $stmt->execute([$this->workspaceId, $sourceType]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
    }

    private function matchUserRule(string $header, array $rules): ?array
    {
        foreach ($rules as $rule) {
            if ($this->headerMatches($header, $rule['source_column'])) {
                $field = self::canon((string)$rule['target_field'], $header);
                if ($field === '') continue;
                return ['field' => $field, 'confidence' => 0.99, 'rule' => '用户规则:' . $rule['source_column']];
            }
        }
        return null;
    }

    private function matchKeyword(string $header): ?array
    {

        $m = FieldRegistry::bestAlias($header, $this->keywordCache);
        if (!$m) return null;
        $field = self::canon((string)$m['field'], $header);
        if ($field === '') return null;
        return [
            'field'      => $field,
            'confidence' => $this->keywordConfidence($header, (string)$m['keyword'], (bool)$m['exact']),
            'rule'       => '关键词:' . $m['keyword'],
        ];
    }

    /** 关键词命中置信度（精确 0.98；包含按长度比例 0.70~0.80） */
    private function keywordConfidence(string $header, string $keyword, bool $exact): float
    {
        if ($exact) return 0.98;
        $hl = mb_strlen($header);
        $kl = mb_strlen($keyword);
        if ($hl === 0 || $kl === 0) return 0.7;
        return 0.7 + (0.1 * (min($hl, $kl) / max($hl, $kl)));
    }

    private function headerMatches(string $header, string $keyword): bool
    {
        $normalize = function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/[\s\-_\.\/\\\()（）]/u', '', $s);
            return $s;
        };
        $h = $normalize($header);
        $k = $normalize($keyword);
        if ($h === '' || $k === '') return false;
        if ($h === $k) return true;
        if (mb_strpos($h, $k) !== false) return true;
        if (mb_strpos($k, $h) !== false) return true;
        return false;
    }

    private function guessByFormat(string $header): ?array
    {
        if (preg_match('/(price|cost|价|￥|\$)/i', $header)) {
            return ['field' => 'unit_price', 'confidence' => 0.55, 'rule' => '含价格关键词'];
        }
        if (preg_match('/(qty|count|数量|个数)/i', $header)) {
            return ['field' => 'quantity', 'confidence' => 0.55, 'rule' => '含数量关键词'];
        }
        if (preg_match('/(date|time|日期|时间)/i', $header)) {
            return ['field' => 'order_time', 'confidence' => 0.50, 'rule' => '含日期关键词'];
        }
        return null;
    }

    /** 内置兜底关键词（注册表派生，仅可写字段；库内 field_keywords 为空/不可用时使用） */
    private function getBuiltInKeywords(): array
    {
        return FieldRegistry::fieldAliases();
    }
}
