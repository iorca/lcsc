// mobile/Assets/js/m-search.js — 移动端「物料查询」交互
(function () {
    'use strict';

    var root = document.getElementById('mSearchRoot');
    if (!root || !window.MUI) return;

    var MUI = window.MUI;
    var esc = MUI.esc;
    var el  = function (id) { return document.getElementById(id); };
    var hide = function (node, h) { if (node) node.classList.toggle('is-hidden', !!h); };
    function parseJson(s) { try { return JSON.parse(s); } catch (e) { return null; } }

    var CFG = {
        canViewCost: root.getAttribute('data-can-view-cost') === '1',
        lowThr:      parseInt(root.getAttribute('data-low-threshold'), 10) || 10,
        catTops:     parseJson(root.getAttribute('data-cat-tops')) || [],
        catChildren: parseJson(root.getAttribute('data-cat-children')) || {},
        platforms:   parseJson(root.getAttribute('data-platforms')) || [],
        initQ:       root.getAttribute('data-init-q') || '',
        initFilter:  root.getAttribute('data-init-filter') || ''
    };
    // Chart.js 地址（详情抽屉的库存变化折线；懒加载时用）
    var CHART_URL = root.getAttribute('data-chart-url') || '';

    var PER_PAGE = 20;
    var state = { q: '', cat: '', page: 1, totalPage: 1, loading: false };
    var rowCache = {};      // part_id -> { row, cats }
    var logsCache = {};     // part_id -> logs（详情首次展开时取）

    // ── 筛选（后端 parts.list 已支持；排序固定 update_time desc，不提供入口）──
    var FILTER = { filter: '', plat: 0 };
    // 筛选抽屉的草稿态（应用前不生效，取消即丢弃）
    var filterDraft = { filter: '', plat: 0 };

    // 分类多选草稿态（已并入筛选抽屉；应用后才写入 state.cat）
    var catState = { top: [], sub: [], kw: '' };

    var SKEL_W = ['w-a', 'w-b', 'w-c'];
    var SKEL_W_SUB = ['w-d', 'w-e', 'w-f'];
    function renderSkeleton(rows) {
        var box = el('partList');
        if (!box) return;
        var html = '';
        for (var i = 0; i < rows; i++) {
            var w1 = SKEL_W[i % SKEL_W.length];
            var w2 = SKEL_W_SUB[i % SKEL_W_SUB.length];
            html += '<div class="m-skel-row">'
                 + '<div class="m-skel-main">'
                 +   '<div class="m-skel-line ' + w1 + '"></div>'
                 +   '<div class="m-skel-line is-sub ' + w2 + '"></div>'
                 + '</div>'
                 + '<div class="m-skel-badge"></div>'
                 + '</div>';
        }
        box.innerHTML = html;
    }


    function load(page) {
        if (state.loading) return;
        state.loading = true;
        state.page = Math.max(1, parseInt(page, 10) || 1);

        // 每页都重画骨架屏：分页是整页替换，不是追加
        renderSkeleton(5);

        var params = { page: state.page, per_page: PER_PAGE, sort: 'update_time', dir: 'desc' };
        if (state.q)      params.q = state.q;
        if (state.cat)    params.cat = state.cat;
        if (FILTER.filter)   params.filter = FILTER.filter;
        if (FILTER.plat > 0) params.plat = FILTER.plat;

        MUI.get('parts.list', params).then(function (res) {
            state.loading = false;
            if (!res || res.code !== 0) {
                state.totalPage = 1;
                renderPager();
                MUI.toast((res && res.msg) || '加载失败', 'error', {
                    label: '重试',
                    onClick: function () { load(state.page); }
                });
                showEmpty('加载失败，请稍后重试', 'alert');
                return;
            }
            var data = res.data || {};
            var list = data.list || [];
            state.totalPage = parseInt(data.total_page, 10) || 1;
            // 筛选后总页数可能变少：当前页越界 → 回第一页重查
            if (state.page > state.totalPage) { load(1); return; }

            el('partList').innerHTML = '';
            rowCache = {};
            logsCache = {};

            if (!list.length) {
                hide(el('partCount'), true);
                // 关键词过短（服务端对 <2 字的词不做匹配）→ 给出可执行的提示
                var tooShort = state.q !== '' && state.q.split(/\s+/).every(function (w) { return w.length < 2; });
                showEmpty(tooShort ? '关键词至少 2 个字符' : '没有匹配的物料', 'inbox');
                renderPager();
                return;
            }
            setCount(data.total);

            var frag = document.createDocumentFragment();
            for (var i = 0; i < list.length; i++) frag.appendChild(buildRow(list[i], data.cat_map || {}));
            el('partList').appendChild(frag);
            renderPager();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }).catch(function () {
            state.loading = false;
            state.totalPage = 1;
            renderPager();
            MUI.toast('网络错误，请重试', 'error', {
                label: '重试',
                onClick: function () { load(state.page); }
            });
            showEmpty('加载失败，请稍后重试', 'alert');
        });
    }

    /* 分页条（服务端分页，20 条/页）：上一页 / 第 x / y 页 / 下一页 */
    function renderPager() {
        var pager = el('partPager');
        if (!pager) return;
        if (state.totalPage <= 1) { pager.innerHTML = ''; pager.hidden = true; return; }
        pager.hidden = false;
        pager.innerHTML = '<button type="button" class="m-btn m-btn-sm m-btn--inline" data-part-page="prev"'
            + (state.page <= 1 ? ' disabled' : '') + '>上一页</button>'
            + '<span class="m-part-page-info">第 ' + state.page + ' / ' + state.totalPage + ' 页</span>'
            + '<button type="button" class="m-btn m-btn-sm m-btn--inline" data-part-page="next"'
            + (state.page >= state.totalPage ? ' disabled' : '') + '>下一页</button>';
    }

    function showEmpty(text, icon) {
        var list = el('partList');
        list.innerHTML = '<div class="m-card m-empty"><div class="m-empty-ico">'
                       + '<span class="m-ico m-ico--' + (icon || 'inbox') + '" aria-hidden="true"></span>'
                       + '</div><div class="m-empty-text">' + esc(text) + '</div></div>';
    }

    /* 结果计数（有结果才显示） */
    function setCount(total) {
        var c = el('partCount');
        if (!c) return;
        var n = parseInt(total, 10) || 0;
        if (n > 0) { c.textContent = '共 ' + n + ' 件物料'; hide(c, false); }
        else { hide(c, true); }
    }

    // ── 行渲染 ──
    function buildRow(p, catMap) {
        var pid = parseInt(p.id, 10) || 0;
        var stock = parseInt(p.stock, 10) || 0;
        var damaged = parseInt(p.damaged, 10) || 0;
        var thr = parseInt(p.low_stock_threshold, 10) || 0;
        var effThr = thr > 0 ? thr : CFG.lowThr;

        var stockCls = stock <= 0 ? 'is-zero' : (stock <= effThr ? 'is-low' : '');
        var subtitle = (p.product_name || '') !== '' ? p.product_name : '';
        var metaBits = [];
        if ((p.brand || '') !== '')   metaBits.push(esc(p.brand));
        if ((p.package || '') !== '') metaBits.push(esc(p.package));
        if ((p.location || '') !== '') metaBits.push('<span class="m-ico m-ico--pin" aria-hidden="true"></span>' + esc(p.location));
        var cats = (catMap[pid] || []).join('、');

        var wrap = document.createElement('div');
        wrap.className = 'm-card m-part-row';
        wrap.setAttribute('data-id', String(pid));
        wrap.innerHTML =
            '<div class="m-part-head">'
          +   '<div class="m-part-main">'
          +     '<div class="m-part-model">' + esc(p.model || p.platform_part_no || ('#' + pid)) + '</div>'
          +     (subtitle !== '' ? '<div class="m-part-name">' + esc(subtitle) + '</div>' : '')
          +     (metaBits.length ? '<div class="m-part-meta">' + metaBits.join(' · ') + '</div>' : '')
          +   '</div>'
          +   '<div class="m-part-side">'
          +     '<span class="m-part-stock ' + stockCls + '">' + esc(String(stock)) + '</span>'
          +     (damaged > 0 ? '<span class="m-part-bad">不良 ' + esc(String(damaged)) + '</span>' : '')
          +   '</div>'
          + '</div>'
          + (cats !== '' ? '<div class="m-part-cats">' + esc(cats) + '</div>' : '');

        rowCache[pid] = { row: p, cats: catMap[pid] || [] };
        return wrap;
    }

    function showDrawer(overlayId, drawerId, onClose, openerId) {
        MUI.drawer.open(overlayId, drawerId, onClose, openerId);
    }
    function hideDrawer(overlayId, drawerId) {
        MUI.drawer.close(overlayId, drawerId);
    }

    function openDetail(partId) {
        var cached = rowCache[partId];
        var body = el('partDetailBody');
        if (!cached || !body) return;

        var title = el('partDetailTitle');
        if (title) title.textContent = cached.row.model || cached.row.platform_part_no || '物料详情';

        // 每次打开先复位所有按需显隐的分区（内容可能与上次不同）
        ['remark-wrap', 'cost-wrap', 'chart-wrap', 'rel-wrap'].forEach(function (role) {
            hide(body.querySelector('[data-role="' + role + '"]'), true);
        });
        var buy = body.querySelector('[data-role="buy"]');
        hide(buy, true);
        if (buy) buy.removeAttribute('href');

        renderHero(body, cached);
        renderFacts(body, cached);
        renderKv(body, cached);
        renderRemarkBuy(body, cached.row);
        // 最近流水：首次打开按需取，之后用缓存
        var logsBox = body.querySelector('[data-role="logs"]');
        if (logsCache[partId]) {
            renderLogs(logsBox, logsCache[partId]);
        } else {
            if (logsBox) logsBox.innerHTML = '<div class="m-hint">加载中…</div>';
            MUI.get('stock.log_query', { part_id: partId, per_page: 5 }).then(function (res) {
                var items = (res && res.code === 0 && res.data && res.data.items) ? res.data.items : [];
                logsCache[partId] = items;
                if (logsBox) renderLogs(logsBox, items);
            }).catch(function () { if (logsBox) logsBox.innerHTML = '<div class="m-hint">流水加载失败</div>'; });
        }

        showDrawer('partDetailOverlay', 'partDetailDrawer', closeDetail, 'partList');

        // 趋势图 / 成本 / 关联信息：抽屉已打开后再异步补（首屏不等网络）
        loadDetailExtra(partId, cached);
    }

    function closeDetail() { hideDrawer('partDetailOverlay', 'partDetailDrawer'); }

    /* 抽屉的异步补充数据：库存变化折线 + 资产成本 + 关联信息 */
    function loadDetailExtra(partId, cached) {
        var body = el('partDetailBody');
        if (!body) return;

        var chartWrap = body.querySelector('[data-role="chart-wrap"]');
        var canvas = body.querySelector('[data-role="stock-chart"]');
        if (chartWrap && canvas) {
            MUI.get('stock.log_query', { part_id: partId, per_page: 60 }).then(function (res) {
                var items = (res && res.code === 0 && res.data && res.data.items) ? res.data.items : [];
                renderStockChart(chartWrap, canvas, items);
            }).catch(function () { hide(chartWrap, true); });
        }

        // 关联信息（替代料 / 关联标准物料）
        MUI.get('parts.edit_detail', { part_id: partId }).then(function (res) {
            var d = (res && res.code === 0 && res.data) ? res.data : null;
            renderRel(body, cached, d);
        }).catch(function () { renderRel(body, cached, null); });

        if (!CFG.canViewCost) return;
        MUI.get('parts.detail', { part_id: partId }).then(function (res) {
            if (!res || res.code !== 0) return;
            var d = res.data || {};
            var costWrap = body.querySelector('[data-role="cost-wrap"]');
            if (!costWrap) return;
            var total = parseFloat(d.total_asset) || 0;
            var latest = parseFloat(d.latest_cost) || 0;
            var tEl = body.querySelector('[data-role="cost-total"]');
            var lEl = body.querySelector('[data-role="cost-latest"]');
            if (tEl) tEl.textContent = '¥' + total.toFixed(2);
            if (lEl) lEl.textContent = latest > 0 ? ('¥' + latest.toFixed(4)) : '—';
            hide(costWrap, false);
        }).catch(function () { /* 成本取不到就整节不渲染 */ });
    }

    /* 库存变化折线图（倒序取回的流水反转成时间正序） */
    function renderStockChart(wrap, canvas, items) {
        if (!items || items.length < 2) { hide(wrap, true); return; }
        // items 是「按时间倒序」，反转成正序
        var pts = items.slice().reverse();
        var labels = [], values = [];
        for (var i = 0; i < pts.length; i++) {
            labels.push(String(pts[i].create_time || '').slice(5, 10));
            values.push(parseInt(pts[i].qty_after, 10) || 0);
        }
        hide(wrap, false);
        // Chart.js 按需懒加载（查询页原本不引图表库）
        ensureChart(function () {
            drawStockChart(canvas, labels, values);
        }, function () {
            // 图表库加载失败 → 退回数字摘要，不留空白框
            var box = wrap.querySelector('.m-pd-chart');
            if (box) box.innerHTML = '<div class="m-hint">库存区间 ' + values[0] + ' → ' + values[values.length - 1] + '</div>';
        });
    }

    var chartLoading = null;
    function ensureChart(onOk, onFail) {
        if (typeof Chart !== 'undefined') { onOk(); return; }
        if (chartLoading) { chartLoading.then(onOk).catch(onFail); return; }
        chartLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = CHART_URL;
            s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('chart load failed')); };
            document.head.appendChild(s);
        });
        chartLoading.then(onOk).catch(onFail);
    }

    /* 折线绘制（Chart.js 懒加载后调用） */
    function drawStockChart(canvas, labels, values) {
        // 同一块 canvas 只能挂一个 Chart 实例：详情抽屉关闭时不会销毁图表，
        var old = Chart.getChart(canvas);
        if (old) old.destroy();

        var ctx = canvas.getContext('2d');
        var grid = 'rgba(122,134,168,.15)';
        var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#4f8ef7';
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '库存',
                    data: values,
                    borderColor: accent,
                    backgroundColor: accent + '22',
                    fill: true,
                    tension: .3,
                    pointRadius: 2,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: '#7a86a8', font: { size: 9 }, maxTicksLimit: 6 } },
                    y: { grid: { color: grid }, ticks: { color: '#7a86a8', font: { size: 9 }, precision: 0 }, beginAtZero: true }
                }
            }
        });
    }

    /* Hero 快览：型号 / 内部编号 / 名称 / 分类 + 右侧大号库存 */
    function renderHero(box, cached) {
        var p = cached.row;
        var thr = parseInt(p.low_stock_threshold, 10) || CFG.lowThr;
        var stock = parseInt(p.stock, 10) || 0;
        var damaged = parseInt(p.damaged, 10) || 0;

        var mEl = box.querySelector('[data-role="hero-model"]');
        if (mEl) mEl.textContent = p.model || p.platform_part_no || '—';
        var cEl = box.querySelector('[data-role="hero-code"]');
        if (cEl) {
            var iid = parseInt(p.internal_id, 10) || 0;
            var code = '';
            if (iid > 0) {
                code = String(iid);
                while (code.length < 5) code = '0' + code;
                code = 'P' + code;
            }
            cEl.textContent = code;
            hide(cEl, code === '');
        }
        var nEl = box.querySelector('[data-role="hero-name"]');
        if (nEl) {
            nEl.textContent = p.product_name || '';
            hide(nEl, !p.product_name);
        }
        var tEl = box.querySelector('[data-role="hero-tags"]');
        if (tEl) {
            var tags = '';
            for (var i = 0; i < cached.cats.length; i++) {
                tags += '<span class="m-pd-hero-tag">' + esc(cached.cats[i]) + '</span>';
            }
            if (p.brand) tags += '<span class="m-pd-hero-tag">' + esc(p.brand) + '</span>';
            tEl.innerHTML = tags;
            hide(tEl, tags === '');
        }
        var sEl = box.querySelector('[data-role="hero-stock"]');
        if (sEl) {
            sEl.textContent = String(stock);
            sEl.className = 'm-pd-hero-num' + (stock <= 0 ? ' is-zero' : (stock < thr ? ' is-low' : ''));
        }
        var dWrap = box.querySelector('[data-role="hero-damaged-wrap"]');
        var dEl = box.querySelector('[data-role="hero-damaged"]');
        if (dEl) dEl.textContent = String(damaged);
        hide(dWrap, damaged <= 0);
    }

    /* 关键信息条：品牌 / 封装 / 库位 / 客户料号 / 编号（空的不占位） */
    function renderFacts(box, cached) {
        var p = cached.row;
        var facts = [
            ['品牌', p.brand, true],
            ['封装', p.package, true],
            ['库位', p.location, true],
            ['客户料号', p.customer_part_no, true],
            ['编号', p.platform_part_no, true]
        ];
        var html = '';
        for (var i = 0; i < facts.length; i++) {
            var v = String(facts[i][1] || '');
            if (v === '') continue;
            html += '<span><span class="m-pd-fact-k">' + esc(facts[i][0]) + '：</span>'
                  + '<span class="m-pd-fact-v' + (facts[i][2] ? ' is-mono' : '') + '">' + esc(v) + '</span></span>';
        }
        var el0 = box.querySelector('[data-role="facts"]');
        if (el0) { el0.innerHTML = html; hide(el0, html === ''); }
    }

    /* 扁平键值行（分节「基础信息」） */
    function pdKvRow(k, v, mono, accent) {
        return '<div class="m-pd-kv-row"><span class="m-pd-kv-k">' + esc(k) + '</span>'
             + '<span class="m-pd-kv-v' + (mono ? ' is-mono' : '') + (accent ? ' is-accent' : '') + '">'
             + esc(v) + '</span></div>';
    }

    function platformName(pid) {
        var list = CFG.platforms || [];
        if (list && !Array.isArray(list) && list.platforms) list = list.platforms;
        if (!Array.isArray(list)) return '';
        for (var i = 0; i < list.length; i++) {
            if (parseInt(list[i].id, 10) === parseInt(pid, 10)) {
                return String(list[i].name || list[i].code || '');
            }
        }
        return '';
    }

    function renderKv(box, cached) {
        var p = cached.row;
        var kv = box.querySelector('[data-role="kv"]');
        if (!kv) return;

        var thr = parseInt(p.low_stock_threshold, 10) || 0;
        var html = '';
        html += pdKvRow('平台', platformName(p.platform_id) || '—');
        html += pdKvRow('商品编号', p.platform_part_no || '—', true, true);
        if ((p.customer_part_no || '') !== '') html += pdKvRow('客户料号', p.customer_part_no, true);
        html += pdKvRow('型号', p.model || p.platform_part_no || '—', true);
        html += pdKvRow('商品名称', p.product_name || '—');
        html += pdKvRow('品牌', p.brand || '—');
        html += pdKvRow('封装', p.package || '—');
        if ((p.product_type || '') !== '') html += pdKvRow('物料类型', p.product_type);
        if ((p.unit || '') !== '') html += pdKvRow('单位', p.unit);
        if ((p.location || '') !== '') html += pdKvRow('库位', p.location, true);
        html += pdKvRow('分类', cached.cats.length ? cached.cats.join('、') : '未归类');
        html += pdKvRow('低库存阈值', thr > 0 ? String(thr) : ('（继承生效：' + CFG.lowThr + '）'));
        if (CFG.canViewCost) {
            var avg = parseFloat(p.avg_cost) || 0;
            var last = parseFloat(p.last_purchase_price) || 0;
            if (avg > 0) html += pdKvRow('库存均价', '¥' + avg.toFixed(4), true);
            if (last > 0) html += pdKvRow('上次采购价', '¥' + last.toFixed(4), true);
        }
        html += pdKvRow('最近更新', String(p.update_time || '').slice(0, 16) || '—', true);
        kv.innerHTML = html;
    }

    /* 关联信息分节：替代料 / 关联标准物料 */
    function renderRel(box, cached, detail) {
        var wrap = box.querySelector('[data-role="rel-wrap"]');
        var host = box.querySelector('[data-role="rel"]');
        if (!wrap || !host) return;

        var html = '';

        if (detail && detail.linked_part && detail.linked_part.model) {
            html += '<div class="m-pd-rel-group">'
                  + '<div class="m-pd-rel-k">参数参考自</div>'
                  + '<div class="m-pd-rel-item is-link"><span class="is-mono">' + esc(detail.linked_part.model) + '</span>'
                  + (detail.linked_part.product_name ? ('<span class="m-pd-rel-v">' + esc(detail.linked_part.product_name) + '</span>') : '')
                  + '</div></div>';
        }

        var alt = (detail && detail.alt_parts) ? detail.alt_parts : [];
        if (alt.length) {
            var altHtml = '';
            for (var i = 0; i < alt.length; i++) {
                var a = alt[i];
                altHtml += '<div class="m-pd-rel-item"><span class="is-mono">' + esc(a.model || a.platform_part_no || '—') + '</span>'
                         + (a.product_name ? ('<span class="m-pd-rel-v">' + esc(a.product_name) + '</span>') : '')
                         + '<span class="m-pd-rel-stock">库存 ' + (parseInt(a.stock, 10) || 0) + '</span></div>';
            }
            html += '<div class="m-pd-rel-group">'
                  + '<div class="m-pd-rel-k">替代料（' + alt.length + '）</div>' + altHtml + '</div>';
        }

        if (html === '') { hide(wrap, true); return; }
        host.innerHTML = html;
        hide(wrap, false);
    }

    /* 备注 / 采购链接（「参数」分节已按需求移除） */
    function renderRemarkBuy(box, p) {
        var remark = String(p.remark || '').trim();
        if (remark !== '') {
            hide(box.querySelector('[data-role="remark-wrap"]'), false);
            box.querySelector('[data-role="remark"]').textContent = remark;
        }
        var url = String(p.purchase_url || '').trim();
        if (url !== '' && /^https?:\/\//i.test(url)) {
            var a = box.querySelector('[data-role="buy"]');
            a.href = url;
            hide(a, false);
        }
    }

    /* 最近出入库：类型中文标签 + 时间 + 变化量 + 变动前后库存 */
    function renderLogs(box, items) {
        if (!box) return;
        if (!items || items.length === 0) {
            box.innerHTML = '<div class="m-hint">暂无出入库流水</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var qty = parseInt(it.qty_change, 10) || 0;
            var cls = qty > 0 ? 'is-in' : (qty < 0 ? 'is-out' : 'is-flat');
            var label = it.change_type_label || it.change_type || '—';
            var before = parseInt(it.qty_before, 10) || 0;
            var after = parseInt(it.qty_after, 10) || 0;
            var when = String(it.create_time || '').slice(0, 16);
            html += '<div class="m-part-log">'
                 + '<div class="m-part-log-main">'
                 +   '<span class="m-part-log-type ' + cls + '">' + esc(label) + '</span>'
                 +   '<span class="m-part-log-time">' + esc(when) + '</span>'
                 + '</div>'
                 + '<div class="m-part-log-side">'
                 +   '<span class="m-part-log-qty ' + cls + '">' + (qty > 0 ? '+' : '') + qty + '</span>'
                 +   '<span class="m-part-log-stock">' + before + ' → ' + after + '</span>'
                 + '</div>'
                 + '</div>';
        }
        box.innerHTML = html;
    }

    function renderFilterPlatforms() {
        var bar = el('filterPlatBar');
        if (!bar) return;
        var plats = CFG.platforms || [];
        if (plats && !Array.isArray(plats) && plats.platforms) plats = plats.platforms;
        if (!Array.isArray(plats)) plats = [];
        var html = '<button type="button" class="m-chip is-on" data-plat="0">全部</button>';
        for (var i = 0; i < plats.length; i++) {
            var p = plats[i];
            var id = parseInt(p.id, 10) || 0;
            if (id <= 0) continue;
            var name = p.name || p.code || ('#' + id);
            html += '<button type="button" class="m-chip" data-plat="' + id + '">' + esc(name) + '</button>';
        }
        bar.innerHTML = html;
    }

    /* 单选组：同一容器内只允许一个 is-on */
    function pickOne(bar, attr, value) {
        if (!bar) return;
        Array.prototype.forEach.call(bar.querySelectorAll('.m-chip'), function (b) {
            b.classList.toggle('is-on', (b.getAttribute(attr) || '') === value);
        });
    }

    /* 把已生效的筛选态回填到抽屉控件（打开时调用） */
    function seedFilter() {
        filterDraft.filter = FILTER.filter;
        filterDraft.plat   = FILTER.plat;

        pickOne(el('filterStockBar'), 'data-filter', filterDraft.filter);
        pickOne(el('filterPlatBar'), 'data-plat', String(filterDraft.plat));
        // 分类多选与筛选同抽屉：打开时把 state.cat 回填到草稿态
        seedCat();
        renderCatDrawer();
    }

    /* 生效筛选数（工具条角标） */
    function filterCount() {
        var n = 0;
        if (state.cat) n++;
        if (FILTER.filter) n++;
        if (FILTER.plat > 0) n++;
        return n;
    }

    function syncFilterBadge() {
        var badge = el('mFilterBadge');
        if (!badge) return;
        var n = filterCount();
        badge.textContent = String(n);
        hide(badge, n === 0);
    }

    function bindFilterDrawer() {
        if (!el('filterDrawer')) return;

        var ov = el('filterOverlay'), closeBtn = el('filterClose');
        if (ov) ov.addEventListener('click', closeFilter);
        if (closeBtn) closeBtn.addEventListener('click', closeFilter);

        var stockBar = el('filterStockBar');
        if (stockBar) stockBar.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-chip') : null;
            if (!b) return;
            filterDraft.filter = b.getAttribute('data-filter') || '';
            pickOne(stockBar, 'data-filter', filterDraft.filter);
        });

        var platBar = el('filterPlatBar');
        if (platBar) platBar.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-chip') : null;
            if (!b) return;
            filterDraft.plat = parseInt(b.getAttribute('data-plat'), 10) || 0;
            pickOne(platBar, 'data-plat', String(filterDraft.plat));
        });

        var reset = el('filterReset');
        if (reset) reset.addEventListener('click', function () {
            // 重置 = 立即生效并重查（不走「应用」）：先改 FILTER 再回填控件
            FILTER.filter = '';
            FILTER.plat = 0;
            state.cat = '';          // 分类多选同一抽屉，重置时一起清空
            seedFilter();
            syncFilterBadge();
            closeFilter();
            load(1);
        });

        var cancel = el('filterCancel');
        if (cancel) cancel.addEventListener('click', closeFilter);

        var apply = el('filterApply');
        if (apply) apply.addEventListener('click', function () {
            FILTER.filter = filterDraft.filter;
            FILTER.plat   = filterDraft.plat;
            // 分类多选与其它筛选同一次「应用」生效
            state.cat = catTokens().join(',');
            syncFilterBadge();
            closeFilter();
            load(1);
        });
    }

    function openFilter() {
        seedFilter();
        showDrawer('filterOverlay', 'filterDrawer', closeFilter, 'mFilterBtn');
    }
    function closeFilter() { hideDrawer('filterOverlay', 'filterDrawer'); }

    function seedCat() {
        catState.top = [];
        catState.sub = [];
        catState.kw = '';
        var topIds = {};
        (CFG.catTops || []).forEach(function (t) { topIds[t.id] = true; });
        String(state.cat || '').split(',').forEach(function (t) {
            t = t.trim();
            if (t === '-1') { catState.top.push(-1); return; }
            if (!/^\d+$/.test(t)) return;
            var id = parseInt(t, 10);
            if (topIds[id]) catState.top.push(id);
            else catState.sub.push(id);
        });
        var s = el('catSearch');
        if (s) s.value = '';
    }

    function renderCatDrawer() {
        renderCatTop();
        renderCatSub();
        updateCatClear();
    }

    /* 一级横滑（选中一级会清空二级，与 1.1.0 一致） */
    function renderCatTop() {
        var box = el('catTopScroll');
        if (!box) return;
        var kw = catState.kw;
        var html = '';
        (CFG.catTops || []).forEach(function (t) {
            if (kw && String(t.name).toLowerCase().indexOf(kw) < 0) return;
            var on = catState.top.indexOf(t.id) >= 0 ? ' is-on' : '';
            html += '<button type="button" class="m-cat-top-tag' + on + '" data-top="' + t.id + '">'
                  + esc(t.name) + (t.cnt > 0 ? '<span class="m-cat-top-cnt">' + t.cnt + '</span>' : '')
                  + '</button>';
        });
        if (!kw || '未归类'.indexOf(kw) >= 0) {
            var onU = catState.top.indexOf(-1) >= 0 ? ' is-on' : '';
            html += '<button type="button" class="m-cat-top-tag' + onU + '" data-top="-1">未归类</button>';
        }
        box.innerHTML = html;
    }

    /* 二级纵列：选中一级则取其子级；未选一级则铺全部子级；无子级时给空态 */
    function collectCatSubs() {
        var subs = [];
        if (catState.top.length === 0) {
            Object.keys(CFG.catChildren).forEach(function (k) {
                subs = subs.concat(CFG.catChildren[k] || []);
            });
        } else {
            catState.top.forEach(function (tid) {
                if (tid === -1) return;
                subs = subs.concat(CFG.catChildren[String(tid)] || []);
            });
        }
        if (catState.kw) {
            subs = subs.filter(function (s) { return String(s.name).toLowerCase().indexOf(catState.kw) >= 0; });
        }
        return subs;
    }

    function renderCatSub() {
        var box = el('catSubList');
        if (!box) return;
        var subs = collectCatSubs();
        if (!subs.length) {
            box.innerHTML = '<div class="m-cat-sub-empty">'
                + (catState.top.indexOf(-1) >= 0 ? '已选「未归类」，可直接应用'
                   : (catState.top.length ? '该大类下无子分类' : '暂无分类'))
                + '</div>';
            return;
        }
        var html = '';
        subs.forEach(function (s) {
            var on = catState.sub.indexOf(s.id) >= 0;
            html += '<label class="m-cat-sub-item' + (on ? ' is-on' : '') + '" data-sub="' + s.id + '">'
                  + '<input type="checkbox" class="m-cat-sub-cb"' + (on ? ' checked' : '') + ' tabindex="-1">'
                  + '<span class="m-cat-sub-name">' + esc(s.name) + '</span>'
                  + (s.cnt > 0 ? '<span class="m-cat-sub-cnt">' + s.cnt + '</span>' : '')
                  + '</label>';
        });
        box.innerHTML = html;
    }

    function updateCatClear() {
        var btn = el('catClear');
        if (btn) btn.disabled = (catState.top.length === 0 && catState.sub.length === 0);
    }

    function catTokens() {
        var tokens = catState.sub.length > 0 ? catState.sub.slice() : catState.top.slice();
        if (catState.sub.length > 0 && catState.top.indexOf(-1) >= 0 && tokens.indexOf(-1) < 0) tokens.push(-1);
        return tokens;
    }

    /* 分类多选控件（位于筛选抽屉内；只改草稿态，应用/重置按钮统一提交） */
    function bindCatControls() {
        if (!el('catTopScroll')) return;

        var topBox = el('catTopScroll');
        if (topBox) topBox.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-cat-top-tag') : null;
            if (!b) return;
            var id = parseInt(b.getAttribute('data-top'), 10);
            if (isNaN(id)) return;
            var i = catState.top.indexOf(id);
            if (i >= 0) catState.top.splice(i, 1);
            else catState.top.push(id);
            catState.sub = [];           // 切换一级 → 清空二级选中
            renderCatDrawer();
        });

        var subBox = el('catSubList');
        if (subBox) subBox.addEventListener('change', function (e) {
            var cb = e.target.closest ? e.target.closest('.m-cat-sub-cb') : null;
            if (!cb) return;
            var item = cb.closest('.m-cat-sub-item');
            var id = parseInt(item ? item.getAttribute('data-sub') : '', 10);
            if (isNaN(id)) return;
            var i = catState.sub.indexOf(id);
            if (cb.checked && i < 0) catState.sub.push(id);
            else if (!cb.checked && i >= 0) catState.sub.splice(i, 1);
            item.classList.toggle('is-on', cb.checked);
            updateCatClear();
        });

        var search = el('catSearch');
        if (search) search.addEventListener('input', function () {
            catState.kw = search.value.trim().toLowerCase();
            renderCatTop();
            renderCatSub();
        });

        var clear = el('catClear');
        if (clear) clear.addEventListener('click', function () {
            // 只清草稿；点「应用」后才真正清空 state.cat
            catState.top = []; catState.sub = [];
            renderCatDrawer();
        });

        var selAll = el('catSelectAll');
        if (selAll) selAll.addEventListener('click', function () {
            catState.sub = collectCatSubs().map(function (s) { return s.id; });
            renderCatSub();
            updateCatClear();
        });
        var invert = el('catInvert');
        if (invert) invert.addEventListener('click', function () {
            var all = collectCatSubs().map(function (s) { return s.id; });
            catState.sub = all.filter(function (id) { return catState.sub.indexOf(id) < 0; });
            renderCatSub();
            updateCatClear();
        });

    }

    function bindSearch() {
        var input = el('partSearch');
        if (!input) return;
        var timer = null;
        var run = function () {
            state.q = input.value.trim();
            load(1);
        };
        input.addEventListener('input', function () {
            syncClearVisible();
            clearTimeout(timer);
            timer = setTimeout(run, 300);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(); }
        });
    }

    /* 清除按钮：有内容才显示，点按即清空并重查 */
    function syncClearVisible() {
        var wrap = el('searchWrap'), input = el('partSearch');
        if (wrap && input) wrap.classList.toggle('has-value', input.value !== '');
    }

    function bindClear() {
        var input = el('partSearch'), clear = el('searchClear');
        if (!input || !clear) return;
        clear.addEventListener('click', function () {
            input.value = '';
            state.q = '';
            syncClearVisible();
            input.focus();
            load(1);
        });
    }

    // ── 初始化 ──
    bindCatControls();
    bindFilterDrawer();
    bindSearch();
    bindClear();
    syncClearVisible();
    renderFilterPlatforms();

    if (CFG.initFilter) {
        FILTER.filter = CFG.initFilter;
        pickOne(el('filterStockBar'), 'data-filter', FILTER.filter);
    }
    if (CFG.initQ) state.q = CFG.initQ;
    syncFilterBadge();

    var filterBtn = el('mFilterBtn');
    if (filterBtn) filterBtn.addEventListener('click', openFilter);

    // 详情抽屉：点列表行打开；遮罩 / 关闭钮 / Esc 关闭
    el('partList').addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.m-part-row') : null;
        if (!row) return;
        openDetail(parseInt(row.getAttribute('data-id'), 10));
    });
    var pdOverlay = el('partDetailOverlay');
    if (pdOverlay) pdOverlay.addEventListener('click', closeDetail);
    var pdClose = el('partDetailClose');
    if (pdClose) pdClose.addEventListener('click', closeDetail);

    var pager = el('partPager');
    if (pager) pager.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-part-page]') : null;
        if (!b || b.disabled) return;
        load(state.page + (b.getAttribute('data-part-page') === 'prev' ? -1 : 1));
    });

    load(1);   // 首屏载入（无条件查一次，空关键词=列全部）
})();
