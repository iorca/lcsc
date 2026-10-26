var CatCfg = window.CategoriesPageConfig || {};
var _catState = {
    page: parseInt(CatCfg.page, 10) || 1,
    per_page: parseInt(CatCfg.perPage, 10) || 25,
    q: CatCfg.q || '',
    sort: CatCfg.sort || 'cnt_desc',
    filter: CatCfg.filter || 'all'
};
// 已勾选分类：{ id: 分类名 }；分页/搜索后清理掉不在当前页的项
var _catSelected = {};
var _catData = { items: [], top_cats: [], all_cats: [], total_all: 0, unbound_count: 0 };

function catEsc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function catSet(id, v) {
    var el = document.getElementById(id);
    if (el) el.value = v;
}
function catRow(catId) {
    return document.querySelector('#catTbody tr[data-cat-id="' + catId + '"]');
}

function catLoad() {
    var params = 'action=category.list_paged&page=' + _catState.page
        + '&per_page=' + _catState.per_page
        + '&sort=' + encodeURIComponent(_catState.sort)
        + '&filter=' + encodeURIComponent(_catState.filter);
    if (_catState.q) params += '&q=' + encodeURIComponent(_catState.q);
    LCSC.get('app/api/router.php?' + params, function (data) {
        _catData = data;
        // 先按当前页重算勾选，再渲染（保证表头全选态与行内 checked 一致）
        catPruneSelection(data.items);
        catRenderTable(data.items);
        catRenderPagination(data);
        catRenderStats(data);
        catRenderParentOptions();
        catRenderTopCats();
        catUpdateBatchBar();
        catUpdateUrl();
    }, function (msg) {
        LCSC.toast('加载失败：' + msg, 'error');
    });
}

function catIndentHtml(depth) {
    var level = Math.max(0, Math.min(4, (parseInt(depth, 10) || 2) - 2));
    var html = '<span class="cat-indent" aria-hidden="true">';
    for (var i = 0; i < level; i++) html += '<i class="cat-indent-step"></i>';
    return html + '</span>';
}

