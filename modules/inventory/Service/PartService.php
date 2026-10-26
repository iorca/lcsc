<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use PDO;

/** 物料读取服务 */
final class PartService
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db          = $db;
        $this->uid         = $uid;
        $this->dataUid     = $dataUid;
        // 单工作空间：`workspace_id` 恒为 1，条件保留但不区分数据
        $this->workspaceId = getWorkspaceId();
    }

    /** 搜索关键词切分：支持多种分隔符，便于从表格/文档复制粘贴后直接检索 */
    public static function splitKeywords(string $q): array
    {
        $parts = preg_split('/[\s,，、|;；]+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) return [];
        // 去重但保留输入顺序（AND 语义下顺序不影响结果，去重仅减少冗余子句）
        return array_values(array_unique(array_map('trim', $parts)));
    }

    /** 判断一个关键词是否「像唯一标识」，决定用精准匹配还是模糊匹配 */
    public static function isUniqueToken(string $kw): bool
    {
        if (mb_strlen($kw) < 2) return false;
        // 内部ID 唯一可靠的前置写法
        if (str_starts_with($kw, '#')) return true;

        if (self::isPackageCode($kw)) return false;

        // 纯数字不视为唯一标识：多为型号/参数中的数字片段，按精准匹配会恒空
        if (ctype_digit($kw)) return false;

        if (preg_match('/^[A-Za-z]\d{4,}$/', $kw)) return true;

        if (preg_match('/^[A-Za-z][-_\\/][A-Za-z0-9]/', $kw)) return true;
        return false;
    }

    /** 是否为封装代码（而非唯一编号） */
    private static function isPackageCode(string $kw): bool
    {
        static $sizes = null;
        if ($sizes === null) {
            $sizes = array_flip(array_merge(
                ['0402','0603','0805','1206','1210','1812','2010','2512','2917','1218'],
                ['1005','1608','2012','3216','4532','5025','6332','7351']
            ));
        }
        if (isset($sizes[$kw])) return true;

        return (bool)preg_match('/^(SOIC|SOP|HSOP|TSSOP|SSOP|MSOP|QSOP|SOT|SOD|SO|QFN|DFN|LQFN|LQFP|TQFP|VQFP|HQFP|QFP|BGA|DIP|TO)-?\d{1,3}(-\d{1,3})?$/i', $kw);
    }

    /** 构建单个关键词的 WHERE 片段 */
    public static function buildKeywordCondition(string $kw): string
    {
        if (self::isUniqueToken($kw)) {
            $id = self::internalIdFromToken($kw);

            return "(REPLACE(REPLACE(LOWER(p.platform_part_no),'-',''),' ','') = REPLACE(REPLACE(LOWER(?),'-',''),' ','')
                 OR REPLACE(REPLACE(LOWER(p.customer_part_no),'-',''),' ','') = REPLACE(REPLACE(LOWER(?),'-',''),' ','')
                 OR CAST(p.internal_id AS CHAR) = ?
                 OR LOWER(TRIM(p.model)) = LOWER(?))";
        }
        return '(p.model LIKE ? OR p.product_name LIKE ? OR p.brand LIKE ? OR p.platform_part_no LIKE ?
                 OR p.package LIKE ? OR p.parameters LIKE ? OR p.parameters_text LIKE ?
                 OR p.product_type LIKE ? OR p.internal_id LIKE ? OR p.remark LIKE ? OR p.location LIKE ?
                 OR EXISTS (SELECT 1 FROM part_categories pc JOIN categories c ON c.id=pc.category_id
                            WHERE pc.part_id=p.id AND c.name LIKE ?))';
    }

    /** 与 buildKeywordCondition() 占位符严格对应的参数值（顺序一致） */
    public static function keywordPlaceholders(string $kw): array
    {
        if (self::isUniqueToken($kw)) {
            $id = self::internalIdFromToken($kw);
            return [$kw, $kw, $id, $kw];
        }
        $like = '%' . $kw . '%';
        return array_fill(0, 12, $like);
    }

    /** 从唯一 token 里剥出 internal_id 字符串：#123 / P00001 → '123'（前导零由 PHP 强转消化）。 */
    private static function internalIdFromToken(string $kw): string
    {
        // 两支分开写捕获组：非捕获组 (?:#|P0\d+) 会让 $m[1] 不存在
        if (preg_match('/^#(\d+)$/i', $kw, $m)) return (string)(int)$m[1];
        if (preg_match('/^P(0\d+)$/i', $kw, $m)) return (string)(int)$m[1];
        return $kw;
    }
    /** 构建搜索结果的相关性排序片段（ORDER BY 用，不含占位符） */
    public static function buildRelevanceOrderBy(PDO $db, array $keywords): string
    {
        $parts = [];
        foreach ($keywords as $kw) {
            $kwLc = mb_strtolower($kw, 'UTF-8');
            // 精准比较用「=」，不涉及 LIKE 通配，无需转义
            $kwEq = $db->quote($kwLc);
            // 前缀比较用 LIKE，% 与 _ 必须转义后再交给 quote
            $kwPre = $db->quote(self::escapeLike($kwLc) . '%');

            $exact = "(LOWER(p.model) = {$kwEq}
                OR LOWER(p.platform_part_no) = {$kwEq}
                OR LOWER(p.customer_part_no) = {$kwEq}
                OR LOWER(p.package) = {$kwEq}
                OR LOWER(p.parameters) = {$kwEq}
                OR LOWER(p.parameters_text) = {$kwEq}
                OR CAST(p.internal_id AS CHAR) = {$db->quote($kw)})";
            $prefixHit = "(LOWER(p.model) LIKE {$kwPre}
                OR LOWER(p.platform_part_no) LIKE {$kwPre}
                OR LOWER(p.product_name) LIKE {$kwPre})";

            $parts[] = "(CASE WHEN {$exact} THEN 0 WHEN {$prefixHit} THEN 1 ELSE 2 END)";
        }
        if (!$parts) return 'p.update_time DESC';

        return '(' . implode(' + ', $parts) . ')';
    }

    /** LIKE 模式转义：% 与 _ 是 LIKE 通配符，用户输入时须转为字面量 */
    private static function escapeLike(string $kw): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $kw);
    }

    /** LIKE 前缀值转义（% 与 _ 需转义，否则用户输入的 % 会变成通配全表） */
    private static function escapeLikePrefix(string $kw): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $kw);
    }

    /** 物料列表查询（支持筛选/分页/排序/搜索） */
    public function getList(array $params): array
    {
        $q        = trim((string)($params['q'] ?? ''));
        $filter   = (string)($params['filter'] ?? '');
        $cat      = trim((string)($params['cat'] ?? ''));
        $plat     = (int)($params['plat'] ?? 0);
        $loc      = trim((string)($params['loc'] ?? ''));
        $status   = (string)($params['status'] ?? '');
        $page     = max(1, (int)($params['page'] ?? 1));
        $perPage  = max(1, min(200, (int)($params['per_page'] ?? 25)));
        $sort     = (string)($params['sort'] ?? 'update_time');
        $dir      = strtoupper((string)($params['dir'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';
        $locMatch = (string)($params['location_match'] ?? 'fuzzy');

        $where = ['p.user_id = ?', 'p.workspace_id = ?'];
        $args  = [$this->dataUid, $this->workspaceId];

        // 最小长度校验：单字符搜索会触发多字段全表扫描（无索引可用），直接判定无结果
        $keywords = $q !== '' ? self::splitKeywords($q) : [];
        $tooShort = false;
        foreach ($keywords as $kw) {
            if (mb_strlen($kw) < 2) { $tooShort = true; break; }
        }
        if ($tooShort) $keywords = [];

        // 关键词搜索：多参数 AND + 分层匹配（唯一字段精准 / 非唯一字段模糊）
        if (!empty($keywords)) {
            $kwWhere = [];
            foreach ($keywords as $kw) {
                $kwWhere[] = self::buildKeywordCondition($kw);
                foreach (self::keywordPlaceholders($kw) as $ph) {
                    $args[] = $ph;
                }
            }
            $where[] = '(' . implode(' AND ', $kwWhere) . ')';
        } elseif ($q !== '' && $tooShort) {
            $where[] = '1=0';   // 存在过短关键词 → 必然无意义结果
        }

        // 库存状态筛选
        if ($filter === 'low') {

            $where[] = 'p.stock > 0 AND p.stock <= COALESCE(p.low_stock_threshold, ?)';
            $args[]  = \getGlobalThreshold();
        } elseif ($filter === 'zero') {
            $where[] = 'p.stock = 0';
        } elseif ($filter === 'damaged') {
            $where[] = 'p.damaged > 0';
        } elseif ($filter === 'negative') {

            $where[] = 'p.stock < 0';
        }

        if ($cat !== '') {
            $tokens    = array_values(array_filter(array_map('trim', explode(',', $cat)), fn($s) => $s !== ''));
            $catIds    = [];
            $hasUncat  = false;
            $nameMatch = [];
            foreach ($tokens as $tk) {
                if ($tk === '-1')      { $hasUncat = true; }
                elseif (ctype_digit($tk) && (int)$tk > 0) { $catIds[] = (int)$tk; }
                else                  { $nameMatch[] = $tk; }
            }

            $expanded = [];
            if ($catIds || $hasUncat) {
                $treeSvc = new \Modules\Category\Service\CategoryTreeService($this->db, $this->dataUid);
                $flat    = $treeSvc->getFlatTree();
                foreach ($catIds as $cid) {
                    if (!isset($flat[$cid])) continue;
                    if (!in_array($cid, $expanded, true)) $expanded[] = $cid;
                    foreach ($flat[$cid]['descendants'] as $did) {
                        if (!in_array($did, $expanded, true)) $expanded[] = $did;
                    }
                }
                if ($hasUncat) {

                    $uStmt = $this->db->prepare(
                        "SELECT id FROM categories WHERE user_id=? AND workspace_id=? AND parent_id = 0"
                    );
                    $uStmt->execute([$this->dataUid, $this->workspaceId]);
                    foreach ($uStmt->fetchAll(PDO::FETCH_COLUMN) as $uid2) {
                        $nid = (int)$uid2;
                        if (!in_array($nid, $expanded, true)) $expanded[] = $nid;
                    }
                }
            }

            $sub = [];
            if ($expanded) {
                $ph = implode(',', array_fill(0, count($expanded), '?'));
                $sub[]      = "EXISTS (SELECT 1 FROM part_categories pc WHERE pc.part_id=p.id AND pc.category_id IN ($ph))";
                array_push($args, ...$expanded);
            }
            foreach ($nameMatch as $nm) {
                $sub[]      = 'EXISTS (SELECT 1 FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.name = ?)';
                $args[]     = $nm;
            }
            if ($sub) {
                $where[] = '(' . implode(' OR ', $sub) . ')';
            } elseif ($catIds || $hasUncat) {
                $where[] = '1=0'; // 无有效分类 → 空结果
            }
        }

        // 平台筛选
        if ($plat > 0) {
            $where[] = 'p.platform_id = ?';
            $args[] = $plat;
        }

        // 库位筛选
        if ($loc !== '') {
            if ($locMatch === 'exact') {
                $where[] = 'p.location = ?';
                $args[] = $loc;
            } else {
                $where[] = 'p.location LIKE ?';
                $args[] = '%' . self::escapeLikePrefix($loc) . '%';
            }
        }

        // 状态筛选
        if ($status !== '' && $status !== 'all') {
            $where[] = 'p.status = ?';
            $args[] = $status;
        } else {
            $where[] = "p.status != 'deleted'";
        }

        $whereSql = implode(' AND ', $where);

        // 总数
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM parts p WHERE {$whereSql}");
        $countStmt->execute($args);
        $total = (int)$countStmt->fetchColumn();
        $totalPage = (int)ceil($total / $perPage);

        // 排序白名单
        $sortMap = [
            'update_time'      => 'p.update_time',
            'model'            => 'p.model',
            'stock'            => 'p.stock',
            'brand'            => 'p.brand',
            'cost'             => 'p.avg_cost',
            'platform_part_no' => 'p.platform_part_no',
            'location'         => 'p.location',
        ];
        $sortCol = $sortMap[$sort] ?? 'p.update_time';

        $offset = ($page - 1) * $perPage;

        // 搜索时的匹配层级排序（仅在有关键词时生效，用户主动点表头排序则以用户选择为准）

        $orderBy = "{$sortCol} {$dir}";
        if ($q !== '' && $sort === 'update_time' && $dir === 'DESC') {
            $orderBy = self::buildRelevanceOrderBy($this->db, $keywords) . ", {$sortCol} {$dir}";
        }

        $sql = "SELECT p.*,
                (CASE WHEN p.stock > 0 AND p.create_time < DATE_SUB(NOW(), INTERVAL 90 DAY)
                      AND NOT EXISTS (SELECT 1 FROM stock_log sl WHERE sl.part_id = p.id
                                      AND sl.create_time >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                                      AND sl.change_type NOT IN ('damaged','repair'))
                 THEN 1 ELSE 0 END) AS is_stale
                FROM parts p WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$offset}, {$perPage}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 分类映射（批量获取所有列表物料的分类）
        $catMap = [];
        if (!empty($list)) {
            $ids = array_column($list, 'id');
            $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $catStmt = $this->db->prepare("SELECT pc.part_id, c.name FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id IN ({$inPlaceholders}) ORDER BY c.id ASC");
            $catStmt->execute($ids);
            while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
                $catMap[$row['part_id']][] = $row['name'];
            }
        }

        return [
            'list'       => $list,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_page' => $totalPage,
            'cat_map'    => $catMap,

            'stats'      => $this->getStats(),
        ];
    }

    /** 统计数据（种类/库存/不足/用完/不良品/呆滞料） */
    public function getStats(): array
    {

        $stmt = $this->db->prepare("SELECT
            COUNT(*) as total,
            COALESCE(SUM(stock), 0) as total_stock,
            COALESCE(SUM(damaged), 0) as total_damaged,
            SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as zero_count,
            SUM(CASE WHEN stock < 0 THEN 1 ELSE 0 END) as negative_count,
            SUM(CASE WHEN stock > 0 AND stock <= COALESCE(low_stock_threshold, ?) THEN 1 ELSE 0 END) as low_count
        FROM parts WHERE user_id = ? AND workspace_id = ? AND status != 'deleted'");
        $stmt->execute([\getGlobalThreshold(), $this->dataUid, $this->workspaceId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // 呆滞料数量（90天无出入库且有库存，仅统计正式物料）
        $staleStmt = $this->db->prepare("SELECT COUNT(*) FROM parts p
            WHERE p.user_id = ? AND p.workspace_id = ? AND p.status != 'deleted' AND p.stock > 0
            AND p.id NOT IN (
                SELECT DISTINCT part_id FROM stock_log
                WHERE user_id = ? AND create_time >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND change_type NOT IN ('damaged','repair')
            )");
        $staleStmt->execute([$this->dataUid, $this->workspaceId, $this->dataUid]);
        $stats['stale_count'] = (int)$staleStmt->fetchColumn();

        $stats['total']         = (int)($stats['total'] ?? 0);
        $stats['total_stock']   = (int)($stats['total_stock'] ?? 0);
        $stats['total_damaged'] = (int)($stats['total_damaged'] ?? 0);
        $stats['zero_count']    = (int)($stats['zero_count'] ?? 0);
        $stats['low_count']     = (int)($stats['low_count'] ?? 0);
        $stats['negative_count'] = (int)($stats['negative_count'] ?? 0);   // 负库存（数据异常）：负库存卡片与 filter=negative 筛选徽标

        return $stats;
    }

    /** 获取分类数据（一级+二级+未绑定+批量，用于分类筛选及批量操作） */
    public function getCategoryData(): array
    {
        $uid = $this->dataUid;

        // 一级大类：cnt 统计该大类下绑定的二级分类数量

        $topStmt = $this->db->prepare(
            "SELECT c.id, c.name,
                    COALESCE((SELECT COUNT(*) FROM categories sc WHERE sc.user_id = ? AND sc.parent_id = c.id), 0) AS cnt
             FROM categories c
             WHERE c.user_id = ? AND c.parent_id IS NULL
             ORDER BY c.id ASC"
        );
        $topStmt->execute([$uid, $uid]);
        $topCats = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        // 子分类：parent_id>0 的分类（绑定父级；P5 树形含任意层级）
        // 计数按 workspace 隔离：仅统计当前工作空间下的物料
        $subStmt = $this->db->prepare(
            "SELECT c.id, c.name, c.parent_id, COUNT(pc.part_id) AS cnt
             FROM categories c
             LEFT JOIN part_categories pc ON pc.category_id=c.id
                  AND EXISTS (SELECT 1 FROM parts pp WHERE pp.id=pc.part_id AND pp.workspace_id=?)
             WHERE c.user_id=? AND c.parent_id>0
             GROUP BY c.id ORDER BY c.name ASC"
        );
        $subStmt->execute([$this->workspaceId, $uid]);
        $subCats = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        // P5 树形深度标注（APCu 缓存树服务），供前端按层级缩进展示
        try {
            $treeDepths = [];
            $treeSvc = new \Modules\Category\Service\CategoryTreeService($this->db, $this->dataUid);
            foreach ($treeSvc->getFlatTree() as $node) {
                $treeDepths[(int)$node['id']] = (int)$node['depth'];
            }
            foreach ($subCats as &$sc) {
                $sc['depth'] = $treeDepths[(int)$sc['id']] ?? 2;
            }
            unset($sc);
        } catch (\Throwable $e) { error_log('PartService getCategoryData tree: ' . $e->getMessage()); }

        $unboundStmt = $this->db->prepare(
            "SELECT c.id, c.name, COUNT(pc.part_id) AS cnt
             FROM categories c
             LEFT JOIN part_categories pc ON pc.category_id=c.id
                  AND EXISTS (SELECT 1 FROM parts pp WHERE pp.id=pc.part_id AND pp.workspace_id=?)
             WHERE c.user_id=? AND c.parent_id=0
             GROUP BY c.id ORDER BY c.name ASC"
        );
        $unboundStmt->execute([$this->workspaceId, $uid]);
        $unboundSubCats = $unboundStmt->fetchAll(PDO::FETCH_ASSOC);

        // 批量分类弹窗用：所有非顶级分类（parent_id IS NOT NULL）
        $allCatsStmt = $this->db->prepare(
            "SELECT c.id, c.name, c.parent_id, c.low_stock_threshold, COUNT(pc.part_id) AS cnt
             FROM categories c
             LEFT JOIN part_categories pc ON pc.category_id=c.id
                  AND EXISTS (SELECT 1 FROM parts pp WHERE pp.id=pc.part_id AND pp.workspace_id=?)
             WHERE c.user_id=? AND c.parent_id IS NOT NULL
             GROUP BY c.id ORDER BY cnt DESC LIMIT 50"
        );
        $allCatsStmt->execute([$this->workspaceId, $uid]);
        $allCats = $allCatsStmt->fetchAll(PDO::FETCH_ASSOC);

        $noCatStmt = $this->db->prepare("SELECT COUNT(*) FROM categories WHERE user_id=? AND parent_id=0");
        $noCatStmt->execute([$uid]);
        $noCatCount = (int)$noCatStmt->fetchColumn();

        return [
            'top_cats'          => $topCats,
            'sub_cats'          => $subCats,
            'unbound_sub_cats'  => $unboundSubCats,
            'all_cats'          => $allCats,
            'no_cat_count'      => $noCatCount,
        ];
    }

    /** 获取平台列表 */
    public function getPlatforms(): array
    {
        $stmt = $this->db->prepare("SELECT id, name, code, platform_type, is_default, url_template FROM platforms WHERE user_id = ? ORDER BY id ASC");
        $stmt->execute([$this->dataUid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 获取商品类型建议（供搜索/分类使用） */
    public function getProductTypeSuggestions(string $query = '', int $limit = 10): array
    {
        if ($query === '') {
            $stmt = $this->db->prepare("SELECT DISTINCT product_type FROM parts WHERE user_id = ? AND workspace_id = ? AND product_type != '' AND status != 'deleted' ORDER BY product_type ASC LIMIT ?");
            $stmt->execute([$this->dataUid, $this->workspaceId, $limit]);
        } else {
            $stmt = $this->db->prepare("SELECT DISTINCT product_type FROM parts WHERE user_id = ? AND workspace_id = ? AND product_type LIKE ? AND status != 'deleted' ORDER BY product_type ASC LIMIT ?");
            $stmt->execute([$this->dataUid, $this->workspaceId, '%' . $query . '%', $limit]);
        }
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'product_type');
    }

    /** 获取库位下拉列表（物料列表页筛选 / 表单用） */
    public function getLocations(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT DISTINCT location FROM parts WHERE user_id=? AND location IS NOT NULL AND location<>'' ORDER BY location ASC");
            $stmt->execute([$this->dataUid]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            error_log('PartService::getLocations: ' . $e->getMessage());
            return [];
        }
    }
}
