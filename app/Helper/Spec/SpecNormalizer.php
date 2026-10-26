<?php
declare(strict_types=1);

namespace App\Helper\Spec;

/** SpecNormalizer — 元器件规格归一化与替代兼容性判定（纯计算，不访问数据库） */
final class SpecNormalizer
{
    /** 分类关键词 → 主参数族（决定「值必须一致」的硬约束族） */
    private const CATEGORY_MAIN = [
        // 电阻
        '电阻'   => 'resistance',
        '电阻类' => 'resistance',
        '贴片电阻' => 'resistance',
        'resistor' => 'resistance',
        'resistance' => 'resistance',
        // 刻意不收 'res'：三字母短词易在通用语境误命中（同 'cap' 的取舍）
        // 电容
        '电容'   => 'capacitance',
        '电容类' => 'capacitance',
        '陶瓷电容' => 'capacitance',
        'electrolytic capacitor' => 'capacitance',
        'capacitor' => 'capacitance',
        'capacitance' => 'capacitance',
        // 刻意不收 'cap'：三字母短词在 "CAPS LOCK" 等通用语境会误命中，

        '电感'   => 'inductance',
        '电感类' => 'inductance',
        '磁珠'   => 'inductance',
        'inductor' => 'inductance',
        'inductance' => 'inductance',
        // 刻意不收 'ind'：三字母短词易在通用语境误命中（同 'cap' 的取舍）
        // 晶振 / 振荡器
        '晶振'   => 'frequency',
        '晶振类' => 'frequency',
        '石英晶振' => 'frequency',
        '晶体谐振器' => 'frequency',
        '振荡器' => 'frequency',
        '压电晶振' => 'frequency',
        'crystal' => 'frequency',
        'crystal oscillator' => 'frequency',
        'oscillator' => 'frequency',
        'frequency' => 'frequency',
        // 保险丝 / 熔断器
        '保险丝' => 'current',
        '熔断器' => 'current',
        '保险丝类' => 'current',
        'fuse' => 'current',
        'current' => 'current',
    ];

    /** 英文关键词的上下文限定词（出现这些词说明是通用语境而非元器件分类） */
    private const EN_CONTEXT_HINT = [
        'current', 'fuse',
    ];

    /** 出现这些词即视为元器件语境，可放宽 current / fuse 的判定 */
    private const EN_PART_TERMS = [
        'part', 'component', 'electronic', 'electronics', 'smd', 'smt', 'through-hole', 'tht',
        '贴片', '插件', '元件', '电子', '元器件', '零件',
    ];

    /** 可作为主参数的族（分类已明确时） */
    private const MAIN_FAMILIES = ['resistance', 'capacitance', 'inductance', 'frequency', 'current'];

    /** 无分类时可反推的主参数族 */
    private const MAIN_INFER = ['resistance', 'capacitance', 'inductance', 'current'];

    /** 限值类参数：替代值 ≥ 原值 才算不降级 */
    private const LIMIT_FAMILIES = ['voltage', 'power', 'current'];

    /** 参与判定的参数族顺序（固定顺序保证结果可复现） */
    private const ITEM_ORDER = ['voltage', 'power', 'current', 'frequency', 'tolerance', 'tempco'];

    private const FAMILY_LABEL = [
        'resistance'  => '阻值',
        'capacitance' => '容值',
        'inductance'  => '感值',
        'frequency'   => '频率',
        'voltage'     => '耐压',
        'power'       => '功率',
        'current'     => '电流',
        'tolerance'   => '精度',
        'tempco'      => '温漂',
    ];

    /** SI 前缀 → 倍数（大小写敏感：m=毫，M=兆） */
    private const SI_PREFIX = [
        'p' => 1.0e-12, 'n' => 1.0e-9, 'u' => 1.0e-6, 'μ' => 1.0e-6, 'µ' => 1.0e-6, 'm' => 1.0e-3,
        '' => 1.0, 'k' => 1.0e3, 'K' => 1.0e3, 'M' => 1.0e6, 'G' => 1.0e9,
    ];

    /** 单位字母 → 参数族 */
    private const UNIT_FAMILY = [
        'OHM' => 'resistance', 'Ω' => 'resistance', 'R' => 'resistance',
        'F'   => 'capacitance', 'H' => 'inductance', 'HZ' => 'frequency',
        'V'   => 'voltage', 'W' => 'power', 'A' => 'current',
    ];

