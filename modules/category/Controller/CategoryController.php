<?php
declare(strict_types=1);

namespace Modules\Category\Controller;

use PDO;

require_once __DIR__ . '/../../inventory/Service/PartManager.php';

/** 分类管理页控制器 */
final class CategoryController
{
    /** 视图数据的空默认值（取数失败时兜底，保证页面结构完整） */
    public static function emptyViewData(): array
    {
        return [
            'list'      => [
                'items'         => [],
                'total'         => 0,
                'page'          => 1,
                'per_page'      => 25,
                'total_page'    => 1,
                'top_cats'      => [],
                'all_cats'      => [],
                'total_all'     => 0,
                'unbound_count' => 0,
            ],
            'q'         => '',
            'sort'      => 'cnt_desc',
            'filter'    => 'all',
            'canManage' => false,
            'canBatch'  => false,
        ];
    }

    /** 组装分类管理页视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid, array $query = []): array
    {
        $list = (new \PartManager($db, $uid, $dataUid))->listCategoriesPaged($query);

        return [
            'list'      => $list,
            'q'         => trim((string)($query['q'] ?? '')),
            'sort'      => self::resolveSort($query),
            'filter'    => self::resolveFilter($query),

            'canManage' => \hasPermission('can_manage_categories'),
            'canBatch'  => \hasPermission('can_edit') && \hasPermission('can_batch'),
        ];
    }

    /** 归一化排序参数（与 PartManager::listCategoriesPaged 的白名单保持一致） */
    private static function resolveSort(array $query): string
    {
        $sort = (string)($query['sort'] ?? 'cnt_desc');
        return in_array($sort, ['cnt_desc', 'cnt_asc', 'name_asc', 'name_desc'], true) ? $sort : 'cnt_desc';
    }

    /** 归一化顶部统计卡筛选参数（与 PartManager::listCategoriesPaged 的白名单一致） */
    private static function resolveFilter(array $query): string
    {
        $filter = (string)($query['filter'] ?? 'all');
        return in_array($filter, ['all', 'top', 'unbound'], true) ? $filter : 'all';
    }
}