function catRenderTable(items) {
    var tbody = document.getElementById('catTbody');
    if (!tbody) return;
    if (!items || items.length === 0) {
        var emptyTitle = '还没有分类', emptyDesc = '导入订单时会自动生成细分类，也可手动新增';
        if (_catState.q) {
            emptyTitle = '没有匹配「' + catEsc(_catState.q) + '」的分类';
            emptyDesc = '试试更短的关键词，或按上级分类名搜索';
        } else if (_catState.filter === 'top') {
            emptyTitle = '还没有一级大类';
            emptyDesc = '在「大类管理」中添加大类后，细分类就能按类归类';
        } else if (_catState.filter === 'unbound') {
            emptyTitle = '全部细分类都已归类';
            emptyDesc = '没有处于「未归类」状态的分类';
        }
        tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="icon">🏷️</div>'
            + '<div class="es-title">' + emptyTitle + '</div><div class="es-desc">' + emptyDesc + '</div>'
            + '</div></td></tr>';
        return;
    }
    var html = '';
    items.forEach(function (c) {
        var cid = parseInt(c.id, 10);
        var cnt = parseInt(c.cnt, 10) || 0;
        var isTop = parseInt(c.is_top, 10) === 1;
        var depth = parseInt(c.depth, 10) || (isTop ? 1 : 2);
        var childCnt = parseInt(c.child_cnt, 10) || 0;
        var path = c.parent_path || '';
        var locs = c.locations || [];

        var locHtml = '<span class="cat-muted">-</span>';
        if (locs.length) {
            locHtml = '<span class="cat-loc" title="' + catEsc(locs[0].location) + '">' + catEsc(locs[0].location) + '</span>';
            if (locs.length > 1) {
                locHtml += '<span class="cat-loc-more" title="共 ' + locs.length + ' 个库位">+' + (locs.length - 1) + '</span>';
            }
        }

        var thr = (c.low_stock_threshold !== null && c.low_stock_threshold !== undefined && c.low_stock_threshold !== '')
            ? '<span class="badge badge-blue">' + catEsc(c.low_stock_threshold) + '</span>'
            : '<span class="cat-muted">继承全局</span>';

        html += '<tr data-cat-id="' + cid + '"'
            + ' data-cat-name="' + catEsc(c.name) + '"'
            + ' data-cat-depth="' + depth + '"'
            + ' data-cat-parent="' + (parseInt(c.parent_id, 10) || 0) + '"'
            + ' data-cat-path="' + catEsc(path) + '">';
        html += '<td class="cb-col"><input type="checkbox" class="cat-cb" value="' + cid + '"'
            + ' aria-label="选择分类 ' + catEsc(c.name) + '"' + (_catSelected[cid] ? ' checked' : '') + '></td>';
        html += '<td><div class="cat-name-cell">' + catIndentHtml(depth)
            + '<span class="cat-name">' + catEsc(c.name) + '</span>'
            + (isTop ? '<span class="cat-top-tag">一级大类</span>' : '')
            + (childCnt ? '<span class="cat-sub-count" title="含 ' + childCnt + ' 个子分类">' + childCnt + ' 子类</span>' : '')
            + '</div></td>';
        html += '<td>' + (isTop
            ? '<span class="cat-parent-none">—</span>'
            : (path
                ? '<span class="cat-parent" title="' + catEsc(path) + '">' + catEsc(path) + '</span>'
                : '<span class="cat-parent-none">未归类</span>')) + '</td>';
        html += '<td class="num">' + cnt + '</td>';
        html += '<td>' + thr + '</td>';
        html += '<td>' + locHtml + '</td>';
        html += '<td class="cat-actions-col">';
        if (CatCfg.canManage) {
            html += '<div class="actions">';
            html += '<button class="btn btn-ghost btn-sm" onclick="openCatRenameModal(' + cid + ')">重命名</button>';
            if (!isTop) {
                html += '<button class="btn btn-ghost btn-sm" onclick="openCatMoveModal(' + cid + ')">移动</button>';
            }
            if (isTop) {
                html += '<button class="btn btn-danger btn-sm" onclick="topCatDelete(' + cid + ',' + catEsc(JSON.stringify(String(c.name))) + ')">删除</button>';
            } else if (childCnt === 0 && cnt === 0) {
                html += '<button class="btn btn-danger btn-sm" onclick="catDelete(' + cid + ')">删除</button>';
            } else {
                html += '<button class="btn btn-ghost btn-sm is-disabled" disabled title="'
                    + (childCnt ? '含 ' + childCnt + ' 个子分类，请先移出或删除子分类' : '仍关联 ' + cnt + ' 个元件，请先移出物料')
                    + '">删除</button>';
            }
            html += '</div>';
        } else {
            html += '<span class="cat-muted">仅查看</span>';
        }
        html += '</td></tr>';
    });
    tbody.innerHTML = html;
    catSyncCheckAll();
}

