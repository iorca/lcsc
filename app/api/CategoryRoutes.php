<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Category\Service\CategoryTreeService;

require_once __DIR__ . '/../../modules/inventory/Service/PartManager.php';

/** 分类管理 API 路由 */
final class CategoryRoutes
{
    public static function handle(string $method, array $user): void
    {
        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'tree':
                self::handleTree($db, $dataUid);
                break;

            case 'list_paged':
                // 取数：分类主表格分页查询
                Response::ok(self::queryListPaged($db, $uid, $dataUid, $_GET));
                break;

            case 'create':
                self::requirePerm('can_manage_categories');
                self::handleCreate($db, $uid, $dataUid);
                break;

            case 'move':
                self::requirePerm('can_manage_categories');
                self::handleMove($db, $uid, $dataUid);
                break;

            case 'rename':
                self::requirePerm('can_manage_categories');
                self::handleRename($db, $uid, $dataUid);
                break;

            case 'delete':
                self::requirePerm('can_manage_categories');
                self::handleDelete($db, $uid, $dataUid);
                break;

            case 'merge':
                self::requirePerm('can_manage_categories');
                self::handleMerge($db, $uid, $dataUid);
                break;

            case 'bind_parent':
                self::requirePerm('can_manage_categories');
                self::handleBindParent($db, $uid, $dataUid);
                break;

            case 'topcat_add':
                self::requirePerm('can_manage_categories');
                self::handleTopcatAdd($db, $uid, $dataUid);
                break;

            case 'topcat_rename':
                self::requirePerm('can_manage_categories');
                self::handleTopcatRename($db, $uid, $dataUid);
                break;

            case 'topcat_delete':
                self::requirePerm('can_manage_categories');
                self::handleTopcatDelete($db, $uid, $dataUid);
                break;

            case 'batch_threshold':
                self::requireBatchPerm();
                self::handleBatchThreshold($db, $uid, $dataUid);
                break;

            case 'batch_set_location':
                self::requireBatchPerm();
                self::handleBatchSetLocation($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 category 方法：' . $method, 404);
        }
    }

    /** 取数：分类主表格分页查询 */
    public static function queryListPaged(\PDO $db, int $uid, int $dataUid, array $params): array
    {
        return (new \PartManager($db, $uid, $dataUid))->listCategoriesPaged($params);
    }

    /** 取数：多级分类树 */
    public static function queryTree(\PDO $db, int $dataUid): array
    {
        return [
            'tree'      => (new CategoryTreeService($db, $dataUid))->getTree(),
            'max_depth' => CategoryTreeService::MAX_DEPTH,
        ];
    }

    /** GET category.tree — 多级分类树（物料列表筛选弹窗 / 分类管理页共用） */
    private static function handleTree(\PDO $db, int $dataUid): void
    {
        Response::ok(self::queryTree($db, $dataUid));
    }

