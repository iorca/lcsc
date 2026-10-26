// modules/inventory/Assets/js/parts-init.js — 物料列表页 · 启动与表单拦截

(function(){
    var addForm = document.getElementById('addForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e){
            if (!validatePartForm('a', _addModalPlatType)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);
        LCSC.interceptForm(addForm, function(data, msg) {
            LCSC.toast(msg, 'success');
            closeOverlay('modalAdd');
            _filterState.page = 1;
            loadPartsList();
        }, function(msg) {
            LCSC.toast(msg, 'error');
        });
    }

    var editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e){
            if (!validatePartForm('e', _editModalPlatType)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);
        LCSC.interceptForm(editForm, function(data, msg) {
            LCSC.toast(msg, 'success');
            closeOverlay('modalEdit');
            loadPartsList();
        }, function(msg) {
            LCSC.toast(msg, 'error');
        });
    }

    var stockForm = document.getElementById('stockForm');
    if (stockForm) {
        // 回车（输入框内）等同点击「确认」，仍走二次确认 + loading 流程
        stockForm.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.target || {}).tagName === 'INPUT') {
                e.preventDefault();
                submitStock();
            }
        });
    }

    var deleteForm = document.getElementById('deleteForm');
    if (deleteForm) LCSC.interceptForm(deleteForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalDel');
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    var batchCatForm = document.getElementById('batchCatForm');
    if (batchCatForm) LCSC.interceptForm(batchCatForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchCat');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    var batchLocForm = document.getElementById('batchLocForm');
    if (batchLocForm) LCSC.interceptForm(batchLocForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchLoc');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    var batchRemForm = document.getElementById('batchRemForm');
    if (batchRemForm) LCSC.interceptForm(batchRemForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchRem');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });
})();

// ── 初始状态同步（筛选 chip 高亮 / 排序箭头 / 筛选状态标签，仅视觉同步）──
(function(){
    try { syncFilterChips(); renderSortArrows(); syncSortSelect(); } catch (e) {}
})();

// ── 回到顶部悬浮按钮 ──
(function(){
    try { initToTopBtn(); } catch (e) {}
})();

// ── 显示模式（舒适 / 紧凑）+ 紧凑模式悬停出图 ──
(function(){
    try {
        var m = (CFG && CFG.viewMode) || 'comfy';
        document.body.classList.toggle('mode-comfy', m === 'comfy');
        document.body.classList.toggle('mode-compact', m === 'compact');
        document.querySelectorAll('.vms-btn').forEach(function (b) {
            b.classList.toggle('is-on', b.getAttribute('data-mode') === m);
        });
        initImgTip();
    } catch (e) {}
})();
