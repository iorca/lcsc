// modules/bom/Assets/js/bom-modal.js — BOM 管理页（?v=bom）· 通用弹窗、项目弹窗、导入弹窗

function openOverlay(id){document.getElementById(id).classList.add('open');}
function closeOverlay(id){document.getElementById(id).classList.remove('open');}

function openProjectModal(data){
    var d = (typeof data === 'object' && data) ? data : null;
    var title = document.getElementById('projectModalTitle');
    var action = document.getElementById('projectAction');
    var pid = document.getElementById('projectId');
    var name = document.getElementById('projectName');
    var desc = document.getElementById('projectDesc');
    var vm = document.getElementById('projectVersionManaged');
    var sv = document.getElementById('projectStartVersion');
    if (d && d.id){
        title.textContent = '编辑 BOM 项目';
        action.value = 'bom.update_project';
        pid.value = d.id;
        name.value = d.name || '';
        desc.value = d.description || '';
        if (vm){
            var managed = parseInt(d.version_managed, 10);
            managed = (managed === 0 || managed === 2) ? 0 : 1;
            vm.checked = !!managed;
            if (sv) sv.disabled = !managed;
        }
        if (sv) sv.value = (d.start_version && d.start_version !== '工作副本') ? d.start_version : '';
        // 类型 / 状态 / 优先级
        var pt = document.getElementById('projectType');
        if (pt) pt.value = d.project_type || '';
        var ps = document.getElementById('projectStatus');
        if (ps) ps.value = d.status || 'draft';
        var pp = document.getElementById('projectPriority');
        if (pp) pp.value = String(parseInt(d.priority, 10) || 0);
        setProjectExtraVisible(true);
    } else {
        title.textContent = '新建 BOM 项目';
        action.value = 'bom.create_project';
        pid.value = 0;
        name.value = '';
        desc.value = '';
        if (vm){ vm.checked = true; sv.disabled = false; }
        if (sv) sv.value = '';
        var ptNew = document.getElementById('projectType');
        if (ptNew) ptNew.value = '';
        setProjectExtraVisible(false);
    }
    openOverlay('projectModal');
}
// 扩项字段仅在编辑态显示（新建走项目默认值）
function setProjectExtraVisible(show){
    var wrap = document.getElementById('projectExtraFields');
    if (wrap) wrap.classList.toggle('is-on', !!show);
}
function openImport(){importResetUI(); openOverlay('importModal');}
function escHtml(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

var importFile = document.getElementById('importFile');
if (importFile){
    importFile.addEventListener('change', function(){
        if (importFile.files[0]){
            document.getElementById('importFileLabel').textContent = importFile.files[0].name;
            document.getElementById('importFileName').classList.add('is-on');
        }
    });
}

document.querySelectorAll('.overlay').forEach(function(ov){
    ov.addEventListener('click', function(e){
        if (e.target === ov) ov.classList.remove('open');
    });
});

// ── 表单提交携带当前筛选状态 ──
(function(){
    var curFilter = _bomCfg.filter || '';
    if (!curFilter) return;
    document.querySelectorAll('form[action="api.php"]').forEach(function(f){
        if (f.querySelector('input[name="filter"]')) return;
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'filter';
        h.value = curFilter;
        f.appendChild(h);
    });
})();

// ── 批量删除勾选 ──
function bomToggleAll(master){
    document.querySelectorAll('.bom-item-chk').forEach(function(c){
        c.checked = master.checked;
    });
    bomUpdateBatchBar();
}
function bomOnItemChkChange(el){
    if (el && el.classList && el.classList.contains('bom-item-chk')) {
        document.querySelectorAll('.bom-item-chk').forEach(function(c){
            if (c !== el && c.value === el.value) c.checked = el.checked;
        });
    }
    bomUpdateBatchBar();
}
function bomUpdateBatchBar(){
    var checked = document.querySelectorAll('.bom-item-chk:checked');
    var seen = {}, ids = [];
    checked.forEach(function(c){
        if (!seen[c.value]) { seen[c.value] = 1; ids.push(c.value); }
    });
    var delForm = document.getElementById('batchDeleteForm');
    if (delForm){
        delForm.querySelectorAll('input[name="item_ids[]"]').forEach(function(e){ e.remove(); });
        ids.forEach(function(v){
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'item_ids[]';
            hidden.value = v;
            delForm.appendChild(hidden);
        });
    }
    // 勾选后浮出批量操作条（与物料列表 batch-bar 一致）
    var bar = document.getElementById('bomBatchBar');
    var cnt = document.getElementById('bomBatchCount');
    if (cnt) cnt.textContent = ids.length;
    if (bar){
        if (ids.length > 0) bar.classList.add('show');
        else bar.classList.remove('show');
    }
}
function bomClearSelection(){
    document.querySelectorAll('.bom-item-chk:checked').forEach(function(c){ c.checked = false; });
    var all = document.getElementById('bomSelectAll');
    if (all) all.checked = false;
    bomUpdateBatchBar();
}