    /** 公制 → 英制贴片尺寸（公制 token 优先识别，规避 0603 公制/英制歧义） */
    private const METRIC_TO_IMPERIAL = [
        '1005' => '0402', '1608' => '0603', '2012' => '0805', '3216' => '1206',
        '3225' => '1210', '4532' => '1812', '5025' => '2010', '6332' => '2512',
    ];

    private const IMPERIAL_SIZE = ['0201', '0402', '0603', '0805', '1206', '1210', '1812', '2010', '2512'];

    /** 具名键 → 参数族（键名归一化后匹配：去空格/下划线/连字符、转小写） */
    private const KEY_FAMILY = [
        'resistance' => 'resistance', 'res' => 'resistance', 'ohm' => 'resistance',
        '阻值' => 'resistance', '电阻' => 'resistance',
        'capacitance' => 'capacitance', 'cap' => 'capacitance', '容值' => 'capacitance', '电容' => 'capacitance',
        'inductance' => 'inductance', 'ind' => 'inductance', '感值' => 'inductance', '电感' => 'inductance',
        'voltage' => 'voltage', 'vw' => 'voltage', '耐压' => 'voltage', '额定电压' => 'voltage', '工作电压' => 'voltage',
        'power' => 'power', '功率' => 'power',
        'current' => 'current', '电流' => 'current', '额定电流' => 'current',
        'frequency' => 'frequency', 'freq' => 'frequency', '频率' => 'frequency',
        'tolerance' => 'tolerance', 'accuracy' => 'tolerance', '精度' => 'tolerance',
        'tempco' => 'tempco', 'ppm' => 'tempco', '温漂' => 'tempco',
        'dielectric' => 'dielectric', '介质' => 'dielectric',
    ];

    /** 介质优劣序（越大越好，用于「更优 / 降级」判定） */
    private const DIELECTRIC_RANK = [
        'Y5V' => 1, 'Z5U' => 1, 'X5R' => 2, 'X7R' => 3, 'C0G' => 4, 'NP0' => 4,
    ];

    /** E24 有效数字（3 位编码解码护栏：只解标准阻容编码，杜绝把数量/年份当参数） */
    private const E24 = [
        '10', '11', '12', '13', '15', '16', '18', '20', '22', '24', '27', '30',
        '33', '36', '39', '43', '47', '51', '56', '62', '68', '75', '82', '91',
    ];

    /** 封装归一化 */
    public static function package(string $pkg): array
    {
        $raw = trim($pkg);
        $out = ['raw' => $raw, 'family' => '', 'kind' => 'other', 'variant' => ''];
        if ($raw === '') return $out;

        $s = strtoupper($raw);
        $compact = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';

        // ① 公制贴片尺寸优先（1608 → 英制 0603）
        foreach (self::METRIC_TO_IMPERIAL as $metric => $imperial) {
            if (str_contains($compact, (string)$metric)) {
                $out['family'] = $imperial;
                $out['kind']   = 'chip';
                return $out;
            }
        }
        // ② 英制贴片尺寸
        foreach (self::IMPERIAL_SIZE as $imperial) {
            if (preg_match('/\b' . $imperial . '\b/', $s)) {
                $out['family'] = $imperial;
                $out['kind']   = 'chip';
                return $out;
            }
        }

        if (preg_match('/\b(DO|SOD|SMA|SMB|SMC)[\s\-_]*(\d{2,3})\s*([A-Z]{0,3})/', $s, $m)) {
            $out['family']  = $m[1] . ltrim($m[2], '0') . strtoupper($m[3]);
            $out['kind']    = 'ic';
            $out['variant'] = $m[1];
            return $out;
        }

        if (preg_match('/\b(SOIC|SOP|HSOP|TSSOP|SSOP|MSOP|QSOP|SOT|SOD|SO|QFN|DFN|LQFN|LQFP|TQFP|VQFP|HQFP|QFP|BGA|DIP|TO)[\s\-_]*(\d{1,3})(?:[\s\-_]+(\d{1,2}))?/', $s, $m)) {
            $prefix = $m[1];
            $pins   = ltrim($m[2], '0');
            $pins   = $pins === '' ? '0' : $pins;
            $family = self::icFamily($prefix, $pins);
            if (($m[3] ?? '') !== '') $family .= '-' . $m[3];
            $out['family']  = $family;
            $out['kind']    = 'ic';
            $out['variant'] = $prefix;
            return $out;
        }
        // ⑤ 无编号封装（贴片/插件/自定义）：归一化文本作为族
        $out['family'] = preg_replace('/[^\p{L}\p{N}]/u', '', $s) ?? '';
        return $out;
    }

