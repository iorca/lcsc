// modules/inventory/Assets/js/parts-drawer.js — 物料列表页 · 分类筛选


var _topCats = CFG.topCats || [];
var _subCats = CFG.subCats || [];
var _unboundSubCats = CFG.unboundSubCats || [];
var _selectedTopIds = [];
var _selectedSubIds = [];

// ── P5 树形辅助：子分类 → 根一级大类 映射（沿 parent 链上溯，最多 5 层防环）──
var _subCatRootMap = (function(){
    var map = {};
    var topIds = {};
    _topCats.forEach(function(t){ topIds[t.id] = true; });
    var byId = {};
    _subCats.forEach(function(s){ byId[s.id] = s; });
    _subCats.forEach(function(s){
        var cur = s.id, guard = 0, root = 0;
        while (guard++ < 8) {
            var node = byId[cur];
            if (!node) break;
            if (topIds[node.parent_id]) { root = node.parent_id; break; }
            if (node.parent_id <= 0) break;
            cur = node.parent_id;
        }
        map[s.id] = root;
    });
    return map;
})();
// 判断子分类是否属于指定一级大类（含任意层级后代）
function subCatInTop(subId, topId) {
    return _subCatRootMap[subId] === topId;
}
// 层级缩进（depth=2 无缩进，每深一层缩进一级）
function catIndentHtml(depth) {
    var d = Math.max(0, (parseInt(depth) || 2) - 2);
    if (d <= 0) return '';
    var html = '';
    for (var i = 0; i < d; i++) html += '<span class="cat-indent-step-blank"></span>';
    return html + '<span class="cat-indent-mark">↳</span>';
}

// ── 移动端分类筛选抽屉 ──
var _mCatMode = 'single';
var _mCatTopSelected = [];
var _mCatSubSelected = [];
var _mCatMultiSnapshotTop = [];
var _mCatMultiSnapshotSub = [];
var _mCatSearchKW = '';

function openMCatDrawer() {
    _mCatTopSelected = _selectedTopIds.slice();
    _mCatSubSelected = _selectedSubIds.slice();
    _mCatMode = 'single';
    _mCatSearchKW = '';
    var searchEl = document.getElementById('mCatSearch');
    if (searchEl) searchEl.value = '';
    document.getElementById('mCatOverlay').classList.add('open');
    document.getElementById('mCatDrawer').classList.add('open');
    renderMCatDrawer();
}

function closeMCatDrawer() {
    document.getElementById('mCatOverlay').classList.remove('open');
    document.getElementById('mCatDrawer').classList.remove('open');
}

function renderMCatDrawer() {
    renderMCatTopRow();
    renderMCatSubList();
    renderMCatModeUI();
    renderMCatClearBtn();
}

function renderMCatTopRow() {
    var container = document.getElementById('mCatTopScroll');
    if (!container) return;
    var html = '';
    _topCats.forEach(function(c) {
        var cls = _mCatTopSelected.indexOf(c.id) >= 0 ? ' active' : '';
        var visible = _mCatSearchKW === '' || c.name.toLowerCase().indexOf(_mCatSearchKW) >= 0;
        if (visible) {
            html += '<span class="m-cat-top-tag' + cls + '" data-cat-id="' + c.id + '" onclick="mCatSelectTop(' + c.id + ')">' + esc(c.name) + '<span class="m-cat-top-cnt">' + c.cnt + '</span></span>';
        }
    });
    if (CFG.noCatCount > 0) {
        var cls = _mCatTopSelected.indexOf(-1) >= 0 ? ' active' : '';
        var visible = _mCatSearchKW === '' || '未归类'.indexOf(_mCatSearchKW) >= 0;
        if (visible) {
            html += '<span class="m-cat-top-tag' + cls + '" data-cat-id="-1" onclick="mCatSelectTop(-1)">未归类<span class="m-cat-top-cnt">' + CFG.noCatCount + '</span></span>';
        }
    }
    container.innerHTML = html;
}

