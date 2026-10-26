<?php
declare(strict_types=1);

namespace Modules\Inventory\Controller;

use Modules\Inventory\Service\NoticeService;
use Modules\Inventory\Service\PartService;
use PDO;

/** 物料列表页控制器 */
final class InventoryController
{
    /** 组装物料列表页视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid): array
    {
        $service = new PartService($db, $uid, $dataUid);

        // ── 参数解析 ──
        $q       = trim((string)($_GET['q'] ?? ''));
        $page    = max(1, intval($_GET['page'] ?? 1));
        $perPage = intval($_GET['per_page'] ?? $_COOKIE['per_page_index'] ?? 25);
        $perPage = max(10, min(50, $perPage));
        $filter  = (string)($_GET['filter'] ?? '');

        if (!in_array($filter, ['low', 'zero', 'damaged', 'negative'], true)) $filter = '';
        $catParam = trim((string)($_GET['cat'] ?? ''));
        $noCat   = ($catParam === '-1');
        $catIds  = [];
        if ($catParam !== '' && $catParam !== '0' && !$noCat) {
            $catIds = array_filter(array_map('intval', explode(',', $catParam)), function($v){ return $v > 0; });
        }
        $catId   = count($catIds) === 1 ? $catIds[0] : (count($catIds) > 1 ? 0 : intval($catParam));
        $platId  = intval($_GET['plat'] ?? 0);
        $locFilter = trim((string)($_GET['loc'] ?? ''));

        $searchKeywordCount = 0;
        if ($q !== '') {
            $keywords = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            $searchKeywordCount = count($keywords);
        }

        // ── 业务数据查询（通过 PartService）──
        $listResult = $service->getList($_GET);
        $rows       = $listResult['list'];
        $total      = $listResult['total'];
        $page       = $listResult['page'];
        $perPage    = $listResult['per_page'];
        $totalPage  = $listResult['total_page'];
        $catMap     = $listResult['cat_map'];

        // 统计数据
        $stats = $service->getStats();

        // 分类数据
        $catData        = $service->getCategoryData();
        $allCats        = $catData['all_cats'];
        $topCats        = $catData['top_cats'];
        $subCats        = $catData['sub_cats'];
        $unboundSubCats = $catData['unbound_sub_cats'];
        $noCatCount     = $catData['no_cat_count'];

        // 平台列表
        $allPlats = $service->getPlatforms();

        // 商品类型联想词
        $ptypeCats = $service->getProductTypeSuggestions();

        // ── 补充查询：库位列表（供 JS 使用）──
        $allLocs = $service->getLocations();

        // ── 分类 JSON（供内联 JS 使用）──
        $topCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'cnt'=>(int)$c['cnt']]; }, $topCats), JSON_UNESCAPED_UNICODE);
        $subCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'parent_id'=>(int)$c['parent_id'],'depth'=>(int)($c['depth'] ?? 2),'cnt'=>(int)$c['cnt']]; }, $subCats), JSON_UNESCAPED_UNICODE);
        $unboundSubCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'cnt'=>(int)$c['cnt']]; }, $unboundSubCats), JSON_UNESCAPED_UNICODE);

        // ── 公告（站点 + 子账号）与首看打标 ──
        $notices = (new NoticeService($db, $uid))->getNotices();

        $flash = $_GET['flash'] ?? '';
        $loginFlash = $_SESSION['login_flash'] ?? '';
        if ($loginFlash) unset($_SESSION['login_flash']);

        return [
            'q'                  => $q,
            'page'               => $page,
            'perPage'            => $perPage,
            'filter'             => $filter,
            'catParam'           => $catParam,
            'catId'              => $catId,
            'catIds'             => $catIds,
            'noCat'              => $noCat,
            'platId'             => $platId,
            'locFilter'          => $locFilter,
            'searchKeywordCount' => $searchKeywordCount,
            'rows'               => $rows,
            'total'              => $total,
            'totalPage'          => $totalPage,
            'catMap'             => $catMap,
            'stats'              => $stats,
            'allCats'            => $allCats,
            'topCats'            => $topCats,
            'subCats'            => $subCats,
            'unboundSubCats'     => $unboundSubCats,
            'noCatCount'         => $noCatCount,
            'allPlats'           => $allPlats,
            'ptypeCats'          => $ptypeCats,
            'allLocs'            => $allLocs,
            'topCatsJson'        => $topCatsJson,
            'subCatsJson'        => $subCatsJson,
            'unboundSubCatsJson' => $unboundSubCatsJson,
            'noticeContent'      => $notices['noticeContent'],
            'noticeMode'         => $notices['noticeMode'],
            'showNotice'         => $notices['showNotice'],
            'subNoticeContent'   => $notices['subNoticeContent'],
            'subNoticeMode'      => $notices['subNoticeMode'],
            'showSubNotice'      => $notices['showSubNotice'],
            'flash'              => $flash,
            'loginFlash'         => $loginFlash,
        ];
    }
}
