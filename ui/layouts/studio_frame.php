<?php
declare(strict_types=1);

clearUiModeSession();

$freshLogin = !empty($_SESSION['fresh_login']);
unset($_SESSION['fresh_login']);

/** 侧边栏线性风格图标（stroke SVG，继承 currentColor，选中态自动变主题色） */
function studioIcon(string $name): string {
    static $paths = [
        'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
        'box'       => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'tags'      => '<path d="M20.6 13.4 12 22l-9-9V4h9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'swap'      => '<path d="m17 4 4 4-4 4M21 8H7"/><path d="m7 20-4-4 4-4M3 16h14"/>',
        'list'      => '<path d="M9 6h12M9 12h12M9 18h12"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a3 3 0 0 1 6 0"/><path d="m9 14 2 2 4-4"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
        'import'    => '<path d="M12 3v10m0 0 4-4m-4 4-4-4"/><path d="M4 17v3h16v-3"/>',
        'export'    => '<path d="M12 13V3m0 0 4 4m-4-4L8 7"/><path d="M4 17v3h16v-3"/>',
        'pie'       => '<path d="M12 3a9 9 0 1 0 9 9h-9V3z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15V3.5z"/>',
        'activity'  => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'file'      => '<path d="M6 2h8l4 4v16H6V2z"/><path d="M14 2v4h4"/><path d="M9 13h6M9 17h6"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.2 2.2M16.9 16.9l2.1 2.1M19.1 4.9 17 7M7 17l-2.2 2.2"/>',
        'link'      => '<path d="M10 14a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M15.5 13.6a6.5 6.5 0 0 1 6 6.4"/>',
        'monitor'   => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'database'  => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'map-pin'   => '<path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'archive'   => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
    ];
    $d = $paths[$name] ?? $paths['box'];

    return '<svg class="studio-nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}
?>
<!-- ══ 团队版主框架：侧边栏 + 多标签页 + iframe ══ -->
<body class="<?=isAdmin()?'is-admin':''?>">
<script>
// ── 防嵌套帧检测（终极安全网） ──
if (window.self !== window.top) {
    window.top.location.href = location.href;
}
</script>
<?php if (!empty($freshLogin)): ?>
<script>
// 刚登录：清除持久化标签页存档，主框架将只恢复工作台
try { localStorage.removeItem('studio_tabs'); } catch(e) {}
</script>
<?php endif; ?>
<div class="studio-layout">
  <!-- 左侧导航 -->
  <aside class="studio-sidebar" id="studioSidebar">
    <div class="studio-sidebar-logo">
        <?php if($siteLogo): ?><img src="<?=h($siteLogo)?>" alt=""><?php endif; ?>
        <?=h($siteTitle)?>
    </div>
    <nav class="studio-nav" id="studioNav">
      <!-- 工作台：首页，独立于分组之外，置于导航最顶部 -->
      <a class="studio-nav-item" data-page="home" onclick="studioNav('index.php','工作台')"><?=studioIcon('home')?><span>工作台</span></a>

      <!-- 物料库：物料基础档案维护 -->
      <div class="studio-nav-group first">物料库</div>
      <div class="studio-nav-sub">
        <a class="studio-nav-item" data-page="parts" onclick="studioNav('index.php?v=parts','物料列表')"><?=studioIcon('box')?><span>物料列表</span></a>
        <a class="studio-nav-item" data-page="categories" onclick="studioNav('index.php?v=categories','分类管理')"><?=studioIcon('tags')?><span>分类管理</span></a>
        <?php if(hasFeature('location_manage')): ?>
        <a class="studio-nav-item" data-page="locations" onclick="studioNav('index.php?v=locations','库位管理')"><?=studioIcon('map-pin')?><span>库位管理</span></a>
        <?php endif; ?>
      </div>

      <!-- 库存管理：库存业务 -->
      <div class="studio-nav-group">库存管理</div>
      <div class="studio-nav-sub">
        <a class="studio-nav-item" data-page="scan" onclick="studioNav('index.php?v=scan','出入库管理')"><?=studioIcon('swap')?><span>出入库管理</span></a>
        <a class="studio-nav-item" data-page="stock" onclick="studioNav('index.php?v=stock','流水中心')"><?=studioIcon('list')?><span>流水中心</span></a>
        <a class="studio-nav-item" data-page="stale" onclick="studioNav('index.php?v=stale','呆滞物料')"><?=studioIcon('archive')?><span>呆滞物料</span></a>
        <?php if(hasFeature('checklist')): ?>
        <a class="studio-nav-item" data-page="checklist" onclick="studioNav('index.php?v=checklist','库存盘点')"><?=studioIcon('clipboard')?><span>库存盘点</span></a>
        <?php endif; ?>
      </div>

      <!-- 项目BOM：项目与 BOM 业务 -->
      <?php if(hasFeature('bom_basic')): ?>
      <div class="studio-nav-group">项目BOM</div>
      <div class="studio-nav-sub">
        <a class="studio-nav-item" data-page="bom" onclick="studioNav('index.php?v=bom','BOM 项目')"><?=studioIcon('layers')?><span>BOM 项目</span></a>
      </div>
      <?php endif; ?>

      <!-- 数据中心：数据、日志类功能（操作记录已合并至流水中心日志视图） -->
      <div class="studio-nav-group">数据中心</div>
      <div class="studio-nav-sub">
        <a class="studio-nav-item" data-page="import" onclick="studioNav('index.php?v=import','导入中心')"><?=studioIcon('import')?><span>导入中心</span></a>
        <?php if(hasFeature('export_simple')): ?>
        <a class="studio-nav-item" data-page="export" onclick="studioNav('index.php?v=export','导出中心')"><?=studioIcon('export')?><span>导出中心</span></a>
        <?php endif; ?>
        <?php if(hasFeature('feature_asset_stats')): ?>
        <a class="studio-nav-item" data-page="assets" onclick="studioNav('index.php?v=assets','资产总览')"><?=studioIcon('pie')?><span>资产总览</span></a>
        <?php endif; ?>
        <a class="studio-nav-item" data-page="data_health" onclick="studioNav('index.php?v=data_health','数据质量')"><?=studioIcon('activity')?><span>数据质量</span></a>
      </div>

      <!-- 系统设置：唯一支持展开/折叠的分组 -->
      <?php if(isPrimaryAdmin()): ?>
      <div class="studio-nav-group studio-nav-group-toggle" onclick="studioToggleAdminGroup(event)"><span>系统设置</span><span class="studio-nav-chevron" id="adminChevron">▾</span></div>
      <div class="studio-nav-sub is-hidden" id="adminSubNav">
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="settings" onclick="studioNav('index.php?v=admin&tab=settings','网站设置')"><?=studioIcon('gear')?><span>网站设置</span></a>
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="platforms" onclick="studioNav('index.php?v=admin&tab=platforms','平台管理')"><?=studioIcon('link')?><span>平台管理</span></a>
        <?php if(hasFeature('multi_user')): ?>
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="users" onclick="studioNav('index.php?v=admin&tab=users','用户管理')"><?=studioIcon('users')?><span>用户管理</span></a>
        <?php endif; ?>
        <?php if(hasFeature('multi_user')): /* 系统监控内含在线用户 / 登录用户数， */ ?>
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="monitor" onclick="studioNav('index.php?v=admin&tab=monitor','系统监控')"><?=studioIcon('monitor')?><span>系统监控</span></a>
        <?php endif; ?>
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="backup" onclick="studioNav('index.php?v=admin&tab=backup','数据备份')"><?=studioIcon('database')?><span>数据备份</span></a>
        <?php if(hasFeature('feature_log_view')): ?>
        <a class="studio-nav-item studio-admin-sub-item" data-admin-tab="trace" onclick="studioNav('index.php?v=admin&tab=trace','溯源日志')"><?=studioIcon('search')?><span>溯源日志</span></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </nav>
    <!-- 退出按钮固定侧边栏底部，与业务菜单留白隔离；附侧边栏收起/展开开关 -->
    <div class="studio-sidebar-footer">
      <button class="icon-btn" id="sidebarToggleBtn" onclick="studioToggleSidebar()" title="收起/展开侧边栏">⟨</button>
      <button class="icon-btn" onclick="toggleTheme()" title="切换主题">🌓</button>
      <?php // 修改密码入口：原改密弹窗只在「用户管理」Tab（团队版专属）里，
            ?>
      <button class="icon-btn studio-pw-btn" onclick="location.href='index.php?v=change_password'" title="修改密码">🔑</button>
      <form method="post" action="logout.php" class="form-inline">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <button type="submit" class="studio-logout-btn">退出</button>
      </form>
    </div>
  </aside>
  <!-- 右侧：标签栏 + 面包屑 + 内容 -->
  <div class="studio-main">
    <div class="studio-tabs">
      <div class="studio-tabs-scroll" id="studioTabs"></div>
      <!-- 标签栏右侧操作区：滚动箭头（溢出时显示）+ 关闭全部；不随标签横向滚动 -->
      <div class="studio-tab-actions">
        <button type="button" class="studio-tab-arrow" id="studioTabArrowL" onclick="studioScrollTabs(-1)" title="向左滚动">‹</button>
        <button type="button" class="studio-tab-arrow" id="studioTabArrowR" onclick="studioScrollTabs(1)" title="向右滚动">›</button>
        <button type="button" class="studio-tab-action" onclick="studioCloseAllTabs()" title="关闭全部标签（工作台保留）">✕ 全部</button>
      </div>
    </div>
    <div class="studio-breadcrumb" id="studioBreadcrumb"></div>
    <div class="studio-content" id="studioContent"></div>
  </div>
</div>

<!-- 网络断开 / 服务器异常 本地提示层（主框架内所有 iframe 共用这一份） -->
<script src="modules/system/Assets/js/netguard.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../../modules/system/Assets/js/netguard.js')?>"></script>

<!-- 右键菜单 -->
<div class="studio-ctx-menu" id="studioCtxMenu">
  <button class="studio-ctx-item" onclick="studioCtxAction('close')">关闭</button>
  <button class="studio-ctx-item" onclick="studioCtxAction('closeOther')">关闭其他</button>
  <button class="studio-ctx-item danger" onclick="studioCtxAction('closeAll')">关闭全部</button>
</div>
<script>
// ── 主题切换（团队版主框架独立处理：切换后广播到所有已打开 iframe 标签页） ──
function applyTheme(t){
    document.documentElement.setAttribute('data-theme',t);
    try{ localStorage.setItem('theme',t); }catch(e){}
    try{ document.querySelectorAll('iframe').forEach(function(f){ if(f.contentWindow) f.contentWindow.postMessage({type:'lcsc:theme',theme:t},'*'); }); }catch(e){}
}
function toggleTheme(){applyTheme(document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark');}
(function(){var s=null;try{s=localStorage.getItem('theme');}catch(e){}if(s)applyTheme(s);else{var c=document.documentElement.getAttribute('data-theme')||'light';document.documentElement.setAttribute('data-theme',c);}})();

// ── 团队版 Toast 提示（主框架不加载 LCSC 全局对象，独立实现） ──
function studioToast(msg) {
    var el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:10px 24px;background:#1e293b;color:#f1f5f9;border-radius:8px;font-size:13px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,.3);transition:opacity .3s';
    document.body.appendChild(el);
    setTimeout(function(){ el.style.opacity='0'; setTimeout(function(){ el.remove(); },300); }, 2500);
}

// ── 团队版多标签页管理 ──
var _studioTabs = [];
var _studioCtxTarget = null;
// ── 全局写操作序列号（跨标签页数据实时刷新机制）──
var _studioWriteSeq = 0;
window.studioMarkWrite = function() {
    _studioWriteSeq++;
    return _studioWriteSeq;
};
// 供 iframe 内页面加载时获取当前序列号快照
window.studioGetWriteSeq = function() {
    return _studioWriteSeq;
};
// 工作台固定标签（首页，禁止关闭）的 id。
// **必须由 tabId() 推导，不可手写字面量** —— 标签 id 的唯一生成规则是
var _studioHomeId = tabId('index.php');
// 系统设置折叠组手动开关状态（持久化；激活 admin 页时强制展开）
var _adminGroupOpen = false;
try { _adminGroupOpen = localStorage.getItem('studio_admin_group') === '1'; } catch(e) {}
function studioToggleAdminGroup(e) {
    if (e) e.stopPropagation();
    _adminGroupOpen = !_adminGroupOpen;
    try { localStorage.setItem('studio_admin_group', _adminGroupOpen ? '1' : '0'); } catch(err) {}
    studioRenderTabs();
}

function tabId(url) {
    return url.replace(/[^a-zA-Z0-9]/g, '_');
}

// 提取 ?v=xxx 模块标识，用于同模块导航去重
function studioModuleOf(url) {
    var m = url.match(/[?&]v=([^&]+)/);
    return m ? m[1] : '';
}
// 提取 ?tab=xxx 子页签（仅 admin 用）
function studioAdminSubTabOf(url) {
    if (studioModuleOf(url) !== 'admin') return '';
    var m = url.match(/[?&]tab=([^&]+)/);
    return m ? m[1] : '';
}
// 标签去重键：
function studioTabKey(url) {
    var mod = studioModuleOf(url);
    if (mod === 'admin') {
        return 'admin::' + (studioAdminSubTabOf(url) || 'settings');
    }
    return mod;
}

/* 把指定标签滚动到可视区内（标签宽度跟随内容，超出标签栏时横向滚动） */
function studioRevealTab(id) {
    var bar = document.getElementById('studioTabs');
    if (!bar) return;
    var el = document.getElementById('stab_' + id);
    if (!el) return;
    var left = el.offsetLeft;
    var right = left + el.offsetWidth;
    if (left < bar.scrollLeft) {
        bar.scrollLeft = Math.max(0, left - 8);
    } else if (right > bar.scrollLeft + bar.clientWidth) {
        bar.scrollLeft = right - bar.clientWidth + 8;
    }
}

function studioNav(url, title) {
    // 规范化：去除残留 tab_mode 参数
    url = url.replace(/([?&])tab_mode=[^&#]*&?/, '$1').replace(/[?&]$/, '');
    var id = tabId(url);
    var existing = _studioTabs.find(function(t) { return t.id === id; });
    if (existing) { studioSwitchTab(id); return; }

    // 同模块去重：若已存在相同去重键的标签则复用并刷新内容
    // （admin 各子页键不同 → 各自独立标签；其余模块同模块复用）
    var newModule = studioModuleOf(url);
    var newKey = studioTabKey(url);
    if (newKey) {
        var sameModuleTab = _studioTabs.find(function(t) { return studioTabKey(t.url) === newKey; });
        if (sameModuleTab) {
            var oldId = sameModuleTab.id;
            sameModuleTab.id = id;
            sameModuleTab.url = url;
            sameModuleTab.title = title;
            // 重命名 iframe id 以匹配新标签 id
            var iframe = document.getElementById('iframe_' + oldId);
            if (iframe) {
                iframe.id = 'iframe_' + id;
                // admin 模块优先尝试动态切换 panel（不重载 iframe）
                if (newModule === 'admin') {
                    var newSub = studioAdminSubTabOf(url);
                    var ready = false;
                    try {
                        ready = iframe.contentDocument && iframe.contentDocument.readyState === 'complete';
                    } catch(e) { ready = false; }
                    var sameQuery = false;
                    if (newSub && ready) {
                        try {
                            var normQ = function(s) {
                                return (s || '').replace(/^\?/, '')
                                    .split('&').filter(function(p) {
                                        return p && p.indexOf('tab_mode=') !== 0;
                                    }).sort().join('&');
                            };
                            var curQ = iframe.contentWindow.location.search;
                            var tgtQ = url.indexOf('?') >= 0 ? url.substring(url.indexOf('?')) : '';
                            sameQuery = (normQ(curQ) === normQ(tgtQ));
                        } catch(e2) { sameQuery = false; }
                    }
                    if (newSub && ready && sameQuery) {
                        try {
                            iframe.contentWindow.postMessage({
                                type: 'studioAdminSwitchTab',
                                tab: newSub,
                                title: title
                            }, location.origin);
                            studioRenderTabs();
                            studioSwitchTab(id);
                            studioSaveTabs();
                            return;
                        } catch(e) { /* 失败则降级为重载 */ }
                    }
                }
                // 默认：用新 URL 重载 iframe
                var separator = url.indexOf('?') >= 0 ? '&' : '?';
                iframe.src = url + separator + 'tab_mode=studio';
            }
            studioRenderTabs();
            studioSwitchTab(id);
            studioSaveTabs();
            return;
        }
    }

    // 标签数量不设上限：标签宽度跟随标题内容，超出标签栏后横向滚动
    _studioTabs.push({ id: id, title: title, url: url, active: true, fixed: (url === 'index.php') });
    _studioTabs.forEach(function(t) { t.active = (t.id === id); });
    studioRenderTabs();
    studioLoadContent(url);
    studioSaveTabs();
}

function studioUpdateTabTitle(url, title) {
    var id = tabId(url);
    var tab = _studioTabs.find(function(t) { return t.id === id; });
    if (!tab) return;
    tab.title = title;
    studioRenderTabs();
    studioSaveTabs();
}

/* 标签的「身份」= 模块(v) + 记录 id。 */
function studioTabIdentity(url) {
    try {
        var u = new URL(url, location.href);
        return (u.searchParams.get('v') || '') + '|' + (u.searchParams.get('id') || '');
    } catch(e) { return ''; }
}

function studioSwitchTab(id) {
    var tab = _studioTabs.find(function(t) { return t.id === id; });
    if (!tab) return;
    if (!document.getElementById('iframe_' + id)) studioLoadContent(tab.url);
    _studioTabs.forEach(function(t) { t.active = (t.id === id); });
    studioRenderTabs();
    // 切换时只显示/隐藏已有 iframe，不重建
    var container = document.getElementById('studioContent');
    if (container) {
        container.querySelectorAll('iframe.studio-iframe').forEach(function(f) {
            var active = (f.id === 'iframe_' + id);
            // 脏检查：本 iframe 加载后全局发生过其他写操作 → 数据已过期需刷新
            // 静默换入刷新：后台新建隐藏 iframe 加载最新内容，加载完成后原地替换，
            // 刷新期间旧内容持续可见，彻底消除切换时的白屏闪烁
            if (active && !f._studioRefreshing) {
                var loadedSeq = null;
                try { loadedSeq = f.contentWindow ? f.contentWindow._studioLoadedWriteSeq : null; } catch(e) { loadedSeq = null; }
                var dirty = (loadedSeq !== null && loadedSeq !== undefined && loadedSeq !== _studioWriteSeq);
                var drift = false;
                try {
                    var liveHref = (f.contentWindow && f.contentWindow.location) ? f.contentWindow.location.href : '';
                    if (liveHref && liveHref !== 'about:blank') {
                        drift = (studioTabIdentity(liveHref) !== studioTabIdentity(tab.url));
                    }
                } catch(e2) { drift = false; }
                if (dirty || drift) {
                    f._studioRefreshing = true;
                    var sep = tab.url.indexOf('?') >= 0 ? '&' : '?';
                    var fresh = document.createElement('iframe');
                    fresh.className = 'studio-iframe';
                    fresh.setAttribute('data-tab-id', id);
                    fresh.src = tab.url + sep + 'tab_mode=studio';
                    fresh.style.display = 'none';
                    fresh.addEventListener('load', function(){
                        // 标签已被关闭或改名（模块去重复用）→ 丢弃本次刷新副本，防止孤儿 iframe
                        var stillExists = _studioTabs.some(function(t) { return t.id === id; });
                        if (!stillExists) { fresh.src = 'about:blank'; fresh.remove(); return; }
                        // 加载完成后原地换入：接管 id → 移除旧 iframe → 按当前激活态显示
                        var old = document.getElementById('iframe_' + id);
                        fresh.id = 'iframe_' + id;
                        fresh._studioRefreshing = false;
                        if (old && old !== fresh) { old.src = 'about:blank'; old.remove(); }
                        var isActive = _studioTabs.some(function(t) { return t.id === id && t.active; });
                        fresh.style.display = isActive ? 'block' : 'none';
                    });
                    container.appendChild(fresh);
                }
            }
            if (active && f._studioRefreshing) {
                // 静默刷新进行中：旧 iframe 保持可见（约等于最新数据），不闪黑
                f.style.display = 'block';
            } else {
                f.style.display = active ? 'block' : 'none';
            }
        });
    }
    studioSaveTabs();
}

// ── 移除标签对应的所有 iframe（含静默换入刷新产生的隐藏副本）──
function studioRemoveIframes(id) {
    document.querySelectorAll('iframe[data-tab-id="' + id + '"]').forEach(function(f) {
        f.src = 'about:blank'; f.remove();
    });
    var iframe = document.getElementById('iframe_' + id);
    if (iframe) { iframe.src = 'about:blank'; iframe.remove(); }
}

function studioCloseTab(id) {
    var tab = _studioTabs.find(function(t) { return t.id === id; });
    if (!tab || tab.fixed) return; // 工作台固定标签禁止关闭
    var idx = _studioTabs.findIndex(function(t) { return t.id === id; });
    if (idx < 0) return;
    _studioTabs.splice(idx, 1);
    // 移除对应 iframe 释放内存
    studioRemoveIframes(id);
    if (_studioTabs.length === 0) { studioNav('index.php', '工作台'); return; }
    var nextIdx = idx > 0 ? idx - 1 : 0;
    _studioTabs.forEach(function(t) { t.active = false; });
    _studioTabs[nextIdx].active = true;
    studioRenderTabs();
    studioSwitchTab(_studioTabs[nextIdx].id);
    studioSaveTabs();
}

function studioCloseOtherTabs(id) {
    // 工作台固定标签始终保留
    _studioTabs.forEach(function(t) {
        if (t.id !== id && !t.fixed) {
            studioRemoveIframes(t.id);
        }
    });
    _studioTabs = _studioTabs.filter(function(t) { return t.id === id || t.fixed; });
    if (_studioTabs.length > 0) {
        var act = _studioTabs.some(function(t) { return t.id === id; }) ? id : _studioTabs[0].id;
        _studioTabs.forEach(function(t) { t.active = (t.id === act); });
        studioRenderTabs();
        studioSwitchTab(act);
    }
    studioSaveTabs();
}

function studioCloseAllTabs() {
    // 移除所有非固定 iframe（工作台保留）
    var container = document.getElementById('studioContent');
    _studioTabs.forEach(function(t) {
        if (!t.fixed) {
            studioRemoveIframes(t.id);
        }
    });
    _studioTabs = _studioTabs.filter(function(t) { return t.fixed; });
    if (_studioTabs.length > 0) {
        _studioTabs.forEach(function(t) { t.active = (t.id === _studioHomeId); });
        studioRenderTabs();
        studioSwitchTab(_studioHomeId);
        studioSaveTabs();
    } else {
        studioNav('index.php', '工作台');
    }
}

function studioRenderTabs() {
    var container = document.getElementById('studioTabs');
    if (!container) return;
    var html = '';
    _studioTabs.forEach(function(t) {
        // 工作台固定标签不渲染关闭按钮
        var closeBtn = t.fixed ? '' : '<span class="studio-tab-close" onclick="event.stopPropagation();studioCloseTab(\'' + t.id + '\')">×</span>';
        html += '<div class="studio-tab ' + (t.active ? 'active' : '') + '" id="stab_' + t.id + '" onclick="studioSwitchTab(\'' + t.id + '\')" oncontextmenu="studioTabCtx(event,\'' + t.id + '\')">'
            + '<span title="' + t.title.replace(/"/g, '&quot;') + '">' + t.title + '</span>'
            + closeBtn
            + '</div>';
    });
    container.innerHTML = html;
    studioUpdateArrows();
    // 激活标签始终滚动到可视区（标签宽度随内容，超出标签栏时横向滚动）
    if (_studioTabs.some(function(t){ return t.active; })) {
        var act = _studioTabs.find(function(t) { return t.active; });
        if (act) studioRevealTab(act.id);
    }
    document.querySelectorAll('.studio-nav-item').forEach(function(el) { el.classList.remove('active'); });
    var activeTab = _studioTabs.find(function(t) { return t.active; });
    var adminActive = false;
    if (activeTab) {
        var pageKey = studioModuleOf(activeTab.url) || 'home';
        var navItem = document.querySelector('.studio-nav-item[data-page="' + pageKey + '"]');
        if (navItem) navItem.classList.add('active');
        adminActive = (pageKey === 'admin');
        // admin 子页面也高亮父级 "系统设置" 项
        if (adminActive) {
            var adminNav = document.querySelector('.studio-nav-item[data-page="admin"]');
            if (adminNav) adminNav.classList.add('active');
        }
    }
    // 系统设置折叠组：激活 admin 时强制展开；侧栏收起（图标态）时也强制展开，
    var adminSubNav = document.getElementById('adminSubNav');
    if (adminSubNav) {
        var sidebarCollapsed = document.getElementById('studioSidebar')
            && document.getElementById('studioSidebar').classList.contains('collapsed');
        var groupOpen = adminActive || _adminGroupOpen || sidebarCollapsed;
        adminSubNav.classList.toggle('is-hidden', !groupOpen);
        var chevron = document.getElementById('adminChevron');
        if (chevron) chevron.textContent = groupOpen ? '▾' : '▸';
        if (pageKey === 'admin') {
            var tabMatch = activeTab.url.match(/[?&]tab=([^&]+)/);
            var currentAdminTab = tabMatch ? tabMatch[1] : 'settings';
            adminSubNav.querySelectorAll('.studio-admin-sub-item').forEach(function(el) {
                el.classList.toggle('active', el.getAttribute('data-admin-tab') === currentAdminTab);
            });
        }
    }
    studioUpdateBreadcrumb();
}

// ── 标签栏滚动箭头 ──
function studioScrollTabs(dir) {
    var c = document.getElementById('studioTabs');
    if (c) c.scrollBy({ left: dir * 220, behavior: 'smooth' });
}
function studioUpdateArrows() {
    var c = document.getElementById('studioTabs');
    if (!c) return;
    var l = document.getElementById('studioTabArrowL');
    var r = document.getElementById('studioTabArrowR');
    if (!l || !r) return;
    var overflow = c.scrollWidth > c.clientWidth + 4;
    l.classList.toggle('hidden', !overflow || c.scrollLeft <= 4);
    r.classList.toggle('hidden', !overflow || c.scrollLeft >= c.scrollWidth - c.clientWidth - 4);
}

// ── 面包屑：首页 > 分组 > 当前页面 ──
function studioUpdateBreadcrumb() {
    var el = document.getElementById('studioBreadcrumb');
    if (!el) return;
    var activeTab = _studioTabs.find(function(t) { return t.active; });
    if (!activeTab) { el.innerHTML = ''; return; }
    var module = studioModuleOf(activeTab.url);
    if (!module) { el.innerHTML = '<span class="crumb-current">首页</span>'; return; }
    var group = '', title = activeTab.title;
    if (module === 'admin') {
        // admin：分组名取折叠组标题，页面名取子菜单文字
        var sub = studioAdminSubTabOf(activeTab.url);
        var subEl = document.querySelector('.studio-admin-sub-item[data-admin-tab="' + sub + '"]');
        if (subEl) title = subEl.textContent.replace(/[▾▸]/g, '').trim();
        var gLabel = document.querySelector('.studio-nav-group-toggle > span');
        if (gLabel) group = gLabel.textContent.trim();
    } else {
        var navItem = document.querySelector('.studio-nav-item[data-page="' + module + '"]');
        if (navItem) {
            var subBox = navItem.closest('.studio-nav-sub');
            if (subBox) {
                var prev = subBox.previousElementSibling;
                if (prev && prev.classList.contains('studio-nav-group')) {
                    group = prev.textContent.replace(/[▾▸]/g, '').trim();
                }
            }
        }
    }
    var html = '<span class="crumb-root" onclick="studioSwitchTab(_studioHomeId)">首页</span>';
    if (group) html += '<span class="crumb-sep">›</span><span class="crumb-group">' + group + '</span>';
    // 当前页可点击：重载 iframe 实现回退刷新
    html += '<span class="crumb-sep">›</span><span class="crumb-current" title="点击刷新当前页" onclick="studioReloadTab(\'' + activeTab.id + '\')">' + title + '</span>';
    el.innerHTML = html;
}

// 重载当前标签的 iframe（面包屑点击回退）
function studioReloadTab(id) {
    var tab = _studioTabs.find(function(t) { return t.id === id; });
    if (!tab) return;
    var iframe = document.getElementById('iframe_' + id);
    if (!iframe) return;
    var separator = tab.url.indexOf('?') >= 0 ? '&' : '?';
    iframe.src = tab.url + separator + 'tab_mode=studio';
}

function studioLoadContent(url) {
    var container = document.getElementById('studioContent');
    if (!container) return;
    var separator = url.indexOf('?') >= 0 ? '&' : '?';
    var iframeUrl = url + separator + 'tab_mode=studio';
    var id = tabId(url);

    // 隐藏所有已有 iframe（不销毁，切换时无需重载）
    var frames = container.querySelectorAll('iframe.studio-iframe');
    frames.forEach(function(f) { f.style.display = 'none'; });

    // 查找是否已有该 iframe
    var existing = document.getElementById('iframe_' + id);
    if (existing) {
        // 已有：直接显示，无白屏闪烁
        existing.style.display = 'block';
        return;
    }

    // 新建：创建 iframe 并显示
    var iframe = document.createElement('iframe');
    iframe.id = 'iframe_' + id;
    iframe.className = 'studio-iframe';
    iframe.src = iframeUrl;
    iframe.style.cssText = 'width:100%;height:100%;border:none;display:block';
    container.appendChild(iframe);
}

function studioSaveTabs() {
    try { localStorage.setItem('studio_tabs', JSON.stringify(_studioTabs)); } catch(e) {}
}

function studioRestoreTabs() {
    try {
        var saved = localStorage.getItem('studio_tabs');
        if (saved) {
            _studioTabs = JSON.parse(saved);
            if (!Array.isArray(_studioTabs) || _studioTabs.length === 0) { studioNav('index.php', '工作台'); return; }
            // 工作台固定标签：确保存在且不可关闭（兼容旧存档）
            _studioTabs.forEach(function(t) { t.fixed = (t.url === 'index.php'); });
            var homeTab = _studioTabs.find(function(t) { return t.fixed; });
            if (!homeTab) {
                homeTab = { id: _studioHomeId, title: '工作台', url: 'index.php', active: false, fixed: true };
                _studioTabs.unshift(homeTab);
            }
            // 确保只有一个active
            var hasActive = _studioTabs.some(function(t) { return t.active; });
            if (!hasActive) _studioTabs[0].active = true;
            studioRenderTabs();
            var active = _studioTabs.find(function(t) { return t.active; });
            if (active) studioLoadContent(active.url);
            return;
        }
    } catch(e) {}
    studioNav('index.php', '工作台');
}

// 右键菜单
function studioTabCtx(e, id) {
    e.preventDefault();
    e.stopPropagation();
    _studioCtxTarget = id;
    var menu = document.getElementById('studioCtxMenu');
    menu.style.left = e.clientX + 'px';
    menu.style.top = e.clientY + 'px';
    menu.classList.add('show');
}
function studioCtxAction(action) {
    var menu = document.getElementById('studioCtxMenu');
    menu.classList.remove('show');
    if (!_studioCtxTarget) return;
    if (action === 'close') studioCloseTab(_studioCtxTarget);
    else if (action === 'closeOther') studioCloseOtherTabs(_studioCtxTarget);
    else if (action === 'closeAll') studioCloseAllTabs();
    _studioCtxTarget = null;
}
document.addEventListener('click', function() {
    var menu = document.getElementById('studioCtxMenu');
    if (menu) menu.classList.remove('show');
});

// ── 侧边栏收起/展开（持久化；收起时仅显示图标，悬停原生 title 提示页面名） ──
function studioToggleSidebar() {
    var sb = document.getElementById('studioSidebar');
    if (!sb) return;
    var collapsed = sb.classList.toggle('collapsed');
    try { localStorage.setItem('studio_sidebar_collapsed', collapsed ? '1' : '0'); } catch(e) {}
    var btn = document.getElementById('sidebarToggleBtn');
    if (btn) btn.textContent = collapsed ? '⟩' : '⟨';
    if (typeof studioRenderTabs === 'function') studioRenderTabs();
}
(function studioInitSidebar(){
    var collapsed = false;
    try { collapsed = localStorage.getItem('studio_sidebar_collapsed') === '1'; } catch(e) {}
    var sb = document.getElementById('studioSidebar');
    if (collapsed && sb) sb.classList.add('collapsed');
    if (typeof studioRenderTabs === 'function') studioRenderTabs();
    var btn = document.getElementById('sidebarToggleBtn');
    if (btn) btn.textContent = collapsed ? '⟩' : '⟨';
    // 为每个导航项补 title（收起态鼠标悬停停留时显示页面名）
    document.querySelectorAll('.studio-nav-item').forEach(function(el) {
        var label = el.querySelector('span:not(.studio-nav-ico)');
        if (label && !el.getAttribute('title')) el.setAttribute('title', label.textContent.trim());
    });
})();

// 页面加载时恢复标签 + 标签栏滚动箭头随滚动/窗口尺寸刷新
window.addEventListener('message', function(ev){
    var d = ev.data || {};
    if (!d || d.type !== 'lcsc:netguard') return;
    if (typeof window.studioNetGuard !== 'function') return;
    if (d.state === 'ok') window.studioNetGuard('ok');
    else window.studioNetGuard(d.state);
});

studioRestoreTabs();
(function(){
    var tabsEl = document.getElementById('studioTabs');
    if (tabsEl) {
        tabsEl.addEventListener('scroll', studioUpdateArrows);
        // 鼠标滚轮横向滚动标签栏（触控板/Shift+滚轮同样生效）
        tabsEl.addEventListener('wheel', function(e){
            if (tabsEl.scrollWidth <= tabsEl.clientWidth) return;
            var delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
            if (!delta) return;
            e.preventDefault();
            tabsEl.scrollLeft += delta;
        }, {passive:false});
    }
    window.addEventListener('resize', studioUpdateArrows);
})();
</script>
</body>
</html>
<?php

exit;
?>