function renderMCatSubList() {
    var container = document.getElementById('mCatSubList');
    if (!container) return;
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (subCatInTop(sc.id, tid)) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    if (_mCatTopSelected.length === 0) {
        subs = _subCats.slice().concat(_unboundSubCats);
    }
    if (_mCatSearchKW !== '') {
        subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    }
    if (subs.length === 0) {
        container.innerHTML = '<div class="m-cat-sub-empty">' + (_mCatTopSelected.length === 0 ? '请选择一级类目查看子分类' : '该大类下无子分类') + '</div>';
        return;
    }
    var html = '';
    var isMulti = _mCatMode === 'multi';
    subs.forEach(function(c) {
        var active = _mCatSubSelected.indexOf(c.id) >= 0;
        var cls = active ? ' active' : '';
        var cbHtml = isMulti ? '<input type="checkbox" class="m-cat-sub-cb" ' + (active ? 'checked' : '') + ' onclick="event.stopPropagation(); mCatToggleSubCb(' + c.id + ', this)">' : '';
        html += '<div class="m-cat-sub-item' + cls + '" data-cat-id="' + c.id + '" onclick="mCatClickSub(' + c.id + ')">' + cbHtml + '<span class="m-cat-sub-name">' + catIndentHtml(c.depth) + esc(c.name) + '</span><span class="m-cat-sub-cnt">' + c.cnt + '</span></div>';
    });
    container.innerHTML = html;
}

function mCatSelectTop(id) {
    if (_mCatMode === 'multi') {
        var idx = _mCatTopSelected.indexOf(id);
        if (idx >= 0) _mCatTopSelected.splice(idx, 1);
        else _mCatTopSelected.push(id);
        _mCatSubSelected = [];
        renderMCatTopRow();
        renderMCatSubList();
    } else {
        _mCatTopSelected = [id];
        _mCatSubSelected = [];
        renderMCatTopRow();
        renderMCatSubList();
    }
    renderMCatClearBtn();
}

function mCatClickSub(id) {
    if (_mCatMode === 'multi') {
        var idx = _mCatSubSelected.indexOf(id);
        if (idx >= 0) _mCatSubSelected.splice(idx, 1);
        else _mCatSubSelected.push(id);
        renderMCatSubList();
    } else {
        _selectedTopIds = _mCatTopSelected.slice();
        _selectedSubIds = [id];
        closeMCatDrawer();
        applyCatFilter();
    }
    renderMCatClearBtn();
}

function mCatToggleSubCb(id, cbEl) {
    var idx = _mCatSubSelected.indexOf(id);
    if (cbEl.checked && idx < 0) _mCatSubSelected.push(id);
    else if (!cbEl.checked && idx >= 0) _mCatSubSelected.splice(idx, 1);
    renderMCatClearBtn();
}

function toggleMCatMode() {
    if (_mCatMode === 'single') {
        _mCatMode = 'multi';
        _mCatMultiSnapshotTop = _mCatTopSelected.slice();
        _mCatMultiSnapshotSub = _mCatSubSelected.slice();
    } else {
        _mCatMode = 'single';
    }
    renderMCatDrawer();
}

function renderMCatModeUI() {
    var btn = document.getElementById('mCatModeBtn');
    var footer = document.getElementById('mCatFooter');
    if (_mCatMode === 'multi') {
        if (btn) { btn.textContent = '退出多选'; btn.classList.add('active'); }
        if (footer) footer.classList.add('is-on');
    } else {
        if (btn) { btn.textContent = '批量多选'; btn.classList.remove('active'); }
        if (footer) footer.classList.remove('is-on');
    }
}

function mCatClear() {
    _mCatTopSelected = [];
    _mCatSubSelected = [];
    renderMCatDrawer();
    _selectedTopIds = [];
    _selectedSubIds = [];
    applyCatFilter();
}

function renderMCatClearBtn() {
    var btn = document.getElementById('mCatClearBtn');
    if (btn) btn.disabled = (_mCatTopSelected.length === 0 && _mCatSubSelected.length === 0);
}

function mCatSearchFilter(kw) {
    _mCatSearchKW = kw.trim().toLowerCase();
    renderMCatTopRow();
    renderMCatSubList();
}

function mCatSelectAll() {
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) { if (subCatInTop(sc.id, tid)) subs.push(sc); });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    if (_mCatTopSelected.length === 0) subs = _subCats.slice().concat(_unboundSubCats);
    if (_mCatSearchKW !== '') subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    _mCatSubSelected = subs.map(function(s) { return s.id; });
    renderMCatSubList();
    renderMCatClearBtn();
}

