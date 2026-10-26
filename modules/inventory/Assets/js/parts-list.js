// modules/inventory/Assets/js/parts-list.js — 物料列表页 · 列表与筛选

function loadPartsList() {
    var params = 'action=parts.list&_csrf=' + LCSC.csrf
        + '&q=' + encodeURIComponent(_filterState.q)
        + '&filter=' + _filterState.filter
        + '&cat=' + encodeURIComponent(_filterState.cat)
        + '&cats=' + encodeURIComponent(_filterState.cats)
        + '&plat=' + _filterState.plat
        + '&loc=' + encodeURIComponent(_filterState.loc)
        + '&page=' + _filterState.page
        + '&per_page=' + _filterState.per_page
        + '&sort=' + _filterState.sort
        + '&dir=' + _filterState.dir;
    LCSC.get('api.php?' + params, function(data) {
        _catMapCache = data.cat_map || {};
        var rows = (data.list || []).map(normalizeRow);
        updateStats(data.stats);
        renderSortArrows();
        renderDesktopTable(rows);
        renderPagination(data.total, data.page, data.per_page, data.total_page);
        updateUrl();
    }, function(msg) {
        LCSC.toast('加载失败: ' + msg, 'error');
    });
}

function updateStats(s) {
    if (!s) return;
    var el;
    if (el = document.getElementById('statTotal')) el.textContent = s.total.toLocaleString ? s.total.toLocaleString() : s.total;
    if (el = document.getElementById('statStock')) el.textContent = s.total_stock.toLocaleString ? s.total_stock.toLocaleString() : s.total_stock;
    if (el = document.getElementById('statLow')) el.textContent = s.low_count;
    if (el = document.getElementById('statZero')) el.textContent = s.zero_count;
    if (el = document.getElementById('statDamaged')) el.textContent = s.total_damaged.toLocaleString ? s.total_damaged.toLocaleString() : s.total_damaged;
    if (el = document.getElementById('statNegative')) el.textContent = (s.negative_count || 0);
}

