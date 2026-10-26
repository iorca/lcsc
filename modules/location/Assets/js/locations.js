function closeOverlay(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

var LocPage = {
    TYPE_NAMES: { warehouse: '仓库', zone: '库区', shelf: '货架/库位', bin: '库位', bench: '工作台', lab: '实验室' },
    TYPE_ICONS: { warehouse: '🏭', zone: '🗄️', shelf: '📦', bin: '📦', bench: '🛠️', lab: '🧪' },
    TYPE_BY_DEPTH: ['warehouse', 'zone', 'shelf'],

    cfg: window.LocPageConfig || {},
    tree: [],
    flat: {},       // id -> node（含 children 引用）
    selectedId: 0,
    _kw: '',

    esc: function (s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },

    // 初始化
    render: function () {
        this.tree = (this.cfg.tree || []).slice();
        this._index(this.tree);
        this.renderTree();
        this._updateEmpty();
    },

    _index: function (nodes) {
        (nodes || []).forEach(function (n) {
            LocPage.flat[n.id] = n;
            LocPage._index(n.children);
        });
    },

    _match: function (n, kw) {
        if (!kw) return true;
        var hay = (n.path || '').toLowerCase() + (n.name || '').toLowerCase() + (LocPage.TYPE_NAMES[n.type] || '');
        return hay.indexOf(kw) > -1;
    },

    // 树搜索：仅展示匹配节点及其祖先
    search: function (kw) {
        this._kw = (kw || '').trim().toLowerCase();
        this.renderTree();
    },

    renderTree: function () {
        var box = document.getElementById('locTreeBox');
        if (!box) return;
        var kw = this._kw;
        var self = this;
        box.innerHTML = self._renderNodes(this.tree, 0, kw, true);
        this._updateSideMeta();
    },

    /* 侧栏统计行：库位总数 + 已绑定物料数（筛选时给出「可见/总数」） */
    _updateSideMeta: function () {
        var el = document.getElementById('locSideMeta');
        if (!el) return;
        var nodes = 0, bound = 0;
        var walk = function (arr) {
            (arr || []).forEach(function (n) { nodes++; bound += (parseInt(n.part_cnt, 10) || 0); walk(n.children); });
        };
        walk(this.tree);
        var box = document.getElementById('locTreeBox');
        var shown = box ? box.querySelectorAll('.loc-node-row').length : nodes;
        var cnt = (this._kw && shown !== nodes) ? (shown + ' / ' + nodes) : String(nodes);
        el.textContent = cnt + ' 个库位 · 已绑定 ' + bound + ' 种物料';
    },

    _renderNodes: function (nodes, depth, kw, forceShow) {
        if (!nodes || !nodes.length) return '';
        var html = '';
        nodes.forEach(function (n) {
            var childHtml = LocPage._renderNodes(n.children, depth + 1, kw, forceShow && LocPage._match(n, kw));
            var matchSelf = LocPage._match(n, kw);
            if (kw && !matchSelf && !childHtml) return; // 搜索时过滤不匹配且无匹配后代
            var open = (!kw || matchSelf) ? ' open' : '';
            var hasKids = n.children && n.children.length;
            html += '<div class="loc-node-row' + open + '" data-id="' + n.id + '">'
                + '<div class="loc-node loc-node-d' + Math.min(depth, 3) + ' kind-' + (n.type || 'shelf') + (LocPage.selectedId === n.id ? ' sel' : '') + '" onclick="LocPage.select(' + n.id + ')">'
                + '<span class="loc-node-twist' + (hasKids ? '' : ' empty') + '">' + (hasKids ? '▾' : '') + '</span>'
                + '<span class="loc-node-ic">' + (LocPage.TYPE_ICONS[n.type] || '📦') + '</span>'
                + '<span class="loc-node-name" title="' + LocPage.esc(n.path) + '">' + LocPage.esc(n.name) + '</span>'
                + '<span class="loc-node-cnt">' + n.part_cnt + '</span>'
                + (LocPage.cfg.canManage
                    ? '<span class="loc-node-ops" onclick="event.stopPropagation()">'
                        + (depth < (LocPage.cfg.maxDepth || 3) ? '<button class="op-btn" title="新增子级" onclick="LocTree.addNode(' + n.id + ')">＋</button>' : '')
                        + '<button class="op-btn" title="编辑" onclick="LocTree.rename(' + n.id + ')">✎</button>'
                        + '<button class="op-btn" title="移动" onclick="LocTree.move(' + n.id + ')">⇄</button>'
                        + '<button class="op-btn danger" title="删除" onclick="LocTree.remove(' + n.id + ')">🗑</button>'
                        + '</span>'
                    : '')
                + '</div>'
                + (hasKids ? '<div class="loc-node-kids">' + childHtml + '</div>' : '')
                + '</div>';
        });
        return html;
    },

    // 选中节点 → 拉取详情
    select: function (id) {
        if (!id) return;
        if (this.selectedId === id) return;
        this.selectedId = id;
        this.renderTree();
        this.loadDetail(id);
    },

    /* 寻物定位：清掉侧栏筛选 → 选中节点 → 树内滚动高亮 → 拉详情 */
    locate: function (id) {
        id = parseInt(id, 10) || 0;
        if (!id || !this.flat[id]) { LCSC.toast('该物料还没有绑定到库位树', 'warning'); return false; }
        if (this._kw) {
            this._kw = '';
            var si = document.getElementById('locSearchInput');
            if (si) si.value = '';
        }
        this.selectedId = id;
        this.renderTree();
        this.loadDetail(id);
        var row = document.querySelector('.loc-node-row[data-id="' + id + '"] .loc-node');
        if (row && row.scrollIntoView) {
            row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            row.classList.add('is-flash');
            setTimeout(function () { row.classList.remove('is-flash'); }, 2400);
        }
        return true;
    },

    loadDetail: function (id) {
        var self = this;
        var pane = document.getElementById('locDetailContent');
        var hint = document.getElementById('locPickHint');
        var emptyTree = document.getElementById('locEmptyTree');
        if (pane) pane.classList.remove('hide');
        // 三个面板互斥：加载详情时先关掉两个空态，再打开内容区
        if (hint) hint.classList.remove('is-on');
        if (emptyTree) emptyTree.classList.remove('is-on');
        var p = document.getElementById('locDetailPane');
        if (pane) pane.classList.add('is-on');
        pane.innerHTML = '<div class="empty-state loc-empty-tall"><div class="icon">⏳</div>加载中...</div>';
        if (typeof LCSC === 'undefined' || !LCSC.fetchJson) { pane.innerHTML = '<div class="ph-dash">组件未加载</div>'; return; }
        LCSC.fetchJson('app/api/router.php?action=location.parts&location_id=' + id + '&descendants=1')
            .then(function (resp) {
                if (resp.code !== 0) throw new Error(resp.msg || '加载失败');
                self._renderDetail(resp.data);
            })
            .catch(function (e) {
                pane.innerHTML = '<div class="flash err">加载失败：' + (e.message || '请重试') + '</div>';
            });
    },

    _renderDetail: function (d) {
        var pane = document.getElementById('locDetailContent');
        if (!pane) return;
        if (!d || !d.node) { pane.innerHTML = '<div class="flash err">库位不存在或已被删除，请刷新</div>'; return; }
        var n = d.node;
        var stats = d.stats || {};
        var parts = d.parts || [];
        var canCash = !!this.cfg.canViewCost;
        var canBatch = !!this.cfg.canBatch;
        var self = this;
        if (typeof LocBatch !== 'undefined') LocBatch.reset();

        var headHtml =
            '<div class="loc-detail-header">'
            + '<div class="loc-detail-main">'
            + '<div class="loc-detail-path">'
            + (this.TYPE_ICONS[n.type] || '📦') + ' ' + this.esc(n.path)
            + '<span class="badge badge-blue">' + (this.TYPE_NAMES[n.type] || n.type) + '</span>'
            + '</div>'
            + (n.desc ? '<div class="loc-detail-desc">📝 ' + this.esc(n.desc) + '</div>' : '')
            + '<div class="loc-stat-grid">'
            + this._statCard('库位编码', '#' + n.id, '')
            + this._statCard('层级', n.depth + ' 级', '')
            + this._statCard('本级物料', stats.part_cnt + ' 种', 'is-count')
            + this._statCard('库存总量', String(stats.total_stock || 0), 'is-stock')
            + (canCash ? this._statCard('库存总价值', '¥' + Number(stats.total_value || 0).toFixed(2), 'is-value') : '')
            + '</div>'
            + '</div>'
            + '<div class="loc-detail-ops">'
            + (this.cfg.canManage
                ? '<button class="btn btn-ghost btn-sm" onclick="LocTree.rename(' + n.id + ')">✎ 编辑</button>'
                  + '<button class="btn btn-ghost btn-sm" onclick="LocTree.addNode(' + n.id + ')">＋ 新增子级</button>'
                : '')
            + '</div>'
            + '</div>';

        var listHtml = '';
        if (!parts.length) {
            listHtml = '<div class="empty-state loc-empty"><div class="icon">📭</div>'
                + '<div class="loc-empty-title">该库位暂无物料</div>'
                + '<div class="loc-empty-desc">在「物料列表」编辑物料的库位，或批量操作中将物料归类到此处</div>'
                + '<a class="btn btn-ghost btn-sm loc-empty-btn" href="index.php?v=inventory">前往物料列表设置库位</a>'
                + '</div>';
        } else {
            var rows = parts.map(function (p) {
                var sc = p.stock === 0 ? 's-zero' : (p.stock <= p.eff_threshold ? 's-low' : 's-ok');
                var act = (self.cfg.canEdit
                    ? '<button class="btn btn-ghost btn-sm loc-row-btn" onclick="LocStock.open(' + JSON.stringify({ id: p.id, model: p.model, no: p.platform_part_no, stock: p.stock }).replace(/"/g, '&quot;') + ')">出入库</button>'
                    : '')
                    + '<a class="btn btn-ghost btn-sm loc-row-btn" href="index.php?v=inventory&q=' + encodeURIComponent(p.model || p.platform_part_no || '') + '&loc=' + encodeURIComponent(n.path) + '">编辑</a>';
                var checkCell = canBatch
                    ? '<td class="loc-cell-check"><input type="checkbox" class="loc-part-cb" value="' + (parseInt(p.id, 10) || 0) + '"'
                        + ' onchange="LocBatch.toggle(this)" aria-label="选择物料"></td>'
                    : '';
                return '<tr>'
                    + checkCell
                    + '<td class="mono">' + (p.platform_part_no ? LocPage.esc(p.platform_part_no) : '<span class="ph-dash">—</span>') + '</td>'
                    + '<td>' + LocPage.esc(p.model || '—') + '</td>'
                    + '<td>' + (p.package ? '<span class="pkg-badge">' + LocPage.esc(p.package) + '</span>' : '<span class="ph-dash">—</span>') + '</td>'
                    + '<td><span class="stock-num ' + sc + '">' + p.stock + '</span>' + (p.damaged > 0 ? ' <span class="loc-damaged">不良 ' + p.damaged + '</span>' : '') + '</td>'
                    + (canCash ? '<td class="num loc-cell-cost">' + (p.avg_cost > 0 ? '¥' + Number(p.stock * p.avg_cost).toFixed(2) : '—') + '</td>' : '')
                    + '<td class="td-actions loc-cell-ops">' + act + '</td>'
                    + '</tr>';
            }).join('');
            listHtml = '<div class="table-wrap loc-table-wrap">'
                + '<table class="data-table"><thead><tr>'
                + (canBatch ? '<th class="loc-th-check"><input type="checkbox" id="locCheckAll" onclick="LocBatch.toggleAll(this.checked)" aria-label="全选物料"></th>' : '')
                + '<th class="loc-th-l">商品编号</th><th class="loc-th-l">型号</th><th>封装</th><th>库存</th>'
                + (canCash ? '<th>货值</th>' : '')
                + '<th class="loc-th-r">操作</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        }

        // 物料小节标题。筛选入口已统一到顶部搜索栏（作用域切到「当前物料」），
        var toolbar = '<div class="loc-list-bar">'
            + '<div class="sec-title loc-sec is-spaced loc-list-title">库位物料 <span class="loc-sec-tail">（共 ' + stats.part_cnt + ' 个，含子级）</span></div>'
            + (parts.length > 1
                ? '<span class="loc-list-hint">筛选：把上方搜索范围切到「当前物料」后输入关键词</span>'
                : '')
            + '</div>';
        // 批量工具条：勾选物料后就地解绑（不弹二级弹窗，确认走全站统一确认框）
        var batchBar = (canBatch && parts.length)
            ? '<div class="loc-batch-bar" id="locBatchBar">'
                + '<span class="loc-batch-count">已选 <b id="locBatchCount">0</b> 项</span>'
                + '<button type="button" class="btn btn-ghost btn-sm" id="locBatchUnbind" onclick="LocBatch.unbind()" disabled>解绑库位</button>'
                + '<button type="button" class="btn btn-ghost btn-sm" onclick="LocBatch.clear()">取消选择</button>'
                + '<span class="loc-batch-hint">解绑后物料不再归属任何库位，库存与流水不受影响</span>'
            + '</div>'
            : '';

        pane.innerHTML =
            '<div class="sec-title loc-sec">库位信息</div>' + headHtml
            + toolbar
            + batchBar
            + listHtml
            + (parts.length > 1
                ? '<div id="locFilterEmpty" class="empty-state loc-filter-empty"><div class="icon">🔍</div><div class="es-desc">没有匹配当前关键词的物料</div></div>'
                : '');
        if (canBatch && typeof LocBatch !== 'undefined') LocBatch._sync();
        if (typeof LocSearch !== 'undefined' && LocSearch.scope === 'parts') {
            var si = document.getElementById('locSearchInput');
            if (si && si.value) this.filterParts(si.value);
        }
    },

    /* 统计卡：详情头部的「编码 / 层级 / 物料 / 库存 / 价值」小卡 */
    _statCard: function (k, v, tone) {
        return '<div class="loc-stat-card">'
            + '<div class="loc-stat-k">' + this.esc(k) + '</div>'
            + '<div class="loc-stat-v ' + (tone || '') + '">' + this.esc(v) + '</div>'
            + '</div>';
    },

    filterParts: function (kw) {
        kw = (kw || '').trim().toLowerCase();
        var rows = document.querySelectorAll('#locDetailContent tbody tr');
        var hit = 0;
        rows.forEach(function (tr) {
            var ok = !kw || tr.textContent.toLowerCase().indexOf(kw) > -1;
            tr.classList.toggle('is-hidden', !ok);
            if (ok) hit++;
        });
        var emp = document.getElementById('locFilterEmpty');
        if (emp) emp.classList.toggle('is-on', kw !== '' && hit === 0);
    },

    _updateEmpty: function () {
        var box = document.getElementById('locEmptyTree');
        var hint = document.getElementById('locPickHint');
        var pane = document.getElementById('locDetailContent');
        var hasTree = this.tree && this.tree.length;
        // 三态互斥走 `is-on`（基础类已含 display:none）——
        if (box) box.classList.toggle('is-on', !hasTree);
        if (hint) hint.classList.toggle('is-on', (!hasTree || !this.selectedId) && this._renderedOnce);
        // 首次加载若树为空，默认显示空态引导
        if (!this._renderedOnce) {
            if (hasTree) {
                // 默认选中第一个叶子节点 —— 选中态必须重绘一次树才可见
                var first = this._firstNode(this.tree);
                if (first) { this.selectedId = first.id; this.renderTree(); }
            }
            this._renderedOnce = true;
        }
        if (hasTree && this.selectedId) this.loadDetail(this.selectedId);
        else if (hasTree) { if (hint) hint.classList.add('is-on'); }
    },

    _firstNode: function (nodes) {
        if (!nodes.length) return null;
        var n = nodes[0];
        if (n.children && n.children.length) return this._firstNode(n.children);
        return n;
    },

    // ── API 封装 ──
    _post: function (method, data, done) {
        var body = 'action=location.' + method + '&_csrf=' + LCSC.csrf;
        Object.keys(data || {}).forEach(function (k) { body += '&' + k + '=' + encodeURIComponent(data[k]); });
        LCSC.fetchJson('app/api/router.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (resp) {
            if (resp.code === 0) { LCSC.toast(resp.msg || '操作成功', 'success'); if (done) done(resp.data); }
            else LCSC.toast(resp.msg || '操作失败', 'error');
        }).catch(function (msg) { LCSC.toast(String(msg), 'error'); });
    },

    _reload: function () {
        LCSC.fetchJson('app/api/router.php?action=location.tree').then(function (resp) {
            if (resp.code !== 0) return;
            var t = (resp.data && resp.data.tree) || [];
            LocPage.tree = t.slice();
            LocPage.flat = {};
            LocPage._index(t);
            if (LocPage._kw) LocPage.renderTree(); else LocPage.renderTree();
            LocPage._updateEmpty();
        }).catch(function () {});
    },

    // 存量回填：先预览 → 二次确认 → 分批执行
    backfill: function () {
        var self = this;
        LCSC.fetchJson('app/api/router.php?action=location.backfill_preview').then(function (resp) {
            if (resp.code !== 0) { LCSC.toast(resp.msg || '请求失败', 'error'); return; }
            var d = resp.data || {};
            if (!d.total || d.total <= 0) { LCSC.toast('当前没有待回填的历史文本库位', 'info'); return; }
            var samples = (d.samples || []).slice(0, 10).map(function (s) {
                return '· ' + LocPage._previewPath(s.location);
            }).join('\n');
            AdminUIConfirm({
                title: '存量回填确认',
                message: '待回填物料：' + d.total + ' 条。\n将自动把现有的文本库位解析为三级库位树并绑定物料，例如：\n' + samples + '\n\n该操作幂等可重复执行，已绑定物料不受影响。\n确认开始回填？',
                okText: '开始回填',
                onOk: function () { self._doBackfill(); }
            });
        }).catch(function (e) { LCSC.toast(String(e.message || '预览失败'), 'error'); });
    },

    _previewPath: function (loc) {
        return String(loc || '').split(/[\/→>]/).map(function (s) { return s.trim(); }).filter(Boolean).join(' / ');
    },

    _doBackfill: function () {
        var self = this;
        var btn = document.getElementById('locBackfillBtn');
        if (btn) btn.disabled = true;
        var step = function () {
            LCSC.fetchJson('app/api/router.php?action=location.backfill', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_csrf=' + LCSC.csrf
            }).then(function (resp) {
                if (resp.code !== 0) { LCSC.toast(resp.msg || '回填失败', 'error'); if (btn) btn.disabled = false; return; }
                var d = resp.data || {};
                LCSC.toast(resp.msg, 'success');
                if (d.remaining > 0) { setTimeout(step, 300); }
                else { if (btn && btn.parentNode) btn.parentNode.removeChild(btn); LocPage._reload(); }
            }).catch(function (msg) { LCSC.toast(String(msg), 'error'); if (btn) btn.disabled = false; });
        };
        step();
    },

    // 批量导入 CSV（顶栏按钮触发）
    importCsv: function (btn) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = '.csv,.txt,text/csv';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) { document.body.removeChild(input); return; }
            var fd = new FormData();
            fd.append('csv', f);
            fd.append('_csrf', LCSC.csrf);
            if (typeof LCSC !== 'undefined' && LCSC.fetchJson) {
                LCSC.fetchJson('app/api/router.php?action=location.import_csv', { method: 'POST', body: fd })
                    .then(function (resp) {
                        if (resp.code === 0) { LCSC.toast(resp.msg, 'success'); LocPage._reload(); }
                        else LCSC.toast(resp.msg || '导入失败', 'error');
                    })
                    .catch(function (e) { LCSC.toast(String(e.message || '导入失败'), 'error'); });
            }
            document.body.removeChild(input);
        });
        input.click();
    }
};