function mCatInvert() {
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) { if (subCatInTop(sc.id, tid)) subs.push(sc); });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    if (_mCatTopSelected.length === 0) subs = _subCats.slice().concat(_unboundSubCats);
    if (_mCatSearchKW !== '') subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    var allIds = subs.map(function(s) { return s.id; });
    _mCatSubSelected = allIds.filter(function(id) { return _mCatSubSelected.indexOf(id) < 0; });
    renderMCatSubList();
    renderMCatClearBtn();
}

function cancelMCatMulti() {
    _mCatTopSelected = _mCatMultiSnapshotTop.slice();
    _mCatSubSelected = _mCatMultiSnapshotSub.slice();
    _mCatMode = 'single';
    closeMCatDrawer();
}

function applyMCatMulti() {
    _selectedTopIds = _mCatTopSelected.slice();
    _selectedSubIds = _mCatSubSelected.slice();
    _mCatMode = 'single';
    closeMCatDrawer();
    applyCatFilter();
}

// ── PC端一级/二级分类筛选逻辑 ──

// ── 初始化分类选中状态（从URL参数cat还原）──
(function(){
    var catParam = CFG.cat || '';
    if (!catParam || catParam === '0') return;
    var ids = catParam.split(',').map(function(x){ return parseInt(x); }).filter(function(x){ return !isNaN(x); });
    if (ids.length === 0) return;
    ids.forEach(function(id) {
        var isTop = _topCats.some(function(c){ return c.id === id; }) || id === -1;
        var isSub = _subCats.some(function(c){ return c.id === id; });
        if (isTop && !isSub) _selectedTopIds.push(id);
        else if (isSub) _selectedSubIds.push(id);
        else _selectedTopIds.push(id);
    });
    if (_selectedSubIds.length > 0 && _selectedTopIds.length === 0) {
        _selectedSubIds.forEach(function(sid) {
            var sc = _subCats.find(function(c){ return c.id === sid; });
            if (!sc) return;
            // P5 树形：沿根映射取所属一级大类（parent 可能是中间层级分类）
            var rootId = _subCatRootMap[sid] || 0;
            if (rootId > 0 && _selectedTopIds.indexOf(rootId) < 0) _selectedTopIds.push(rootId);
            else if (rootId <= 0 && sc.parent_id > 0 && _selectedTopIds.indexOf(sc.parent_id) < 0 && _topCats.some(function(t){ return t.id === sc.parent_id; })) _selectedTopIds.push(sc.parent_id);
        });
    }
    renderTopCatPills();
    if (_selectedTopIds.length > 0) renderSubCatRow();
})();

function selectTopCat(id) {
    _selectedTopIds = [id];
    _selectedSubIds = [];
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
    closeTopExpandPanel();
}

