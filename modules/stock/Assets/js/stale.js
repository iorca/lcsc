var Stale = {
    page: 1,
    defaultDays: 90,
    canCost: false,
    canEdit: false,
    lastIds: [],   // 当前页物料ID（全选用）
    view: 'list',  // list | location | category
    drill: null,   // 下钻过滤 {type:'loc'|'cat', key, label, gid}

    esc: function (s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    init: function (defaultDays, canCost, canEdit) {
        this.defaultDays = defaultDays;
        this.canCost = canCost;
        this.canEdit = !!canEdit;
        ['staleDays', 'staleMinDays', 'staleShowProcessed', 'staleSort'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', function () { Stale.load(1); });
        });
        // 视图切换（清单 / 按库位 / 按分类）
        var seg = document.getElementById('staleViewSeg');
        if (seg) {
            seg.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.stale-seg') : null;
                if (!btn) return;
                Stale.setView(btn.getAttribute('data-view'));
            });
        }
        this.load(1);
    },

    setView: function (view) {
        view = (view === 'location' || view === 'category') ? view : 'list';
        if (this.view === view) return;
        this.view = view;
        if (view !== 'list') this.clearDrill(true);   // 换聚合维度时清掉上一次下钻
        var seg = document.getElementById('staleViewSeg');
        if (seg) {
            seg.querySelectorAll('.stale-seg').forEach(function (b) {
                var on = b.getAttribute('data-view') === view;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        var section = document.querySelector('.check-section');
        if (section) section.classList.toggle('is-group-view', view !== 'list');
        this.load(1);
    },

    /* 聚合行下钻：带上过滤条件回到清单视图 */
    drillTo: function (type, key, label) {
        this.drill = { type: type, key: key, label: label };
        this.view = 'list';
        var seg = document.getElementById('staleViewSeg');
        if (seg) {
            seg.querySelectorAll('.stale-seg').forEach(function (b) {
                var on = b.getAttribute('data-view') === 'list';
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        var section = document.querySelector('.check-section');
        if (section) section.classList.remove('is-group-view');
        this.renderDrill();
        this.load(1);
    },

    clearDrill: function (silent) {
        this.drill = null;
        this.renderDrill();
        if (!silent) this.load(1);
    },

    renderDrill: function () {
        var box = document.getElementById('staleDrill');
        if (!box) return;
        if (!this.drill) { box.classList.add('is-hidden'); box.innerHTML = ''; return; }
        var d = this.drill;
        var title = (d.type === 'cat' ? '分类：' : '库位：') + this.esc(d.label);
        box.innerHTML = '已筛选 ' + title
            + '<button type="button" class="stale-drill-x" title="清除筛选" onclick="Stale.clearDrill()">✕</button>';
        box.classList.remove('is-hidden');
    },

    _params: function () {
        var p = {
            days: document.getElementById('staleDays').value,
            min_days: (document.getElementById('staleMinDays').value || 0),
            show_processed: document.getElementById('staleShowProcessed').checked ? '1' : '0',
            q: (document.getElementById('staleSearch').value || '').trim()
        };
        // 排序（值形如 "stale|asc"）
        var sortSel = document.getElementById('staleSort');
        if (sortSel && sortSel.value) {
            var parts = sortSel.value.split('|');
            p.sort = parts[0];
            p.dir = parts[1] || '';
        }
        if (this.drill) {
            if (this.drill.type === 'loc') p.loc = this.drill.key;
            else p.cat_key = this.drill.key;
        }
        return p;
    },

    _post: function (method, data, done) {
        var body = 'action=stock.' + method + '&_csrf=' + (window.LCSC ? LCSC.csrf : '');
        Object.keys(data || {}).forEach(function (k) { body += '&' + k + '=' + encodeURIComponent(data[k]); });
        LCSC.fetchJson('app/api/router.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (resp) {
            if (resp.code === 0) { if (done) done(resp.data || {}, resp.msg || 'ok'); }
            else LCSC.toast(resp.msg || '操作失败', 'error');
        }).catch(function (msg) { LCSC.toast(String(msg), 'error'); });
    },

    load: function (page) {
        this.page = page || 1;
        var p = this._params();
        var ld = document.querySelector('.stale-loading');
        if (ld) ld.classList.add('is-on');
        var wrap = document.getElementById('staleTableWrap');
        if (wrap) wrap.style.opacity = '0.5';

        var self = this;
        if (this.view === 'list') {
            p.page = this.page;
            p.per_page = 50;
            this._post('stale_list', p, function (data) {
                if (ld) ld.classList.remove('is-on');
                if (wrap) wrap.style.opacity = '1';
                Stale.render(data);
            });
        } else {
            p.by = this.view;
            this._post('stale_group', p, function (data) {
                if (ld) ld.classList.remove('is-on');
                if (wrap) wrap.style.opacity = '1';
                Stale.renderGroup(data);
            });
        }
    },

    // ── 分档统计（多久没动销的三档分布）──
    renderBuckets: function (buckets) {
        var box = document.getElementById('staleBuckets');
        if (!box) return;
        if (!buckets || !buckets.length) { box.innerHTML = ''; return; }
        var tone = { mid: 'sb-mid', long: 'sb-long', oldest: 'sb-oldest' };
        var html = '<div class="stale-bucket-head">积压分布</div>';
        buckets.forEach(function (b) {
            html += '<div class="stale-bucket ' + (tone[b.key] || '') + '">'
                + '<div class="sb-label">' + Stale.esc(b.label) + '</div>'
                + '<div class="sb-count">' + Number(b.count || 0).toLocaleString() + '<span class="sb-unit"> 种</span></div>'
                + (Stale.canCost && b.value !== null
                    ? '<div class="sb-val">¥' + Number(b.value).toFixed(2) + '</div>'
                    : '')
                + '</div>';
        });
        box.innerHTML = html;
    },

    render: function (data) {
        var wrap = document.getElementById('staleTableWrap');
        var summary = document.getElementById('staleSummary');
        var pager = document.getElementById('stalePager');
        if (!wrap) return;
        var items = data.items || [];
        this.lastIds = items.map(function (r) { return r.id; });

        // 汇总条
        var sv = '<div class="stale-chip"><span class="stale-chip-label">呆滞物料</span><span class="stale-chip-val">' + data.total + '</span></div>';
        if (data.can_cost && data.total_value !== null) {
            sv += '<div class="stale-chip"><span class="stale-chip-label">占用资产</span><span class="stale-chip-val">¥' + Number(data.total_value).toFixed(2) + '</span></div>';
        }
        sv += '<div class="stale-chip"><span class="stale-chip-label">判定天数</span><span class="stale-chip-val">' + data.days + '</span></div>';
        summary.innerHTML = sv;
        this.renderBuckets(data.buckets);

        // 表格（固定列宽，与盘点单列表同规格）
        if (!items.length) {
            wrap.innerHTML = '<div class="stale-empty"><div class="stale-empty-ico">🗄️</div><div>当前条件下无呆滞物料</div><div class="stale-empty-sub">可调整天数 / 严重度 / 显示已处理后刷新</div></div>';
            pager.innerHTML = '';
            return;
        }
        var html = '<table class="data-table stale-table"><colgroup>'
            + '<col class="stale-col-cb"><col class="stale-col-part"><col class="stale-col-loc"><col class="stale-col-num"><col class="stale-col-time"><col class="stale-col-num">'
            + (this.canCost ? '<col class="stale-col-money"><col class="stale-col-money">' : '')
            + '<col class="stale-col-status"><col class="stale-col-act"></colgroup>'
            + '<thead><tr>'
            + '<th class="ta-c"><input type="checkbox" id="staleCbAll" onchange="Stale.toggleAll(this.checked)"></th>'
            + '<th class="ta-l">物料</th><th>库位</th><th>库存</th><th>最后动销</th><th>呆滞天数</th>'
            + (this.canCost ? '<th>含税均价</th><th>资产价值</th>' : '')
            + '<th>状态</th><th class="ta-c">处理</th>'
            + '</tr></thead><tbody>';
        items.forEach(function (r) {
            var name = r.model || r.platform_part_no || LCSC.partCode(r.internal_id) || ('#' + r.id);
            var sub = [r.platform_part_no, r.brand].filter(Boolean).join(' · ');
            var proc = r.is_processed === 1;
            html += '<tr' + (proc ? ' class="stale-row-processed"' : '') + '>'
                + '<td class="ta-c">' + (proc ? '<span title="已处理">—</span>' : '<input type="checkbox" class="stale-row-cb" data-part="' + r.id + '">') + '</td>'
                + '<td class="stale-cell-part" title="' + Stale.esc(r.product_name || name) + '"><a href="index.php?v=parts&q=' + encodeURIComponent(r.model || r.platform_part_no || '') + '">' + Stale.esc(name) + '</a><div class="cs-cell-sub">' + Stale.esc(sub) + '</div></td>'
                + '<td class="ta-c">' + Stale.esc(r.location || '—') + '</td>'
                + '<td class="ta-c num">' + r.stock + '</td>'
                + '<td class="ta-c mono">' + Stale.esc(r.last_move_time) + '</td>'
                + '<td class="ta-c num"><span class="stale-days-badge' + (r.stale_days >= 180 ? ' stale-days-hot' : '') + '">' + r.stale_days + '</span></td>'
                + (Stale.canCost
                    ? '<td class="ta-r num mono">' + (r.avg_cost === null ? '—' : Number(r.avg_cost).toFixed(4)) + '</td>'
                      + '<td class="ta-r num mono">¥' + (r.asset_value === null ? '—' : Number(r.asset_value).toFixed(2)) + '</td>'
                    : '')
                + '<td class="ta-c">' + (proc ? '<span class="stale-badge-processed">已处理</span>' : '<span class="stale-badge-active">呆滞</span>') + '</td>'
                // 单条处理：标记 / 取消标记（与批量同一套后端动作，只是粒度到行）。
                // 用 data-* + 事件委托，不把 id 拼进 onclick 字面量
                + '<td class="ta-c">' + (Stale.canEdit
                    ? (proc
                        ? '<button type="button" class="btn btn-ghost btn-xs" data-unmark="' + r.id + '">取消标记</button>'
                        : '<button type="button" class="btn btn-ghost btn-xs" data-mark="' + r.id + '">标记已处理</button>')
                    : '<span class="stale-muted">—</span>')
                + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;

        var self = this;
        wrap.querySelectorAll('button[data-mark]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                self.markOne(parseInt(btn.getAttribute('data-mark'), 10));
            });
        });
        wrap.querySelectorAll('button[data-unmark]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                self.unmarkOne(parseInt(btn.getAttribute('data-unmark'), 10));
            });
        });

        var tp = data.total_page || 1;
        if (tp > 1) {
            var cur = data.page;
            var s = Math.max(1, cur - 2), e = Math.min(tp, cur + 2);
            var ph = '';
            var pbtn = function (p, label) {
                var active = (p === cur) ? 'btn-primary' : 'btn-ghost';
                var lab = label || p;
                return '<button class="btn ' + active + ' btn-sm" onclick="Stale.load(' + p + ')">' + lab + '</button>';
            };
            if (cur > 1) ph += pbtn(cur - 1, '‹');
            if (s > 1) ph += pbtn(1);
            if (s > 2) ph += '<span class="page-info is-tight">…</span>';
            for (var i = s; i <= e; i++) ph += pbtn(i);
            if (e < tp - 1) ph += '<span class="page-info is-tight">…</span>';
            if (e < tp) ph += pbtn(tp);
            if (cur < tp) ph += pbtn(cur + 1, '›');
            pager.innerHTML = ph;
        } else {
            pager.innerHTML = '';
        }
    },

    /* 聚合表渲染（按库位 / 按分类）：回答「积压压在哪里」 */
    renderGroup: function (data) {
        var wrap = document.getElementById('staleTableWrap');
        var pager = document.getElementById('stalePager');
        if (!wrap) return;
        if (pager) pager.innerHTML = '';
        var rows = (data && data.items) || [];
        if (!rows.length) {
            wrap.innerHTML = '<div class="stale-empty"><div class="stale-empty-ico">🗄️</div><div>当前条件下无呆滞物料</div><div class="stale-empty-sub">可调整天数 / 严重度 / 显示已处理后刷新</div></div>';
            return;
        }
        var isCat = (data.by === 'category');
        var total = 0;
        rows.forEach(function (r) { total += r.count; });
        var html = '<table class="data-table stale-table stale-group-table"><colgroup>'
            + '<col class="stale-col-gname"><col class="stale-col-gnum"><col class="stale-col-gnum">'
            + (data.can_cost ? '<col class="stale-col-money">' : '')
            + '<col class="stale-col-gbar"><col class="stale-col-gact"></colgroup>'
            + '<thead><tr>'
            + '<th class="ta-l">' + (isCat ? '分类' : '库位') + '</th>'
            + '<th class="ta-r">呆滞种类</th><th class="ta-r">库存数量</th>'
            + (data.can_cost ? '<th class="ta-r">占用资金</th>' : '')
            + '<th>占比</th><th class="ta-c">明细</th>'
            + '</tr></thead><tbody>';
        rows.forEach(function (r) {
            var pct = total > 0 ? (r.count / total * 100) : 0;
            html += '<tr>'
                + '<td class="ta-l stale-cell-part" title="' + Stale.esc(r.label) + '">' + Stale.esc(r.label) + '</td>'
                + '<td class="ta-r num">' + r.count + '</td>'
                + '<td class="ta-r num">' + r.qty + '</td>'
                + (data.can_cost ? '<td class="ta-r num mono">' + (r.value === null ? '—' : '¥' + Number(r.value).toFixed(2)) + '</td>' : '')
                + '<td><div class="stale-bar"><span class="stale-bar-fill" style="width:' + pct.toFixed(1) + '%"></span>'
                + '<em>' + pct.toFixed(1) + '%</em></div></td>'
                // 下钻参数走 data-*（esc 已转义双引号），点击时用事件委托读取 ——
                // 不把库位/分类名拼进 onclick 字面量：那是把数据当代码执行
                + '<td class="ta-c"><button type="button" class="btn btn-ghost btn-xs stale-drill-btn"'
                + ' data-type="' + (isCat ? 'cat' : 'loc') + '"'
                + ' data-key="' + Stale.esc(r.key) + '"'
                + ' data-label="' + Stale.esc(r.label) + '">查看明细</button></td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;

        var self = this;
        wrap.querySelectorAll('.stale-drill-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                self.drillTo(
                    btn.getAttribute('data-type'),
                    btn.getAttribute('data-key'),
                    btn.getAttribute('data-label')
                );
            });
        });
    },

    toggleAll: function (checked) {
        document.querySelectorAll('input.stale-row-cb').forEach(function (cb) { cb.checked = checked; });
    },

    _selectedIds: function () {
        return Array.prototype.slice.call(document.querySelectorAll('input.stale-row-cb:checked')).map(function (cb) {
            return parseInt(cb.getAttribute('data-part'), 10);
        });
    },

    markProcessed: function () {
        var ids = this._selectedIds();
        if (!ids.length) { LCSC.toast('请先勾选要标记的物料', 'warning'); return; }
        var self = this;
        AdminUIConfirm({
            title: '标记为已处理',
            message: '将把勾选的 ' + ids.length + ' 项标记为「呆滞已处理」。\n\n标记会写进物料备注前缀；物料一旦发生动销会自动清除。',
            okText: '确认标记',
            danger: false,
            onOk: function () {
                self._post('stale_mark', { part_ids: JSON.stringify(ids) }, function (d, msg) {
                    LCSC.toast(msg || ('已标记 ' + d.marked + ' 项'), 'success');
                    Stale.load(Stale.page);
                });
            }
        });
    },

    /* 单条标记（行内按钮） */
    markOne: function (id) {
        var self = this;
        AdminUIConfirm({
            title: '标记为已处理',
            message: '将把该物料标记为「呆滞已处理」，它会从默认清单里隐去（打开「显示已处理」仍可见）。\n\n物料发生动销后标记会自动清除。',
            okText: '确认标记',
            danger: false,
            onOk: function () {
                self._post('stale_mark', { part_ids: JSON.stringify([id]) }, function (d, msg) {
                    LCSC.toast(msg || ('已标记 ' + d.marked + ' 项'), 'success');
                    Stale.load(Stale.page);
                });
            }
        });
    },

    /* 单条取消标记（只摘掉备注里的前缀，不动备注正文） */
    unmarkOne: function (id) {
        var self = this;
        AdminUIConfirm({
            title: '取消已处理标记',
            message: '将去掉该物料备注里的「呆滞已处理」前缀，它会重新回到呆滞清单中。\n\n你写在备注里的内容会原样保留。',
            okText: '取消标记',
            danger: false,
            onOk: function () {
                self._post('stale_unmark', { part_ids: JSON.stringify([id]) }, function (d, msg) {
                    LCSC.toast(msg || ('已取消 ' + d.unmarked + ' 项标记'), 'success');
                    Stale.load(Stale.page);
                });
            }
        });
    },

    markAll: function () {
        var p = this._params();
        var self = this;
        var cnt = 0;
        this._post('stale_list', { days:p.days, min_days:p.min_days, show_processed:p.show_processed, q:p.q, page:1, per_page:1 }, function (data) {
            cnt = data.total || 0;
            if (!cnt) { LCSC.toast('当前条件下没有可标记的呆滞物料', 'warning'); return; }
            AdminUIConfirm({
                title: '全部标记为已处理',
                message: '将把当前筛选下的 ' + cnt + ' 项呆滞物料全部标记为「已处理」。\n\n标记会写进物料备注前缀，物料发生动销后自动清除。',
                okText: '确认全部标记',
                danger: false,
                onOk: function () {
                    self._post('stale_mark', { all: '1', days:p.days, min_days:p.min_days, show_processed:p.show_processed, q:p.q }, function (d, msg) {
                        LCSC.toast(msg || ('已标记 ' + d.marked + ' 项'), 'success');
                        Stale.load(1);
                    });
                }
            });
        });
    },

    exportCsv: function () {
        var p = this._params();
        var url = 'app/api/router.php?action=stock.stale_export&_csrf=' + LCSC.csrf
            + '&days=' + encodeURIComponent(p.days)
            + '&min_days=' + encodeURIComponent(p.min_days)
            + '&show_processed=' + p.show_processed
            + (p.loc ? '&loc=' + encodeURIComponent(p.loc) : '')
            + (p.cat_key ? '&cat_key=' + encodeURIComponent(p.cat_key) : '')
            + '&q=' + encodeURIComponent(p.q || '');
        window.open(url, '_blank');
    }
};

// ── 页面初始化──
(function () {
    function boot() {
        var cfg = window.StalePageConfig || {};
        Stale.init(cfg.defaultDays || 90, !!cfg.canCost, !!cfg.canEdit);
        // 回车触发搜索
        var box = document.getElementById('staleSearch');
        if (box) box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') Stale.load(1);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
