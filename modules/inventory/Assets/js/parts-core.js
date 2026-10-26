// modules/inventory/Assets/js/parts-core.js — 物料列表页（?v=parts）· 公共基础
var CFG = window.PartsPageConfig || {};

// ── 全局筛选状态 ──
var _filterState = {
    q: CFG.q || '',
    filter: CFG.filter || '',
    cat: CFG.cat || '',
    plat: CFG.plat || 0,
    loc: CFG.loc || '',
    page: CFG.page || 1,
    per_page: CFG.perPage || 25,
    sort: 'update_time',
    dir: 'desc'
};

// ── 平台元数据（type / url_template）与全局低库存阈值 ──
var _platMeta = CFG.platMeta || {};
var _globalThr = CFG.globalThr || 0;
var _catMapCache = {};

function normalizeRow(r) {
    var plat = _platMeta[r.platform_id] || {};
    r.eff_threshold = r.low_stock_threshold || _globalThr;
    r.platform_type = plat.type || 'standard';
    r.url_template  = plat.url || '';
    r.ppn           = r.platform_part_no || '';
    r.cats          = _catMapCache[r.id] || [];
    return r;
}

// ── 简易HTML转义 ──
function esc(s) { if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── URL生成辅助 ──
function platformUrl(template, partNo) {
    if (!template || !partNo) return '';
    return template.replace('{part_no}', partNo);
}
