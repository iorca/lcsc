// modules/inventory/Assets/js/parts-batch.js — 物料列表页 · 批量与工具

document.getElementById('searchInput').addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();ajaxSearch(e);}
});

// ── 清空搜索框 ──
/* 同步叉号显隐：有内容才显示。 */
function syncSearchClear(){
    var input = document.getElementById('searchInput');
    var btn = document.getElementById('clearSearchBtn');
    if (!input || !btn) return;
    btn.style.display = input.value.trim() !== '' ? 'flex' : 'none';
}
function clearSearch(){
    var input = document.getElementById('searchInput');
    input.value = '';
    syncSearchClear();
    _filterState.q = '';
    _filterState.page = 1;
    loadPartsList();
}
(function(){
    var input = document.getElementById('searchInput');
    if (!input) return;
    syncSearchClear();
    input.addEventListener('input', syncSearchClear);
})();

// ── 批量操作 ──
function _uniqueIds(nodeList){
    return Array.from(new Set(Array.prototype.map.call(nodeList, function(cb){ return cb.value; })));
}
function _syncRowHighlight(partId, selected){
    if (!partId) return;
    document.querySelectorAll('tr[data-part-id="' + partId + '"]').forEach(function(r){
        r.classList.toggle('row-selected', selected);
    });
}
function onPartCbChange(el){
    if (el && el.classList && el.classList.contains('part-cb')) {
        document.querySelectorAll('.part-cb').forEach(function(cb){
            if (cb !== el && cb.value === el.value) cb.checked = el.checked;
        });
    }
    updateBatchBar();
}
function toggleSelectAll(el){
    document.querySelectorAll('.part-cb').forEach(cb=>{
        cb.checked=el.checked;
        const tr=cb.closest('tr');
        const pid=tr?tr.getAttribute('data-part-id'):'';
        _syncRowHighlight(pid, el.checked);
    });
    updateBatchBar();
}
function updateBatchBar(){
    const all=document.querySelectorAll('.part-cb');
    const total=_uniqueIds(all).length;
    const sel=_uniqueIds(document.querySelectorAll('.part-cb:checked')).length;
    document.getElementById('batchCount').textContent=sel;
    const bar=document.getElementById('batchBar');
    if(bar){
        if(sel>0) bar.classList.add('show'); else bar.classList.remove('show');
    }
    all.forEach(cb=>{
        const tr=cb.closest('tr');
        const pid=tr?tr.getAttribute('data-part-id'):'';
        _syncRowHighlight(pid, cb.checked);
    });
    const sa=document.getElementById('selectAll');
    if(sa){
        sa.checked=total>0&&sel===total;
        sa.indeterminate=sel>0&&sel<total;
    }
}
function clearSelection(){
    document.querySelectorAll('.part-cb').forEach(cb=>cb.checked=false);
    document.querySelectorAll('.row-selected').forEach(r=>r.classList.remove('row-selected'));
    updateBatchBar();
}
function getSelectedIds(){
    return _uniqueIds(document.querySelectorAll('.part-cb:checked'));
}
function batchDelete(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    AdminUIConfirm({
        title: '批量删除物料',
        message: '确定删除选中的 '+ids.length+' 个元件？此操作不可撤销！',
        okText: '确认删除',
        onOk: function(){
            var formData = new FormData();
            formData.append('action', 'parts.batch_delete');
            formData.append('_csrf', LCSC.csrf);
            ids.forEach(function(id){ formData.append('ids[]', id); });
            LCSC.post('api.php', formData, function(data, msg) {
                LCSC.toast(msg, 'success');
                clearSelection();
                loadPartsList();
            }, function(msg) {
                LCSC.toast(msg, 'error');
            });
        }
    });
}
function batchExport(){
    const ids=getSelectedIds();
    if(ids.length===0){
        LCSC.toast('请先勾选要导出的元件', 'warning');
        return;
    }
    const form=document.createElement('form');
    form.method='post'; form.action='index.php?v=export&type=csv';
    ids.forEach(id=>{
        const inp=document.createElement('input');
        inp.type='hidden'; inp.name='ids[]'; inp.value=id;
        form.appendChild(inp);
    });
    document.body.appendChild(form); form.submit();
}
function batchSetThreshold(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    document.getElementById('batchThrCount').textContent=ids.length;
    document.getElementById('batchThrValue').value='';
    document.getElementById('modalBatchThreshold').classList.add('open');
}
function submitBatchThreshold(){
    const ids=getSelectedIds();
    const thr=document.getElementById('batchThrValue').value;
    if(ids.length===0||thr==='') return;
    const body='action=stock.batch_set_threshold&_csrf='+LCSC.csrf
        +'&threshold='+encodeURIComponent(thr)
        +'&'+ids.map(function(id){return 'ids[]='+id;}).join('&');
    LCSC.fetchJson('app/api/router.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(resp){
            if(resp.code===0){ LCSC.toast(resp.msg||'安全库存设置成功','success'); closeOverlay('modalBatchThreshold'); loadPartsList(); }
            else LCSC.toast(resp.msg||'设置失败','error');
        }).catch(function(msg){ LCSC.toast(String(msg),'error'); });
}

