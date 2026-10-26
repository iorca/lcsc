<?php
declare(strict_types=1);
// scripts.php — 全局 JS 脚本

$_isStudioIframe = $_isStudioIframe ?? false;
?>
<!-- 网络断开 / 服务器异常 本地提示层（浏览器侧，断网时服务端无法渲染任何内容） -->
<script src="modules/system/Assets/js/netguard.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../../modules/system/Assets/js/netguard.js')?>"></script>
<script>
// ── 弹窗右上角统一关闭按钮（视觉层注入；点击=关闭当前弹窗，与蒙层/ESC 行为一致，不改业务逻辑）──
(function(){
    function ensureCloseBtn(){
        document.querySelectorAll('.overlay.open > .modal').forEach(function(m){
            if (m.querySelector('.modal-close')) return;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'modal-close';
            btn.setAttribute('aria-label', '关闭');
            btn.title = '关闭';
            btn.innerHTML = '✕';
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                var ov = m.closest('.overlay');
                if (ov) ov.classList.remove('open');
            });
            m.appendChild(btn);
        });
    }
    function boot(){
        ensureCloseBtn();
        var mo = new MutationObserver(function(records){
            records.forEach(function(rec){
                if (rec.attributeName === 'class') {
                    var t = rec.target;
                    if (t.classList && t.classList.contains('overlay')) ensureCloseBtn();
                }
            });
        });
        document.querySelectorAll('.overlay').forEach(function(ov){ mo.observe(ov, {attributes:true}); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
// ── flash 提示条自动消失（全局统一，覆盖 PC/个人版/工作室 iframe 所有布局）──
// 成功/失败 4 秒淡出，警告类（含重要信息如重置密码）8 秒淡出
(function(){
    function autoDismissFlash(){
        document.querySelectorAll('.flash').forEach(function(el){
            if (el.dataset.autoDismiss) return;
            el.dataset.autoDismiss = '1';
            var delay = el.classList.contains('warn') ? 8000 : 4000;
            setTimeout(function(){
                el.style.transition = 'opacity .4s ease';
                el.style.opacity = '0';
                setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 450);
            }, delay);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoDismissFlash);
    } else {
        autoDismissFlash();
    }
})();

// ── 弹窗开关（全局）──
function openOverlay(id){var el=document.getElementById(id); if(el) el.classList.add('open');}
function closeOverlay(id){var el=document.getElementById(id); if(el) el.classList.remove('open');}

// ── AdminUIConfirm — 全局统一确认弹窗（居中模态框，替代原生 confirm）──
(function(){
    if (!document.getElementById('adminConfirmStyle')) {
        var st = document.createElement('style');
        st.id = 'adminConfirmStyle';
        st.textContent =
            '.acm-overlay{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;' +
            'background:rgba(0,0,0,.5);backdrop-filter:blur(4px);animation:acmFade .15s ease;}' +
            '@keyframes acmFade{from{opacity:0}to{opacity:1}}' +
            '@keyframes acmPop{from{opacity:0;transform:scale(.92) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}' +
            '.acm-dialog{width:min(400px,calc(100vw - 40px));background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);' +
            'border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.35);padding:24px 24px 20px;' +
            'animation:acmPop .18s cubic-bezier(.34,1.4,.64,1);}' +
            '.acm-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;' +
            'font-size:22px;margin-bottom:14px;}' +
            '.acm-icon.danger{background:rgba(239,68,68,.12);}' +
            '.acm-icon.info{background:var(--accent-dim,rgba(79,142,247,.12));}' +
            '.acm-title{font-size:16px;font-weight:700;color:var(--text,#1a1d29);margin-bottom:8px;}' +
            '.acm-msg{font-size:13px;line-height:1.7;color:var(--text2,#6b7280);white-space:pre-line;margin-bottom:20px;}' +
            '.acm-btns{display:flex;justify-content:flex-end;gap:10px;}' +
            '.acm-btn{padding:8px 20px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;' +
            'border:1px solid var(--border,#d1d5db);background:var(--surface2,#f5f6f8);color:var(--text,#1a1d29);' +
            'transition:all .15s ease;font-family:inherit;}' +
            '.acm-btn:hover{border-color:var(--border2,#9ca3af);}' +
            '.acm-btn.ok{background:var(--red,#ef4444);border-color:var(--red,#ef4444);color:#fff;}' +
            '.acm-btn.ok:hover{filter:brightness(1.08);box-shadow:0 4px 12px rgba(239,68,68,.3);}' +
            '.acm-btn.ok.primary{background:var(--accent,#2563eb);border-color:var(--accent,#2563eb);}' +
            '.acm-btn.ok.primary:hover{box-shadow:0 4px 12px rgba(37,99,235,.3);}';
        document.head.appendChild(st);
    }
})();

function AdminUICloseConfirm(){
    var ov = document.getElementById('adminConfirmOverlay');
    if (ov) ov.remove();
}
function AdminUIConfirm(opts){
    // 防重复：已有弹窗先关闭
    AdminUICloseConfirm();
    var overlay = document.createElement('div');
    overlay.id = 'adminConfirmOverlay';
    overlay.className = 'acm-overlay';
    var danger = opts.danger !== false;
    overlay.innerHTML =
        '<div class="acm-dialog" role="dialog" aria-modal="true">' +
            '<div class="acm-icon ' + (danger ? 'danger' : 'info') + '">' + (danger ? '⚠️' : '❓') + '</div>' +
            '<div class="acm-title">' + escAttrAdmin(opts.title || '操作确认') + '</div>' +
            '<div class="acm-msg">' + escAttrAdmin(opts.message || '') + '</div>' +
            '<div class="acm-btns">' +
                '<button type="button" class="acm-btn" data-act="cancel">取消</button>' +
                '<button type="button" class="acm-btn ok' + (danger ? '' : ' primary') + '" data-act="ok">' + escAttrAdmin(opts.okText || '确认') + '</button>' +
            '</div>' +
        '</div>';
    overlay.addEventListener('click', function(e){
        if (e.target === overlay) { AdminUICloseConfirm(); return; }
        var act = e.target.getAttribute && e.target.getAttribute('data-act');
        if (act === 'cancel') AdminUICloseConfirm();
        else if (act === 'ok') { AdminUICloseConfirm(); if (typeof opts.onOk === 'function') opts.onOk(); }
    });
    // Esc 关闭
    document.addEventListener('keydown', function escHandler(e){
        if (e.key === 'Escape') { AdminUICloseConfirm(); document.removeEventListener('keydown', escHandler); }
    });
    document.body.appendChild(overlay);
    // 聚焦确认按钮，支持回车快捷确认
    var okBtn = overlay.querySelector('[data-act=ok]');
    if (okBtn) okBtn.focus();
}
// 属性安全转义（弹窗内容为纯文本渲染）
function escAttrAdmin(s){
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── AdminUIConfirmSubmit — 表单确认助手（替代内联 onsubmit="return confirm('...')"）──
function AdminUIConfirmSubmit(e, msg, okText){
    var form = e.target.closest ? e.target.closest('form') : null;
    if (!form) return;
    // 二次进入（确认后 requestSubmit 触发）：清除标记并放行原生提交流程
    if (form._confirmPassed) { form._confirmPassed = false; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    AdminUIConfirm({
        title: '操作确认',
        message: msg,
        okText: okText || '确认',
        onOk: function(){
            form._confirmPassed = true;
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        }
    });
}

// ── 团队版多标签页：写操作通知父框架（跨标签页数据实时刷新）──
function studioNotifyWrite() {
    try {
        if (window.parent && window.parent !== window && typeof window.parent.studioMarkWrite === 'function') {
            var seq = window.parent.studioMarkWrite();
            window._studioLoadedWriteSeq = seq;
        }
    } catch(e) {}
}

// ── 判断 AJAX 动作是否为真正的写操作 ──
function studioIsWriteAction(data) {
    var action = '';
    if (data && typeof data === 'object' && !(data instanceof FormData)) {
        action = String(data.action || '');
    } else if (data instanceof FormData) {
        action = String(data.get('action') || '');
    }
    if (!action) return true; // 无动作标识：按写操作处理（保守策略）
    // 写动词白名单（按 . _ - 分段匹配）：命中才视为写操作
    return /(^|[._-])(add|create|new|edit|update|save|del|delete|remove|import|restore|backup|merge|batch|bulk|scan|undo|adjust|toggle|set|config|reorder|move|assign|bind|unbind|link|unlink|clear|reset|regenerate|rebuild|generate|sync|upload|submit|approve|reject|checkout|cancel|pay|refund|reinit)([._-]|$)/i.test(action);
}

// ── 主题切换（全局统一：切换即广播到同源 iframe / 父框架，已打开标签页实时跟随）──
function applyTheme(t){
    document.documentElement.setAttribute('data-theme',t);
    try{ localStorage.setItem('theme',t); }catch(e){}
    try{ if(window.parent && window.parent!==window) window.parent.postMessage({type:'lcsc:theme',theme:t},'*'); }catch(e){}
    try{ document.querySelectorAll('iframe').forEach(function(f){ if(f.contentWindow) f.contentWindow.postMessage({type:'lcsc:theme',theme:t},'*'); }); }catch(e){}
}
function toggleTheme(){
    applyTheme(document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark');
}
// 接收父/子帧主题广播，已打开页面实时跟随
window.addEventListener('message', function(ev){
    var d = ev.data || {};
    if (d && d.type === 'lcsc:theme' && d.theme) applyTheme(d.theme);
});
(function(){
    var s = null;
    try{ s = localStorage.getItem('theme'); }catch(e){}
    if (s) { applyTheme(s); }
    else {
        var cur = document.documentElement.getAttribute('data-theme') || 'light';
        if (cur) document.documentElement.setAttribute('data-theme', cur);
    }
})();

(function(){
    function migrateQTips(){
        document.querySelectorAll('.q-tip').forEach(function(t){
            var sp = t.querySelector('span');
            if (sp && !t.getAttribute('data-tip')) { t.setAttribute('data-tip', sp.textContent); sp.remove(); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', migrateQTips);
    else migrateQTips();
})();

// ── 全站导出进度提示（对标「生成并下载备份」：点击导出即顶部悬浮提示生成中）──
(function(){
    var SEL = 'a[href*="export"], a[href*="shortage_csv"], a[href*="pending_csv"]';
    function fire(){ if (window.LCSC && LCSC.toast) LCSC.toast('正在生成导出文件，请稍候…', 'info'); }
    document.addEventListener('click', function(e){
        if (e.target && e.target.closest && e.target.closest(SEL)) fire();
    }, true);
    document.addEventListener('submit', function(e){
        if (e.target && e.target.closest && e.target.closest('form[action*="export"]')) fire();
    }, true);
})();
// ── 将版权底部移动到页面末尾 ──
(function(){
    function moveFooter(){
        var footers = document.querySelectorAll('.site-footer');
        if (!footers.length) return;
        for (var i = 1; i < footers.length; i++) { footers[i].remove(); }
        document.body.appendChild(footers[0]);
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',moveFooter);}
    else{moveFooter();}
    window.addEventListener('load',moveFooter);
})();

// ── 会话超时自动退出（客户端）──
(function(){
    var timeout = <?=(int)(getSetting('session_timeout','0') ?: '1800')?>;
    if (timeout <= 0) timeout = 1800; // 0 或无效值均回退到默认30分钟
    var warnTime = Math.max(60, Math.floor(timeout * 0.1)); // 10% 时间提前警告
    var warnTimer = null;
    var logoutTimer = null;
    function resetTimers(){
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);
        warnTimer = setTimeout(showWarning, (timeout - warnTime) * 1000);
        logoutTimer = setTimeout(doLogout, timeout * 1000);
    }
    function showWarning(){
        var existing = document.getElementById('timeoutWarning');
        if (existing) return;
        var div = document.createElement('div');
        div.id = 'timeoutWarning';
        div.style.cssText = 'position:fixed;top:0;left:0;right:0;background:var(--yellow);color:#000;text-align:center;padding:10px;z-index:9999;font-size:13px;cursor:pointer;';
        div.textContent = '⚠ 您已长时间未操作，' + warnTime + '秒后将自动退出。点击任意位置继续使用';
        div.onclick = function(){ div.remove(); resetTimers(); };
        document.body.prepend(div);
    }
    function doLogout(){
        var target = (window.self !== window.top) ? window.top : window;
        target.location.href = 'login.php?reason=timeout';
    }
    var lastActivityReset = 0;
    function onActivity(){
        var now = Date.now();
        if (now - lastActivityReset < 1000) return;
        lastActivityReset = now;
        resetTimers();
    }
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(ev){
        document.addEventListener(ev, onActivity, {passive:true});
    });
    resetTimers();
})();

// ── AJAX 全局超时拦截器：鉴权失败统一弹窗 ──
(function(){
    function _isAuthFailure(data){
        if (!data) return false;
        if (data.code === 403 && data.data && data.data.auth === false) return true;
        return false;
    }
    function _isTimeoutResp(data){
        if (!data) return false;
        if (data.data && typeof data.data.timeout !== 'undefined') return !!data.data.timeout;
        return false;
    }
    var origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function(url, opts) {
            return origFetch.apply(this, arguments).then(function(resp) {
                var ct = resp.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    resp.clone().json().then(function(data) {
                        if (_isAuthFailure(data)) showAjaxTimeoutModal(_isTimeoutResp(data));
                    }).catch(function(){});
                }
                return resp;
            });
        };
    }
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._url = url;
        return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function() {
        var self = this;
        this.addEventListener('load', function() {
            try {
                var ct = self.getResponseHeader('content-type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    var data = JSON.parse(self.responseText);
                    if (_isAuthFailure(data)) showAjaxTimeoutModal(_isTimeoutResp(data));
                }
            } catch(e) {}
        });
        return origSend.apply(this, arguments);
    };
    function showAjaxTimeoutModal(isTimeout) {
        LCSC.showAuthExpired(isTimeout);
    }
    // 暴露给同步代码使用
    window._ajaxShowTimeout = showAjaxTimeoutModal;
})();

// ── 表格横向滚动：回到最左侧浮动按钮 ──
(function(){
    function initTableScrollBtns(){
        document.querySelectorAll('.table-wrap').forEach(function(wrap){
            if (wrap._scrollBtnInit) return;
            wrap._scrollBtnInit = true;
            var btn = document.createElement('button');
            btn.className = 'scroll-left-btn';
            btn.innerHTML = '⟸';
            btn.title = '回到最左侧';
            btn.onclick = function(){ wrap.scrollTo({left:0,behavior:'smooth'}); };
            wrap.appendChild(btn);
            wrap.addEventListener('scroll', function(){
                if (wrap.scrollLeft > 100) btn.classList.add('show');
                else btn.classList.remove('show');
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTableScrollBtns);
    } else {
        initTableScrollBtns();
    }
    document.addEventListener('DOMContentLoaded', initTableScrollBtns);
    window.addEventListener('load', initTableScrollBtns);
})();

// ── 全局分页页码直达函数 ──
function pageJumpTo(e, baseUrl, totalPages){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if(raw === '') return;
    var p = parseInt(raw, 10);
    if(isNaN(p) || p < 1){ e.target.value = ''; LCSC.toast('请输入有效页码', 'warning'); return; }
    if(p > totalPages) p = totalPages;
    e.target.value = p;
    (window._iframeNavigate || function(u){location.href=u})(baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + p);
}

// ── 全局 AJAX 工具函数（统一 {code,msg,data} 响应处理）──
var LCSC = {
    csrf: document.querySelector('meta[name="csrf"]')?.content || '',
    _fragBusy: {},
    /* 检测响应是否为鉴权失败 */
    _isAuthFail: function(json) {
        if (!json) return false;
        if (json.code === 403 && json.data && json.data.auth === false) return true;
        return false;
    },
    /* 提取超时标志 */
    _isTimeout: function(json) {
        if (!json) return false;
        if (json.data && typeof json.data.timeout !== 'undefined') return !!json.data.timeout;
        return false;
    },
    _safeJson: function(r) {
        if (!r.ok) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function(j){
                    if (LCSC._isAuthFail(j)) LCSC.showAuthExpired(LCSC._isTimeout(j));
                    var msg = (j && j.msg) ? j.msg : ('HTTP ' + r.status);
                    throw new Error(msg);
                });
            }
            throw new Error('HTTP ' + r.status);
        }
        var ct2 = r.headers.get('content-type') || '';
        if (ct2.indexOf('application/json') === -1) {
            // 服务器意外返回 HTML（如 PHP 致命错误页）→ 抛出明确错误
            throw new Error('服务器返回非 JSON 数据');
        }
        return r.json();
    },
    /* 统一 AJAX POST，自动附加CSRF和AJAX标识 */
    post: function(url, data, callback, errCallback) {
        if (typeof data === 'object' && !(data instanceof FormData)) {
            data._csrf = LCSC.csrf;
            data.ajax = '1';
        } else if (data instanceof FormData) {
            data.append('_csrf', LCSC.csrf);
            data.append('ajax', '1');
        }
        fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LCSC.csrf},
            body: data instanceof FormData ? data : new URLSearchParams(data),
            credentials: 'same-origin'
        }).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return; }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (!json) return;
            // 鉴权失败统一弹窗
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return;
            }
            if (json.code === 0) {
                // 仅真正的写操作才递增父框架写序列号（读操作不触发其他标签页重载）
                if (studioIsWriteAction(data)) studioNotifyWrite();
                if (callback) callback(json.data, json.msg);
            } else {
                if (errCallback) errCallback(json.msg, json.code, json.data);
                else LCSC.toast(json.msg || '操作失败', 'error');
            }
        }).catch(function(e) {
            if (errCallback) errCallback('网络错误：'+e.message);
            else LCSC.toast('网络错误：' + e.message, 'error');
        });
    },
    /* 统一 AJAX GET */
    get: function(url, callback, errCallback) {
        fetch(url, {
            method: 'GET',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin'
        }).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return; }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (!json) return;
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return;
            }
            if (json.code === 0) { if (callback) callback(json.data, json.msg); }
            else { if (errCallback) errCallback(json.msg, json.code, json.data); else LCSC.toast(json.msg || '请求失败', 'error'); }
        }).catch(function(e) {
            if (errCallback) errCallback('网络错误：'+e.message);
            else LCSC.toast('网络错误：' + e.message, 'error');
        });
    },
    /* 按需注入同源脚本（懒加载）。同一 URL 只注入一次，重复调用自动排队回调。 */
    loadScriptOnce: function(url, onload, onerror) {
        if (!url) { if (onerror) onerror(); return; }
        if (!LCSC._scriptState) LCSC._scriptState = {};   // url -> '' | 'loading' | 'ok' | 'fail'
        if (!LCSC._scriptWaiters) LCSC._scriptWaiters = {}; // url -> [{ok, err}]
        var st = LCSC._scriptState[url];
        if (st === 'ok') { if (onload) onload(); return; }
        if (st === 'fail') { if (onerror) onerror(); return; }
        if (st === 'loading') {
            (LCSC._scriptWaiters[url] = LCSC._scriptWaiters[url] || []).push({ ok: onload, err: onerror });
            return;
        }
        LCSC._scriptState[url] = 'loading';
        LCSC._scriptWaiters[url] = [];
        var s = document.createElement('script');
        s.src = url;
        s.async = true;
        s.onload = function() {
            LCSC._scriptState[url] = 'ok';
            var ws = LCSC._scriptWaiters[url] || [];
            LCSC._scriptWaiters[url] = [];
            if (onload) onload();
            for (var i = 0; i < ws.length; i++) { if (ws[i].ok) ws[i].ok(); }
        };
        s.onerror = function() {
            LCSC._scriptState[url] = 'fail';
            var ws = LCSC._scriptWaiters[url] || [];
            LCSC._scriptWaiters[url] = [];
            if (onerror) onerror();
            for (var i = 0; i < ws.length; i++) { if (ws[i].err) ws[i].err(); }
        };
        document.head.appendChild(s);
    },
    fetchJson: function(url, opts) {
        opts = opts || {};
        opts.credentials = opts.credentials || 'same-origin';
        opts.headers = opts.headers || {};
        opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, opts).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return Promise.reject(new Error('鉴权失败')); }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return Promise.reject(new Error('鉴权失败'));
            }
            return json;
        });
    },
    /* 片段无感刷新（全站统一的「保存 / 筛选后刷新」方案） */
    fragmentRefresh: function (target, url, opt) {
        opt = opt || {};
        var el = typeof target === 'string' ? document.querySelector(target) : target;
        if (!el || !url) return Promise.resolve(null);

        var key = opt.busyKey || el.id || '_frag';
        if (this._fragBusy[key]) return Promise.resolve(null);
        this._fragBusy[key] = true;
        el.classList.add('is-loading');

        var self = this;
        var asJson = opt.json !== false;
        var p = asJson ? this.fetchJson(url) : fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        });

        return p.then(function (res) {
            var data = {};
            var html = '';
            if (asJson) {
                if (!res || res.code !== 0) throw new Error((res && res.msg) || '刷新失败');
                data = res.data || {};
                html = data.html || '';
            } else {
                html = res;
            }
            if (opt.mustContain && html.indexOf(opt.mustContain) < 0) throw new Error('返回内容异常');
            if (/<(html|body)[\s>]/i.test(html)) throw new Error('返回内容异常');

            el.innerHTML = html;
            if (typeof opt.onDone === 'function') opt.onDone(html, data);
            return html;
        })
        .catch(function (err) {
            if (err && err.message === '鉴权失败') return null;   // 已由 showAuthExpired 统一处理
            if (typeof opt.onFail === 'function') opt.onFail(err);
            else self.toast('刷新失败：' + ((err && err.message) ? err.message : '网络异常'), 'error');
            return null;
        })
        .then(function (r) {
            el.classList.remove('is-loading');
            self._fragBusy[key] = false;
            return r;
        });
    },
    isStrongPassword: function(pw) {
        if (!pw || String(pw).length < 8) return false;
        var c = 0;
        if (/[a-z]/.test(pw)) c++;
        if (/[A-Z]/.test(pw)) c++;
        if (/[0-9]/.test(pw)) c++;
        if (/[^a-zA-Z0-9]/.test(pw)) c++;
        return c >= 3;
    },
    partCode: function(id) {
        var n = parseInt(id, 10) || 0;
        return n > 0 ? 'P' + String(n).padStart(5, '0') : '';
    },
    /* 轻量 Toast 提示（全站统一） */
    toast: function(msg, type) {
        type = type || 'info';
        var colors = {success:'#22c55e', error:'#ef4444', warning:'#f59e0b', info:'#4f8ef7'};
        var hold   = (type === 'error') ? 8000 : ((type === 'warning') ? 5000 : 3000);
        var el = document.createElement('div');
        el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:8px;background:'+(colors[type] || colors.info)+';color:#fff;font-size:14px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.3);opacity:0;transition:opacity .3s;max-width:90vw;text-align:center;';
        el.textContent = msg;
        document.body.appendChild(el);
        el.style.opacity = '1';
        setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, hold);
    },
    showAuthExpired: function(isTimeout) {
        // 团队版iframe内鉴权失效：直接跳转父框架到登录页
        if (window.self !== window.top) {
            window.top.location.href = isTimeout ? 'login.php?reason=timeout' : 'login.php';
            return;
        }
        if (document.getElementById('authExpiredModal') || document.getElementById('ajaxTimeoutModal')) return;
        var modal = document.createElement('div');
        modal.id = 'authExpiredModal';
        modal.className = 'auth-expired-mask';
        var title = isTimeout ? '会话已超时' : '登录已失效';
        var desc = isTimeout ? '您的登录会话因长时间无操作已过期，请重新登录。' : '会话超时或CSRF校验失败，请重新登录';
        var loginUrl = isTimeout ? 'login.php?reason=timeout' : 'login.php';
        modal.innerHTML = '<div class="auth-expired-card">' +
            '<div class="auth-expired-ico">⏱️</div>' +
            '<div class="auth-expired-title">' + title + '</div>' +
            '<div class="auth-expired-desc">' + desc + '</div>' +
            '<a href="' + loginUrl + '" class="auth-expired-btn">重新登录</a>' +
            '</div>';
        document.body.appendChild(modal);
    },
    /* 拦截表单提交为AJAX（自动检测表单action） */
    interceptForm: function(formEl, callback, errCallback) {
        formEl.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            e.preventDefault();
            var fd = new FormData(formEl);
            var url = formEl.getAttribute('action');
            LCSC.post(url, fd, callback, errCallback);
        });
    }
};

