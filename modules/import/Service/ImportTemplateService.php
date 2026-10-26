<?php
declare(strict_types=1);

namespace Modules\Import\Service;

use App\Helper\Parser\FieldIdentifier;
use App\Helper\Parser\FieldRegistry;

/** ImportTemplateService — 平台导入模板 */
final class ImportTemplateService
{
    /** 默认匹配键优先级（模板未定义时回落；立创内置模板即用此默认） */
    public const DEFAULT_MATCH_KEYS = ['platform_part_no', 'model'];

    private \PDO $db;
    private int $uid;
    private int $wsId;

    public function __construct(\PDO $db, int $uid, int $wsId = 1)
    {
        $this->db   = $db;
        $this->uid  = $uid;
        $this->wsId = $wsId;
    }

    /** 系统字段白名单（可映射目标） */
    public static function fieldLabels(): array
    {
        return SheetTableParser::fieldLabels();
    }

    /** 内置立创商城模板（别名表来自字段注册表，唯一事实来源；供 seed 与「重置为内置」共用） */
    public static function builtinLcscProfile(): array
    {
        return [
            'sig'        => '',
            'headers'    => ['订单编号','商品编号','品牌','商品类型','商品名称','商品型号','封装格式','型号发货数量','单价（人民币含税）','下单时间','客户料号','备注'],
            'columns'    => [],
            'ignored'    => [],
            'aliases'    => FieldRegistry::fieldAliases(),
            'match_keys' => self::DEFAULT_MATCH_KEYS,
            'builtin'    => true,
        ];
    }

    /** 读取平台的匹配键优先级（物料身份判定口径） */
    public function matchKeys(int $platformId): array
    {
        $tpl = $this->get($platformId);
        $fallback = [];
        foreach ((array)($tpl['profiles'] ?? []) as $p) {
            $keys = self::sanitizeMatchKeys((array)($p['match_keys'] ?? []));
            if (empty($keys)) continue;

            if (empty($p['builtin'])) return $keys;
            if (empty($fallback)) $fallback = $keys;
        }
        return $fallback ?: self::DEFAULT_MATCH_KEYS;
    }

    /** 匹配键净化：归一化 + 去重 + 仅保留可写且可作匹配键的字段；空 → 返回空数组（由调用方回落默认） */
    public static function sanitizeMatchKeys(array $keys): array
    {

        static $allowed = ['platform_part_no' => 1, 'model' => 1, 'customer_part_no' => 1];
        $out = [];
        foreach ($keys as $k) {
            $f = FieldRegistry::canonicalField((string)$k);
            if ($f === '' || !FieldRegistry::isWritableField($f) || !isset($allowed[$f])) continue;
            if (!in_array($f, $out, true)) $out[] = $f;
        }
        return $out;
    }