    /** 生成封装 SQL 粗筛用的 LIKE 关键词（用于无分类时的候选召回） */
    public static function packageSearchTerms(string $pkg): array
    {
        $p = self::package($pkg);
        if ($p['family'] === '') return [];

        $terms = [];
        if ($p['kind'] === 'chip') {
            $terms[] = $p['family'];
            foreach (self::METRIC_TO_IMPERIAL as $metric => $imperial) {
                if ($imperial === $p['family']) $terms[] = (string)$metric;
            }
        } elseif ($p['kind'] === 'ic') {
            if (preg_match('/^([A-Z]+)(\d+)/', $p['family'], $m)) {
                $prefix = $m[1];
                $pins   = ltrim($m[2], '0');
                $families = $prefix === 'SO' ? ['SO', 'SOP', 'SOIC'] : [$prefix];
                foreach ($families as $f) {
                    $terms[] = $f . '-' . $pins;
                    $terms[] = $f . $pins;
                }
            }
            $terms[] = $p['family'];
        } else {
            $terms[] = $p['family'];
        }

        $terms = array_values(array_unique(array_filter($terms, static fn($t) => $t !== '')));
        return array_slice($terms, 0, 6);
    }

    /** IC 封装族：SO/SOP/SOIC 收敛为同族，引脚数统一两位补零（SOP8 ≡ SOP-8） */
    private static function icFamily(string $prefix, string $pins): string
    {
        if (in_array($prefix, ['SO', 'SOIC', 'SOP'], true)) $prefix = 'SO';
        if (in_array($prefix, ['SO', 'DIP', 'TSSOP', 'SSOP', 'MSOP', 'QSOP'], true)) {
            return $prefix . str_pad($pins, 2, '0', STR_PAD_LEFT);
        }
        return $prefix . $pins;
    }

    /** 提取物料规格 */
    public static function extract(array $part): array
    {
        $paramsText  = trim((string)($part['parameters_text'] ?? ''));
        $productName = trim((string)($part['product_name'] ?? ''));
        $category    = trim((string)($part['category'] ?? ''));
        $package     = trim((string)($part['package'] ?? ''));

        $values  = [];   // family => ['value'=>float,'raw'=>string]
        $keyvals = [];
        $flags   = [];

        // ① 主参数族：分类优先（文本解析需据此启用裸后缀阻值规则）
        $mainFamily = self::mainFamilyByCategory($category);

        // ② 文本：参数文本 + 商品名称
        $text = trim($paramsText . ' ' . $productName);
        if ($text !== '') {
            self::extractFromText($text, $mainFamily, $values, $keyvals, $flags);
        }

        // ③ 主参数族兜底：无分类时由唯一出现的可主参数族反推
        if ($mainFamily === '') {
            $hits = array_values(array_intersect(self::MAIN_INFER, array_keys($values)));
            if (count($hits) === 1) $mainFamily = $hits[0];
        }
        $main = null;
        if ($mainFamily !== '' && isset($values[$mainFamily])) {
            $main = ['family' => $mainFamily, 'value' => $values[$mainFamily]['value'], 'raw' => $values[$mainFamily]['raw']];
        }

        // ④ 拆分：主参数 / 限值参数 / 其余数值降级为具名键值（软判定）
        $items = [];
        foreach (self::ITEM_ORDER as $family) {
            if ($family === $mainFamily || !isset($values[$family])) continue;

            if ($family === 'frequency') continue;
            $items[$family] = $values[$family];
        }
        foreach ($values as $family => $v) {
            if ($family === $mainFamily || isset($items[$family])) continue;
            $keyvals[$family] = ['key' => self::familyLabel($family), 'value' => $v['value'], 'raw' => $v['raw']];
        }

        return [
            'main_family' => $mainFamily,
            'main'        => $main,
            'items'       => $items,
            'flags'       => $flags,
            'keyvals'     => self::dedupeKeyvals($keyvals),
            'package'     => self::package($package),
        ];
    }

