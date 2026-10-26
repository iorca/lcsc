<?php
declare(strict_types=1);

namespace App\Helper\Parser;

/** FieldRegistry — 导入字段注册表（全链路唯一事实来源） */
final class FieldRegistry
{
    /** 字段注册表：映射目标字段 => 元信息 */
    public const FIELD_REGISTRY = [
        'platform_part_no' => [
            'label'   => '商品编号（平台编号）',
            'kind'    => 'text',
            'aliases' => ['商品编号', '平台编号', '料号', '物料编号', '产品编号', '商品编码', 'MPN', 'Part No', 'Manufacturer Part Number'],
        ],
        'parameters'       => [
            'label'   => '参数',
            'kind'    => 'text',
            'aliases' => ['参数', '规格参数', '参数值', '特性', 'Properties'],
        ],
        'model'            => [
            'label'   => '型号',
            'kind'    => 'text',
            'aliases' => ['型号', '规格型号', '商品型号', '产品型号', '器件型号', 'Model', 'Value', '规格'],
        ],
        'product_name'     => [
            'label'   => '商品名称',
            'kind'    => 'text',
            'aliases' => ['商品名称', '名称', '品名', '产品名称', '物料名称', 'Description', '描述', '商品描述'],
        ],
        'product_type'     => [
            'label'   => '分类',
            'kind'    => 'category',
            'aliases' => ['商品类型', '分类', '物料分类', '类型', 'Category', 'Type'],
        ],
        'brand'            => [
            'label'   => '品牌',
            'kind'    => 'text',
            'aliases' => ['品牌', 'Brand', 'Manufacturer', '制造商', '厂家'],
        ],
        'package'          => [
            'label'   => '封装',
            'kind'    => 'text',
            'aliases' => ['封装', '封装格式', 'Footprint', 'Package', 'Case'],
        ],
        'quantity'         => [
            'label'   => '数量',
            'kind'    => 'number',
            'aliases' => ['数量', 'Quantity', 'Qty', '用量', '需求数量', '发货数量', '订购数量', '购买数量', '采购数量'],
        ],
        'unit_price'       => [
            'label'   => '单价',
            'kind'    => 'price',
            'aliases' => ['单价', '含税单价', '单价（人民币含税）', 'Unit Price', 'Price', '采购单价', '价格', '成交价'],
        ],
        'order_no'         => [
            'label'   => '订单编号',
            'kind'    => 'text',
            'aliases' => ['订单编号', '订单号', 'Order No', 'Order Number', '采购单号', 'PO Number'],
        ],
        'order_time'       => [
            'label'   => '下单时间',
            'kind'    => 'date',
            'aliases' => ['下单时间', '下单日期', '订单日期', 'Order Date', '购买时间'],
        ],
        'customer_part_no' => [
            'label'   => '客户料号',
            'kind'    => 'text',
            'aliases' => ['客户料号', '客户编号', 'Customer Part No', 'Customer Part'],
        ],
        'location'         => [
            'label'   => '库位',
            'kind'    => 'text',
            'aliases' => ['库位', '货位', 'Location', '位置'],
        ],
        'remark'           => [
            'label'   => '备注',
            'kind'    => 'text',
            'aliases' => ['备注', 'Remark', 'Comment', '说明', 'Note'],
        ],
        // ── 以下为「需手动绑定」字段（manual=true）────────────────────────────
        // 平台文件里的扩展列（立创对账单的收货人/快递/发票等）：默认不参与自动识别，

        'unit'             => [
            'label'   => '商品单位',
            'kind'    => 'text',
            'manual'  => true,
            'aliases' => ['商品单位', '单位', '计量单位', 'Unit'],
        ],

        'datasheet'        => [
            'label'   => '数据手册链接',
            'kind'    => 'text',
            'manual'  => true,
            'aliases' => ['数据手册链接', '数据手册', '手册', '手册链接', '规格书', 'Datasheet', 'Data Sheet'],
        ],
        'image'            => [
            'label'   => '物料图片链接',
            'kind'    => 'text',
            'manual'  => true,
            'aliases' => ['物料图片链接', '物料图片', '图片链接', '图片', 'Image'],
        ],
        'order_status'     => [
            'label'   => '订单状态',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['订单状态', '订单状态说明', 'Order Status'],
        ],
        'receiver'         => [
            'label'   => '收货人',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['收货人', '收件人', 'Receiver'],
        ],
        'customer_rep'     => [
            'label'   => '客户代表',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['客户代表', '销售代表', '业务员'],
        ],
        'ship_date'        => [
            'label'   => '发货日期',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['发货日期', '发货时间', 'Ship Date'],
        ],
        'express_company'  => [
            'label'   => '快递公司',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['快递公司', '物流公司', '承运商', 'Express'],
        ],
        'express_no'       => [
            'label'   => '快递单号',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['快递单号', '运单号', '物流单号', 'Tracking No'],
        ],
        'sign_date'        => [
            'label'   => '签收日期',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['签收日期', '签收时间', 'Sign Date'],
        ],
        'invoice_no'       => [
            'label'   => '发票号',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['发票号', '发票号码', 'Invoice No'],
        ],
        'invoice_time'     => [
            'label'   => '开票时间',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['开票时间', '开票日期', 'Invoice Date'],
        ],
        'origin_order_no'  => [
            'label'   => '归属原订单',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['归属原订单', '原订单号', '原订单'],
        ],
        'bom_flag'         => [
            'label'   => 'BOM标识',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['bom标识', 'BOM标记', 'BOM Flag'],
        ],
        'contract_no'      => [
            'label'   => '订单合同编号',
            'kind'    => 'text',
            'group'   => 'order',
            'manual'  => true,
            'aliases' => ['订单合同编号', '合同编号', '合同号', 'Contract No'],
        ],
        'reference'        => [
            'label'    => '位号（仅识别，不作为导入目标）',
            'kind'     => 'text',
            'writable' => false,
            'aliases'  => ['位号', 'Designator', 'Reference', 'Ref Des'],
        ],
    ];