    /** 为指定工作区种子内置立创模板（幂等；仅在无记录时创建） */
    public static function seedBuiltin(\PDO $db, int $uid, int $wsId): void
    {
        $pl = $db->prepare("SELECT id, code FROM platforms WHERE user_id=? AND workspace_id=? AND code='lcsc' LIMIT 1");
        $pl->execute([$uid, $wsId]);
        $platform = $pl->fetch(\PDO::FETCH_ASSOC);
        if (!$platform) return;

        $ex = $db->prepare("SELECT id FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
        $ex->execute([$wsId, (int)$platform['id']]);
        if ((int)$ex->fetchColumn() > 0) return;

        $payload = \json_encode(['profiles' => [self::builtinLcscProfile()]], JSON_UNESCAPED_UNICODE);
        $ins = $db->prepare("INSERT INTO import_template (workspace_id, platform_id, platform_code, profiles_json, is_builtin, revision, updated_by)
                             VALUES (?, ?, ?, ?, 1, 1, ?)");
        $ins->execute([$wsId, (int)$platform['id'], (string)$platform['code'], $payload, $uid]);
    }

    // ── 读取 ───────────────────────────────────────

    /** 平台模板记录 */
    public function get(int $platformId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
        $stmt->execute([$this->wsId, $platformId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        $profiles = \json_decode((string)$row['profiles_json'], true);
        $row['profiles'] = (is_array($profiles) && isset($profiles['profiles'])) ? $profiles['profiles'] : [];
        return $row;
    }

    /** 是否已配置导入模板（导入页据此硬性禁用未配置平台） */
    public function hasTemplate(int $platformId): bool
    {

        $stmt = $this->db->prepare("SELECT profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
        $stmt->execute([$this->wsId, $platformId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) return false;
        $p = \json_decode((string)$raw, true);
        return is_array($p['profiles'] ?? null) && count($p['profiles']) > 0;
    }

    /** 平台模板状态表（平台列表徽标用） */
    public function listPlatformStatus(): array
    {
        $out = [];
        try {
            $stmt = $this->db->prepare("SELECT platform_id, profiles_json, is_builtin, revision FROM import_template WHERE workspace_id=?");
            $stmt->execute([$this->wsId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $p = \json_decode((string)$r['profiles_json'], true);
                $profiles = is_array($p['profiles'] ?? null) ? $p['profiles'] : [];
                $out[(int)$r['platform_id']] = [
                    'has'        => count($profiles) > 0,
                    'is_builtin' => (int)$r['is_builtin'] === 1,
                    'formats'    => count($profiles),
                    'revision'   => (int)$r['revision'],
                ];
            }
        } catch (\Throwable $e) { /* 表未建时返回空 */ }
        return $out;
    }

    /** 模板已登记的全部表头（供解析时给「表头行探测」加权） */
    public function expectedHeaders(int $platformId): array
    {
        $tpl = $this->get($platformId);
        if (!$tpl) return [];
        $out = [];
        foreach ($tpl['profiles'] as $p) {
            foreach ((array)($p['headers'] ?? []) as $h) {
                $h = trim((string)$h);
                if ($h !== '') $out[$h] = true;
            }
            foreach ((array)($p['aliases'] ?? []) as $aliases) {
                foreach ((array)$aliases as $a) {
                    $a = trim((string)$a);
                    if ($a !== '') $out[$a] = true;
                }
            }
        }
        return array_keys($out);
    }

    // ── 识别 ────────────────────────────────────────

    /** 按平台模板识别一组表头的字段映射 */
    public function detect(int $platformId, array $headers, string $sourceType = 'custom'): array
    {
        $tpl      = $this->get($platformId);
        $profiles = $tpl['profiles'] ?? [];

        $result = [];
        $used   = [];

        // ① 签名精确命中（用户显式保存的模板，最高优先级）
        $sig = self::sig($headers);
        foreach ($profiles as $p) {
            if (!empty($p['sig']) && $p['sig'] === $sig && !empty($p['columns'])) {
                foreach ($headers as $i => $h) {
                    $f = self::canonField((string)($p['columns'][(string)$i] ?? $p['columns'][$i] ?? ''), (string)$h);
                    if (in_array($i, (array)($p['ignored'] ?? []), true)) $f = '';
                    $result[$i] = $this->entry($f, $f === '' ? 0.0 : 1.0, $f === '' ? '已忽略' : '模板签名精确匹配', (string)$h);
                }
                ksort($result);   // 键序归位：保证返回 JSON 为数组（前端按下标取值）
                return $result;
            }
        }

        $this->applyUserRules($result, $used, $headers, $sourceType);

        // ③ 模板已知表头高重合 → 按表头文本对齐
        $bestProfile = null; $bestOverlap = 0.0;
        foreach ($profiles as $p) {
            $known = (array)($p['headers'] ?? []);
            if (empty($known)) continue;
            $hit = 0;
            foreach ($headers as $h) {
                if ($h === '') continue;
                foreach ($known as $k) {
                    if (SheetTableParser::matchText((string)$h, (string)$k)) { $hit++; break; }
                }
            }
            $ratio = $hit / max(1, count(array_filter($headers, static fn($v) => $v !== '')));
            if ($ratio > $bestOverlap) { $bestOverlap = $ratio; $bestProfile = $p; }
        }
        if ($bestProfile !== null && $bestOverlap >= 0.6) {
            $known = (array)$bestProfile['headers'];
            $filledNow = 0;
            foreach ($headers as $i => $h) {
                if (self::decided($result, (int)$i)) continue;   // 用户规则已定
                $field = '';
                foreach ($known as $j => $k) {
                    if ($k !== '' && SheetTableParser::matchText((string)$h, (string)$k)) {
                        $cand = self::canonField((string)($bestProfile['columns'][(string)$j] ?? ''), (string)$h);
                        if ($cand !== '' && !isset($used[$cand])) { $field = $cand; break; }
                    }
                }
                if ($field !== '') { $used[$field] = true; $filledNow++; }
                $result[$i] = $this->entry($field, $field === '' ? 0.0 : 0.90, $field === '' ? '未识别' : '模板表头匹配', (string)$h);
            }
            if ($filledNow > 0) { ksort($result); return $result; }
        }

        // ④ 模板别名包含匹配（内置立创模板走这里）

        $aliasMap = [];
        foreach ($profiles as $p) {
            foreach ((array)($p['aliases'] ?? []) as $f => $aliases) {
                foreach ((array)$aliases as $a) {
                    $a = trim((string)$a);
                    if ($a !== '') $aliasMap[(string)$f][] = $a;
                }
            }
        }
        $aliasHit = false;
        foreach ($headers as $i => $h) {
            if (self::decided($result, (int)$i)) continue;   // 用户规则已定
            $m     = FieldRegistry::bestAlias((string)$h, $aliasMap, $used);
            $field = $m ? (string)$m['field'] : '';
            if ($field !== '') { $used[$field] = true; $aliasHit = true; }
            $result[$i] = $this->entry($field, $field === '' ? 0.0 : 0.85, $field === '' ? '未识别' : '模板别名匹配', (string)$h);
        }
        if ($aliasHit) {
            // 别名命中但仍有空列 → 用关键词补齐未命中的列
            $this->fillByKeywords($result, $headers, $used, $sourceType);
            ksort($result);
            return $result;
        }

        // ⑤ 系统识别兜底（用户规则 → 关键词 → 格式推断）
        $identifier = new FieldIdentifier($this->db, $this->wsId);
        $recs = $identifier->recognizeHeaders($headers, $sourceType);
        foreach ($headers as $i => $h) {
            if (self::decided($result, (int)$i)) continue;   // 用户规则已定
            $r = $recs[$i] ?? null;
            $field = (string)($r['field'] ?? '');
            // 跳过已被前面占用的字段
            if ($field !== '' && isset($used[$field])) $field = '';
            if ($field !== '') $used[$field] = true;
            $result[$i] = $this->entry($field, (float)($r['confidence'] ?? 0.0), (string)($r['rule'] ?? '未识别'), (string)$h);
        }
        ksort($result);
        return $result;
    }

    /** 用户规则预置（历史纠正优先于模板别名/关键词；与 $used 去重） */
    private function applyUserRules(array &$result, array &$used, array $headers, string $sourceType): void
    {
        try {
            $identifier = new FieldIdentifier($this->db, $this->wsId);
            $hits = $identifier->recognizeByUserRules($headers, $sourceType);
            foreach ($hits as $i => $m) {
                $field = (string)($m['field'] ?? '');
                if ($field === '' || isset($used[$field])) continue;
                $used[$field] = true;
                $result[$i] = $this->entry($field, (float)($m['confidence'] ?? 0.99), (string)($m['rule'] ?? '用户规则'), (string)($headers[$i] ?? ''));
            }
        } catch (\Throwable $e) { /* 用户规则不可用时不影响识别 */ }
    }

    // ── 保存 ──────────────────────────────────────

    /** 保存模板（合并 profile 并 revision+1） */
    public function save(int $platformId, string $platformCode, array $headers, array $columns, array $ignored = [], array $aliases = [], array $matchKeys = []): int
    {
        $tpl = $this->get($platformId);
        $profiles = $tpl['profiles'] ?? [];

        // 入库前字段名归一化（旧名绝不写进模板）
        $canonColumns = [];
        foreach ($columns as $idx => $field) {
            $canonColumns[(string)$idx] = self::canonField((string)$field, (string)($headers[(int)$idx] ?? ''));
        }
        $columns = $canonColumns;

        // 自动从表头生成别名（每个字段补充其列名作为别名）
        if (empty($aliases)) {
            foreach ($columns as $idx => $field) {
                if ($field === '') continue;
                $h = (string)($headers[(int)$idx] ?? '');
                if ($h !== '') $aliases[$field][] = $h;
            }
        }

        $newProfile = [
            'sig'        => self::sig($headers),
            'headers'    => array_values($headers),
            'columns'    => array_map('strval', $columns),
            'ignored'    => array_values($ignored),
            'aliases'    => $aliases,
            'match_keys' => self::sanitizeMatchKeys($matchKeys) ?: self::DEFAULT_MATCH_KEYS,
        ];

        $replaced = false;
        foreach ($profiles as $k => $p) {
            if (!empty($p['sig']) && $p['sig'] === $newProfile['sig']) { $profiles[$k] = $newProfile; $replaced = true; break; }
        }
        if (!$replaced) $profiles[] = $newProfile;

        // 别名合并到首个 profile（保持内置别名不丢失）
        if (!empty($profiles[0]['builtin']) && !empty($newProfile['aliases'])) {
            $merged = (array)($profiles[0]['aliases'] ?? []);
            foreach ($newProfile['aliases'] as $f => $list) {
                $merged[$f] = array_values(array_unique(array_merge((array)($merged[$f] ?? []), (array)$list)));
            }
            $profiles[0]['aliases'] = $merged;
        }

        $raw = \json_decode((string)($tpl['profiles_json'] ?? ''), true);
        if (!is_array($raw)) $raw = [];
        $raw['profiles'] = array_values($profiles);
        $payload = \json_encode($raw, JSON_UNESCAPED_UNICODE);
        $revision = max(1, (int)($tpl['revision'] ?? 1)) + 1;

        if ($tpl) {
            $stmt = $this->db->prepare("UPDATE import_template SET profiles_json=?, revision=?, updated_by=?, updated_time=NOW() WHERE id=?");
            $stmt->execute([$payload, $revision, $this->uid, (int)$tpl['id']]);
            return $revision;
        }

        $stmt = $this->db->prepare("INSERT INTO import_template (workspace_id, platform_id, platform_code, profiles_json, is_builtin, revision, updated_by)
                                    VALUES (?, ?, ?, ?, 0, ?, ?)");
        $stmt->execute([$this->wsId, $platformId, $platformCode, $payload, $revision, $this->uid]);
        return $revision;
    }

    /** 重置为内置模板（仅立创等有内置模板的平台可用） */
    public function resetToBuiltin(int $platformId): bool
    {
        $tpl = $this->get($platformId);
        if (!$tpl) return false;
        // 保留同一行的扫码词典 `qr_aliases`，只重置 Excel 模板段
        $raw = \json_decode((string)($tpl['profiles_json'] ?? ''), true);
        if (!is_array($raw)) $raw = [];
        $raw['profiles'] = [self::builtinLcscProfile()];
        $payload = \json_encode($raw, JSON_UNESCAPED_UNICODE);
        $stmt = $this->db->prepare("UPDATE import_template SET profiles_json=?, is_builtin=1, revision=1, updated_by=?, updated_time=NOW() WHERE id=?");
        $stmt->execute([$payload, $this->uid, (int)$tpl['id']]);
        return true;
    }

    /** 删除平台 Excel 模板（保留扫码词典） */
    public function remove(int $platformId): bool
    {
        $tpl = $this->get($platformId);
        if (!$tpl) return false;

        $raw = \json_decode((string)($tpl['profiles_json'] ?? ''), true);
        if (!is_array($raw)) $raw = [];
        unset($raw['profiles']);
        $raw = array_filter($raw, static fn($v) => $v !== [] && $v !== null && $v !== '');

        if (!empty($raw)) {
            $stmt = $this->db->prepare("UPDATE import_template SET profiles_json=?, updated_time=NOW() WHERE id=?");
            $stmt->execute([\json_encode($raw, JSON_UNESCAPED_UNICODE), (int)$tpl['id']]);
        } else {
            $stmt = $this->db->prepare("DELETE FROM import_template WHERE id=?");
            $stmt->execute([(int)$tpl['id']]);
        }
        return true;
    }

    // ─ 工具 ───────────────────────────────────────

    /** 表头签名（排序后 md5，忽略空列与顺序差异） */
    public static function sig(array $headers): string
    {
        $norm = [];
        foreach ($headers as $h) {
            $h = trim((string)$h);
            if ($h === '') continue;
            $norm[] = mb_strtolower($h);
        }
        sort($norm);
        return md5(implode('|', $norm));
    }

    private function entry(string $field, float $confidence, string $rule, string $header): array
    {
        return ['field' => $field, 'confidence' => $confidence, 'rule' => $rule, 'header' => $header];
    }

    /** 字段名归一化到注册表 canonical（历史模板可能存有 category/part_number 等旧名）；非可写字段返回 '' */
    private static function canonField(string $field, string $header = ''): string
    {
        $field = trim($field);
        if (str_starts_with($field, ImportService::EXT_PREFIX)) {
            $key = ImportService::normalizeExtKey(substr($field, strlen(ImportService::EXT_PREFIX)));
            return $key === '' ? '' : ImportService::EXT_PREFIX . $key;
        }
        $f = FieldRegistry::canonicalField($field, $header);
        return FieldRegistry::isWritableField($f) ? $f : '';
    }

    /** 该列是否已由更高优先级路径定下明确字段 */
    private static function decided(array $result, int $i): bool
    {
        return isset($result[$i]) && (string)($result[$i]['field'] ?? '') !== '';
    }

    /** 用关键词补齐未识别列（别名路径的补充） */
    private function fillByKeywords(array &$result, array $headers, array &$used, string $sourceType): void
    {
        try {
            $identifier = new FieldIdentifier($this->db, $this->wsId);
            $recs = $identifier->recognizeHeaders($headers, $sourceType);
            foreach ($headers as $i => $h) {
                if (($result[$i]['field'] ?? '') !== '') continue;
                $field = (string)($recs[$i]['field'] ?? '');
                if ($field === '' || isset($used[$field])) continue;
                $used[$field] = true;
                $result[$i] = $this->entry($field, (float)($recs[$i]['confidence'] ?? 0.6), '关键词兜底: ' . (string)($recs[$i]['rule'] ?? ''), (string)$h);
            }
        } catch (\Throwable $e) { /* 兜底失败不影响主流程 */ }
    }
}
