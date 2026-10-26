// modules/bom/Assets/js/bom-alt.js — BOM 管理页（?v=bom）· 绑定替代料弹窗（AltPicker）

var AltPicker = (function(){
    var state = {
        itemId: 0,
        currentPartId: 0,
        q: '',
        page: 1,
        perPage: 15,
        total: 0,
        totalPage: 1,
        cache: {},
        loading: false,
        debounceTimer: null,
        reachedEnd: false,
        suggestions: [],
        suggestLoaded: false,
        hint: '',
        selectedPid: 0,
        selectedTitle: ''
    };

    var els = {
        modal:       document.getElementById('altModal'),
        curInfo:     document.getElementById('altCurInfo'),
        input:       document.getElementById('altSearchInput'),
        listBox:     document.getElementById('altResultBox'),
        list:        document.getElementById('altResultList'),
        loading:     document.getElementById('altLoading'),
        loadMore:    document.getElementById('altLoadMore'),
        pageInfo:    document.getElementById('altPageInfo'),
        pageInput:   document.getElementById('altPageInput'),
        itemId:      document.getElementById('altItemId'),
        partId:      document.getElementById('altPartId'),
        useForm:     document.getElementById('altUseForm'),
        replaceForm: document.getElementById('altReplaceForm'),
        replaceItemId: document.getElementById('altReplaceItemId'),
        replacePartId: document.getElementById('altReplacePartId'),
        bindBtn:     document.getElementById('altBindBtn'),
        replaceBtn:  document.getElementById('altReplaceBtn'),
        selHint:     document.getElementById('altSelHint'),
        suggestHost: document.getElementById('altResultList')
    };

    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function renderRow(p){
        var cur = (p.id === state.currentPartId);
        var sel = (p.id === state.selectedPid) ? ' alt-row-selected' : '';
        var stockCls = p.stock > 0 ? 'is-in' : 'is-out';
        var colIntId  = p.internal_id ? LCSC.partCode(p.internal_id) : '-';
        var colModel  = p.model || '-';
        var colName   = p.product_name || '-';
        var colPkg    = p.package || '-';
        var levelBadge = '';
        if (p.level === 'auto' || p.recommended === 1) {
            levelBadge = '<span class="alt-suggest-badge" title="匹配总分'+(p.score_pct||0)+'%：封装/主参数硬约束全部通过，可直接选用">推荐'+(p.score_pct?' '+p.score_pct+'%':'')+'</span>';
        } else if (p.level === 'review') {
            levelBadge = '<span class="alt-level-badge" title="匹配总分'+(p.score_pct||0)+'%：存在需人工确认项，请核对理由标签">待确认'+(p.score_pct?' '+p.score_pct+'%':'')+'</span>';
        }
        var reasonChips = '';
        if (p.reasons && p.reasons.length) {
            var shown = 0;
            for (var ri = 0; ri < p.reasons.length && shown < 4; ri++) {
                var rr = p.reasons[ri];
                if (!rr || !rr.text) continue;
                reasonChips += '<span class="alt-reason alt-reason-'+(rr.type||'info')+'" title="'+escapeHtml(rr.text)+'">'+escapeHtml(rr.text)+'</span>';
                shown++;
            }
        }
        return '<div class="alt-row'+(cur?' alt-row-cur':'')+sel+'" data-pid="'+p.id+'" data-title="'+escapeHtml(colModel)+'">'
            + '<div class="alt-row-grid">'
            +   '<div class="alt-col" title="'+escapeHtml(colIntId)+'">'+escapeHtml(colIntId)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colModel)+'">'+levelBadge+escapeHtml(colModel)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colName)+'">'+escapeHtml(colName)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colPkg)+'">'+escapeHtml(colPkg)+'</div>'
            + '</div>'
            + '<div class="alt-row-meta">'
            +   '<span>编号:'+escapeHtml(p.platform_part_no||'-')+'</span>'
            +   (p.brand ? '<span> · 品牌:'+escapeHtml(p.brand)+'</span>' : '')
            +   (p.location ? '<span> · 库位:'+escapeHtml(p.location)+'</span>' : '')
            +   '<span class="alt-stock ' + stockCls + '"> · 库存 '+p.stock+'</span>'
            +   (cur ? '<span class="alt-bound"> · 当前已绑定</span>' : '')
            + '</div>'
            + (reasonChips ? '<div class="alt-row-reasons">'+reasonChips+'</div>' : '')
            + '</div>';
    }

    function updatePageInfo(){
        els.pageInfo.textContent = '第 ' + state.page + ' 页 / 共 ' + state.totalPage + ' 页 · 共 ' + state.total + ' 条';
        els.pageInput.max = state.totalPage;
        els.pageInput.value = state.page;
    }

    // ── 相似候选（仅供参考）：只读推荐，不代替用户搜索确认 ──
    function paintSuggest(){
        if (!els.suggestHost) return;
        var old = els.suggestHost.querySelector('.alt-suggest-block');
        if (old) old.remove();
        if (state.q !== '' || !state.suggestions || !state.suggestions.length) return;

        var html = '<div class="alt-suggest-block">'
            + '<div class="alt-suggest-header">相似候选 · 仅供参考（' + state.suggestions.length + ' 条）</div>';
        for (var i = 0; i < state.suggestions.length; i++) html += renderRow(state.suggestions[i]);
        html += '<div class="alt-suggest-divider">▼ 以下为全部库内物料，请自行搜索并确认后选择</div>'
            + '<div class="alt-suggest-note">以上相似项由系统按本行物料参数算出，可能不适用于本设计，请以搜索结果与实际规格为准。</div>'
            + '</div>';
        els.suggestHost.insertAdjacentHTML('afterbegin', html);
    }
    function renderSuggest(itemId){
        if (!itemId || state.suggestLoaded) return;
        state.suggestLoaded = true;
        var url = 'api.php?action=bom.pending_suggest&item_id=' + encodeURIComponent(itemId)
                + '&project_id=' + encodeURIComponent(_bomSelId || 0);
        LCSC.fetchJson(url).then(function(json){
            if (json.code !== 0) return;          // 候选是锦上添花，失败静默
            var list = (json.data && json.data.candidates) || [];
            if (!list.length) return;
            state.suggestions = list;
            paintSuggest();
        }).catch(function(){ /* 忽略 */ });
    }

    function renderList(pageData, append){
        if (!append) {
            els.list.innerHTML = '';
            state.reachedEnd = false;
        }
        if (!pageData || !pageData.list || pageData.list.length === 0) {
            if (!append) {
                els.list.innerHTML = state.q === ''
                    ? '<div class="alt-suggest-hint">请在上方搜索框输入关键词查找物料，或点下方候选先看个大概</div>'
                    : '<div class="alt-suggest-hint">未找到匹配的物料</div>';
                paintSuggest();
            }
            els.loadMore.classList.remove('is-on');
            return;
        }
        var html = '';
        for (var i = 0; i < pageData.list.length; i++) html += renderRow(pageData.list[i]);
        els.list.insertAdjacentHTML('beforeend', html);
        if (!append) paintSuggest();
        state.reachedEnd = (state.page >= state.totalPage);
        els.loadMore.classList.toggle('is-on', !state.reachedEnd);
    }

    function fetchPage(page, silent){
        if (state.cache[page]) {
            return Promise.resolve(state.cache[page]);
        }
        if (!silent) els.loading.classList.add('is-on');
        var url = 'api.php?action=parts.search_paged&q=' + encodeURIComponent(state.q) + '&page=' + page;
        return LCSC.fetchJson(url).then(function(json){
            if (!silent) els.loading.classList.remove('is-on');
            if (json.code !== 0) {
                LCSC.toast(json.msg || '查询失败', 'error');
                return null;
            }
            var d = json.data || {};
            var pageData = {
                list: d.list || [],
                total: d.total || 0,
                total_page: d.total_page || 1,
                page: d.page || page,
                has_more: !!d.has_more
            };
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
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 30) {
            loadNext();
        }
    }

    function open(itemId, model, name, partNo, qty, currentPartId, pkg, cat){
        state.itemId = itemId;
        state.currentPartId = currentPartId || 0;
        // 搜索框不预填：默认展示全库列表 + 顶部「相似候选 · 仅供参考」区块
        state.q = '';
        state.page = 1;
        state.cache = {};
        state.reachedEnd = false;
        state.suggestions = [];
        state.suggestLoaded = false;
        state.hint = '';
        state.selectedPid = 0;
        state.selectedTitle = '';
        els.itemId.value = itemId;
        els.partId.value = 0;
        if (els.replaceItemId) els.replaceItemId.value = itemId;
        if (els.replacePartId) els.replacePartId.value = 0;
        els.input.value = '';
        var infoHtml = '<div class="alt-info-row"><span class="alt-info-label">型号</span><b class="alt-info-val" title="'+escapeHtml(model||'-')+'">'+escapeHtml(model||'-')+'</b></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">名称</span><span class="alt-info-val" title="'+escapeHtml(name||'-')+'">'+escapeHtml(name||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">编号</span><span class="alt-info-val" title="'+escapeHtml(partNo||'-')+'">'+escapeHtml(partNo||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">封装</span><span class="alt-info-val" title="'+escapeHtml(pkg||'-')+'">'+escapeHtml(pkg||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">需求量</span><span class="alt-info-val">'+qty+'</span></div>';
        els.curInfo.innerHTML = infoHtml;
        els.list.innerHTML = '';
        if (els.bindBtn) els.bindBtn.disabled = true;
        if (els.replaceBtn) els.replaceBtn.disabled = true;
        if (els.selHint) els.selHint.textContent = '请在上方搜索框输入关键词查找物料（支持内部编号/型号/名称/外部编号）；顶部相似候选仅供参考，确认后点【绑定】或【替换】';
        openOverlay('altModal');
        loadPage(1);
        renderSuggest(itemId);
    }

    function selectRow(row, pid, title){
        state.selectedPid = pid;
        state.selectedTitle = title || '';
        var rows = els.list.querySelectorAll('.alt-row.alt-row-selected');
        for (var i = 0; i < rows.length; i++) rows[i].classList.remove('alt-row-selected');
        if (row) row.classList.add('alt-row-selected');
        if (els.bindBtn) els.bindBtn.disabled = false;
        if (els.replaceBtn) els.replaceBtn.disabled = false;
        if (els.selHint) {
            els.selHint.innerHTML = '已选中：<b class="alt-sel-name">'+escapeHtml(state.selectedTitle)+'</b> · 点【绑定】扣减库存关联 · 点【替换】仅覆盖BOM物料行';
        }
    }

    function bindSelect(){
        var pid = state.selectedPid;
        var title = state.selectedTitle;
        if (!pid) { LCSC.toast('请先在列表中点选一个物料', 'error'); return; }
        AdminUIConfirm({
            title: '绑定替代料',
            message: '确认将此物料绑定为替代料？\n\n选中物料：'+title+'\n\n绑定后该 BOM 项将切换为替代料扣减库存。',
            okText: '确认绑定',
            onOk: function(){
                els.partId.value = pid;
                var fd = new FormData(els.useForm);
                LCSC.post('api.php', fd, function(data, msg){
                    LCSC.toast(msg || '替代料绑定成功', 'success');
                    closeOverlay('altModal');
                    bomReload();
                });
            }
        });
    }

    function replaceSelect(){
        var pid = state.selectedPid;
        var title = state.selectedTitle;
        if (!pid) { LCSC.toast('请先在列表中点选一个物料', 'error'); return; }
        if (!els.replaceForm) { LCSC.toast('替换表单未就绪', 'error'); return; }
        AdminUIConfirm({
            title: '替换为标准物料',
            message: '确认将此 BOM 物料行替换为库存标准物料？\n\n选中物料：'+title+'\n\n替换后：\n· 本条 BOM 直接引用库存标准物料参数\n· 数量仍为 BOM 中导入的值\n· 不入库、不新增物料、不改变库存池\n· 原残缺数据被覆盖废弃',
            okText: '确认替换',
            onOk: function(){
                els.replacePartId.value = pid;
                var fd = new FormData(els.replaceForm);
                LCSC.post('api.php', fd, function(data, msg){
                    LCSC.toast(msg || '已替换为库存标准物料', 'success');
                    closeOverlay('altModal');
                    bomReload();
                });
            }
        });
    }

    function onListClick(e){
        var row = e.target.closest('.alt-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        var title = row.getAttribute('data-title') || '';
        if (pid > 0) selectRow(row, pid, title);
    }

    function jumpPage(){
        var p = parseInt(els.pageInput.value, 10);
        if (isNaN(p) || p < 1) p = 1;
        if (p > state.totalPage) p = state.totalPage;
        if (p === state.page) return;
        state.cache = {};
        loadPage(p);
    }

    if (els.input) {
        els.input.addEventListener('input', onInput);
    }
    if (els.listBox) {
        els.listBox.addEventListener('scroll', onScroll);
    }
    if (els.list) {
        els.list.addEventListener('click', onListClick);
    }
    if (els.loadMore) {
        els.loadMore.addEventListener('click', loadNext);
    }

    return { open: open, bindSelect: bindSelect, replaceSelect: replaceSelect, jumpPage: jumpPage };
})();

// 替代料按钮事件委托
document.addEventListener('click', function(e){
    var btn = e.target.closest('.alt-trigger');
    if (!btn) return;
    var itemId = parseInt(btn.getAttribute('data-item-id'), 10);
    var model = btn.getAttribute('data-model') || '';
    var name = btn.getAttribute('data-name') || '';
    var partNo = btn.getAttribute('data-partno') || '';
    var qty = parseInt(btn.getAttribute('data-qty'), 10) || 1;
    var currentPartId = parseInt(btn.getAttribute('data-current-pid'), 10) || 0;
    var pkg = btn.getAttribute('data-package') || '';
    var cat = btn.getAttribute('data-category') || '';
    AltPicker.open(itemId, model, name, partNo, qty, currentPartId, pkg, cat);
});
function altJumpPage(){ AltPicker.jumpPage(); }

// ════════ 清单行编辑（数量 / 位号 / 备注） ═══════
document.addEventListener('click', function(e){
    var btn = e.target.closest('.item-edit-trigger');
    if (!btn) return;
    var itemId = parseInt(btn.getAttribute('data-item-id'), 10);
    if (!itemId) return;
    openItemEdit(itemId,
        btn.getAttribute('data-qty') || '1',
        btn.getAttribute('data-ref') || '',
        btn.getAttribute('data-remark') || '',
        btn.getAttribute('data-part') || '');
});

var EI_PART_FIELDS = [
    ['model', 'ei_p_model'], ['brand', 'ei_p_brand'], ['package', 'ei_p_package'],
    ['product_type', 'ei_p_product_type'], ['product_name', 'ei_p_product_name'],
    ['customer_part_no', 'ei_p_customer_part_no'], ['location', 'ei_p_location'],
    ['parameters', 'ei_p_parameters']
];

function openItemEdit(itemId, qty, ref, remark, partJson){
    var form = document.getElementById('itemEditForm');
    if (!form) return;
    form.reset();
    document.getElementById('ei_item_id').value = itemId;
    document.getElementById('ei_qty').value = qty;
    document.getElementById('ei_ref').value = ref;
    document.getElementById('ei_remark').value = remark;

    var part = null;
    try { part = partJson ? JSON.parse(partJson) : null; } catch (err) { part = null; }
    var sec = document.getElementById('ei_partSec');
    if (sec) sec.classList.toggle('is-on', !!part);
    if (part) {
        EI_PART_FIELDS.forEach(function(pair){
            var el = document.getElementById(pair[1]);
            if (el) el.value = (part[pair[0]] == null ? '' : part[pair[0]]);
        });
    }

    var info = document.getElementById('itemEditInfo');
    if (info) {
        var row = document.querySelector('.item-edit-trigger[data-item-id="' + itemId + '"]');
        var host = row ? (row.closest('tr') || row.closest('.bom-card')) : null;
        var no = host ? ((host.querySelector('.bom-p-model') || {}).textContent || '') : '';
        var pn = host ? ((host.querySelector('.bom-p-no') || {}).textContent || '') : '';
        info.setAttribute('data-tip', '编号 ' + (pn || '-') + ' · 型号 ' + (no || '-'));
    }
    openOverlay('itemEditModal');
    setTimeout(function(){
        var q = document.getElementById('ei_qty');
        if (q) { q.focus(); q.select(); }
    }, 100);
}
