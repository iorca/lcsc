<?php
declare(strict_types=1);
/** mobile/Views/search.php — 移动端「物料查询」 */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

use Modules\Category\Service\CategoryTreeService;

$canSearch = hasFeature('parts');

$canViewCost = can_view_cost();
$globalThr   = getGlobalThreshold();

// ── 筛选维度选项（后端 parts.list 已支持）──

$platforms = [];
try {
    $platforms = \App\Api\PlatformRoutes::queryPlatforms(getDB(), (int)$user['id'], (int)getDataUserId());
} catch (\Throwable $e) {
    error_log('mobile/search: queryPlatforms failed: ' . $e->getMessage());
}

// ── 两级分类 chips（一级大类 is_top / 各一级下的二级）──
$tops = [];
$childrenByParent = [];
if ($canSearch) {
    try {
        $flat = (new CategoryTreeService(getDB(), (int)getDataUserId()))->getFlatTree();
        foreach ($flat as $n) {
            if (!empty($n['is_top'])) {
                $tops[] = ['id' => (int)$n['id'], 'name' => (string)$n['name'], 'cnt' => (int)$n['cnt']];
            } elseif ((int)$n['parent_id'] > 0) {
                $childrenByParent[(int)$n['parent_id']][] = ['id' => (int)$n['id'], 'name' => (string)$n['name'], 'cnt' => (int)$n['cnt']];
            }
        }
        usort($tops, static fn($a, $b) => $b['cnt'] <=> $a['cnt'] ?: strcmp($a['name'], $b['name']));
    } catch (\Throwable $e) {
        error_log('mobile/search: getFlatTree failed: ' . $e->getMessage());
    }
}

if (!$canSearch):
    ?>
    <div class="m-card m-placeholder">
        <div class="m-ph-icon"><span class="m-ico m-ico--lock" aria-hidden="true"></span></div>
        <div class="m-ph-title">物料查询未启用</div>
        <div class="m-ph-text">当前账号不可访问物料数据。</div>
    </div>
    <?php
    return;
endif;
?>

<!-- 配置注入：data-* 承载（移动端约定不写内联 <script>） -->
<?php

$__chartjs = __DIR__ . '/../../third_party/js/chart.umd.js';
$chartUrl  = '../third_party/js/chart.umd.js?v=' . APP_VERSION . '.' . (is_file($__chartjs) ? filemtime($__chartjs) : '0');
?>
<div id="mSearchRoot" hidden
     data-can-view-cost="<?= $canViewCost ? '1' : '0' ?>"
     data-low-threshold="<?= (int)$globalThr ?>"
     data-cat-tops="<?= h(json_encode($tops, JSON_UNESCAPED_UNICODE)) ?>"
     data-cat-children="<?= h(json_encode($childrenByParent, JSON_UNESCAPED_UNICODE)) ?>"
     data-platforms="<?= h(json_encode($platforms, JSON_UNESCAPED_UNICODE)) ?>"
     data-chart-url="<?= h($chartUrl) ?>"
     data-init-q="<?= h((string)($_GET['q'] ?? '')) ?>"
     data-init-filter="<?= h((string)($_GET['filter'] ?? '')) ?>"></div>

<!-- ══ 搜索框 ══ -->
<div class="m-card">
    <div class="m-search-wrap" id="searchWrap">
        <span class="m-search-ico" aria-hidden="true"><span class="m-ico m-ico--search" aria-hidden="true"></span></span>
        <input type="search" id="partSearch" class="m-input" autocomplete="off"
               enterkeyhint="search" placeholder="搜编号 / 型号 / 名称 / 库位" aria-label="搜索物料"
               value="<?= h((string)($_GET['q'] ?? '')) ?>">
        <button type="button" class="m-search-clear" id="searchClear" aria-label="清除关键词"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>

</div>

<!-- ══ 筛选工具条（统一入口） ══ -->
<div class="m-toolbar">
    <button type="button" class="m-toolbar-btn" id="mFilterBtn" aria-haspopup="dialog">
        <span class="m-ico m-ico--gear" aria-hidden="true"></span> 筛选<span class="m-toolbar-badge is-hidden" id="mFilterBadge">0</span>
    </button>
</div>

<!-- ══ 结果区 ══ -->
<div class="m-result-count is-hidden" id="partCount"></div>

<div class="m-parts" id="partList" tabindex="-1"></div>
<div class="m-part-pager" id="partPager" hidden></div>

