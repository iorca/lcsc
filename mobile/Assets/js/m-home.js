// mobile/Assets/js/m-home.js — 移动端「工作台」交互
(function () {
    'use strict';
    if (!window.MUI) return;

    var cfg = window.MobileHomeConfig || {};
    function el(id) { return document.getElementById(id); }

    function initStatEditor() {
        var grid = el('mStatGrid'), btn = el('mStatEditBtn'), editor = el('mStatEditor');
        if (!grid || !btn || !editor) return;

        var saveBtn   = el('mStatSave');
        var cancelBtn = el('mStatCancel');
        var checks    = editor.querySelectorAll('input[type=checkbox]');
        var max       = cfg.max || 6;

        var byKey = {};
        (cfg.catalog || []).forEach(function (c) { byKey[c.key] = c; });

        function renderGrid(keys) {
            var html = '';
            (keys || []).forEach(function (k) {
                var c = byKey[k];
                if (!c) return;
                html += '<div class="m-stat' + (c.tone ? ' m-stat-' + c.tone : '') + '">'
                      + '<div class="m-stat-value">' + MUI.esc(c.value) + '</div>'
                      + '<div class="m-stat-label">' + MUI.esc(c.label) + '</div>'
                      + (c.sub ? '<div class="m-stat-sub">' + MUI.esc(c.sub) + '</div>' : '')
                      + '</div>';
            });
            grid.innerHTML = html;
        }
        function setEditorOpen(open) {
            editor.classList.toggle('is-hidden', !open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        // 收集勾选项（按目录顺序），并封顶 max —— 达上限后未勾选项禁用
        function collected() {
            var n = 0, list = [];
            Array.prototype.forEach.call(checks, function (inp) {
                if (inp.checked) { n++; list.push(inp.value); }
            });
            Array.prototype.forEach.call(checks, function (inp) {
                if (!inp.checked) inp.disabled = (n >= max);
            });
            return list;
        }

        btn.addEventListener('click', function () {
            setEditorOpen(editor.classList.contains('is-hidden'));
        });
        Array.prototype.forEach.call(checks, function (inp) {
            inp.addEventListener('change', collected);
        });
        if (cancelBtn) cancelBtn.addEventListener('click', function () {
            var sel = cfg.selected || [];
            Array.prototype.forEach.call(checks, function (inp) {
                inp.checked = sel.indexOf(inp.value) !== -1;
            });
            collected();
            setEditorOpen(false);
        });
        if (saveBtn) saveBtn.addEventListener('click', function () {
            var list = collected();
            if (!list.length) { MUI.toast('请至少选择一项', 'warning'); return; }
            saveBtn.disabled = true;
            MUI.ajax('account.save_home_cards', { cards: JSON.stringify(list) }).then(function (res) {
                saveBtn.disabled = false;
                if (res && res.code === 0) {
                    var saved = (res.data && res.data.cards) || list;
                    cfg.selected = saved;
                    renderGrid(saved);
                    setEditorOpen(false);
                    MUI.toast(res.msg || '已保存', 'success');
                } else {
                    MUI.toast((res && res.msg) || '保存失败', 'error');
                }
            }).catch(function () {
                saveBtn.disabled = false;
                MUI.toast('网络错误，请重试', 'error');
            });
        });
        collected();
    }

    var chartsDone = false;
    var charts = [];

    function token(name, fallback) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }
    function withAlpha(color, a) {
        var c = (color || '').trim();
        if (c.charAt(0) === '#') {
            var hex = c.slice(1);
            if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            var n = parseInt(hex, 16);
            return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
        }
        return c;
    }
    function tooltipStyle() {
        return {
            backgroundColor: token('--surface', '#191c29'),
            titleColor: token('--text', '#dde3f0'),
            bodyColor: token('--text2', '#7a86a8'),
            borderColor: token('--border2', '#343857'),
            borderWidth: 1,
            padding: 10,
            boxWidth: 8,
            boxHeight: 8
        };
    }
    function destroyCharts() {
        charts.forEach(function (c) { if (c) c.destroy(); });
        charts = [];
    }
    function renderTrend() {
        var cv = el('mTrendChart'), card = el('mTrendCard');
        if (!cv || typeof Chart === 'undefined') return;
        var t = cfg.trend || { labels: [], 'in': [], out: [] };
        var inSum  = (t['in'] || []).reduce(function (a, b) { return a + b; }, 0);
        var outSum = (t.out || []).reduce(function (a, b) { return a + b; }, 0);
        // 无数据 → **整块卡片隐藏**（不留空框）；服务端已算好判据，这里只做兜底
        if (inSum === 0 && outSum === 0) {
            if (card) card.classList.add('is-hidden');
            return;
        }
        if (card) card.classList.remove('is-hidden');
        var text2 = token('--text2', '#7a86a8');
        var grid  = withAlpha(text2, 0.12);
        charts.push(new Chart(cv, {
            type: 'bar',
            data: {
                labels: t.labels,
                datasets: [
                    { label: '入库', data: t['in'],  backgroundColor: withAlpha(token('--green', '#22c55e'), 0.75), borderRadius: 3, maxBarThickness: 16 },
                    { label: '出库', data: t.out, backgroundColor: withAlpha(token('--red', '#ef4444'), 0.75),   borderRadius: 3, maxBarThickness: 16 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltipStyle() },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: text2, font: { size: 10 } } },
                    y: { grid: { color: grid }, ticks: { color: text2, font: { size: 10 }, precision: 0 }, beginAtZero: true }
                }
            }
        }));
    }
    function renderCatDist() {
        var cv = el('mCatChart'), card = el('mCatCard');
        if (!cv || typeof Chart === 'undefined') return;
        var list = cfg.catDist || [];
        var qtySum = list.reduce(function (a, r) { return a + (parseInt(r.qty, 10) || 0); }, 0);
        // 无库存 → 整块隐藏
        if (!list.length || qtySum === 0) {
            if (card) card.classList.add('is-hidden');
            return;
        }
        if (card) card.classList.remove('is-hidden');
        var text2 = token('--text2', '#7a86a8');
        charts.push(new Chart(cv, {
            type: 'bar',
            data: {
                labels: list.map(function (r) { return r.name; }),
                datasets: [{
                    data: list.map(function (r) { return r.qty; }),
                    backgroundColor: withAlpha(token('--accent', '#4f8ef7'), 0.8),
                    borderRadius: 3,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                // 竖式（纵向柱）：分类名在横轴上，柱自下而上 —— 窄屏比横向条更易读
                indexAxis: 'x',
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign(tooltipStyle(), {
                        callbacks: {
                            label: function (ctx) {
                                var row = list[ctx.dataIndex] || {};
                                return ' ' + ctx.label + '：' + ctx.parsed.y + ' 件 / ' + (row.kinds || 0) + ' 种';
                            }
                        }
                    })
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: text2, font: { size: 10 }, maxRotation: 0, autoSkip: false } },
                    y: { grid: { color: withAlpha(text2, 0.12) }, ticks: { color: text2, font: { size: 10 }, precision: 0 }, beginAtZero: true }
                }
            }
        }));
    }
    function renderCharts() {
        destroyCharts();
        renderTrend();
        renderCatDist();
    }
    function loadChart(cb) {
        if (window.Chart) { cb(); return; }
        var s = document.createElement('script');
        s.src = cfg.chartUrl || '../third_party/js/chart.umd.js';
        s.onload = cb;
        s.onerror = function () { /* 图表库加载失败：保持空态，不影响其余功能 */ };
        document.head.appendChild(s);
    }
    function ensureCharts() {
        if (chartsDone) return;
        chartsDone = true;
        loadChart(renderCharts);
    }

    function initPcHint() {
        var body = el('mCollapseBody');
        if (!body) return;
        function hit(node) {
            var item = node && node.closest ? node.closest('.m-todo-item[data-pc-hint]') : null;
            if (!item) return false;
            MUI.toast(item.getAttribute('data-pc-hint') || '请在电脑端处理');
            return true;
        }
        body.addEventListener('click', function (e) { hit(e.target); });
        body.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            if (hit(e.target)) e.preventDefault();
        });
    }

    function watchTheme() {
        if (!window.MutationObserver) return;
        var prev = document.documentElement.getAttribute('data-theme');
        new MutationObserver(function () {
            var now = document.documentElement.getAttribute('data-theme');
            if (now === prev) return;
            prev = now;
            if (chartsDone) setTimeout(renderCharts, 30);
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }
    ready(function () {
        initStatEditor();
        initPcHint();
        watchTheme();
        ensureCharts();
    });
})();
