<?php
declare(strict_types=1);
// studio_iframe.php — 团队版 iframe 内页面布局

?>
<!-- ══ 团队版iframe内嵌页面：仅渲染内容，不含导航 ══ -->
<body class="<?=isAdmin()?'is-admin':''?> studio-iframe-body">
<script>
(function() {
    'use strict';

    // ── 写操作序列号快照：记录本页加载时父框架的全局写序列号 ──
    try {
        window._studioLoadedWriteSeq = (window.parent && typeof window.parent.studioGetWriteSeq === 'function')
            ? window.parent.studioGetWriteSeq() : 0;
    } catch(e) { window._studioLoadedWriteSeq = 0; }

    // ── 页面标题映射 ──
    var _titleMap = {
        admin:'系统设置', bom:'项目 BOM', scan:'出入库', categories:'分类管理',
        locations:'库位管理', stale:'呆滞物料',
        import:'导入中心', stock:'流水中心', assets:'资产总览',
        data_health:'数据质量', export:'数据导出中心',
        change_password:'修改密码',
        personal:'工作台',
        pending:'待处理', parts:'物料列表', print:'标签打印',
        checklist:'库存盘点', home:'工作台'
    };
    function titleFromUrl(url) {
        var m = url.match(/[?&]v=([^&#]+)/);
        if (m && _titleMap[m[1]]) return _titleMap[m[1]];
        if (m && m[1]) return m[1];
        return '首页';
    }

    // ── 判断是否为站内导航链接 ──
    function isInternalNav(href) {
        if (!href || href.charAt(0) === '#' || href.startsWith('javascript:')) return false;
        // 绝对 URL：仅拦截同源 index.php
        if (href.startsWith('http') || href.startsWith('//')) {
            try {
                var u = new URL(href, location.origin);
                if (u.origin !== location.origin) return false;
                var idxPath = location.pathname.substring(0, location.pathname.lastIndexOf('/') + 1) + 'index.php';
                return u.pathname === idxPath;
            } catch(e) { return false; }
        }
        // 相对路径：指向 index.php 或无文件名（纯查询字符串）
        var file = href.split('?')[0].split('/').pop() || '';
        return file === 'index.php' || file === '';
    }

    // ── 将导航交给父框架处理 ──
    function navigateToParent(url) {
        // 清理残留参数
        url = url.replace(/([?&])tab_mode=[^&#]*&?/, '$1').replace(/[?&]$/, '');
        if (window.parent && window.parent.studioNav) {
            window.parent.studioNav(url, titleFromUrl(url));
        } else {
            // fallback：加 tab_mode 防嵌套
            var sep = url.indexOf('?') >= 0 ? '&' : '?';
            location.href = url + sep + 'tab_mode=studio';
        }
    }

    // ━━━━━━━ 防线1：<a> 点击拦截 ━━━━━━━
    document.addEventListener('click', function(e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        var href = a.getAttribute('href');
        if (!isInternalNav(href)) return;
        // target=_blank 不拦截（外部链接）
        if (a.target === '_blank') return;
        // data-skip-iframe-nav 属性：显式跳过拦截（页面内交互）
        if (a.hasAttribute('data-skip-iframe-nav')) return;
        var onclick = a.getAttribute('onclick') || '';
        if (/return\s+false/.test(onclick)) return;
        // 拦截：阻止默认跳转，交给父框架
        e.preventDefault();
        navigateToParent(href);
    }, true);

    // ━━━━━━━ 防线2：覆写 location.assign / location.replace ━━━━━━━
    try {
        var _origAssign = Location.prototype.assign;
        Location.prototype.assign = function(url) {
            if (isInternalNav(url)) { navigateToParent(url); return; }
            return _origAssign.call(this, url);
        };
    } catch(e) {}
    try {
        var _origReplace = Location.prototype.replace;
        Location.prototype.replace = function(url) {
            if (isInternalNav(url)) { navigateToParent(url); return; }
            return _origReplace.call(this, url);
        };
    } catch(e) {}

    // ━━━━━━━ 防线3：全局导航函数 ━━━━━━━
    window._iframeNavigate = function(url) {
        navigateToParent(url);
    };

    // ━━━━━━━ 防线3.5：父框架 postMessage 监听（admin 子页签动态切换） ━━━━━━━
    window.addEventListener('message', function(e) {
        if (e.origin !== location.origin) return;
        if (!e.data || e.data.type !== 'studioAdminSwitchTab') return;
        var tab = e.data.tab;
        if (!tab) return;
        var panels = document.querySelectorAll('.admin-panel');
        if (panels.length === 0) return; // 非 admin 页面忽略
        panels.forEach(function(p) { p.classList.remove('active'); });
        var target = document.getElementById('tab-' + tab);
        if (target) target.classList.add('active');
        var activeInput = document.getElementById('activeTab');
        if (activeInput) activeInput.value = tab;
        // 同步浏览器 URL（不重载页面）
        var newUrl = 'index.php?v=admin&tab=' + encodeURIComponent(tab) + '&tab_mode=studio';
        try { history.replaceState(null, '', newUrl); } catch(err) {}
        // 滚动到顶部
        try { window.scrollTo(0, 0); } catch(err) {}
    });

    // ━━━━━━━ 防线4：表单提交拦截 ━━━━━━━
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (form.hasAttribute('data-skip-iframe-nav')) return;
        var method = (form.method || 'get').toLowerCase();
        var action = form.getAttribute('action') || '';
        // 无 action 或 action 为空：默认提交到当前页，也是站内导航
        if (action === '' || action === '#') {
            if (method === 'get') {
                e.preventDefault();
                var fd = new FormData(form);
                var params = new URLSearchParams(fd).toString();
                var base = location.pathname + location.search.split('#')[0];
                var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + params;
                navigateToParent(url);
            }
            return;
        }
        // 非站内导航的 action（如 api.php）：不拦截
        if (!isInternalNav(action)) return;
        // GET 方法：走父框架导航
        if (method === 'get') {
            e.preventDefault();
            var fd2 = new FormData(form);
            var params2 = new URLSearchParams(fd2).toString();
            // 合并 action 中的查询参数和表单参数
            var act = action;
            if (params2) {
                act += (act.indexOf('?') >= 0 ? '&' : '?') + params2;
            }
            navigateToParent(act);
        }
        // POST 方法：到 API 的让它走（返回 JSON），到页面的拦截
        // 注：大部分 POST 表单走 api.php，返回 JSON 不跳转
        if (method === 'post') {
            setTimeout(function() {
                if (e.defaultPrevented) return;
                try {
                    if (window.parent && typeof window.parent.studioMarkWrite === 'function') {
                        window.parent.studioMarkWrite();
                    }
                } catch(err) {}
            }, 0);
        }
    }, true);

})();

// ── 页面加载完成后，通知父框架更新当前标签标题 ──
(function() {
    function updateParentTabTitle() {
        if (window.parent && window.parent !== window && typeof window.parent.studioUpdateTabTitle === 'function') {
            var pageTitle = <?=json_encode($pageTitle ?? '')?>;
            if (pageTitle) {
                window.parent.studioUpdateTabTitle(location.href, pageTitle);
            }
        }
    }
    function addTabModeToForms() {
        document.querySelectorAll('form[method="post"]').forEach(function(form) {
            if (!form.querySelector('input[name="tab_mode"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'tab_mode';
                input.value = 'studio';
                form.appendChild(input);
            }
        });
    }
    function init() {
        updateParentTabTitle();
        addTabModeToForms();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    // 监听后续动态添加的表单（如弹窗中的表单）
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            if (m.addedNodes) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) {
                        if (node.tagName === 'FORM' && node.method.toLowerCase() === 'post') {
                            if (!node.querySelector('input[name="tab_mode"]')) {
                                var inp = document.createElement('input');
                                inp.type = 'hidden';
                                inp.name = 'tab_mode';
                                inp.value = 'studio';
                                node.appendChild(inp);
                            }
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll('form[method="post"]').forEach(function(form) {
                                if (!form.querySelector('input[name="tab_mode"]')) {
                                    var inp2 = document.createElement('input');
                                    inp2.type = 'hidden';
                                    inp2.name = 'tab_mode';
                                    inp2.value = 'studio';
                                    form.appendChild(inp2);
                                }
                            });
                        }
                    }
                });
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
</script>
