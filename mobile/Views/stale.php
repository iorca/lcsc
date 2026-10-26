<?php
declare(strict_types=1);
/** mobile/Views/stale.php — 移动端「呆滞物料」（二级页） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$canViewCost = can_view_cost();
?>
<div id="mStaleRoot" hidden
     data-can-view-cost="<?= $canViewCost ? '1' : '0' ?>"></div>

<!-- ══ 搜索 ══ -->
<div class="m-card">
    <div class="m-search-wrap" id="staleSearchWrap">
        <span class="m-search-ico" aria-hidden="true"><span class="m-ico m-ico--search" aria-hidden="true"></span></span>
        <input type="search" id="staleSearch" class="m-input" autocomplete="off"
               enterkeyhint="search" placeholder="搜型号 / 编号 / 名称" aria-label="搜索呆滞物料">
        <button type="button" class="m-search-clear" id="staleClear" aria-label="清除关键词"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>
    <div class="m-chip-scroll" id="staleDaysBar" role="group" aria-label="呆滞天数">
        <button type="button" class="m-chip is-on" data-days="0">默认天数</button>
        <button type="button" class="m-chip" data-days="90">90 天</button>
        <button type="button" class="m-chip" data-days="180">180 天</button>
        <button type="button" class="m-chip" data-days="365">365 天</button>
    </div>
</div>

<!-- ══ 积压分档 ══ -->
<div class="m-card" id="staleBucketsCard">
    <div class="m-card-title">积压分档</div>
    <div class="m-stat-grid" id="staleBuckets"></div>
</div>

<!-- ══ 清单 ══ -->
<div class="m-result-count is-hidden" id="staleCount"></div>
<div class="m-parts" id="staleList"></div>

<div class="m-load-more" id="staleMore">
    <span class="m-load-more-text is-hidden" id="staleMoreText"></span>
</div>

<div class="m-card m-note-card">
    <div class="m-note-text">呆滞指「长期没有出入库」且仍有库存的物料。标记已处理等批量操作请到电脑端「库存管理 · 呆滞物料」进行。</div>
</div>
