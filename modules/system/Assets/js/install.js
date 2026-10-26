// install.js — 安装向导交互（版本选择 / 密码强度 / 表单校验）
(function () {
    'use strict';

    /* 版本选择卡片 */
    function pickEdition(ed, el) {
        var input = document.getElementById('editionInput');
        if (input) input.value = ed;
        document.querySelectorAll('.cd').forEach(function (c) { c.classList.remove('sel'); });
        if (el) el.classList.add('sel');
    }
    window.pickEdition = pickEdition;

    // 键盘可达：卡片支持 Tab 聚焦 + Enter/Space 选择
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = e.target.closest ? e.target.closest('.cd') : null;
        if (!el) return;
        e.preventDefault();
        pickEdition(el.getAttribute('data-edition') || 'studio', el);
    });

    function scorePassword(pw) {
        if (!pw || pw.length < 8) return 0;
        var c = 0;
        if (/[a-z]/.test(pw)) c++;
        if (/[A-Z]/.test(pw)) c++;
        if (/[0-9]/.test(pw)) c++;
        if (/[^a-zA-Z0-9]/.test(pw)) c++;
        if (pw.length >= 14 && c === 4) return 4;
        return Math.max(1, c);
    }
    var PW_TIP = ['至少 8 位', '强度：弱', '强度：中', '强度：强', '强度：很强'];
    function bindPwMeter(inputId, meterId, tipId) {
        var input = document.getElementById(inputId);
        var meter = document.getElementById(meterId);
        var tip = document.getElementById(tipId);
        if (!input || !meter) return;
        var update = function () {
            var s = scorePassword(input.value);
            meter.className = 'pw-meter' + (s ? ' s' + s : '');
            if (tip) tip.textContent = input.value ? PW_TIP[s] : PW_TIP[0];
        };
        input.addEventListener('input', update);
        update();
    }
    bindPwMeter('password', 'pwMeter', 'pwTip');

    /* 两次密码一致性即时提示 */
    (function () {
        var pw = document.getElementById('password');
        var cf = document.getElementById('confirm');
        var tip = document.getElementById('confirmTip');
        if (!pw || !cf || !tip) return;
        var update = function () {
            if (!cf.value) { tip.textContent = ''; return; }
            if (cf.value === pw.value) { tip.textContent = '✓ 两次输入一致'; tip.style.color = 'var(--ok)'; }
            else { tip.textContent = '两次密码不一致'; tip.style.color = 'var(--err)'; }
        };
        cf.addEventListener('input', update);
        pw.addEventListener('input', update);
    })();

    /* 提交前拦截：环境检测未通过 / 密码强度不足时不放行 */
    /* 表单校验提示条 */
    function installNotify(msg) {
        var el = document.getElementById('installNotify');
        if (!el) {
            el = document.createElement('div');
            el.id = 'installNotify';
            el.className = 'install-notify';
            el.setAttribute('role', 'alert');
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.classList.add('is-on');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.classList.remove('is-on'); }, 3600);
    }

    var form = document.getElementById('setupForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            var pw = document.getElementById('password');
            var cf = document.getElementById('confirm');
            if (pw && cf) {
                if (scorePassword(pw.value) === 0) {
                    e.preventDefault();
                    installNotify('密码强度不足：至少 8 位，且包含大小写字母 / 数字 / 特殊字符中的 3 类');
                    pw.focus();
                    return;
                }
                if (pw.value !== cf.value) {
                    e.preventDefault();
                    installNotify('两次密码不一致');
                    cf.focus();
                    return;
                }
            }
            var btn = form.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = '正在完成安装…'; }
        });
    }

    /* 数据库配置表单：提交后按钮进入忙碌态，防重复提交 */
    var dbForm = document.getElementById('dbForm');
    if (dbForm) {
        dbForm.addEventListener('submit', function () {
            var btn = dbForm.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = '正在连接并初始化表结构…'; }
        });
    }

    /* 重置确认表单 */
    var resetForm = document.getElementById('resetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function (e) {
            var code = document.getElementById('confirm_code');
            if (code && code.value.trim().toUpperCase() !== 'RESET') {
                e.preventDefault();
                installNotify('确认文本必须为大写 RESET');
                return;
            }
            var btn = resetForm.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = '正在重置…'; }
        });
    }
})();