    /** POST category.create — 新增子分类（parent_id=0 表示新增一级大类） */
    private static function handleCreate(\PDO $db, int $uid, int $dataUid): void
    {
        $name     = trim((string)($_POST['name'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if ($name === '') Response::error('分类名称不能为空');

        $svc = new CategoryTreeService($db, $dataUid);

        // 父级校验 + 深度校验
        if ($parentId > 0) {
            $flat = $svc->getFlatTree();
            if (!isset($flat[$parentId])) Response::error('父分类不存在');
            if ($flat[$parentId]['depth'] >= CategoryTreeService::MAX_DEPTH) {
                Response::error('分类层级最深 ' . CategoryTreeService::MAX_DEPTH . ' 级，无法继续添加子分类');
            }
        }

        $check = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
        $check->execute([$dataUid, $name]);
        if ($check->fetch()) Response::error('分类名称「' . $name . '」已存在，请使用其他名称', 2);

        $db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, ?, ?)")
           ->execute([$dataUid, $parentId > 0 ? $parentId : null, $name]);
        $newId = (int)$db->lastInsertId();
        $svc->invalidateCache();
        \traceLog($uid, 'cat_create', 'category', $newId, "新增分类 name:{$name} parent:{$parentId}");
        Response::ok(['id' => $newId, 'name' => $name, 'parent_id' => $parentId], '分类已添加');
    }

    /** POST category.move — 移动分类到新父级（防环 + 深度限制） */
    private static function handleMove(\PDO $db, int $uid, int $dataUid): void
    {
        $id        = (int)($_POST['id'] ?? 0);
        $parentId  = (int)($_POST['parent_id'] ?? 0);
        if ($id <= 0) Response::error('参数无效');

        $svc = new CategoryTreeService($db, $dataUid);

        // 新深度校验（内部含环检测：新父级为自身后代时返回 -1）
        $newDepth = $svc->calcDepthAfterMove($id, $parentId);
        if ($newDepth < 0) Response::error('移动失败：目标父级不能是自身或自身的下级分类');
        if ($newDepth > CategoryTreeService::MAX_DEPTH) {
            Response::error('移动后层级深度将达到 ' . $newDepth . ' 级，超过最大限制 ' . CategoryTreeService::MAX_DEPTH . ' 级');
        }

        $db->prepare("UPDATE categories SET parent_id=? WHERE id=? AND user_id=?")
           ->execute([$parentId > 0 ? $parentId : null, $id, $dataUid]);
        $svc->invalidateCache();
        \traceLog($uid, 'cat_move', 'category', $id, "移动分类 id:{$id} → parent:{$parentId}");
        Response::ok(['id' => $id, 'parent_id' => $parentId], '分类已移动');
    }

    /** 权限校验：分类管理 */
    private static function requirePerm(string $perm): void
    {
        if (!\hasPermission($perm)) {
            Response::error('权限不足', 403);
        }
    }

    /** 权限校验：批量操作（can_edit + can_batch） */
    private static function requireBatchPerm(): void
    {
        if (!\hasPermission('can_edit') || !\hasPermission('can_batch')) {
            Response::error('权限不足', 403);
        }
    }

    /** POST category.rename — 重命名分类 */
    private static function handleRename(\PDO $db, int $uid, int $dataUid): void
    {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') Response::error('分类名称不能为空');
        $db->prepare("UPDATE categories SET name=? WHERE id=? AND user_id=?")->execute([$name, $id, $dataUid]);
        (new CategoryTreeService($db, $dataUid))->invalidateCache();
        \traceLog($uid, 'cat_rename', 'category', $id, "重命名分类 id:{$id} name:{$name}");
        Response::ok(['id' => $id, 'name' => $name], '重命名成功');
    }

    /** POST category.delete — 删除分类（前置校验：有子级或关联物料时拒绝） */
    private static function handleDelete(\PDO $db, int $uid, int $dataUid): void
    {
        $id = (int)($_POST['id'] ?? 0);

        // 校验分类归属
        $chk = $db->prepare("SELECT id, name FROM categories WHERE id=? AND user_id=?");
        $chk->execute([$id, $dataUid]);
        $row = $chk->fetch(\PDO::FETCH_ASSOC);
        if (!$row) Response::error('分类不存在');

        // 有子分类 → 拒绝（多级树形：删除中间节点会导致子级悬空）
        $child = $db->prepare("SELECT COUNT(*) FROM categories WHERE parent_id=? AND user_id=?");
        $child->execute([$id, $dataUid]);
        if ((int)$child->fetchColumn() > 0) {
            Response::error('该分类下仍有子分类，请先删除或移出全部子分类');
        }

        $pc = $db->prepare("SELECT COUNT(*) FROM part_categories WHERE category_id=?");
        $pc->execute([$id]);
        if ((int)$pc->fetchColumn() > 0) {
            Response::error('该分类仍关联物料，请先移出物料或使用「合并分类」');
        }

        $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id, $dataUid]);
        (new CategoryTreeService($db, $dataUid))->invalidateCache();
        \traceLog($uid, 'cat_delete', 'category', $id, "删除分类 id:{$id} name:{$row['name']}");
        Response::ok(['id' => $id], '分类已删除');
    }

