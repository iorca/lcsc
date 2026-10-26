// settings.js — 网站设置 Tab 交互
(function () {
    'use strict';

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }


    function initFeatCards() {
        qsa('.feat-card').forEach(function (card) {
            card.setAttribute('tabindex', '0');
            card.setAttribute('role', 'switch');
            syncFeatAria(card);

            card.addEventListener('click', function () { toggleFeatCard(card); });
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleFeatCard(card);
                }
            });
        });
    }

    function toggleFeatCard(card) {
        var inp = card.querySelector('input[type=hidden]');
        if (!inp) return;
        var on = inp.value !== '1';
        inp.value = on ? '1' : '0';
        card.classList.toggle('on', on);
        syncFeatAria(card);
        markCardDirty(card);
    }

    function syncFeatAria(card) {
        var inp = card.querySelector('input[type=hidden]');
        card.setAttribute('aria-checked', inp && inp.value === '1' ? 'true' : 'false');
    }


    var dirtyCards = [];

    var reloadPending = false;

    function markCardDirty(card) {
        if (!card) return;
        if (card.classList.contains('is-dirty')) return;
        card.classList.add('is-dirty');
        if (dirtyCards.indexOf(card) === -1) dirtyCards.push(card);
    }

    function clearDirty(card) {
        card.classList.remove('is-dirty');
        var i = dirtyCards.indexOf(card);
        if (i > -1) dirtyCards.splice(i, 1);
    }

    /* 记录表单初始快照，用于判断是否真的改动过 */
    function snapshotInputs(form) {
        var map = {};
        qsa('input, select, textarea', form).forEach(function (el) {
            if (!el.name || el.type === 'hidden' && el.name === '_csrf') return;
            map[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        });
        return map;
    }

    function diffSnapshot(form, snap) {
        var changed = [];
        qsa('input, select, textarea', form).forEach(function (el) {
            if (!el.name || el.name === '_csrf') return;
            var now = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
            if (snap[el.name] !== undefined && snap[el.name] !== now) changed.push(el.name);
        });
        return changed;
    }

    function initDirtyGuard() {
        var forms = qsa('form.settings-card');
        if (!forms.length) return;

        var snaps = {};
        forms.forEach(function (f) { snaps[f.dataset.cardId || f.getAttribute('id') || String(forms.indexOf(f))] = snapshotInputs(f); });

        forms.forEach(function (form) {
            var key = form.dataset.cardId || form.getAttribute('id') || String(forms.indexOf(form));
            form.addEventListener('input', function () { markCardDirty(form); });
            form.addEventListener('change', function () { markCardDirty(form); });
            form.addEventListener('submit', function () {
                clearDirty(form);
                qsa('.feat-card', form).forEach(clearDirty);
            });
            form._snapKey = key;
            form._snaps = snaps;
        });

        // 离开前提示
        window.addEventListener('beforeunload', function (e) {
            if (reloadPending) return;
            var anyDirty = dirtyCards.some(function (card) {
                var snap = card._snaps && card._snaps[card._snapKey];
                return snap ? diffSnapshot(card, snap).length > 0 : true;
            });
            if (!anyDirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    }


    /* 刷新「侧边栏所在的框架」 */
    function reloadTopFrame() {
        reloadPending = true;
        try {
            if (window.parent && window.parent !== window && window.parent.location) {
                window.parent.location.reload();
                return;
            }
        } catch (e) { /* 跨域：只能刷自己 */ }
        location.reload();
    }

    /* 「功能开关」卡片改走 AJAX，保存成功后刷新父框架 */
    function initFeatureCardAutoReload() {
        var form = qs('form.settings-card[data-card-id="feature"]');
        if (!form || typeof LCSC === 'undefined' || !LCSC.interceptForm) return;
        LCSC.interceptForm(form, function (data, msg) {
            // 保存成功：先把功能卡的脏标记清干净（表单在 submit 阶段已清，这里兜底
            qsa('.feat-card', form).forEach(clearDirty);
            clearDirty(form);

            // 其他卡片有未保存输入时**不整页刷新**：刷父框架会把这些输入一起丢掉。
            // 代价是导航菜单要等下次刷新才更新，故在提示里说清楚，不静默。
            if (dirtyCards.length > 0) {
                LCSC.toast((msg || '设置已保存') + '；其他卡片有未保存内容，导航菜单将在下次刷新时更新', 'success');
                return;
            }
            LCSC.toast(msg || '设置已保存', 'success');
            setTimeout(reloadTopFrame, 250);
        });
    }


    function initAnchors() {
        var nav = qs('.settings-anchors');
        if (!nav) return;
        var links = qsa('a[href^="#"]', nav);
        var targets = links.map(function (a) {
            return document.getElementById(a.getAttribute('href').slice(1));
        }).filter(Boolean);

        /* 点击锚点后「钉住」所点的那一项 */
        var pinnedIdx = -1;
        ['wheel', 'touchmove', 'keydown'].forEach(function (ev) {
            window.addEventListener(ev, function () { pinnedIdx = -1; }, { passive: true });
        });
        nav.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
            var i = a ? links.indexOf(a) : -1;
            if (i < 0) return;
            pinnedIdx = i;
            links.forEach(function (x, k) { x.classList.toggle('is-active', k === i); });
        });

        function syncActive() {
            if (pinnedIdx >= 0) return;      // 点击后、用户滚动前：保持所点的那一项
            var pos = window.scrollY + 120;
            var activeIdx = 0;
            targets.forEach(function (t, i) {
                if (!t) return;
                if (t.getBoundingClientRect().top + window.scrollY <= pos) activeIdx = i;
            });
            // 滚到底时最后两节永远到不了 pos（页面剩余高度不够把它们顶到吸顶栏下），
            var doc = document.documentElement;
            var scrollable = doc.scrollHeight > window.innerHeight + 80;
            if (scrollable && (window.innerHeight + window.scrollY) >= (doc.scrollHeight - 4)) {
                activeIdx = links.length - 1;
            }
            links.forEach(function (a, i) { a.classList.toggle('is-active', i === activeIdx); });
        }

        window.addEventListener('scroll', syncActive, { passive: true });
        syncActive();
    }


    function initSaveButtons() {
        qsa('form.settings-card').forEach(function (form) {
            var btn = qs('button[type=submit]', form);
            if (!btn) return;
            form.addEventListener('submit', function () {
                // 提交后浏览器会整页跳转，这里先把按钮置为「保存中」给出即时反馈
                btn.disabled = true;
                btn.dataset.origText = btn.textContent;
                btn.textContent = '保存中…';
                // 若 AJAX 拦截器接管了表单（admin.js），成功后恢复按钮
                setTimeout(function () {
                    if (document.body.contains(btn)) {
                        btn.disabled = false;
                        btn.textContent = btn.dataset.origText || '保存';
                    }
                }, 4000);
            });
        });
    }


    function initActionButtons() {
        var verBtn = qs('#btnCheckVersion');
        if (verBtn && typeof window.checkVersionManual === 'function') {
            verBtn.addEventListener('click', function () { window.checkVersionManual(); });
        }

        var costBtn = qs('#rebuildCostBtn');
        if (costBtn && typeof window.confirmRebuildCost === 'function') {
            costBtn.addEventListener('click', function () { window.confirmRebuildCost(); });
        }

        initDatasheetScan();
    }

    /**
     * 规格书目录「扫描绑定」：按文件名里的立创编号（C+数字）自动挂到对应物料。
     * 目录里的 PDF 可能很多，扫描前先确认。
     */
    function initDatasheetScan() {
        var btn = qs('#btnScanDatasheet');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var input = qs('input[name="datasheet_dir"]');
            var dir = input ? input.value.trim() : '';

            AdminUIConfirm({
                title: '扫描并自动绑定规格书',
                message: '将扫描目录「' + (dir || 'uploads/datasheets') + '」里的 PDF，'
                       + '按文件名中的立创编号自动绑定到对应物料。<br>'
                       + '已绑定过的会跳过，不会重复。确定继续？',
                okText: '开始扫描',
                onOk: function () {
                    btn.disabled = true;
                    var orig = btn.textContent;
                    btn.textContent = '扫描中…';
                    LCSC.post('api.php', {
                        action: 'asset.datasheet_scan',
                        dir: dir
                    }, function (data) {
                        btn.disabled = false;
                        btn.textContent = orig;
                        showDatasheetScanResult(data);
                    }, function (msg) {
                        btn.disabled = false;
                        btn.textContent = orig;
                        LCSC.toast(msg || '扫描失败', 'error');
                    });
                }
            });
        });
    }

    /** 扫描结果汇总展示 */
    function showDatasheetScanResult(data) {
        data = data || {};
        var lines = [];
        lines.push('扫描 ' + (data.scanned || 0) + ' 个 PDF');
        lines.push('新绑定 ' + (data.bound || 0) + ' 份');
        if (data.skipped)     lines.push('已绑定跳过 ' + data.skipped + ' 份');
        if (data.unmatched)  lines.push('编号未匹配 ' + data.unmatched + ' 份（库中无此编号物料）');
        if (data.unrecognized) lines.push('文件名无编号 ' + data.unrecognized + ' 份');

        var details = data.details || [];
        if (details.length) {
            var bad = details.filter(function (d) { return d.msg && d.file; }).slice(0, 8);
            if (bad.length) {
                lines.push('');
                bad.forEach(function (d) { lines.push('· ' + d.file + '：' + d.msg); });
            }
        }
        LCSC.toast(lines.join('\n'), data.bound ? 'success' : 'info');
    }


    document.addEventListener('DOMContentLoaded', function () {
        initFeatCards();
        initFeatureCardAutoReload();
        initDirtyGuard();
        initAnchors();
        initSaveButtons();
        initActionButtons();
    });
})();