var MergeWizard = {
    groups: [],      // 疑似重复组
    selection: {},   // groupIndex -> 主物料 id

    esc: function(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); },

    open: function(){
        document.getElementById('modalMergeWizard').classList.add('open');
        this.backToStep1();
        this.loadGroups();
    },

    _get: function(params, done){
        var qs = Object.keys(params||{}).map(function(k){ return k+'='+encodeURIComponent(params[k]); }).join('&');
        LCSC.fetchJson('app/api/router.php?action=parts.duplicate_groups&_csrf='+LCSC.csrf+(qs?'&'+qs:''))
            .then(function(resp){ if(resp.code===0) done(resp.data||{}); else LCSC.toast(resp.msg||'加载失败','error'); })
            .catch(function(msg){ LCSC.toast(String(msg),'error'); });
    },

    loadGroups: function(){
        var self=this;
        var q=(document.getElementById('mergeSearch').value||'').trim();
        document.getElementById('mergeGroupList').innerHTML='<div class="pbx-note">查询中...</div>';
        this._get({q:q}, function(data){ self.groups=(data.groups||[]); self.renderGroups(); });
    },

    renderGroups: function(){
        var wrap=document.getElementById('mergeGroupList');
        if(!this.groups.length){
            wrap.innerHTML='<div class="pbx-note is-lg">未发现疑似重复物料<br><span class="pbx-sub">判定口径：型号 + 品牌 + 封装 三元组相同</span></div>';
            return;
        }
        var html='';
        this.groups.forEach(function(g, gi){
            // 默认主物料：库存最大（并列取 id 最小）
            var main=g.parts.reduce(function(a,b){ return (b.stock>a.stock||(b.stock===a.stock&&b.id<a.id))?b:a; }, g.parts[0]);
            MergeWizard.selection[gi]=main.id;
            html+='<div class="mg-group">'
                +'<div class="mg-group-title">'+MergeWizard.esc(g.model)
                +' <span class="mg-group-sub">'+MergeWizard.esc(g.brand||'—')+' · '+MergeWizard.esc(g.package||'—')+' · '+g.cnt+' 项</span></div>';
            g.parts.forEach(function(p){
                var checked=(p.id===main.id)?'checked':'';
                html+='<label class="mg-pick">'
                    +'<input type="radio" name="merge_main_'+gi+'" value="'+p.id+'" '+checked+' onchange="MergeWizard.selection['+gi+']='+p.id+'">'
                    +'<span class="mg-pick-main">#'+p.id+' '+MergeWizard.esc(LCSC.partCode(p.internal_id))+' <span class="mg-pick-sub">'+MergeWizard.esc(p.platform_part_no||'')+'</span></span>'
                    +'<span class="mg-pick-sub">库存 <b>'+p.stock+'</b></span>'
                    +'<span class="mg-pick-sub has-width">'+MergeWizard.esc(p.location||'无库位')+'</span>'
                    +'</label>';
            });
            html+='<div class="mg-group-ops">'
                +'<button type="button" class="btn btn-ghost btn-sm" onclick="MergeWizard.confirmGroup('+gi+')">合并此组 →</button></div></div>';
        });
        wrap.innerHTML=html;
    },

    // 步骤1 → 步骤2：单组确认（一次只合并一组，风险可控）
    confirmGroup: function(gi){
        var g=this.groups[gi];
        var mainId=this.selection[gi];
        var sources=g.parts.filter(function(p){return p.id!==mainId;});
        if(!sources.length){ LCSC.toast('该组只剩一个物料','warning'); return; }
        var totalStock=g.parts.reduce(function(s,p){return s+parseInt(p.stock,10)||0;},0);
        var html='<div class="mg-preview">'
            +'<div class="mg-preview-sec"><strong>主物料（保留）：</strong>#'+mainId+' '+this.esc(g.model)
            +'<div class="mg-preview-hint">库存/流水/分类/标签/BOM 引用将并入此物料，加权平均成本自动重算</div></div>'
            +'<div class="mg-preview-sec"><strong>源物料（合并后软删，共 '+sources.length+' 个）：</strong></div>'
            +'<ul class="mg-preview-list">';
        sources.forEach(function(p){
            html+='<li>#'+p.id+' '+MergeWizard.esc(LCSC.partCode(p.internal_id))+'（库存 '+p.stock+'）</li>';
        });
        html+='</ul><div class="mg-preview-total">合并后主物料库存合计：<b class="emb-accent">'+totalStock+'</b></div></div>';
        document.getElementById('mergePreview').innerHTML=html;
        this._pending={target:mainId, sources:sources.map(function(p){return p.id;})};
        document.getElementById('mergeConfirmChk').checked=false;
        document.getElementById('mergeExecBtn').disabled=true;
        document.getElementById('mergeStep1').style.display='none';
        document.getElementById('mergeStep1Footer').style.display='none';
        document.getElementById('mergeStep2').style.display='';
    },

    backToStep1: function(){
        document.getElementById('mergeStep1').style.display='';
        document.getElementById('mergeStep1Footer').style.display='';
        document.getElementById('mergeStep2').style.display='none';
    },

    execute: function(){
        if(!document.getElementById('mergeConfirmChk').checked) return;
        var pending=this._pending; if(!pending) return;
        var body='action=parts.batch_merge&_csrf='+LCSC.csrf
            +'&confirm=1&target_id='+pending.target
            +'&source_ids='+encodeURIComponent(JSON.stringify(pending.sources));
        LCSC.fetchJson('app/api/router.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(resp){
                if(resp.code===0){
                    LCSC.toast(resp.msg||'合并完成','success');
                    closeOverlay('modalMergeWizard');
                    loadPartsList();
                } else LCSC.toast(resp.msg||'合并失败','error');
            }).catch(function(msg){ LCSC.toast(String(msg),'error'); });
    }
};
// 勾选确认框联动执行按钮
document.addEventListener('change', function(e){
    if(e.target && e.target.id==='mergeConfirmChk'){
        var btn=document.getElementById('mergeExecBtn');
        if(btn) btn.disabled=!e.target.checked;
    }
});

