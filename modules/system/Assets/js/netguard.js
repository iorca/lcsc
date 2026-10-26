// netguard.js — 网络断开 / 服务器异常 本地提示层
(function () {
    'use strict';

    if (window.__lcscNetGuard) return;
    window.__lcscNetGuard = true;

    var OVERLAY_ID = 'lcscNetOverlay';
    var isIframe = false;
    try { isIframe = (window.self !== window.top); } catch (e) { isIframe = false; }

    /* 服务端不可用类状态码：网关/服务未启动/反向代理错误 */
    var SERVER_CODES = [502, 503, 504];

    function isOffline() {
        return typeof navigator !== 'undefined' && navigator.onLine === false;
    }

    /* iframe 内：上报父框架 */
    function notifyParent(state) {
        if (!isIframe) return;
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'lcsc:netguard', state: state }, '*');
            }
        } catch (e) { /* 跨域父框架忽略 */ }
    }

    /* 提示层 DOM（结构与样式全部由 JS 注入，不依赖任何服务端资源） */
    function ensureOverlay() {
        var el = document.getElementById(OVERLAY_ID);
        if (el) return el;
        if (!document.body) return null;

        el = document.createElement('div');
        el.id = OVERLAY_ID;
        el.setAttribute('role', 'alert');
        el.innerHTML =
            '<div class="ng-card">' +
                '<div class="ng-ico">📡</div>' +
                '<div class="ng-title" data-ng-title>网络已断开</div>' +
                '<div class="ng-desc" data-ng-desc>无法连接到服务器，请检查网络连接后重试。</div>' +
                '<ul class="ng-tips">' +
                    '<li>请确认本机网络 / Wi-Fi 已连接</li>' +
                    '<li>若使用本地部署，请确认 Apache / Nginx 与 MySQL 服务仍在运行</li>' +
                    '<li>恢复后点击下方按钮重新加载当前页面</li>' +
                '</ul>' +
                '<button type="button" class="ng-btn" data-ng-retry>重新加载页面</button>' +
                '<div class="ng-meta" data-ng-meta></div>' +
            '</div>';
        document.body.appendChild(el);

        var style = document.createElement('style');
        style.id = 'lcscNetGuardStyle';
        style.textContent =
            '#' + OVERLAY_ID + '{position:fixed;inset:0;z-index:2147483000;display:none;' +
                'align-items:center;justify-content:center;padding:20px;' +
                'background:var(--bg,#f5f6f8);color:var(--text,#1a1d29);}' +
            '#' + OVERLAY_ID + '.show{display:flex;}' +
            '#' + OVERLAY_ID + ' .ng-card{max-width:420px;width:100%;text-align:center;' +
                'background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);' +
                'border-radius:14px;padding:32px 28px;box-shadow:0 12px 40px rgba(0,0,0,.12);}' +
            '#' + OVERLAY_ID + ' .ng-ico{font-size:40px;margin-bottom:12px;line-height:1;}' +
            '#' + OVERLAY_ID + ' .ng-title{font-size:18px;font-weight:700;margin-bottom:8px;}' +
            '#' + OVERLAY_ID + ' .ng-desc{font-size:13px;line-height:1.7;opacity:.75;margin-bottom:16px;}' +
            '#' + OVERLAY_ID + ' .ng-tips{text-align:left;font-size:12px;line-height:1.9;' +
                'opacity:.7;margin:0 0 20px;padding-left:20px;}' +
            '#' + OVERLAY_ID + ' .ng-btn{width:100%;padding:11px 20px;border:none;border-radius:8px;' +
                'background:var(--accent,#3b82f6);color:#fff;font-size:14px;font-weight:600;' +
                'cursor:pointer;font-family:inherit;}' +
            '#' + OVERLAY_ID + ' .ng-btn:hover{filter:brightness(1.08);}' +
            '#' + OVERLAY_ID + ' .ng-meta{margin-top:12px;font-size:11px;opacity:.5;font-family:monospace;}';
        document.head.appendChild(style);

        el.querySelector('[data-ng-retry]').addEventListener('click', function () {
            location.reload();
        });
        return el;
    }

    /* 显示提示层 */
    function show(state) {
        var title = state === 'offline' ? '网络已断开' : '服务器异常';
        var desc  = state === 'offline'
            ? '无法连接到服务器，请检查网络连接后重试。'
            : '服务器暂时无法访问（可能未启动或正在维护），请稍后重试。';

        var el = ensureOverlay();
        if (!el) {
            // body 尚未就绪：DOMContentLoaded 后重试一次
            document.addEventListener('DOMContentLoaded', function () { show(state); }, { once: true });
            return;
        }
        var t = el.querySelector('[data-ng-title]');
        var d = el.querySelector('[data-ng-desc]');
        if (t) t.textContent = title;
        if (d) d.textContent = desc;
        var m = el.querySelector('[data-ng-meta]');
        if (m) m.textContent = new Date().toLocaleString();
        el.classList.add('show');
    }

    function hide() {
        var el = document.getElementById(OVERLAY_ID);
        if (el) el.classList.remove('show');
        shown = false;   // 恢复后允许再次判定（var 声明在 fetch 拦截段，提升后此处可直接赋值）
    }

    /* iframe：接收父框架指令 */
    window.addEventListener('message', function (ev) {
        var d = ev.data || {};
        if (d && d.type === 'lcsc:netguard') {
            if (d.state === 'ok') hide(); else show(d.state);
        }
    });

    /* 对外接口（团队版主框架用）： */
    window.LCSCNetGuard = {
        report: function (state) {
            if (state === 'ok') hide(); else show(state);
        }
    };
    // 兼容别名：主框架监听 postMessage 时直接调用
    window.studioNetGuard = function (state) {
        if (state === 'ok') hide(); else show(state);
    };

    /* 事件绑定 */
    window.addEventListener('offline', function () {
        notifyParent('offline');
        if (!isIframe) show('offline');
    });
    window.addEventListener('online', function () {
        notifyParent('ok');
        if (!isIframe) hide();
    });

    // 页面加载时若已处于断网状态，立即提示（刷新/新开标签页场景）
    if (isOffline()) {
        if (isIframe) notifyParent('offline');
        else if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { show('offline'); }, { once: true });
        } else {
            show('offline');
        }
    }

    /* fetch 拦截：区分「网络异常」与「服务不可用」 */
    var origFetch = window.fetch;
    if (!origFetch) return;

    var PROBE_FLAG = 'X-LCSC-Probe';
    var PROBE_URL = 'api.php?action=netguard.probe';
    var probing = false;
    var shown = false;

    function isSameOrigin(url) {
        try { return new URL(url, location.href).origin === location.origin; }
        catch (e) { return false; }
    }

    function hasProbeFlag(opts) {
        if (!opts || !opts.headers) return false;
        var h = opts.headers;
        if (typeof Headers !== 'undefined' && h instanceof Headers) return h.has(PROBE_FLAG);
        if (Array.isArray(h)) return h.some(function (p) { return String(p[0]).toLowerCase() === PROBE_FLAG.toLowerCase(); });
        return Object.keys(h).some(function (k) { return k.toLowerCase() === PROBE_FLAG.toLowerCase(); });
    }

    /* 探活确认：只有服务端确实不可达才展示整屏提示 */
    function confirmAndShow(state) {
        if (shown || probing) return;
        if (state === 'offline' || isOffline()) { show(state); shown = true; return; }
        probing = true;
        var done = function (unhealthy) {
            probing = false;
            if (shown) return;
            if (!unhealthy) return;          // 服务还活着 → 瞬时抖动，交给调用方自己的错误提示
            shown = true;
            show(state);
        };
        try {
            origFetch(PROBE_URL + '&_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: (function () { var o = {}; o[PROBE_FLAG] = '1'; return o; })()
            }).then(function (r) {
                done(r.status >= 500);       // 5xx = 网关/服务不可用；其余任何响应都说明服务在
            }).catch(function () {
                done(true);                  // 探针本身都发不出去 = 确实连不上
            });
        } catch (e) {
            done(true);
        }
    }

    window.fetch = function () {
        var input = arguments[0];
        var opts = arguments[1];
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        // 探针自身 / 站外请求（外部图片、CDN 等连不上与本站无关）不参与判定
        if (hasProbeFlag(opts) || !isSameOrigin(url)) return origFetch.apply(this, arguments);

        return origFetch.apply(this, arguments).then(function (resp) {
            if (SERVER_CODES.indexOf(resp.status) > -1) confirmAndShow(isOffline() ? 'offline' : 'server');
            return resp;
        }).catch(function (err) {
            if (err && err.name === 'TypeError') confirmAndShow(isOffline() ? 'offline' : 'server');
            throw err;
        });
    };
})();