    /** 旧字段名（历史种子/历史模板遗留），需归一化到 canonical 键 */
    private const LEGACY_FIELDS = ['part_number', 'category', 'description', 'value', 'supplier'];

    /** 平台二维码字段别名表（内置）—— 扫码管线的主要事实来源 */
    public const QR_ALIASES = [
        'lcsc' => [
            'match'  => ['on', 'pc'],
            'fields' => [
                'order_no'         => ['on'],
                'platform_part_no' => ['pc'],
                'model'            => ['pm'],
                'quantity'         => ['qty'],
            ],
        ],
    ];

    /** 内置平台二维码字段别名表（内置，供 seed 下存与页面注入共用） */
    public static function qrAliases(): array
    {
        return self::QR_ALIASES;
    }

    /** 把内置词典的一条包装成「格式」结构（与用户导入的 `formats` 同构），专供下存进平台行 */
    public static function builtinQrFormats(string $platformCode): array
    {
        $def = self::QR_ALIASES[strtolower(trim($platformCode))] ?? null;
        if (!$def) return [];
        return [[
            'label'  => '平台收货码',
            'action' => 'in',
            'match'  => array_values((array)($def['match'] ?? [])),
            'fields' => (array)($def['fields'] ?? []),
        ]];
    }

    /** 按一组「二维码格式」把原始键值映射为 canonical 值（纯函数，不碰数据库） */
    public static function mapQrFields(array $raw, array $formats, bool $lenient = false): array
    {
        $empty = ['format_index' => -1, 'action' => '', 'label' => '', 'values' => []];
        if ($raw === [] || $formats === []) return $empty;

        $norm = [];
        foreach ($raw as $k => $v) {
            $key = strtolower(trim((string)$k));
            if ($key !== '' && !isset($norm[$key])) $norm[$key] = trim((string)$v);
        }
        if ($norm === []) return $empty;

        $best = -1; $bestHits = 0;
        foreach ($formats as $i => $f) {
            $need = array_values((array)($f['match'] ?? []));
            if ($need === []) continue;
            $hits = 0;
            foreach ($need as $n) {
                if (isset($norm[strtolower(trim((string)$n))])) { $hits++; }
                else { $hits = -1; break; }
            }
            if ($hits > 0 && $hits > $bestHits) { $best = (int)$i; $bestHits = $hits; }
        }
        if ($best < 0) {
            if (!$lenient) return $empty;
            $best = 0;   // 已按 platform_id 选定词典：无签名命中也要按第一条映射
        }

        $f      = $formats[$best];
        $values = [];
        foreach ((array)($f['fields'] ?? []) as $canonical => $keys) {
            $canonical = self::canonicalField((string)$canonical);
            if ($canonical === '' || !self::isWritableField($canonical)) continue;
            foreach ((array)$keys as $key) {
                $k = strtolower(trim((string)$key));
                if (isset($norm[$k]) && $norm[$k] !== '') { $values[$canonical] = $norm[$k]; break; }
            }
        }
        return [
            'format_index' => $best,
            'action'       => (string)($f['action'] ?? ''),
            'label'        => (string)($f['label'] ?? ''),
            'values'       => $values,
        ];
    }