function renderDesktopTable(parts) {
    var tbody = document.getElementById('desktopTbody');
    if (!tbody) return;
    var isAdmin_ = CFG.isAdmin ? 1 : 0;
    var canEdit_ = CFG.canEdit ? 1 : 0;
    var canDel_ = CFG.canDelete ? 1 : 0;
    var cols_ = CFG.cols;
    // 图片开关：由 PHP 注入（站点级设置，默认关）。关闭时不输出图片 td ——
    var showImg_ = CFG.showImages ? 1 : 0;
    if (!parts || parts.length === 0) {
        var esAct = '';
        if (canEdit_) esAct += '<button class="btn btn-primary btn-sm" onclick="openAddModal()">＋ 添加元件</button>';
        if (_filterState.q || _filterState.filter || _filterState.cat || _filterState.plat || _filterState.loc) {
            esAct += '<button class="btn btn-ghost btn-sm" onclick="resetFilters()">清除全部筛选</button>';
        }
        // 空状态文案与 PHP 首屏保持一致：多条件时提示 AND 语义与分隔符
        var esDesc;
        if (!_filterState.q) {
            esDesc = '添加第一个元件开始管理库存';
        } else if (splitSearchKeywords(_filterState.q).length > 1) {
            esDesc = '多个条件之间为 AND 关系（需同时满足）。可用空格 / | , ; 分隔；商品编号等唯一标识按精准匹配，封装与参数按包含匹配';
        } else {
            esDesc = '试试简化关键词，或清除筛选条件（至少输入 2 个字符）';
        }
        tbody.innerHTML = '<tr><td colspan="' + cols_ + '"><div class="empty-state">'
            + '<div class="icon">📦</div>'
            + '<div class="es-title">' + (_filterState.q ? '未找到匹配物料' : '暂无数据') + '</div>'
            + '<div class="es-desc">' + esDesc + '</div>'
            + (esAct ? '<div class="es-actions">' + esAct + '</div>' : '')
            + '</div></td></tr>';
        return;
    }
    var html = '';
    parts.forEach(function(r) {
        var sc = r.stock === 0 ? 's-zero' : (r.stock <= r.eff_threshold ? 's-low' : 's-ok');
        var rc = r.stock === 0 ? 'row-zero' : (r.stock <= r.eff_threshold ? 'row-low' : '');
        var ppnUrl = (r.platform_type === 'standard' && r.url_template) ? platformUrl(r.url_template, r.ppn) : '';
        var catsHtml = (r.cats || []).map(function(ct) { return '<span class="cat-tag">' + esc(ct) + '</span>'; }).join('');
        var pkgHtml = r.package ? '<span class="pkg-badge">' + esc(r.package) + '</span>' : '<span class="ph-dash">—</span>';
        var catsHtmlFull = (r.cats && r.cats.length) ? catsHtml : '<span class="ph-dash">—</span>';
        var locHtml = r.location ? '<span class="loc-chip" title="' + esc(r.location) + '（点击可筛选同库位物料）" onclick="event.stopPropagation();filterByLocation(\'' + esc(r.location).replace(/'/g,'&#39;') + '\')">📍 <span class="loc-name">' + esc(r.location) + '</span></span>' : '<span class="loc-chip-empty">—</span>';
        // 型号列第二行：商品描述（超长截断，悬停显示完整）；品牌已独立成列
        var metaHtml = r.product_name
            ? '<span class="model-sub td-ellipsis" title="' + esc(r.product_name) + '">' + esc(r.product_name) + '</span>'
            : '';
        var brandHtml = r.brand
            ? '<span class="brand-txt">' + esc(r.brand) + '</span>'
            : '<span class="ph-dash">—</span>';
        html += '<tr class="' + rc + '" data-part-id="' + r.id + '">';
        if (isAdmin_) html += '<td class="cb-col" onclick="event.stopPropagation()"><input type="checkbox" class="part-cb" value="' + r.id + '" data-stock="' + r.stock + '" onchange="onPartCbChange(this)"></td>';
        if (showImg_) {
            html += r.image
                ? '<td class="col-thumb" onclick="event.stopPropagation()"><img class="row-thumb" src="' + esc(attImgSrc(r.image, 160)) + '" alt="" loading="lazy" onclick="event.stopPropagation();attPreview(\'' + esc(attImgSrc(r.image, 800)) + '\')" title="点击放大"></td>'
                : '<td class="col-thumb"><span class="row-thumb-empty"></span></td>';
        }
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.ppn) + '" class="col-code td-ellipsis" data-label="商品编号">';
        if (ppnUrl) html += '<a href="' + esc(ppnUrl) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="code-blue u-underline">' + esc(r.ppn) + '</a>';
        else html += '<span class="code-blue">' + esc(r.ppn) + '</span>';
        html += '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.model) + '" class="col-model" data-label="型号"><span class="model-line"><span class="model-txt">' + esc(r.model) + '</span></span>' + metaHtml + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.package) + '" class="col-pkg td-ellipsis" data-label="封装">' + pkgHtml + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc((r.cats || []).join(', ')) + '" class="col-cat td-ellipsis" data-label="分类">' + catsHtmlFull + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.brand || '') + '" class="col-brand td-ellipsis" data-label="品牌">' + brandHtml + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" class="col-stock" data-label="库存"><span class="stock-num ' + sc + '" title="当前库存 ' + r.stock + (r.eff_threshold ? ' / 最低阈值 ' + r.eff_threshold : '') + '">' + r.stock + '</span>'
            + (r.damaged > 0 ? '<span class="stk-tag red" title="不良品数量">不良' + r.damaged + '</span>' : '')
            + (r.is_stale ? '<span class="stk-tag gray" title="90天无出入库">呆滞</span>' : '') + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.location) + '" class="col-location td-ellipsis" data-label="库位">' + locHtml + '</td>';
        var ctimeTxt = r.create_time ? String(r.create_time).substring(0, 16).replace('T', ' ') : '';
        var ctimeHtml = ctimeTxt
            ? '<span class="ctime-txt" title="导入时间 ' + esc(r.create_time || '') + '">' + esc(ctimeTxt) + '</span>'
            : '<span class="ph-dash">—</span>';
        html += '<td onclick="openDrawer(' + r.id + ')" class="col-ctime" data-label="导入时间">' + ctimeHtml + '</td>';
        html += '<td class="td-actions" onclick="event.stopPropagation()"><div class="actions">';
        if (canEdit_) html += '<button class="btn btn-ghost btn-sm btn-stock" data-info="' + esc(JSON.stringify({id:r.id,model:r.model,ppn:r.ppn,location_id:r.location_id || '',stock:r.stock,damaged:r.damaged})) + '">出入库</button>';
        if (canEdit_) html += '<button class="btn btn-ghost btn-sm" data-part="' + esc(JSON.stringify(r)) + '" data-platform-type="' + esc(r.platform_type || 'standard') + '" onclick="openEditModal(this)">编辑</button>';
        if (canDel_) html += '<button class="btn btn-danger btn-sm btn-del" data-info="' + esc(JSON.stringify({id:r.id,model:r.model,stock:r.stock,damaged:r.damaged})) + '">删除</button>';
        html += '</div></td></tr>';
    });
    tbody.innerHTML = html;
}