// ═══ 库位物料：批量选择 + 快捷解绑 ═══
var LocBatch = {
    _ids: {},

    /* 清空选择（切换库位 / 重绘表单时调用，防止解绑到已不可见的旧 id） */
    reset: function () {
        this._ids = {};
        var all = document.getElementById('locCheckAll');
        if (all) { all.checked = false; all.indeterminate = false; }
        this._sync();
    },

    toggle: function (cb) {
        if (!cb) return;
        var id = String(parseInt(cb.value, 10) || 0);
        if (!id || id === '0') return;
        if (cb.checked) this._ids[id] = 1; else delete this._ids[id];
        this._sync();
    },

    /* 全选 / 取消全选：跳过被「当前物料」筛选隐藏的行 */
    toggleAll: function (on) {
        var self = this;
        this._ids = {};
        document.querySelectorAll('#locDetailContent .loc-part-cb').forEach(function (cb) {
            var tr = cb.closest('tr');
            var hidden = tr && tr.classList.contains('is-hidden');
            if (!on || hidden) { cb.checked = false; return; }
            cb.checked = true;
            self._ids[String(parseInt(cb.value, 10) || 0)] = 1;
        });
        this._sync();
    },

    clear: function () {
        this._ids = {};
        document.querySelectorAll('#locDetailContent .loc-part-cb').forEach(function (cb) { cb.checked = false; });
        this._sync();
    },

    _sync: function () {
        var cnt = Object.keys(this._ids).length;
        var el = document.getElementById('locBatchCount');
        if (el) el.textContent = String(cnt);
        var btn = document.getElementById('locBatchUnbind');
        if (btn) btn.disabled = cnt === 0;
        var all = document.getElementById('locCheckAll');
        if (all) {
            var boxes = document.querySelectorAll('#locDetailContent .loc-part-cb');
            all.checked = boxes.length > 0 && cnt === boxes.length;
            all.indeterminate = cnt > 0 && cnt < boxes.length;
        }
    },

    unbind: function () {
        var ids = Object.keys(this._ids);
        if (!ids.length) return;
        var self = this;
        AdminUIConfirm({
            title: '批量解绑库位',
            message: '已选 ' + ids.length + ' 个物料。解绑后会清空这些物料的库位绑定与库位文本，库存与出入库流水不受影响。\n确认解绑？',
            okText: '确认解绑',
            onOk: function () { self._doUnbind(ids); }
        });
    },

    _doUnbind: function (ids) {
        var body = 'action=location.batch_bind&_csrf=' + encodeURIComponent(LCSC.csrf) + '&location_id=0';
        ids.forEach(function (id) { body += '&part_ids%5B%5D=' + encodeURIComponent(id); });
        LCSC.fetchJson('app/api/router.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (resp) {
            if (resp.code !== 0) { LCSC.toast(resp.msg || '解绑失败', 'error'); return; }
            LCSC.toast(resp.msg || '已解绑', 'success');
            // 树上的物料计数会变，详情也要重拉 —— _reload 内部会重新加载当前节点
            LocPage._reload();
        }).catch(function (msg) { LCSC.toast(String(msg || '解绑失败'), 'error'); });
    }
};

