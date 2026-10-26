// modules/bom/Assets/js/bom-form.js — BOM 管理页（?v=bom）· 各表单的 AJAX 提交拦截

var _bomRefreshing = false;
function bomReload(){
    if (_bomRefreshing) return;
    _bomRefreshing = true;
    // 1) 物料表无感刷新（已有 opacity 淡变逻辑）
    try {
        if (typeof loadBomItems === 'function') { loadBomItems(); }
    } catch(e) {}
    // 1.1) 缺料分析同步刷新：删除/编辑/匹配后清单已变，缺料表必须跟着变
    if (typeof refreshShortage === 'function') { try { refreshShortage(); } catch(e) {} }
    var url = location.pathname + location.search;
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            bomSwapRegion('bomVersionBar', doc);
            bomSwapRegion('bomVersionHint', doc);
            bomSwapRegion('bomHeadBar', doc);
        })
        .catch(function(){  })
        .then(function(){
            _bomRefreshing = false;
        });
}
function bomSwapRegion(id, doc){
    var cur = document.getElementById(id);
    var nw  = doc.getElementById(id);
    if (cur && nw) cur.replaceWith(nw.cloneNode(true));
    else if (cur && !nw) cur.remove(); // 新版已无该区域（如只读提示消失）
}

// 项目列表无感刷新（新建/编辑/删除项目后原位替换列表，不整页闪烁）
function bomReloadProjects(){
    var url = location.pathname + location.search;
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var cur = document.getElementById('bomProjectList');
            var nw  = doc.getElementById('bomProjectList');
            if (cur && nw) {
                cur.replaceWith(nw.cloneNode(true));
                if (typeof bomRestoreProjectFilter === 'function') bomRestoreProjectFilter();
                return;
            }
            // 兜底：列表容器异常时整页刷新
            window.location.reload();
        })
        .catch(function(){ window.location.reload(); });
}

// 新建/编辑项目表单
(function(){
    var f = document.getElementById('projectForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '操作成功', 'success');
        var act = document.getElementById('projectAction').value;
        closeOverlay('projectModal');
        if ((act === 'bom.create_project' || act === 'create_project') && data && data.project_id){
            bomReloadProjects();
            return;
        }
        if (document.getElementById('bomProjectList')) {
            // 列表页编辑：整卡信息（名称/类型/状态/优先级）可能都变了 → 原地刷新
            bomReloadProjects();
            return;
        }
        // 明细页编辑：更新页头名称/描述
        var newName = document.getElementById('projectName').value.trim();
        var newDesc = document.getElementById('projectDesc').value.trim();
        var nameEl = document.getElementById('bomProjectName');
        var descEl = document.getElementById('bomProjectDesc');
        if (nameEl && newName) nameEl.textContent = newName;
        if (descEl) {
            // 空/非空改用 `.is-empty` 表达（原为写行内 `display`，
            // 而视图里那条 `display:none` 已外置成类，写行内样式会赢不过类）
            descEl.classList.toggle('is-empty', !newDesc);
            descEl.textContent = newDesc;
        }
        bomReload();
    });
})();

// 添加物料表单
(function(){
    var f = document.getElementById('addItemForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '物料已添加', 'success');
        closeOverlay('addItemModal');
        bomReload();
    });
})();

// 批量删除表单
(function(){
    var f = document.getElementById('batchDeleteForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '批量删除成功', 'success');
        bomReload();
    });
})();

(function(){
    var f = document.getElementById('itemEditForm');
    if (!f) return;
    f.setAttribute('data-ajax-bound', '1');
    LCSC.interceptForm(f, function(data, msg){
        var extra = '';
        if (data && data.part_fields) extra = '（已同步物料参数：' + String(data.part_fields).split(',').join('、') + '）';
        LCSC.toast((msg || '清单行已更新') + extra, 'success');
        closeOverlay('itemEditModal');
        bomReload();
    });
})();

// 一键出库表单（整单出库：成功/失败都用弹窗提示，不在页面内嵌结果卡）
(function(){
    var f = document.getElementById('bomCheckoutForm');
    if (!f) return;
    LCSC.interceptForm(f,
        function(data, msg){
            bomCheckoutResultCard(true, { msg: msg, stats: (data && data.stats) || {}, message: (data && data.message) || '' });
            bomReload();
        },
        function(msg, code, data){
            bomCheckoutResultCard(false, { msg: msg, data: data || {} });
        }
    );
})();

// 行内删除项目/物料表单
document.querySelectorAll('form[action="api.php"]').forEach(function(f){
    if (f.id === 'projectForm' || f.id === 'addItemForm' || f.id === 'batchDeleteForm' || f.id === 'bomCheckoutForm' || f.id === 'importForm') return;
    if (f.hasAttribute('data-ajax-bound')) return;
    f.setAttribute('data-ajax-bound', '1');
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '操作成功', 'success');
        var actInput = f.querySelector('input[name="action"]');
        if (actInput && actInput.value === 'bom.delete_project'){
            bomReloadProjects();
        } else {
            bomReload();
        }
    });
});

document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || f.getAttribute('action') !== 'api.php') return;
    if (f.hasAttribute('data-ajax-bound')) return;
    var act = f.querySelector('input[name="action"]');
    if (!act) return;
    var v = act.value;
    if (v !== 'bom.delete_item' && v !== 'bom.batch_delete_items') return;

    e.preventDefault();
    e.stopImmediatePropagation();

    var submitNow = function(){
        LCSC.post('api.php', new FormData(f), function(data, msg){
            LCSC.toast(msg || '已删除', 'success');
            bomReload();   // 表格 + 缺料分析 + 版本栏 一并刷新
        }, function(msg){
            LCSC.toast(msg || '删除失败', 'error');
        });
    };

    // 第二段：确认已通过，放行提交
    if (f._bomConfirmPassed) { f._bomConfirmPassed = false; submitNow(); return; }

    // 第一段：拦下并弹确认框
    f._bomConfirmPassed = true;
    AdminUIConfirm({
        title: '操作确认',
        message: v === 'bom.batch_delete_items' ? '确认删除选中的物料？' : '确认删除该物料？',
        okText: '确认删除',
        onOk: function(){
            if (typeof f.requestSubmit === 'function') f.requestSubmit();
            else { f._bomConfirmPassed = false; submitNow(); }
        }
    });
}, true);