    /** 可映射/可写库的字段（与 parts 真实列对齐） */
    public static function writableFields(): array
    {
        $out = [];
        foreach (self::FIELD_REGISTRY as $field => $meta) {
            if (($meta['writable'] ?? true) !== false) $out[] = $field;
        }
        return $out;
    }

    /** 是否可写入业务表（normalizeRow 守卫用）；需手动绑定字段同样可写 */
    public static function isWritableField(string $field): bool
    {
        if ($field === '') return false;
        $meta = self::FIELD_REGISTRY[$field] ?? null;
        return $meta !== null && ($meta['writable'] ?? true) !== false;
    }

    /** 是否「需手动绑定」字段（平台扩展列） */
    public static function isManualField(string $field): bool
    {
        $meta = self::FIELD_REGISTRY[$field] ?? null;
        return $meta !== null && !empty($meta['manual']);
    }

    /** 是否订单级扩展字段（仅用于 UI 下拉标签提示，落库到 order_meta） */
    public static function isOrderField(string $field): bool
    {
        $meta = self::FIELD_REGISTRY[$field] ?? null;
        return $meta !== null && (($meta['group'] ?? '') === 'order');
    }

    /** UI 映射下拉：字段 => 中文标签（仅可写字段；订单级扩展字段带分组提示） */
    public static function fieldLabels(): array
    {
        $out = [];
        foreach (self::FIELD_REGISTRY as $field => $meta) {
            if (($meta['writable'] ?? true) === false) continue;
            $label = (string)$meta['label'];
            if (($meta['group'] ?? '') === 'order') $label .= '（订单信息）';
            $out[$field] = $label;
        }
        return $out;
    }

    /** 内置模板别名：字段 => 别名列表（仅可写字段；需手动绑定字段不入池，避免默认自动识别） */
    public static function fieldAliases(): array
    {
        $out = [];
        foreach (self::FIELD_REGISTRY as $field => $meta) {
            if (($meta['writable'] ?? true) === false) continue;
            if (!empty($meta['manual'])) continue;
            $out[$field] = array_values((array)$meta['aliases']);
        }
        return $out;
    }

    /** 表头关键词识别/打分：字段 => 关键词（含仅识别字段，顺序即优先级；需手动绑定字段不入池） */
    public static function fieldKeywords(): array
    {
        $out = [];
        foreach (self::FIELD_REGISTRY as $field => $meta) {
            if (!empty($meta['manual'])) continue;
            $out[$field] = array_values((array)$meta['aliases']);
        }
        return $out;
    }

    /** 字段名归一化：旧名/漂移名 → canonical 注册表键 */
    public static function canonicalField(string $field, string $header = ''): string
    {
        $field = trim($field);
        if ($field === '') return '';
        if (isset(self::FIELD_REGISTRY[$field])) return $field;

        switch ($field) {
            case 'part_number':
                if ($header !== '') {
                    foreach (['商品编号', '平台编号', '料号', '物料编号', '产品编号', '商品编码', '编码', 'MPN', 'Part No'] as $kw) {
                        if (self::matchText($header, $kw)) return 'platform_part_no';
                    }
                }
                return 'model';
            case 'description':
                if ($header !== '') {
                    foreach (['备注', 'Remark', 'Note', '说明', 'Comment'] as $kw) {
                        if (self::matchText($header, $kw)) return 'remark';
                    }
                }
                return 'product_name';
            case 'category':
                return 'product_type';
            case 'value':
                return 'parameters';
            case 'supplier':
                return '';
        }
        return '';
    }

