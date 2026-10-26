// modules/admin/Assets/js/trace.js — 后台「溯源日志」Tab 交互脚本
(function(){
    'use strict';
    var panel = document.getElementById('tracePanel');
    if (!panel) return;

    var busy = false;

    function v(id) {
        var el = document.getElementById(id);
        return el ? String(el.value || '').trim() : '';
    }
    function setVal(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val;
    }
    function toast(msg, type) {
        if (window.LCSC && LCSC.toast) LCSC.toast(msg, type || 'success');
    }

    // ── 组装片段请求：fragment=1 时后端只回传面板 HTML（不输出整页框架） ──
    function buildUrl() {
        var p = new URLSearchParams();
        p.set('v', 'admin');
        p.set('tab', 'trace');
        p.set('fragment', '1');
        var from = v('traceDateFrom');  if (from) p.set('trace_date_from', from);
        var to   = v('traceDateTo');    if (to)   p.set('trace_date_to', to);
        var act  = v('traceActionSel'); if (act)  p.set('trace_action', act);
        var typ  = v('traceTypeSel');   if (typ)  p.set('trace_target_type', typ);
        var op   = v('traceOperator');  if (op)   p.set('trace_operator', op);
        return 'index.php?' + p.toString();
    }

    // ─ 无感刷新：只替换面板内容，页面与 iframe 均不重载（旧内容保持可见，不闪白）──
    function refresh(msg) {
        if (busy) return;
        busy = true;
        LCSC.fragmentRefresh(panel, buildUrl(), {
            json: false,                 // 片段接口回传裸 HTML，不走 {code,msg,data} 信封
            busyKey: 'tracePanel',
            mustContain: 'trace-card',   // 片段必须只含面板卡片
            onDone: function () { if (msg) toast(msg, 'success'); },
            onFail: function (err) {
                toast('刷新失败：' + ((err && err.message) ? err.message : '网络异常'), 'error');
            }
        }).then(function () {
            busy = false;
        });
    }

    // ── 快捷时段：填充起止日期后立即刷新 ──
    function setPreset(days) {
        var dTo = new Date();
        var dFrom = new Date();
        dFrom.setDate(dFrom.getDate() - days);
        function fmt(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        setVal('traceDateFrom', fmt(dFrom));
        setVal('traceDateTo', fmt(dTo));
    }

    document.addEventListener('click', function(e) {
        var el = (e.target && e.target.closest) ? e.target.closest('[data-trace-days],[data-trace-apply],[data-trace-reset]') : null;
        if (!el) return;
        e.preventDefault();
        if (el.hasAttribute('data-trace-days')) {
            setPreset(parseInt(el.getAttribute('data-trace-days'), 10) || 0);
            refresh('已按快捷时段筛选');
            return;
        }
        if (el.hasAttribute('data-trace-apply')) { refresh('已按当前条件筛选'); return; }
        setVal('traceDateFrom', ''); setVal('traceDateTo', ''); setVal('traceOperator', '');
        setVal('traceActionSel', ''); setVal('traceTypeSel', '');
        refresh('已重置筛选条件');
    }, true);

    // 操作人输入框回车 = 筛选
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' || !e.target || e.target.id !== 'traceOperator') return;
        e.preventDefault();
        refresh('已按当前条件筛选');
    }, true);

    document.addEventListener('click', function(e) {
        var btn = (e.target && e.target.closest) ? e.target.closest('.trace-detail-toggle') : null;
        if (!btn) return;
        e.preventDefault();
        var cell = btn.closest('.trace-cell-detail');
        if (!cell) return;
        var open = cell.classList.toggle('is-open');
        btn.textContent = open ? '收起' : '展开';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }, true);

    // ── 导出 CSV：同步当前筛选日期到隐藏域；未选日期则拦截并提示 ──
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.id !== 'traceExportForm') return;
        var curFrom = v('traceDateFrom');
        var curTo   = v('traceDateTo');
        var from = form.querySelector('[name="date_from"]');
        var to   = form.querySelector('[name="date_to"]');
        if (from) from.value = curFrom;
        if (to)   to.value   = curTo;
        if (!curFrom || !curTo) {
            e.preventDefault();
            e.stopImmediatePropagation();
            toast('请先选择起始日期和结束日期后再导出', 'warning');
        }
    }, true);
})();
