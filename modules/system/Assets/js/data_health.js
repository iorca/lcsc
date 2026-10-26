// 数据健康中心 — 页面脚本
(function () {
    'use strict';

    var cfg = window.HealthPageConfig || {};

    // ── 明细面板状态 ──
    var detailState = {
        key: '',
        page: 1,
        perPage: 20,
        fixable: false,
        fix: ''
    };

    function $(id) { return document.getElementById(id); }
    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* 团队版 iframe 内跳转需交父框架导航 */
    function navigate(url) {
        if (typeof window._iframeNavigate === 'function') {
            window._iframeNavigate(url);
        } else {
            location.href = url;
        }
    }


    function initGroupToggle() {
        qsa('[data-toggle-group]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.dh-group');
                if (group) group.classList.toggle('is-open');
            });
        });
    }


    function initCheckActions() {
        qsa('.dh-check').forEach(function (card) {
            card.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                e.stopPropagation();
                var action = btn.getAttribute('data-action');
                if (action === 'detail') {
                    openDetail(card.getAttribute('data-key'), 1);
                } else if (action === 'fix') {
                    runFix(card.getAttribute('data-fix'), card.getAttribute('data-label'));
                } else if (action === 'ignore') {
                    var on = card.getAttribute('data-ignored') !== '1';
                    runIgnore(card.getAttribute('data-key'), card.getAttribute('data-label'), on);
                }
            });
        });
    }

    /* 忽略/恢复「仅供参考」检测项 */
    function runIgnore(key, label, on) {
        LCSC.post('api.php?action=health.ignore', { key: key, on: on ? 1 : 0 }, function (data, msg) {
            LCSC.toast(msg || (on ? '已忽略' : '已恢复提醒'), 'success');
            setTimeout(function () { location.reload(); }, 400);
        }, function (msg) {
            LCSC.toast(msg || '操作失败', 'error');
        });
    }

    function initIgnoredToggle() {
        var cb = $('dhShowIgnored');
        if (!cb) return;
        var stored = '';
        try { stored = sessionStorage.getItem('dh_show_ignored') || ''; } catch (e) {}
        cb.checked = (stored === '1');
        applyIgnoredVisibility(cb.checked);
        cb.addEventListener('change', function () {
            try { sessionStorage.setItem('dh_show_ignored', cb.checked ? '1' : '0'); } catch (e) {}
            applyIgnoredVisibility(cb.checked);
        });
    }
    function applyIgnoredVisibility(show) {
        qsa('.dh-check[data-ignored="1"]').forEach(function (card) {
            card.classList.toggle('is-hidden', !show);
        });
    }


    function openDetail(key, page) {
        if (!key) return;
        detailState.key = key;
        detailState.page = page || 1;

        var panel = $('detailPanel');
        panel.classList.remove('is-hidden');
        $('detailBody').innerHTML = '<tr><td colspan="8" class="muted">加载中…</td></tr>';
        $('detailPager').innerHTML = '';
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        LCSC.get(
            'api.php?action=health.detail&key=' + encodeURIComponent(key)
            + '&page=' + detailState.page + '&per_page=' + detailState.perPage,
            function (data) {
                renderDetail(data);
            },
            function (msg) {
                $('detailBody').innerHTML = '<tr><td colspan="8" class="muted">' + esc(msg) + '</td></tr>';
            }
        );
    }

    function renderDetail(data) {
        var check = data.check || {};
        var labels = data.labels || {};
        var cols = data.columns || [];

        $('detailTitle').textContent = (check.icon || '') + ' ' + (check.label || '明细');
        $('detailHint').textContent = check.hint || '';

        detailState.fixable = !!check.fixable && !!cfg.canEdit;
        detailState.fix = check.fix || '';
        var fixBtn = $('detailFixBtn');
        if (detailState.fixable) {
            fixBtn.classList.remove('is-hidden');
            fixBtn.setAttribute('data-fix', detailState.fix);
        } else {
            fixBtn.classList.add('is-hidden');
        }

        // 表头
        $('detailHead').innerHTML = cols.map(function (c) {
            return '<th>' + esc(labels[c] || c) + '</th>';
        }).join('') + (detailState.key === 'bom_pending' ? '<th>操作</th>' : '');

        // 表体
        var items = data.items || [];
        if (items.length === 0) {
            $('detailBody').innerHTML = '<tr><td colspan="' + (cols.length + (detailState.key === 'bom_pending' ? 1 : 0)) + '">'
                + '<div class="empty-state"><div class="icon">✓</div>'
                + '<div class="es-desc">该项已全部处理完毕</div></div></td></tr>';
            $('detailPager').innerHTML = '';
            return;
        }

        $('detailBody').innerHTML = items.map(function (row) {
            var body = cols.map(function (c) {
                var v = row[c];
                if (v === null || v === undefined || v === '') return '<td class="muted">—</td>';
                if (c === 'id' && detailState.key !== 'bom_pending' && parseInt(v, 10) > 0) {
                    return '<td class="mono"><a href="javascript:;" class="dh-link" data-goto="' + parseInt(v, 10) + '">#'
                        + parseInt(v, 10) + '</a></td>';
                }
                if (c === 'stock') return '<td class="mono num">' + esc(v) + '</td>';
                if (c === 'model') return '<td class="mono">' + esc(v) + '</td>';
                return '<td>' + esc(v) + '</td>';
            }).join('');
            if (detailState.key === 'bom_pending') {
                var pid = parseInt(row.project_id, 10) || 0;
                body += '<td>' + (pid > 0
                    ? '<a href="javascript:;" class="dh-link dh-bom-goto" data-project="' + pid + '">去处理</a>'
                    : '<span class="muted">—</span>') + '</td>';
            }
            return '<tr>' + body + '</tr>';
        }).join('');

        // 物料 ID 可点击跳到物料列表
        qsa('.dh-link').forEach(function (a) {
            a.addEventListener('click', function () {
                if (a.classList.contains('dh-bom-goto')) {
                    navigate('index.php?v=bom&id=' + encodeURIComponent(a.getAttribute('data-project') || ''));
                    return;
                }
                navigate('index.php?v=parts&q=' + encodeURIComponent('#' + a.getAttribute('data-goto')));
            });
        });

        renderPager(data);
    }

    function renderPager(data) {
        var total = data.total || 0;
        var totalPage = data.total_page || 1;
        var page = data.page || 1;
        if (totalPage <= 1) {
            $('detailPager').innerHTML = total > 0
                ? '<span class="page-info">共 ' + total + ' 条</span>'
                : '';
            return;
        }

        var html = '<span class="page-info">共 ' + total + ' 条 / ' + totalPage + ' 页</span>';
        html += '<button class="page-btn' + (page <= 1 ? ' is-disabled' : '') + '" data-page="' + (page - 1) + '">上一页</button>';

        var start = Math.max(1, page - 2);
        var end = Math.min(totalPage, start + 4);
        start = Math.max(1, end - 4);
        for (var p = start; p <= end; p++) {
            html += '<button class="page-btn' + (p === page ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }
        html += '<button class="page-btn' + (page >= totalPage ? ' is-disabled' : '') + '" data-page="' + (page + 1) + '">下一页</button>';
        $('detailPager').innerHTML = html;

        qsa('#detailPager .page-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var target = parseInt(b.getAttribute('data-page'), 10);
                if (target >= 1 && target <= totalPage && !b.classList.contains('is-disabled')) {
                    openDetail(detailState.key, target);
                }
            });
        });
    }


    var fixLabels = {
        sync_stock: '按出入库流水重新回算库存数量',
        recalc_cost: '重新计算加权平均成本',
        cleanup_deprecated: '删除已确认无用的历史遗留字段'
    };

    var fixMessages = {
        sync_stock: '将按出入库流水重新回算库存数量。\n\n只做数字回算，不会删除或改动任何业务数据；完成后会自动刷新检测结果。',
        recalc_cost: '将重新计算所有物料的加权平均成本。\n\n只做数字回算，不会删除或改动任何业务数据；完成后会自动刷新检测结果。',
        cleanup_deprecated: '将删除台账标记为「可删除」、且已无任何代码使用的历史字段（如 parts.is_incomplete、parts.parameters_json）。\n\n⚠️ 此操作不可撤销（DDL 不随代码回滚），建议先备份数据库。确认执行？'
    };

    function runFix(fix, label) {
        if (!fix) return;
        var msg = fixMessages[fix] || ('将' + (fixLabels[fix] || fix) + '。\n\n完成后会自动刷新检测结果。');
        AdminUIConfirm({
            title: '确认执行修复',
            message: msg,
            okText: '执行修复',
            onOk: function () {
                doFix(fix);
            }
        });
    }

    function doFix(fix) {
        LCSC.post('api.php?action=health.fix', { fix: fix, limit: 500 }, function (data, msg) {
            LCSC.toast(msg || '修复完成', 'success');
            setTimeout(function () { location.reload(); }, 600);
        }, function (msg) {
            LCSC.toast(msg || '修复失败', 'error');
        });
    }


    function initRefresh() {
        var btn = $('btnRefresh');
        if (!btn) return;
        btn.addEventListener('click', function () {
            AdminUIConfirm({
                title: '重新检测数据质量',
                message: '将重新扫描全部物料并更新检测结果；物料较多时可能需要几秒。\n\n检测过程不会改动任何业务数据，完成后页面会自动刷新。',
                okText: '开始检测',
                danger: false,
                onOk: function () {
                    btn.disabled = true;
                    btn.textContent = '检测中…';
                    LCSC.post('api.php?action=health.refresh', {}, function (data, msg) {
                        LCSC.toast(msg || '检测完成', 'success');
                        setTimeout(function () { location.reload(); }, 500);
                    }, function (msg) {
                        LCSC.toast(msg || '检测失败', 'error');
                        btn.disabled = false;
                        btn.textContent = '重新检测';
                    });
                }
            });
        });
    }


    function initDetailClose() {
        var close = $('detailClose');
        if (close) {
            close.addEventListener('click', function () {
                $('detailPanel').classList.add('is-hidden');
            });
        }
        var fixBtn = $('detailFixBtn');
        if (fixBtn) {
            fixBtn.addEventListener('click', function () {
                runFix(fixBtn.getAttribute('data-fix'), '');
            });
        }
    }


    document.addEventListener('DOMContentLoaded', function () {
        initGroupToggle();
        initCheckActions();
        initIgnoredToggle();   // 「显示已忽略」开关（须在 initCheckActions 之后，互不影响）
        initDetailClose();
        initRefresh();
        initDeepLink();
    });


    /* 从 URL 的 `check` 参数直接展开对应检测项的明细 */
    function initDeepLink() {
        var key = new URLSearchParams(location.search).get('check');
        if (!key) return;

        var card = qsa('.dh-check').filter(function (el) {
            return el.getAttribute('data-key') === key;
        })[0];
        if (!card) return;

        var group = card.closest('.dh-group');
        if (group) group.classList.add('is-open');

        openDetail(key, 1);
    }
})();
