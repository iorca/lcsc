// modules/bom/Assets/js/bom-item.js — BOM 管理页（?v=bom）· 清单行编辑、手动添加物料到 BOM 弹窗

var AddItemPicker = (function(){
    var state = {
        q: '', page: 1, perPage: 15, total: 0, totalPage: 1,
        cache: {}, loading: false, debounceTimer: null, reachedEnd: false,
        selectedPartId: 0
    };
    var els = {
        input: document.getElementById('addItemSearchInput'),
        listBox: document.getElementById('addItemResultBox'),
        list: document.getElementById('addItemResultList'),
        loading: document.getElementById('addItemLoading'),
        loadMore: document.getElementById('addItemLoadMore'),
        pageInfo: document.getElementById('addItemPageInfo'),
        pageInput: document.getElementById('addItemPageInput'),
        selected: document.getElementById('addItemSelected'),
        selectedInfo: document.getElementById('addItemSelectedInfo'),
        partIdInput: document.getElementById('addItemPartId'),
        qtyGroup: document.getElementById('addItemQtyGroup'),
        actionInput: document.getElementById('addItemAction'),
        submitBtn: document.getElementById('addItemSubmit'),
        title: document.getElementById('addItemModalTitle')
    };
    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function renderRow(p){
        var colIntId = p.internal_id ? LCSC.partCode(p.internal_id) : '-';
        var colModel = p.model || '-';
        var colName = p.product_name || '-';
        var colPkg = p.package || '-';
        var sel = (p.id === state.selectedPartId) ? ' alt-row-cur' : '';
        return '<div class="alt-row'+sel+'" data-pid="'+p.id+'" data-title="'+escapeHtml(colModel)+'">'
            + '<div class="alt-row-grid">'
            +   '<div class="alt-col" title="'+escapeHtml(colIntId)+'">'+escapeHtml(colIntId)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colModel)+'">'+escapeHtml(colModel)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colName)+'">'+escapeHtml(colName)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colPkg)+'">'+escapeHtml(colPkg)+'</div>'
            + '</div>'
            + '<div class="alt-row-meta">'
            +   '<span>编号:'+escapeHtml(p.platform_part_no||'-')+'</span>'
            +   (p.brand ? '<span> · 品牌:'+escapeHtml(p.brand)+'</span>' : '')
            +   '<span class="alt-stock '+(p.stock>0?'is-in':'is-out')+'"> · 库存 '+p.stock+'</span>'
            + '</div></div>';
    }
    function updatePageInfo(){
        els.pageInfo.textContent = '第 ' + state.page + ' 页 / 共 ' + state.totalPage + ' 页 · 共 ' + state.total + ' 条';
        els.pageInput.max = state.totalPage;
        els.pageInput.value = state.page;
    }
    function renderList(pageData, append){
        if (!append) { els.list.innerHTML = ''; state.reachedEnd = false; }
        if (!pageData || !pageData.list || pageData.list.length === 0) {
            if (!append) els.list.innerHTML = '<div class="alt-empty">未找到匹配的物料</div>';
            // **必须用 classList.toggle，不能写 style.display** ——
            els.loadMore.classList.remove('is-on');
            return;
        }
        var html = '';
        for (var i = 0; i < pageData.list.length; i++) html += renderRow(pageData.list[i]);
        els.list.insertAdjacentHTML('beforeend', html);
        els.loadMore.classList.toggle('is-on', state.page < state.totalPage);
    }
    function fetchPage(page, silent){
        if (state.cache[page]) return Promise.resolve(state.cache[page]);
        if (!silent) els.loading.classList.add('is-on');
        var url = 'api.php?action=parts.search_paged&q=' + encodeURIComponent(state.q) + '&page=' + page;
        return LCSC.fetchJson(url).then(function(json){
            if (!silent) els.loading.classList.remove('is-on');
            if (json.code !== 0) { LCSC.toast(json.msg || '查询失败', 'error'); return null; }
            var d = json.data || {};
            var pageData = { list: d.list || [], total: d.total || 0, total_page: d.total_page || 1, page: d.page || page, has_more: !!d.has_more };
            state.cache[page] = pageData;
            return pageData;
        }).catch(function(e){
            if (!silent) els.loading.classList.remove('is-on');
            LCSC.toast('查询失败：' + (e.message || e), 'error');
            return null;
        });
    }
    function loadPage(page, opts){
        opts = opts || {};
        if (state.loading) return;
        state.loading = true;
        state.page = page;
        fetchPage(page, opts.silent).then(function(pageData){
            state.loading = false;
            if (!pageData) return;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, false);
            updatePageInfo();
            if (page === 1 && !opts.noPreload) {
                if (state.totalPage >= 2) fetchPage(2, true);
                if (state.totalPage >= 3) fetchPage(3, true);
            }
            if (!opts.silent) els.listBox.scrollTop = 0;
        });
    }
    function loadNext(){
        if (state.loading || state.reachedEnd) return;
        var next = state.page + 1;
        if (next > state.totalPage) return;
        state.loading = true;
        els.loadMore.textContent = '加载中…';
        fetchPage(next, false).then(function(pageData){
            state.loading = false;
            els.loadMore.textContent = '点击加载更多';
            if (!pageData) return;
            state.page = next;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, true);
            updatePageInfo();
            var preload = next + 1;
            if (preload <= state.totalPage) fetchPage(preload, true);
        });
    }
    function onInput(){
        if (state.debounceTimer) clearTimeout(state.debounceTimer);
        state.debounceTimer = setTimeout(function(){
            var v = els.input.value.trim();
            if (v === state.q) return;
            state.q = v;
            state.cache = {};
            loadPage(1);
        }, 300);
    }
    function onScroll(){
        var box = els.listBox;
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 30) loadNext();
    }
    function onListClick(e){
        var row = e.target.closest('.alt-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        if (pid <= 0) return;
        state.selectedPartId = pid;
        var allRows = els.list.querySelectorAll('.alt-row');
        for (var i = 0; i < allRows.length; i++) allRows[i].classList.remove('alt-row-cur');
        row.classList.add('alt-row-cur');
        var title = row.getAttribute('data-title') || '';
        var meta = row.querySelector('.alt-row-meta');
        var metaText = meta ? meta.textContent : '';
        els.selectedInfo.innerHTML = '<strong>'+escapeHtml(title)+'</strong><div class="alt-sel-meta">'+escapeHtml(metaText)+'</div>';
        els.selected.classList.add('is-on');
        els.partIdInput.value = pid;
        els.submitBtn.disabled = false;
    }
    function jumpPage(){
        var p = parseInt(els.pageInput.value, 10);
        if (isNaN(p) || p < 1) p = 1;
        if (p > state.totalPage) p = state.totalPage;
        if (p === state.page) return;
        state.cache = {};
        loadPage(p);
    }
    function open(){
        state.q = '';
        state.page = 1; state.cache = {}; state.reachedEnd = false; state.selectedPartId = 0;
        els.input.value = '';
        els.partIdInput.value = 0;
        els.submitBtn.disabled = true;
        els.selected.classList.remove('is-on');
        els.list.innerHTML = '';
        if (els.title) els.title.textContent = '添加物料到 BOM';
        if (els.qtyGroup) els.qtyGroup.style.display = '';
        if (els.actionInput) els.actionInput.value = 'bom.add_item_by_part_id';
        if (els.submitBtn) els.submitBtn.textContent = '添加到 BOM';
        openOverlay('addItemModal');
        loadPage(1);
        setTimeout(function(){ els.input.focus(); }, 100);
    }
    if (els.input) els.input.addEventListener('input', onInput);
    if (els.listBox) els.listBox.addEventListener('scroll', onScroll);
    if (els.list) els.list.addEventListener('click', onListClick);
    if (els.loadMore) els.loadMore.addEventListener('click', loadNext);
    return { open: open, jumpPage: jumpPage };
})();

function openAddItem(){ AddItemPicker.open(); }
function addItemJumpPage(){ AddItemPicker.jumpPage(); }

function saveBomProfitMargin(val) {
    var cfg = window._bomCfg || window.BomPageConfig || {};
    var margin = parseFloat(val) || 0;
    if (margin < 0) margin = 0;
    if (margin >= 100) margin = 99.99;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=bom.save_profit_margin&project_id=' + (cfg.selId || 0) + '&profit_margin=' + margin + '&_csrf=' + (cfg.csrf || '')
    }).then(r => r.json()).then(d => {
        if (d.code === 0) {
            var totalCost = cfg.bomCostTotal || 0;
            var quoteEl = document.getElementById('bomQuotePrice');
            if (margin > 0 && totalCost > 0) {
                var quote = totalCost / (1 - margin / 100);
                if (quoteEl) {
                    quoteEl.textContent = '¥' + quote.toFixed(2);
                    quoteEl.style.display = '';
                }
            } else if (quoteEl) {
                quoteEl.style.display = 'none';
            }
            LCSC.toast('利润率已保存');
        } else {
            LCSC.toast(d.msg || '保存失败', 'error');
        }
    });
}