(function(){
    function isPC(){ return window.innerWidth >= 769; }
    var tipEl = null;
    var currentHost = null;

    function ensureTip(){
        if (tipEl) return tipEl;
        tipEl = document.createElement('div');
        tipEl.id = 'lcscTip';
        tipEl.style.cssText = 'position:fixed;z-index:99998;max-width:340px;padding:6px 10px;background:var(--surface3,#272b3f);color:var(--text,#dde3f0);border:1px solid var(--border2,#343857);border-radius:6px;font-size:12px;line-height:1.5;box-shadow:0 4px 16px var(--shadow,rgba(0,0,0,.4));pointer-events:none;display:none;word-break:break-all;white-space:normal;';
        document.body.appendChild(tipEl);
        return tipEl;
    }
    function showTip(host, text){
        if (!text || !isPC()) return;
        var el = ensureTip();
        el.textContent = text;
        el.style.display = 'block';
        var r = host.getBoundingClientRect();
        // 定位：优先在元素上方，空间不足时下方
        var x = r.left + (r.width / 2);
        var tipW = el.offsetWidth, tipH = el.offsetHeight;
        var placeAbove = (r.top - tipH - 6) > 8;
        var top = placeAbove ? (r.top - tipH - 6) : (r.bottom + 6);
        var left = x - tipW / 2;
        // 边界保护
        if (left < 6) left = 6;
        if (left + tipW > window.innerWidth - 6) left = window.innerWidth - tipW - 6;
        el.style.left = left + 'px';
        el.style.top = top + 'px';
        currentHost = host;
    }
    function hideTip(){
        if (tipEl) tipEl.style.display = 'none';
        currentHost = null;
    }
    // 截断检测：scrollWidth > clientWidth 表示有溢出
    function isTruncated(el){
        return el.scrollWidth - el.clientWidth > 2;
    }
    // 取 Tooltip 内容：优先 data-tip，其次 title
    function getTipText(el){
        var t = el.getAttribute('data-tip');
        if (t) return t;
        t = el.getAttribute('title');
        if (t) return t;
        // 元素自身文本
        return (el.textContent || '').trim();
    }
    // 候选选择器：所有可能需要 Tooltip 的元素
    var SELECTOR = '.td-ellipsis, .alt-col, [data-tip], .row-second-val, .alt-info-val, .row-second-item span[title], .code-blue';
    document.addEventListener('mouseover', function(e){
        if (!isPC()) return;
        var el = e.target.closest(SELECTOR);
        if (!el || el === currentHost) return;
        var text = getTipText(el);
        if (!text) return;
        var explicit = el.hasAttribute('data-tip');
        var truncated = isTruncated(el);
        // .alt-col 等四列等宽容器总是显示（即使未截断也展示完整信息）
        var alwaysShow = el.classList && (el.classList.contains('alt-col'));
        if (explicit || truncated || alwaysShow) {
            showTip(el, text);
        }
    });
    document.addEventListener('mouseout', function(e){
        var el = e.target.closest(SELECTOR);
        if (el && el === currentHost) hideTip();
    });
    // 滚动/resize 隐藏
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);
})();

<?php if($_isStudioIframe): ?>
// ── 团队版iframe内：提示 studio_iframe.php 已接管导航拦截 ──
<?php endif; ?>
</script>
