<?php
declare(strict_types=1);

namespace Modules\Category\Service;

use PDO;

/**
 * 规则自动归类器
 *
 * 按**并列维度**把物料挂到分类树上（对齐立创商城 UI 的「类目 / 品牌 / 封装」三行筛选）：
 *
 *   品牌      → ESPRESSIF(乐鑫) / TI(德州仪器) / …
 *   商品类型  → 射频收发器IC / TVS管 / …
 *   封装格式  → SOT-23 / 0603 / SOIC-8 / …
 *
 * 三个维度是**并列的一级分类**（各自互不从属），每个物料在每个维度下
 * 挂一个「值」二级节点，因此按任一维度筛选都能命中。
 *
 * ── 关于同名值 ───────────────────────────────────────────────
 * categories 表的原始约束是 UNIQUE(user_id, name)（分类名在用户内全局唯一）。
 * 同一维度下的值天然唯一（同一父级），不同维度之间值撞名是极端情况，
 * 此时自动加限定后缀消歧（如 "0603 (封装格式)"），不报错、不丢数据。
 */
class CategoryAutoClassifier
{
    /** 维度：parts 表字段名 */
    public const DIM_BRAND = 'brand';
    public const DIM_TYPE  = 'product_type';
    public const DIM_PKG   = 'package';

    /** 字段名 → 一级分类显示名（三个并列维度） */
    public const DIM_LABELS = [
        self::DIM_BRAND => '品牌',
        self::DIM_TYPE  => '商品类型',
        self::DIM_PKG   => '封装格式',
    ];

    /** 默认参与归类的三个维度 */
    public const DEFAULT_DIMS = [self::DIM_BRAND, self::DIM_TYPE, self::DIM_PKG];

    private PDO $db;
    private int $dataUid;

    /** 统计：本次新建的一级 / 二级分类数 */
    private int $newTop  = 0;
    private int $newLeaf = 0;

    /** id 缓存，避免同批次重复查库 */
    private array $topCache  = [];
    private array $leafCache = [];

    public function __construct(PDO $db, int $dataUid)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
    }

    /**
     * 对指定物料执行规则归类
     *
     * @param int[] $partIds
     * @param array $opts [
     *   'dims'      => ['brand','product_type','package'], // 参与归类的维度
     *   'overwrite' => false,   // 是否先清掉原有分类关联
     * ]
     * @return array{processed:int,linked:int,new_top:int,new_leaf:int,no_value:int}
     */
    public function classify(array $partIds, array $opts = []): array
    {
        $dims      = (array)($opts['dims'] ?? self::DEFAULT_DIMS);
        $overwrite = !empty($opts['overwrite']);

        $stats = ['processed' => 0, 'linked' => 0, 'new_top' => 0, 'new_leaf' => 0, 'no_value' => 0];
        if (empty($partIds)) return $stats;

        $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));
        if (empty($ids)) return $stats;

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $this->db->prepare(
            "SELECT id, brand, product_type, package FROM parts WHERE id IN ($in) AND user_id=?"
        );
        $st->execute(array_merge($ids, [$this->dataUid]));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $stats['processed'] = count($rows);

        $linkStmt = $this->db->prepare("INSERT IGNORE INTO part_categories (part_id, category_id) VALUES (?,?)");
        $delStmt  = $this->db->prepare("DELETE FROM part_categories WHERE part_id=?");

        foreach ($rows as $r) {
            $pid = (int)$r['id'];
            if ($overwrite) $delStmt->execute([$pid]);

            $linked = 0;
            foreach ($dims as $dim) {
                $val = trim((string)($r[$dim] ?? ''));
                if ($val === '') continue;

                $label = self::DIM_LABELS[$dim] ?? $dim;
                $topId = $this->ensureTop($label);
                if ($topId <= 0) continue;

                $leafId = $this->ensureLeaf($topId, $val, $label);
                if ($leafId > 0) {
                    $linkStmt->execute([$pid, $leafId]);
                    $linked++;
                }
            }
            $stats['linked'] += $linked;
            if ($linked === 0) $stats['no_value']++;
        }

        $stats['new_top']  = $this->newTop;
        $stats['new_leaf'] = $this->newLeaf;
        return $stats;
    }

    /**
     * 对「尚未挂任何分类」的物料批量归类
     * @return array 同 classify()
     */
    public function classifyUnclassified(int $limit = 2000): array
    {
        $st = $this->db->prepare(
            "SELECT p.id FROM parts p
             LEFT JOIN part_categories pc ON pc.part_id = p.id
             WHERE p.user_id=? AND p.status<>'deleted' AND pc.part_id IS NULL
             ORDER BY p.id ASC LIMIT ?"
        );
        $st->execute([$this->dataUid, max(1, $limit)]);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
        return $this->classify($ids);
    }

    // ── 内部：分类节点的取或建 ─────────────────────────────────────

    /**
     * 取或建**维度一级分类**（品牌 / 商品类型 / 封装格式）
     *
     * 根层分类有两种历史写法：分类管理页建的是 parent_id=NULL，
     * 而 getOrCreateCategory()（config.php）建的是 parent_id=0 —— 都要认，
     * 否则同一名称会被重复建出两个一级分类。
     */
    private function ensureTop(string $label): int
    {
        if (isset($this->topCache[$label])) return $this->topCache[$label];

        $st = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND (parent_id IS NULL OR parent_id=0) AND name=?");
        $st->execute([$this->dataUid, $label]);
        $id = $st->fetchColumn();
        if ($id) {
            $this->topCache[$label] = (int)$id;
            return (int)$id;
        }

        $this->db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, NULL, ?)")
            ->execute([$this->dataUid, $label]);
        $id = (int)$this->db->lastInsertId();
        $this->newTop++;
        $this->topCache[$label] = $id;
        return $id;
    }

    /** 取或建维度下的**值节点**（父级 = 维度一级分类） */
    private function ensureLeaf(int $topId, string $value, string $label): int
    {
        $key = $topId . '|' . $value;
        if (isset($this->leafCache[$key])) return $this->leafCache[$key];

        // 同一父级下已有同名 → 直接用
        $st = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND parent_id=? AND name=?");
        $st->execute([$this->dataUid, $topId, $value]);
        $id = $st->fetchColumn();
        if ($id) {
            $this->leafCache[$key] = (int)$id;
            return (int)$id;
        }

        // 全局同名（UNIQUE(user_id,name) 硬约束）→ 加限定后缀消歧
        $st2 = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
        $st2->execute([$this->dataUid, $value]);
        if ($st2->fetch()) {
            $base   = $value . ' (' . self::shortName($label) . ')';
            $value  = $base;
            $n      = 1;
            while (true) {
                $st3 = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
                $st3->execute([$this->dataUid, $value]);
                if (!$st3->fetchColumn()) break;
                $n++;
                $value = $base . ' ' . $n;
            }
        }

        $this->db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, ?, ?)")
            ->execute([$this->dataUid, $topId, $value]);
        $id = (int)$this->db->lastInsertId();
        $this->newLeaf++;
        $this->leafCache[$key] = $id;
        return $id;
    }

    /** 限定后缀过长则截断 */
    private static function shortName(string $s): string
    {
        $s = trim($s);
        if ($s === '') return 'X';
        if (function_exists('mb_substr')) {
            return mb_strlen($s, 'UTF-8') > 12 ? mb_substr($s, 0, 12, 'UTF-8') : $s;
        }
        return strlen($s) > 12 ? substr($s, 0, 12) : $s;
    }
}
