var LocPicker = {
    _tree: null,
    _loaded: false,

    // 团队版 + 全局开关开启才可用
    available: function () {
        return typeof _featureMap !== 'undefined' && !!_featureMap.location_manage;
    },

    // 扁平化树（BFS，带深度）
    _flatten: function (nodes, depth, out) {
        (nodes || []).forEach(function (n) {
            out.push({ id: n.id, name: n.name, path: n.path, depth: depth, part_cnt: n.part_cnt });
            LocPicker._flatten(n.children, depth + 1, out);
        });
        return out;
    },

    // 加载库位树（单次缓存）
    load: function (cb) {
        if (this._loaded) { if (cb) cb(this._tree); return; }
        LCSC.fetchJson('app/api/router.php?action=location.tree')
            .then(function (resp) {
                var data = (resp && resp.data) ? resp.data : {};
                LocPicker._tree = data.tree || [];
                LocPicker._loaded = true;
                if (cb) cb(LocPicker._tree);
            })
            .catch(function () {
                LocPicker._tree = null;
                LocPicker._loaded = true;
                if (cb) cb(null);
            });
    },

    // 初始化页面上所有 .loc-picker 下拉框
    initAll: function () {
        if (!this.available()) return;
        var selects = document.querySelectorAll('select.loc-picker');
        if (!selects.length) return;
        this.load(function (tree) {
            selects.forEach(LocPicker._fill);
        });
    },

    // 填充单个下拉框
    _fill: function (sel) {
        if (sel.dataset.locReady) return;
        sel.dataset.locReady = '1';
        var hidden = sel.parentElement.querySelector('input.loc-picker-id');
        var textEl = sel.getAttribute('data-text') ? document.getElementById(sel.getAttribute('data-text')) : null;

        var tree = LocPicker._tree;
        var html = '';
        if (!tree || !tree.length) {
            // 无库位数据：退化为占位（保留手动文本输入）
            html = '<option value="">— 暂无库位（手动输入） —</option>';
            sel.innerHTML = html;
            sel.disabled = true;
            return;
        }
        html += '<option value="">— 手动输入 —</option>';
        html += '<option value="0">— 解绑 / 清空 —</option>';
        LocPicker._flatten(tree, 0, []).forEach(function (n) {
            var indent = '';
            for (var i = 0; i < n.depth; i++) indent += '&nbsp;&nbsp;&nbsp;';
            html += '<option value="' + parseInt(n.id) + '" data-path="' + esc(n.path) + '">'
                  + indent + esc(n.name) + '</option>';
        });
        sel.innerHTML = html;

        sel.addEventListener('change', function () {
            var v = sel.value;
            if (hidden) hidden.value = v;
            var opt = sel.options[sel.selectedIndex];
            if (textEl) {
                if (v !== '') {
                    // 结构化模式：文本自动回填为库位路径（或清空）
                    textEl.value = (v === '0') ? '' : (opt ? (opt.getAttribute('data-path') || '') : '');
                    textEl.readOnly = false;
                }
                // v === ''：手动输入模式，文本框保持用户内容
            }
        });
    },

    // 回显：按 location_id 选中选项（编辑弹窗打开时调用）
    setByValue: function (sel, locId) {
        if (typeof sel === 'string') sel = document.getElementById(sel);
        if (!sel || !sel.options.length) return;
        var v = (locId === null || locId === undefined || locId === '') ? '' : String(parseInt(locId) || 0);
        var exists = false;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === v) { exists = true; break; }
        }
        sel.value = exists ? v : '';
        var hidden = sel.parentElement.querySelector('input.loc-picker-id');
        if (hidden) hidden.value = exists ? v : '';
        if (exists) {
            var opt = sel.options[sel.selectedIndex];
            var textEl = sel.getAttribute('data-text') ? document.getElementById(sel.getAttribute('data-text')) : null;
            if (textEl && v !== '' && v !== '0' && opt) textEl.value = opt.getAttribute('data-path') || '';
        }
    }
};

var LocText = {
    LIST_ID: 'locTextList',

    ensureList: function () {
        var dl = document.getElementById(LocText.LIST_ID);
        if (dl) return dl;
        dl = document.createElement('datalist');
        dl.id = LocText.LIST_ID;
        document.body.appendChild(dl);

        LCSC.fetchJson('app/api/router.php?action=parts.location_options&limit=60')
            .then(function (resp) {
                var opts = (resp && resp.data && resp.data.options) ? resp.data.options : [];
                opts.forEach(function (o) {
                    var v = (o && o.location) ? String(o.location).trim() : '';
                    if (!v) return;
                    var opt = document.createElement('option');
                    opt.value = v;
                    // title 展示该库位下已有多少物料，便于挑最常用的那个
                    if (o.cnt) opt.label = o.cnt + ' 个物料';
                    dl.appendChild(opt);
                });
            })
            .catch(function () { /* 联想属增强，失败静默降级为普通输入框 */ });

        return dl;
    },

    /* 初始化：给所有声明 list="locTextList" 的输入框挂上补全 */
    initAll: function () {
        var inputs = document.querySelectorAll('input[list="' + LocText.LIST_ID + '"]');
        if (!inputs.length) return;
        LocText.ensureList();
    }
};

// 页面加载完成后自动初始化
document.addEventListener('DOMContentLoaded', function () {
    LocText.initAll();
    LocPicker.initAll();
});
