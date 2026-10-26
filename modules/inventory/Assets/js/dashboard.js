// dashboard.js — 团队版工作台首页交互
(function () {
    'use strict';

    var cfg = window.DashboardPageConfig || {};

    // ── 站内导航：统一交给父框架（iframe 防嵌套防线3）──
    // 卡片/按钮一律用 data-nav 声明目标，JS 只做事件委托，
    // 因此视图里没有一处内联 onclick。
    function navigate(url) {
        if (!url) return;
        if (window._iframeNavigate) window._iframeNavigate(url);
        else location.href = url;
    }

    function bindNav() {
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-nav]');
            if (!el) return;
            // 卡片内含链接时优先走链接自身行为
            if (e.target.closest('a')) return;
            e.preventDefault();
            navigate(el.getAttribute('data-nav'));
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var el = e.target.closest('[data-nav][role="button"]');
            if (!el) return;
            e.preventDefault();
            navigate(el.getAttribute('data-nav'));
        });
    }

    function fillMeters() {
        document.querySelectorAll('.wb-meter-fill[data-pct]').forEach(function (el) {
            var pct = parseFloat(el.getAttribute('data-pct')) || 0;
            el.style.width = Math.max(0, Math.min(100, pct)) + '%';
        });
    }

    // ── 工作台时钟：每 30 秒对齐一次 ──
    function startClock() {
        var el = document.getElementById('wbClock');
        if (!el) return;
        function tick() {
            var d = new Date();
            el.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        }
        tick();
        var timer = setInterval(tick, 30000);
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (timer) { clearInterval(timer); timer = null; }
            } else if (!timer) {
                tick();
                timer = setInterval(tick, 30000);
            }
        });
    }

    // ── 欢迎/公告轻提示：顶部中间、自动消失（登录欢迎 + 公告，仅首次）──
    function showNotices() {
        var notices = cfg.notices || [];
        if (!notices.length) return;
        var i = 0;
        function next() {
            if (i >= notices.length) return;
            var n = notices[i++];
            var text = n && n.content ? n.content : '';
            if (!text) return next();
            if (typeof LCSC !== 'undefined' && LCSC.toast) {
                LCSC.toast(text, 'info', 4500);
                setTimeout(next, 4800);
            }
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', next);
        else next();
    }

    // ── 图表：主题联动（暗色下重绘），读 token 而非硬编码色 ──
    var charts = [];

    function token(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (v && v.trim()) || fallback;
    }

    function withAlpha(color, alpha) {
        var c = String(color).trim();
        if (c.charAt(0) === '#') {
            var hex = c.slice(1);
            if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            var n = parseInt(hex, 16);
            return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
        }
        return c;
    }

    function destroyCharts() {
        charts.forEach(function (c) { if (c) c.destroy(); });
        charts = [];
    }

    function baseScales() {
        var text2 = token('--text2', '#7a86a8');
        var grid = withAlpha(text2, 0.12);
        return {
            x: { grid: { color: grid }, ticks: { color: text2, font: { size: 10 }, maxTicksLimit: 8 } },
            y: { grid: { color: grid }, ticks: { color: text2, font: { size: 10 }, precision: 0 }, beginAtZero: true }
        };
    }

    function renderTrend() {
        var cv = document.getElementById('wbTrendChart');
        var fallback = document.getElementById('wbTrendFallback');
        if (!cv || typeof Chart === 'undefined') {
            if (fallback) fallback.hidden = false;
            return;
        }
        var t = cfg.trend || { labels: [], in: [], out: [] };
        var inSum = (t['in'] || []).reduce(function (a, b) { return a + b; }, 0);
        var outSum = (t['out'] || []).reduce(function (a, b) { return a + b; }, 0);
        if (inSum === 0 && outSum === 0) {
            cv.hidden = true;
            if (fallback) fallback.hidden = false;
            return;
        }
        var green = token('--green', '#22c55e');
        var red = token('--red', '#ef4444');
        var text2 = token('--text2', '#7a86a8');
        var surface = token('--surface', '#191c29');
        charts.push(new Chart(cv, {
            type: 'bar',
            data: {
                labels: t.labels,
                datasets: [
                    { label: '入库', data: t['in'], backgroundColor: withAlpha(green, 0.75), borderRadius: 3, maxBarThickness: 18 },
                    { label: '出库', data: t['out'], backgroundColor: withAlpha(red, 0.75), borderRadius: 3, maxBarThickness: 18 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: surface,
                        titleColor: token('--text', '#dde3f0'),
                        bodyColor: text2,
                        borderColor: token('--border2', '#343857'),
                        borderWidth: 1,
                        padding: 10,
                        displayColors: true,
                        boxWidth: 8,
                        boxHeight: 8
                    }
                },
                scales: baseScales()
            }
        }));
    }

    var CAT_TYPE_KEY = 'wb_cat_chart_type';
    var catType = 'vbar';
    try {
        var savedCatType = localStorage.getItem(CAT_TYPE_KEY);
        if (savedCatType === 'hbar' || savedCatType === 'vbar') catType = savedCatType;
    } catch (e) { /* 隐私模式禁用存储时回落默认柱形 */ }

    /* 切换按钮高亮同步到 catType（首屏与点击后共用） */
    function syncCatTypeButtons() {
        var seg = document.getElementById('wbCatSeg');
        if (!seg) return;
        seg.querySelectorAll('[data-cat-type]').forEach(function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-cat-type') === catType);
        });
    }

    function renderCatDist() {
        var cv = document.getElementById('wbCatChart');
        if (!cv || typeof Chart === 'undefined') return;
        var list = cfg.catDist || [];
        if (!list.length) return;
        var type = catType;
        var text2 = token('--text2', '#7a86a8');
        var surface = token('--surface', '#191c29');
        charts.push(new Chart(cv, {
            type: 'bar',
            data: {
                labels: list.map(function (r) { return r.name; }),
                datasets: [{
                    data: list.map(function (r) { return r.qty; }),
                    backgroundColor: withAlpha(token('--accent', '#4f8ef7'), 0.8),
                    borderColor: withAlpha(token('--accent', '#4f8ef7'), 0.8),
                    borderWidth: 0,
                    hoverOffset: 6,
                    borderRadius: 3,
                    maxBarThickness: 22
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: type === 'hbar' ? 'y' : 'x',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: surface,
                        titleColor: token('--text', '#dde3f0'),
                        bodyColor: text2,
                        borderColor: token('--border2', '#343857'),
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label: function (ctx) {
                                var row = list[ctx.dataIndex] || {};
                                return ' ' + ctx.label + '：' + ctx.parsed + ' 件 / ' + (row.kinds || 0) + ' 种';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { color: withAlpha(text2, 0.12) }, ticks: { color: text2, font: { size: 10 }, precision: 0 }, beginAtZero: true },
                    y: { grid: { display: false }, ticks: { color: text2, font: { size: 11 } } }
                }
            }
        }));
    }

    /* 库存结构图的形态切换按钮 */
    function bindCatTypeToggle() {
        var seg = document.getElementById('wbCatSeg');
        if (!seg) return;
        seg.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-cat-type]');
            if (!btn || btn.classList.contains('is-on')) return;
            catType = btn.getAttribute('data-cat-type');
            try { localStorage.setItem(CAT_TYPE_KEY, catType); } catch (e) {}
            syncCatTypeButtons();
            // canvas 上不能直接重画（Chart 实例会叠加），必须销毁后重建。
            var cv = document.getElementById('wbCatChart');
            if (cv && typeof Chart !== 'undefined') {
                var old = Chart.getChart(cv);
                if (old) {
                    old.destroy();
                    var i = charts.indexOf(old);
                    if (i >= 0) charts.splice(i, 1);
                }
            }
            renderCatDist();
        });
    }

    function renderCharts() {
        destroyCharts();
        renderTrend();
        renderCatDist();
    }

    function bindThemeRerender() {
        // 主题切换由主框架广播（lcsc:theme），图表颜色取自 CSS 变量，需重绘
        window.addEventListener('message', function (ev) {
            var d = ev.data || {};
            if (d.type !== 'lcsc:theme' || !d.theme) return;
            if (document.documentElement.getAttribute('data-theme') === d.theme) return;
            setTimeout(renderCharts, 30);
        });
    }

    // ── 天气：悬停出速览卡片，点击开详细窗口 ──
    var WB_HOVER_DELAY = 180;
    var wbHoverTimer = null;
    var wbPopup = null;
    var wbOpenTimer = null;

    function wbReadData() {
        var el = document.querySelector('.wb-weather.is-live');
        if (!el) return null;
        var raw = el.getAttribute('data-weather');
        if (!raw) return null;
        try { return JSON.parse(raw); } catch (e) { return null; }
    }

    // ── 异步补齐：天气 + 一言 ──
    function loadExternal() {
        var box = document.getElementById('wbWeatherBox');
        var txt = document.getElementById('wbWeatherText');
        var slog = document.getElementById('wbSloganText');

        function settle(node) {
            if (node) node.classList.remove('is-loading');
        }

        LCSC.get('app/api/router.php?action=external.wb', function (data) {
            if (slog) {
                settle(slog);
                if (data && data.slogan) slog.textContent = data.slogan;
            }

            var w = data && data.weather;
            if (w && w.display === false) {
                if (box) {
                    settle(box);
                    box.classList.remove('is-live');
                    box.style.display = 'none';
                    var sep = box.previousElementSibling;
                    if (sep && sep.classList.contains('wb-dot-sep')) sep.style.display = 'none';
                }
                return;
            }
            if (!w || !txt) return;
            txt.textContent = w.text || '';

            if (!w.live) {
                if (box) { settle(box); box.classList.remove('is-live'); }
                return;
            }
            if (box) {
                box.setAttribute('data-weather', JSON.stringify({
                    city: w.city || '',
                    summary: w.text || '',
                    icon: w.icon || '',
                    temp: w.temp || '',
                    text: w.text || '',
                    quick: w.summary || {},
                    detail: w.detail || {},
                    advice: w.advice || {},
                    source: w.source || '',
                    at: w.at || ''
                }));
                settle(box);
                box.classList.add('is-live');
            }
        }, function () {
            if (txt) txt.textContent = '天气暂不可用';
            if (slog) { settle(slog); slog.textContent = ''; }
            if (box) { settle(box); box.classList.remove('is-live'); }
        });
    }

    function wbEnsurePopup() {
        if (wbPopup) return wbPopup;
        wbPopup = document.createElement('div');
        wbPopup.className = 'wb-wpop';
        wbPopup.setAttribute('role', 'tooltip');
        document.body.appendChild(wbPopup);

        // 事件委托：卡片内部按钮与关闭钮由容器统一处理
        wbPopup.addEventListener('click', function (e) {
            if (e.target.closest('[data-wb-close]')) wbClose();
            // 点击卡片本体 =升级为详细窗口（符合「点一下看到更多」的直觉）
            else if (e.target.closest('[data-wb-detail]')) wbDetail();
        });
        wbPopup.addEventListener('mouseenter', function () {
            if (wbHoverTimer) { clearTimeout(wbHoverTimer); wbHoverTimer = null; }
        });
        wbPopup.addEventListener('mouseleave', function () {
            if (wbPopup.classList.contains('is-detail')) return;  // 详情态不自动关
            wbScheduleClose();
        });
        return wbPopup;
    }

    function wbRowsHtml(d, isDetail) {
        var rows = '';
        if (d.city) {
            rows += '<div class="wb-wpop-city">' + wbEsc(d.city) + '</div>';
        }
        rows += '<div class="wb-wpop-now">'
              + '<span class="wb-wpop-ico">' + wbEsc(d.icon || '') + '</span>'
              + '<span class="wb-wpop-temp">' + wbEsc(d.temp || '') + '</span>'
              + '<span class="wb-wpop-text">' + wbEsc(d.text || '') + '</span>'
              + '</div>';
        // 速览只铺指标网格；详情态额外加一段「生活指数」
        rows += wbGridHtml(isDetail ? (d.detail || {}) : (d.quick || {}));
        if (isDetail) {
            var adv = d.advice || {};
            var advKeys = Object.keys(adv);
            if (advKeys.length) {
                rows += '<div class="wb-wpop-adv">' + wbEsc(advKeys.map(function (k) {
                    return k + ' ' + adv[k];
                }).join('　')) + '</div>';
            }
        }
        return rows;
    }

    function wbGridHtml(obj) {
        var keys = Object.keys(obj || {});
        if (!keys.length) return '';
        var rows = '<div class="wb-wpop-grid">';
        keys.forEach(function (k) {
            rows += '<div class="wb-wpop-cell"><span class="k">' + wbEsc(k) + '</span>'
                  + '<span class="v">' + wbEsc(obj[k]) + '</span></div>';
        });
        return rows + '</div>';
    }

    function wbFootHtml(d, isDetail) {
        var names = { 'uapis': 'uapis.cn', 'wttr': 'wttr.in', 'open-meteo': 'Open-Meteo' };
        var src = names[d.source] || d.source || '—';
        var at = (d.at || '').replace('T', ' ').slice(0, 16);
        var left = '<span class="wb-wpop-src">' + wbEsc(src) + (at ? ' · ' + wbEsc(at) : '') + '</span>';
        var right = isDetail
            ? '<button type="button" class="wb-wpop-close" data-wb-close>关闭</button>'
            : '<button type="button" class="wb-wpop-more" data-wb-detail>详细</button>';
        return '<div class="wb-wpop-foot">' + left + right + '</div>';
    }

    function wbEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* 打开详细窗口。 */
    function wbDetail() {
        wbShow(true);
    }

    /* 打开悬浮卡片。isDetail=true 时为详细窗口（居中长条）。 */
    function wbShow(isDetail) {
        var d = wbReadData();
        if (!d) return;
        var pop = wbEnsurePopup();
        pop.innerHTML = wbRowsHtml(d, isDetail) + wbFootHtml(d, isDetail);
        pop.classList.toggle('is-detail', !!isDetail);
        pop.classList.add('is-open');

        // 先显示再量尺寸 —— 元素有真实尺寸后才能做「不超出视口」的修正
        var pw = pop.offsetWidth;
        var ph = pop.offsetHeight;
        var left, top;

        if (isDetail) {
            // 详情态：视口居中。指标十余项，贴着触发器的小浮层放不下，
            // 锚定反而要额外处理四边越界 —— 直接居中最省事也最稳。
            left = Math.max(8, (window.innerWidth - pw) / 2);
            top = Math.max(8, (window.innerHeight - ph) / 2);
        } else {
            var trig = document.querySelector('.wb-weather.is-live');
            var rect = trig ? trig.getBoundingClientRect() : { left: 0, top: 0, right: 0, bottom: 0 };
            left = rect.left;
            top = rect.bottom + 8;                    // 默认在下方
            // 下方空间不够就翻到上方
            if (rect.bottom + ph + 12 > window.innerHeight && rect.top - ph - 8 > 0) {
                top = rect.top - ph - 8;
            }
            if (rect.left + pw > window.innerWidth - 12) {
                left = Math.max(8, window.innerWidth - pw - 12);
            }
            top = Math.max(8, top);
        }

        pop.style.left = left + 'px';
        pop.style.top = top + 'px';

        if (isDetail) {
            document.body.classList.add('wb-wpop-locked');
            var c = pop.querySelector('.wb-wpop-close');
            if (c) c.focus();
        }
    }

    function wbClose() {
        if (!wbPopup) return;
        wbPopup.classList.remove('is-open', 'is-detail');
        document.body.classList.remove('wb-wpop-locked');
    }

    function wbScheduleClose() {
        if (wbHoverTimer) clearTimeout(wbHoverTimer);
        wbHoverTimer = setTimeout(function () {
            if (!wbPopup || !wbPopup.classList.contains('is-detail')) wbClose();
        }, WB_HOVER_DELAY);
    }

    window.wbWeatherHover = function (enter) {
        if (enter) {
            if (wbOpenTimer) { clearTimeout(wbOpenTimer); wbOpenTimer = null; }
            if (wbHoverTimer) { clearTimeout(wbHoverTimer); wbHoverTimer = null; }
            wbOpenTimer = setTimeout(function () { wbOpenTimer = null; wbShow(false); }, WB_HOVER_DELAY);
        } else {
            wbScheduleClose();
        }
    };

    window.wbWeatherOpen = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (wbOpenTimer) { clearTimeout(wbOpenTimer); wbOpenTimer = null; }
        if (wbHoverTimer) { clearTimeout(wbHoverTimer); wbHoverTimer = null; }
        wbShow(true);
    };

    // Esc 关闭详情窗；点击浮层外部（遮罩区域）也关 —— 与项目其它浮层一致
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') wbClose();
    });
    document.addEventListener('click', function (e) {
        if (!wbPopup || !wbPopup.classList.contains('is-detail')) return;
        var r = wbPopup.getBoundingClientRect();
        var inside = e.clientX >= r.left && e.clientX <= r.right
                  && e.clientY >= r.top && e.clientY <= r.bottom;
        if (inside) return;
        wbClose();
    });
    // 主题切换后浮层里的硬编码色需跟随 → 直接关掉（重开即取新 token）
    window.addEventListener('message', function (ev) {
        var d = ev.data || {};
        if (d.type === 'lcsc:theme') wbClose();
    });

    // ── 启动 ──
    function boot() {
        bindNav();
        fillMeters();
        startClock();
        showNotices();
        bindThemeRerender();
        bindCatTypeToggle();
        syncCatTypeButtons();
        renderCharts();
        // 天气 / 一言：首屏不阻塞，页面就绪后再补齐
        loadExternal();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();