// ═══ 树节点操作（弹窗） ═══
var LocModal = {
    _open: function (title, bodyHtml, onOk, wide) {
        LocModal._close();
        var ov = document.createElement('div');
        ov.id = 'locModalOverlay';
        ov.className = 'acm-overlay';
        ov.innerHTML = '<div class="acm-dialog' + (wide ? ' is-wide' : ' is-narrow') + '">'
            + '<div class="acm-title">' + LocPage.esc(title) + '</div>'
            + bodyHtml
            + '<div class="acm-btns">'
            + '<button type="button" class="acm-btn" data-act="cancel">取消</button>'
            + '<button type="button" class="acm-btn ok primary" data-act="ok">确定</button>'
            + '</div></div>';
        document.body.appendChild(ov);
        ov.addEventListener('click', function (e) {
            if (e.target === ov) { LocModal._close(); return; }
            var act = e.target.getAttribute && e.target.getAttribute('data-act');
            if (act === 'cancel') LocModal._close();
            else if (act === 'ok') {
                LocModal._values = {};
                ov.querySelectorAll('input[id],select[id],textarea[id]').forEach(function (el) {
                    LocModal._values[el.id] = el.value;
                });
                LocModal._close();
                if (onOk) onOk();
            }
        });
        ov.querySelector('[data-act="ok"]').focus();
        var kd = function (e) { if (e.key === 'Escape') { LocModal._close(); document.removeEventListener('keydown', kd); } };
        document.addEventListener('keydown', kd);
    },
    _close: function () {
        var ov = document.getElementById('locModalOverlay');
        if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
    },
    /* 聚焦并选中弹窗内输入框（须与 open() 同对象定义，供各调用点使用） */
    _inputSel: function (sel) {
        var el = document.querySelector(sel);
        if (el) { setTimeout(function () { el.focus(); el.select(); }, 60); }
    }
};

