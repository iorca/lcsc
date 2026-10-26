<?php
declare(strict_types=1);

namespace Modules\Location\Service;

use PDO;

/** 库位管理服务（P6 三级结构：仓库 warehouse → 库区 zone → 库位 bin） */
final class LocationService
{
    /** 库位树最大层级（仓库=1，库区=2，库位=3） */
    public const MAX_DEPTH = 3;

    /** 存量回填单批次上限 */
    public const BACKFILL_BATCH = 500;

    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
        $this->wsId    = (int)\getWorkspaceId();
    }

    /** 全局开关：库位管理是否启用（默认关闭 → 纯文本库位备注模式） */
    public static function isEnabled(): bool
    {
        return \getSetting('enable_location_manage', '0') === '1';
    }

    /** 全量库位（含绑定物料数），扁平结构 */
    public function getFlatTree(): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.id, l.name, l.type, l.description, l.parent_id,
                    (SELECT COUNT(*) FROM parts p
                      WHERE p.location_id = l.id AND p.status != 'deleted'
                        AND p.user_id = ? AND p.workspace_id = ?) AS part_cnt
             FROM locations l
             WHERE l.workspace_id = ?
             ORDER BY l.name ASC"
        );
        $stmt->execute([$this->dataUid, $this->wsId, $this->wsId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nodes = [];
        foreach ($rows as $r) {
            $nodes[(int)$r['id']] = [
                'id'          => (int)$r['id'],
                'name'        => (string)$r['name'],
                'type'        => (string)$r['type'],
                'description' => (string)$r['description'],
                'parent_id'   => $r['parent_id'] === null ? 0 : (int)$r['parent_id'],
                'part_cnt'    => (int)$r['part_cnt'],
                'depth'       => 0,
                'path'        => '',
                'descendants' => [],
            ];
        }

        $children = [];
        foreach ($nodes as $id => $n) {
            $pid = $n['parent_id'];
            if ($pid > 0 && isset($nodes[$pid]) && $pid !== $id) $children[$pid][] = $id;
            else $children[0][] = $id;
        }

        $queue = [];
        foreach ($children[0] ?? [] as $rootId) $queue[] = [$rootId, 1, ''];
        while ($queue) {
            [$id, $depth, $parentPath] = array_shift($queue);
            $node = &$nodes[$id];
            $node['depth'] = $depth;
            $node['path']  = $parentPath === '' ? $node['name'] : $parentPath . ' / ' . $node['name'];
            $path = $node['path'];

            $pid = $node['parent_id'];
            if ($pid > 0 && isset($nodes[$pid])) {
                $anc = $pid; $guard = 0;
                while ($anc > 0 && isset($nodes[$anc]) && $guard++ < self::MAX_DEPTH + 2) {
                    $nodes[$anc]['descendants'][] = $id;
                    $anc = $nodes[$anc]['parent_id'];
                }
            }
            unset($node);

            foreach ($children[$id] ?? [] as $cid) $queue[] = [$cid, $depth + 1, $path];
        }
        return $nodes;
    }

    /** 嵌套树（供前端渲染） */
    public function getTree(): array
    {
        $nodes = $this->getFlatTree();
        $children = [];
        foreach ($nodes as $id => $n) {
            $pid = $n['parent_id'];
            $children[($pid > 0 && isset($nodes[$pid]) && $pid !== $id) ? $pid : 0][] = $id;
        }
        $build = function(int $pid) use (&$build, &$nodes, &$children): array {
            $out = [];
            foreach ($children[$pid] ?? [] as $cid) {
                $n = $nodes[$cid];
                $out[] = [
                    'id'       => $n['id'],
                    'name'     => $n['name'],
                    'type'     => $n['type'],
                    'depth'    => $n['depth'],
                    'path'     => $n['path'],
                    'part_cnt' => $n['part_cnt'],
                    'children' => $build($cid),
                ];
            }
            usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
            return $out;
        };
        return $build(0);
    }

    /** 待回填的存量文本库位物料数 */
    public function countPendingBackfill(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM parts
                 WHERE user_id=? AND location_id IS NULL
                   AND location IS NOT NULL AND location<>'' AND status!='deleted'"
            );
            $stmt->execute([$this->dataUid]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('LocationService::countPendingBackfill: ' . $e->getMessage());
            return 0;
        }
    }

    /** 新增库位（parentId=0 为顶层仓库） */
    public function create(string $name, string $type, int $parentId, string $description = ''): array
    {
        $name = trim($name);
        if ($name === '') return ['ok' => false, 'msg' => '名称不能为空'];
        $type = self::normalizeType($type);

        if ($parentId > 0) {
            $flat = $this->getFlatTree();
            if (!isset($flat[$parentId])) return ['ok' => false, 'msg' => '父级库位不存在'];
            if ($flat[$parentId]['depth'] >= self::MAX_DEPTH) {
                return ['ok' => false, 'msg' => '库位层级最深 ' . self::MAX_DEPTH . ' 级（仓库→库区→库位）'];
            }
        }

        // 同父级下名称唯一
        $chk = $this->db->prepare("SELECT id FROM locations WHERE workspace_id=? AND name=? AND (parent_id <=> ?)");
        $chk->execute([$this->wsId, $name, $parentId > 0 ? $parentId : null]);
        if ($chk->fetch()) return ['ok' => false, 'msg' => '同级下已存在同名库位「' . $name . '」'];

        $description = mb_substr(trim($description), 0, 500);
        $this->db->prepare("INSERT INTO locations (workspace_id, name, type, parent_id, description) VALUES (?,?,?,?,?)")
                 ->execute([$this->wsId, $name, $type, $parentId > 0 ? $parentId : null, $description]);
        $newId = (int)$this->db->lastInsertId();
        $this->invalidatePathCache();   // 新增节点可能使既有路径的解析结果变化（如补齐了缺失层级）
        \traceLog($this->uid, 'loc_create', 'location', $newId, "新增库位 name:{$name} type:{$type} parent:{$parentId}");
        return ['ok' => true, 'id' => $newId];
    }

    /** 类型归一化：内置类型原样保留，**其余按自定义类型存原文** */
    private static function normalizeType(string $type): string
    {
        $type = trim($type);
        if (in_array($type, ['warehouse', 'zone', 'bin', 'shelf', 'bench', 'lab'], true)) return $type;
        $type = preg_replace('/[\x00-\x1F<>"]/u', '', $type) ?? '';
        $type = mb_substr($type, 0, 30);
        return $type === '' ? 'shelf' : $type;
    }

    /** 编辑库位（事务同步绑定物料的 location 文本） */
    public function rename(int $id, string $name, ?string $type = null, ?string $description = null): array
    {
        $name = trim($name);
        if ($name === '') return ['ok' => false, 'msg' => '名称不能为空'];
        $node = $this->getNode($id);
        if (!$node) return ['ok' => false, 'msg' => '库位不存在'];

        $newPath = $this->buildPathAfterRename($node, $name);

        $sql = "UPDATE locations SET name=?";
        $args = [$name];
        if ($type !== null) {
            $sql .= ", type=?";
            $args[] = self::normalizeType($type);
        }
        if ($description !== null) {
            $sql .= ", description=?";
            $args[] = mb_substr(trim($description), 0, 500);
        }
        $sql .= " WHERE id=? AND workspace_id=?";

        $this->db->beginTransaction();
        try {
            $args[] = $id;
            $args[] = $this->wsId;
            $this->db->prepare($sql)->execute($args);
            // 同步绑定物料的库位文本：自身及所有后代的路径均因祖先改名而变化，

            $this->syncPartLocationText(array_merge([$id], $node['descendants']));
            $this->db->commit();
            $this->invalidatePathCache();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('LocationService rename: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '保存失败，已回滚'];
        }
        $detail = "编辑库位 id:{$id} name:{$name}";
        if ($type !== null) $detail .= ' type:' . self::normalizeType($type);
        \traceLog($this->uid, 'loc_rename', 'location', $id, $detail);
        return ['ok' => true, 'path' => $newPath];
    }

    /** 移动库位（防环 + 深度限制） */
    public function move(int $id, int $newParentId): array
    {
        $flat = $this->getFlatTree();
        if (!isset($flat[$id])) return ['ok' => false, 'msg' => '库位不存在'];
        if ($newParentId === $id) return ['ok' => false, 'msg' => '不能移动到自身'];

        if ($newParentId > 0) {
            if (!isset($flat[$newParentId])) return ['ok' => false, 'msg' => '目标父级不存在'];
            // 防环：目标不得是自身后代
            $cur = $newParentId; $guard = 0;
            while ($cur > 0 && isset($flat[$cur]) && $guard++ < self::MAX_DEPTH + 2) {
                if ($cur === $id) return ['ok' => false, 'msg' => '目标父级不能是自身的下级库位'];
                $cur = $flat[$cur]['parent_id'];
            }
            // 深度校验（子树整体深度）
            $subDepth = 1;
            foreach ($flat[$id]['descendants'] as $did) {
                $subDepth = max($subDepth, $flat[$did]['depth'] - $flat[$id]['depth'] + 1);
            }
            if ($flat[$newParentId]['depth'] + $subDepth > self::MAX_DEPTH) {
                return ['ok' => false, 'msg' => '移动后层级将超过 ' . self::MAX_DEPTH . ' 级'];
            }
        }

        $this->db->prepare("UPDATE locations SET parent_id=? WHERE id=? AND workspace_id=?")
                 ->execute([$newParentId > 0 ? $newParentId : null, $id, $this->wsId]);
        $this->syncPartLocationText(array_merge([$id], $flat[$id]['descendants']));
        $this->invalidatePathCache();   // 移动改变了祖先链，路径文本 → id 的映射已变
        \traceLog($this->uid, 'loc_move', 'location', $id, "移动库位 id:{$id} → parent:{$newParentId}");
        return ['ok' => true];
    }

    /** 删除库位（有子级或绑定物料时拒绝） */
    public function delete(int $id): array
    {
        $node = $this->getNode($id);
        if (!$node) return ['ok' => false, 'msg' => '库位不存在'];
        if (!empty($node['descendants'])) return ['ok' => false, 'msg' => '该库位下仍有子级库位，请先删除或移出'];
        if ($node['part_cnt'] > 0) return ['ok' => false, 'msg' => '该库位已绑定 ' . $node['part_cnt'] . ' 个物料，请先解绑'];

        $this->db->prepare("DELETE FROM locations WHERE id=? AND workspace_id=?")
                 ->execute([$id, $this->wsId]);
        $this->invalidatePathCache();   // 节点已删，缓存里指向它的映射必须作废
        \traceLog($this->uid, 'loc_delete', 'location', $id, "删除库位 id:{$id} name:{$node['name']}");
        return ['ok' => true];
    }

    /** 库位详情：节点信息 + 绑定的物料列表（含后代） + 统计（物料数 / 总库存 / 库存总价值） */
    public function getPartsByLocation(int $locationId, bool $includeDescendants = true): array
    {
        $empty = ['node' => null, 'parts' => [], 'stats' => ['part_cnt' => 0, 'total_stock' => 0, 'total_value' => 0.0]];
        $flat  = $this->getFlatTree();
        $node  = $flat[$locationId] ?? null;
        if (!$node) return $empty;

        $ids = [$locationId];
        if ($includeDescendants) {
            foreach ($node['descendants'] as $did) $ids[] = $did;
        }
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$this->dataUid, $this->wsId], $ids);

        $stmt = $this->db->prepare(
            "SELECT id, internal_id, platform_part_no, customer_part_no, model, product_name, brand, package,
                    product_type, stock, damaged, location, low_stock_threshold, avg_cost
             FROM parts
             WHERE user_id=? AND workspace_id=? AND status!='deleted' AND location_id IN ($in)
             ORDER BY update_time DESC"
        );
        $stmt->execute($params);

        $parts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $parts[] = [
                'id'               => (int)$r['id'],
                'internal_id'      => (int)($r['internal_id'] ?? 0),
                'platform_part_no' => (string)$r['platform_part_no'],
                'customer_part_no' => (string)$r['customer_part_no'],
                'model'            => (string)$r['model'],
                'product_name'     => (string)$r['product_name'],
                'brand'            => (string)$r['brand'],
                'package'          => (string)$r['package'],
                'product_type'     => (string)$r['product_type'],
                'stock'            => (int)$r['stock'],
                'damaged'          => (int)($r['damaged'] ?? 0),
                'location'         => (string)$r['location'],
                'eff_threshold'    => (int)($r['low_stock_threshold'] ?? 0),
                'avg_cost'         => (float)($r['avg_cost'] ?? 0),
            ];
        }

        $canCash = \App\Core\Edition::canViewCost();
        $totalStock = 0;
        $totalValue = 0.0;
        foreach ($parts as $p) {
            $totalStock += $p['stock'];
            if ($canCash) $totalValue += $p['stock'] * $p['avg_cost'];
        }
        return [
            'node'  => ['id' => $node['id'], 'name' => $node['name'], 'type' => $node['type'], 'depth' => $node['depth'], 'path' => $node['path'], 'desc' => (string)($node['description'] ?? '')],
            'parts' => $parts,
            'stats' => ['part_cnt' => count($parts), 'total_stock' => $totalStock, 'total_value' => round($totalValue, 2)],
        ];
    }

    /** 声光寻物：按关键词 / 扫码内容定位物料及其库位 */
    public function findForPickup(string $keyword, int $limit = 8): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return $this->pickupResult('bad_input', '请输入物料编号、型号或扫码', []);
        }

        // ── ① 物料二维码 → 按内部编号精确命中 ──
        $internalId = self::parseQrInternalId($kw);
        if ($internalId > 0) {
            $r = $this->pickupQueryByInternalId($internalId);
            if ($r !== null) {
                return $this->wrapPickup([$r], $kw, true);
            }
            // 二维码里的编号已不存在（物料被删）：明确告知，不静默回退到模糊搜索
            return $this->pickupResult('not_found', "编号 #{$internalId} 对应的物料已不存在", []);
        }

        // ── ② `#123` / 纯数字 → 内部编号 ──
        if (preg_match('/^#?(\d+)$/', $kw, $m)) {
            $r = $this->pickupQueryByInternalId((int)$m[1]);
            if ($r !== null) {
                return $this->wrapPickup([$r], $kw, true);
            }
            // 数字也可能是平台编号/客户料号里的数字片段，交给模糊匹配兜底
        }

        // ── ③ 模糊匹配：复用物料列表的搜索实现 ──
        $pm  = new \PartManager($this->db, $this->uid, $this->dataUid);
        $res = $pm->searchPartsPaged($kw, 1, max(1, min(20, $limit)));
        $rows = $res['list'] ?? [];
        if ($rows === []) {
            return $this->pickupResult('not_found', "没有找到「{$kw}」对应的物料", []);
        }
        $hits = $this->pickupHydrate($rows);
        return $this->wrapPickup($hits, $kw, count($hits) === 1);
    }

    /** 播报结果组装：判定状态 + 生成播报文案 */
    private function wrapPickup(array $hits, string $keyword, bool $isSingle): array
    {
        if ($hits === []) {
            return $this->pickupResult('not_found', "没有找到「{$keyword}」对应的物料", []);
        }
        if (!$isSingle) {
            return $this->pickupResult('ok_multi', '', $hits);
        }
        $h = $hits[0];
        if ($h['location_id'] <= 0 || $h['location_path'] === '') {
            // 找到了料但没绑库位 —— 这是最需要说清的一种：
            // 用户会以为「系统坏了」，实际只是该料还没分配库位。
            return $this->pickupResult(
                'no_location',
                "找到「{$h['model_label']}」，但它还没有分配库位，无法寻物",
                $hits
            );
        }
        return $this->pickupResult(
            'ok_single',
            "请到 {$h['location_path']} 取「{$h['model_label']}」，该库位共 {$h['sibling_cnt']} 种物料",
            $hits
        );
    }

    private function pickupResult(string $status, string $speak, array $hits): array
    {
        return ['status' => $status, 'speak' => $speak, 'hits' => $hits];
    }

    /** 从物料二维码原文里取内部编号；不是二维码格式则返回 0 */
    private static function parseQrInternalId(string $raw): int
    {
        $raw = trim($raw);

        if (!str_contains($raw, '{') || !str_contains($raw, '}')) {
            return 0;
        }

        if (!preg_match('/[{,]\s*id\s*:\s*"?(\d+)"?/', $raw, $m)) {
            return 0;
        }
        $id = (int)$m[1];
        return $id > 0 ? $id : 0;
    }

    /** 按内部编号精确取一个物料（含库位信息） */
    private function pickupQueryByInternalId(int $internalId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, internal_id, platform_part_no, customer_part_no, model, product_name,
                    brand, package, stock, location_id, location
             FROM parts
             WHERE user_id=? AND workspace_id=? AND internal_id=? AND status!='deleted'"
        );
        $stmt->execute([$this->dataUid, $this->wsId, $internalId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $one = $this->pickupHydrate([$r]);
        return $one[0] ?? null;
    }

    /** 给候选补上「库位路径」与「同库位物料数」 */
    private function pickupHydrate(array $rows): array
    {
        if ($rows === []) return [];

        $ids = [];
        foreach ($rows as $r) {
            if (isset($r['id'])) $ids[] = (int)$r['id'];
        }
        $locMap = [];
        if ($ids !== []) {
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, location_id FROM parts
                 WHERE user_id=? AND workspace_id=? AND id IN ($in)"
            );
            $stmt->execute(array_merge([$this->dataUid, $this->wsId], $ids));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $locMap[(int)$lr['id']] = (int)($lr['location_id'] ?? 0);
            }
        }

        // 同库位物料数按库位聚合，只查每个用到的库位一次
        $sibCache = [];
        $out = [];
        foreach ($rows as $r) {
            $pid   = (int)($r['id'] ?? 0);
            $locId = $locMap[$pid] ?? (int)($r['location_id'] ?? 0);
            // 文本库位在「只有文本、没绑结构化库位」时（如回填前的存量数据）仍然有用 ——
            // 用户至少知道东西在这条路径上，故保留原样，仅 location_id 为 0。
            $path  = trim((string)($r['location'] ?? ''));
            if ($locId > 0 && !isset($sibCache[$locId])) {
                $sibCache[$locId] = $this->countPartsAtLocation($locId);
            }
            $out[] = [
                'part_id'          => $pid,
                'internal_id'      => (int)($r['internal_id'] ?? 0),
                'platform_part_no' => (string)($r['platform_part_no'] ?? ''),
                'model'            => (string)($r['model'] ?? ''),
                'product_name'     => (string)($r['product_name'] ?? ''),
                'brand'            => (string)($r['brand'] ?? ''),
                'package'          => (string)($r['package'] ?? ''),
                'stock'            => (int)($r['stock'] ?? 0),
                'location_id'      => $locId,
                'location_path'    => $path,
                'sibling_cnt'      => $locId > 0 ? $sibCache[$locId] : 0,
                'model_label'      => $this->pickupLabel($r),
            ];
        }
        return $out;
    }

    /** 播报用的物料称呼：有型号念型号，否则念名称，再否则念编号 */
    private function pickupLabel(array $r): string
    {
        $model = trim((string)($r['model'] ?? ''));
        if ($model !== '') return $model;
        $name = trim((string)($r['product_name'] ?? ''));
        if ($name !== '') return $name;
        return \partCode($r['internal_id'] ?? 0);
    }

    /** 某库位（含后代）下的物料种类数 */
    private function countPartsAtLocation(int $locationId): int
    {
        $flat = $this->getFlatTree();
        $node = $flat[$locationId] ?? null;
        if (!$node) return 0;
        $ids = array_merge([$locationId], $node['descendants']);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $this->db->prepare(
            "SELECT COUNT(*) FROM parts
             WHERE user_id=? AND workspace_id=? AND status!='deleted' AND location_id IN ($in)"
        );
        $st->execute(array_merge([$this->dataUid, $this->wsId], $ids));
        return (int)$st->fetchColumn();
    }

    /** 存量回填预览：待回填物料总数 + 前 $limit 条样例（库位文本），供二次确认前展示 */
    public function previewBackfill(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts p
             WHERE p.user_id=? AND p.location_id IS NULL AND p.location IS NOT NULL AND p.location<>'' AND p.status!='deleted'"
        );
        $stmt->execute([$this->dataUid]);
        $total = (int)$stmt->fetchColumn();

        $samples = [];
        if ($total > 0) {
            $s = $this->db->prepare(
                "SELECT DISTINCT p.location FROM parts p
                 WHERE p.user_id=? AND p.location_id IS NULL AND p.location IS NOT NULL AND p.location<>'' AND p.status!='deleted'
                 ORDER BY p.location LIMIT " . max(1, (int)$limit)
            );
            $s->execute([$this->dataUid]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $samples[] = ['location' => (string)$r['location']];
            }
        }
        return ['total' => $total, 'samples' => $samples];
    }

    /** 批量导入库位（CSV 行：层级,库位路径,类型；层级/类型可省略，路径按 / 、→ 分隔最多3级） */
    public function importCsv(array $rows): array
    {
        $typeNames = ['warehouse', 'zone', 'shelf'];
        $types     = ['warehouse', 'zone', 'bin', 'shelf', 'bench', 'lab'];
        $typeMap   = [
            '仓库' => 'warehouse', '库区' => 'zone', '库位' => 'bin', '货架' => 'shelf',
            '工作台' => 'bench', '实验室' => 'lab',
            'warehouse' => 'warehouse', 'zone' => 'zone', 'bin' => 'bin',
            'shelf' => 'shelf', 'bench' => 'bench', 'lab' => 'lab',
        ];
        $created = 0;
        $failed  = 0;

        foreach ($rows as $i => $row) {
            $row = array_values((array)$row);
            if ($row === []) { continue; }
            // 表头识别：首行含「库位路径 / 类型 / location」列名则跳过
            if ($i === 0) {
                $head = implode('|', $row);
                if (mb_stripos($head, '库位路径') !== false || mb_stripos($head, 'location') !== false) continue;
            }
            $levelRaw = trim((string)($row[0] ?? ''));
            $path     = trim((string)($row[1] ?? ($row[0] ?? '')));
            $typeRaw  = trim((string)($row[2] ?? ''));
            if ($path === '') { $failed++; continue; }

            // 若首列是路径且第二列为类型（无层级列容错）
            if (mb_stripos($levelRaw, '/') !== false || mb_stripos($levelRaw, '→') !== false) {
                $path    = $levelRaw;
                $typeRaw = trim((string)($row[1] ?? ''));
            }

            $segs = preg_split('/\s*[\/→>]\s*/u', $path);
            $segs = array_values(array_filter($segs, function ($s) { return trim((string)$s) !== ''; }));
            if (!$segs) { $failed++; continue; }
            if (count($segs) > self::MAX_DEPTH) { $failed++; continue; } // 最深3级

            $rowType = isset($typeMap[$typeRaw]) ? $typeMap[$typeRaw] : '';
            $parentId = 0;
            $n = count($segs);
            foreach ($segs as $idx => $seg) {
                if ($idx + 1 > self::MAX_DEPTH) { $failed++; break; }
                $seg = trim((string)$seg);
                if ($seg === '') continue;
                $chk = $this->db->prepare("SELECT id FROM locations WHERE workspace_id=? AND name=? AND (parent_id <=> ?)");
                $chk->execute([$this->wsId, $seg, $parentId > 0 ? $parentId : null]);
                $nid = (int)$chk->fetchColumn();
                if ($nid > 0) { $parentId = $nid; continue; }

                $nodeType = ($idx === $n - 1 && $rowType !== '') ? $rowType : ($typeNames[$idx] ?? 'shelf');
                if (!in_array($nodeType, $types, true)) $nodeType = 'shelf';
                $this->db->prepare("INSERT INTO locations (workspace_id, name, type, parent_id) VALUES (?,?,?,?)")
                         ->execute([$this->wsId, $seg, $nodeType, $parentId > 0 ? $parentId : null]);
                $parentId = (int)$this->db->lastInsertId();
                $created++;
            }
        }
        if ($created > 0) {
            $this->invalidatePathCache();   // 新建了节点，缓存的路径映射需重算
            \traceLog($this->uid, 'loc_import', 'location', 0, "批量导入库位 created:{$created} failed:{$failed}");
        }
        return ['created' => $created, 'failed' => $failed];
    }

    /** 绑定物料到库位：同一事务内更新 parts.location_id + parts.location 文本 */
    public function bindPart(int $partId, int $locationId): array
    {
        $locPath = '';
        if ($locationId > 0) {
            $node = $this->getNode($locationId);
            if (!$node) return ['ok' => false, 'msg' => '库位不存在'];
            $locPath = $node['path'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE parts SET location_id=?, location=? WHERE id=? AND user_id=?")
                     ->execute([$locationId > 0 ? $locationId : null, $locPath, $partId, $this->dataUid]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('LocationService bindPart: ' . $e->getMessage());
            return ['ok' => false, 'msg' => '绑定失败，已回滚'];
        }
        \traceLog($this->uid, 'loc_bind', 'location', $locationId, "物料 {$partId} 绑定库位 {$locationId} 「{$locPath}」");
        return ['ok' => true, 'path' => $locPath];
    }

    /** 存量文本库位回填：仅处理 location_id IS NULL 且 location 文本非空的物料 */
    public function backfillBatch(): array
    {
        // 1. 取一批待回填物料（仅 location_id IS NULL）
        $stmt = $this->db->prepare(
            "SELECT p.id, p.location FROM parts p
             WHERE p.user_id=? AND p.location_id IS NULL AND p.location IS NOT NULL AND p.location<>'' AND p.status!='deleted'
             LIMIT " . self::BACKFILL_BATCH
        );
        $stmt->execute([$this->dataUid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filled = 0;

        $byLoc = [];
        foreach ($rows as $r) {
            $locId = $this->resolvePath((string)$r['location']);
            if ($locId <= 0) continue;
            $byLoc[$locId][] = (int)$r['id'];
        }
        foreach ($byLoc as $locId => $ids) {

            $in = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->db->prepare("UPDATE parts SET location_id=? WHERE id IN ($in) AND location_id IS NULL");
            $upd->execute(array_merge([$locId], $ids));
            $filled += $upd->rowCount();
        }

        // 2. 剩余待回填数
        $cnt = $this->db->prepare(
            "SELECT COUNT(*) FROM parts p
             WHERE p.user_id=? AND p.location_id IS NULL AND p.location IS NOT NULL AND p.location<>'' AND p.status!='deleted'"
        );
        $cnt->execute([$this->dataUid]);
        $remaining = (int)$cnt->fetchColumn();

        if ($filled > 0) {
            \traceLog($this->uid, 'loc_backfill', 'location', 0, "存量库位回填 batch:{$filled} remaining:{$remaining}");
        }
        return ['batch' => count($rows), 'filled' => $filled, 'remaining' => $remaining];
    }

    private function getNode(int $id): ?array
    {
        $flat = $this->getFlatTree();
        return $flat[$id] ?? null;
    }

    /** 按库位路径文本逐级解析并补齐缺失节点，返回末级节点 ID */
    private array $pathCache = [];

    /** 结构变更后清空路径缓存（rename / move / delete 节点时调用） */
    private function invalidatePathCache(): void
    {
        $this->pathCache = [];
    }

    private function resolvePath(string $path): int
    {
        $cacheKey = trim($path);
        if (isset($this->pathCache[$cacheKey])) return $this->pathCache[$cacheKey];

        $segs = preg_split('/\s*\/\s*/u', $cacheKey);
        $segs = array_values(array_filter((array)$segs, function ($s) { return trim((string)$s) !== ''; }));
        if (!$segs) return $this->pathCache[$cacheKey] = 0;
        if (count($segs) > self::MAX_DEPTH) $segs = array_slice($segs, 0, self::MAX_DEPTH);

        $typeByDepth = ['warehouse', 'zone', 'shelf'];
        $parentId = 0;
        foreach ($segs as $i => $seg) {
            $seg = trim((string)$seg);
            if ($seg === '') continue;
            $pid = $parentId > 0 ? $parentId : null;
            $chk = $this->db->prepare("SELECT id FROM locations WHERE workspace_id=? AND name=? AND (parent_id <=> ?)");
            $chk->execute([$this->wsId, $seg, $pid]);
            $nid = (int)$chk->fetchColumn();
            if ($nid <= 0) {
                $this->db->prepare("INSERT INTO locations (workspace_id, name, type, parent_id) VALUES (?,?,?,?)")
                     ->execute([$this->wsId, $seg, $typeByDepth[$i] ?? 'shelf', $pid]);
                $nid = (int)$this->db->lastInsertId();
            }
            $parentId = $nid;
        }
        return $this->pathCache[$cacheKey] = $parentId;
    }

    /** 重命名后构建新路径（父路径不变，仅替换末段） */
    private function buildPathAfterRename(array $node, string $newName): string
    {
        $pos = strrpos($node['path'], ' / ');
        return $pos === false ? $newName : substr($node['path'], 0, $pos) . ' / ' . $newName;
    }

    /** 重算库位树下所有绑定物料的 location 文本（结构变化后调用） */
    private function syncPartLocationText(array $locationIds): void
    {
        if (!$locationIds) return;
        $flat = $this->getFlatTree();
        $upd = $this->db->prepare("UPDATE parts SET location=? WHERE location_id=?");
        foreach ($locationIds as $lid) {
            if (!isset($flat[$lid])) continue;
            $upd->execute([$flat[$lid]['path'], $lid]);
        }
    }
}