    /** 由物料的各个字段汇总出规范化的「参数文本」（`parts.parameters_text` 的唯一生成口径） */
    public static function buildText(array $part): string
    {
        $raw         = trim((string)($part['parameters'] ?? ''));
        $model       = trim((string)($part['model'] ?? ''));
        $productName = trim((string)($part['product_name'] ?? ''));
        $ptype       = trim((string)($part['category'] ?? ''));
        $package     = trim((string)($part['package'] ?? ''));

        // 扫描文本 = 平台参数列 + 型号 + 商品名称 + 封装。
        // 型号与封装常含规格（「10KΩ」「0402」），漏掉它们等于把参数扔了。
        $scan = trim($raw . ' ' . $model . ' ' . $productName . ' ' . $package);
        if ($scan === '') return '';

        try {
            $spec = self::extract([
                'parameters_text' => $scan,
                'product_name'    => $productName,
                'category'        => $ptype,
                'package'         => $package,
            ]);
        } catch (\Throwable $e) {
            return mb_substr($scan, 0, 500);
        }

        $parts = [];
        if (!empty($spec['main']['raw'])) $parts[] = (string)$spec['main']['raw'];
        foreach ((array)($spec['items'] ?? []) as $it) {
            if (!empty($it['raw'])) $parts[] = (string)$it['raw'];
        }
        if (!empty($spec['flags']['dielectric'])) $parts[] = (string)$spec['flags']['dielectric'];
        foreach ((array)($spec['keyvals'] ?? []) as $kv) {
            if (empty($kv['raw'])) continue;
            $key = trim((string)($kv['key'] ?? ''));
            $parts[] = $key !== '' ? $key . '=' . $kv['raw'] : (string)$kv['raw'];
        }

        if ($package !== '') $parts[] = $package;

        $text = trim(implode(' ', array_unique(array_filter(
            $parts,
            static fn($v): bool => trim((string)$v) !== ''
        ))));
        // 抽不出任何结构化值时回落扫描文本原文（宁可保留原文也不丢数据）
        return mb_substr($text !== '' ? $text : $scan, 0, 500);
    }

    /** 具名键值去重：同一数值被 JSON 键 / 文本 key=value / 单位识别重复登记时只保留一条 */
    private static function dedupeKeyvals(array $keyvals): array
    {
        $out = [];
        $seen = [];
        foreach ($keyvals as $key => $item) {
            $sig = (preg_replace('/\s+/', '', (string)$item['raw']) ?? '') . '|' . var_export($item['value'], true);
            if (isset($seen[$sig])) {
                $prevKey = $seen[$sig];
                if (self::isGenericLabel((string)($out[$prevKey]['key'] ?? '')) && !self::isGenericLabel((string)$item['key'])) {
                    unset($out[$prevKey]);
                    $out[$key] = $item;
                    $seen[$sig] = $key;
                }
                continue;
            }
            $out[$key] = $item;
            $seen[$sig] = $key;
        }
        return $out;
    }

    /** 自动生成的通用标签（非 JSON / 用户提供的语义键名） */
    private static function isGenericLabel(string $key): bool
    {
        return in_array($key, ['mV级电压', 'mA级电流', '阻值', '容值', '感值', '频率', '耐压', '功率', '电流', '精度', '温漂'], true);
    }

    /** 分类名 → 主参数族 */
    public static function mainFamilyByCategory(string $category): string
    {
        $category = trim($category);
        if ($category === '') return '';

        $lower = mb_strtolower($category, 'UTF-8');
        foreach (self::CATEGORY_MAIN as $keyword => $family) {
            if (preg_match('/^[\x{4e00}-\x{9fa5}]/u', $keyword)) {
                // 中文关键词：子串命中（兼容「贴片电阻」「陶瓷电容」等自定义名）
                if (str_contains($category, $keyword)) return $family;
                continue;
            }

            $en = mb_strtolower($keyword, 'UTF-8');

            $pat = '/(?<![a-z0-9])' . preg_quote($en, '/') . '(?:e?s|ors?|ics)?(?![a-z0-9])/';
            if (!preg_match($pat, $lower)) continue;

            if (in_array($en, self::EN_CONTEXT_HINT, true)) {

                if (self::isWholeWord($lower, $en)) return $family;

                if (!self::hasPartContext($lower)) continue;
            }
            return $family;
        }
        return '';
    }