function removeTopCat(id) {
    _selectedTopIds = _selectedTopIds.filter(function(x){ return x !== id; });
    _selectedSubIds = [];
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

function clearTopCat() {
    _selectedTopIds = [];
    _selectedSubIds = [];
    closeTopExpandPanel();
    closeSubExpandPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

function toggleTopExpand() {
    var panel = document.getElementById('topExpandPanel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
        closeTopExpandPanel();
    } else {
        openTopExpandPanel();
    }
}
function openTopExpandPanel() {
    var panel = document.getElementById('topExpandPanel');
    var list = document.getElementById('topExpandList');
    if (!panel || !list) return;
    var html = '';
    _topCats.forEach(function(c) {
        var cls = _selectedTopIds.indexOf(c.id) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="' + c.id + '" onclick="expandSelectTopCat(' + c.id + ')"><span class="cat-expand-item-name" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-expand-item-cnt">' + c.cnt + '</span></div>';
    });
    if (CFG.noCatCount > 0) {
        var cls = _selectedTopIds.indexOf(-1) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="-1" onclick="expandSelectTopCat(-1)"><span class="cat-expand-item-name" title="未归类">未归类</span><span class="cat-expand-item-cnt">' + CFG.noCatCount + '</span></div>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    var btn = document.getElementById('topExpandBtn');
    if (btn) btn.textContent = '收起';
    closeTopMultiPanel();
    closeSubExpandPanel();
}
function closeTopExpandPanel() {
    var panel = document.getElementById('topExpandPanel');
    if (panel) panel.classList.remove('open');
    var btn = document.getElementById('topExpandBtn');
    if (btn) btn.textContent = '展开';
}
function expandSelectTopCat(id) {
    _selectedTopIds = [id];
    _selectedSubIds = [];
    closeTopExpandPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

function renderTopCatPills() {
    var container = document.getElementById('topCatPills');
    var clearBtn = document.getElementById('topClearBtn');
    if (!container) return;
    var html = '';
    if (_selectedTopIds.length === 0) {
        _topCats.forEach(function(c) {
            html += '<span class="cat-tag-pill" data-cat-id="' + c.id + '" onclick="selectTopCat(' + c.id + ')">' + esc(c.name) + '<span class="cat-tag-cnt">' + c.cnt + '</span></span>';
        });
        if (CFG.noCatCount > 0) {
            html += '<span class="cat-tag-pill" data-cat-id="-1" onclick="selectTopCat(-1)">未归类<span class="cat-tag-cnt">' + CFG.noCatCount + '</span></span>';
        }
        if (clearBtn) clearBtn.disabled = true;
    } else {
        _selectedTopIds.forEach(function(id) {
            var cat = _topCats.find(function(c){ return c.id === id; });
            if (id === -1) cat = { id: -1, name: '未归类' };
            if (!cat) return;
            html += '<span class="cat-tag-pill selected" data-cat-id="' + cat.id + '">' + esc(cat.name) + '<span class="cat-tag-close" onclick="event.stopPropagation();removeTopCat(' + cat.id + ')">×</span></span>';
        });
        if (clearBtn) clearBtn.disabled = false;
    }
    container.innerHTML = html;
    container.classList.remove('expanded');
}

function renderSubCatRow() {
    var row = document.getElementById('catRow2');
    var clearBtn = document.getElementById('subClearBtn');
    if (!row) return;
    if (_selectedTopIds.length === 0) {
        row.classList.add('is-hidden');
        return;
    }
    row.classList.remove('is-hidden');
    closeSubExpandPanel();
    renderSubCatPills();
}

function renderSubCatPills() {
    var container = document.getElementById('subCatPills');
    var clearBtn = document.getElementById('subClearBtn');
    if (!container) return;
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (subCatInTop(sc.id, tid)) subs.push(sc);
        });
        if (tid === -1) {
            _unboundSubCats.forEach(function(sc) { subs.push(sc); });
        }
    });
    var html = '';
    if (_selectedSubIds.length === 0) {
        subs.forEach(function(c) {
            html += '<span class="cat-tag-pill" data-cat-id="' + c.id + '" onclick="selectSubCat(' + c.id + ')">' + catIndentHtml(c.depth) + esc(c.name) + '<span class="cat-tag-cnt">' + c.cnt + '</span></span>';
        });
        if (clearBtn) clearBtn.disabled = true;
        if (subs.length === 0) {
            html = '<span class="cat-sub-empty">该大类下无子分类</span>';
        }
    } else {
        _selectedSubIds.forEach(function(id) {
            var cat = subs.find(function(c){ return c.id === id; });
            if (!cat) return;
            html += '<span class="cat-tag-pill selected" data-cat-id="' + cat.id + '">' + esc(cat.name) + '<span class="cat-tag-close" onclick="event.stopPropagation();removeSubCat(' + cat.id + ')">×</span></span>';
        });
        if (clearBtn) clearBtn.disabled = false;
    }
    container.innerHTML = html;
    container.classList.remove('expanded');
}

function selectSubCat(id) {
    _selectedSubIds = [id];
    renderSubCatPills();
    applyCatFilter();
    closeSubExpandPanel();
}

function removeSubCat(id) {
    _selectedSubIds = _selectedSubIds.filter(function(x){ return x !== id; });
    renderSubCatPills();
    applyCatFilter();
}

function clearSubCat() {
    _selectedSubIds = [];
    closeSubExpandPanel();
    renderSubCatPills();
    applyCatFilter();
}