var LocTree = {
    /* 类型下拉（含「自定义…」）+ 描述输入 —— 新增与编辑弹窗共用。 */
    _typeBody: function (defaultType, desc) {
        defaultType = defaultType || 'warehouse';
        var builtins = ['warehouse', 'zone', 'shelf', 'bench', 'lab'];
        var isCustom = builtins.indexOf(defaultType) === -1;
        var opts = builtins.map(function (t) {
            return '<option value="' + t + '" ' + (t === defaultType ? 'selected' : '') + '>' + (LocPage.TYPE_NAMES[t] || t) + '</option>';
        }).join('') + '<option value="__custom"' + (isCustom ? ' selected' : '') + '>自定义…</option>';
        return '<div class="acm-field">'
            + '<div class="acm-msg">类型：</div>'
            + '<select id="locMType" class="loc-m-input">' + opts + '</select></div>'
            + '</div>'   // 收口 .acm-grid2（调用方开的头）
            + '<div class="acm-field">'
            + '<input type="text" id="locMTypeCustom" class="loc-m-custom" maxlength="30" placeholder="自定义类型，如：自封袋 / 抽屉格 / 周转箱" value="' + (isCustom ? LocPage.esc(defaultType) : '') + '">'
            + '<div class="acm-msg">描述（可选）：</div>'
            + '<textarea id="locMDesc" class="loc-m-input loc-m-desc" rows="2" placeholder="备注，如：A区第二层，存放大件">' + LocPage.esc(desc || '') + '</textarea>'
            + '</div>';
    },
    _bindTypeToggle: function () {
        var sel = document.getElementById('locMType');
        var custom = document.getElementById('locMTypeCustom');
        if (!sel || !custom) return;
        var sync = function () { custom.classList.toggle('is-on', sel.value === '__custom'); };
        sel.addEventListener('change', sync);
        sync();
    },
    _pickType: function () {
        var vals = LocModal._values || {};
        var sel = vals.locMType || 'warehouse';
        return sel === '__custom' ? (vals.locMTypeCustom || '').trim() : sel;
    },

    // 新增节点：mode 可选 parentId（0=顶层）或省略
    addNode: function (parentId) {
        parentId = parseInt(parentId, 10) || 0;
        var parent = parentId > 0 ? LocPage.flat[parentId] : null;
        if (parent && parent.depth >= (LocPage.cfg.maxDepth || 3)) { LCSC.toast('库位层级最深 ' + (LocPage.cfg.maxDepth || 3) + ' 级', 'warning'); return; }
        var defaultType = parent ? (LocPage.TYPE_BY_DEPTH[parent.depth] || 'shelf') : 'warehouse';
        LocModal._open(
            parent ? ('在「' + parent.path + '」下新增') : '新增库位',
            '<div class="acm-grid2">'
            + '<div class="acm-field"><div class="acm-msg">名称：</div>'
            + '<input type="text" id="locMName" class="loc-m-input" placeholder="如：' + (parent ? 'A-01 货架' : '主仓库') + '"></div>'
            + LocTree._typeBody(defaultType, ''),
            function () {
                var vals = LocModal._values || {};
                var name = (vals.locMName || '').trim();
                var type = LocTree._pickType();
                if (!name) { LCSC.toast('请输入库位名称', 'warning'); LocTree.addNode(parentId); return; }
                if (vals.locMType === '__custom' && !type) { LCSC.toast('请输入自定义类型名称', 'warning'); LocTree.addNode(parentId); return; }
                LocPage._post('create', { name: name, type: type || 'warehouse', parent_id: parentId, description: (vals.locMDesc || '').trim() }, function () { LocPage._reload(); });
            },
            true
        );
        LocTree._bindTypeToggle();
        LocModal._inputSel('#locMName');
    },

    rename: function (id) {
        var node = LocPage.flat[id];
        if (!node) return;
        LocModal._open('编辑库位',
            '<div class="acm-msg">当前：' + LocPage.esc(node.path) + '</div>'
            + '<div class="acm-grid2">'
            + '<div class="acm-field"><div class="acm-msg">名称：</div>'
            + '<input type="text" id="locMName" class="loc-m-input" value="' + LocPage.esc(node.name) + '"></div>'
            + LocTree._typeBody(node.type, node.description),
            function () {
                var vals = LocModal._values || {};
                var name = (vals.locMName || '').trim();
                var type = LocTree._pickType();
                if (!name) { LCSC.toast('名称不能为空', 'warning'); LocTree.rename(id); return; }
                if (vals.locMType === '__custom' && !type) { LCSC.toast('请输入自定义类型名称', 'warning'); LocTree.rename(id); return; }
                LocPage._post('rename', { id: id, name: name, type: type || 'warehouse', description: (vals.locMDesc || '').trim() }, function () { LocPage._reload(); });
            },
            true
        );
        LocTree._bindTypeToggle();
        LocModal._inputSel('#locMName');
    },

    move: function (id) {
        var node = LocPage.flat[id];
        if (!node) return;
        var nodePath = node.path;
        var maxDepth = LocPage.cfg.maxDepth || 3;
        var opts = '<option value="0">— 顶层（仓库） —</option>';
        Object.keys(LocPage.flat).forEach(function (k) {
            var n = LocPage.flat[k];
            if (n.id === id) return;
            if ((n.path + ' / ').indexOf(nodePath + ' / ') === 0 || n.path === nodePath) return; // 防环
            if (n.depth >= maxDepth) return;
            opts += '<option value="' + n.id + '">' + LocPage.esc(n.path) + '</option>';
        });
        LocModal._open('移动库位',
            '<div class="acm-msg">将「' + LocPage.esc(nodePath) + '」移动到：</div>'
            + '<select id="locMMove" class="loc-m-input">' + opts + '</select>',
            function () {
                var pid = parseInt((LocModal._values || {}).locMMove || '0', 10) || 0;
                LocPage._post('move', { id: id, parent_id: pid }, function () { LocPage._reload(); });
            }
        );
    },

    remove: function (id) {
        var node = LocPage.flat[id];
        if (!node) return;
        AdminUIConfirm({
            title: '删除库位',
            message: '确认删除库位「' + node.path + '」？\n（该库位下仍有子级或已绑定物料时，系统将拒绝删除）',
            okText: '删除',
            onOk: function () {
                LocPage._post('delete', { id: id }, function () { LocPage._reload(); });
            }
        });
    }
};

