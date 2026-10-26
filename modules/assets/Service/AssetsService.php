<?php
declare(strict_types=1);
/** 资产统计模块（v1.1.0 正式版） */

class AssetException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message, $errCode);
        $this->errCode = $errCode;
    }
}

/** 资产统计管理器 */
final class AssetManager
{
    /** 「未被标记为呆滞已处理」的 SQL 条件（不含 `AND`）。 */
    private const STALE_UNPROCESSED_SQL = " AND (p.remark IS NULL OR p.remark NOT LIKE '[呆滞已处理%')";

    /** 「空值档」在聚合视图里的哨兵键（未填库位 / 未分类共用） */
    public const STALE_LOC_NONE = '__none__';

    /** 主分类的显示值（SQL 片段，返回「分类名」或空串） */
    public const STALE_CATEGORY_SQL = "COALESCE((SELECT TRIM(IFNULL(c.name, '')) FROM part_categories pc
                                        JOIN categories c ON c.id = pc.category_id
                                        WHERE pc.part_id = p.id
                                        ORDER BY pc.category_id LIMIT 1), '')";

    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId; // 工作空间ID

    /** 操作类型标签映射（供前端展示） */
    public const TYPE_LABELS = [
        'import'        => ['订单导入', '#4f8ef7'],
        'manual_in'     => ['手动入库', '#22c55e'],
        'manual_out'    => ['手动出库', '#ef4444'],
        'adjust'        => ['库存调整', '#f59e0b'],
        'scan_in'       => ['扫码入库', '#22c55e'],
        'scan_out'      => ['扫码出库', '#ef4444'],
        'damaged'       => ['报损',     '#8b5cf6'],
        'repair'        => ['修复',     '#8b5cf6'],
        'scan_undo_in'  => ['撤销入库', '#f59e0b'],
        'scan_undo_out' => ['撤销出库', '#f59e0b'],
        'bom_out'       => ['BOM出库',  '#ef4444'],
        'batch_stock_in' => ['批量入库', '#22c55e'],
        'import_undo_out' => ['导入回退', '#f59e0b'],
        'checklist_adjust' => ['盘点调整', '#f59e0b'],
    ];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid         = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = getWorkspaceId();
    }

    /** 获取4个统计卡片数据 */
    public function getStats(): array
    {

        $dirtyStmt = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND workspace_id=? AND cost_dirty=1 ORDER BY last_cost_recalc ASC LIMIT 100");
        $dirtyStmt->execute([$this->dataUid, $this->workspaceId]);
        foreach ($dirtyStmt->fetchAll(PDO::FETCH_COLUMN) as $dirtyId) {
            recalcAvgCost($this->db, (int)$dirtyId);
        }

        // 1. 累计入库总金额 = SUM(subtotal) 全系统非样品入库流水小计

        $totalCostStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(subtotal), 0) FROM stock_log
             WHERE user_id=? AND workspace_id=? AND subtotal > 0 AND is_sample=0 AND qty_change > 0"
        );
        $totalCostStmt->execute([$this->dataUid, $this->workspaceId]);
        $totalStockCost = (float)$totalCostStmt->fetchColumn();

        // 2. 样品物料数量（排除统计）
        $sampleStmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT part_id) FROM stock_log
             WHERE user_id=? AND workspace_id=? AND is_sample=1"
        );
        $sampleStmt->execute([$this->dataUid, $this->workspaceId]);
        $sampleCount = (int)$sampleStmt->fetchColumn();

        // 3. 本月新增资产 = 当前自然月内入库流水小计总和（排除样品）
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd   = date('Y-m-t 23:59:59');
        $monthStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN qty_change>0 AND is_sample=0 THEN subtotal ELSE 0 END), 0)
             FROM stock_log WHERE user_id=? AND workspace_id=? AND create_time BETWEEN ? AND ?"
        );
        $monthStmt->execute([$this->dataUid, $this->workspaceId, $monthStart, $monthEnd]);
        $monthInAmount = (float)$monthStmt->fetchColumn();

        // 4. 在库物料总种类 = COUNT(parts) WHERE stock>0
        $typeStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE user_id=? AND workspace_id=? AND stock>0");
        $typeStmt->execute([$this->dataUid, $this->workspaceId]);
        $inStockTypes = (int)$typeStmt->fetchColumn();

        $newStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts WHERE user_id=? AND workspace_id=? AND update_time BETWEEN ? AND ?"
        );
        $newStmt->execute([$this->dataUid, $this->workspaceId, $monthStart, $monthEnd]);
        $monthNewParts = (int)$newStmt->fetchColumn();

        return [
            'total_stock_cost'  => round($totalStockCost, 2),
            'sample_count'      => $sampleCount,
            'month_in_amount'   => round($monthInAmount, 2),
            'in_stock_types'    => $inStockTypes,
            'month_new_parts'   => $monthNewParts,
            'month_label'       => date('Y年m月'),
        ];
    }

    /** 获取近12个月图表数据 */
    public function getChartData(): array
    {
        // 计算12个月前的月初作为查询起点
        $startMonth = date('Y-m-01 00:00:00', strtotime('-11 months'));

        // 单查询获取近12个月每月的入库/出库/小计汇总
        $stmt = $this->db->prepare(
            "SELECT
                DATE_FORMAT(create_time, '%Y-%m') AS ym,
                COALESCE(SUM(CASE WHEN qty_change>0 AND is_sample=0 THEN subtotal ELSE 0 END), 0) AS in_amt,
                COALESCE(SUM(CASE WHEN qty_change<0 THEN qty_change*unit_cost ELSE 0 END), 0) AS out_amt,
                COALESCE(SUM(CASE WHEN qty_change>0 AND is_sample=0 AND subtotal>0 THEN subtotal ELSE 0 END), 0) AS month_subtotal
             FROM stock_log
             WHERE user_id=? AND workspace_id=? AND create_time >= ?
             GROUP BY ym
             ORDER BY ym"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId, $startMonth]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 构建月份映射，补齐缺失月份为0
        $monthMap = [];
        foreach ($rows as $r) {
            $monthMap[$r['ym']] = $r;
        }

        // 生成近12个月标签（从11个月前到当前月）
        $labels = [];
        $inValues = [];
        $outValues = [];
        $monthSubtotals = [];
        for ($i = 11; $i >= 0; $i--) {
            $mLabel = date('Y-m', strtotime("-$i month"));
            $labels[] = $mLabel;
            $data = $monthMap[$mLabel] ?? null;
            $inValues[]    = round((float)($data['in_amt'] ?? 0), 2);
            $outValues[]   = round(abs((float)($data['out_amt'] ?? 0)), 2);
            $monthSubtotals[] = (float)($data['month_subtotal'] ?? 0);
        }

        $baseStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(subtotal), 0) FROM stock_log
             WHERE user_id=? AND workspace_id=? AND create_time < ? AND is_sample=0 AND subtotal>0 AND qty_change>0"
        );
        $baseStmt->execute([$this->dataUid, $this->workspaceId, $startMonth]);
        $cumulative = (float)$baseStmt->fetchColumn();

        // 逐月累加得到折线图数据
        $lineValues = [];
        foreach ($monthSubtotals as $ms) {
            $cumulative += $ms;
            $lineValues[] = round($cumulative, 2);
        }

        return [
            'line' => [
                'labels' => $labels,
                'values' => $lineValues,
            ],
            'bar' => [
                'labels'     => $labels,
                'in_values'  => $inValues,
                'out_values' => $outValues,
            ],
        ];
    }

    /** 出入库流水查询 */
    public function listLogs(array $f): array
    {
        // 解析筛选参数
        $keyword  = trim((string)($f['keyword'] ?? ''));
        $catId    = (int)($f['cat_id'] ?? 0);
        $platId   = (int)($f['plat_id'] ?? 0);
        $dateFrom = trim((string)($f['date_from'] ?? ''));
        $dateTo   = trim((string)($f['date_to'] ?? ''));

        // 构建 WHERE 条件
        $whereSql = "WHERE sl.user_id=? AND sl.workspace_id=?";
        $params = [$this->dataUid, $this->workspaceId];
        if ($keyword !== '') {
            $whereSql .= " AND (p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ?)";
            $kw = '%' . $keyword . '%';
            array_push($params, $kw, $kw, $kw);
        }
        if ($platId > 0) {
            $whereSql .= " AND p.platform_id=?";
            $params[] = $platId;
        }
        if ($catId > 0) {
            $whereSql .= " AND EXISTS (SELECT 1 FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=sl.part_id AND (c.id=? OR c.parent_id=?))";
            array_push($params, $catId, $catId);
        }
        if ($dateFrom !== '') {
            $whereSql .= " AND sl.create_time>=?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereSql .= " AND sl.create_time<=?";
            $params[] = $dateTo . ' 23:59:59';
        }

        $perPage = (int)($f['per_page'] ?? 30);
        if ($perPage < 15 || $perPage > 10000) $perPage = 30;
        $page = max(1, (int)($f['page'] ?? 1));

        // 总数查询
        $cntStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM stock_log sl LEFT JOIN parts p ON p.id=sl.part_id AND p.workspace_id=? $whereSql"
        );
        // 在 params 最前面插入 workspaceId（给 JOIN 条件用）
        $cntParams = array_merge([$this->workspaceId], $params);
        $cntStmt->execute($cntParams);
        $total = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // 流水查询（JOIN 平台 + GROUP_CONCAT 分类，消除 N+1）
        $sql = "SELECT sl.id, sl.part_id, sl.platform_part_no, sl.change_type, sl.qty_change,
                       sl.unit_cost, sl.is_sample, sl.subtotal, sl.create_time, sl.remark,
                       p.model, p.product_name, pl.name AS pname,
                       (SELECT GROUP_CONCAT(c.name SEPARATOR ',')
                        FROM part_categories pc JOIN categories c ON c.id=pc.category_id
                        WHERE pc.part_id=sl.part_id) AS cat_names
                FROM stock_log sl
                LEFT JOIN parts p ON p.id=sl.part_id AND p.workspace_id=?
                LEFT JOIN platforms pl ON pl.id=p.platform_id
                $whereSql
                ORDER BY sl.create_time DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->workspaceId], $params));
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($logs as &$l) {
            $l['id']           = (int)$l['id'];
            $l['part_id']      = (int)$l['part_id'];
            $l['qty_change']   = (int)$l['qty_change'];
            $l['unit_cost']    = (float)$l['unit_cost'];
            $l['is_sample']    = (int)$l['is_sample'];
            $l['subtotal']     = (float)$l['subtotal'];
            $l['cat_names']    = $l['cat_names'] ?? '';
        }
        unset($l);

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'type_labels' => self::TYPE_LABELS,
        ];
    }

    /** 查询指定 ID 的流水记录（用于 CSV 导出，含分类拼接） */
    public function getLogsForExport(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($ids)) {
            throw new AssetException('未选择记录', 4);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT sl.id, sl.platform_part_no, sl.change_type, sl.qty_change,
                       sl.unit_cost, sl.is_sample, sl.subtotal, sl.create_time, sl.remark,
                       sl.part_id,
                       p.model, p.product_name, pl.name AS pname,
                       (SELECT GROUP_CONCAT(c.name SEPARATOR ',')
                        FROM part_categories pc JOIN categories c ON c.id=pc.category_id
                        WHERE pc.part_id=sl.part_id) AS cat_names
                FROM stock_log sl
                LEFT JOIN parts p ON p.id=sl.part_id AND p.workspace_id=?
                LEFT JOIN platforms pl ON pl.id=p.platform_id
                WHERE sl.user_id=? AND sl.workspace_id=? AND sl.id IN ($in)
                ORDER BY sl.create_time DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->workspaceId, $this->dataUid, $this->workspaceId], $ids));
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($logs)) {
            throw new AssetException('所选记录不存在或无权访问', 404);
        }

        return $logs;
    }

    /** 获取筛选下拉选项（一级分类 + 平台列表） */
    public function getFilterOptions(): array
    {
        // 一级分类
        $catStmt = $this->db->prepare(
            "SELECT id, name FROM categories WHERE user_id=? AND workspace_id=? AND parent_id IS NULL ORDER BY name"
        );
        $catStmt->execute([$this->dataUid, $this->workspaceId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // 平台列表
        $platStmt = $this->db->prepare(
            "SELECT id, name FROM platforms WHERE user_id=? AND workspace_id=? ORDER BY is_default DESC, name"
        );
        $platStmt->execute([$this->dataUid, $this->workspaceId]);
        $platforms = $platStmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化
        foreach ($categories as &$c) { $c['id'] = (int)$c['id']; }
        unset($c);
        foreach ($platforms as &$p) { $p['id'] = (int)$p['id']; }
        unset($p);

        return [
            'categories' => $categories,
            'platforms'  => $platforms,
        ];
    }

    /** 物料总资产价值 = Σ(stock × avg_cost)，仅计算 stock>0 的物料 */
    public function getTotalAssetValue(): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(stock * avg_cost), 0) AS total_value,
                    COALESCE(SUM(stock), 0) AS total_qty
             FROM parts WHERE user_id=? AND workspace_id=? AND stock > 0"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_asset_value' => round((float)($row['total_value'] ?? 0), 2),
            'total_stock_qty'   => (int)($row['total_qty'] ?? 0),
        ];
    }

    /** 分类资产分布（饼图数据） */
    public function getCategoryAssetDistribution(): array
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(c2.name, c1.name, '未分类') AS cat_name,
                    SUM(p.stock * p.avg_cost) AS asset_value
             FROM parts p
             LEFT JOIN part_categories pc ON pc.part_id = p.id
             LEFT JOIN categories c1 ON c1.id = pc.category_id
             LEFT JOIN categories c2 ON c2.id = c1.parent_id
             WHERE p.user_id = ? AND p.workspace_id = ? AND p.stock > 0
             GROUP BY cat_name
             HAVING asset_value > 0
             ORDER BY asset_value DESC
             LIMIT 11"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $otherValue = 0.0;
        $count = 0;
        foreach ($rows as $r) {
            $count++;
            if ($count <= 10) {
                $result[] = [
                    'name'  => $r['cat_name'],
                    'value' => round((float)$r['asset_value'], 2),
                ];
            } else {
                $otherValue += (float)$r['asset_value'];
            }
        }
        // 超出 Top10 的分类合并为「其他」
        if ($otherValue > 0) {
            $result[] = ['name' => '其他', 'value' => round($otherValue, 2)];
        }
        return $result;
    }

    /** 高价值物料 TOP N（按 stock × avg_cost 降序） */
    public function getTopHighValueParts(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT p.id, p.model, p.product_name, p.stock, p.avg_cost,
                    p.platform_part_no,
                    (p.stock * p.avg_cost) AS asset_value
             FROM parts p
             WHERE p.user_id = ? AND p.workspace_id = ? AND p.stock > 0 AND p.avg_cost > 0
             ORDER BY asset_value DESC
             LIMIT $limit"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['stock']         = (int)$r['stock'];
            $r['avg_cost']      = round((float)$r['avg_cost'], 4);
            $r['asset_value']   = round((float)$r['asset_value'], 2);
        }
        unset($r);
        return $rows;
    }

    /** 呆滞料识别：超过 N 天无出入库且 stock>0 */
    public function getStaleParts(int $days = 90, int $limit = 50, bool $showProcessed = false): array
    {
        $days  = max(30, min(365, $days));
        $limit = max(1, min(200, $limit));
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // P8 已处理过滤：默认排除 remark 带呆滞已处理前缀的物料
        $processedWhere = $showProcessed ? '' : self::STALE_UNPROCESSED_SQL;

        $stmt = $this->db->prepare(
            "SELECT p.id, p.model, p.product_name, p.stock, p.avg_cost,
                    p.platform_part_no, p.location,
                    (p.stock * p.avg_cost) AS asset_value,
                    COALESCE(last_move.last_time, p.update_time) AS last_move_time,
                    (p.remark LIKE '[呆滞已处理%') AS is_processed
             FROM parts p
             LEFT JOIN (
                SELECT part_id, MAX(create_time) AS last_time
                FROM stock_log WHERE user_id = ? AND workspace_id = ?
                GROUP BY part_id
             ) last_move ON last_move.part_id = p.id
             WHERE p.user_id = ? AND p.workspace_id = ? AND p.stock > 0 AND p.status!='deleted'
               AND COALESCE(last_move.last_time, p.update_time) < ?{$processedWhere}
             ORDER BY last_move_time ASC
             LIMIT $limit"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId, $this->dataUid, $this->workspaceId, $cutoff]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']            = (int)$r['id'];
            $r['stock']         = (int)$r['stock'];
            $r['avg_cost']      = round((float)$r['avg_cost'], 4);
            $r['asset_value']   = round((float)$r['asset_value'], 2);
            $r['last_move_time'] = substr((string)$r['last_move_time'], 0, 16);
            $r['is_processed']  = (int)$r['is_processed'];
        }
        unset($r);
        return $rows;
    }

    /** 呆滞判定 cutoff 时间（供列表/导出/标记共用口径） */
    public static function staleCutoff(int $days): string
    {
        $days = max(30, min(365, $days));
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    /** 读取呆滞判定天数设置（admin 设置 stale_days，30-365，默认 90） */
    public static function getStaleDays(): int
    {
        $v = getSetting('stale_days');
        if ($v === false || $v === null || $v === '') return 90;
        return max(30, min(365, (int)$v));
    }

    /** 呆滞清单的公共查询口径（列表 / 聚合 / 导出共用） */
    private function staleQuery(array $params): array
    {
        $reqDays = (int)($params['days'] ?? 0);
        $days    = $reqDays > 0 ? max(30, min(365, $reqDays)) : self::getStaleDays();
        $showProcessed = !empty($params['show_processed']);
        $keyword = trim((string)($params['q'] ?? ''));
        $minDays = (int)($params['min_days'] ?? 0);
        $loc     = (string)($params['loc'] ?? '');
        $catKey  = $params['cat_key'] ?? null;   // null=不过滤；'' 不会出现（空值档用哨兵 __none__）
        $canCost = can_view_cost();
        $cutoff  = self::staleCutoff($days);

        // 已处理过滤：默认排除；开关打开时全部返回并带 is_processed 标记
        $processedWhere = $showProcessed ? '' : self::STALE_UNPROCESSED_SQL;

        $bind   = [$this->dataUid, $this->workspaceId, $this->dataUid, $this->workspaceId, $cutoff];
        $extra  = '';
        if ($keyword !== '') {
            $extra .= " AND (p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ?)";
            $kw = '%' . $keyword . '%';
            array_push($bind, $kw, $kw, $kw);
        }
        if ($loc !== '') {
            // __none__ = 聚合视图里「未填库位」那一档的下钻哨兵值。

            if ($loc === self::STALE_LOC_NONE) {
                $extra .= " AND TRIM(IFNULL(p.location, '')) = ''";
            } else {
                $extra .= " AND TRIM(IFNULL(p.location, '')) = ?";
                $bind[] = $loc;
            }
        }
        if ($catKey !== null) {

            if ($catKey === self::STALE_LOC_NONE) {
                $extra .= ' AND ' . self::STALE_CATEGORY_SQL . " = ''";
            } else {
                $extra .= ' AND ' . self::STALE_CATEGORY_SQL . ' = ?';
                $bind[] = (string)$catKey;
            }
        }
        if ($minDays > 0) {
            $extra .= " AND COALESCE(last_move.last_time, p.update_time) <= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $bind[] = $minDays;
        }

        $baseFrom =
            "FROM parts p
             LEFT JOIN (
                SELECT part_id, MAX(create_time) AS last_time
                FROM stock_log WHERE user_id = ? AND workspace_id = ?
                GROUP BY part_id
             ) last_move ON last_move.part_id = p.id";
        $where = "WHERE p.user_id = ? AND p.workspace_id = ? AND p.stock > 0 AND p.status!='deleted'
                  AND COALESCE(last_move.last_time, p.update_time) < ?{$processedWhere}{$extra}";

        return [
            'days'     => $days,
            'canCost'  => $canCost,
            'baseFrom' => $baseFrom,
            'where'    => $where,
            'bind'     => $bind,
        ];
    }

    /** P8 呆滞物料专区列表（分页 + 已处理开关 + 排序 + 下钻过滤） */
    public function getStaleList(array $params = []): array
    {
        $q = $this->staleQuery($params);
        $days    = $q['days'];
        $canCost = $q['canCost'];
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = max(10, min(200, (int)($params['per_page'] ?? 50)));
        $baseFrom = $q['baseFrom'];
        $where    = $q['where'];
        $bind     = $q['bind'];

        $cnt = $this->db->prepare("SELECT COUNT(*) {$baseFrom} {$where}");
        $cnt->execute($bind);
        $total = (int)$cnt->fetchColumn();

        $valueExpr = $canCost ? "COALESCE(SUM(p.stock * p.avg_cost), 0)" : "0";
        $sumStmt = $this->db->prepare("SELECT {$valueExpr} AS total_value {$baseFrom} {$where}");
        $sumStmt->execute($bind);
        $totalValue = round((float)$sumStmt->fetchColumn(), 2);

        // 分档统计：同一批物料按「多久没动销」切成三档，一眼看出积压严重在哪一档。
        // 用 DATEDIFF 而不是 PHP 逐行算：行数可能上千，没必要来回传。
        $buckets = [];
        try {
            $bStmt = $this->db->prepare(
                "SELECT SUM(t.d < 180) AS c1,
                        SUM(t.d >= 180 AND t.d < 365) AS c2,
                        SUM(t.d >= 365) AS c3,
                        SUM(CASE WHEN t.d < 180 THEN t.v ELSE 0 END) AS v1,
                        SUM(CASE WHEN t.d >= 180 AND t.d < 365 THEN t.v ELSE 0 END) AS v2,
                        SUM(CASE WHEN t.d >= 365 THEN t.v ELSE 0 END) AS v3
                 FROM (
                    SELECT DATEDIFF(NOW(), COALESCE(last_move.last_time, p.update_time)) AS d,
                           (p.stock * p.avg_cost) AS v
                     {$baseFrom} {$where}
                 ) t"
            );
            $bStmt->execute($bind);
            $b = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $buckets = [
                ['key' => 'mid',    'label' => ($days < 180 ? "{$days}~179 天" : '不足 180 天'),
                 'count' => (int)($b['c1'] ?? 0), 'value' => $canCost ? round((float)($b['v1'] ?? 0), 2) : null],
                ['key' => 'long',   'label' => '180~365 天',
                 'count' => (int)($b['c2'] ?? 0), 'value' => $canCost ? round((float)($b['v2'] ?? 0), 2) : null],
                ['key' => 'oldest', 'label' => '超过 365 天',
                 'count' => (int)($b['c3'] ?? 0), 'value' => $canCost ? round((float)($b['v3'] ?? 0), 2) : null],
            ];
        } catch (\Throwable $e) {
            error_log('stale buckets: ' . $e->getMessage());   // 分档只是概览，失败不影响清单
        }

        // 排序：白名单映射，绝不把前端字符串拼进 ORDER BY
        $sortMap = [
            'stale' => 'last_move_time',
            'stock' => 'p.stock',
            'value' => 'asset_value',
            'name'  => 'p.model',
        ];
        $sortParam = (string)($params['sort'] ?? '');
        $dirParam  = strtolower((string)($params['dir'] ?? ''));
        $sortKey   = $sortMap[$sortParam] ?? 'last_move_time';
        // 方向缺省：时间类升序（最久没动销在最前，与改造前一致），
        // 数量 / 金额类降序（最占库存、最占钱的在最前）
        $defaultDir = in_array($sortParam, ['stock', 'value'], true) ? 'DESC' : 'ASC';
        $dir = $dirParam === 'asc' ? 'ASC' : ($dirParam === 'desc' ? 'DESC' : $defaultDir);

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            "SELECT p.id, p.internal_id, p.model, p.product_name, p.platform_part_no,
                    p.brand, p.package, p.location, p.stock, p.avg_cost,
                    (p.stock * p.avg_cost) AS asset_value,
                    COALESCE(last_move.last_time, p.update_time) AS last_move_time,
                    (p.remark LIKE '[呆滞已处理%') AS is_processed,
                    p.remark
             {$baseFrom} {$where}
             ORDER BY {$sortKey} {$dir}, p.id ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id'             => (int)$r['id'],
                'internal_id'    => (string)$r['internal_id'],
                'model'          => (string)$r['model'],
                'product_name'   => (string)$r['product_name'],
                'platform_part_no' => (string)$r['platform_part_no'],
                'brand'          => (string)$r['brand'],
                'package'        => (string)$r['package'],
                'location'       => (string)$r['location'],
                'stock'          => (int)$r['stock'],
                'avg_cost'       => $canCost ? round((float)$r['avg_cost'], 4) : null,
                'asset_value'    => $canCost ? round((float)$r['asset_value'], 2) : null,
                'last_move_time' => substr((string)$r['last_move_time'], 0, 16),
                'stale_days'     => (int)ceil((time() - strtotime((string)$r['last_move_time'])) / 86400),
                'is_processed'   => (int)$r['is_processed'],
            ];
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'total_value' => $canCost ? $totalValue : null,
            'days'        => $days,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_page'  => (int)ceil(max(1, $total) / $perPage),
            'can_cost'    => $canCost,
            'buckets'     => $buckets,
            'sort'        => $sortParam,
            'dir'         => $dir,
        ];
    }

    /** 呆滞清单聚合视图（按库位 / 按分类） */
    public function getStaleGroups(array $params, string $by): array
    {
        $by = $by === 'category' ? 'category' : 'location';
        $q  = $this->staleQuery($params);
        $canCost = $q['canCost'];

        // 桶表达式：返回可比较的原始值（空串 = 「未填库位 / 未分类」那一档）
        $bucketRaw = $by === 'category'
            ? self::STALE_CATEGORY_SQL
            : "TRIM(IFNULL(p.location, ''))";
        $valExpr = $canCost ? '(p.stock * p.avg_cost)' : '0';

        $sql = "SELECT t.raw AS grp, COUNT(*) AS cnt, SUM(t.qty) AS qty, SUM(t.val) AS val
                FROM (
                    SELECT {$bucketRaw} AS raw, p.stock AS qty, {$valExpr} AS val
                    {$q['baseFrom']} {$q['where']}
                ) t
                GROUP BY t.raw
                ORDER BY cnt DESC, val DESC
                LIMIT 200";

        $rows = [];
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($q['bind']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $raw  = (string)$r['grp'];
                $none = ($raw === '');
                $rows[] = [

                    'key'   => $none ? self::STALE_LOC_NONE : $raw,
                    'label' => $none
                        ? ($by === 'category' ? '（未分类）' : '（未填库位）')
                        : $raw,
                    'count' => (int)$r['cnt'],
                    'qty'   => (int)$r['qty'],
                    'value' => $canCost ? round((float)$r['val'], 2) : null,
                ];
            }
        } catch (\Throwable $e) {
            error_log('stale groups: ' . $e->getMessage());
        }

        return ['by' => $by, 'items' => $rows, 'can_cost' => $canCost];
    }

    /** P8 呆滞清单 CSV 导出（fputcsv 标准函数，UTF-8 BOM） */
    public function exportStaleCsv(int $days, bool $showProcessed = false, string $keyword = '', int $minDays = 0, string $loc = '', ?string $catKey = null): string
    {
        $list = $this->getStaleList([
            'days' => $days, 'page' => 1, 'per_page' => 10000,
            'show_processed' => $showProcessed, 'q' => $keyword, 'min_days' => $minDays,
            'loc' => $loc, 'cat_key' => $catKey,
        ]);
        $canCost = $list['can_cost'];

        $fp = fopen('php://temp', 'r+');
        // UTF-8 BOM（Excel 直开不乱码）
        fwrite($fp, "\xEF\xBB\xBF");

        $header = ['内部编号', '平台编号', '型号', '品名', '品牌', '封装', '库位', '库存数量', '最后动销时间', '呆滞天数', '是否已处理'];
        if ($canCost) {
            $header[] = '含税成交均价';
            $header[] = '资产价值';
        }
        fputcsv($fp, $header);

        foreach ($list['items'] as $r) {
            $row = [
                $r['internal_id'], $r['platform_part_no'], $r['model'], $r['product_name'],
                $r['brand'], $r['package'], $r['location'], $r['stock'],
                $r['last_move_time'], $r['stale_days'],
                $r['is_processed'] ? '是' : '否',
            ];
            if ($canCost) {
                $row[] = $r['avg_cost'];
                $row[] = $r['asset_value'];
            }
            fputcsv($fp, $row);
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    /** 首页预警中心汇总 */
    public function getWarnings(): array
    {
        // 低库存数量（三级优先级：单品 > 分类 > 全局）
        $globalThr = getGlobalThreshold();
        $effThrExpr = "COALESCE(p.low_stock_threshold,
            (SELECT c.low_stock_threshold FROM part_categories pc
             JOIN categories c ON c.id = pc.category_id
             WHERE pc.part_id = p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
            {$globalThr})";
        $lowStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts p
             WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted' AND p.stock > 0
               AND p.stock <= {$effThrExpr}"
        );
        $lowStmt->execute([$this->dataUid, $this->workspaceId]);
        $lowStockCount = (int)$lowStmt->fetchColumn();

        // 呆滞料（N天无出入库且有库存，天数读 admin 设置 stale_days）
        $staleDays = self::getStaleDays();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$staleDays} days"));
        $staleStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts p
             LEFT JOIN (
                SELECT part_id, MAX(create_time) AS last_time
                FROM stock_log WHERE user_id = ? AND workspace_id = ?
                GROUP BY part_id
             ) last_move ON last_move.part_id = p.id
             WHERE p.user_id = ? AND p.workspace_id = ? AND p.stock > 0 AND p.status!='deleted'
               AND (p.remark IS NULL OR p.remark NOT LIKE '[呆滞已处理%')
               AND COALESCE(last_move.last_time, p.update_time) < ?"
        );
        $staleStmt->execute([$this->dataUid, $this->workspaceId, $this->dataUid, $this->workspaceId, $cutoff]);
        $staleCount = (int)$staleStmt->fetchColumn();

        // 呆滞料 TOP5（供首页展示）
        $staleTop5 = $this->getStaleParts($staleDays, 5);

        // P9 库存异常（单查询三类：不良品库存 / 缺货待补 / 负库存数据异常）
        $anomalyStmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN p.damaged > 0 THEN 1 ELSE 0 END), 0) AS damaged_cnt,
                COALESCE(SUM(CASE WHEN p.stock = 0 AND {$effThrExpr} > 0 THEN 1 ELSE 0 END), 0) AS zero_needy_cnt,
                COALESCE(SUM(CASE WHEN p.stock < 0 THEN 1 ELSE 0 END), 0) AS negative_cnt
             FROM parts p
             WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted'"
        );
        $anomalyStmt->execute([$this->dataUid, $this->workspaceId]);
        $a = $anomalyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $anomaly = [
            'damaged'            => (int)($a['damaged_cnt'] ?? 0),
            'zero_with_threshold' => (int)($a['zero_needy_cnt'] ?? 0),
            'negative'           => (int)($a['negative_cnt'] ?? 0),
        ];
        $anomaly['total'] = $anomaly['damaged'] + $anomaly['zero_with_threshold'] + $anomaly['negative'];

        return [
            'low_stock_count' => $lowStockCount,
            'stale_count'     => $staleCount,
            'stale_days'      => $staleDays,
            'stale_top5'      => $staleTop5,
            'anomaly'         => $anomaly,
        ];
    }
}