<!-- ══ 筛选抽屉：分类多选 / 库存状态 / 平台 ══ -->
<div class="m-drawer-overlay" id="filterOverlay"></div>
<div class="m-drawer" id="filterDrawer" role="dialog" aria-modal="true" aria-label="筛选">
    <div class="m-drawer-handle"></div>
    <div class="m-drawer-head">
        <span class="m-drawer-title">筛选</span>
        <button type="button" class="m-drawer-close" id="filterClose" aria-label="关闭"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>
    <div class="m-drawer-body">
        <div class="m-field">
            <span class="m-label">分类（可多选）
                <button type="button" class="m-cat-clear" id="catClear" disabled>清空</button>
            </span>
            <input type="text" class="m-input" id="catSearch" placeholder="搜索分类名称…" autocomplete="off" aria-label="搜索分类">
            <div class="m-cat-top-scroll" id="catTopScroll" role="group" aria-label="一级分类"></div>
            <div class="m-cat-sub-list" id="catSubList"></div>
            <div class="m-cat-footer-ops">
                <button type="button" class="m-btn m-btn-sm m-btn--inline" id="catSelectAll">全选</button>
                <button type="button" class="m-btn m-btn-sm m-btn--inline" id="catInvert">反选</button>
            </div>
        </div>

        <div class="m-field">
            <span class="m-label">库存状态</span>
            <div class="m-chip-scroll" id="filterStockBar" role="group" aria-label="库存状态">
                <button type="button" class="m-chip is-on" data-filter="">全部</button>
                <button type="button" class="m-chip" data-filter="low">库存不足</button>
                <button type="button" class="m-chip" data-filter="zero">已用完</button>
                <button type="button" class="m-chip" data-filter="damaged">含不良品</button>
                <button type="button" class="m-chip" data-filter="negative">负库存</button>
            </div>
        </div>

        <div class="m-field">
            <span class="m-label">平台</span>
            <div class="m-chip-scroll" id="filterPlatBar" role="group" aria-label="平台">
                <button type="button" class="m-chip is-on" data-plat="0">全部</button>
            </div>
        </div>
    </div>
    <div class="m-cat-footer">
        <div class="m-cat-footer-ops">
            <button type="button" class="m-btn m-btn-sm m-btn--inline" id="filterReset">重置</button>
        </div>
        <div class="m-cat-footer-btns">
            <button type="button" class="m-btn m-btn-sm m-btn--inline" id="filterCancel">取消</button>
            <button type="button" class="m-btn m-btn-sm m-btn--inline m-btn-primary" id="filterApply">应用</button>
        </div>
    </div>
</div>


<div class="m-drawer-overlay" id="partDetailOverlay"></div>
<div class="m-drawer m-drawer-tall" id="partDetailDrawer" role="dialog" aria-modal="true" aria-label="物料详情">
    <div class="m-drawer-handle"></div>
    <div class="m-drawer-head">
        <span class="m-drawer-title" id="partDetailTitle">物料详情</span>
        <button type="button" class="m-drawer-close" id="partDetailClose" aria-label="关闭"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>
    <div class="m-drawer-body" id="partDetailBody">
        <!-- Hero 快览：型号 + 内部编号 + 名称 + 分类，右侧大号库存 -->
        <div class="m-pd-hero" data-role="hero">
            <div class="m-pd-hero-main">
                <div class="m-pd-hero-top">
                    <span class="m-pd-hero-model is-mono" data-role="hero-model">—</span>
                    <span class="m-pd-hero-code is-mono" data-role="hero-code"></span>
                </div>
                <div class="m-pd-hero-name" data-role="hero-name"></div>
                <div class="m-pd-hero-tags" data-role="hero-tags"></div>
            </div>
            <div class="m-pd-hero-stock">
                <div class="m-pd-hero-num" data-role="hero-stock">0</div>
                <div class="m-pd-hero-cap">良品库存</div>
                <div class="m-pd-hero-damaged is-hidden" data-role="hero-damaged-wrap">
                    <div class="m-pd-hero-num is-bad" data-role="hero-damaged">0</div>
                    <div class="m-pd-hero-cap">不良品</div>
                </div>
            </div>
        </div>

        <!-- 关键信息条：品牌 / 封装 / 库位 / 客户料号 / 编号（横向可扫） -->
        <div class="m-pd-facts is-hidden" data-role="facts"></div>


        <section class="m-pd-sec">
            <div class="m-pd-sec-title">基础信息</div>
            <div class="m-pd-kv" data-role="kv"></div>
        </section>

        <!-- 分节：资产成本（个人版 / 关闭成本视图时整节不渲染） -->
        <section class="m-pd-sec is-hidden" data-role="cost-wrap">
            <div class="m-pd-sec-title">资产成本</div>
            <div class="m-pd-cost">
                <div class="m-pd-cost-item">
                    <div class="m-pd-cost-k">物料累计资产总额</div>
                    <div class="m-pd-cost-v is-green is-mono" data-role="cost-total">—</div>
                </div>
                <div class="m-pd-cost-item">
                    <div class="m-pd-cost-k">最新采购含税单价</div>
                    <div class="m-pd-cost-v is-accent is-mono" data-role="cost-latest">—</div>
                </div>
            </div>
            <div class="m-pd-cost-note">所有有效入库流水（含税）小计之和；「样品不计资产」的入库记录不计入</div>
        </section>

        <!-- 分节：库存变化（折线图；无足够记录时整节不渲染） -->
        <section class="m-pd-sec is-hidden" data-role="chart-wrap">
            <div class="m-pd-sec-title">库存变化</div>
            <div class="m-pd-chart"><canvas data-role="stock-chart"></canvas></div>
        </section>

        <!-- 分节：关联信息（分类 / 替代料 / 关联标准物料） -->
        <section class="m-pd-sec is-hidden" data-role="rel-wrap">
            <div class="m-pd-sec-title">关联信息</div>
            <div class="m-pd-rel" data-role="rel"></div>
        </section>

        <!-- 备注 / 采购链接（无内容不渲染） -->
        <section class="m-pd-sec is-hidden" data-role="remark-wrap">
            <div class="m-pd-sec-title">备注</div>
            <div class="m-note-body" data-role="remark"></div>
        </section>
        <a class="m-btn is-hidden" data-role="buy" target="_blank" rel="noopener noreferrer"><span class="m-ico m-ico--link" aria-hidden="true"></span> 去采购平台</a>

        <!-- 最近出入库 -->
        <section class="m-pd-sec">
            <div class="m-pd-sec-title">最近出入库</div>
            <div class="m-part-logs" data-role="logs"><div class="m-hint">加载中…</div></div>
        </section>
        <div class="m-hint">编辑物料请到电脑端「物料管理」。</div>
    </div>
</div>