    /** 是否历史遗留字段名 */
    public static function isLegacyField(string $field): bool
    {
        return in_array($field, self::LEGACY_FIELDS, true);
    }

    /** field_keywords 表种子 + 旧名幂等迁移（config.php 安装/升级时调用） */
    public static function seedKeywords(\PDO $db): void
    {
        try {
            // ① 旧名迁移
            $legacy = $db->query("SELECT id, standard_field, keyword, language, priority FROM field_keywords
                                  WHERE standard_field IN ('part_number','category','description','value','supplier')")
                         ->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($legacy as $row) {
                $canon = self::canonicalField((string)$row['standard_field'], (string)$row['keyword']);
                if ($canon !== '') {
                    try {
                        $db->prepare("INSERT INTO field_keywords (standard_field, keyword, language, priority) VALUES (?,?,?,?)")
                           ->execute([$canon, (string)$row['keyword'], (string)$row['language'], (int)$row['priority']]);
                    } catch (\Throwable $e) { /* 与既有 canonical 行重复：丢弃旧行即可 */ }
                }
                $db->prepare("DELETE FROM field_keywords WHERE id=?")->execute([(int)$row['id']]);
            }

            $ins = $db->prepare("INSERT INTO field_keywords (standard_field, keyword, language, priority) VALUES (?,?,?,?)
                                 ON DUPLICATE KEY UPDATE priority=VALUES(priority)");
            $fieldIdx = 0;
            foreach (self::FIELD_REGISTRY as $field => $meta) {
                if (($meta['writable'] ?? true) === false) continue;
                if (!empty($meta['manual'])) continue;
                $fieldIdx++;
                $aliasIdx = 0;
                foreach ((array)$meta['aliases'] as $kw) {
                    $aliasIdx++;
                    $ins->execute([$field, (string)$kw, 'auto', $fieldIdx * 100 + $aliasIdx]);
                }
            }
        } catch (\Throwable $e) { /* 早期库结构缺失时静默 */ }
    }

    /** 宽松文本匹配（忽略大小写/空格/常见分隔符，支持包含；关键词 1 字时要求完全相等） */
    public static function matchText(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ($na === '' || $nb === '') return false;
        if ($na === $nb) return true;
        if (mb_strlen($nb) <= 1) return false;
        return mb_strpos($na, $nb) !== false;
    }

    /** 严格相等匹配（归一化后完全一致，不做包含判定） */
    public static function matchExact(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        return $na !== '' && $na === $nb;
    }

    /** 从「字段 => 关键词列表」里挑出最具体的候选（避免短别名抢占更具体的列） */
    public static function bestAlias(string $header, array $aliasMap, array $used = []): ?array
    {
        $best = null; $bestLen = -1; $bestExact = false;
        foreach ($aliasMap as $field => $keywords) {
            $field = self::canonicalField((string)$field, $header);
            if ($field === '' || !self::isWritableField($field) || isset($used[$field])) continue;
            foreach ((array)$keywords as $kw) {
                $kw    = (string)$kw;
                $exact = self::matchExact($header, $kw);
                if (!$exact && !self::matchText($header, $kw)) continue;
                $len = mb_strlen(self::normalize($kw));
                if ($best !== null) {
                    if ($bestExact && !$exact) continue;
                    if ($bestExact === $exact && $len <= $bestLen) continue;   // 精确度相同且不更长
                }
                $best = ['field' => $field, 'keyword' => $kw, 'exact' => $exact];
                $bestLen = $len; $bestExact = $exact;
            }
        }
        return $best;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return preg_replace('/[\s\-_\.\/（）()【】\[\]]/u', '', $s) ?? '';
    }
}
