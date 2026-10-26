// mobile/Assets/js/mobile.js — 移动端独立 JS 框架
(function (global) {
    'use strict';

    var MUI = {};

    // ── 主题 ──
    function applyTheme(t) {
        document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem('theme', t); } catch (e) {}
    }
    MUI.applyTheme = applyTheme;
    MUI.toggleTheme = function () {
        var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        applyTheme(cur);
        MUI.toast(cur === 'dark' ? '已切换深色主题' : '已切换浅色主题');
    };

    // ── Toast ──
    /* 轻提示 */
    MUI.toast = function (msg, type, action) {
        var el = document.createElement('div');
        el.className = 'm-toast' + (type ? ' m-toast-' + type : '');
        var text = document.createElement('span');
        text.className = 'm-toast-text';
        text.textContent = String(msg || '');
        el.appendChild(text);

        var duration = 2200;
        if (action && action.label) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'm-toast-action';
            btn.textContent = String(action.label);
            btn.addEventListener('click', function () {
                close();
                if (typeof action.onClick === 'function') {
                    try { action.onClick(); } catch (e) { /* 动作异常不影响提示条关闭 */ }
                }
            });
            el.appendChild(btn);
            duration = 4000;
        }

        document.body.appendChild(el);

        var timer = null;
        function close() {
            if (timer) clearTimeout(timer);
            el.classList.add('is-closing');
            setTimeout(function () { el.remove(); }, 300);
        }
        timer = setTimeout(close, duration);
        return close;
    };

    // ── CSRF ──
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf"]');
        return meta ? meta.getAttribute('content') : '';
    }
    MUI.csrf = csrfToken;

    MUI.esc = function (s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    // ── 二级页顶栏「返回」 ──
    MUI.back = function (page) {
        location.href = 'index.php?v=' + encodeURIComponent(page || 'home');
    };

    // ── 底部抽屉的通用无障碍行为（焦点陷阱 / Esc 关闭 / 焦点移入）──
    var drawerStack = [];
    var restoring = false;
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]),'
                 + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    /* 抽屉内当前可聚焦的元素（过滤掉隐藏的） */
    function focusablesIn(drawer) {
        return Array.prototype.filter.call(
            drawer.querySelectorAll(FOCUSABLE),
            function (el) { return el.offsetParent !== null || el === document.activeElement; }
        );
    }

    /* 聚焦（带 preventScroll 的现代写法，老浏览器回落普通 focus） */
    function focusTo(el) {
        if (!el) return;
        try { el.focus({ preventScroll: true }); } catch (e) { try { el.focus(); } catch (e2) {} }
    }

    var drawer = {
        /* 打开抽屉 */
        open: function (overlayId, drawerId, onClose, openerId) {
            var ov = document.getElementById(overlayId);
            var dr = document.getElementById(drawerId);
            if (!dr) return;
            if (ov) ov.classList.add('is-open');
            dr.classList.add('is-open');
            if (!dr.hasAttribute('tabindex')) dr.setAttribute('tabindex', '-1');
            // 同一抽屉重复打开 → 先从栈里摘掉旧记录，不重复入栈
            drawerStack = drawerStack.filter(function (it) { return it.drawerId !== drawerId; });
            var opener = openerId ? document.getElementById(openerId) : document.activeElement;
            drawerStack.push({
                overlayId: overlayId,
                drawerId:  drawerId,
                onClose:   onClose || null,
                opener:    opener
            });
            // 焦点移入：优先第一个可聚焦元素；一个都没有时落在抽屉容器上
            var items = focusablesIn(dr);
            focusTo(items.length ? items[0] : dr);
            startTrap();
        },

        /* 关闭抽屉（并把焦点还给打开它的元素） */
        close: function (overlayId, drawerId) {
            var ov = document.getElementById(overlayId);
            var dr = document.getElementById(drawerId);
            if (ov) ov.classList.remove('is-open');
            if (dr) dr.classList.remove('is-open');
            var idx = -1;
            for (var i = 0; i < drawerStack.length; i++) {
                if (drawerStack[i].drawerId === drawerId) { idx = i; break; }
            }
            if (idx < 0) return;
            var item = drawerStack[idx];
            drawerStack.splice(idx, 1);
            var op = item.opener;
            if (op && op.isConnected && typeof op.focus === 'function') {
                restoring = true;
                focusTo(op);
                restoring = false;
            }
            if (!drawerStack.length) stopTrap();
        },

        /* 当前是否有抽屉打开（供页面判断） */
        isOpen: function (drawerId) {
            return drawerStack.some(function (it) { return it.drawerId === drawerId; });
        }
    };
    MUI.drawer = drawer;

    // 全局键盘处理：Esc 关闭栈顶抽屉 + Tab 焦点陷阱（只注册一次）
    document.addEventListener('keydown', function (e) {
        if (!drawerStack.length) return;
        var top = drawerStack[drawerStack.length - 1];
        var dr = document.getElementById(top.drawerId);

        if (e.key === 'Escape' || e.key === 'Esc') {
            e.preventDefault();
            if (top.onClose) {
                try { top.onClose(); } catch (err) { drawer.close(top.overlayId, top.drawerId); }
            } else {
                drawer.close(top.overlayId, top.drawerId);
            }
            return;
        }

        if (e.key !== 'Tab' || !dr) return;
        var items = focusablesIn(dr);
        if (!items.length) { e.preventDefault(); focusTo(dr); return; }
        var first = items[0], last = items[items.length - 1];
        var cur = document.activeElement;
        var idx = items.indexOf(cur);
        if (idx < 0) {
            // 焦点不在抽屉内（或不在可聚焦清单里）→ 拉回第一个
            e.preventDefault();
            focusTo(e.shiftKey ? last : first);
            return;
        }
        e.preventDefault();
        var next = e.shiftKey ? (idx === 0 ? items.length - 1 : idx - 1)
                              : (idx === items.length - 1 ? 0 : idx + 1);
        focusTo(items[next]);
    });

    document.addEventListener('focusin', function (e) {
        if (!drawerStack.length || restoring) return;
        var top = drawerStack[drawerStack.length - 1];
        var dr = document.getElementById(top.drawerId);
        if (!dr || dr.contains(e.target)) return;
        var items = focusablesIn(dr);
        focusTo(items.length ? items[0] : dr);
    });

    var trapTimer = null;
    function ensureFocusInside() {
        if (!drawerStack.length) {
            if (trapTimer) { clearInterval(trapTimer); trapTimer = null; }
            return;
        }
        var top = drawerStack[drawerStack.length - 1];
        var dr = document.getElementById(top.drawerId);
        if (!dr) return;
        var a = document.activeElement;
        if (a && dr.contains(a)) return;
        if (restoring) return;
        var items = focusablesIn(dr);
        focusTo(items.length ? items[0] : dr);
    }
    function startTrap() {
        if (trapTimer) return;
        trapTimer = setInterval(ensureFocusInside, 200);
    }
    function stopTrap() {
        if (trapTimer) { clearInterval(trapTimer); trapTimer = null; }
    }

    // ── 统一 API 请求（后续业务开发入口） ──
    MUI.ajax = function (action, data) {
        var body = new URLSearchParams();
        body.append('_csrf', csrfToken());
        Object.keys(data || {}).forEach(function (k) {
            body.append(k, data[k]);
        });
        return fetch('../api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    };

    // ── 统一 API GET（幂等读操作） ──
    MUI.get = function (action, params) {
        var qs = new URLSearchParams(params || {});
        return fetch('../api.php?action=' + encodeURIComponent(action) + '&' + qs.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); });
    };

    // ── 初始化主题（读取共享偏好，默认跟随系统） ──
    (function () {
        var saved = null;
        try { saved = localStorage.getItem('theme'); } catch (e) {}
        if (saved === 'dark' || saved === 'light') {
            applyTheme(saved);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
            applyTheme('light');
        }
    })();

    global.MUI = MUI;
})(window);
