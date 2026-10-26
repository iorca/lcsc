// platforms.js — 平台 URL Tab 交互
(function () {
    'use strict';

    function qs(id) { return document.getElementById(id); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }


    function initGuide() {
        qsa('[data-toggle-guide]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var box = btn.closest('.plat-guide');
                if (box) box.classList.toggle('is-open');
            });
        });
    }


    function initTypeToggle() {
        var sel = qs('platType');
        var group = qs('platUrlGroup');
        if (!sel || !group) return;

        function apply() {
            var isLoose = sel.value === 'loose';
            group.classList.toggle('is-hidden', isLoose);
            updatePreview();
        }
        sel.addEventListener('change', apply);
        apply();
    }


    var SAMPLE_NO = 'C123456';

    function updatePreview() {
        var input = qs('platUrl');
        var out = qs('platUrlPreview');
        if (!input || !out) return;

        var tpl = (input.value || '').trim();
        if (!tpl) {
            out.textContent = '填写模板后此处显示点击商品编号时的实际跳转地址';
            out.classList.add('is-empty');
            return;
        }
        // 与后端一致的占位符替换（{part_no}）
        var sample = tpl.replace(/\{part_no\}/g, SAMPLE_NO);
        if (sample === tpl && tpl.indexOf('{part_no}') === -1) {
            out.textContent = '未包含 {part_no} 占位符，该模板不会随商品编号变化';
            out.classList.add('is-empty');
            return;
        }
        out.textContent = sample;
        out.classList.remove('is-empty');
    }

    function initPreview() {
        var input = qs('platUrl');
        if (input) input.addEventListener('input', updatePreview);
        updatePreview();
    }


    function initSetDefault() {
        qsa('.js-set-default').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var id = radio.getAttribute('data-id');
                if (id && typeof window.setDefaultPlatform === 'function') {
                    window.setDefaultPlatform(id);
                }
            });
        });
    }

    function initTplOpen() {
        qsa('.js-tpl-open').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (typeof window.openTplModal === 'function') {
                    window.openTplModal(
                        btn.getAttribute('data-id'),
                        btn.getAttribute('data-name'),
                        btn.getAttribute('data-code')
                    );
                }
            });
        });
    }

    function initTplActions() {
        var parseBtn = qs('tplParseBtn');
        if (parseBtn && typeof window.tplParseSample === 'function') {
            parseBtn.addEventListener('click', function () { window.tplParseSample(); });
        }
        var saveBtn = qs('tplSaveBtn');
        if (saveBtn && typeof window.tplSave === 'function') {
            saveBtn.addEventListener('click', function () { window.tplSave(); });
        }
        var resetBtn = qs('tplResetBtn');
        if (resetBtn && typeof window.tplResetBuiltin === 'function') {
            resetBtn.addEventListener('click', function () { window.tplResetBuiltin(); });
        }
        qsa('[data-tpl-close]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (typeof window.tplClose === 'function') window.tplClose();
            });
        });
        // 遮罩点击关闭
        var modal = qs('tplModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal && typeof window.tplClose === 'function') window.tplClose();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('open')
                && typeof window.tplClose === 'function') {
                window.tplClose();
            }
        });
    }

    function initCancelEdit() {
        var btn = qs('platCancelBtn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (typeof window.cancelEditPlatform === 'function') window.cancelEditPlatform();
        });
    }


    function markEditing(on) {
        var box = qs('platFormBox');
        if (box) box.classList.toggle('is-editing', !!on);
        var cancel = qs('platCancelBtn');
        if (cancel) cancel.classList.toggle('is-hidden', !on);
        // 扫码参数块：仅编辑态出现；出现时按当前平台拉取配置（默认收起）
        var qr = qs('platQrBlock');
        if (qr) qr.classList.toggle('is-hidden', !on);
        if (on) {
            loadQrConfig();
        } else {
            var guide = qs('platQrGuide');
            if (guide) guide.classList.remove('is-open');
            var st = qs('platQrStatus');
            if (st) st.textContent = '';
        }
    }

    function initEditingHooks() {
        ['editPlatform', 'cancelEditPlatform'].forEach(function (fnName) {
            var orig = window[fnName];
            if (typeof orig !== 'function') return;
            window[fnName] = function () {
                var r = orig.apply(this, arguments);
                markEditing(fnName === 'editPlatform');
                return r;
            };
        });
    }


    var API = 'app/api/router.php';

    function qrEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* 未配置平台时的最简骨架（型号 / 封装两个最常用字段；键名留空待填） */
    function qrSkeleton() {
        return JSON.stringify([
            {
                label: '平台收货码',
                action: 'in',
                match: [''],
                fields: { model: [''], package: [''] }
            }
        ], null, 2);
    }

    function loadQrConfig() {
        var pid = (qs('platId') || {}).value || '';
        var ta  = qs('platQrJson');
        var st  = qs('platQrStatus');
        if (!pid || !ta) return;
        if (st) st.textContent = '加载中…';
        LCSC.get(API + '?action=platform.qr_config_get&platform_id=' + encodeURIComponent(pid), function (data) {
            var fmts = (data && data.formats) || [];
            ta.value = fmts.length ? JSON.stringify(fmts, null, 2) : qrSkeleton();
            if (st) st.textContent = fmts.length ? '' : '该平台暂无配置，已给出最简骨架（请替换占位键名后再保存）';
        }, function (msg) {
            ta.value = qrSkeleton();
            if (st) st.textContent = String(msg);
        });
    }

    function saveQrConfig() {
        var pid = (qs('platId') || {}).value || '';
        var ta  = qs('platQrJson');
        var st  = qs('platQrStatus');
        if (!pid) { LCSC.toast('请先保存平台，再配置扫码参数', 'warning'); return; }
        var raw = (ta && ta.value || '').trim();
        if (raw === '') { LCSC.toast('扫码参数不能为空', 'warning'); return; }
        var parsed;
        try { parsed = JSON.parse(raw); }
        catch (e) { LCSC.toast('JSON 格式错误：' + e.message, 'error'); return; }

        var btn = qs('platQrSaveBtn');
        if (btn) btn.disabled = true;
        LCSC.post(API, {
            action: 'platform.qr_config_save',
            platform_id: pid,
            formats: JSON.stringify(parsed)
        }, function (data, msg) {
            if (btn) btn.disabled = false;
            LCSC.toast(msg || '扫码参数已保存', 'success');
            if (st) st.textContent = '';
        }, function (msg) {
            if (btn) btn.disabled = false;
            LCSC.toast(String(msg), 'error');
        });
    }

    function resetQrConfig() {
        var pid = (qs('platId') || {}).value || '';
        if (!pid) return;
        AdminUIConfirm({
            title: '重置为内置扫码参数',
            message: '将丢弃该平台当前的扫码参数配置，恢复为系统内置词典。是否继续？',
            okText: '确认重置',
            onOk: function () {
                LCSC.post(API, { action: 'platform.qr_config_reset', platform_id: pid }, function (data, msg) {
                    LCSC.toast(msg || '已重置为内置', 'success');
                    loadQrConfig();
                }, function (msg) { LCSC.toast(String(msg), 'error'); });
            }
        });
    }

    function initQrBlock() {
        var head = qs('platQrHead');
        if (head) {
            head.addEventListener('click', function () {
                var guide = qs('platQrGuide');
                if (guide) guide.classList.toggle('is-open');
            });
        }
        var loadBtn = qs('platQrLoadBtn');
        if (loadBtn) loadBtn.addEventListener('click', loadQrConfig);
        var saveBtn = qs('platQrSaveBtn');
        if (saveBtn) saveBtn.addEventListener('click', saveQrConfig);
        var resetBtn = qs('platQrResetBtn');
        if (resetBtn) resetBtn.addEventListener('click', resetQrConfig);
    }

    // ── 导入弹窗 ──
    var qrPendingFormats = null;

    function openQrImport() {
        qrPendingFormats = null;
        var f = qs('qrCfgFile'); if (f) f.value = '';
        var st = qs('qrCfgStatus'); if (st) st.innerHTML = '';
        var block = qs('qrCfgCreateBlock'); if (block) block.classList.add('is-hidden');
        var modal = qs('qrCfgModal'); if (modal) modal.classList.add('open');
    }
    function closeQrImport() {
        var modal = qs('qrCfgModal'); if (modal) modal.classList.remove('open');
    }

    function importQrConfig() {
        var f = qs('qrCfgFile');
        if (!f || !f.files || !f.files[0]) { LCSC.toast('请先选择 .json 配置文件', 'warning'); return; }
        var st  = qs('qrCfgStatus');
        var btn = qs('qrCfgImportBtn');
        if (btn) btn.disabled = true;
        if (st) st.innerHTML = '<span class="tpl-note">解析中…</span>';

        var fd = new FormData();
        fd.append('action', 'platform.qr_config_import');
        fd.append('file', f.files[0]);

        LCSC.post(API, fd, function (data, msg) {
            if (btn) btn.disabled = false;
            if (data && data.applied) {
                LCSC.toast('已覆盖平台「' + (data.platform_code || '') + '」的扫码参数', 'success');
                closeQrImport();
                setTimeout(function () { location.reload(); }, 600);
                return;
            }
            // 未匹配：进入「补充信息后创建」步骤
            qrPendingFormats = (data && data.formats) || null;
            var pl = (data && data.platform) || {};
            if (qs('qrCfgCode')) qs('qrCfgCode').value = pl.code || '';
            if (qs('qrCfgName')) qs('qrCfgName').value = pl.name || '';
            if (qs('qrCfgType')) qs('qrCfgType').value = pl.type || 'standard';
            if (qs('qrCfgUrl'))  qs('qrCfgUrl').value = pl.url_template || '';
            if (qs('qrCfgCreateBlock')) qs('qrCfgCreateBlock').classList.remove('is-hidden');
            if (st) st.innerHTML = '<span class="badge badge-yellow">未匹配到平台</span>'
                + '<span class="tpl-note is-spaced">请补充平台信息后创建</span>';
        }, function (msg) {
            if (btn) btn.disabled = false;
            if (st) st.innerHTML = '<span class="badge badge-red">导入失败</span>'
                + '<span class="tpl-note is-spaced">' + qrEsc(msg) + '</span>';
            LCSC.toast(String(msg), 'error');
        });
    }

    function createQrPlatform() {
        var code = (qs('qrCfgCode') || {}).value || '';
        var name = ((qs('qrCfgName') || {}).value || '').trim();
        if (name === '') { LCSC.toast('请填写平台名称', 'warning'); return; }

        var btn = qs('qrCfgCreateBtn');
        if (btn) btn.disabled = true;
        LCSC.post(API, {
            action: 'platform.qr_config_create',
            code: code,
            name: name,
            type: (qs('qrCfgType') || {}).value || 'standard',
            url_template: (qs('qrCfgUrl') || {}).value || '',
            formats: JSON.stringify(qrPendingFormats || [])
        }, function (data, msg) {
            if (btn) btn.disabled = false;
            LCSC.toast(msg || '平台已创建并应用扫码参数', 'success');
            closeQrImport();
            setTimeout(function () { location.reload(); }, 600);
        }, function (msg) {
            if (btn) btn.disabled = false;
            LCSC.toast(String(msg), 'error');
        });
    }

    function initQrImport() {
        var open = qs('qrImportOpenBtn');
        if (open) open.addEventListener('click', openQrImport);
        var imp = qs('qrCfgImportBtn');
        if (imp) imp.addEventListener('click', importQrConfig);
        var create = qs('qrCfgCreateBtn');
        if (create) create.addEventListener('click', createQrPlatform);
        qsa('[data-qr-close]').forEach(function (b) {
            b.addEventListener('click', closeQrImport);
        });
        var modal = qs('qrCfgModal');
        if (modal) {
            modal.addEventListener('click', function (e) { if (e.target === modal) closeQrImport(); });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('open')) closeQrImport();
        });
    }


    document.addEventListener('DOMContentLoaded', function () {
        window.__platJsBound = true;
        initGuide();
        initTypeToggle();
        initPreview();
        initSetDefault();
        initTplOpen();
        initTplActions();
        initCancelEdit();
        initEditingHooks();
        initQrBlock();
        initQrImport();
    });
})();
