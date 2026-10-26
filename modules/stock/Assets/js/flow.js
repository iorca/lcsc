var FlowCenter = {
    cfg: window.FlowCenterConfig || {},
    busy: false,
    busyReview: false,

    init: function () {
        var self = this;

        // 内部链接（导航 / 统计卡 / 分页）：捕获阶段处理，先于框架的 <a> 拦截
        document.addEventListener('click', function (e) {
            var clearBtn = e.target && e.target.closest ? e.target.closest('#logClearBtn') : null;
            if (clearBtn) {
                e.preventDefault();
                e.stopPropagation();
                var box = document.getElementById('logSearchInput');
                if (box) box.value = '';
                clearBtn.style.display = 'none';
                self.go(self.withParams(location.search, { view: 'log', q: '', page: 1 }), { push: true, focus: true });
                return;
            }

            var a = e.target && e.target.closest ? e.target.closest('a[data-flow]') : null;
            if (!a) return;
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            e.stopPropagation();
            self.go(a.getAttribute('href'), { push: true, focus: true });
        }, true);

        // 筛选表单提交
        document.addEventListener('submit', function (e) {
            var f = e.target && e.target.closest ? e.target.closest('form[data-flow-filter]') : null;
            if (!f) return;
            e.preventDefault();
            e.stopPropagation();
            self.go(self.formUrl(f), { push: true, focus: true });
        }, true);

        document.addEventListener('change', function (e) {
            var sel = e.target && e.target.closest ? e.target.closest('select[data-flow-perpage]') : null;
            if (!sel) return;
            var cookie = sel.getAttribute('data-cookie') || '';
            if (cookie) {
                document.cookie = cookie + '=' + sel.value + ';max-age=2592000;path=/';
                try { localStorage.setItem(cookie, sel.value); } catch (err) {}
            }
            self.go(self.withParams(location.search, { per_page: sel.value, page: 1 }), { push: true, focus: true });
        }, true);

        document.addEventListener('click', function (e) {
            if (!e.target || !e.target.closest) return;
            var one = e.target.closest('[data-review-id]');
            var all = e.target.closest('#flowReviewAllBtn');
            if (!one && !all) return;
            e.preventDefault();
            e.stopPropagation();

            var ids;
            if (one) {
                var id = parseInt(one.getAttribute('data-review-id'), 10) || 0;
                ids = id > 0 ? [id] : [];
            } else {
                ids = [];
                document.querySelectorAll('#flowRegion [data-review-id]').forEach(function (b) {
                    var v = parseInt(b.getAttribute('data-review-id'), 10) || 0;
                    if (v > 0) ids.push(v);
                });
                if (!ids.length) { LCSC.toast('本页没有待复核的撤销流水'); return; }
            }
            if (!ids.length) return;
            self.submitReview(ids, all || one);
        }, true);

        // 回车：页码跳转输入框 / 操作日志搜索框
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var t = e.target;
            if (!t || !t.getAttribute) return;

            if (t.hasAttribute('data-flow-jump')) {
                e.preventDefault();
                e.stopPropagation();
                var base = t.getAttribute('data-flow-base') || location.search;
                var max  = parseInt(t.getAttribute('max'), 10) || 1;
                var p    = parseInt(t.value, 10);
                if (isNaN(p) || p < 1) {
                    t.value = '';
                    if (window.LCSC && LCSC.toast) LCSC.toast('请输入有效页码', 'warning');
                    return;
                }
                if (p > max) p = max;
                t.value = p;
                self.go(self.withParams(base, { page: p }), { push: true, focus: true });
                return;
            }

            if (t.id === 'logSearchInput') {
                e.preventDefault();
                e.stopPropagation();
                self.go(self.withParams(location.search, { view: 'log', q: t.value.trim(), page: 1 }), { push: true, focus: true });
            }
        }, true);

        // 浏览器前进 / 后退 → 局部刷新（不整页重载）
        window.addEventListener('popstate', function () {
            self.go(location.search ? ('index.php' + location.search) : 'index.php?v=stock', { push: false });
        });
    },

    go: function (url, opts) {
        opts = opts || {};
        var region = document.getElementById('flowRegion');
        if (!region || !url || this.busy) return;

        var qs = this.query(url);
        if (!qs.toString()) return;

        this.busy = true;
        LCSC.fragmentRefresh(region, this.cfg.api + '&' + qs.toString(), {
            busyKey: 'flowRegion',
            onDone: function (html, d) {
                FlowCenter.applyState(d);
                if (opts.push !== false) {
                    try { history.pushState({ flow: 1 }, '', 'index.php?' + FlowCenter.pageQuery(qs)); } catch (e) {}
                }
                FlowCenter.afterSwap(!!opts.focus);
            },
            onFail: function (err) {
                LCSC.toast('加载失败：' + ((err && err.message) ? err.message : String(err)), 'error');
            }
        }).then(function () {
            FlowCenter.busy = false;
        });
    },

    submitReview: function (ids, trigger) {
        if (this.busyReview) return;
        this.busyReview = true;

        var btn = (trigger && trigger.tagName === 'BUTTON') ? trigger : null;
        if (btn) btn.disabled = true;

        var self = this;
        var url = 'api.php?action=stock.review_undo';

        LCSC.post(url, { ids: JSON.stringify(ids) }, function (data) {
            self.busyReview = false;
            if (btn) btn.disabled = false;
            var n = (data && typeof data.reviewed === 'number') ? data.reviewed : ids.length;
            LCSC.toast(n > 0 ? ('已复核 ' + n + ' 条撤销流水') : '所选流水无需复核', n > 0 ? 'success' : 'warning');
            self.go(location.search, { push: false });
        }, function (msg) {
            self.busyReview = false;
            if (btn) btn.disabled = false;
            LCSC.toast(msg || '复核失败，请重试', 'error');
        });
    },

    /* 解析查询串 → 仅保留业务参数（去掉路由/导出等页面级参数） */
    query: function (url) {
        var s = String(url || '');
        var i = s.indexOf('?');
        s = i >= 0 ? s.slice(i + 1) : s;
        s = s.replace(/#.*$/, '');
        var p = new URLSearchParams(s);
        ['v', 'export', 'partial', 'tab_mode', 'flash', 'ajax', '_csrf'].forEach(function (k) { p.delete(k); });
        ['part_id', 'cat_id', 'plat_id'].forEach(function (k) { if (p.get(k) === '0' || p.get(k) === '') p.delete(k); });
        ['model', 'keyword', 'date_from', 'date_to', 'q', 'per_page', 'page', 'tab', 'view'].forEach(function (k) {
            if (p.get(k) === '') p.delete(k);
        });
        return p;
    },

    pageQuery: function (qs) {
        var p = new URLSearchParams(qs);
        p.set('v', 'stock');
        var mode = new URLSearchParams(location.search).get('tab_mode');
        if (mode) p.set('tab_mode', mode);
        return p.toString();
    },

    /* 表单 → 页面 URL */
    formUrl: function (form) {
        var p = new URLSearchParams(new FormData(form));
        return 'index.php?' + p.toString();
    },

    /* 在既有查询串上覆盖若干参数 */
    withParams: function (base, extra) {
        var p = this.query(base);
        Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); });
        return 'index.php?' + p.toString();
    },

    /* 同步导航/统计卡高亮态（服务端返回的 view/tab 为准） */
    applyState: function (d) {
        var view = d.view || 'business';
        var tab  = d.tab || '';

        document.querySelectorAll('.flow-tab1').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-view') === view);
        });
        var sub = document.getElementById('flowSubNav');
        if (sub) sub.classList.toggle('is-hidden', view !== 'business');
        document.querySelectorAll('.flow-tab2').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-tab') === tab);
        });
        document.querySelectorAll('#scStats .sc-stat').forEach(function (a) {
            a.classList.toggle('filter-active', !!tab && a.getAttribute('data-tab') === tab);
        });

        var fin = document.querySelector('.flow-tab1[data-view="finance"]');
        if (fin) fin.style.display = d.can_finance ? '' : 'none';
    },

    /* 内容区替换后的收尾：重挂日志视图交互 + 需要时把内容区滚回视野 */
    afterSwap: function (focus) {
        if (typeof window.initLogSearchBox === 'function') window.initLogSearchBox();
        if (typeof window.updateSelectedCount === 'function') window.updateSelectedCount();
        if (window.OrderInfo && typeof window.OrderInfo.onRegionSwap === 'function') {
            window.OrderInfo.onRegionSwap();
        }

        if (!focus) return;
        var region = document.getElementById('flowRegion');
        var nav    = document.getElementById('flowNav');
        if (!region) return;
        var top = (nav ? nav.getBoundingClientRect().bottom : region.getBoundingClientRect().top) - 8;
        if (top < 0) window.scrollTo({ top: window.scrollY + top, behavior: 'smooth' });
    }
};

