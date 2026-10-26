<?php
declare(strict_types=1);

namespace Modules\Category\Service;

use PDO;

/**
 * 规则自动归类器
 *
 * 按固定规则把物料挂到两级分类树上：
 *   一级分类 = 品牌值 或 商品类型值（可多维度并存）
 *   二级分类 = 封装格式
 *
 * 例：物料「ESP32-WROOM-32D-N4 / 品牌=ESPRESSIF(乐鑫) / 封装=SMD,25.5x18mm」
 *   → 一级「ESPRESSIF(乐鑫)」→ 二级「SMD,25.5x18mm」
 *
 * ── 关于同名二级分类 ─────────────────────────────────────────────
 * categories 表的原始约束是 UNIQUE(user_id, name)（分类名在用户内全局唯一），
 * 而「品牌→0603」与「电阻→0603」需要同名二级共存。因此本类在插入前
 * 先按 (user_id, parent_id, name) 查重；命中不了时，说明该名字已被别的
 * 一级分类占用，此时自动加限定后缀消歧（如 "0603 (ESPRESSIF(乐鑫))"），
 * 保证既不报错、也不产生歧义，且不改动表结构。
 */
class CategoryAutoClassifier
{
    /** 可作为一级分类的维度 */
    public const DIM_BRAND = 'brand';
    public const DIM_TYPE  = 'product_type';

    /** 二级分类默认取封装格式 */
    public const LEAF_PACKAGE = 'package';

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
     *   'dims'      => ['brand','product_type'], // 用哪些字段做一级分类
     *   'leaf'      => 'package',                // 二级分类取哪个字段
     *   'overwrite' => false,                    // 是否先清掉原有分类关联
     * ]
     * @return array{processed:int,linked:int,new_top:int,new_leaf:int,no_value:int}
     */
    public function classify(array $partIds, array $opts = []): array
    {
        $dims      = (array)($opts['dims'] ?? [self::DIM_BRAND, self::DIM_TYPE]);
        $leafField = (string)($opts['leaf'] ?? self::LEAF_PACKAGE);
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

            $leafName = trim((string)($r[$leafField] ?? ''));
            $linked   = 0;

            foreach ($dims as $dim) {
                $topName = trim((string)($r[$dim] ?? ''));
                if ($topName === '') continue;

                $topId = $this->ensureTop($dim, $topName);
                if ($topId <= 0) continue;

                if ($leafName === '') {
                    // 没有封装值时，一级分类本身直接挂上
                    $linkStmt->execute([$pid, $topId]);
                    $linked++;
                    continue;
                }
                $leafId = $this->ensureLeaf($topId, $leafName, $topName);
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

    /** 取或建一级分类（parent_id = NULL） */
    private function ensureTop(string $dim, string $name): int
    {
        $key = $dim . '|' . $name;
        if (isset($this->topCache[$key])) return $this->topCache[$key];

        // 根层分类有两种历史写法：分类管理页建的是 parent_id=NULL，
        // 而 getOrCreateCategory()（config.php）建的是 parent_id=0 —— 都要认，
        // 否则同一名称会被重复建出两个一级分类。
        $st = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND (parent_id IS NULL OR parent_id=0) AND name=?");
        $st->execute([$this->dataUid, $name]);
        $id = $st->fetchColumn();
        if ($id) {
            $this->topCache[$key] = (int)$id;
            return (int)$id;
        }

        $this->db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, NULL, ?)")
            ->execute([$this->dataUid, $name]);
        $id = (int)$this->db->lastInsertId();
        $this->newTop++;
        $this->topCache[$key] = $id;
        return $id;
    }

    /** 取或建二级分类（parent_id = 一级分类 id） */
    private function ensureLeaf(int $topId, string $name, string $topName): int
    {
        $key = $topId . '|' . $name;
        if (isset($this->leafCache[$key])) return $this->leafCache[$key];

        // 同一父级下已有同名 → 直接用
        $st = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND parent_id=? AND name=?");
        $st->execute([$this->dataUid, $topId, $name]);
        $id = $st->fetchColumn();
        if ($id) {
            $this->leafCache[$key] = (int)$id;
            return (int)$id;
        }

        // 全局同名但挂在别的父级下 → 加限定后缀消歧（不改表结构）
        $st2 = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
        $st2->execute([$this->dataUid, $name]);
        if ($st2->fetch()) {
            $suffix = self::shortName($topName);
            $base   = $name . ' (' . $suffix . ')';
            $name   = $base;
            $n      = 1;
            while (true) {
                $st3 = $this->db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
                $st3->execute([$this->dataUid, $name]);
                if (!$st3->fetchColumn()) break;
                $n++;
                $name = $base . ' ' . $n;
            }
        }

        $this->db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, ?, ?)")
            ->execute([$this->dataUid, $topId, $name]);
        $id = (int)$this->db->lastInsertId();
        $this->newLeaf++;
        $this->leafCache[$key] = $id;
        return $id;
    }

    /** 一级分类名做后缀时过长则截断 */
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
