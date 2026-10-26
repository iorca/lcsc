// users.js — 用户管理 Tab 交互
(function () {
    'use strict';

    function qs(id) { return document.getElementById(id); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }


    function showPwMsg(text, kind) {
        var el = qs('pwModalMsg');
        if (!el) return;
        el.textContent = text;
        el.classList.remove('is-hidden', 'is-err', 'is-ok');
        el.classList.add(kind === 'ok' ? 'is-ok' : 'is-err');
    }

    function openPwModal() {
        var m = qs('pwModal');
        if (!m) return;
        var f = qs('pwModalForm');
        if (f) f.reset();
        var msg = qs('pwModalMsg');
        if (msg) msg.classList.add('is-hidden');
        m.classList.add('open');
        setTimeout(function () {
            var i = document.querySelector('#pwModalForm input[name=old_password]');
            if (i) i.focus();
        }, 60);
    }

    function closePwModal() {
        var m = qs('pwModal');
        if (m) m.classList.remove('open');
    }

    function initPwModal() {
        var openBtn = qs('btnChangeMyPw');
        if (openBtn) openBtn.addEventListener('click', openPwModal);

        // 关闭：按钮、遮罩点击、ESC
        qsa('[data-close-pw]').forEach(function (b) {
            b.addEventListener('click', closePwModal);
        });
        var modal = qs('pwModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closePwModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('open')) closePwModal();
        });

        var form = qs('pwModalForm');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var np = form.new_password.value;
            var cf = form.confirm.value;

            if (np !== cf) { showPwMsg('两次输入的新密码不一致', 'err'); return; }
            if (typeof LCSC !== 'undefined' && LCSC.isStrongPassword && !LCSC.isStrongPassword(np)) {
                showPwMsg('新密码至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种', 'err');
                return;
            }

            var btn = qs('pwModalSubmit');
            if (btn) btn.disabled = true;
            LCSC.post('index.php?v=change_password', {
                old_password: form.old_password.value,
                new_password: np,
                confirm: cf
            }, function (data, msg) {
                if (btn) btn.disabled = false;
                showPwMsg(msg || '密码已更新', 'ok');
                if (typeof LCSC !== 'undefined' && LCSC.toast) LCSC.toast(msg || '密码已更新', 'success');
                setTimeout(closePwModal, 1200);
            }, function (msg) {
                if (btn) btn.disabled = false;
                showPwMsg(msg || '操作失败', 'err');
            });
        });
    }


    function initPermBulk() {
        var form = qs('createSubForm');
        if (!form) return;
        var boxes = qsa('input[name="sub_perms[]"]', form);

        var all = qs('btnPermAll');
        if (all) {
            all.addEventListener('click', function () {
                boxes.forEach(function (b) { b.checked = true; });
            });
        }
        var none = qs('btnPermNone');
        if (none) {
            none.addEventListener('click', function () {
                boxes.forEach(function (b) { b.checked = false; });
            });
        }
    }


    var PERM_LABELS = {
        can_edit: '编辑物料',
        can_delete: '删除物料',
        can_import: '导入订单',
        can_manage_categories: '管理分类',
        can_batch: '批量操作',
        can_export: '导出数据',
        can_scan: '扫码出入库',
        can_print: '打印标签'
    };

    function initPermHints() {
        qsa('.um-perm-chip').forEach(function (chip) {
            var list = (chip.getAttribute('data-perm-list') || '').split(',').filter(Boolean);
            if (!list.length) return;
            var names = list.map(function (k) { return PERM_LABELS[k] || k; });
            chip.setAttribute('title', '拥有：' + names.join('、'));
        });
    }


    document.addEventListener('DOMContentLoaded', function () {
        initPwModal();
        initPermBulk();
        initPermHints();
    });
})();