    /** POST category.merge — 合并分类（source_ids[] → target_id，目标不得为源的后代） */
    private static function handleMerge(\PDO $db, int $uid, int $dataUid): void
    {
        $targetId  = (int)($_POST['target_id'] ?? 0);
        $sourceIds = array_map('intval', $_POST['source_ids'] ?? []);
        $target    = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
        $target->execute([$targetId, $dataUid]);
        if (!$target->fetch()) Response::error('目标分类无效');

        // 防环：目标分类不得是任何源分类的后代（合并后源被删，子树挂到目标下会成环）
        $svc = new CategoryTreeService($db, $dataUid);
        foreach ($sourceIds as $srcId) {
            if ($srcId > 0 && $svc->isDescendantOf($targetId, $srcId)) {
                Response::error('合并失败：目标分类不能是来源分类的下级分类');
            }
        }

        $mergedCnt = 0;
        foreach ($sourceIds as $srcId) {
            if ($srcId === $targetId) continue;
            $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
            $chk->execute([$srcId, $dataUid]);
            if (!$chk->fetch()) continue;
            // 源分类的后代节点迁移：整棵子树挂到目标分类下
            $db->prepare("UPDATE categories SET parent_id=? WHERE parent_id=? AND user_id=?")
               ->execute([$targetId, $srcId, $dataUid]);
            $parts = $db->prepare("SELECT part_id FROM part_categories WHERE category_id=?");
            $parts->execute([$srcId]);
            foreach ($parts->fetchAll() as $p) {
                $db->prepare("INSERT IGNORE INTO part_categories (part_id,category_id) VALUES (?,?)")->execute([$p['part_id'], $targetId]);
            }
            $db->prepare("DELETE FROM part_categories WHERE category_id=?")->execute([$srcId]);
            $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$srcId, $dataUid]);
            $mergedCnt++;
        }
        $svc->invalidateCache();
        \traceLog($uid, 'cat_merge', 'category', $targetId, "合并分类 target:{$targetId} sources:" . implode(',', $sourceIds));
        Response::ok(['target_id' => $targetId, 'merged' => $mergedCnt], '合并完成');
    }

    /** POST category.bind_parent — 批量绑定/解绑父分类（多级 + 防环 + 深度校验） */
    private static function handleBindParent(\PDO $db, int $uid, int $dataUid): void
    {
        $catIds   = array_map('intval', $_POST['cat_ids'] ?? []);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if (empty($catIds)) Response::error('未选择分类');

        $svc = new CategoryTreeService($db, $dataUid);

        // parentId=0 表示解除绑定；>0 时允许任意层级分类作为父级（多级树形）
        if ($parentId > 0) {
            $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
            $chk->execute([$parentId, $dataUid]);
            if (!$chk->fetch()) Response::error('目标分类不存在');

            // 防环 + 深度校验（逐个分类检查，任一非法则整体拒绝）
            foreach ($catIds as $cid) {
                if ($cid === $parentId) continue;
                $newDepth = $svc->calcDepthAfterMove($cid, $parentId);
                if ($newDepth < 0) {
                    Response::error('绑定失败：目标分类不能是被绑定分类的下级分类');
                }
                if ($newDepth > CategoryTreeService::MAX_DEPTH) {
                    Response::error('绑定后层级深度将超过最大限制 ' . CategoryTreeService::MAX_DEPTH . ' 级');
                }
            }
        }

        $in = implode(',', array_fill(0, count($catIds), '?'));
        $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
        $valid->execute([...$catIds, $dataUid]);
        $validIds = array_column($valid->fetchAll(), 'id');
        if ($validIds) {
            // 排除目标自身（不能绑定到自己）
            $validIds = array_values(array_diff($validIds, [$parentId === 0 ? -1 : $parentId]));
        }
        if ($validIds) {
            $inV = implode(',', array_fill(0, count($validIds), '?'));
            $bindVal = $parentId > 0 ? $parentId : 0;
            $db->prepare("UPDATE categories SET parent_id=? WHERE id IN ($inV) AND user_id=?")
               ->execute([$bindVal, ...$validIds, $dataUid]);
            \traceLog($uid, 'cat_bind_parent', 'category', $parentId, "批量绑定父分类 parent:{$parentId} cats:" . implode(',', $validIds));
        }
        $svc->invalidateCache();
        $bindMsg = $parentId > 0 ? '绑定完成' : '解绑成功';
        Response::ok(['parent_id' => $parentId, 'updated' => count($validIds)], $bindMsg);
    }

    /** POST category.topcat_add — 新增一级大类 */
    private static function handleTopcatAdd(\PDO $db, int $uid, int $dataUid): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') Response::error('大类名称不能为空');

        $check = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
        $check->execute([$dataUid, $name]);
        if ($check->fetch()) Response::error('分类名称「' . $name . '」已存在，请使用其他名称', 2);
        $db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, NULL, ?)")->execute([$dataUid, $name]);
        $newId = (int)$db->lastInsertId();
        \traceLog($uid, 'topcat_add', 'category', $newId, "新增一级大类 name:{$name}");
        Response::ok(['id' => $newId, 'name' => $name], '大类已添加');
    }

    /** POST category.topcat_rename — 重命名一级大类 */
    private static function handleTopcatRename(\PDO $db, int $uid, int $dataUid): void
    {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') Response::error('大类名称不能为空');
        $db->prepare("UPDATE categories SET name=? WHERE id=? AND user_id=? AND parent_id IS NULL")->execute([$name, $id, $dataUid]);
        \traceLog($uid, 'topcat_rename', 'category', $id, "重命名一级大类 id:{$id} name:{$name}");
        Response::ok(['id' => $id, 'name' => $name], '重命名成功');
    }

    /** POST category.topcat_delete — 删除一级大类（子分类解除绑定） */
    private static function handleTopcatDelete(\PDO $db, int $uid, int $dataUid): void
    {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=? AND parent_id IS NULL");
        $chk->execute([$id, $dataUid]);
        if (!$chk->fetch()) Response::error('一级大类不存在');
        // 子分类解除绑定（parent_id 置 0，挂回根层）
        $db->prepare("UPDATE categories SET parent_id=0 WHERE parent_id=? AND user_id=?")->execute([$id, $dataUid]);

        $db->prepare("DELETE FROM part_categories WHERE category_id=?")->execute([$id]);
        // 删除一级大类本身
        $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id, $dataUid]);
        (new CategoryTreeService($db, $dataUid))->invalidateCache();
        \traceLog($uid, 'topcat_delete', 'category', $id, "删除一级大类 id:{$id}");
        Response::ok(['id' => $id], '大类已删除');
    }

    /** POST category.batch_threshold — 批量设置低库存阈值 */
    private static function handleBatchThreshold(\PDO $db, int $uid, int $dataUid): void
    {
        $catIds    = array_map('intval', $_POST['cat_ids'] ?? []);
        $threshold = max(0, (int)($_POST['threshold'] ?? 10));
        $updated   = 0;
        if ($catIds) {
            $in    = implode(',', array_fill(0, count($catIds), '?'));
            $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$catIds, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if ($validIds) {
                $inV = implode(',', array_fill(0, count($validIds), '?'));
                $db->prepare("UPDATE categories SET low_stock_threshold=? WHERE id IN ($inV) AND user_id=?")
                   ->execute([$threshold, ...$validIds, $dataUid]);
                $updated = count($validIds);
                \traceLog($uid, 'batch_set_threshold', 'category', 0, "批量设置分类阈值 count:{$updated} threshold:{$threshold}");
            }
        }
        Response::ok(['updated' => $updated, 'threshold' => $threshold], '阈值已同步');
    }

    /** POST category.batch_set_location — 按分类批量设置库位 */
    private static function handleBatchSetLocation(\PDO $db, int $uid, int $dataUid): void
    {
        $catIds   = array_map('intval', $_POST['cat_ids'] ?? []);
        $location = trim((string)($_POST['location'] ?? ''));
        if (empty($catIds) || $location === '') Response::error('参数无效');
        $in = implode(',', array_fill(0, count($catIds), '?'));
        $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
        $valid->execute([...$catIds, $dataUid]);
        $validIds = array_column($valid->fetchAll(), 'id');
        if (empty($validIds)) Response::error('分类无效');
        $inV = implode(',', array_fill(0, count($validIds), '?'));
        $db->prepare("UPDATE parts SET location=? WHERE user_id=? AND id IN (SELECT part_id FROM part_categories WHERE category_id IN ($inV))")
           ->execute([$location, $dataUid, ...$validIds]);
        \traceLog($uid, 'batch_set_category_location', 'category', 0, "按分类批量设置库位 cats:" . implode(',', $validIds) . " location:{$location}");
        Response::ok(['updated' => count($validIds), 'location' => $location], '库位已设置');
    }
}