// ═══ 寻物（GET location.find?q= —— 纯查询，登录即可） ═══
var LocFind = {
    _last: null,

    search: function () {
        var input = document.getElementById('locSearchInput');
        var q = ((input && input.value) || '').trim();
        var box = document.getElementById('locFindResult');
        if (!box) return;
        if (!q) { LCSC.toast('请输入物料编号、型号或扫码', 'warning'); if (input) input.focus(); return; }
        this._stopSpeak();
        box.classList.add('is-on');
        box.innerHTML = '<div class="loc-find-loading">⏳ 正在查找「' + LocPage.esc(q) + '」…</div>';
        LCSC.fetchJson('app/api/router.php?action=location.find&q=' + encodeURIComponent(q))
            .then(function (resp) {
                if (resp.code !== 0) throw new Error(resp.msg || '寻物失败');
                LocFind._render(resp.data || {});
            })
            .catch(function (e) {
                box.innerHTML = '<div class="loc-find-banner is-err">'
                    + '<span class="loc-find-bn-ico">⚠️</span>'
                    + '<div class="loc-find-bn-txt">查询失败：' + LocPage.esc((e && e.message) || '请重试') + '</div>'
                    + '<div class="loc-find-bn-acts"><button type="button" class="btn btn-ghost btn-sm" onclick="LocFind.close()">✕ 关闭</button></div>'
                    + '</div>';
            });
    },

    close: function () {
        this._stopSpeak();
        var box = document.getElementById('locFindResult');
        if (box) { box.classList.remove('is-on'); box.innerHTML = ''; }
    },

    _render: function (d) {
        var box = document.getElementById('locFindResult');
        if (!box) return;
        this._last = d;
        var status = d.status || 'not_found';
        var tones  = { ok_single: 'is-ok', ok_multi: 'is-info', no_location: 'is-warn', not_found: 'is-err', bad_input: 'is-info' };
        var icons  = { ok_single: '🎯', ok_multi: '📋', no_location: '⚠️', not_found: '🔍', bad_input: 'ℹ️' };
        var speak  = d.speak || '';
        var hits   = d.hits || [];

        var html = '<div class="loc-find-banner ' + (tones[status] || 'is-info') + '">'
            + '<span class="loc-find-bn-ico">' + (icons[status] || '🔍') + '</span>'
            + '<div class="loc-find-bn-txt">' + LocPage.esc(speak || '查询完成') + '</div>'
            + '<div class="loc-find-bn-acts">'
            + (speak && window.speechSynthesis
                ? '<button type="button" class="btn btn-ghost btn-sm" onclick="LocFind.speak()">🔊 播报</button>' : '')
            + '<button type="button" class="btn btn-ghost btn-sm" onclick="LocFind.close()">✕ 关闭</button>'
            + '</div></div>';
        if (hits.length) {
            html += '<div class="loc-find-cards">' + hits.map(function (h) { return LocFind._cardHtml(h); }).join('') + '</div>';
        }
        box.innerHTML = html;
        box.classList.add('is-on');

        // 唯一命中且已绑库位：自动在左侧树中定位 + 播报一次（寻物的「开箱即用」路径）
        if (status === 'ok_single' && hits.length && (hits[0].location_id > 0)) {
            LocPage.locate(hits[0].location_id);
            this.speak();
        }
    },

    _cardHtml: function (h) {
        var meta = [];
        if (h.platform_part_no) meta.push(LocPage.esc(h.platform_part_no));
        if (h.brand) meta.push(LocPage.esc(h.brand));
        if (h.package) meta.push(LocPage.esc(h.package));
        var located = (h.location_id > 0) && !!h.location_path;
        var jump = 'index.php?v=inventory&q=' + encodeURIComponent(h.model || h.platform_part_no || LCSC.partCode(h.internal_id));
        return '<div class="loc-find-card' + (located ? '' : ' is-noloc') + '">'
            + '<div class="loc-find-card-head">'
            + '<div class="loc-find-name">' + LocPage.esc(h.model_label || h.model || LCSC.partCode(h.internal_id)) + '</div>'
            + '<div class="loc-find-stock"><b class="loc-find-stock-v">' + (h.stock || 0) + '</b><span class="loc-find-stock-k">库存</span></div>'
            + '</div>'
            + '<div class="loc-find-meta">' + (meta.join(' · ') || '—') + '</div>'
            + (located ? this._pathHtml(h) : '<div class="loc-find-noloc">⚠️ 尚未分配库位，无法定位</div>')
            + (located && h.sibling_cnt > 0 ? '<div class="loc-find-sib">该库位共 ' + h.sibling_cnt + ' 种物料</div>' : '')
            + '<div class="loc-find-acts">'
            + (located
                ? '<button type="button" class="btn btn-primary btn-sm" onclick="LocPage.locate(' + h.location_id + ')">在库位树中定位</button>'
                : '<a class="btn btn-ghost btn-sm" href="' + jump + '">去设置库位</a>')
            + '<a class="btn btn-ghost btn-sm" href="' + jump + '">查看物料</a>'
            + '</div>'
            + '</div>';
    },

    /* 库位路径面包屑：路径能对上库位树节点的段落可点击（逐级定位） */
    _pathHtml: function (h) {
        var path = h.location_path || '';
        if (!path) return '';
        var segs = path.split('/').map(function (s) { return s.trim(); }).filter(Boolean);
        var cum = '', out = '';
        for (var i = 0; i < segs.length; i++) {
            cum = cum ? cum + ' / ' + segs[i] : segs[i];
            var node = LocFind._nodeByPath(cum);
            out += (i ? '<span class="loc-find-sep">›</span>' : '')
                + (node
                    ? '<button type="button" class="loc-find-seg is-link" onclick="LocPage.locate(' + node.id + ')">' + LocPage.esc(segs[i]) + '</button>'
                    : '<span class="loc-find-seg">' + LocPage.esc(segs[i]) + '</span>');
        }
        return '<div class="loc-find-path"><span class="loc-find-path-k">存放于</span>' + out + '</div>';
    },

    /* 按完整路径反查树节点（纯文本库位对不上节点时返回 null，该段落退化为纯文字） */
    _nodeByPath: function (p) {
        var keys = Object.keys(LocPage.flat);
        for (var i = 0; i < keys.length; i++) {
            var n = LocPage.flat[keys[i]];
            if ((n.path || '') === p) return n;
        }
        return null;
    },

    speak: function () {
        var txt = (this._last && this._last.speak) || '';
        if (!txt) return;
        if (!window.speechSynthesis) { LCSC.toast('当前浏览器不支持语音播报', 'warning'); return; }
        try {
            window.speechSynthesis.cancel();
            var u = new SpeechSynthesisUtterance(txt);
            u.lang = 'zh-CN';
            window.speechSynthesis.speak(u);
        } catch (e) {
            LCSC.toast('语音播报不可用', 'warning');
        }
    },

    _stopSpeak: function () {
        try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) { /* 忽略 */ }
    }
};