function openMergeWizard(){ MergeWizard.open(); }

// ── 规则自动归类：按「品牌 / 商品类型 → 封装格式」建两级分类树并挂到物料 ──
// 未选中物料时 → 处理全部「尚未挂任何分类」的物料
function autoClassifyParts(){
    var ids = getSelectedIds();
    var formData = new FormData();
    formData.append('action', 'classify.auto');
    formData.append('_csrf', LCSC.csrf);
    formData.append('scope', 'all');
    if (ids.length) { ids.forEach(function(id){ formData.append('ids[]', id); }); }
    LCSC.post('api.php', formData, function(data, msg){
        LCSC.toast(msg || '自动归类完成', 'success');
        loadPartsList();
    }, function(msg){
        LCSC.toast(msg, 'error');
    });
}

// ── 立创补全：按立创编号回查商品类型（订单详情表没有「商品类型」列时用）──
// 未选中物料时 → 处理全部缺商品类型 / 品牌 / 封装的立创物料
function lcscEnrichParts(){
    var ids = getSelectedIds();
    var formData = new FormData();
    formData.append('action', 'classify.lcsc_enrich');
    formData.append('_csrf', LCSC.csrf);
    if (ids.length) { ids.forEach(function(id){ formData.append('ids[]', id); }); }
    LCSC.post('api.php', formData, function(data, msg){
        LCSC.toast(msg || '立创补全完成', 'success');
        loadPartsList();
    }, function(msg){
        LCSC.toast(msg, 'error');
    });
}

// ── AI 智能分类：调大模型判定商品类型 / 品牌 / 封装 ──
function batchAiClassify(){
    var ids = getSelectedIds();
    if (ids.length === 0) { LCSC.toast('请先勾选要识别的物料', 'error'); return; }
    var formData = new FormData();
    formData.append('action', 'classify.ai');
    formData.append('_csrf', LCSC.csrf);
    ids.forEach(function(id){ formData.append('ids[]', id); });
    LCSC.post('api.php', formData, function(data, msg){
        LCSC.toast(msg || 'AI 识别完成', 'success');
        loadPartsList();
    }, function(msg){
        LCSC.toast(msg, 'error');
    });
}

function batchSetCategory(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchCatIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchCat').classList.add('open');
}
function batchSetLocation(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchLocIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchLoc').classList.add('open');
}
function batchSetRemark(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchRemIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchRem').classList.add('open');
}

