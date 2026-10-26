<?php
declare(strict_types=1);

use App\Core\Enum\PartStatus;
use App\Helper\Spec\SpecNormalizer;
use App\Service\AlternativeScorer;

require_once __DIR__ . '/../../../config.php';

if (!function_exists('normalizeSearchTerm')) {
    function normalizeSearchTerm(string $term): string {
        return strtolower(str_replace(['-', ' ', '_'], '', $term));
    }
}

/** 物料业务异常：携带错误码与消息，供入口文件区分 JSON 响应或重定向。 */
final class PartException extends \RuntimeException
{
    public function __construct(string $message, public int $errCode = 1)
    {
        parent::__construct($message);
    }
}

/** 物料管理器：所有元件相关业务逻辑的唯一入口。 */
final class PartManager
{
    private PDO $db;
    private int $uid;         // 操作用户 ID（用于日志记录）
    private int $dataUid;     // 数据归属用户 ID（子用户继承父用户）
    private int $globalThr;   // 全局低库存阈值
    private int $wsId;        // 工作空间 ID（数据隔离）

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db        = $db;
        $this->uid       = $uid;
        $this->dataUid   = $dataUid;
        $this->globalThr = getGlobalThreshold();
        $this->wsId      = getWorkspaceId();
    }

    /** 分类列表分页查询（分类管理页 AJAX 局部刷新用） */
    public function listCategoriesPaged(array $params): array
    {
        $perPage = intval($params['per_page'] ?? ($_COOKIE['per_page_categories'] ?? 25));
        $perPage = max(10, min(50, $perPage));
        $page    = max(1, intval($params['page'] ?? 1));
        $keyword = trim((string)($params['q'] ?? ''));
        $sortBy  = (string)($params['sort'] ?? 'cnt_desc');
        if (!in_array($sortBy, ['cnt_desc', 'cnt_asc', 'name_asc', 'name_desc'], true)) {
            $sortBy = 'cnt_desc';
        }

        $filter = (string)($params['filter'] ?? 'all');
        if (!in_array($filter, ['all', 'top', 'unbound'], true)) {
            $filter = 'all';
        }

        // 全部二级分类（与 categories.php 顶部查询保持一致）
        $catsStmt = $this->db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NOT NULL GROUP BY c.id ORDER BY cnt DESC,c.name ASC");
        $catsStmt->execute([$this->dataUid]);
        $allCats = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

        $topStmt = $this->db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NULL GROUP BY c.id ORDER BY c.name ASC");
        $topStmt->execute([$this->dataUid]);
        $topCats = $topStmt->fetchAll(PDO::FETCH_ASSOC);
        $topCatMap = [];
        foreach ($topCats as $tc) { $topCatMap[(int)$tc['id']] = $tc['name']; }

        // P5 树形数据：父级路径 + 深度（APCu 缓存）
        $catPaths = [];
        $catDepths = [];
        $catChildCnt = [];
        try {
            $treeSvc = new \Modules\Category\Service\CategoryTreeService($this->db, $this->dataUid);
            foreach ($treeSvc->getFlatTree() as $node) {
                $catDepths[(int)$node['id']] = (int)$node['depth'];
                $catChildCnt[(int)$node['id']] = count($node['descendants']);
                $full = $node['path'];
                $pos = strrpos($full, ' / ');
                $catPaths[(int)$node['id']] = $pos === false ? '' : substr($full, 0, $pos);
            }
        } catch (\Throwable $e) { error_log('PartManager listCategoriesPaged tree: ' . $e->getMessage()); }

        // 搜索/排序前的全量快照：父级下拉、合并目标等选项不应被当前筛选/搜索条件裁剪
        $allCatsFull = $allCats;

        // ── 顶部统计卡筛选 ──
        if ($filter === 'top') {
            // 一级大类不在主表格的常规数据源内（常规列表只列细分类），筛选时单独装配
            $baseCats = array_map(static function ($c) {
                return [
                    'id'                  => (int)$c['id'],
                    'name'                => (string)$c['name'],
                    'parent_id'           => null,
                    'low_stock_threshold' => $c['low_stock_threshold'],
                    'cnt'                 => (int)$c['cnt'],
                ];
            }, $topCats);
        } elseif ($filter === 'unbound') {
            $baseCats = array_values(array_filter($allCats, static fn($c) => (int)$c['parent_id'] === 0));
        } else {
            $baseCats = $allCats;
        }

        // ── 搜索：分类名或完整路径（含父级路径）模糊匹配 ──
        if ($keyword !== '') {
            $kw = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
            $baseCats = array_values(array_filter($baseCats, function ($c) use ($kw, $catPaths) {
                $name = function_exists('mb_strtolower') ? mb_strtolower((string)$c['name']) : strtolower((string)$c['name']);
                if (str_contains($name, $kw)) return true;
                $path = $catPaths[(int)$c['id']] ?? '';
                if ($path === '') return false;
                $path = function_exists('mb_strtolower') ? mb_strtolower($path) : strtolower($path);
                return str_contains($path, $kw);
            }));
        }

        // ── 排序 ──
        usort($baseCats, function ($a, $b) use ($sortBy) {
            $cmp = match ($sortBy) {
                'cnt_asc'  => (int)$a['cnt'] <=> (int)$b['cnt'],
                'name_asc' => strcmp((string)$a['name'], (string)$b['name']),
                'name_desc'=> strcmp((string)$b['name'], (string)$a['name']),
                default    => (int)$b['cnt'] <=> (int)$a['cnt'],
            };
            // 同值时按名称升序兜底，保证分页翻页结果稳定不跳动
            return $cmp !== 0 ? $cmp : strcmp((string)$a['name'], (string)$b['name']);
        });

        // 内存分页
        $total = count($baseCats);
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;
        $pageCats = array_slice($baseCats, $offset, $perPage);

        $catLocations = [];
        if ($pageCats) {
            $catIds = array_column($pageCats, 'id');
            $inCats = implode(',', array_fill(0, count($catIds), '?'));
            $locStmt = $this->db->prepare("SELECT pc.category_id, p.location, COUNT(*) as loc_cnt FROM part_categories pc INNER JOIN parts p ON p.id=pc.part_id WHERE pc.category_id IN ($inCats) AND p.user_id=? AND p.status!='deleted' AND p.location IS NOT NULL AND p.location<>'' GROUP BY pc.category_id, p.location ORDER BY pc.category_id, loc_cnt DESC");
            $locStmt->execute([...$catIds, $this->dataUid]);
            foreach ($locStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $cid = (int)$lr['category_id'];
                if (!isset($catLocations[$cid])) $catLocations[$cid] = [];
                $catLocations[$cid][] = [
                    'location' => (string)$lr['location'],
                    'loc_cnt'  => (int)$lr['loc_cnt'],
                ];
            }
        }

        // 类型规范化（前端无需再做类型转换）
        $items = array_map(function($c) use ($topCatMap, $catLocations, $catPaths, $catDepths, $catChildCnt) {
            $cid = (int)$c['id'];
            $pid = (int)$c['parent_id'];
            return [
                'id'                  => $cid,
                'name'                => (string)$c['name'],
                'parent_id'           => $pid,
                'parent_name'         => ($pid > 0 && isset($topCatMap[$pid])) ? (string)$topCatMap[$pid] : null,
                'parent_path'         => $catPaths[$cid] ?? '',
                'depth'               => $catDepths[$cid] ?? ($c['parent_id'] === null ? 1 : 2),
                'is_top'              => $c['parent_id'] === null ? 1 : 0,
                'child_cnt'           => $catChildCnt[$cid] ?? 0,
                'low_stock_threshold' => $c['low_stock_threshold'] !== null ? (string)$c['low_stock_threshold'] : null,
                'cnt'                 => (int)$c['cnt'],
                'locations'           => $catLocations[$cid] ?? [],
            ];
        }, $pageCats);

        $allCatsList = array_map(function($c) use ($topCatMap, $catPaths, $catDepths) {
            $cid = (int)$c['id'];
            $pid = (int)$c['parent_id'];
            return [
                'id'          => $cid,
                'name'        => (string)$c['name'],
                'parent_id'   => $pid,
                'parent_name' => ($pid > 0 && isset($topCatMap[$pid])) ? (string)$topCatMap[$pid] : null,
                'parent_path' => $catPaths[$cid] ?? '',
                'depth'       => $catDepths[$cid] ?? 2,
                'cnt'         => (int)$c['cnt'],
            ];
        }, $allCatsFull);
        // 父级下拉按层级路径排序：先一级大类，同级按名称，保证选项顺序符合树形直觉
        usort($allCatsList, function ($a, $b) {
            $c = strcmp((string)$a['parent_path'], (string)$b['parent_path']);
            return $c !== 0 ? $c : strcmp((string)$a['name'], (string)$b['name']);
        });

        // 未绑定（parent_id=0）分类数：页头统计卡用
        $unboundCount = 0;
        foreach ($allCatsFull as $c) {
            if ((int)$c['parent_id'] === 0) $unboundCount++;
        }

        return [
            'items'          => $items,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $perPage,
            'total_page'     => $totalPage,
            'top_cats'       => $topCats,
            'all_cats'       => $allCatsList,
            'total_all'      => count($allCatsFull) + count($topCats),
            'top_count'      => count($topCats),
            'unbound_count'  => $unboundCount,
            'filter'         => $filter,
        ];
    }

    /** 物料详情摘要（累计资产 + 最新采购单价 + 新增字段） */
    public function getPartDetail(int $pid): array
    {
        if ($pid <= 0) throw new PartException('参数错误', 4);
        $costSummary = $this->getCostSummary($pid);

        try {
            $stmt = $this->db->prepare("SELECT parameters_text, workspace_id, datasheet, image FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
            $stmt->execute([$pid, $this->dataUid, $this->wsId]);
            $extra = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $extra = null;
        }
        return array_merge($costSummary, [
            'parameters_text' => $extra['parameters_text'] ?? '',
            'workspace_id'    => (int)($extra['workspace_id'] ?? 1),
            'datasheet'       => $extra['datasheet'] ?? '',
            'image'           => $extra['image'] ?? '',
        ]);
    }

    /** 编辑弹窗完整字段查询（统一数据源） */
    public function getPartEditData(int $id): array
    {
        if ($id <= 0) throw new PartException('参数错误', 4);

        $stmt = $this->db->prepare("SELECT p.id,p.platform_id,p.platform_part_no,p.customer_part_no,p.model,p.brand,p.product_name,p.product_type,p.package,p.location,p.location_id,p.low_stock_threshold,p.remark,p.purchase_url,p.linked_part_id,p.stock,p.damaged,p.internal_id,p.parameters_text,p.workspace_id,p.datasheet,p.image,pl.platform_type FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id=? AND p.user_id=? AND p.workspace_id=?");
        $stmt->execute([$id, $this->dataUid, $this->wsId]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new PartException('元件不存在', 404);

        // 关联标准物料（用于搜索框回显）
        $linkedPart = null;
        if (!empty($part['linked_part_id'])) {
            $lpStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
            $lpStmt->execute([$part['linked_part_id'], $this->dataUid, $this->wsId]);
            $linkedPart = $lpStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 分类ID列表（用于分类下拉框回显选中状态）
        $catStmt = $this->db->prepare("SELECT c.id, c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
        $catStmt->execute([$id]);
        $catRows = $catStmt->fetchAll();
        $catIds = array_map(fn($r) => (int)$r['id'], $catRows);
        $cats   = array_map(fn($r) => $r['name'], $catRows);

        $altParts = [];
        try {
            $altStmt = $this->db->prepare("SELECT p.id, p.internal_id, p.model, p.platform_part_no, p.product_name, p.stock FROM parts p INNER JOIN part_alternatives pa ON pa.target_part_id = p.id WHERE pa.source_part_id=? AND p.user_id=? AND p.workspace_id=?");
            $altStmt->execute([$id, $this->dataUid, $this->wsId]);
            $altParts = $altStmt->fetchAll();
        } catch (Throwable $e) {
            // 兼容：part_alternatives 表不存在时静默失败
        }

        return [
            'part'         => $part,
            'platform_type'=> $part['platform_type'] ?? 'standard',
            'linked_part'  => $linkedPart,
            'cat_ids'      => $catIds,
            'cats'         => $cats,
            'alt_parts'    => $altParts,
        ];
    }

    /** 成本摘要（累计资产 = SUM(subtotal) 排除样品；最新采购含税单价） */
    public function getCostSummary(int $pid): array
    {
        if ($pid <= 0) throw new PartException('参数错误', 4);
        $tStmt = $this->db->prepare("SELECT COALESCE(SUM(subtotal), 0) AS total FROM stock_log WHERE part_id=? AND is_sample=0 AND subtotal>0");
        $tStmt->execute([$pid]);
        $totalAsset = (float)$tStmt->fetchColumn();
        $lStmt = $this->db->prepare("SELECT unit_cost FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time DESC LIMIT 1");
        $lStmt->execute([$pid]);
        $row = $lStmt->fetch();
        // 个人版SQL层成本隔离：不返回价格数据
        if (!can_view_cost()) {
            $totalAsset = 0;
        }
        return [
            'total_asset' => $totalAsset,
            'latest_cost' => can_view_cost() ? ($row ? (float)$row['unit_cost'] : 0) : 0,
        ];
    }

    /** 替代料编号批量查询（按 part_id 列表） */
    public function altLookup(array $ids): array
    {
        $result = [];
        if (!empty($ids)) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, brand, package, stock, purchase_url FROM parts WHERE id IN ($in) AND user_id=? AND workspace_id=?");
            $stmt->execute([...$ids, $this->dataUid, $this->wsId]);
            $found = [];
            foreach ($stmt->fetchAll() as $p) $found[$p['id']] = $p;
            foreach ($ids as $pid) {
                if (isset($found[$pid])) {
                    $p = $found[$pid];
                    $result[] = ['id' => (int)$pid, 'internal_id' => (int)$p['internal_id'], 'no' => $p['platform_part_no'], 'name' => $p['model'] ?: $p['product_name'], 'brand' => (string)($p['brand'] ?? ''), 'package' => (string)($p['package'] ?? ''), 'stock' => (int)$p['stock'], 'purchase_url' => $p['purchase_url'] ?? '', 'found' => true];
                } else {
                    $result[] = ['id' => (int)$pid, 'internal_id' => 0, 'no' => '', 'name' => '', 'brand' => '', 'package' => '', 'stock' => 0, 'purchase_url' => '', 'found' => false];
                }
            }
        }
        return ['items' => $result];
    }

    /** 替代料搜索（按关键词模糊匹配型号/编号/内部ID） */
    public function altSearch(string $q): array
    {
        $result = [];
        $q = trim($q);
        if ($q !== '') {
            // 支持 #数字 / P补零码 精确匹配 internal_id：
            // #123 与 P000123 均匹配 internal_id=123。

            if (preg_match('/^#(\d+)$/i', $q, $m) || preg_match('/^P(0\d+)$/i', $q, $m)) {
                $intId = (int)$m[1];
                $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, brand, package, product_type, stock, location, remark, purchase_url FROM parts WHERE user_id=? AND workspace_id=? AND internal_id=? ORDER BY model LIMIT 20");
                $stmt->execute([$this->dataUid, $this->wsId, $intId]);
            } else {
                $like = '%' . $q . '%';
                // 模糊匹配范围：型号/编号/内部ID/名称/品牌/封装/备注
                $stmt = $this->db->prepare("SELECT id, platform_id, internal_id, platform_part_no, model, product_name, brand, package, product_type, stock, location, remark, purchase_url FROM parts WHERE user_id=? AND workspace_id=? AND (model LIKE ? OR platform_part_no LIKE ? OR internal_id LIKE ? OR product_name LIKE ? OR brand LIKE ? OR package LIKE ? OR remark LIKE ?) ORDER BY model LIMIT 50");
                $stmt->execute([$this->dataUid, $this->wsId, $like, $like, $like, $like, $like, $like, $like]);
            }
            foreach ($stmt->fetchAll() as $p) {
                $result[] = [
                    'id'              => (int)$p['id'],
                    'internal_id'     => (int)$p['internal_id'],
                    'no'              => (string)$p['platform_part_no'],
                    'model'           => (string)$p['model'],
                    'product_name'    => (string)$p['product_name'],
                    'brand'           => (string)$p['brand'],
                    'package'         => (string)$p['package'],
                    'product_type'    => (string)($p['product_type'] ?? ''),
                    'stock'           => (int)$p['stock'],
                    'location'        => (string)$p['location'],
                    'remark'          => (string)$p['remark'],
                    'purchase_url'    => (string)($p['purchase_url'] ?? ''),
                ];
            }
        }
        return ['items' => $result];
    }

    /** BOM 替代料绑定弹窗专用：全物料分页模糊搜索 */
    public function searchPartsPaged(string $q, int $page = 1, int $perPage = 15): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        $where = ["user_id=?", "workspace_id=?", "status!='deleted'"];
        $params = [$this->dataUid, $this->wsId];

        $q = trim($q);
        if ($q !== '') {
            $keywords = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($keywords as $kw) {
                $like = '%' . $kw . '%';

                if (preg_match('/^#(\d+)$/i', $kw, $m) || preg_match('/^P(0\d+)$/i', $kw, $m)) {
                    $where[] = "(internal_id=? OR REPLACE(REPLACE(REPLACE(LOWER(model),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(product_name),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(remark),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(platform_part_no),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters_text),'-',''),' ',''),'_','') LIKE ?)";
                    $normalizedKw = normalizeSearchTerm($kw);
                    $kwLike = "%{$normalizedKw}%";
                    array_push($params, (int)$m[1], $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike);
                } else {
                    // 模糊匹配：去除连字符/空格/下划线+小写后匹配
                    $normalizedKw = normalizeSearchTerm($kw);
                    $kwLike = "%{$normalizedKw}%";

                    if (ctype_digit($kw)) {
                        $where[] = "(REPLACE(REPLACE(REPLACE(LOWER(model),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(product_name),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(remark),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(platform_part_no),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(brand),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters_text),'-',''),' ',''),'_','') LIKE ? OR internal_id=?)";
                        array_push($params, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, (int)$kw);
                    } else {
                        $where[] = "(REPLACE(REPLACE(REPLACE(LOWER(model),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(product_name),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(remark),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(platform_part_no),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(brand),'-',''),' ',''),'_','') LIKE ? OR REPLACE(REPLACE(REPLACE(LOWER(parameters_text),'-',''),' ',''),'_','') LIKE ?)";
                        array_push($params, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike, $kwLike);
                    }
                }
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts $whereSql");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;

        $rowsStmt = $this->db->prepare("SELECT id, internal_id, platform_id, platform_part_no, customer_part_no, model, product_name, brand, package, product_type, stock, location, remark FROM parts $whereSql ORDER BY update_time DESC LIMIT $perPage OFFSET $offset");
        $rowsStmt->execute($params);
        $rows = $rowsStmt->fetchAll();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'              => (int)$r['id'],
                'internal_id'     => (int)$r['internal_id'],
                'platform_id'     => (int)$r['platform_id'],
                'platform_part_no'=> (string)$r['platform_part_no'],
                'customer_part_no'=> (string)$r['customer_part_no'],
                'model'           => (string)$r['model'],
                'product_name'    => (string)$r['product_name'],
                'brand'           => (string)$r['brand'],
                'package'         => (string)$r['package'],
                'product_type'    => (string)$r['product_type'],
                'stock'           => (int)$r['stock'],
                'location'        => (string)$r['location'],
                'remark'          => (string)$r['remark'],
            ];
        }
        return [
            'list'        => $list,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_page'  => $totalPage,
            'has_more'    => $page < $totalPage,
        ];
    }

    /** 根据封装推荐替代料 */
    public function suggestAlternativesByPackage(string $package, int $excludePartId = 0): array
    {
        $package = trim($package);
        if ($package === '') return [];

        if ($excludePartId <= 0) {
            $stmt = $this->db->prepare(
                "SELECT id, model, package, stock, brand, platform_part_no
                 FROM parts
                 WHERE user_id = ? AND workspace_id = ? AND package = ? AND status!='deleted' AND id != ?
                 ORDER BY stock DESC
                 LIMIT 10"
            );
            $stmt->execute([$this->dataUid, $this->wsId, $package, $excludePartId]);
            return array_map(fn($r) => [
                'id'       => (int)$r['id'],
                'model'    => (string)$r['model'],
                'package'  => (string)$r['package'],
                'stock'    => (int)$r['stock'],
                'brand'    => (string)$r['brand'],
                'part_no'  => (string)$r['platform_part_no'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        $ctx = $this->buildMatchContext($excludePartId, '', $package, '');
        if ($ctx['package'] === '') return [];

        $candidates = $this->getCandidatesByPackageLike($ctx['package'], $excludePartId, 200);
        if (!$candidates) return [];

        $result = $this->scoreCandidates($ctx['q'], $ctx['l2'], $candidates, 10);
        return array_map(static fn(array $r) => [
            'id'          => $r['id'],
            'model'       => $r['model'],
            'package'     => $r['package'],
            'stock'       => $r['stock'],
            'brand'       => $r['brand'],
            'part_no'     => $r['platform_part_no'],
            'recommended' => $r['recommended'],
            'score'       => $r['score'],
            'score_pct'   => $r['score_pct'],
            'level'       => $r['level'],
            'reasons'     => $r['reasons'],
        ], $result['rows']);
    }

    /** 替代料差异化推荐（加权评分模型） */
    public function suggestAlternatives(string $model, string $package, string $category, int $excludeId = 0, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));
        $ctx = $this->buildMatchContext($excludeId, trim($model), trim($package), trim($category));
        if ($ctx['package'] === '') {
            return ['list' => [], 'count' => 0, 'hint' => '物料缺少封装信息，无法自动推荐，请手动搜索'];
        }

        $candidates = $ctx['l1'] !== null
            ? $this->getCandidatesByL1Category((int)$ctx['l1']['id'], $excludeId, 200)
            : $this->getCandidatesByPackageLike($ctx['package'], $excludeId, 200);
        if (empty($candidates)) {
            return ['list' => [], 'count' => 0, 'hint' => ''];
        }

        $result = $this->scoreCandidates($ctx['q'], $ctx['l2'], $candidates, $limit);
        $hint = ($result['rows'] === [] && $result['blocked'] > 0)
            ? "同品类 {$result['blocked']} 项候选存在封装或参数冲突，无可靠替代料，请手动搜索"
            : '';
        return ['list' => $result['rows'], 'count' => count($result['rows']), 'hint' => $hint];
    }

    /** 候选/原物料召回与评分所需的统一列集 */
    private const MATCH_COLUMNS = "p.id, p.internal_id, p.platform_id, p.platform_part_no, p.customer_part_no, p.model, "
        . "p.product_name, p.brand, p.package, p.product_type, p.stock, p.location, p.remark, p.status, "
        . "p.parameters_text";

    /** 构建「原物料」匹配上下文 */
    private function buildMatchContext(int $excludeId, string $model, string $package, string $category): array
    {
        $row  = $excludeId > 0 ? $this->loadPartForMatch($excludeId) : null;
        $cats = $excludeId > 0 ? $this->getPartCategoriesWithHierarchy($excludeId) : [];
        [$l1, $l2] = self::pickL1L2($cats);

        // 分类名：优先入参（调用方已知），其次物料自身分类挂载
        $catName = $category;
        if ($catName === '') $catName = (string)($l2['name'] ?? $l1['name'] ?? '');

        $model   = $model   !== '' ? $model   : (string)($row['model'] ?? '');
        $package = $package !== '' ? $package : (string)($row['package'] ?? '');

        $paramsText = trim((string)($row['parameters_text'] ?? ''));
        if ($paramsText === '') $paramsText = $model;

        return [
            'q' => [
                'model'  => $model,
                'brand'  => (string)($row['brand'] ?? ''),
                'stock'  => (int)($row['stock'] ?? 0),
                'status' => (string)($row['status'] ?? 'active'),
                'specs'  => SpecNormalizer::extract([
                    'parameters_text' => $paramsText,
                    'product_name'    => (string)($row['product_name'] ?? ''),
                    'category'        => $catName,
                    'package'         => $package,
                ]),
            ],
            'package'  => $package,
            'category' => $catName,
            'l1'       => $l1,
            'l2'       => $l2,
        ];
    }

    /** 读取参与匹配的原物料行（限定当前数据归属与工作空间） */
    private function loadPartForMatch(int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $this->db->prepare(
            "SELECT id, model, product_name, brand, package, stock, status, parameters_text
             FROM parts WHERE id=? AND user_id=? AND workspace_id=?"
        );
        $stmt->execute([$id, $this->dataUid, $this->wsId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 从分类挂载中取 L1/L2（L2 = 挂在某个 L1 下的子分类） */
    private static function pickL1L2(array $cats): array
    {
        $l1 = null; $l2 = null;
        foreach ($cats as $c) {
            if ((int)$c['parent_id'] > 0 && isset($cats[(int)$c['parent_id']])) {
                $l2 = $c;
                $l1 = $cats[(int)$c['parent_id']];
                break;
            }
        }
        if ($l1 === null) {
            foreach ($cats as $c) {
                if ((int)$c['parent_id'] === 0) { $l1 = $c; break; }
            }
        }
        return [$l1, $l2];
    }

    /** 候选召回（原物料无分类信息时的兜底）：按归一化封装的等价写法 LIKE 粗筛 */
    private function getCandidatesByPackageLike(string $package, int $excludeId, int $limit): array
    {
        $terms = SpecNormalizer::packageSearchTerms($package);
        if (!$terms) return [];
        $limit = max(1, min(300, $limit));

        $like = implode(' OR ', array_fill(0, count($terms), 'p.package LIKE ?'));
        $sql = "SELECT " . self::MATCH_COLUMNS . " FROM parts p
                WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted' AND ($like)";
        $params = [$this->dataUid, $this->wsId];
        foreach ($terms as $t) $params[] = '%' . $t . '%';
        if ($excludeId > 0) { $sql .= " AND p.id<>?"; $params[] = $excludeId; }
        $sql .= " ORDER BY p.stock DESC, p.id ASC LIMIT $limit";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 候选集硬约束淘汰 + 加权评分 + 排序 */
    private function scoreCandidates(array $q, ?array $qL2, array $candidates, int $limit): array
    {
        $candCatsMap = $this->batchGetCategoriesWithHierarchy(array_column($candidates, 'id'));
        $scored = [];
        $blocked = 0;

        foreach ($candidates as $r) {
            $pid = (int)$r['id'];
            [$cL1, $cL2] = self::pickL1L2($candCatsMap[$pid] ?? []);

            $eval = AlternativeScorer::evaluate($q, [
                'model'  => (string)($r['model'] ?? ''),
                'brand'  => (string)($r['brand'] ?? ''),
                'stock'  => (int)($r['stock'] ?? 0),
                'status' => (string)($r['status'] ?? 'active'),
                'specs'  => SpecNormalizer::extract([
                    'parameters_text' => (string)($r['parameters_text'] ?? ''),
                    'product_name'    => (string)($r['product_name'] ?? ''),
                    'category'        => (string)($cL2['name'] ?? $cL1['name'] ?? ''),
                    'package'         => (string)($r['package'] ?? ''),
                ]),
            ]);
            if ($eval === null) { $blocked++; continue; }

            $scored[] = [
                'row'    => $r,
                'eval'   => $eval,
                'sameL2' => ($qL2 !== null && $cL2 !== null && (int)$qL2['id'] === (int)$cL2['id']) ? 1 : 0,
            ];
        }

        // 排序：总分降序 → 同品类优先 → 库存降序 → ID 升序（保证结果可复现）
        usort($scored, static function (array $a, array $b): int {
            if (abs($b['eval']['score'] - $a['eval']['score']) > 1.0e-9) return $b['eval']['score'] <=> $a['eval']['score'];
            if ($a['sameL2'] !== $b['sameL2']) return $b['sameL2'] <=> $a['sameL2'];
            if ((int)$a['row']['stock'] !== (int)$b['row']['stock']) return (int)$b['row']['stock'] <=> (int)$a['row']['stock'];
            return (int)$a['row']['id'] <=> (int)$b['row']['id'];
        });

        $rows = [];
        foreach (array_slice($scored, 0, $limit) as $s) {
            $r = $s['row'];
            $rows[] = [
                'id'              => (int)$r['id'],
                'internal_id'     => (int)($r['internal_id'] ?? 0),
                'platform_id'     => (int)($r['platform_id'] ?? 0),
                'platform_part_no'=> (string)($r['platform_part_no'] ?? ''),
                'customer_part_no'=> (string)($r['customer_part_no'] ?? ''),
                'model'           => (string)($r['model'] ?? ''),
                'product_name'    => (string)($r['product_name'] ?? ''),
                'brand'           => (string)($r['brand'] ?? ''),
                'package'         => (string)($r['package'] ?? ''),
                'product_type'    => (string)($r['product_type'] ?? ''),
                'stock'           => (int)($r['stock'] ?? 0),
                'location'        => (string)($r['location'] ?? ''),
                'remark'          => (string)($r['remark'] ?? ''),
                'recommended'     => $s['eval']['level'] === 'auto' ? 1 : 0,
                'score'           => $s['eval']['score'],
                'score_pct'       => $s['eval']['score_pct'],
                'level'           => $s['eval']['level'],
                'reasons'         => $s['eval']['reasons'],
                'breakdown'       => $s['eval']['breakdown'],
            ];
        }
        return ['rows' => $rows, 'blocked' => $blocked];
    }

    /** 获取物料关联的所有分类（含 L1/L2 层级） */
    private function getPartCategoriesWithHierarchy(int $partId): array
    {
        if ($partId <= 0) return [];
        $stmt = $this->db->prepare(
            "SELECT c.id, c.parent_id, c.name FROM part_categories pc
             JOIN categories c ON c.id=pc.category_id
             WHERE pc.part_id=? AND c.user_id=? AND c.workspace_id=?"
        );
        $stmt->execute([$partId, $this->dataUid, $this->wsId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            $result[$cid] = [
                'id'        => $cid,
                'parent_id' => (int)($row['parent_id'] ?? 0),
                'name'      => (string)$row['name'],
            ];
        }
        return $result;
    }

    /** 批量获取多个物料的分类层级（避免 N+1 查询） */
    private function batchGetCategoriesWithHierarchy(array $partIds): array
    {
        $result = [];
        $partIds = array_filter(array_map('intval', $partIds), fn($v) => $v > 0);
        if (empty($partIds)) return $result;
        $ph = implode(',', array_fill(0, count($partIds), '?'));
        $sql = "SELECT pc.part_id, c.id, c.parent_id, c.name FROM part_categories pc
                JOIN categories c ON c.id=pc.category_id
                WHERE c.user_id=? AND c.workspace_id=? AND pc.part_id IN ($ph)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$this->dataUid, $this->wsId], $partIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['part_id'];
            if (!isset($result[$pid])) $result[$pid] = [];
            $cid = (int)$row['id'];
            $result[$pid][$cid] = [
                'id'        => $cid,
                'parent_id' => (int)($row['parent_id'] ?? 0),
                'name'      => (string)$row['name'],
            ];
        }
        return $result;
    }

    /** 候选召回：同一级分类（含其下属 L2 子分类）下的物料 */
    private function getCandidatesByL1Category(int $l1CatId, int $excludeId, int $limit): array
    {
        if ($l1CatId <= 0) return [];
        $limit = max(1, min(300, $limit));
        $sql = "SELECT " . self::MATCH_COLUMNS . " "
             . "FROM parts p INNER JOIN part_categories pc ON pc.part_id=p.id "
             . "INNER JOIN categories c ON c.id=pc.category_id "
             . "WHERE p.user_id=? AND p.workspace_id=? AND p.status!='deleted' AND (c.id=? OR c.parent_id=?)";
        $params = [$this->dataUid, $this->wsId, $l1CatId, $l1CatId];
        if ($excludeId > 0) {
            $sql .= " AND p.id<>?";
            $params[] = $excludeId;
        }
        $sql .= " GROUP BY p.id ORDER BY p.stock DESC, p.id ASC LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 物料完整详情数据（供 detail_ajax.php 渲染抽屉使用） */
    public function getPartFullData(int $id): array
    {
        if ($id <= 0) throw new PartException('参数错误', 4);

        $partStmt = $this->db->prepare("SELECT p.*,pl.name AS pname,pl.url_template,COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$this->globalThr) AS eff_threshold FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id=? AND p.user_id=? AND p.workspace_id=?");
        $partStmt->execute([$id, $this->dataUid, $this->wsId]);
        $part = $partStmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new PartException('元件不存在', 404);

        // 平台类型
        $platType = 'standard';
        try {
            $ptStmt = $this->db->prepare("SELECT platform_type FROM platforms WHERE id=?");
            $ptStmt->execute([$part['platform_id']]);
            $ptRow = $ptStmt->fetch();
            if ($ptRow && in_array($ptRow['platform_type'] ?? '', ['standard','loose'], true)) {
                $platType = $ptRow['platform_type'];
            }
        } catch (Throwable) {}

        // 关联标准物料
        $linkedPart = null;
        if (!empty($part['linked_part_id'])) {
            $lpStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
            $lpStmt->execute([$part['linked_part_id'], $this->dataUid, $this->wsId]);
            $linkedPart = $lpStmt->fetch(PDO::FETCH_ASSOC);
        }

        // 散料采购渠道
        $bulkStmt = $this->db->prepare("SELECT id, model, platform_part_no, product_name, stock, purchase_url FROM parts WHERE linked_part_id=? AND user_id=? AND workspace_id=?");
        $bulkStmt->execute([$id, $this->dataUid, $this->wsId]);
        $bulkParts = $bulkStmt->fetchAll();

        $altParts = [];
        try {
            $altStmt = $this->db->prepare("
                SELECT p.id, p.platform_id, p.internal_id, p.model, p.platform_part_no, p.product_name, p.stock, p.purchase_url
                FROM parts p
                INNER JOIN part_alternatives pa ON pa.target_part_id = p.id
                WHERE pa.source_part_id = ? AND p.user_id=? AND p.workspace_id=?
                UNION
                SELECT p.id, p.platform_id, p.internal_id, p.model, p.platform_part_no, p.product_name, p.stock, p.purchase_url
                FROM parts p
                INNER JOIN part_alternatives pa ON pa.source_part_id = p.id
                WHERE pa.target_part_id = ? AND p.user_id=? AND p.workspace_id=?
            ");
            $altStmt->execute([$id, $this->dataUid, $this->wsId, $id, $this->dataUid, $this->wsId]);
            $altParts = $altStmt->fetchAll();
        } catch (Throwable) {}

        // 分类
        $catsStmt = $this->db->prepare("SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
        $catsStmt->execute([$id]);
        $cats = array_column($catsStmt->fetchAll(), 'name');

        $pricesStmt = $this->db->prepare("SELECT unit_cost AS unit_price, qty_change AS qty, order_time, create_time, source_type, remark FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time ASC");
        $pricesStmt->execute([$id]);
        $prices = $pricesStmt->fetchAll();

        $stockHistStmt = $this->db->prepare("SELECT qty_after,create_time FROM stock_log WHERE part_id=? ORDER BY create_time ASC");
        $stockHistStmt->execute([$id]);
        $stockHist = $stockHistStmt->fetchAll();

        // 成本折线数据
        $costHistStmt = $this->db->prepare("SELECT qty_after,unit_cost,create_time,is_sample FROM stock_log WHERE part_id=? AND qty_change>0 AND unit_cost>0 ORDER BY create_time ASC");
        $costHistStmt->execute([$id]);
        $costHist = $costHistStmt->fetchAll();

        // 出入库记录（最近10条）
        $logLimit = 10;
        $logStmt = $this->db->prepare("SELECT * FROM stock_log WHERE part_id=? ORDER BY create_time DESC LIMIT ?");
        $logStmt->execute([$id, $logLimit]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM stock_log WHERE part_id=?");
        $cntStmt->execute([$id]); $logTotal = (int)$cntStmt->fetchColumn();

        // 资产成本
        $cost = $this->getCostSummary($id);

        // 图表数据
        $stockData = count($stockHist) >= 2 ? [
            'labels' => array_map(fn($r) => substr($r['create_time'], 0, 10), $stockHist),
            'values' => array_map(fn($r) => (int)$r['qty_after'], $stockHist),
        ] : null;
        $priceData = count($prices) >= 2 ? [
            'labels' => array_map(fn($r) => substr(($r['order_time'] ?? null) ?: ($r['create_time'] ?? ''), 0, 10), $prices),
            'values' => array_map(fn($r) => (float)$r['unit_price'], $prices),
        ] : null;
        $costData = count($costHist) >= 2 ? [
            'labels'     => array_map(fn($r) => substr($r['create_time'], 0, 10), $costHist),
            'qty_values' => array_map(fn($r) => (int)$r['qty_after'], $costHist),
            'cost_values'=> array_map(fn($r) => (float)$r['unit_cost'], $costHist),
        ] : null;

        // 个人版SQL层成本隔离：剔除价格字段
        if (!can_view_cost()) {
            $part['avg_cost'] = 0;
            $part['last_purchase_price'] = 0;
            $prices = [];
            $costHist = [];
            $priceData = null;
            $costData = null;
        }

        return [
            'part'        => $part,
            'platType'    => $platType,
            'linkedPart'  => $linkedPart,
            'bulkParts'   => $bulkParts,
            'altParts'    => $altParts,
            'cats'        => $cats,
            'prices'      => $prices,
            'stockHist'   => $stockHist,
            'costHist'    => $costHist,
            'logs'        => $logs,
            'logTotal'    => $logTotal,
            'totalAsset'  => $cost['total_asset'],
            'latestCost'  => $cost['latest_cost'],
            'globalThr'   => $this->globalThr,
            'stockData'   => $stockData,
            'priceData'   => $priceData,
            'costData'    => $costData,
        ];
    }

    /** 添加元件 */
    public function addPart(array $p): array
    {
        $ppn       = trim((string)($p['platform_part_no'] ?? ''));
        $platId    = intval($p['platform_id'] ?? 1);
        $stock     = intval($p['stock'] ?? 0);
        $thrRaw    = trim((string)($p['low_stock_threshold'] ?? ''));
        $thrVal    = $thrRaw === '' ? null : max(0, (int)$thrRaw);
        $purchaseUrl = trim((string)($p['purchase_url'] ?? ''));
        $linkedPartIdRaw = trim((string)($p['linked_part_id'] ?? ''));
        $linkedPartId = $linkedPartIdRaw !== '' ? intval($linkedPartIdRaw) : null;
        $unitCost  = round((float)($p['unit_cost'] ?? 0), 4);
        $isSample  = !empty($p['is_sample']) ? 1 : 0;
        $parameters = trim((string)($p['parameters'] ?? ''));
        $alternatives = trim((string)($p['alternatives'] ?? ''));
        $datasheet = trim((string)($p['datasheet'] ?? ''));
        if ($datasheet !== '' && !preg_match('#^https?://#i', $datasheet)) {
            throw new PartException('数据手册链接必须以 http:// 或 https:// 开头', 2);
        }

        $ptype = trim((string)($p['product_type'] ?? ''));

        $paramsText = \App\Helper\Spec\SpecNormalizer::buildText([
            'parameters'   => $parameters,
            'model'        => trim((string)($p['model'] ?? '')),
            'product_name' => trim((string)($p['product_name'] ?? '')),
            'category'     => $ptype,
            'package'      => trim((string)($p['package'] ?? '')),
        ]);

        // 平台类型联动校验
        $currentPlatType = $this->getPlatType($platId);
        if ($currentPlatType === 'standard') {
            $purchaseUrl = '';
            $linkedPartId = null;
            if ($ppn === '') throw new PartException('标准商城平台必须填写商品编号', 2);
        } else {
            if ($purchaseUrl === '') throw new PartException('散货渠道平台必须填写采购链接', 2);
            if (!preg_match('#^https?://#i', $purchaseUrl)) throw new PartException('采购链接必须以 http:// 或 https:// 开头', 2);
        }

        // 验证 linked_part_id
        if ($linkedPartId !== null && $linkedPartId > 0) {
            $linkCheck = $this->db->prepare("SELECT id FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
            $linkCheck->execute([$linkedPartId, $this->dataUid, $this->wsId]);
            if (!$linkCheck->fetch()) $linkedPartId = null;
        } else {
            $linkedPartId = null;
        }

        $ppnInsert = $ppn !== '' ? $ppn : null;

        // 生成全平台唯一的 internal_id（带重试机制，防止并发冲突）
        $retry = 0;
        $newId = 0;
        $nextInternalId = 0; // 预初始化（循环必定执行，此处仅为消除静态分析误报）
        while ($retry < 3) {
            $maxIdStmt = $this->db->prepare("SELECT COALESCE(MAX(internal_id),0) FROM parts WHERE user_id=? AND workspace_id=?");
            $maxIdStmt->execute([$this->dataUid, $this->wsId]);
            $nextInternalId = (int)$maxIdStmt->fetchColumn() + 1;
            try {
                $this->db->prepare("INSERT INTO parts (user_id,workspace_id,platform_id,internal_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,stock,low_stock_threshold,location,remark,purchase_url,linked_part_id,datasheet,parameters,parameters_text,alternatives) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$this->dataUid,$this->wsId,$platId,$nextInternalId,$ppnInsert,trim((string)($p['customer_part_no']??'')),trim((string)($p['model']??'')),
                     trim((string)($p['product_name']??'')),trim((string)($p['product_type']??'')),trim((string)($p['package']??'')),
                     trim((string)($p['brand']??'')),$stock,$thrVal,
                     trim((string)($p['location']??'')),trim((string)($p['remark']??'')),$purchaseUrl,$linkedPartId,$datasheet,
                     $parameters,$paramsText,$alternatives]);
                $newId = (int)$this->db->lastInsertId();
                break;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'Duplicate entry') !== false) {

                    if (strpos($msg, 'internal_id') !== false || strpos($msg, 'uq_user_internal') !== false || strpos($msg, 'uq_user_platform_internal') !== false) {
                        $retry++;
                        continue;
                    }
                    throw new PartException('该平台下商品编号已存在', 2);
                }
                throw $e;
            }
        }
        if ($newId === 0) throw new PartException('内部ID生成失败，请重试', 3);
        $pid = $newId;

        $finalPpn = $ppn;
        if ($ppn === '' && $nextInternalId > 0) {
            $finalPpn = '#' . $nextInternalId . '内部';
            $this->db->prepare("UPDATE parts SET platform_part_no=? WHERE id=? AND user_id=? AND workspace_id=?")
               ->execute([$finalPpn, $pid, $this->dataUid, $this->wsId]);
        }
        if ($stock > 0) {
            $subtotal = $unitCost > 0 ? round($stock * $unitCost, 4) : 0;
            \insertStockLog($this->db, [
                'user_id'          => $this->uid,
                'part_id'          => $pid,
                'platform_part_no' => $finalPpn,
                'change_type'      => 'manual_in',
                'qty_change'       => $stock,
                'qty_before'       => 0,
                'qty_after'        => $stock,
                'unit_cost'        => $unitCost,
                'is_sample'        => $isSample,
                'subtotal'         => $subtotal,
                'remark'           => '初始入库',
                'workspace_id'     => $this->wsId,
            ]);

            \ensureStockRecord($pid, 0);
        }
        if ($ptype) linkCategories($pid, $this->dataUid, parseCategories($ptype));

        // 替代料同步写入 part_alternatives 表
        if ($alternatives !== '') {
            $this->syncAlternativesToTable($pid, $alternatives);
        }

        traceLog($this->uid, 'add_part', 'part', $pid, "添加元件 id:{$pid} 编号:{$ppn} 平台类型:{$currentPlatType}");
        return ['part_id' => $pid];
    }

    /** 编辑元件 */
    public function editPart(array $p): array
    {
        $id    = intval($p['id'] ?? 0);
        $ptype = trim((string)($p['product_type'] ?? ''));
        $alts  = trim((string)($p['alternatives'] ?? ''));
        $thrRaw = trim((string)($p['low_stock_threshold'] ?? ''));
        $thrVal = $thrRaw === '' ? null : max(0, (int)$thrRaw);
        $purchaseUrl = trim((string)($p['purchase_url'] ?? ''));
        $linkedPartIdRaw = trim((string)($p['linked_part_id'] ?? ''));
        $linkedPartId = $linkedPartIdRaw !== '' ? intval($linkedPartIdRaw) : null;
        $editPpn = trim((string)($p['platform_part_no'] ?? ''));

        $datasheetRaw = trim((string)($p['datasheet'] ?? ''));
        $datasheetSet = !empty($p['datasheet_clear']);
        $datasheet = '';
        if ($datasheetRaw !== '') {
            if (!preg_match('#^https?://#i', $datasheetRaw)) {
                throw new PartException('数据手册链接必须以 http:// 或 https:// 开头', 2);
            }
            $datasheet = $datasheetRaw;
            $datasheetSet = true;
        }

        // 查询当前物料所属平台
        $curPlatStmt = $this->db->prepare("SELECT platform_id FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
        $curPlatStmt->execute([$id, $this->dataUid, $this->wsId]);
        $curPlatRow = $curPlatStmt->fetch(PDO::FETCH_ASSOC);
        if (!$curPlatRow) throw new PartException('元件不存在或无权编辑', 3);

        $currentPlatType = $this->getPlatType((int)$curPlatRow['platform_id']);
        if ($currentPlatType === 'standard') {
            $purchaseUrl = '';
            $linkedPartId = null;
            if ($editPpn === '') throw new PartException('标准商城平台必须填写商品编号', 2);
        } else {
            if ($purchaseUrl === '') throw new PartException('散货渠道平台必须填写采购链接', 2);
            if (!preg_match('#^https?://#i', $purchaseUrl)) throw new PartException('采购链接必须以 http:// 或 https:// 开头', 2);
        }

        // 验证 linked_part_id
        if ($linkedPartId !== null && $linkedPartId > 0) {
            $linkCheck = $this->db->prepare("SELECT id FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
            $linkCheck->execute([$linkedPartId, $this->dataUid, $this->wsId]);
            if (!$linkCheck->fetch()) $linkedPartId = null;
        } else {
            $linkedPartId = null;
        }

        $editPpnInsert = $editPpn !== '' ? $editPpn : null;
        $parameters = trim((string)($p['parameters'] ?? ''));

        $paramsText = \App\Helper\Spec\SpecNormalizer::buildText([
            'parameters'   => $parameters,
            'model'        => trim((string)($p['model'] ?? '')),
            'product_name' => trim((string)($p['product_name'] ?? '')),
            'category'     => $ptype,
            'package'      => trim((string)($p['package'] ?? '')),
        ]);
        $setSql = "UPDATE parts SET platform_part_no=?,customer_part_no=?,model=?,product_name=?,product_type=?,package=?,brand=?,low_stock_threshold=?,location=?,remark=?,purchase_url=?,linked_part_id=?,parameters=?";
        $setArgs = [$editPpnInsert,trim((string)($p['customer_part_no']??'')),
            trim((string)($p['model']??'')),trim((string)($p['product_name']??'')),$ptype,trim((string)($p['package']??'')),
            trim((string)($p['brand']??'')),$thrVal,
            trim((string)($p['location']??'')),trim((string)($p['remark']??'')),$purchaseUrl,$linkedPartId,
            $parameters];
        if ($paramsText !== '') {
            $setSql .= ',parameters_text=?';
            $setArgs[] = $paramsText;
        }
        if ($datasheetSet) {
            $setSql .= ',datasheet=?';
            $setArgs[] = $datasheet;
        }
        $setSql .= ' WHERE id=? AND user_id=? AND workspace_id=?';
        $setArgs[] = $id;
        $setArgs[] = $this->dataUid;
        $setArgs[] = $this->wsId;
        $this->db->prepare($setSql)->execute($setArgs);
        $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);

        if ($ptype) linkCategories($id, $this->dataUid, parseCategories($ptype));

        // 替代料变更时同步写入 part_alternatives 表
        if ($alts !== '') {
            $this->syncAlternativesToTable($id, $alts);
        } else {
            // 清空 part_alternatives 表中该物料的关联
            try {
                $this->db->prepare("DELETE FROM part_alternatives WHERE source_part_id=?")->execute([$id]);
            } catch (Throwable $e) {}
        }

        traceLog($this->uid, 'edit_part', 'part', $id, "编辑元件 id:{$id}");
        return ['part_id' => $id];
    }

    /** 删除元件（软删除：设置status='deleted'，保留历史数据） */
    public function deletePart(int $id): array
    {
        $check = $this->db->prepare("SELECT id, status FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
        $check->execute([$id, $this->dataUid, $this->wsId]);
        $row = $check->fetch();
        if (!$row) throw new PartException('元件不存在', 3);
        if (($row['status'] ?? 'active') === PartStatus::DELETED) throw new PartException('元件已删除', 3);
        cleanupAlternativesReverseLinks([$id], $this->dataUid);
        $this->db->prepare("UPDATE parts SET status='deleted', update_time=NOW() WHERE id=? AND user_id=? AND workspace_id=?")->execute([$id, $this->dataUid, $this->wsId]);
        traceLog($this->uid, 'delete_part', 'part', $id, "软删除元件 id:{$id}");
        return ['part_id' => $id];
    }

    /** 批量软删除（设置status='deleted'，保留历史数据） */
    public function batchDelete(array $ids): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);

        $in = implode(',', array_fill(0, count($validIds), '?'));
        $chk = $this->db->prepare("SELECT id, status FROM parts WHERE id IN ($in) AND user_id=? AND workspace_id=?");
        $chk->execute([...$validIds, $this->dataUid, $this->wsId]);
        $deletable = [];
        $skipped = 0;
        foreach ($chk->fetchAll() as $r) {
            if (($r['status'] ?? 'active') !== PartStatus::DELETED) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }
        if (empty($deletable)) {
            throw new PartException('所选物料均已删除', 5);
        }
        cleanupAlternativesReverseLinks($deletable, $this->dataUid);
        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("UPDATE parts SET status='deleted', update_time=NOW() WHERE id IN ($inV) AND user_id=? AND workspace_id=?")->execute([...$deletable, $this->dataUid, $this->wsId]);
        traceLog($this->uid, 'batch_delete_parts', 'part', 0, "批量软删除元件 count:" . count($deletable) . ($skipped > 0 ? " skipped:{$skipped}" : ''));
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }

    /** 批量设置分类 */
    public function batchSetCategory(array $ids, int $catId, string $newCat): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        if ($newCat !== '') {
            $catId = getOrCreateCategory($this->dataUid, $newCat);
        }
        if ($catId <= 0) throw new PartException('请选择分类', 2);

        // 批量改分类：两条语句搞定，不按物料逐条循环。

        $in = implode(',', array_fill(0, count($validIds), '?'));
        $this->db->prepare("DELETE FROM part_categories WHERE part_id IN ($in)")->execute($validIds);

        $vals = implode(',', array_fill(0, count($validIds), '(?,?)'));
        $args = [];
        foreach ($validIds as $pid) { $args[] = $pid; $args[] = $catId; }
        $this->db->prepare("INSERT IGNORE INTO part_categories (part_id, category_id) VALUES $vals")->execute($args);

        traceLog($this->uid, 'batch_set_category', 'part', 0, "批量设置分类 count:" . count($validIds) . " cat_id:{$catId}");
        return ['updated' => count($validIds)];
    }

    /** 批量设置库位（记录前后对比日志） */
    public function batchSetLocation(array $ids, string $location): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        $inV = implode(',', array_fill(0, count($validIds), '?'));
        // 记录库位修改前后对比
        $oldLocStmt = $this->db->prepare("SELECT id, location FROM parts WHERE id IN ($inV) AND user_id=? AND workspace_id=?");
        $oldLocStmt->execute([...$validIds, $this->dataUid, $this->wsId]);
        $oldLocations = $oldLocStmt->fetchAll(PDO::FETCH_ASSOC);
        $this->db->prepare("UPDATE parts SET location=? WHERE id IN ($inV) AND user_id=? AND workspace_id=?")
           ->execute([$location, ...$validIds, $this->dataUid, $this->wsId]);
        $locChanges = [];
        foreach ($oldLocations as $old) {
            $locChanges[] = "id:{$old['id']} [{$old['location']}]→[{$location}]";
        }
        traceLog($this->uid, 'batch_set_location', 'part', 0, "批量修改库位:{$location} 详情:" . implode('; ', $locChanges));
        return ['updated' => count($validIds)];
    }

    /** 批量设置备注 */
    public function batchSetRemark(array $ids, string $remark): array
    {
        if (empty($ids)) throw new PartException('未选择元件', 2);
        $validIds = $this->filterValidIds($ids);
        if (empty($validIds)) throw new PartException('无有效元件', 2);
        $inV = implode(',', array_fill(0, count($validIds), '?'));
        $this->db->prepare("UPDATE parts SET remark=? WHERE id IN ($inV) AND user_id=? AND workspace_id=?")
           ->execute([$remark, ...$validIds, $this->dataUid, $this->wsId]);
        traceLog($this->uid, 'batch_set_remark', 'part', 0, "批量设置备注 count:" . count($validIds));
        return ['updated' => count($validIds)];
    }

    /** 疑似重复物料分组（model+brand+package 三元组聚合） */
    public function duplicateGroups(string $q = ''): array
    {
        $kw = trim($q);
        $kwWhere = $kw !== '' ? " AND model LIKE ?" : '';
        $bind = [$this->dataUid, $this->wsId];
        if ($kw !== '') $bind[] = '%' . $kw . '%';

        // 1. 找重复组键（同 user+ws、未删、model 非空）
        $grpStmt = $this->db->prepare(
            "SELECT model, brand, package, COUNT(*) AS cnt
             FROM parts
             WHERE user_id=? AND workspace_id=? AND status!='deleted' AND model<>''{$kwWhere}
             GROUP BY model, brand, package
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC, model ASC
             LIMIT 50"
        );
        $grpStmt->execute($bind);
        $groups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$groups) return ['groups' => []];

        // 2. 逐组取明细（组数 ≤50，循环可控）
        $detailStmt = $this->db->prepare(
            "SELECT id, internal_id, platform_part_no, model, brand, package, product_name,
                    stock, avg_cost, location, low_stock_threshold
             FROM parts
             WHERE user_id=? AND workspace_id=? AND status!='deleted' AND model=? AND brand<=>? AND package<=>?"
        );
        $out = [];
        foreach ($groups as $g) {
            $detailStmt->execute([$this->dataUid, $this->wsId, $g['model'], $g['brand'], $g['package']]);
            $parts = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($parts as &$p) {
                $p['id'] = (int)$p['id'];
                $p['stock'] = (int)$p['stock'];
                $p['avg_cost'] = can_view_cost() ? round((float)$p['avg_cost'], 4) : null;
                $p['low_stock_threshold'] = $p['low_stock_threshold'] !== null ? (int)$p['low_stock_threshold'] : null;
            }
            unset($p);
            $out[] = [
                'model'  => (string)$g['model'],
                'brand'  => (string)$g['brand'],
                'package'=> (string)$g['package'],
                'cnt'    => (int)$g['cnt'],
                'parts'  => $parts,
            ];
        }
        return ['groups' => $out];
    }

    /** 批量合并重复物料（P10 核心：单事务，源物料软删，可追溯可恢复） */
    public function mergeParts(int $targetId, array $sourceIds): array
    {
        $targetId = (int)$targetId;
        if ($targetId <= 0) throw new PartException('参数无效：缺少主物料', 2);
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), fn($v) => $v > 0)));
        if (!$sourceIds) throw new PartException('请选择要合并的重复物料', 2);
        if (in_array($targetId, $sourceIds, true)) throw new PartException('主物料不能同时作为源物料', 2);

        // ── 事务 ──
        $this->db->beginTransaction();
        try {
            // 锁定主/源行（防合并期间并发出入库），并校验归属与状态
            $allIds = array_merge([$targetId], $sourceIds);
            $inAll  = implode(',', array_fill(0, count($allIds), '?'));
            $lock = $this->db->prepare(
                "SELECT id, model, platform_part_no, stock, damaged, avg_cost, low_stock_threshold,
                        location, location_id, purchase_url, parameters, remark
                 FROM parts WHERE id IN ($inAll) AND user_id=? AND workspace_id=? AND status!='deleted' FOR UPDATE"
            );
            $lock->execute([...$allIds, $this->dataUid, $this->wsId]);
            $rows = [];
            foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
            if (!isset($rows[$targetId])) throw new PartException('主物料不存在或已删除', 3);
            $srcRows = [];
            foreach ($sourceIds as $sid) {
                if (!isset($rows[$sid])) throw new PartException("源物料 #{$sid} 不存在或已删除", 3);
                $srcRows[$sid] = $rows[$sid];
            }
            $target = $rows[$targetId];

            // ── 预检流水行数──
            $inSrc = implode(',', array_fill(0, count($sourceIds), '?'));
            $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM stock_log WHERE part_id IN ($inSrc)");
            $cntStmt->execute($sourceIds);
            $logCount = (int)$cntStmt->fetchColumn();
            if ($logCount > 20000) {
                throw new PartException("源物料出入库流水共 {$logCount} 行，超过单次合并上限 20000 行，请先导出备份并分批处理", 4);
            }

            $stat = ['stock_moved' => 0, 'logs_moved' => 0, 'bom_rows_merged' => 0, 'alt_rows_fixed' => 0, 'cats_merged' => 0];

            // ── 1. stock 独立表行级合并（同库位累加 / 异库位转移）──

            $tgtLocStmt = $this->db->prepare("SELECT id, location_id, quantity, damaged FROM stock WHERE part_id=?");
            $tgtLocStmt->execute([$targetId]);
            $tgtLocs = []; // location_id => stock 行 id
            foreach ($tgtLocStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $tgtLocs[$r['location_id'] ?? 0] = (int)$r['id'];

            $srcIn = implode(',', array_fill(0, count($sourceIds), '?'));
            $allSrcStmt = $this->db->prepare(
                "SELECT id, part_id, location_id, quantity, damaged FROM stock WHERE part_id IN ($srcIn)"
            );
            $allSrcStmt->execute($sourceIds);

            $srcByLoc = [];
            foreach ($allSrcStmt->fetchAll(PDO::FETCH_ASSOC) as $srow) {
                $srcByLoc[$srow['location_id'] ?? 0][] = $srow;
            }
            foreach ($srcByLoc as $locId => $srows) {
                // 累加量一次求和（同库位多行合并为一条 UPDATE）
                $sumQ = 0; $sumD = 0; $firstRowId = 0;
                foreach ($srows as $srow) {
                    $sumQ += (int)$srow['quantity'];
                    $sumD += (int)$srow['damaged'];
                    if ($firstRowId === 0) $firstRowId = (int)$srow['id'];
                    $stat['stock_moved'] += (int)$srow['quantity'] + (int)$srow['damaged'];
                }
                if (isset($tgtLocs[$locId])) {
                    // 同库位：累加到主行，删源行
                    $this->db->prepare("UPDATE stock SET quantity=quantity+?, damaged=damaged+? WHERE id=?")
                             ->execute([$sumQ, $sumD, $tgtLocs[$locId]]);
                    $delIds = array_column($srows, 'id');
                    $delIn = implode(',', array_fill(0, count($delIds), '?'));
                    $this->db->prepare("DELETE FROM stock WHERE id IN ($delIn)")->execute($delIds);
                } else {
                    // 异库位：把该库位下**第一行**转移归属到主物料，其余直接删

                    $this->db->prepare("UPDATE stock SET part_id=? WHERE id=?")->execute([$targetId, $firstRowId]);
                    $restIds = array_slice(array_column($srows, 'id'), 1);
                    if ($restIds) {
                        $restIn = implode(',', array_fill(0, count($restIds), '?'));
                        $this->db->prepare("DELETE FROM stock WHERE id IN ($restIn)")->execute($restIds);
                    }
                    $tgtLocs[$locId] = $firstRowId;
                }
            }
            // parts 库存缓存回写（主物料）
            $this->db->prepare(
                "UPDATE parts p SET p.stock=(SELECT COALESCE(SUM(s.quantity),0) FROM stock s WHERE s.part_id=p.id),
                                  p.damaged=(SELECT COALESCE(SUM(s.damaged),0) FROM stock s WHERE s.part_id=p.id)
                 WHERE p.id=?"
            )->execute([$targetId]);

            // ── 2. 属性迁移（仅主物料为空时采用源值，逐源判断首个非空值）──
            $migFields = ['low_stock_threshold' => null, 'location' => null, 'location_id' => null, 'purchase_url' => null, 'parameters' => null];
            foreach ($srcRows as $sid => $s) {
                if ($migFields['low_stock_threshold'] === null && $s['low_stock_threshold'] !== null) $migFields['low_stock_threshold'] = $s['low_stock_threshold'];
                if (($migFields['location'] === null || $migFields['location'] === '') && $s['location'] !== '') { $migFields['location'] = $s['location']; $migFields['location_id'] = $s['location_id']; }
                if (($migFields['purchase_url'] ?? '') === '' && $s['purchase_url'] !== '') $migFields['purchase_url'] = $s['purchase_url'];
                if (($migFields['parameters'] ?? '') === '' && $s['parameters'] !== '') $migFields['parameters'] = $s['parameters'];
            }
            $setParts = []; $migDesc = [];
            if ($target['low_stock_threshold'] === null && $migFields['low_stock_threshold'] !== null) { $setParts[] = 'low_stock_threshold=?'; $migDesc[] = '阈值'; }
            if (($target['location'] ?? '') === '' && $target['location_id'] === null && $migFields['location'] !== null) { $setParts[] = 'location=?'; $setParts[] = 'location_id=?'; $migDesc[] = '库位'; }
            if (($target['purchase_url'] ?? '') === '' && $migFields['purchase_url'] !== null) { $setParts[] = 'purchase_url=?'; $migDesc[] = '采购链接'; }
            if (($target['parameters'] ?? '') === '' && $migFields['parameters'] !== null) { $setParts[] = 'parameters=?'; $migDesc[] = '参数'; }
            if ($setParts) {
                $binds = [];
                foreach ($setParts as $i => $_) {
                    // 按字段顺序取迁移值
                    $col = explode('=', $setParts[$i])[0];
                    $binds[] = $migFields[$col];
                }
                $binds[] = $targetId;
                $this->db->prepare("UPDATE parts SET " . implode(',', $setParts) . " WHERE id=?")->execute($binds);
            }

            // ── 3. 流水重定向（分批 500，按 id 区间）──
            $batch = 500;
            foreach (['stock_log', 'scan_log', 'price_history'] as $tbl) {
                $minStmt = $this->db->prepare("SELECT MIN(id), MAX(id) FROM {$tbl} WHERE part_id IN ($inSrc)");
                $minStmt->execute($sourceIds);
                [$minId, $maxId] = $minStmt->fetch(PDO::FETCH_NUM) ?: [null, null];
                if ($minId === null) continue;
                for ($lo = (int)$minId; $lo <= (int)$maxId; $lo += $batch) {
                    $hi = $lo + $batch - 1;
                    $up = $this->db->prepare("UPDATE {$tbl} SET part_id=? WHERE id BETWEEN ? AND ? AND part_id IN ($inSrc)");
                    $up->execute(array_merge([$targetId, $lo, $hi], $sourceIds));
                    $stat['logs_moved'] += $up->rowCount();
                }
            }

            // ── 4. 分类合并（INSERT IGNORE 去重并入主物料，随后删源行）──
            $cp = $this->db->prepare("INSERT IGNORE INTO part_categories (part_id, category_id) SELECT ?, category_id FROM part_categories WHERE part_id IN ($inSrc)");
            $cp->execute(array_merge([$targetId], $sourceIds));
            $stat['cats_merged'] += $cp->rowCount();
            $del = $this->db->prepare("DELETE FROM part_categories WHERE part_id IN ($inSrc)");
            $del->execute($sourceIds);

            // ── 5. bom_items：同 project+version 内 qty 累加到主行，其余转移 ──
            $hasVer = false;
            try {
                $hasVer = (bool)$this->db->query("SHOW COLUMNS FROM bom_items LIKE 'version_id'")->fetch();
            } catch (Throwable $e) {}
            $verExpr = $hasVer ? 'IFNULL(version_id,0)' : '0';
            $srcBom = $this->db->prepare(
                "SELECT id, project_id, qty, {$verExpr} AS vkey FROM bom_items WHERE part_id IN ($inSrc)"
            );
            $srcBom->execute($sourceIds);

            $mainMap = [];   // "project|version" => ['id'=>, 'qty'=>]
            {
                $mainRows = $this->db->prepare(
                    "SELECT id, project_id, {$verExpr} AS vkey, qty FROM bom_items WHERE part_id=?"
                );
                $mainRows->execute([$targetId]);
                foreach ($mainRows->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                    $mainMap[$mr['project_id'] . '|' . $mr['vkey']] = ['id' => (int)$mr['id'], 'qty' => (int)$mr['qty']];
                }
            }
            foreach ($srcBom->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $mkey = (int)$b['project_id'] . '|' . $b['vkey'];
                if (isset($mainMap[$mkey])) {
                    // 同 project+version 已有主行：累加后删本行
                    $this->db->prepare("UPDATE bom_items SET qty=qty+? WHERE id=?")
                             ->execute([(int)$b['qty'], $mainMap[$mkey]['id']]);
                    $mainMap[$mkey]['qty'] += (int)$b['qty'];
                    $this->db->prepare("DELETE FROM bom_items WHERE id=?")->execute([(int)$b['id']]);
                    $stat['bom_rows_merged']++;
                } else {
                    // 无主行：本行成为主行（登记进map，后续同组行会命中它）
                    $this->db->prepare("UPDATE bom_items SET part_id=? WHERE id=?")->execute([$targetId, (int)$b['id']]);
                    $mainMap[$mkey] = ['id' => (int)$b['id'], 'qty' => (int)$b['qty']];
                }
            }

            // ── 6~10. 逐 sid 循环改批量 IN ──

            $in = implode(',', array_fill(0, count($sourceIds), '?'));

            // ── 6. part_alternatives 双向替换（IGNORE 防唯一键冲突，清理残留与自指）──
            $a1 = $this->db->prepare("UPDATE IGNORE part_alternatives SET source_part_id=? WHERE source_part_id IN ($in)");
            $a1->execute(array_merge([$targetId], $sourceIds));
            $a2 = $this->db->prepare("UPDATE IGNORE part_alternatives SET target_part_id=? WHERE target_part_id IN ($in)");
            $a2->execute(array_merge([$targetId], $sourceIds));
            // IGNORE 跳过的冲突行（仍指向源）直接删除
            $d1 = $this->db->prepare("DELETE FROM part_alternatives WHERE source_part_id IN ($in) OR target_part_id IN ($in)");
            $d1->execute(array_merge($sourceIds, $sourceIds));
            $stat['alt_rows_fixed'] += $a1->rowCount() + $a2->rowCount();
            // 自指向行清理
            $dSelf = $this->db->prepare("DELETE FROM part_alternatives WHERE source_part_id=? AND target_part_id=?");
            $dSelf->execute([$targetId, $targetId]);

            // ── 7. attachments（part_id + target_id 双字段）──
            $this->db->prepare("UPDATE attachments SET part_id=?, target_id=? WHERE part_id IN ($in) OR (target_type='part' AND target_id IN ($in))")
                     ->execute(array_merge([$targetId, $targetId], $sourceIds, $sourceIds));

            // ── 8. parts.linked_part_id 自引用重定向 ──
            $this->db->prepare("UPDATE parts SET linked_part_id=? WHERE linked_part_id IN ($in)")
                     ->execute(array_merge([$targetId], $sourceIds));

            // ── 9. pending_items：源物料待处理记录置 resolved ──
            $this->db->prepare("UPDATE pending_items SET status='resolved', resolved_at=NOW() WHERE part_id IN ($in) AND status='pending'")
                     ->execute($sourceIds);

            // ── 10. 源物料软删 + 库存清零（防统计漏网重复计数）──
            $this->db->prepare("UPDATE parts SET status='deleted', stock=0, damaged=0 WHERE id IN ($in)")
                     ->execute($sourceIds);

            // ── 11. 主物料成本脏标记（加权平均走统一重算机制）──
            \markCostDirty($this->db, $targetId);

            $this->db->commit();
        } catch (PartException $e) {
            $this->db->rollBack();
            throw $e;
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('mergeParts: ' . $e->getMessage());
            throw new PartException('合并失败，事务已回滚：' . $e->getMessage(), 5);
        }

        // ── traceLog 全量明细（事务外，源/目标/各表行数/迁移项/操作人）──
        $srcDesc = [];
        foreach ($srcRows as $sid => $s) {
            $srcDesc[] = "#{$sid}({$s['model']},库存{$s['stock']},均价{$s['avg_cost']})";
        }
        $migText = $migDesc ? ('属性迁移:' . implode('/', $migDesc)) : '属性迁移:无';
        traceLog($this->uid, 'part_merge', 'part', $targetId,
            "物料合并 → 主物料 #{$targetId}({$target['model']}) ← " . implode(',', $srcDesc)
            . " | stock行变动:{$stat['stock_moved']} 流水重定向:{$stat['logs_moved']} BOM合并:{$stat['bom_rows_merged']} 替代料:{$stat['alt_rows_fixed']} {$migText} 备注/标签未迁移");

        return [
            'merged'          => count($sourceIds),
            'target_id'       => $targetId,
            'stock_moved'     => $stat['stock_moved'],
            'logs_moved'      => $stat['logs_moved'],
            'bom_rows_merged' => $stat['bom_rows_merged'],
            'alt_rows_fixed'  => $stat['alt_rows_fixed'],
            'moved_parts'     => array_keys($srcRows),
        ];
    }

    /** 读取平台类型（standard / loose），不存在返回 standard */
    private function getPlatType(int $platId): string
    {
        $stmt = $this->db->prepare("SELECT platform_type FROM platforms WHERE id=? AND user_id=?");
        $stmt->execute([$platId, $this->dataUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && in_array($row['platform_type'] ?? '', ['standard','loose'], true))
            ? $row['platform_type'] : 'standard';
    }

    /** 过滤出属于当前数据用户的元件 ID */
    private function filterValidIds(array $ids): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $valid = $this->db->prepare("SELECT id FROM parts WHERE id IN ($in) AND user_id=? AND workspace_id=?");
        $valid->execute([...$ids, $this->dataUid, $this->wsId]);
        return array_column($valid->fetchAll(), 'id');
    }

    /** 快捷添加替代料：增量写入 part_alternatives（双向互绑，不去重冲突、不覆盖现有） */
    public function addAlternatives(int $partId, array $altIds): bool
    {
        if ($partId <= 0) return false;
        $altIds = array_values(array_unique(array_filter($altIds, fn($v) => $v > 0 && (int)$v !== $partId)));
        if (!$altIds) return true;

        try {
            $this->db->prepare("DELETE FROM part_alternatives WHERE source_part_id=?")->execute([$partId]);
            // 读取既有双向绑定并合并新增项（去重 + 去自指）
            $binds = $this->db->query(
                "SELECT DISTINCT source_part_id, target_part_id FROM part_alternatives WHERE source_part_id IN (" . implode(',', array_merge([$partId], $altIds)) . ")"
            )->fetchAll(PDO::FETCH_ASSOC);
            $pairs = [];
            foreach ($binds as $b) $pairs[$b['source_part_id'] . '|' . $b['target_part_id']] = 1;
            $pairs[$partId . '|' . $partId] = 1; // 占位防自指
            foreach ($altIds as $aid) {
                $pairs[$partId . '|' . $aid]     = 1;
                $pairs[$aid . '|' . $partId]     = 1;
                $pairs[$aid . '|' . $aid]        = 1; // 占位防自指
            }

            $ins = $this->db->prepare("INSERT IGNORE INTO part_alternatives (source_part_id, target_part_id, level) VALUES (?,?,'full')");
            foreach ($pairs as $key => $_) {
                [$s, $t] = explode('|', $key);
                if ((int)$s === (int)$t) continue;
                $ins->execute([$s, $t]);
            }
        } catch (Throwable $e) {
            // 兼容：part_alternatives 表不存在时静默失败
            return false;
        }
        return true;
    }

    /** 替代料同步写入 part_alternatives 表 */
    private function syncAlternativesToTable(int $partId, string $alternatives): void
    {
        try {
            // 先清空该物料的旧关联
            $this->db->prepare("DELETE FROM part_alternatives WHERE source_part_id=?")->execute([$partId]);

            $altIds = array_filter(array_map('intval', explode(',', $alternatives)), fn($v) => $v > 0);
            if (empty($altIds)) return;

            $insStmt = $this->db->prepare("INSERT IGNORE INTO part_alternatives (source_part_id, target_part_id, level) VALUES (?, ?, 'full')");
            foreach ($altIds as $altId) {
                if ($altId === $partId) continue; // 排除自身
                $insStmt->execute([$partId, $altId]);
            }
        } catch (Throwable $e) {
            // 兼容：part_alternatives 表不存在时静默失败
        }
    }

    /** 查询物料附件（兼容 target_type/target_id 与旧 part_id 两种模式） */
    public function getPartAttachments(int $partId): array
    {
        if ($partId <= 0) return [];
        try {
            // 优先：新字段 target_type + target_id
            $stmt = $this->db->prepare("SELECT * FROM attachments WHERE target_type='part' AND target_id=? AND user_id=?");
            $stmt->execute([$partId, $this->dataUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) return $rows;

            // 回退：旧字段 part_id（兼容未迁移数据）
            $stmt2 = $this->db->prepare("SELECT * FROM attachments WHERE part_id=? AND user_id=?");
            $stmt2->execute([$partId, $this->dataUid]);
            return $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
