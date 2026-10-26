// modules/inventory/Assets/js/parts-filter.js — 立创式多维分类筛选条
//
// 版式对齐立创商城：三个**并列**维度各占一行（品牌 / 商品类型 / 封装格式），
// 每行把该维度的值大号铺开，可点选多选；同一维度内多选为「或」，
// 跨维度为「且」。
//
// 状态放在 _filterState.cats，格式 '12,15|3|8'：
//   组间用 | 分隔（= AND），组内用 , 分隔（= OR），组序 = 维度行序。

var LCSC_DIM_LABELS = [];   // 维度行序（由 PHP 注入）

// ── 初始化：从 URL 恢复选中态并高亮 ───────────────────────────
function lcscFilterInit(dimLabels, initialCats) {
    LCSC_DIM_LABELS = dimLabels || [];
    if (initialCats) _filterState.cats = initialCats;
    lcscFilterPaint();
}

// 解析 '12,15|3' → [[12,15],[3]]
function lcscFilterParse(cats) {
    if (!cats) return [];
    return String(cats).split('|')
        .map(function (g) {
            return g.split(',')
                .map(function (v) { return parseInt(v, 10); })
                .filter(function (v) { return v > 0; });
        })
        .filter(function (g) { return g.length > 0; });
}

// 组序 = 维度行序：组 i 对应第 i 个维度
function lcscFilterGroups() {
    var raw = lcscFilterParse(_filterState.cats);
    var groups = [];
    for (var i = 0; i < LCSC_DIM_LABELS.length; i++) groups.push(raw[i] || []);
    return groups;
}

function lcscFilterSetGroups(groups) {
    var parts = [];
    for (var i = 0; i < groups.length; i++) {
        if (groups[i] && groups[i].length) parts.push(groups[i].join(','));
    }
    _filterState.cats = parts.join('|');
    lcscFilterPaint();
    _filterState.page = 1;
    loadPartsList();
}

// 点选某个值（同一维度内多选）
function toggleLcscCat(dimIdx, catId) {
    var groups = lcscFilterGroups();
    var g = groups[dimIdx] || (groups[dimIdx] = []);
    var pos = g.indexOf(catId);
    if (pos >= 0) g.splice(pos, 1);
    else g.push(catId);
    lcscFilterSetGroups(groups);
}

function clearLcscDim(dimIdx) {
    var groups = lcscFilterGroups();
    groups[dimIdx] = [];
    lcscFilterSetGroups(groups);
}

function clearLcscFilter() {
    _filterState.cats = '';
    lcscFilterPaint();
    _filterState.page = 1;
    loadPartsList();
}

// 展开/收起某行的全部值
function toggleLcscMore(dimIdx) {
    var box = document.getElementById('lcscMore' + dimIdx);
    var btn = document.getElementById('lcscMoreBtn' + dimIdx);
    if (!box) return;
    var hidden = box.hasAttribute('hidden');
    if (hidden) box.removeAttribute('hidden'); else box.setAttribute('hidden', '');
    if (btn) btn.textContent = hidden ? '收起 ∧' : '更多 ∨';
}

// ── 渲染选中态 ────────────────────────────────────────────────
function lcscFilterPaint() {
    var groups = lcscFilterGroups();

    // chip 高亮
    document.querySelectorAll('.lcsc-chip').forEach(function (el) {
        var id = parseInt(el.getAttribute('data-cat-id'), 10);
        var dim = parseInt(el.getAttribute('data-dim'), 10);
        var on = (groups[dim] || []).indexOf(id) >= 0;
        el.classList.toggle('is-on', on);
    });

    // 每行已选条件 + 清除按钮
    for (var i = 0; i < LCSC_DIM_LABELS.length; i++) {
        var sel = document.getElementById('lcscSel' + i);
        var btn = document.getElementById('lcscClear' + i);
        var ids = groups[i] || [];
        if (sel) {
            sel.innerHTML = ids.map(function (id) {
                var name = lcscFilterName(i, id);
                return '<span class="lcsc-sel-tag" data-dim="' + i + '" data-cat-id="' + id + '" title="点击移除">'
                     + esc(name) + ' <b>×</b></span>';
            }).join('');
        }
        if (btn) {
            if (ids.length) btn.removeAttribute('disabled');
            else btn.setAttribute('disabled', 'disabled');
        }
    }

    // 面板整体高亮（有任何选中时）
    var box = document.getElementById('lcscFilter');
    if (box) box.classList.toggle('has-filter', !!_filterState.cats);
}

// 从 chip 上取回名称（已选 tag 需要显示名）
function lcscFilterName(dimIdx, catId) {
    var el = document.querySelector('.lcsc-chip[data-dim="' + dimIdx + '"][data-cat-id="' + catId + '"]');
    if (el) {
        var t = el.getAttribute('data-cat-name') || el.textContent;
        return t.replace(/\s*\d+\s*$/, '').trim();
    }
    return String(catId);
}

// ── 自动初始化 ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    lcscFilterInit((CFG && CFG.dimLabels) || [], (CFG && CFG.cats) || '');
});

// 事件委托：点击已选 tag 的 × 移除
document.addEventListener('click', function (e) {
    var tag = e.target && e.target.closest ? e.target.closest('.lcsc-sel-tag') : null;
    if (!tag) return;
    e.preventDefault();
    e.stopPropagation();
    var dim = parseInt(tag.getAttribute('data-dim'), 10);
    var id  = parseInt(tag.getAttribute('data-cat-id'), 10);
    toggleLcscCat(dim, id);
});