// ── 批量加入 BOM 项目 ──
function batchAddToBom(){
    var ids = getSelectedIds();
    if (ids.length === 0) { LCSC.toast('请先勾选要加入 BOM 的物料', 'error'); return; }
    var cntEl = document.getElementById('batchBomCount');
    if (cntEl) cntEl.textContent = ids.length;
    var sel = document.getElementById('batchBomProject');
    if (sel) {
        sel.innerHTML = '<option value="">加载中...</option>';
        LCSC.fetchJson('api.php?action=bom.projects')
            .then(function(resp) {
                var list = (resp.data && resp.data.projects) || resp.projects || [];
                if (!list.length) {
                    sel.innerHTML = '<option value="">暂无 BOM 项目，请先创建</option>';
                    return;
                }
                sel.innerHTML = '<option value="">-- 请选择 BOM 项目 --</option>' + list.map(function(p) {
                    return '<option value="' + p.id + '">' + esc(p.name) + '（已含 ' + p.item_count + ' 项）</option>';
                }).join('');
            })
            .catch(function() {
                sel.innerHTML = '<option value="">加载失败，请重试</option>';
            });
    }
    var qtyEl = document.getElementById('batchBomQty');
    if (qtyEl) qtyEl.value = '1';
    document.getElementById('modalBatchBom').classList.add('open');
}
function confirmBatchAddToBom(){
    var ids = getSelectedIds();
    if (ids.length === 0) { LCSC.toast('未选择物料', 'error'); return; }
    var pidEl = document.getElementById('batchBomProject');
    var pid = pidEl ? parseInt(pidEl.value, 10) : 0;
    if (!(pid > 0)) { LCSC.toast('请选择 BOM 项目', 'error'); return; }
    var qtyEl = document.getElementById('batchBomQty');
    var qty = qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1;
    var formData = new FormData();
    formData.append('action', 'bom.batch_add_to_bom');
    formData.append('project_id', String(pid));
    formData.append('qty', String(qty));
    ids.forEach(function(id) { formData.append('part_ids[]', id); });
    LCSC.post('api.php', formData, function(data, msg) {
        LCSC.toast(msg || '已加入 BOM 项目', 'success');
        closeOverlay('modalBatchBom');
        clearSelection();
    }, function(msg) {
        LCSC.toast(msg || '操作失败', 'error');
    });
}

// ── 关联标准物料搜索 ──
var _lpEsc = function(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };

// ── 物料选择弹窗（关联标准物料/替代料共用，交互对齐 BOM 绑定替代料弹窗）──
var _ppMode = 'linked';   // linked=关联标准物料（单选） alt=替代料（多选）
var _ppPrefix = 'e';       // 目标表单前缀：a=添加弹窗 e=编辑弹窗
var _ppSelected = null;    // linked 模式当前选中 {id, title}
var _ppTimer = null;

var _ppChecked = []; // 替代料多选集合