// ═══ 统一搜索栏（作用域切换：寻物 / 库位 / 当前物料） ═══
var LocSearch = {
    scope: 'find',

    setScope: function (s) {
        this.scope = s || 'find';
        var inp = document.getElementById('locSearchInput');
        if (inp) inp.value = '';
        LocPage.search('');
        LocPage.filterParts('');
        LocFind.close();
        var btn  = document.getElementById('locSearchBtn');
        var hint = document.getElementById('locSearchHint');
        if (this.scope === 'find') {
            if (inp)  inp.placeholder = '输入物料编号 / 型号，或用扫码枪扫物料码，回车定位库位';
            if (btn)  btn.textContent = '寻物';
            if (hint) hint.textContent = '支持内部编号（#123）、立创编号、型号关键词；结果可播报并在左侧库位树中高亮';
        } else if (this.scope === 'tree') {
            if (inp)  inp.placeholder = '输入库位名称 / 路径关键词，实时筛选左侧库位树';
            if (btn)  btn.textContent = '清空';
            if (hint) hint.textContent = '仅过滤左侧库位树，不影响物料数据';
        } else {
            if (inp)  inp.placeholder = '输入型号 / 编号关键词，实时筛选当前库位的物料';
            if (btn)  btn.textContent = '清空';
            if (hint) hint.textContent = '仅过滤右侧「库位物料」表，需先选中一个库位';
        }
        if (inp) inp.focus();
    },

    /* 输入即过滤（寻物作用域不实时过滤，等回车或点按钮） */
    onInput: function (v) {
        if (this.scope === 'tree') LocPage.search(v);
        else if (this.scope === 'parts') LocPage.filterParts(v);
    },

    /* 主按钮：寻物作用域执行查询；树 / 物料作用域为实时过滤，按钮退化为「清空」 */
    go: function () {
        if (this.scope === 'find') { LocFind.search(); return; }
        var inp = document.getElementById('locSearchInput');
        if (inp) inp.value = '';
        if (this.scope === 'tree') LocPage.search('');
        else LocPage.filterParts('');
    }
};