    /** 整串是否恰为该词（允许复数/后缀形态） */
    private static function isWholeWord(string $lower, string $en): bool
    {
        return (bool)preg_match(
            '/^' . preg_quote($en, '/') . '(?:e?s|ors?|ics)?$/',
            $lower
        );
    }

    /** 分类名中是否含元器件语境词（用于限定 current / fuse 这类通用英文词） */
    private static function hasPartContext(string $lower): bool
    {
        foreach (self::EN_PART_TERMS as $term) {
            $t = mb_strtolower($term, 'UTF-8');
            if (preg_match('/^[\x{4e00}-\x{9fa5}]/u', $term)) {
                if (str_contains($lower, $t)) return true;
                continue;
            }
            if (preg_match('/(?<![a-z0-9])' . preg_quote($t, '/') . '(?![a-z0-9])/', $lower)) return true;
        }
        return false;
    }

    /** 参数族中文名 */
    public static function familyLabel(string $family): string
    {
        return self::FAMILY_LABEL[$family] ?? $family;
    }

    /** 单个数值 token 解析（JSON 值 / 带语义键的值） */
    public static function parseQuantity(string $raw, string $familyHint = ''): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // R/K/M 小数记法：4R7 → 4.7Ω、1K5 → 1.5kΩ
        if (preg_match('/^(\d+)\s*([RKM])\s*(\d+)$/i', $raw, $m)) {
            $mult = ['R' => 1.0, 'K' => 1.0e3, 'M' => 1.0e6][strtoupper($m[2])];
            return ['value' => ((float)($m[1] . '.' . $m[3])) * $mult, 'raw' => $raw];
        }
        // 数字 + 可选 SI 前缀 + 单位
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([pnuμµmkKMG]?)\s*(OHM|HZ|Ω|F|H|V|W|A|R)$/iu', $raw, $m)) {
            $unit = strtoupper($m[3]);
            if ($unit === 'Ω') $unit = 'OHM';
            if (!isset(self::UNIT_FAMILY[$unit])) return null;
            if ($familyHint !== '' && self::UNIT_FAMILY[$unit] !== $familyHint) {
                // 键名与单位族不符（如 tolerance:"1%"），交由调用方按纯数值处理
                return null;
            }
            return ['value' => (float)$m[1] * (self::SI_PREFIX[$m[2]] ?? 1.0), 'raw' => $raw];
        }

        if ($familyHint !== '' && preg_match('/^(\d+(?:\.\d+)?)\s*([kKmM]?)$/', $raw, $m)) {
            $mult = $m[2] === '' ? 1.0 : (self::SI_PREFIX[$m[2]] ?? 1.0);
            if ($m[2] === '' && preg_match('/^\d{3}$/', $raw) && in_array($m[1][0] . $m[1][1], self::E24, true)) {
                // 3 位标准编码（104 = 10×10^4 pF / Ω）：仅在键名已明确参数族时解码
                if (in_array($familyHint, ['resistance', 'capacitance'], true)) {
                    $significant = (int)substr($raw, 0, 2);
                    $exponent    = (int)$raw[2];
                    $unitScale   = $familyHint === 'capacitance' ? 1.0e-12 : 1.0;   // 电容编码基准 pF
                    return ['value' => $significant * (10 ** $exponent) * $unitScale, 'raw' => $raw];
                }
                return null;
            }
            return ['value' => (float)$m[1] * $mult, 'raw' => $raw];
        }
        // 百分比（精度）
        if ($familyHint === 'tolerance' && preg_match('/^±?\s*(\d+(?:\.\d+)?)\s*%$/', $raw, $m)) {
            return ['value' => (float)$m[1], 'raw' => $raw];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*%$/', $raw, $m)) {
            return ['value' => (float)$m[1], 'raw' => $raw];
        }
        return null;
    }

    /** 比对原物料与候选物料规格 */
    public static function compare(array $q, array $c): array
    {
        $conflicts = [];
        $matched = [];
        $better = [];
        $downgrades = [];
        $unknowns = [];
        $review = false;

        $paramWeight = 0.0;
        $paramSum    = 0.0;
        $addParam = static function (float $weight, float $score) use (&$paramWeight, &$paramSum): void {
            $paramWeight += $weight;
            $paramSum    += $weight * $score;
        };

        // ── 1. 封装（硬约束：族不同 / 类型不同 直接淘汰）──
        $pq = $q['package'] ?? ['raw' => '', 'family' => '', 'kind' => 'other', 'variant' => ''];
        $pc = $c['package'] ?? ['raw' => '', 'family' => '', 'kind' => 'other', 'variant' => ''];
        $packageScore = 0.5;
        if ($pq['family'] !== '' && $pc['family'] !== '') {
            if ($pq['kind'] !== $pc['kind']) {
                $conflicts[] = "封装类型不同：{$pq['raw']} vs {$pc['raw']}";
            } elseif ($pq['family'] === $pc['family']) {
                if ($pq['variant'] === $pc['variant']) {
                    $packageScore = 1.0;
                    $matched[] = "封装一致 {$pq['family']}";
                } else {
                    $packageScore = 0.6;
                    $downgrades[] = "封装近似：{$pq['raw']} ≈ {$pc['raw']}（引脚间距需确认）";
                    $review = true;
                }
            } else {
                $conflicts[] = "封装不一致：{$pq['raw']} vs {$pc['raw']}";
            }
        } else {
            $unknowns[] = '封装信息不完整，需人工确认';
            $review = true;
        }

        // ── 2. 主参数（硬约束：类型不同 / 值不同 直接淘汰）──
        $qf = (string)($q['main_family'] ?? '');
        $cf = (string)($c['main_family'] ?? '');
        $qm = $q['main'] ?? null;
        $cm = $c['main'] ?? null;
        if ($qf !== '' && $cf !== '' && $qf !== $cf) {
            $conflicts[] = '物料类型不同：' . self::familyLabel($qf) . ' vs ' . self::familyLabel($cf);
        } elseif ($qm !== null && $cm !== null) {
            if (self::valueEqual((float)$qm['value'], (float)$cm['value'])) {
                $matched[] = self::familyLabel($qf) . "一致 {$qm['raw']}";
                $addParam(3.0, 1.0);
            } else {
                $conflicts[] = self::familyLabel($qf) . "不同：{$qm['raw']} vs {$cm['raw']}";
            }
        } elseif ($qf !== '' && $qm !== null && $cm === null) {
            $unknowns[] = '替代料缺' . self::familyLabel($qf) . '，需人工确认';
            $review = true;
            $addParam(3.0, 0.5);
        } elseif ($qf !== '') {
            $addParam(3.0, 0.5);   // 双方均未标注主参数值 → 中性
        }

        // ── 3. 限值 / 精度 / 温漂 ──
        foreach (self::ITEM_ORDER as $family) {
            $qi = $q['items'][$family] ?? null;
            if ($qi === null) continue;
            $label = self::familyLabel($family);
            $ci = $c['items'][$family] ?? null;
            if ($ci === null) {
                $unknowns[] = "替代料未标注{$label}";
                continue;
            }
            $qv = (float)$qi['value'];
            $cv = (float)$ci['value'];

            if ($family === 'tolerance' || $family === 'tempco') {
                if ($cv <= $qv * 1.0000001) {
                    $addParam(1.0, 1.0);
                    if ($cv < $qv * 0.9999999) $better[] = "{$label} {$ci['raw']} 严于 {$qi['raw']}";
                    else $matched[] = "{$label}一致 {$qi['raw']}";
                } else {
                    $downgrades[] = "{$label}放宽：{$qi['raw']} → {$ci['raw']}";
                    $review = true;
                    $addParam(1.0, 0.4);
                }
            } elseif (in_array($family, self::LIMIT_FAMILIES, true)) {
                if ($cv >= $qv * 0.9999999) {
                    $addParam(1.0, 1.0);
                    if ($cv > $qv * 1.0000001) $better[] = "{$label} {$ci['raw']} ≥ {$qi['raw']}";
                    else $matched[] = "{$label}一致 {$qi['raw']}";
                } else {
                    $conflicts[] = "{$label}不足：{$ci['raw']} < {$qi['raw']}";
                }
            } else {
                if (self::valueEqual($qv, $cv)) {
                    $matched[] = "{$label}一致 {$qi['raw']}";
                    $addParam(1.0, 1.0);
                } else {
                    $conflicts[] = "{$label}不同：{$qi['raw']} vs {$ci['raw']}";
                }
            }
        }

        // ── 4. 介质（C0G 可替 X7R，反向为降级）──
        $qdg = (string)($q['flags']['dielectric'] ?? '');
        $cdg = (string)($c['flags']['dielectric'] ?? '');
        if ($qdg !== '' && $cdg !== '') {
            if ($qdg === $cdg) {
                $matched[] = "介质一致 {$qdg}";
                $addParam(1.0, 1.0);
            } else {
                $rq = self::DIELECTRIC_RANK[$qdg] ?? 0;
                $rc = self::DIELECTRIC_RANK[$cdg] ?? 0;
                if ($rc > $rq) {
                    $better[] = "介质 {$cdg} 优于 {$qdg}";
                    $addParam(1.0, 1.0);
                } else {
                    $downgrades[] = "介质降级：{$qdg} → {$cdg}";
                    $review = true;
                    $addParam(1.0, 0.4);
                }
            }
        }

        // ── 5. 具名键值（键名自由，双方同名才比对；差异只降级不淘汰）──
        $qk = $q['keyvals'] ?? [];
        $ck = $c['keyvals'] ?? [];
        $shared = array_intersect_key($qk, $ck);
        ksort($shared);
        $shown = 0;
        foreach ($shared as $key => $qv) {
            $cv = $ck[$key];
            $same = ($qv['value'] !== null && $cv['value'] !== null)
                ? self::valueEqual((float)$qv['value'], (float)$cv['value'])
                : (strcasecmp((string)$qv['raw'], (string)$cv['raw']) === 0);
            if ($same) continue;
            $downgrades[] = "参数 {$qv['key']} 不同：{$qv['raw']} vs {$cv['raw']}";
            $review = true;
            if (++$shown >= 3) break;
        }

        $reasons = [];
        foreach ($matched as $t)    $reasons[] = ['type' => 'match',  'text' => $t];
        foreach ($better as $t)     $reasons[] = ['type' => 'better', 'text' => $t];
        foreach ($downgrades as $t) $reasons[] = ['type' => 'warn',   'text' => $t];
        foreach ($unknowns as $t)   $reasons[] = ['type' => 'info',   'text' => $t];

        return [
            'compatible'    => $conflicts === [],
            'conflicts'     => $conflicts,
            'package_score' => $packageScore,
            'param_score'   => $paramWeight > 0 ? round($paramSum / $paramWeight, 4) : 0.5,
            'review'        => $review,
            'matched'       => $matched,
            'better'        => $better,
            'downgrades'    => $downgrades,
            'unknowns'      => $unknowns,
            'reasons'       => $reasons,
        ];
    }

    private static function extractFromText(string $text, string $mainFamily, array &$values, array &$keyvals, array &$flags): void
    {
        // 分式功率：1/4W、1/8W
        if (preg_match_all('#(?<![0-9])1\s*/\s*(\d+)\s*[Ww]#', $text, $m)) {
            foreach ($m[1] as $n) {
                $n = (int)$n;
                if ($n > 0) self::putValue($values, 'power', ['value' => 1.0 / $n, 'raw' => "1/{$n}W"]);
            }
        }

        if (preg_match_all('/(?<![A-Za-z0-9])(\d+)([RKM])(\d+)(?![0-9])/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mt) {
                $mult = ['R' => 1.0, 'K' => 1.0e3, 'M' => 1.0e6][strtoupper($mt[2])];
                self::putValue($values, 'resistance', [
                    'value' => ((float)($mt[1] . '.' . $mt[3])) * $mult,
                    'raw'   => $mt[0],
                ]);
            }
        }

        if (preg_match_all('/(?<![A-Za-z0-9.])(\d+(?:\.\d+)?)\s*([pnuμµmkKMG]?)\s*(OHM|HZ|Ω|F|H|V|W|A|R)(?![A-Za-z0-9])/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mt) {
                $unit = strtoupper($mt[3]);
                if ($unit === 'Ω') $unit = 'OHM';
                $family = self::UNIT_FAMILY[$unit] ?? '';
                if ($family === '') continue;
                $value = (float)$mt[1] * (self::SI_PREFIX[$mt[2]] ?? 1.0);
                $raw   = trim($mt[0]);
                // mV 级电压 / mA 级电流属信号电平（Vos、Iq），不作耐压/电流硬约束
                if ($family === 'voltage' && $value < 1.0) {
                    $keyvals['v_low'] = ['key' => 'mV级电压', 'value' => $value, 'raw' => $raw];
                    continue;
                }
                if ($family === 'current' && $value < 0.1) {
                    $keyvals['i_low'] = ['key' => 'mA级电流', 'value' => $value, 'raw' => $raw];
                    continue;
                }
                self::putValue($values, $family, ['value' => $value, 'raw' => $raw]);
            }
        }

        if ($mainFamily === 'resistance'
            && preg_match_all('/(?<![A-Za-z0-9.])(\d+(?:\.\d+)?)\s*([kKmM])(?![A-Za-z0-9])/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mt) {
                self::putValue($values, 'resistance', [
                    'value' => (float)$mt[1] * (self::SI_PREFIX[$mt[2]] ?? 1.0),
                    'raw'   => trim($mt[0]),
                ]);
            }
        }

        // 精度：±x% 恒认可；裸 x% 仅在出现精度语义词时认可
        if (preg_match_all('/±\s*(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
            foreach ($m[1] as $v) self::putValue($values, 'tolerance', ['value' => (float)$v, 'raw' => '±' . $v . '%']);
        } elseif (preg_match('/(精度|误差|容差|tolerance|accuracy)/i', $text)
            && preg_match_all('/(?<![0-9.])(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
            foreach ($m[1] as $v) self::putValue($values, 'tolerance', ['value' => (float)$v, 'raw' => $v . '%']);
        }

        // 介质
        if (preg_match('/\b(X7R|X5R|C0G|NP0|NPO|Y5V|Z5U)\b/i', $text, $m)) {
            $dg = strtoupper($m[1]);
            $flags['dielectric'] = $dg === 'NPO' ? 'NP0' : $dg;
        }

        // 温漂（ppm）
        if (preg_match('/(\d+(?:\.\d+)?)\s*ppm/i', $text, $m)) {
            self::putValue($values, 'tempco', ['value' => (float)$m[1], 'raw' => $m[0]]);
        }

        // 具名键值：Vos=2mV、GBW=1MHz
        if (preg_match_all('/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9_]{0,15})\s*[=:]\s*(\d+(?:\.\d+)?)\s*([a-zA-Z%μµ\/]*)/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mt) {
                $key = $mt[1];
                $raw = trim($mt[2] . $mt[3]);
                if ($raw === '') continue;
                $family = self::keyFamily($key);
                if ($family !== '' && $family !== 'dielectric' && in_array($family, self::ITEM_ORDER, true)) {
                    $parsed = self::parseQuantity($raw, $family);
                    if ($parsed !== null) {
                        self::putValue($values, $family, $parsed);
                        continue;
                    }
                }
                $parsed = self::parseQuantity($raw, '');
                $keyvals[self::normalizeKey($key)] = [
                    'key'   => $key,
                    'value' => $parsed['value'] ?? null,
                    'raw'   => $raw,
                ];
            }
        }
    }

    /** 键名 → 参数族 */
    private static function keyFamily(string $key): string
    {
        $k = self::normalizeKey($key);
        if ($k === '') return '';
        if (isset(self::KEY_FAMILY[$k])) return self::KEY_FAMILY[$k];
        foreach (self::KEY_FAMILY as $needle => $family) {
            if (str_contains($k, $needle)) return $family;
        }
        return '';
    }

    private static function normalizeKey(string $key): string
    {
        return preg_replace('/[\s\-_\.\/]/', '', mb_strtolower(trim($key))) ?? '';
    }

    /** 写入数值：限值/精度类取最严（最小）值，其余族取首个出现的值 */
    private static function putValue(array &$values, string $family, array $item): void
    {
        if (!isset($values[$family])) {
            $values[$family] = $item;
            return;
        }
        $keepMin = in_array($family, self::LIMIT_FAMILIES, true) || in_array($family, ['tolerance', 'tempco'], true);
        if ($keepMin && $item['value'] < $values[$family]['value']) {
            $values[$family] = $item;
        }
    }

    /** 浮点等价（相对误差 1e-6） */
    private static function valueEqual(float $a, float $b): bool
    {
        if ($a === $b) return true;
        $scale = max(abs($a), abs($b));
        if ($scale < 1.0e-15) return true;
        return abs($a - $b) / $scale < 1.0e-6;
    }
}