function catRenderPagination(data) {
    var area = document.getElementById('catPaginationArea');
    if (!area) return;
    var total = parseInt(data.total, 10) || 0;
    var page = parseInt(data.page, 10) || 1;
    var perPage = parseInt(data.per_page, 10) || 25;
    var totalPage = parseInt(data.total_page, 10) || 1;
    if (total === 0) { area.style.display = 'none'; return; }
    area.style.display = '';

    function link(p, text, cls) {
        return '<a href="?per_page=' + perPage + '&page=' + p + '" class="page-btn ' + (cls || '')
            + '" onclick="catGoPage(' + p + ');return false;">' + text + '</a>';
    }
    var s = Math.max(1, page - 2), e = Math.min(totalPage, page + 2);
    var html = '<span class="page-jump">第 <input type="number" min="1" max="' + totalPage + '" value="' + page
        + '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();catGoPage(parseInt(this.value)||1);}"> 页</span>';
    html += link(Math.max(1, page - 1), '‹', page <= 1 ? 'disabled' : '');
    if (s > 1) html += link(1, '1');
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += link(i, String(i), i === page ? 'active' : '');
    if (e < totalPage - 1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += link(totalPage, String(totalPage));
    html += link(Math.min(totalPage, page + 1), '›', page >= totalPage ? 'disabled' : '');
    html += '<select onchange="catChangePerPage(this.value)" class="per-page-select">';
    [10, 15, 20, 25, 30, 35, 40, 45, 50].forEach(function (pp) {
        html += '<option value="' + pp + '"' + (perPage === pp ? ' selected' : '') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

function catRenderStats(data) {
    function set(id, v) {
        var el = document.getElementById(id);
        if (el) el.textContent = String(v);
    }
    var totalAll = parseInt(data.total_all, 10) || 0;
    var shown = parseInt(data.total, 10) || 0;
    set('catStatTotal', totalAll);
    set('catStatTop', parseInt(data.top_count, 10) || (data.top_cats || []).length);
    set('catStatUnbound', parseInt(data.unbound_count, 10) || 0);
    set('catCountInfo', (_catState.q || _catState.filter !== 'all')
        ? '匹配 ' + shown + ' / ' + totalAll + ' 个分类'
        : '共 ' + totalAll + ' 个分类');
    catSyncFilterCards();
}

/* 同步顶部统计卡的高亮态（筛选结果由服务端返回，这里只切 UI 状态） */
function catSyncFilterCards() {
    document.querySelectorAll('#catStatGrid .stat-card[data-cat-filter]').forEach(function (card) {
        card.classList.toggle('filter-active', card.getAttribute('data-cat-filter') === _catState.filter);
    });
}

/* 切换顶部统计卡筛选 */
function catOnFilterChange(filter) {
    var allowed = ['all', 'top', 'unbound'];
    if (allowed.indexOf(filter) === -1) filter = 'all';
    if (_catState.filter === filter) return;
    _catState.filter = filter;
    _catState.page = 1;
    clearCatSelection();
    catLoad();
}

/* 重建分类选择控件 */
function catRenderParentOptions() {
    var cats = _catData.all_cats || [];
    var byTop = {}, unbound = [];
    cats.forEach(function (c) {
        var pid = parseInt(c.parent_id, 10) || 0;
        if (pid > 0) { (byTop[pid] = byTop[pid] || []).push(c); }
        else unbound.push(c);
    });
    function option(id, name, depth) {
        return '<option value="' + parseInt(id, 10) + '" data-path="' + catEsc(name) + '"'
            + ' data-depth="' + (parseInt(depth, 10) || 2) + '">'
            + catIndentHtml(depth) + catEsc(name) + '</option>';
    }

    var head = '<option value="0" data-path="" data-depth="0">— 无（顶级分类）—</option>';
    var groups = '';
    (_catData.top_cats || []).forEach(function (tc) {
        var tid = parseInt(tc.id, 10);
        var tname = String(tc.name || '');
        var inner = option(tid, tname, 1);
        (byTop[tid] || []).forEach(function (c) {
            inner += option(c.id, c.name, c.depth);
        });
        groups += '<optgroup label="' + catEsc(tname) + '">' + inner + '</optgroup>';
    });
    if (unbound.length) {
        var inner = '';
        unbound.forEach(function (c) { inner += option(c.id, c.name, c.depth); });
        groups += '<optgroup label="未归类">' + inner + '</optgroup>';
    }

    ['catCreateParent', 'catMoveParent'].forEach(function (id) {
        var sel = document.getElementById(id);
        if (sel) sel.innerHTML = head + groups;
    });

    var bindSel = document.getElementById('catBindParent');
    if (bindSel) {
        var bindHead = '<option value="0" data-path="" data-depth="0">— 无（解除绑定，回到未归类）—</option>';
        var bindOpts = '';
        (_catData.top_cats || []).forEach(function (tc) {
            bindOpts += '<option value="' + parseInt(tc.id, 10) + '" data-path="' + catEsc(tc.name) + '" data-depth="1">'
                + catEsc(tc.name) + '</option>';
        });
        bindSel.innerHTML = bindHead + bindOpts;
    }

    // 合并目标是「输入联想」而非下拉：重建 datalist 候选
    catRenderMergeTargetList();
}

/* 重建「合并分类」目标联想候选 */
function catRenderMergeTargetList() {
    var list = document.getElementById('catMergeTargetList');
    if (!list) return;

    // 本次勾选的来源分类：自身完整路径 = 上级路径 + 名称
    var blocked = [];
    Object.keys(_catSelected).forEach(function (id) {
        var tr = catRow(parseInt(id, 10));
        if (!tr) return;
        var p = tr.getAttribute('data-cat-path') || '';
        var n = tr.getAttribute('data-cat-name') || '';
        blocked.push(p ? (p + ' / ' + n) : n);
    });
    function isBlocked(path) {
        for (var i = 0; i < blocked.length; i++) {
            if (path === blocked[i] || path.indexOf(blocked[i] + ' / ') === 0) return true;
        }
        return false;
    }

    var html = '';
    (_catData.top_cats || []).forEach(function (tc) {
        var name = String(tc.name || '');
        if (!isBlocked(name)) {
            html += '<option value="' + catEsc(name) + '" label="' + catEsc(name) + '"></option>';
        }
    });
    (_catData.all_cats || []).forEach(function (c) {
        var name = String(c.name || '');
        var path = c.parent_path ? (c.parent_path + ' / ' + name) : name;
        if (!isBlocked(path)) {
            html += '<option value="' + catEsc(name) + '" label="' + catEsc(path) + '"></option>';
        }
    });
    list.innerHTML = html;
}

/* 合并目标名称 → id（分类名唯一，隐藏域 target_id 由此写入） */
function catResolveMergeTarget() {
    var input = document.getElementById('catMergeTargetInput');
    var hidden = document.getElementById('catMergeTarget');
    if (!input || !hidden) return 0;
    var name = String(input.value || '').trim();
    if (name === '') { hidden.value = ''; return 0; }

    var id = 0;
    (_catData.top_cats || []).some(function (tc) {
        if (String(tc.name || '') === name) { id = parseInt(tc.id, 10); return true; }
        return false;
    });
    if (!id) {
        (_catData.all_cats || []).some(function (c) {
            if (String(c.name || '') === name) { id = parseInt(c.id, 10); return true; }
            return false;
        });
    }
    hidden.value = id > 0 ? String(id) : '';
    return id;
}

/* 一级大类抽屉内的大类列表（写操作后局部刷新，抽屉保持开启） */
function catRenderTopCats() {
    var listEl = document.getElementById('topCatList');
    if (!listEl) return;
    var topCats = _catData.top_cats || [];
    var unbound = parseInt(_catData.unbound_count, 10) || 0;

    var childCount = {};
    (_catData.all_cats || []).forEach(function (c) {
        var pid = parseInt(c.parent_id, 10) || 0;
        if (pid > 0) childCount[pid] = (childCount[pid] || 0) + 1;
    });

    var html = '';
    if (unbound > 0) {
        html += '<div class="topcat-item topcat-default">'
            + '<span class="topcat-item-name">未归类</span>'
            + '<span class="topcat-item-count">' + unbound + ' 个分类</span>'
            + '<span class="topcat-item-tag">系统默认</span></div>';
    }
    if (!topCats.length && unbound === 0) {
        listEl.innerHTML = '<div class="empty-state"><div class="icon">📁</div>'
            + '<div class="es-title">还没有一级大类</div>'
            + '<div class="es-desc">添加大类后，导入生成的细分类就能按类归类</div></div>';
        return;
    }
    topCats.forEach(function (tc) {
        var tid = parseInt(tc.id, 10);
        var name = String(tc.name || '');
        var arg = catEsc(JSON.stringify(name));
        html += '<div class="topcat-item">'
            + '<span class="topcat-item-name">' + catEsc(name) + '</span>'
            + '<span class="topcat-item-count">' + (childCount[tid] || 0) + ' 个分类</span>'
            + '<button class="btn btn-ghost btn-xs" onclick="openTopCatRenameModal(' + tid + ',' + arg + ')">重命名</button>'
            + '<button class="btn btn-danger btn-xs" onclick="topCatDelete(' + tid + ',' + arg + ')">删除</button>'
            + '</div>';
    });
    listEl.innerHTML = html;
}

function catSelectedIds() {
    return Object.keys(_catSelected).map(function (id) { return parseInt(id, 10); });
}

function catUpdateBatchBar() {
    var bar = document.getElementById('catBatchBar');
    if (!bar) return;
    var n = catSelectedIds().length;
    var el = document.getElementById('catBatchCount');
    if (el) el.textContent = String(n);
    bar.classList.toggle('show', n > 0);
}

function catAllPageChecked() {
    var boxes = document.querySelectorAll('#catTbody .cat-cb');
    if (!boxes.length) return false;
    for (var i = 0; i < boxes.length; i++) {
        if (!boxes[i].checked) return false;
    }
    return true;
}

function catSyncCheckAll() {
    var cb = document.getElementById('catCheckAll');
    if (cb) cb.checked = catAllPageChecked();
}

function catOnRowCheckChange() {
    document.querySelectorAll('#catTbody .cat-cb').forEach(function (box) {
        var id = parseInt(box.value, 10);
        if (box.checked) {
            var tr = box.closest('tr');
            _catSelected[id] = (tr && tr.getAttribute('data-cat-name')) || ('分类 #' + id);
        } else {
            delete _catSelected[id];
        }
    });
    catSyncCheckAll();
    catUpdateBatchBar();
}

function catToggleAll(checked) {
    document.querySelectorAll('#catTbody .cat-cb').forEach(function (box) { box.checked = checked; });
    catOnRowCheckChange();
}

function catPruneSelection(items) {
    var visible = {};
    (items || []).forEach(function (c) { visible[parseInt(c.id, 10)] = true; });
    Object.keys(_catSelected).forEach(function (id) {
        if (!visible[id]) delete _catSelected[id];
    });
}

function clearCatSelection() {
    _catSelected = {};
    document.querySelectorAll('#catTbody .cat-cb').forEach(function (box) { box.checked = false; });
    var cb = document.getElementById('catCheckAll');
    if (cb) cb.checked = false;
    catUpdateBatchBar();
}

/* 填充批量操作的分类 id 隐藏域 */
function catFillIds(boxId, fieldName) {
    var box = document.getElementById(boxId);
    if (!box) return;
    var ids = catSelectedIds();
    box.innerHTML = '<div class="cat-batch-summary">已选 <b>' + ids.length + '</b> 个分类：'
        + '<span class="cat-batch-names">' + catEsc(ids.map(function (id) { return _catSelected[id]; }).join('、')) + '</span></div>'
        + ids.map(function (id) {
            return '<input type="hidden" name="' + fieldName + '" value="' + id + '">';
        }).join('');
}

function catRequireSelection() {
    if (catSelectedIds().length) return true;
    LCSC.toast('请先勾选要操作的分类', 'warning');
    return false;
}

function openCatOverlay(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}
function closeCatOverlay(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

function openCatCreateModal() {
    if (!CatCfg.canManage) return;
    catRenderParentOptions();
    var form = document.getElementById('catCreateForm');
    if (form) form.reset();
    catSet('catCreateParent', '0');
    catSyncClearStates();
    openCatOverlay('catCreateOverlay');
    setTimeout(function () { document.getElementById('catCreateName').focus(); }, 0);
}

function openCatRenameModal(catId) {
    if (!CatCfg.canManage) return;
    var tr = catRow(catId);
    if (!tr) return;
    catSet('catRenameId', catId);
    catSet('catRenameInput', tr.getAttribute('data-cat-name') || '');
    openCatOverlay('catRenameOverlay');
    setTimeout(function () { document.getElementById('catRenameInput').focus(); }, 0);
}

/* 移动分类：单条改上级 */
function openCatMoveModal(catId) {
    if (!CatCfg.canManage) return;
    var tr = catRow(catId);
    if (!tr) return;
    catRenderParentOptions();
    catSet('catMoveId', catId);

    var selfPath = tr.getAttribute('data-cat-path') || '';
    var selfName = tr.getAttribute('data-cat-name') || '';
    var selfFull = selfPath ? (selfPath + ' / ' + selfName) : selfName;
    var currentParent = parseInt(tr.getAttribute('data-cat-parent'), 10) || 0;

    var sel = document.getElementById('catMoveParent');
    if (sel) {
        Array.prototype.forEach.call(sel.options, function (opt) {
            var v = parseInt(opt.value, 10);
            if (!v) return;   // 「无（顶级分类）」始终可选
            var p = opt.getAttribute('data-path') || '';
            if (v === catId || p === selfFull || p.indexOf(selfFull + ' / ') === 0) {
                opt.disabled = true;
            }
        });
        sel.value = String(currentParent);
        if (sel.selectedIndex < 0) sel.selectedIndex = 0;
    }
    var hint = document.getElementById('catMoveHint');
    if (hint) {
        hint.textContent = '「' + selfName + '」及其全部子分类将一起移动；为避免形成环，自身与其下级不出现在选项中。';
    }
    openCatOverlay('catMoveOverlay');
}

function openCatBindModal() {
    if (!catRequireSelection()) return;
    catRenderParentOptions();
    catFillIds('catBindIds', 'cat_ids[]');
    openCatOverlay('catBindOverlay');
}

function openCatMergeModal() {
    if (!catRequireSelection()) return;
    catRenderParentOptions();
    catFillIds('catMergeIds', 'source_ids[]');
    catSet('catMergeTarget', '');
    catSet('catMergeTargetInput', '');
    catSyncClearStates();
    openCatOverlay('catMergeOverlay');
    setTimeout(function () {
        var input = document.getElementById('catMergeTargetInput');
        if (input) input.focus();
    }, 0);
}

function openCatThresholdModal() {
    if (!catRequireSelection()) return;
    catFillIds('catThrIds', 'cat_ids[]');
    openCatOverlay('catThrOverlay');
}

function openCatLocationModal() {
    if (!catRequireSelection()) return;
    catFillIds('catLocIds', 'cat_ids[]');
    if (typeof LocText !== 'undefined') LocText.ensureList();
    openCatOverlay('catLocOverlay');
    setTimeout(function () { document.getElementById('catLocValue').focus(); }, 0);
}

// ── 一级大类弹窗 ──
function openTopCatModal() {
    if (!CatCfg.canManage) return;
    openCatOverlay('topCatOverlay');
    setTimeout(function () { var n = document.querySelector('#topCatForm input[name="name"]'); if (n) n.focus(); }, 0);
}

function openTopCatRenameModal(id, name) {
    catSet('topCatRenameId', id);
    catSet('topCatRenameInput', name || '');
    openCatOverlay('topCatRenameOverlay');
    setTimeout(function () { document.getElementById('topCatRenameInput').focus(); }, 0);
}

function topCatDelete(id, name) {
    AdminUIConfirm({
        title: '删除一级大类',
        message: '确认删除大类「' + name + '」？\n其下细分类不会被删除，仅解除绑定回到未归类。',
        okText: '确认删除',
        onOk: function () {
            LCSC.post('app/api/router.php', { action: 'category.topcat_delete', id: id }, function (data, msg) {
                LCSC.toast(msg || '大类已删除', 'success');
                catLoad();
            });
        }
    });
}

function catDelete(catId) {
    var tr = catRow(catId);
    var name = tr ? tr.getAttribute('data-cat-name') : ('分类 #' + catId);
    AdminUIConfirm({
        title: '删除分类',
        message: '确认删除分类「' + name + '」？',
        okText: '确认删除',
        onOk: function () {
            LCSC.post('app/api/router.php', { action: 'category.delete', id: catId }, function (data, msg) {
                LCSC.toast(msg || '分类已删除', 'success');
                delete _catSelected[catId];
                catLoad();
            });
        }
    });
}

var _catSearchTimer = null;
function catOnSearchInput(val) {
    _catState.q = val;
    _catState.page = 1;
    clearTimeout(_catSearchTimer);
    _catSearchTimer = setTimeout(catLoad, 300);
}

function catOnSortChange(val) {
    _catState.sort = val;
    _catState.page = 1;
    catLoad();
}

function catGoPage(p) {
    _catState.page = parseInt(p, 10) || 1;
    catLoad();
}

function catChangePerPage(val) {
    _catState.per_page = parseInt(val, 10) || 25;
    _catState.page = 1;
    document.cookie = 'per_page_categories=' + _catState.per_page + ';max-age=2592000;path=/';
    catLoad();
}

function catUpdateUrl() {
    var qs = '?v=categories&per_page=' + _catState.per_page + '&page=' + _catState.page
        + '&sort=' + encodeURIComponent(_catState.sort);
    if (_catState.filter !== 'all') qs += '&filter=' + encodeURIComponent(_catState.filter);
    if (_catState.q) qs += '&q=' + encodeURIComponent(_catState.q);
    history.replaceState(null, '', qs);
}


function catSyncClearStates() {
    document.querySelectorAll('.cat-clear-wrap').forEach(function (wrap) {
        var input = wrap.querySelector('input');
        if (input) wrap.classList.toggle('has-value', String(input.value).trim() !== '');
    });
    var search = document.getElementById('catSearch');
    var box = document.getElementById('catSearchBox');
    if (search && box) box.classList.toggle('has-value', String(search.value).trim() !== '');
}

/* 绑定清除按钮：点击清空 → 广播 input（搜索框复用已有的防抖加载） */
function catInitClearButtons() {
    document.querySelectorAll('.cat-clear-wrap').forEach(function (wrap) {
        var input = wrap.querySelector('input');
        var btn = wrap.querySelector('.cat-clear-btn');
        if (!input || !btn) return;
        input.addEventListener('input', catSyncClearStates);
        btn.addEventListener('click', function () {
            input.value = '';
            catSyncClearStates();
            input.focus();
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    var search = document.getElementById('catSearch');
    var searchClear = document.getElementById('catSearchClear');
    if (search && searchClear) {
        searchClear.addEventListener('click', function () {
            search.value = '';
            catSyncClearStates();
            search.focus();
            search.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }
}

/* 绑定合并目标的联想输入与提交前校验（隐藏域 target_id 由名称反查写入） */
function catInitMergeTarget() {
    var input = document.getElementById('catMergeTargetInput');
    if (input) {
        input.addEventListener('input', catResolveMergeTarget);
        input.addEventListener('change', catResolveMergeTarget);
    }

    var form = document.getElementById('catMergeForm');
    if (form) {
        // 捕获阶段先解析；未匹配到分类时阻止后续 AJAX 提交
        form.addEventListener('submit', function (e) {
            if (catResolveMergeTarget() > 0) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            LCSC.toast('请从联想列表中选择合并目标分类', 'warning');
        }, true);
    }
}

function catBindWriteForm(formId, overlayId) {
    var form = document.getElementById(formId);
    if (!form || form.getAttribute('data-ajax-bound')) return;
    form.setAttribute('data-ajax-bound', '1');
    LCSC.interceptForm(form, function (data, msg) {
        LCSC.toast(msg || '操作成功', 'success');
        if (overlayId) closeCatOverlay(overlayId);
        clearCatSelection();
        catLoad();
    }, function (msg) {
        LCSC.toast(msg || '操作失败', 'error');
    });
}

function catInit() {
    var search = document.getElementById('catSearch');
    if (search) search.addEventListener('input', function () {
        catSyncClearStates();
        catOnSearchInput(this.value.trim());
    });

    var sortSel = document.getElementById('catSort');
    if (sortSel) sortSel.addEventListener('change', function () { catOnSortChange(this.value); });

    // 顶部统计卡 → 列表筛选（点击 / 键盘 Enter、Space）
    document.querySelectorAll('#catStatGrid .stat-card[data-cat-filter]').forEach(function (card) {
        var apply = function () { catOnFilterChange(card.getAttribute('data-cat-filter')); };
        card.addEventListener('click', apply);
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); apply(); }
        });
    });

    var checkAll = document.getElementById('catCheckAll');
    if (checkAll) checkAll.addEventListener('change', function () { catToggleAll(this.checked); });

    // 行内勾选用事件委托，重渲染后无需重新绑定
    var tbody = document.getElementById('catTbody');
    if (tbody) {
        tbody.addEventListener('change', function (e) {
            if (e.target && e.target.classList.contains('cat-cb')) catOnRowCheckChange();
        });
    }

    catBindWriteForm('catCreateForm', 'catCreateOverlay');
    catBindWriteForm('catRenameForm', 'catRenameOverlay');
    catBindWriteForm('catMoveForm', 'catMoveOverlay');
    catBindWriteForm('catBindForm', 'catBindOverlay');
    catBindWriteForm('catMergeForm', 'catMergeOverlay');
    catBindWriteForm('catThrForm', 'catThrOverlay');
    catBindWriteForm('catLocForm', 'catLocOverlay');
    catBindWriteForm('topCatForm', null);
    catBindWriteForm('topCatRenameForm', 'topCatRenameOverlay');

    // 浏览器前进/后退同步列表状态
    window.addEventListener('popstate', function () {
        var p = new URLSearchParams(window.location.search);
        _catState.page = parseInt(p.get('page'), 10) || 1;
        _catState.per_page = parseInt(p.get('per_page'), 10) || 25;
        _catState.sort = p.get('sort') || 'cnt_desc';
        _catState.filter = p.get('filter') || 'all';
        _catState.q = p.get('q') || '';
        if (search) search.value = _catState.q;
        if (sortSel) sortSel.value = _catState.sort;
        catSyncClearStates();
        catSyncFilterCards();
        catLoad();
    });

    // ESC 关闭最上层弹层
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelectorAll('.overlay.open, .drawer.open');
        if (open.length) open[open.length - 1].classList.remove('open');
    });

    catInitClearButtons();
    catInitMergeTarget();
    catSyncClearStates();
    catSyncFilterCards();
    catUpdateBatchBar();
}

document.addEventListener('DOMContentLoaded', catInit);