function renderPagination(total, page, perPage, totalPage) {
    var area = document.getElementById('paginationArea');
    if (!area) return;
    if (totalPage <= 1 && total <= 0) { area.innerHTML = ''; return; }
    var html = '';
    html += '<a href="javascript:void(0)" onclick="goPage(' + (page-1) + ')" class="page-btn ' + (page<=1?'disabled':'') + '">‹</a>';
    var s = Math.max(1, page-2), e = Math.min(totalPage, page+2);
    if (s > 1) html += '<a href="javascript:void(0)" onclick="goPage(1)" class="page-btn">1</a>';
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += '<a href="javascript:void(0)" onclick="goPage(' + i + ')" class="page-btn ' + (i===page?'active':'') + '">' + i + '</a>';
    if (e < totalPage-1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += '<a href="javascript:void(0)" onclick="goPage(' + totalPage + ')" class="page-btn">' + totalPage + '</a>';
    html += '<a href="javascript:void(0)" onclick="goPage(' + (page+1) + ')" class="page-btn ' + (page>=totalPage?'disabled':'') + '">›</a>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    html += '<select onchange="changePerPage(this.value)" class="per-page-select">';
    [10,15,20,25,30,35,40,45,50].forEach(function(pp) {
        html += '<option value="' + pp + '" ' + (pp===perPage?'selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

function buildFilterQueryString() {
    var parts = [];
    if (_filterState.q) parts.push('q=' + encodeURIComponent(_filterState.q));
    if (_filterState.filter) parts.push('filter=' + _filterState.filter);
    if (_filterState.cat) parts.push('cat=' + encodeURIComponent(_filterState.cat));
    if (_filterState.cats) parts.push('cats=' + encodeURIComponent(_filterState.cats));
    if (_filterState.plat) parts.push('plat=' + _filterState.plat);
    if (_filterState.loc) parts.push('loc=' + encodeURIComponent(_filterState.loc));
    return parts.join('&');
}

// ── 筛选操作函数 ──
function applyFilter(f) {
    _filterState.filter = f;
    _filterState.page = 1;
    // 高亮统计卡筛选态（点击可点卡筛选）
    syncFilterChips();
    var tip = { '': '已显示全部物料', low: '已筛选：库存不足', zero: '已筛选：用完', damaged: '已筛选：不良品', negative: '已显示负库存' };
    if (typeof LCSC !== 'undefined' && LCSC.toast) LCSC.toast(tip[f] || '已筛选', 'info');
    loadPartsList();
}

function applyPlatform(val) {
    _filterState.plat = parseInt(val) || 0;
    _filterState.page = 1;
    loadPartsList();
}

// ── 库位筛选：点击列表中库位 chip，筛选同库位物料 ──
function filterByLocation(loc) {
    _filterState.loc = loc;
    _filterState.page = 1;
    syncFilterChips();   // 立即亮出「📍 库位 … ✕」标签 —— 不亮的话用户不知道列表被筛过
    loadPartsList();
    if (typeof LCSC !== 'undefined' && LCSC.toast) LCSC.toast('已筛选库位：' + loc + '（点标签上的 ✕ 可清除）', 'info');
}
function syncFilterChips() {
    document.querySelectorAll('.stat-filter[data-filter]').forEach(function(card) {
        card.classList.toggle('active', card.getAttribute('data-filter') === _filterState.filter);
    });
    updateFilterStateTag();
}
// ── 筛选状态标签（点击统计卡后自动生成，样式与分类标签统一）──
var FS_TIP = { '': '', low: '库存不足', zero: '已用完', damaged: '不良品', negative: '负库存' };
function updateFilterStateTag() {
    var row = document.getElementById('filterStateRow');
    if (!row) return;
    var html = '';
    var f = _filterState.filter;
    if (f && FS_TIP[f]) {
        html += '<span class="filter-state-tag">' + esc(FS_TIP[f])
            + '<span class="fs-close" title="清除筛选" onclick="applyFilter(\'\')">✕</span></span>';
    }
    if (_filterState.loc) {
        html += '<span class="filter-state-tag">📍 ' + esc(_filterState.loc)
            + '<span class="fs-close" title="清除库位筛选" onclick="clearLocFilter()">✕</span></span>';
    }
    row.innerHTML = html;
}
// ── 表头排序（点击切换升降序，仅更新排序参数）──
var SORT_KEY = { platform_part_no: 'platform_part_no', model: 'model', stock: 'stock', location: 'location' };
function toggleSort(th) {
    if (!th) return;
    var key = th.getAttribute('data-sort');
    if (_filterState.sort === key) {
        _filterState.dir = (_filterState.dir === 'desc') ? 'asc' : 'desc';
    } else {
        _filterState.sort = key;
        _filterState.dir = 'desc';
    }
    _filterState.page = 1;
    renderSortArrows();
    loadPartsList();
}
function renderSortArrows() {
    document.querySelectorAll('th.sortable').forEach(function(th) {
        th.classList.remove('sorted-asc', 'sorted-desc');
        var k = th.getAttribute('data-sort');
        if (k === _filterState.sort) th.classList.add(_filterState.dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
    });
}
// ── 表格密度切换（舒适/紧凑，存本地偏好）──
function toggleDensity(mode) {
    var tbl = document.getElementById('desktopTable');
    if (tbl) tbl.classList.toggle('dense', mode === 'dense');
    try { localStorage.setItem('inv_density', mode); } catch (e) {}
    document.querySelectorAll('#densSeg button').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-dens') === mode);
    });
}
(function(){
    var m = 'comfort';
    try { m = localStorage.getItem('inv_density') || 'comfort'; } catch (e) {}
    toggleDensity(m);
})();
function clearLocFilter() {
    _filterState.loc = '';
    _filterState.page = 1;
    syncFilterChips();
    loadPartsList();
    if (typeof LCSC !== 'undefined' && LCSC.toast) LCSC.toast('已清除库位筛选', 'info');
}
// 清空全部搜索/筛选条件（空状态引导按钮用）
function resetFilters() {
    _filterState.q = '';
    _filterState.filter = '';
    _filterState.cat = '';
    _filterState.plat = 0;
    _filterState.loc = '';
    _filterState.page = 1;
    var si = document.getElementById('searchInput');
    if (si) si.value = '';
    if (typeof syncSearchClear === 'function') syncSearchClear();
    syncFilterChips();
    loadPartsList();
}

function goPage(p) {
    _filterState.page = p;
    loadPartsList();
}

function changePerPage(val) {
    _filterState.per_page = parseInt(val) || 25;
    _filterState.page = 1;
    document.cookie = 'per_page_index=' + val + ';max-age=2592000;path=/';
    loadPartsList();
}

function splitSearchKeywords(q) {
    if (!q) return [];
    var parts = String(q).split(/[\s,，、|;；]+/);
    var seen = {}, out = [];
    for (var i = 0; i < parts.length; i++) {
        var w = parts[i].trim();
        if (w === '' || seen[w]) continue;
        seen[w] = 1; out.push(w);
    }
    return out;
}

function ajaxSearch(e) {
    if (e && e.preventDefault) e.preventDefault();
    try {
        var si = document.getElementById('searchInput');
        _filterState.q = si ? si.value.trim() : '';
        _filterState.page = 1;
        loadPartsList();
    } catch (err) {
        if (typeof LCSC !== 'undefined' && LCSC.toast) LCSC.toast('搜索出错：' + (err && err.message ? err.message : err), 'error');
    }
    return false;
}

// ── 更新浏览器URL（pushState，不刷新）──
function updateUrl() {
    var qs = buildFilterQueryString();
    if (qs) qs += '&';
    qs += 'per_page=' + _filterState.per_page + '&page=' + _filterState.page;
    var url = 'index.php?v=parts&' + qs;
    try { history.pushState(null, '', url); } catch(e) {}
}

// ── popstate处理：浏览器前进后退 ──
window.addEventListener('popstate', function(e) {
    var params = new URLSearchParams(window.location.search);
    _filterState.q = params.get('q') || '';
    _filterState.filter = params.get('filter') || '';
    _filterState.cat = params.get('cat') || '';
    _filterState.plat = parseInt(params.get('plat')) || 0;
    _filterState.loc = params.get('loc') || '';
    _filterState.page = parseInt(params.get('page')) || 1;
    _filterState.per_page = parseInt(params.get('per_page')) || 25;
    syncFilterChips();
    document.getElementById('searchInput').value = _filterState.q;
    if (typeof syncSearchClear === 'function') syncSearchClear();
    loadPartsList();
});

// ── 库存表格：同行组 hover 统一高亮 ──
(function(){
    var tbody = document.getElementById('desktopTbody');
    if (!tbody) return;
    tbody.addEventListener('mouseenter', function(e){
        var tr = e.target.closest('tr[data-part-id]');
        if (!tr) return;
        var pid = tr.getAttribute('data-part-id');
        tbody.querySelectorAll('tr[data-part-id="' + pid + '"]').forEach(function(r){
            r.classList.add('row-hover');
        });
    }, true);
    tbody.addEventListener('mouseleave', function(e){
        var tr = e.target.closest('tr[data-part-id]');
        if (!tr) return;
        var pid = tr.getAttribute('data-part-id');
        tbody.querySelectorAll('tr[data-part-id="' + pid + '"]').forEach(function(r){
            r.classList.remove('row-hover');
        });
    }, true);
})();

// ── 初始PHP分页事件委托（拦截.page-btn点击转AJAX）──
(function(){
    var area = document.getElementById('paginationArea');
    if (area) area.addEventListener('click', function(e) {
        var btn = e.target.closest('.page-btn');
        if (!btn || btn.classList.contains('disabled') || btn.classList.contains('active')) return;
        var href = btn.getAttribute('href');
        if (!href || href.indexOf('?') < 0) return;
        e.preventDefault();
        var params = new URLSearchParams(href.substring(href.indexOf('?') + 1));
        _filterState.page = parseInt(params.get('page')) || 1;
        loadPartsList();
    });
})();