window.FlowCenter = FlowCenter;
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { FlowCenter.init(); });
} else {
    FlowCenter.init();
}

window.OrderInfo = (function () {
    var api = 'api.php?action=stock.';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function toast(msg) {
        if (typeof window.LCSC && typeof window.LCSC.toast === 'function') window.LCSC.toast(msg);
        else if (typeof window.AdminUIConfirm === 'function') window.AdminUIConfirm(msg);
        else if (window.console) console.warn(msg);
    }

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('open');
    }
    function closeModal(el) {
        var ov = el && el.closest ? el.closest('.overlay') : null;
        if (ov) ov.classList.remove('open');
    }

    // ── 详情弹窗 ──
    function showDetail(orderNo) {
        if (!orderNo) return;
        var body = $('#orderDetailBody');
        if (!body) return;
        body.innerHTML = '<div class="order-detail-loading">加载中…</div>';
        openModal('orderDetailModal');

        // 用项目规范封装 LCSC.get（自动带 AJAX 标识与登录失效处理）
        LCSC.get(api + 'order_detail&order_no=' + encodeURIComponent(orderNo), function (data) {
            renderDetail(body, data || {});
        }, function (msg) {
            body.innerHTML = '<div class="empty-state">' + escapeHtml(msg || '加载失败') + '</div>';
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var GROUP_TITLE = { order: '订单信息', logistics: '物流信息', custom: '自定义字段' };

    function renderDetail(body, data) {
        var items = data.items || [];
        if (!items.length) {
            body.innerHTML = '<div class="empty-state">该订单暂无扩展信息</div>';
            return;
        }
        var groups = { order: [], logistics: [], custom: [] };
        items.forEach(function (it) {
            var g = groups[it.group] ? it.group : 'order';
            groups[g].push(it);
        });

        var html = '';
        ['order', 'logistics', 'custom'].forEach(function (g) {
            if (!groups[g].length) return;
            html += '<div class="order-detail-group"><div class="order-detail-group-title">' +
                escapeHtml(GROUP_TITLE[g]) + '</div><div class="order-detail-grid">';
            groups[g].forEach(function (it) {
                var v = (it.value === '' || it.value == null) ? '—' : escapeHtml(it.value);
                html += '<div class="order-detail-item">' +
                    '<span class="order-detail-label">' + escapeHtml(it.label) + '</span>' +
                    '<span class="order-detail-value">' + v + '</span></div>';
            });
            html += '</div></div>';
        });

        if (data.updated_time) {
            html += '<div class="order-detail-foot">最后更新：' +
                escapeHtml(String(data.updated_time).slice(0, 16)) + '</div>';
        }
        body.innerHTML = html;
    }

    // ── 列设置弹窗 ──
    var colDefs = {};
    var colAlways = ['order_no'];

    function readConfig() {
        var box = $('#orderColsList');
        if (!box) return null;
        try {
            colDefs = JSON.parse(box.getAttribute('data-defs') || '{}');
        } catch (e) { colDefs = {}; }
        try {
            colAlways = JSON.parse(box.getAttribute('data-always') || '["order_no"]');
        } catch (e2) { colAlways = ['order_no']; }
        return colDefs;
    }

    function currentlyVisible() {
        // 从当前表格表头反推已显示的列
        var heads = $$('#orderTable thead th');
        var keys = Object.keys(colDefs);
        var vis = [];
        heads.forEach(function (th, i) {
            var label = (th.textContent || '').trim();
            keys.forEach(function (k) {
                if (colDefs[k] === label && vis.indexOf(k) === -1) vis.push(k);
            });
        });
        return vis.length ? vis : colAlways.slice();
    }

    function buildColsList() {
        var box = $('#orderColsList');
        if (!box) return;
        var defs = readConfig() || {};
        var vis = currentlyVisible();
        var keys = Object.keys(defs);

        box.innerHTML = keys.map(function (k) {
            var on = vis.indexOf(k) !== -1;
            var always = colAlways.indexOf(k) !== -1;
            return '<label class="order-cols-item">' +
                '<input type="checkbox" value="' + escapeHtml(k) + '"' + (on ? ' checked' : '') +
                (always ? ' disabled' : '') + '>' +
                '<span>' + escapeHtml(defs[k]) + '</span>' +
                (always ? '<em class="order-cols-lock">始终显示</em>' : '') +
                '</label>';
        }).join('');
    }

    function openColsModal() {
        buildColsList();
        openModal('orderColsModal');
    }

    function saveCols() {
        var picked = $$('#orderColsList input[type=checkbox]:checked').map(function (i) { return i.value; });
        colAlways.forEach(function (k) { if (picked.indexOf(k) === -1) picked.push(k); });
        if (!picked.length) { toast('至少保留一列'); return; }

        var btn = $('#orderColsSave');

        LCSC.post(api + 'order_columns_save', { cols: JSON.stringify(picked) }, function () {
            toast('显示列已保存');
            closeModal(btn);
            if (window.FlowCenter) FlowCenter.go(location.search, { push: false });
        }, function (msg) {
            toast(msg || '保存失败，请重试');
        });
    }

    // ── 事件绑定（委托到 document，内容区替换后自动生效）──
    function bind() {
        if (bind._done) return;
        bind._done = true;

        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('.order-no-link') : null;
            if (link) {
                e.preventDefault();
                e.stopPropagation();
                showDetail(link.getAttribute('data-order-no') || '');
                return;
            }
            if (e.target.closest && e.target.closest('#orderColsBtn')) { openColsModal(); return; }
            if (e.target.closest && e.target.closest('#orderColsSave')) { saveCols(); return; }
            if (e.target.closest && e.target.closest('#orderColsAll')) {
                $$('#orderColsList input[type=checkbox]').forEach(function (i) { i.checked = true; });
                return;
            }
            if (e.target.closest && e.target.closest('#orderColsNone')) {
                $$('#orderColsList input[type=checkbox]').forEach(function (i) {
                    i.checked = colAlways.indexOf(i.value) !== -1;
                });
                return;
            }
            // 点击遮罩空白处关闭弹窗
            if (e.target.classList && e.target.classList.contains('overlay')) {
                e.target.classList.remove('open');
            }
        });

        // 订单号支持键盘触发（role=button + tabindex）
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var link = e.target.closest ? e.target.closest('.order-no-link') : null;
            if (link) {
                e.preventDefault();
                showDetail(link.getAttribute('data-order-no') || '');
            }
        });
    }

    return {
        init: function () {
            bind();
            readConfig();
        },
        onRegionSwap: function () { readConfig(); },
        showDetail: showDetail,
        openColsModal: openColsModal
    };
})();

// 首屏与局部刷新后都要初始化
(function () {
    function boot() { if (window.OrderInfo) window.OrderInfo.init(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