function showPpEmpty(show, text){
    var e = document.getElementById('ppEmpty');
    if (!e) return;
    // **必须用 classList.toggle，不能写 style.display** ——
    e.classList.toggle('is-on', !!show);
    if (text) {
        var t = document.getElementById('ppEmptyText');
        if (t) t.innerHTML = text;
    }
}
function openPartPicker(mode, prefix) {
    _ppMode = mode;
    _ppPrefix = prefix;
    _ppSelected = null;
    _ppChecked = [];
    var titleEl = document.getElementById('ppTitle');
    if (titleEl) titleEl.textContent = mode === 'alt' ? '添加替代料' : '关联标准物料';
    var input = document.getElementById('ppSearchInput');
    if (input) input.value = '';
    showPpEmpty(true, '输入关键词开始搜索<br>支持型号 / 内部编号 / 名称 / 品牌 / 封装模糊匹配');
    var list = document.getElementById('ppResultList');
    if (list) list.innerHTML = '';
    var head = document.getElementById('ppHead');
    if (head) { head.innerHTML = ''; head.classList.remove('is-on'); }
    var hint = document.getElementById('ppSelHint');
    if (hint) hint.textContent = mode === 'alt' ? '勾选要添加的物料（支持多选批量添加），点【确定】完成' : '点击列表中的物料行进行选中，再点【确定】完成关联';
    document.getElementById('modalPartPick').classList.add('open');
}
function ppClose() {
    document.getElementById('modalPartPick').classList.remove('open');
}
function ppOnSearch() {
    var input = document.getElementById('ppSearchInput');
    if (!input) return;
    clearTimeout(_ppTimer);
    var q = input.value.trim();
    _ppTimer = setTimeout(function() { ppLoad(q); }, 300);
}
function ppScore(item, q){
    var ql = q.toLowerCase();
    var m = String(item.model || '').toLowerCase();
    var no = String(item.no || '').toLowerCase();
    // 内部编号两种输入等价：#123 与显示码 P00001
    var intid = LCSC.partCode(item.internal_id).toLowerCase();
    var b = String(item.brand || '').toLowerCase();
    var p = String(item.package || '').toLowerCase();
    var n = String(item.product_name || '').toLowerCase();
    if (m === ql) return 100;
    if (no === ql) return 99;
    if (intid === ql || (('#' + (item.internal_id || 0)) === ql)) return 98;
    if (m.indexOf(ql) === 0) return 80;
    if (no.indexOf(ql) === 0) return 79;
    if (b.indexOf(ql) >= 0) return 60;
    if (p.indexOf(ql) >= 0) return 59;
    if (n.indexOf(ql) >= 0) return 40;
    if (m.indexOf(ql) >= 0) return 30;
    if (no.indexOf(ql) >= 0) return 29;
    return 1;
}
function renderPpHead(){
    var head = document.getElementById('ppHead');
    if (!head) return;
    head.className = 'pp-head alt-pick-head ' + (_ppMode === 'alt' ? 'alt-cols' : 'linked-cols');
    head.classList.add('is-on');
    head.innerHTML = (_ppMode === 'alt' ? '<div class="alt-c">✔</div>' : '')
        + '<div>型号</div><div>品牌</div><div>封装</div><div class="alt-c">当前库存</div><div>分类</div>';
}
function ppToggleCheck(cb, pid){
    var idx = _ppChecked.indexOf(pid);
    if (cb.checked && idx < 0) _ppChecked.push(pid);
    if (!cb.checked && idx >= 0) _ppChecked.splice(idx, 1);
    var row = cb.closest('.alt-pick-row');
    if (row) row.classList.toggle('is-checked', cb.checked);
    updatePpHint();
}
function updatePpHint(){
    var hint = document.getElementById('ppSelHint');
    if (!hint) return;
    if (_ppMode === 'alt' && _ppChecked.length) {
        hint.innerHTML = '已选 <b class="emb-accent">' + _ppChecked.length + '</b> 个物料，点【确定】批量添加为替代料';
    } else {
        hint.textContent = _ppMode === 'alt' ? '勾选要添加的物料（支持多选批量添加），点【确定】完成' : '点击列表中的物料行进行选中，再点【确定】完成关联';
    }
}
function ppLoad(q) {
    var list = document.getElementById('ppResultList');
    var loading = document.getElementById('ppLoading');
    if (!list) return;
    showPpEmpty(false);
    if (q.trim() === '') {
        list.innerHTML = '';
        showPpEmpty(true, '输入关键词开始搜索<br>支持型号 / 内部编号 / 名称 / 品牌 / 封装模糊匹配');
        return;
    }
    if (loading) loading.classList.add('is-on');
    LCSC.fetchJson('app/api/router.php?action=parts.alt_search&q=' + encodeURIComponent(q))
        .then(function(resp) {
            if (loading) loading.classList.remove('is-on');
            var data = resp.data ? resp.data.items : (resp.items || []);
            if (!data || !data.length) {
                list.innerHTML = '';
                showPpEmpty(true, '未找到匹配物料<br>可尝试简化关键词，或去掉空格 / 型号后缀');
                return;
            }
            // 按匹配度排序
            data.sort(function(a, b){ return ppScore(b, q) - ppScore(a, q); });
            renderPpHead();
            var html = '';
            data.forEach(function(item) {
                var already = (_ppMode === 'alt' && _altSelectedIds.indexOf(item.id) >= 0);
                var curLinked = 0;
                var lpidEl = document.getElementById(_ppPrefix + '_lpid');
                if (_ppMode === 'linked' && lpidEl) curLinked = parseInt(lpidEl.value, 10) || 0;
                var cur = (_ppMode === 'linked' && curLinked === item.id);
                var off = already || cur;
                var checked = (_ppMode === 'alt' && !off && _ppChecked.indexOf(item.id) >= 0);
                var rowClass = 'alt-pick-row ' + (_ppMode === 'alt' ? 'alt-cols' : 'linked-cols') + (off ? ' is-off' : '') + (checked ? ' is-checked' : '');
                var stkCls = (item.stock !== undefined && item.stock > 0) ? 'alt-stk-ok' : 'alt-stk-zero';
                html += '<div class="' + rowClass + '" data-pid="' + item.id + '" data-off="' + (off ? 1 : 0) + '" data-title="' + _lpEsc(item.model || item.no || '') + '">'
                    + (_ppMode === 'alt' ? '<div><input type="checkbox" ' + (off ? 'disabled' : '') + (checked ? ' checked' : '') + ' onclick="event.stopPropagation()" onchange="ppToggleCheck(this,' + item.id + ')"></div>' : '')
                    + '<div title="' + _lpEsc(item.model || '') + '">' + _lpEsc(item.model || '-')
                        + (off ? ' <span class="alt-flag">' + (cur ? '当前已关联' : '已添加') + '</span>' : '')
                        + '</div>'
                    + '<div title="' + _lpEsc(item.brand || '') + '">' + (_lpEsc(item.brand) || '<span class="ph-dash">—</span>') + '</div>'
                    + '<div title="' + _lpEsc(item.package || '') + '">' + (_lpEsc(item.package) || '<span class="ph-dash">—</span>') + '</div>'
                    + '<div class="apt-stk ' + stkCls + '">' + (item.stock !== undefined ? item.stock : '-') + '</div>'
                    + '<div title="' + _lpEsc(item.product_type || '') + '">' + (_lpEsc(item.product_type) || '<span class="ph-dash">—</span>') + '</div>'
                    + '</div>';
            });
            list.innerHTML = html;
            if (_ppMode === 'alt') updatePpHint();
        })
        .catch(function(){
            if (loading) loading.style.display = 'none';
            showPpEmpty(true, '搜索失败，请重试');
        });
}
// 弹窗列表行点击（事件委托）
(function(){
    var list = document.getElementById('ppResultList');
    if (!list) return;
    list.addEventListener('click', function(e){
        var row = e.target.closest('.alt-pick-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        if (!(pid > 0)) return;
        if (row.getAttribute('data-off') === '1') { LCSC.toast('该物料已添加，无需重复选择', 'info'); return; }
        if (_ppMode === 'alt') {
            var cb = row.querySelector('input[type=checkbox]');
            if (cb) { cb.checked = !cb.checked; ppToggleCheck(cb, pid); }
        } else {
            // 关联标准物料：单选高亮
            _ppSelected = { id: pid, title: row.getAttribute('data-title') || '' };
            var rows = list.querySelectorAll('.alt-pick-row.is-checked');
            for (var i = 0; i < rows.length; i++) rows[i].classList.remove('is-checked');
            row.classList.add('is-checked');
            var hint = document.getElementById('ppSelHint');
            if (hint) hint.innerHTML = '已选中：<b class="emb-accent">' + _lpEsc(_ppSelected.title || '') + '</b>';
        }
    });
})();
function ppConfirm() {
    if (_ppMode === 'linked') {
        if (!_ppSelected) { LCSC.toast('请先在列表中点选一个物料', 'error'); return; }
        selectLinkedPart(_ppPrefix, _ppSelected.id, _ppSelected.title);
        ppClose();
        return;
    }
    // 替代料：批量添加已勾选物料，完成后自动关闭弹窗
    if (_ppChecked.length === 0) { LCSC.toast('请先勾选要添加的替代料', 'error'); return; }
    _ppChecked.forEach(function(pid){ selectAltPart(pid); });
    LCSC.toast('已添加 ' + _ppChecked.length + ' 个替代料', 'success');
    ppClose();
}
function selectLinkedPart(prefix, id, name) {
    document.getElementById(prefix + '_lpid').value = id;
    var result = document.getElementById(prefix + '_lp_result');
    if (result) result.innerHTML = '已关联: ' + name;
    LCSC.fetchJson('api.php?action=parts.edit_detail&part_id=' + encodeURIComponent(id))
        .then(function(resp){
            var data = resp.data || resp;
            var part = data.part || {};
            var cats = Array.isArray(data.cats) ? data.cats : [];
            var fields = [
                {key: 'model', suffix: '_model'},
                {key: 'product_name', suffix: '_pname'},
                {key: 'package', suffix: '_pkg'},
            ];
            fields.forEach(function(f){
                var el = document.getElementById(prefix + f.suffix);
                if(el && (!el.value || el.value.trim() === '') && part[f.key]){
                    el.value = part[f.key];
                }
            });
            var ptypeEl = document.getElementById(prefix + '_ptype');
            if(ptypeEl && (!ptypeEl.value || ptypeEl.value.trim() === '') && cats.length > 0){
                ptypeEl.value = cats.join('，');
            }
        })
        .catch(function(e){
            console.error('拉取关联物料信息失败', e);
        });
}
function clearLinkedPart(prefix) {
    document.getElementById(prefix + '_lpid').value = '';
    var result = document.getElementById(prefix + '_lp_result');
    if (result) result.innerHTML = '';
}

// ── 粘贴链接自动提取标题 ──
function extractTitleFromUrl() {
    var input = document.getElementById('a_purl');
    if (!input || !input.value.trim()) return;
    var url = input.value.trim();
    var modelInput = document.querySelector('#modalAdd [name="model"]');
    if (modelInput && !modelInput.value) {
        try {
            var u = new URL(url);
            var match = url.match(/id=(\d+)/) || url.match(/\/(\d{6,})/);
            if (match) {
                modelInput.value = '采购-' + match[1].substring(0, 8);
            }
        } catch(e) {}
    }
}

// ── 替代料已选列表维护（选择弹窗 openPartPicker('alt','e') 写入）──
var _altSelectedIds = [];
function selectAltPart(partId) {
    if (_altSelectedIds.indexOf(partId) >= 0) return;
    _altSelectedIds.push(partId);
    document.getElementById('e_alts').value = _altSelectedIds.join(',');
    renderAltList();
}
function removeAltPart(partId) {
    _altSelectedIds = _altSelectedIds.filter(function(id) { return id !== partId; });
    document.getElementById('e_alts').value = _altSelectedIds.join(',');
    renderAltList();
}
function renderAltList() {
    var box = document.getElementById('e_alt_list');
    if (!box) return;
    if (_altSelectedIds.length === 0) {
        box.innerHTML = '<div class="pbx-dash">暂无替代料，点击上方「＋ 添加替代料」搜索绑定</div>';
        return;
    }
    var ids = _altSelectedIds.join(',');
    LCSC.fetchJson('app/api/router.php?action=parts.alt_lookup&ids=' + encodeURIComponent(ids))
        .then(function(resp) {
            var list = Array.isArray(resp.data) ? resp.data : (resp.data ? resp.data.items : (resp.items || []));
            if (!list || !list.length) { box.innerHTML = '<div class="pbx-note is-tight">暂无替代料</div>'; return; }
            var html = '<table class="alt-table"><thead><tr>'
                + '<th class="alt-w-id">内部编号</th><th>型号</th><th>品牌</th><th>封装</th><th class="alt-r alt-w-stock">库存</th><th class="alt-w-del"></th>'
                + '</tr></thead><tbody>';
            list.forEach(function(it) {
                if (it.found) {
                    html += '<tr>'
                        + '<td><span class="alt-pill">' + LCSC.partCode(it.internal_id) + '</span></td>'
                        + '<td title="' + _lpEsc(it.name || '') + '"><span class="alt-no">' + _lpEsc(it.no) + '</span><span class="alt-name">' + _lpEsc(it.name || '') + '</span></td>'
                        + '<td>' + (_lpEsc(it.brand) || '<span class="ph-dash">—</span>') + '</td>'
                        + '<td>' + (_lpEsc(it.package) || '<span class="ph-dash">—</span>') + '</td>'
                        + '<td class="alt-r">' + it.stock + '</td>'
                        + '<td class="alt-r"><button type="button" class="alt-del" onclick="removeAltPart(' + it.id + ')" title="移除">✕</button></td>'
                        + '</tr>';
                } else {
                    html += '<tr><td colspan="5" class="is-gone">✕ ID:' + it.id + '（物料不存在）</td>'
                        + '<td class="alt-r"><button type="button" class="alt-del" onclick="removeAltPart(' + it.id + ')" title="移除">✕</button></td></tr>';
                }
            });
            html += '</tbody></table>';
            box.innerHTML = html;
        })
        .catch(function(){ });
}

// ── 打印标签（分层流程：配置弹窗 → 预览打印）──
var _printSelectedParts = [];
function printLabel(partId){
    var cb = document.querySelector('.part-cb[value="'+partId+'"]');
    var stock = cb ? parseInt(cb.getAttribute('data-stock') || '0', 10) : 0;
    _printSelectedParts = [{id: partId, stock: stock}];
    openPrintConfig();
}
function batchPrint(){
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    var stockById = {};
    document.querySelectorAll('.part-cb:checked').forEach(function(cb){
        if (!(cb.value in stockById)) stockById[cb.value] = parseInt(cb.getAttribute('data-stock') || '0', 10);
    });
    _printSelectedParts = ids.map(function(id){ return {id: id, stock: stockById[id] || 0}; });
    openPrintConfig();
}
function openPrintConfig(){
    // 重置打印偏好
    var tplIn = document.querySelector('input[name="printTpl"][value="default"]');
    if (tplIn) tplIn.checked = true;
    document.querySelectorAll('#printFieldsGroup input[type=checkbox]').forEach(function(cb){ cb.checked = true; });
    document.querySelector('input[name="printLabelType"][value="in"]').checked = true;
    document.getElementById('printQty').value = 1;
    document.getElementById('printQty').disabled = false;
    document.getElementById('printRemark').value = '';
    document.getElementById('printQtyError').classList.remove('is-on');
    document.getElementById('printQtyHint').textContent = '';
    document.getElementById('fillAllStockBtn').textContent = '填充全部库存';
    var each = document.getElementById('printEachStock'); if (each) each.checked = false;
    var row = document.getElementById('batchAllStockRow');
    if (row) row.classList.toggle('is-on', _printSelectedParts.length > 1);
    var info = '已选 <b class="emb-accent">' + _printSelectedParts.length + '</b> 个元件';
    var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
    var zeroCnt = _printSelectedParts.filter(function(p){return p.stock <= 0;}).length;
    if (zeroCnt > 0) info += '，其中 <span class="emb-danger">' + zeroCnt + '</span> 个库存为0';
    info += '，最低库存 <b>' + minStock + '</b>';
    document.getElementById('printSelectedInfo').innerHTML = info;
    onPrintLabelTypeChange();
    document.getElementById('modalPrintConfig').classList.add('open');
}
function fillPrintRemark(txt){
    var rem = document.getElementById('printRemark');
    var cur = rem.value.trim();
    rem.value = cur ? cur + ' ' + txt : txt;
}
function onPrintTplChange(){ /* 模板选择已即时生效，预览时读取 */ }
function applyEachStockMode(){
    var qtyInput = document.getElementById('printQty');
    var fillBtn = document.getElementById('fillAllStockBtn');
    var each = document.getElementById('printEachStock');
    var per = each && each.checked;
    if (per) {
        qtyInput.disabled = true;
        qtyInput.value = '';
        fillBtn.textContent = '取消按库存';
    } else {
        qtyInput.disabled = false;
        qtyInput.value = 1;
        fillBtn.textContent = '填充全部库存';
    }
}
function onPrintEachStockChange(){
    applyEachStockMode();
    onPrintQtyInput();
}
function onPrintLabelTypeChange(){
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    var qtyInput = document.getElementById('printQty');
    var hint = document.getElementById('printQtyHint');
    var fillBtn = document.getElementById('fillAllStockBtn');
    if (labelType === 'out') {
        var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
        hint.textContent = '（出库模式：数量不可超过最低库存 ' + minStock + '）';
        fillBtn.textContent = '填充全部库存';
        qtyInput.disabled = false;
    } else {
        hint.textContent = '';
        fillBtn.textContent = '填充全部库存';
        qtyInput.disabled = false;
    }
    onPrintQtyInput();
}
function onPrintQtyInput(){
    var errBox = document.getElementById('printQtyError');
    var qtyInput = document.getElementById('printQty');
    errBox.classList.remove('is-on');
    errBox.textContent = '';
    if (qtyInput.disabled) return;
    var raw = qtyInput.value.trim();
    if (raw === '') return;
    var n = parseInt(raw, 10);
    if (isNaN(n) || n <= 0) {
        errBox.textContent = '数量必须为正整数';
        errBox.classList.add('is-on');
        return;
    }
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    if (labelType === 'out') {
        var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
        if (n > minStock) {
            errBox.textContent = '出库数量不可超过最低库存 ' + minStock;
            errBox.classList.add('is-on');
        }
    }
}
function fillAllStock(){
    var each = document.getElementById('printEachStock');
    if (each) { each.checked = !each.checked; applyEachStockMode(); }
    onPrintQtyInput();
}
var _printPreviewUrl = '';
function openPrintPreview(){
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    var remark = document.getElementById('printRemark').value.trim();
    var qtyInput = document.getElementById('printQty');
    var errBox = document.getElementById('printQtyError');
    var tpl = document.querySelector('input[name="printTpl"]:checked').value;
    var fields = Array.from(document.querySelectorAll('#printFieldsGroup input[type=checkbox]:checked, input[name^="pf_"][type=checkbox]:checked')).map(function(cb){ return cb.name.replace('pf_', ''); });
    if (!fields.length) fields = ['model'];

    var qtyParam;
    if (qtyInput.disabled) {
        qtyParam = 'all';
    } else {
        var raw = qtyInput.value.trim();
        if (raw === '') { errBox.textContent = '请输入打印数量'; errBox.style.display = 'block'; return; }
        var n = parseInt(raw, 10);
        if (isNaN(n) || n <= 0) { errBox.textContent = '数量必须为正整数'; errBox.style.display = 'block'; return; }
        if (labelType === 'out') {
            var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
            if (n > minStock) { errBox.textContent = '出库数量不可超过最低库存 ' + minStock; errBox.style.display = 'block'; return; }
        }
        qtyParam = n;
    }

    var ids = _printSelectedParts.map(function(p){return 'ids[]=' + encodeURIComponent(p.id);}).join('&');
    var params = '?' + ids
        + '&label_type=' + encodeURIComponent(labelType)
        + '&qty=' + encodeURIComponent(qtyParam)
        + '&remark=' + encodeURIComponent(remark)
        + '&tpl=' + encodeURIComponent(tpl)
        + '&fields=' + encodeURIComponent(fields.join(','));
    _printPreviewUrl = 'print.php' + params;

    var total = _printSelectedParts.length;
    var labelCls = labelType === 'out' ? 'emb-danger' : 'emb-ok';
    document.getElementById('ppPreviewMeta').innerHTML =
        '共 <b class="emb-accent">' + total + '</b> 个标签 · '
        + '<span class="' + labelCls + ' emb-strong">' + (labelType === 'out' ? '出库标签' : '入库标签') + '</span>'
        + ' · 模板：' + (tpl === 'mini' ? '迷你' : '标准')
        + ' · 数量：' + (qtyParam === 'all' ? '按各物料当前库存' : qtyParam)
        + ' · 字段：' + fields.join(' / ');
    document.getElementById('ppPreviewFrame').src = _printPreviewUrl;
    document.getElementById('modalPrintPreview').classList.add('open');
    closeOverlay('modalPrintConfig');
}
function confirmPrint(){
    // 点「确认打印」→ 触发预览页面的浏览器打印（确认后再发送）
    var fr = document.getElementById('ppPreviewFrame');
    if (fr && fr.contentWindow) {
        try { fr.contentWindow.focus(); fr.contentWindow.print(); }
        catch(e) { if (_printPreviewUrl) window.open(_printPreviewUrl, '_blank'); }
    }
}
