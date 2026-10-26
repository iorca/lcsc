// monitor.js — 系统监控 Tab 交互
(function () {
    'use strict';

    function qs(id) { return document.getElementById(id); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }


    var dashBuilt = false;

    function buildDash() {
        if (dashBuilt) return;
        var dash = qs('monitorViewDash');
        if (!dash || dash.dataset.built === '1') { dashBuilt = true; return; }
        dash.dataset.built = '1';
        dashBuilt = true;
    }

    function applyView(v) {
        var listEl = qs('monitorViewList');
        var dashEl = qs('monitorViewDash');
        if (!listEl || !dashEl) return;
        if (v === 'dash') buildDash();
        listEl.classList.toggle('is-hidden', v === 'dash');
        dashEl.classList.toggle('is-hidden', v !== 'dash');
        qsa('.mon-view-btn').forEach(function (b) {
            var on = b.getAttribute('data-view') === v;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function initViewSwitch() {
        var btns = qsa('.mon-view-btn');
        if (!btns.length) return;
        btns.forEach(function (b) {
            b.setAttribute('role', 'tab');
            b.addEventListener('click', function () {
                var v = b.getAttribute('data-view');
                try { localStorage.setItem('admin_monitor_view', v); } catch (e) {}
                applyView(v);
            });
        });
        var saved = 'list';
        try { saved = localStorage.getItem('admin_monitor_view') || 'list'; } catch (e) {}
        applyView(saved);
    }


    var batchMode = false;

    function setCellVisible(on) {
        qsa('#thLogCheckbox, #thLogAction').forEach(function (el) {
            el.classList.toggle('is-hidden', !on);
        });
        qsa('.tdLogCheckbox, .tdLogAction').forEach(function (el) {
            el.classList.toggle('is-hidden', !on);
        });
        var delBtn = qs('logBatchDeleteBtn');
        if (delBtn) delBtn.classList.toggle('is-hidden', !on);
    }

    function toggleLogBatch() {
        batchMode = !batchMode;
        setCellVisible(batchMode);
        var btn = qs('logBatchBtn');
        if (btn) btn.textContent = batchMode ? '☑ 取消批量' : '☑ 批量选择';
        if (!batchMode) {
            qsa('.logCheckbox').forEach(function (cb) { cb.checked = false; });
            var all = qs('selectAllLogs');
            if (all) all.checked = false;
        }
    }

    function initLogBatch() {
        var btn = qs('logBatchBtn');
        if (btn) btn.addEventListener('click', toggleLogBatch);

        // 全选
        var all = qs('selectAllLogs');
        if (all) {
            all.addEventListener('change', function () {
                qsa('.logCheckbox').forEach(function (cb) { cb.checked = all.checked; });
            });
        }

        var delBtn = qs('logBatchDeleteBtn');
        if (delBtn) delBtn.addEventListener('click', batchDeleteLoginLogs);

        // 表单提交兜底：JS 失效时不至于误跳到 api.php
        var form = qs('logBatchForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                batchDeleteLoginLogs();
            });
        }

        // 行内单条删除（事件委托，兼容分页重载）
        qsa('.js-del-log').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteLoginLog(btn.getAttribute('data-id'));
            });
        });
    }

    function deleteLoginLog(id) {
        if (!id) return;
        AdminUIConfirm({
            title: '删除记录',
            message: '确认删除该条登录记录？',
            okText: '确认删除',
            onOk: function () {
                LCSC.post('api.php', { action: 'log.delete_login', log_id: id }, function (data, msg) {
                    LCSC.toast(msg || '记录已删除', 'success');
                    setTimeout(function () { location.reload(); }, 500);
                }, function (msg) {
                    LCSC.toast(msg || '删除失败', 'error');
                });
            }
        });
    }

    function batchDeleteLoginLogs() {
        var ids = [];
        qsa('.logCheckbox:checked').forEach(function (cb) { ids.push(cb.value); });
        if (ids.length === 0) { LCSC.toast('请先选中要删除的记录', 'warning'); return; }

        AdminUIConfirm({
            title: '批量删除',
            message: '确认删除选中的 ' + ids.length + ' 条登录记录？该操作不可撤销。',
            okText: '确认删除',
            onOk: function () {
                var fd = new FormData();
                fd.append('action', 'log.batch_delete_login');
                ids.forEach(function (id) { fd.append('log_ids[]', id); });
                LCSC.post('api.php', fd, function (data, msg) {
                    LCSC.toast(msg || '批量删除成功', 'success');
                    setTimeout(function () { location.reload(); }, 500);
                }, function (msg) {
                    LCSC.toast(msg || '批量删除失败', 'error');
                });
            }
        });
    }


    function initPerPage() {
        var sel = qs('logPerPageSel');
        if (!sel) return;
        sel.addEventListener('change', function () {
            var url = '?v=admin&tab=monitor&log_per_page=' + encodeURIComponent(sel.value) + '&log_page=1#tab-monitor';
            // 走父框架标签导航（团队版 iframe 内），退回整页跳转
            if (typeof window._iframeNavigate === 'function') window._iframeNavigate(url);
            else location.href = url;
        });
    }


    function initRefresh() {
        var btn = qs('monRefreshBtn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            var label = btn.textContent;
            btn.textContent = '刷新中…';
            location.reload();
            // 兜底：若 reload 被拦截，3 秒后恢复按钮
            setTimeout(function () {
                if (document.body.contains(btn)) {
                    btn.disabled = false;
                    btn.textContent = label;
                }
            }, 3000);
        });
    }


    document.addEventListener('DOMContentLoaded', function () {
        initViewSwitch();
        initLogBatch();
        initPerPage();
        initRefresh();
    });
})();
