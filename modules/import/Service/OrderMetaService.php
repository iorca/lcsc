<?php
declare(strict_types=1);

namespace Modules\Import\Service;

use PDO;

/** OrderMetaService — 订单扩展信息（order_meta 只读 + order_meta_ext 读写） */
final class OrderMetaService
{
    /** 列定义的**唯一来源**：字段名 => 中文标题。 */
    public const COLUMN_DEFS = [
        'order_no'        => '订单号',
        'order_status'    => '订单状态',
        'receiver'        => '收货人',
        'customer_rep'    => '客户代表',
        'express_company' => '快递公司',
        'express_no'      => '快递单号',
        'ship_date'       => '发货日期',
        'sign_date'       => '签收日期',
        'invoice_no'      => '发票号',
        'invoice_time'    => '开票时间',
        'origin_order_no' => '归属原订单',
        'bom_flag'        => 'BOM标识',
        'contract_no'     => '合同编号',
    ];

    /** 详情弹窗里的物流分组（展示用，不影响列配置） */
    public const LOGISTICS_FIELDS = ['express_company', 'express_no', 'ship_date', 'sign_date'];

    /** 恒显示不可关闭的列 */
    public const ALWAYS_VISIBLE = ['order_no'];

    /** 用户级配置键 */
    public const K_VISIBLE_COLS = 'order_visible_cols';
    public const K_CUSTOM_FIELDS = 'order_custom_fields';

    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $uid, int $dataUid, int $wsId)
    {
        $this->db      = $db;
        $this->uid     = $uid;      // 操作者（写 trace_log 用）
        $this->dataUid = $dataUid;  // 数据归属（读 order_meta 用）
        $this->wsId    = $wsId;
    }

    /** 有效列定义 = 固定列 ∪（启用的自定义字段），按配置顺序排列。 */
    public function columnDefs(): array
    {
        return self::COLUMN_DEFS + $this->customFieldLabels();
    }

    /** 列表页要显示的列（字段名数组）。 */
    public function visibleColumns(): array
    {
        $defs = $this->columnDefs();
        $all  = array_keys($defs);
        $raw  = \getUserSetting($this->uid, self::K_VISIBLE_COLS, '');
        if (trim($raw) === '') return $all;

        $arr = json_decode($raw, true);
        if (!is_array($arr)) return $all;

        $picked = [];
        foreach ($arr as $k) {
            $k = (string)$k;
            if (array_key_exists($k, $defs)) $picked[$k] = true;
        }
        foreach (self::ALWAYS_VISIBLE as $k) $picked[$k] = true;   // 恒显示列兜底补齐

        $out = [];
        foreach ($defs as $f => $label) {
            if (isset($picked[$f])) $out[] = $f;
        }
        return $out;
    }

    /** 保存列表页列显隐配置。 */
    public function saveVisibleColumns(array $cols): bool
    {
        $defs = $this->columnDefs();
        $clean = [];
        foreach ($cols as $c) {
            $c = trim((string)$c);
            // 白名单过滤：只接受已定义的字段名，杜绝任意字符串进 KV
            if ($c !== '' && array_key_exists($c, $defs)) $clean[$c] = true;
        }
        foreach (self::ALWAYS_VISIBLE as $k) $clean[$k] = true;
        if (!$clean) {
            $clean[self::ALWAYS_VISIBLE[0]] = true;
        }

        $val = json_encode(array_keys($clean), JSON_UNESCAPED_UNICODE);
        \setUserSetting($this->uid, self::K_VISIBLE_COLS, $val);
        \traceLog($this->uid, 'order_columns_save', 'user_setting', 0,
            '保存订单信息列显隐：' . implode(',', array_keys($clean)));
        return true;
    }

    /** 自定义字段定义数组。 */
    public function customFields(): array
    {
        $raw = \getUserSetting($this->uid, self::K_CUSTOM_FIELDS, '');
        if (trim($raw) === '') return [];
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** 启用中的自定义字段：字段名 => 中文标题。 */
    private function customFieldLabels(): array
    {
        $out = [];
        foreach ($this->customFields() as $f) {
            if (!is_array($f) || empty($f['key']) || empty($f['enabled'])) continue;
            $out[(string)$f['key']] = (string)($f['label'] ?? $f['key']);
        }
        return $out;
    }

    /** 新增自定义字段。 */
    public function addCustomField(string $key, string $label): array
    {
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return ['ok' => false, 'msg' => '字段名无效：只能用英文字母、数字、下划线或连字符（中文请填在「显示名」里）'];
        }
        if (isset(self::COLUMN_DEFS[$key])) {
            return ['ok' => false, 'msg' => "「{$key}」是系统已有字段，请直接在映射页绑定到该字段"];
        }
        $label = trim($label);
        if ($label === '') return ['ok' => false, 'msg' => '字段显示名不能为空'];

        $list = $this->customFields();
        foreach ($list as $f) {
            if (is_array($f) && (string)($f['key'] ?? '') === $key) {
                return ['ok' => false, 'msg' => "字段「{$key}」已存在"];
            }
        }
        $list[] = ['key' => $key, 'label' => $label, 'enabled' => true];
        $this->writeCustomFields($list);
        \traceLog($this->uid, 'order_field_add', 'user_setting', 0, "新增订单自定义字段：{$key}（{$label}）");
        return ['ok' => true, 'msg' => '已新增，请在导入映射页绑定到平台文件列', 'key' => $key];
    }

    /** 停用 / 启用自定义字段（停用保留数据）。 */
    public function toggleCustomField(string $key, bool $enabled): array
    {
        $key = $this->normalizeKey($key);
        $list = $this->customFields();
        $hit = false;
        foreach ($list as &$f) {
            if (is_array($f) && (string)($f['key'] ?? '') === $key) {
                $f['enabled'] = $enabled;
                $hit = true;
            }
        }
        unset($f);
        if (!$hit) return ['ok' => false, 'msg' => '字段不存在'];
        $this->writeCustomFields($list);
        \traceLog($this->uid, 'order_field_toggle', 'user_setting', 0,
            ($enabled ? '启用' : '停用') . "订单自定义字段：{$key}");
        return ['ok' => true, 'msg' => $enabled ? '已启用' : '已停用（数据保留）'];
    }

    /** 删除自定义字段。 */
    public function deleteCustomField(string $key, bool $purge): array
    {
        $key = $this->normalizeKey($key);
        if ($key === '') return ['ok' => false, 'msg' => '字段名不能为空', 'purged' => 0];

        $purged = 0;
        if ($purge) {
            try {
                $st = $this->db->prepare(
                    "DELETE FROM order_meta_ext WHERE field_key=? AND user_id=? AND workspace_id=?"
                );
                $st->execute([$key, $this->dataUid, $this->wsId]);
                $purged = $st->rowCount();
            } catch (\Throwable $e) {
                \error_log('deleteCustomField purge failed: ' . $e->getMessage());
                return ['ok' => false, 'msg' => '清理数据失败：' . $e->getMessage(), 'purged' => 0];
            }
        }

        $list = [];
        foreach ($this->customFields() as $f) {
            if (is_array($f) && (string)($f['key'] ?? '') === $key) continue;
            $list[] = $f;
        }
        $this->writeCustomFields($list);
        \traceLog($this->uid, 'order_field_delete', 'user_setting', 0,
            '删除订单自定义字段：' . $key . ($purge ? "（强制删除，清 {$purged} 行值，订单记录保留）" : '（仅移除定义，数据保留）'));
        return [
            'ok'     => true,
            'msg'    => $purge ? "已删除字段并清理 {$purged} 行值（订单记录保留）" : '已删除字段定义（数据保留）',
            'purged' => $purged,
        ];
    }

    /** 字段名归一：作为 `field_key` 落库与作为 HTML 属性值使用，故必须安全。 */
    private function normalizeKey(string $key): string
    {
        return (string)preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($key)));
    }

    /** 覆盖写回自定义字段定义 */
    private function writeCustomFields(array $list): void
    {
        \setUserSetting($this->uid, self::K_CUSTOM_FIELDS,
            json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
    }

    /** 批量取回一批订单的自定义扩展值。 */
    public function extValues(array $metaIds): array
    {
        $metaIds = array_values(array_unique(array_map('intval', $metaIds)));
        $out = [];
        if (!$metaIds) return $out;

        // IN 片段按整数量生成（全部是 intval 过的整数，非用户输入）
        $in = implode(',', array_fill(0, count($metaIds), '?'));
        try {
            $st = $this->db->prepare(
                "SELECT order_meta_id, field_key, field_value FROM order_meta_ext
                 WHERE user_id=? AND workspace_id=? AND order_meta_id IN ({$in})"
            );
            $st->execute(array_merge([$this->dataUid, $this->wsId], $metaIds));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(int)$r['order_meta_id']][(string)$r['field_key']] = (string)($r['field_value'] ?? '');
            }
        } catch (\Throwable $e) {
            \error_log('OrderMetaService::extValues failed: ' . $e->getMessage());
        }
        return $out;
    }

    /** 写入某订单的单个自定义扩展值（UPSERT，空值不覆盖）。 */
    public function setExtValue(int $metaId, string $key, string $value): bool
    {
        $key = $this->normalizeKey($key);
        if ($key === '' || $metaId <= 0) return false;
        $value = mb_substr(trim($value), 0, 2000);
        try {
            $this->db->prepare(
                "INSERT INTO order_meta_ext (workspace_id, user_id, order_meta_id, field_key, field_value)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE field_value = CASE WHEN VALUES(field_value) <> '' THEN VALUES(field_value) ELSE field_value END,
                                         updated_time = NOW()"
            )->execute([$this->wsId, $this->dataUid, $metaId, $key, $value]);
            return true;
        } catch (\Throwable $e) {
            \error_log('OrderMetaService::setExtValue failed: ' . $e->getMessage());
            return false;
        }
    }

    /** 订单列表（分页 + 关键字 + 列显隐） */
    public function listOrders(array $f): array
    {
        $keyword = trim((string)($f['keyword'] ?? $f['q'] ?? ''));
        $perPage = max(10, min(200, (int)($f['per_page'] ?? 25)));
        $page    = max(1, (int)($f['page'] ?? 1));

        $where = ['user_id = ?', 'workspace_id = ?'];
        $bind  = [$this->dataUid, $this->wsId];
        if ($keyword !== '') {
            $where[] = '(order_no LIKE ? OR receiver LIKE ? OR customer_rep LIKE ? OR express_no LIKE ? OR invoice_no LIKE ? OR contract_no LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($bind, $like, $like, $like, $like, $like, $like);
        }
        $wsql = implode(' AND ', $where);

        $defs    = $this->columnDefs();
        $visible = $this->visibleColumns();

        $out = [
            'rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1,
            'per_page' => $perPage, 'keyword' => $keyword,
            'cols' => array_values(array_intersect($visible, array_keys($defs))),
        ];
        try {
            $cnt = $this->db->prepare("SELECT COUNT(*) FROM order_meta WHERE {$wsql}");
            $cnt->execute($bind);
            $total = (int)$cnt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;

            $fixed = array_values(array_intersect($out['cols'], array_keys(self::COLUMN_DEFS)));
            $extra = array_values(array_diff($out['cols'], $fixed));

            $selCols = array_merge($fixed, ['id', 'updated_time']);
            $cols = implode(',', array_map(static fn($c) => "`{$c}`", $selCols));
            $st = $this->db->prepare("SELECT {$cols} FROM order_meta WHERE {$wsql} ORDER BY id DESC LIMIT " . (($page - 1) * $perPage) . ", {$perPage}");
            $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 自定义扩展值：一次 IN 取回，PHP 侧组装
            $ext = [];
            if ($extra && $rows) {
                $ext = $this->extValues(array_map(static fn($r) => (int)$r['id'], $rows));
            }
            foreach ($rows as &$r) {
                $id = (int)$r['id'];
                foreach ($extra as $k) {
                    $r[$k] = (string)($ext[$id][$k] ?? '');
                }
                unset($r['id']);
            }
            unset($r);

            $out['rows']        = $rows;
            $out['total']       = $total;
            $out['page']        = $page;
            $out['total_pages'] = $totalPages;
        } catch (\Throwable $e) {
            \error_log('OrderMetaService::listOrders failed: ' . $e->getMessage());
        }
        return $out;
    }

    /** 单订单完整信息（详情弹窗用）。 */
    public function orderDetail(string $orderNo): ?array
    {
        if (trim($orderNo) === '') return null;
        try {
            $cols = implode(',', array_map(
                static fn($c) => "`{$c}`",
                array_merge(array_keys(self::COLUMN_DEFS), ['id', 'created_time', 'updated_time'])
            ));
            $st = $this->db->prepare(
                "SELECT {$cols} FROM order_meta WHERE order_no=? AND user_id=? AND workspace_id=? LIMIT 1"
            );
            $st->execute([$orderNo, $this->dataUid, $this->wsId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            $metaId = (int)$row['id'];
            $ext = $this->extValues([$metaId])[$metaId] ?? [];

            unset($row['id']);
            $row['created_time'] = (string)($row['created_time'] ?? '');
            $row['updated_time'] = (string)($row['updated_time'] ?? '');

            return [
                'row'       => $row,
                'ext'       => $ext,
                'cols'      => self::COLUMN_DEFS,
                'extLabels' => $this->customFieldLabels(),
                'logistics' => self::LOGISTICS_FIELDS,
            ];
        } catch (\Throwable $e) {
            \error_log('OrderMetaService::orderDetail failed: ' . $e->getMessage());
            return null;
        }
    }
}