function toggleSubExpand() {
    var panel = document.getElementById('subExpandPanel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
        closeSubExpandPanel();
    } else {
        openSubExpandPanel();
    }
}
function openSubExpandPanel() {
    var panel = document.getElementById('subExpandPanel');
    var list = document.getElementById('subExpandList');
    if (!panel || !list) return;
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (subCatInTop(sc.id, tid)) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    var html = '';
    subs.forEach(function(c) {
        var cls = _selectedSubIds.indexOf(c.id) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="' + c.id + '" onclick="expandSelectSubCat(' + c.id + ')"><span class="cat-expand-item-name" title="' + esc(c.name) + '">' + catIndentHtml(c.depth) + esc(c.name) + '</span><span class="cat-expand-item-cnt">' + c.cnt + '</span></div>';
    });
    if (subs.length === 0) {
        html = '<div class="cat-sub-empty is-block">该大类下无子分类</div>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    var btn = document.getElementById('subExpandBtn');
    if (btn) btn.textContent = '收起';
    closeSubMultiPanel();
}
function closeSubExpandPanel() {
    var panel = document.getElementById('subExpandPanel');
    if (panel) panel.classList.remove('open');
    var btn = document.getElementById('subExpandBtn');
    if (btn) btn.textContent = '展开';
}
function expandSelectSubCat(id) {
    _selectedSubIds = [id];
    closeSubExpandPanel();
    renderSubCatPills();
    applyCatFilter();
}

function applyCatFilter() {
    var ids = _selectedSubIds.length > 0 ? _selectedSubIds : _selectedTopIds;
    if (ids.length === 0) {
        _filterState.cat = '';
    } else if (ids.length === 1) {
        _filterState.cat = String(ids[0]);
    } else {
        _filterState.cat = ids.join(',');
    }
    _filterState.page = 1;
    if (_filterState.loc) {
        _filterState.loc = '';
        syncFilterChips();
    }
    loadPartsList();
}

// ── 一级大类：多选内嵌面板 ──
var _topMultiSnapshot = [];

function openTopMultiPanel() {
    var panel = document.getElementById('topMultiPanel');
    var list = document.getElementById('topMultiList');
    if (!panel || !list) return;
    closeTopExpandPanel();
    _topMultiSnapshot = _selectedTopIds.slice();
    var html = '';
    _topCats.forEach(function(c) {
        var checked = _selectedTopIds.indexOf(c.id) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="' + c.id + '" ' + checked + '><span class="cat-inline-item-text" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-inline-item-cnt">' + c.cnt + '</span></label>';
    });
    if (CFG.noCatCount > 0) {
        var chk = _selectedTopIds.indexOf(-1) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="-1" ' + chk + '><span class="cat-inline-item-text" title="未归类">未归类</span><span class="cat-inline-item-cnt">' + CFG.noCatCount + '</span></label>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    closeSubMultiPanel(true);
}
function closeTopMultiPanel() {
    var panel = document.getElementById('topMultiPanel');
    if (panel) panel.classList.remove('open');
}
function cancelTopMulti() {
    _selectedTopIds = _topMultiSnapshot.slice();
    closeTopMultiPanel();
}
function topMultiSelectAll() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = true; });
}
function topMultiInvert() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = !cb.checked; });
}
function applyTopMulti() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]:checked');
    _selectedTopIds = [];
    cbs.forEach(function(cb){ _selectedTopIds.push(parseInt(cb.value)); });
    _selectedSubIds = [];
    closeTopMultiPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

// ── 二级分类：多选内嵌面板 ──
var _subMultiSnapshot = [];

function openSubMultiPanel() {
    var panel = document.getElementById('subMultiPanel');
    var list = document.getElementById('subMultiList');
    if (!panel || !list) return;
    closeSubExpandPanel();
    _subMultiSnapshot = _selectedSubIds.slice();
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (subCatInTop(sc.id, tid)) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    var html = '';
    subs.forEach(function(c) {
        var checked = _selectedSubIds.indexOf(c.id) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="' + c.id + '" ' + checked + '><span class="cat-inline-item-text" title="' + esc(c.name) + '">' + catIndentHtml(c.depth) + esc(c.name) + '</span><span class="cat-inline-item-cnt">' + c.cnt + '</span></label>';
    });
    list.innerHTML = html;
    panel.classList.add('open');
    closeTopMultiPanel();
}
function closeSubMultiPanel(silent) {
    var panel = document.getElementById('subMultiPanel');
    if (panel) panel.classList.remove('open');
}
function cancelSubMulti() {
    _selectedSubIds = _subMultiSnapshot.slice();
    closeSubMultiPanel();
}
function subMultiSelectAll() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = true; });
}
function subMultiInvert() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = !cb.checked; });
}
function applySubMulti() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]:checked');
    _selectedSubIds = [];
    cbs.forEach(function(cb){ _selectedSubIds.push(parseInt(cb.value)); });
    closeSubMultiPanel();
    renderSubCatPills();
    applyCatFilter();
}