// ═══ 库位页内置出入库（直连 api.php?action=stock.change） ═══
var LocStock = {
    _part: null,
    open: function (info) {
        // onclick 中 JSON 以 &quot; 转义传入，这里统一还原为对象
        try { this._part = (typeof info === 'string') ? JSON.parse(info) : info; }
        catch (e) { this._part = info || {}; }
        var p = this._part;
        document.getElementById('lskName').textContent = p.model || '—';
        var noEl = document.getElementById('lskModel');
        noEl.textContent = p.no || ('#ID ' + p.id);
        document.getElementById('lskCur').textContent = p.stock;
        document.getElementById('lskQty').value = 1;
        document.getElementById('lskRemark').value = '';
        document.getElementById('lskQtyErr').classList.remove('show');
        document.getElementById('lskQty').classList.remove('input-invalid');
        document.getElementById('lskBtn').disabled = false;
        document.getElementById('lskType').value = 'manual_in';
        this.onTypeChange();
        document.getElementById('locStockModal').classList.add('open');
    },
    onTypeChange: function () {
        var t = document.getElementById('lskType').value;
        var q = document.getElementById('lskQty');
        var btn = document.getElementById('lskBtn');
        var sel = document.getElementById('lskType');
        sel.className = '';
        if (t === 'manual_out') { q.min = 1; q.value = 1; btn.className = 'btn btn-danger'; sel.className = 'sem-red'; }
        else if (t === 'adjust') { q.min = 0; q.value = parseInt(document.getElementById('lskCur').textContent, 10) || 0; btn.className = 'btn btn-warning'; sel.className = 'sem-amber'; }
        else { q.min = 1; q.value = 1; btn.className = 'btn btn-success'; }
        this.onQty();
    },
    step: function (d) {
        var q = document.getElementById('lskQty');
        var v = parseInt(q.value, 10) || 0;
        var t = document.getElementById('lskType').value;
        var min = t === 'adjust' ? 0 : 1;
        q.value = Math.max(min, v + d);
        this.onQty();
    },
    onQty: function () {
        var q = document.getElementById('lskQty');
        var err = document.getElementById('lskQtyErr');
        var btn = document.getElementById('lskBtn');
        var t = document.getElementById('lskType').value;
        var raw = q.value.trim();
        err.classList.remove('show');
        q.classList.remove('input-invalid');
        btn.disabled = false;
        if (raw === '') return;
        var n = parseInt(raw, 10);
        if (isNaN(n) || n < (t === 'adjust' ? 0 : 1)) {
            err.textContent = '数量无效';
            err.classList.add('show'); q.classList.add('input-invalid'); btn.disabled = true; return;
        }
        if (t === 'manual_out') {
            var cur = parseInt(document.getElementById('lskCur').textContent, 10) || 0;
            if (n > cur) {
                err.textContent = '库存不足，当前库存 ' + cur;
                err.classList.add('show'); q.classList.add('input-invalid'); btn.disabled = true;
            }
        }
    },
    _valid: function () {
        var t = document.getElementById('lskType').value;
        var n = parseInt(document.getElementById('lskQty').value, 10) || 0;
        var cur = parseInt(document.getElementById('lskCur').textContent, 10) || 0;
        if (t === 'manual_out' && n > cur) { LCSC.toast('库存不足，当前库存 ' + cur, 'error'); return false; }
        if (n < (t === 'adjust' ? 0 : 1)) { LCSC.toast('数量无效', 'error'); return false; }
        return true;
    },
    submit: function () {
        var self = this;
        if (!this._valid()) return;
        var t = document.getElementById('lskType').value;
        var n = parseInt(document.getElementById('lskQty').value, 10) || 0;
        var cur = parseInt(document.getElementById('lskCur').textContent, 10) || 0;
        var dangerous = (t === 'manual_out') || (t === 'adjust' && n < cur);
        if (!dangerous) { this._go(); return; }
        AdminUIConfirm({
            title: '操作确认',
            message: '确认执行该操作？库存将发生变动，不可撤销。',
            okText: '确认执行',
            onOk: function () { self._go(); }
        });
    },
    _go: function () {
        var btn = document.getElementById('lskBtn');
        btn.disabled = true;
        var orig = btn.textContent;
        btn.textContent = '提交中…';
        var t = document.getElementById('lskType').value;
        var n = document.getElementById('lskQty').value;
        var remark = document.getElementById('lskRemark').value.trim() || '库位操作';
        var payload = {
            action: 'stock.change',
            id: this._part.id,
            change_type: t,
            qty: n,
            remark: remark
        };
        // 团队版：入库强制携带有效单价（后端 isEnforceUnitPrice 校验）
        var costEl = document.getElementById('lskUnitCost');
        if (costEl && t === 'manual_in') {
            var cost = parseFloat(costEl.value);
            if (!(cost > 0)) {
                LCSC.toast((window._editionName || '当前版本') + '入库必须填写有效单价（大于0）', 'error');
                var ce = document.getElementById('lskCostErr');
                if (ce) { ce.textContent = '请填写大于 0 的单价'; ce.classList.add('show'); }
                return;
            }
            payload.unit_cost = cost;
        }
        LCSC.post('api.php', payload, function (data, msg) {
            LCSC.toast(msg, 'success');
            closeOverlay('locStockModal');
            LocPage.loadDetail(LocPage.selectedId);
        }, function (msg) {
            btn.disabled = false;
            btn.textContent = orig;
            LCSC.toast(msg, 'error');
        });
    }
};

// ═══ 通用事件：蒙层点击 / ESC 关闭（库位页局部注册） ═══
(function () {
    document.addEventListener('click', function (e) {
        var ov = e.target;
        if (ov && ov.classList && ov.classList.contains('overlay')) {
            ov.classList.remove('open');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.overlay.open').forEach(function (o) { o.classList.remove('open'); });
        }
    });
})();

// 页面加载后渲染
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { LocPage.render(); });
} else {
    LocPage.render();
}
