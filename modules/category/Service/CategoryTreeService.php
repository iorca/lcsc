<?php
declare(strict_types=1);

namespace Modules\Category\Service;

use PDO;

/** 分类树服务（P5 多级树形分类） */
final class CategoryTreeService
{
    /** 树缓存 TTL（秒）：写操作会主动失效，TTL 仅作兜底 */
    private const TREE_CACHE_TTL = 300;

    /** 允许的最大分类层级深度（根=1） */
    public const MAX_DEPTH = 5;

    private PDO $db;
    private int $dataUid;

    public function __construct(PDO $db, int $dataUid)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
    }

    private function cacheKey(): string
    {
        return 'cat_tree_' . $this->dataUid;
    }

    /** 写操作后调用：主动失效树缓存 */
    public function invalidateCache(): void
    {
        if (\function_exists('apcu_delete')) {
            try { apcu_delete($this->cacheKey()); } catch (\Throwable $e) {}
        }
    }

    /** 全量分类行（含物料计数），供树构建 */
    public function getFlatTree(): array
    {
        $rows = $this->loadAllRows();
        $nodes = [];
        foreach ($rows as $r) {
            $nodes[(int)$r['id']] = [
                'id'          => (int)$r['id'],
                'name'        => (string)$r['name'],
                'parent_id'   => (int)$r['parent_id'],
                'is_top'      => ($r['parent_id'] === null) ? 1 : 0,
                'cnt'         => (int)$r['cnt'],
                'depth'       => 0,
                'path'        => '',
                'descendants' => [],
            ];
        }

        // children 索引
        $children = [];
        foreach ($nodes as $id => $n) {
            $pid = $n['parent_id'];
            if ($pid > 0 && isset($nodes[$pid]) && $pid !== $id) {
                $children[$pid][] = $id;
            } else {
                $children[0][] = $id; // 根（parent_id NULL/0 或父级缺失的孤儿）
            }
        }

        // BFS 计算深度、路径、后代集合
        $queue = [];
        foreach ($children[0] ?? [] as $rootId) {
            $queue[] = [$rootId, 1, ''];
        }
        while ($queue) {
            [$id, $depth, $parentPath] = array_shift($queue);
            $node = &$nodes[$id];
            $node['depth'] = $depth;
            $node['path']  = $parentPath === '' ? $node['name'] : $parentPath . ' / ' . $node['name'];
            $path = $node['path'];

            // 回填祖先的后代集合
            $pid = $node['parent_id'];
            if ($pid > 0 && isset($nodes[$pid])) {
                $anc = $pid;
                $guard = 0;
                while ($anc > 0 && isset($nodes[$anc]) && $guard++ < self::MAX_DEPTH + 2) {
                    $nodes[$anc]['descendants'][] = $id;
                    $anc = $nodes[$anc]['parent_id'];
                }
            }
            unset($node);

            foreach ($children[$id] ?? [] as $cid) {
                $queue[] = [$cid, $depth + 1, $path];
            }
        }

        return $nodes;
    }

    /** 嵌套树结构（供前端树形组件渲染） */
    public function getTree(): array
    {
        $nodes = $this->getFlatTree();

        $children = [];
        foreach ($nodes as $id => $n) {
            $pid = $n['parent_id'];
            $children[($pid > 0 && isset($nodes[$pid]) && $pid !== $id) ? $pid : 0][] = $id;
        }

        $build = function(int $pid, int $depth) use (&$build, &$nodes, &$children): array {
            $out = [];
            foreach ($children[$pid] ?? [] as $cid) {
                $n = $nodes[$cid];
                $out[] = [
                    'id'       => $n['id'],
                    'name'     => $n['name'],
                    'cnt'      => $n['cnt'],
                    'depth'    => $n['depth'],
                    'path'     => $n['path'],
                    'children' => $build($cid, $depth + 1),
                ];
            }
            usort($out, fn($a, $b) => $b['cnt'] <=> $a['cnt'] ?: strcmp($a['name'], $b['name']));
            return $out;
        };

        return $build(0, 1);
    }

    /** 指定分类的全部后代 ID（不含自身） */
    public function getDescendantIds(int $catId): array
    {
        $nodes = $this->getFlatTree();
        return $nodes[$catId]['descendants'] ?? [];
    }

    /** 防环校验：$nodeId 是否为 $ancestorId 的后代（含深度保护） */
    public function isDescendantOf(int $nodeId, int $ancestorId): bool
    {
        if ($nodeId <= 0 || $ancestorId <= 0 || $nodeId === $ancestorId) {
            return false;
        }
        $nodes = $this->getFlatTree();
        $cur = $nodeId;
        $guard = 0;
        while ($cur > 0 && isset($nodes[$cur]) && $guard++ < self::MAX_DEPTH + 2) {
            $cur = $nodes[$cur]['parent_id'];
            if ($cur === $ancestorId) return true;
        }
        return false;
    }

    /** 计算节点移动到新父级后的深度（用于深度限制校验） */
    public function calcDepthAfterMove(int $nodeId, int $newParentId): int
    {
        $nodes = $this->getFlatTree();
        if (!isset($nodes[$nodeId])) return -1;

        if ($newParentId === 0) return 1; // 移到根
        if (!isset($nodes[$newParentId])) return -1;
        if ($nodeId === $newParentId) return -1;
        if ($this->isDescendantOf($newParentId, $nodeId)) return -1; // 新父级是自身后代 → 环

        // 新深度 = 新父级深度 + 1
        $parentDepth = $nodes[$newParentId]['depth'];
        if ($parentDepth < 1) {
            // 父级深度未算出（异常环），沿 parent 链手动计算
            $cur = $newParentId;
            $d = 1;
            $guard = 0;
            while ($nodes[$cur]['parent_id'] > 0 && isset($nodes[$nodes[$cur]['parent_id']]) && $guard++ < self::MAX_DEPTH + 2) {
                $cur = $nodes[$cur]['parent_id'];
                $d++;
            }
            $parentDepth = $d;
        }

        // 子树最大相对深度
        $subDepth = 1;
        foreach ($nodes[$nodeId]['descendants'] as $did) {
            $subDepth = max($subDepth, $nodes[$did]['depth'] - $nodes[$nodeId]['depth'] + 1);
        }

        return $parentDepth + $subDepth;
    }

    /** 全量分类行（APCu 缓存，TTL 兜底 + 写操作主动失效） */
    private function loadAllRows(): array
    {
        if (\function_exists('apcu_fetch')) {
            try {
                $hit = false;
                $cached = apcu_fetch($this->cacheKey(), $hit);
                if ($hit && \is_array($cached)) return $cached;
            } catch (\Throwable $e) {}
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT c.id, c.name, c.parent_id,
                        (SELECT COUNT(*) FROM part_categories pc WHERE pc.category_id = c.id) AS cnt
                 FROM categories c
                 WHERE c.user_id = ?
                 ORDER BY c.name ASC"
            );
            $stmt->execute([$this->dataUid]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('CategoryTreeService loadAllRows: ' . $e->getMessage());
            return [];
        }

        if (\function_exists('apcu_store')) {
            try { apcu_store($this->cacheKey(), $rows, self::TREE_CACHE_TTL); } catch (\Throwable $e) {}
        }

        return $rows;
    }
}
