// modules/inventory/Assets/js/parts-modal.js — 物料列表页 · 弹窗与表单

function closeOverlay(id){document.getElementById(id).classList.remove('open');}

var _addModalPlatType = 'standard';
var _editModalPlatType = 'standard';

function openAddModal(){
    clearFldErrs('a');
    document.getElementById('modalAdd').classList.add('open');
    onAddPlatformChange();
}

function onAddPlatformChange(){
    var sel = document.getElementById('a_platform');
    if(!sel) return;
    var pid = sel.value;
    if(!pid) return;
    var opt = sel.options[sel.selectedIndex];
    var optimisticType = opt ? (opt.getAttribute('data-type') || 'standard') : 'standard';
    toggleLooseFields('a', optimisticType);
    _addModalPlatType = optimisticType;
    LCSC.get('api.php?action=platform.type&platform_id=' + encodeURIComponent(pid), function(data){
        if(!data || !data.platform_type) return;
        _addModalPlatType = data.platform_type;
        toggleLooseFields('a', data.platform_type);
    }, function(){
    });
}

function toggleLooseFields(prefix, ptype){
    var purlGroup = document.getElementById(prefix + '_purl_group');
    var lpidGroup = document.getElementById(prefix + '_lpid_group');
    var ppnReq = document.getElementById(prefix + '_ppn_req');
    var ppnInput = document.getElementById(prefix + '_ppn');
    if(ptype === 'loose'){
        if(purlGroup) purlGroup.classList.remove('loose-field');
        if(lpidGroup) lpidGroup.classList.remove('loose-field');
        if(ppnReq) ppnReq.style.display = 'none';
        if(ppnInput) ppnInput.removeAttribute('required');
    } else {
        if(purlGroup) purlGroup.classList.add('loose-field');
        if(lpidGroup) lpidGroup.classList.add('loose-field');
        if(ppnReq) ppnReq.style.display = '';
        if(ppnInput) ppnInput.setAttribute('required', 'required');
    }
}

function fldErr(prefix, field, msg) {
    var e = document.getElementById(prefix + '_' + field + '_err');
    var inp = document.getElementById(prefix + '_' + field);
    if (e) { e.textContent = msg; e.classList.add('show'); }
    else if (typeof LCSC !== 'undefined' && LCSC.toast) { LCSC.toast(msg, 'error'); }
    if (inp) inp.classList.add('input-invalid');
}
function clearFldErrs(prefix) {
    var scope = document.getElementById(prefix === 'a' ? 'modalAdd' : 'modalEdit');
    if (!scope) return;
    scope.querySelectorAll('.fld-err').forEach(function (e) { e.classList.remove('show'); });
    scope.querySelectorAll('.input-invalid').forEach(function (e) { e.classList.remove('input-invalid'); });
}
// 编辑弹窗「清除链接」：置位隐藏域并清空输入框，保存时后端据此把列置空
function clearDatasheetUrl(){
    var el=document.getElementById('e_datasheet');
    var flag=document.getElementById('e_datasheet_clear');
    if(flag) flag.value='1';
    if(el){
        el.value='';
        el.classList.remove('input-invalid');
        var err=document.getElementById('e_datasheet_err');
        if(err){ err.textContent=''; err.classList.remove('show'); }
    }
}
function validatePartForm(prefix, ptype){
    clearFldErrs(prefix);
    var ppn = document.getElementById(prefix + '_ppn');
    var purl = document.getElementById(prefix + '_purl');
    if (ptype === 'standard') {
        if (ppn && !ppn.value.trim()) {
            fldErr(prefix, 'ppn', '标准商城平台必须填写商品编号');
            if (ppn) ppn.focus();
            return false;
        }
    } else if (ptype === 'loose') {
        if (purl) {
            var v = purl.value.trim();
            if (!v) {
                fldErr(prefix, 'purl', '散货渠道平台必须填写采购链接');
                purl.focus();
                return false;
            }
            if (!/^https?:\/\//i.test(v)) {
                fldErr(prefix, 'purl', '采购链接必须以 http:// 或 https:// 开头');
                purl.focus();
                return false;
            }
        }
    }
    var ds = document.getElementById(prefix + '_datasheet');
    if (ds) {
        var dsVal = ds.value.trim();
        if (dsVal && !/^https?:\/\//i.test(dsVal)) {
            fldErr(prefix, 'datasheet', '数据手册链接必须以 http:// 或 https:// 开头');
            ds.focus();
            return false;
        }
    }
    return true;
}
function openEditModal(btn){
    try{
        const d=JSON.parse(btn.getAttribute('data-part'));
        var partId = d.id;
        if(!partId){ LCSC.toast('物料ID缺失', 'error'); return; }

        var ptype0 = btn.getAttribute('data-platform-type') || (d.platform_type || 'standard');
        toggleLooseFields('e', ptype0);
        if(typeof LocPicker !== 'undefined' && LocPicker.available()){
            LocPicker.setByValue('e_locpicker', null);
        }
        _editModalPlatType = ptype0;
        // 清除标记在打开时就复位：放在 fetchJson 回调里的话，拉取失败时
        // 上一次的标记会残留，下一次保存就把别人的手册链接清掉了
        var dsClear=document.getElementById('e_datasheet_clear');
        var dsClearBtn=document.getElementById('e_datasheet_clear_btn');
        if(dsClear) dsClear.value='';
        if(dsClearBtn) dsClearBtn.style.display='none';
        var optMap={id:'e_id',platform_part_no:'e_ppn',customer_part_no:'e_cpn',
            model:'e_model',brand:'e_brand',product_name:'e_pname',
            package:'e_pkg',product_type:'e_ptype',location:'e_loc',
            low_stock_threshold:'e_thr',remark:'e_rem',purchase_url:'e_purl'};
        for(const[k,eid] of Object.entries(optMap)){
            const el=document.getElementById(eid);
            if(el) el.value=d[k]??'';
        }
        document.getElementById('modalEdit').classList.add('open');

        LCSC.fetchJson('api.php?action=parts.edit_detail&part_id=' + encodeURIComponent(partId))
            .then(function(resp){
                var data = resp.data || resp;
                var part = data.part || {};
                var ptype = data.platform_type || 'standard';
                _editModalPlatType = ptype;
                toggleLooseFields('e', ptype);
                var fillMap={id:'e_id',platform_part_no:'e_ppn',customer_part_no:'e_cpn',
                    model:'e_model',brand:'e_brand',product_name:'e_pname',
                    package:'e_pkg',location:'e_loc',
                    low_stock_threshold:'e_thr',remark:'e_rem',purchase_url:'e_purl'};
                for(const[k,eid] of Object.entries(fillMap)){
                    const el=document.getElementById(eid);
                    if(el) el.value=part[k]!==null&&part[k]!==undefined?part[k]:'';
                }
                // datasheet 列混存「站外 URL」与「上传 PDF 的相对路径」：
                // 只把 http(s) 的值回填进输入框，路径值留在附件区展示，
                var dsEl=document.getElementById('e_datasheet');
                var dsHint=document.getElementById('e_datasheet_hint');
                if(dsEl){
                    var dsVal=(part.datasheet||'').trim();
                    var dsIsUrl=/^https?:\/\//i.test(dsVal);
                    dsEl.value=dsIsUrl?dsVal:'';
                    if(dsHint){
                        dsHint.textContent = dsVal && !dsIsUrl
                            ? '当前手册为已上传的 PDF 附件；此处留空则不改动'
                            : '';
                    }
                    // 「清除链接」只对 URL 有意义：路径值归附件管理，本表单不碰
                    if(dsClearBtn) dsClearBtn.style.display = dsIsUrl ? 'inline-flex' : 'none';
                }
                if(typeof LocPicker !== 'undefined' && LocPicker.available()){
                    LocPicker.setByValue('e_locpicker', part.location_id);
                }
                var ptypeEl = document.getElementById('e_ptype');
                if(ptypeEl){
                    var cats = Array.isArray(data.cats) ? data.cats : [];
                    ptypeEl.value = cats.length > 0 ? cats.join('，') : (part.product_type || '');
                }
                var lpidEl = document.getElementById('e_lpid');
                var lpResultEl = document.getElementById('e_lp_result');
                var lpid = part.linked_part_id || '';
                if(lpidEl) lpidEl.value = lpid || '';
                if(lpid && data.linked_part){
                    var lpModel = data.linked_part.model || data.linked_part.platform_part_no || ('ID:' + lpid);
                    if(lpResultEl) lpResultEl.innerHTML = '已关联: ' + lpModel;
                } else {
                    if(lpResultEl) lpResultEl.innerHTML = '';
                }
                var altsVal = part.alternatives || '';
                _altSelectedIds = altsVal ? altsVal.split(',').map(function(s){return parseInt(s.trim());}).filter(function(v){return !isNaN(v);}) : [];
                var altsInput = document.getElementById('e_alts');
                if(altsInput) altsInput.value = _altSelectedIds.join(',');
                if(typeof renderAltList === 'function') renderAltList();
                var hint=document.getElementById('e_thr_hint');
                if(hint){
                    var own=part.low_stock_threshold;
                    var eff=part.low_stock_threshold;
                    if(own===null||own===undefined||own===''){
                        hint.textContent='当前生效: '+(eff||'—')+'（继承中）';
                    }else{
                        hint.textContent='当前生效: '+own;
                    }
                }
                var totalAssetEl = document.getElementById('e_total_asset');
                var latestCostEl = document.getElementById('e_latest_cost');
                return LCSC.fetchJson('app/api/router.php?action=parts.cost_summary&part_id=' + encodeURIComponent(partId))
                    .then(function(resp2){
                        var cost = resp2.data || resp2;
                        if(totalAssetEl) totalAssetEl.value = cost.total_asset > 0 ? '¥' + parseFloat(cost.total_asset).toFixed(2) : '¥0.00';
                        if(latestCostEl) latestCostEl.value = cost.latest_cost > 0 ? '¥' + parseFloat(cost.latest_cost).toFixed(4) : '—';
                    })
                    .catch(function(){
                        if(totalAssetEl) totalAssetEl.value = '¥0.00';
                        if(latestCostEl) latestCostEl.value = '—';
                    });
            })
            .catch(function(e){
                console.error('拉取编辑详情失败', e);
            });
    }catch(e){console.error('编辑数据解析失败',e);}
}

// ── 事件委托：出入库 & 删除按钮 ──
document.addEventListener('click', function(e){
    var btn = e.target.closest('.btn-stock');
    if (btn) {
        try {
            var info = JSON.parse(btn.getAttribute('data-info'));
            openStockModal(info);
        } catch(ex) { console.error('出入库数据解析失败', ex); }
        return;
    }
    var btnDel = e.target.closest('.btn-del');
    if (btnDel) {
        try {
            var info = JSON.parse(btnDel.getAttribute('data-info'));
            document.getElementById('d_id').value = info.id;
            document.getElementById('delMsg').textContent = '确定删除\u300c' + info.model + '\u300d？操作不可撤销。';
            document.getElementById('modalDel').classList.add('open');
        } catch(ex) { console.error('删除数据解析失败', ex); }
        return;
    }
}, true);

var _stkInfo = null;

// 打开出入库弹窗：填充头部/库存/库位带出并重置状态
function openStockModal(info){
    _stkInfo = info;
    document.getElementById('s_id').value = info.id;
    document.getElementById('stkName').textContent = info.model || '—';
    document.getElementById('stkPpn').textContent = info.ppn || ('#ID ' + info.id);
    document.getElementById('s_cur').textContent = info.stock;
    var dEl = document.getElementById('s_damaged');
    if (info.damaged > 0) { dEl.classList.add('is-on'); dEl.textContent = '| 不良品: ' + info.damaged; }
    else { dEl.classList.remove('is-on'); }
    document.getElementById('changeType').value = 'manual_in';
    document.getElementById('s_qty').value = 1;
    document.getElementById('s_qty').min = 1;
    var qinput = document.getElementById('s_qty'); qinput.classList.remove('input-invalid');
    var qerr = document.getElementById('s_qty_err'); qerr.classList.remove('show');
    var rem = document.getElementById('s_remark'); if (rem) rem.value = '';
    // 库位带出：默认选中物料绑定的库位，支持修改
    if (typeof LocPicker !== 'undefined' && LocPicker.available()){
        LocPicker.setByValue('s_locpicker', info.location_id);
        var orig = document.getElementById('s_orig_locid');
        if (orig) orig.value = info.location_id || '';
    }
    document.getElementById('modalStock').classList.add('open');
    onChangeType();
}

function onChangeType(){
    const t=document.getElementById('changeType').value;
    const q=document.getElementById('s_qty');
    var costGroup = document.getElementById('s_cost_group');
    var ucInput = document.getElementById('s_unit_cost');
    var spCheck = document.getElementById('s_is_sample');
    var isInbound = (t === 'manual_in');
    if(costGroup) costGroup.classList.toggle('is-on', isInbound);
    if(!isInbound){ if(ucInput) ucInput.value='0'; if(spCheck) spCheck.checked=false; }
    if(t==='adjust'){q.min=0;q.value=parseInt(document.getElementById('s_cur').textContent)||0;}
    else if(t==='damaged'||t==='repair'){q.min=1;q.value=1;}
    else{q.min=1;q.value=1;}
    // 操作类型语义色：入库绿 / 出库红 / 调整橙，确认按钮同步变色
    var sel = document.getElementById('changeType');
    sel.classList.remove('sem-green','sem-red','sem-amber');
    var btn = document.getElementById('stockSubmitBtn');
    var sem, bcls;
    if (t === 'manual_out') { sem='sem-red'; bcls='btn-danger'; }
    else if (t === 'adjust' || t === 'damaged') { sem='sem-amber'; bcls='btn-warning'; }
    else if (t === 'repair') { sem='sem-green'; bcls='btn-success'; }
    else { sem='sem-green'; bcls='btn-primary'; }
    sel.classList.add(sem);
    btn.className = 'btn ' + bcls;
    onQtyChange();
}

// 数量加减
function qtyStep(d){
    var q = document.getElementById('s_qty');
    var v = parseInt(q.value, 10) || 0;
    var t = document.getElementById('changeType').value;
    var min = (t === 'adjust') ? 0 : 1;
    q.value = Math.max(min, v + d);
    onQtyChange();
}
// 数量实时校验：出库/报损超出当前库存 → 红色边框 + 场下提示 + 禁止确认
function onQtyChange(){
    var q = document.getElementById('s_qty');
    var err = document.getElementById('s_qty_err');
    var btn = document.getElementById('stockSubmitBtn');
    var raw = q.value.trim();
    err.classList.remove('show');
    q.classList.remove('input-invalid');
    if (btn) btn.disabled = false;
    if (raw === '') return;
    var t = document.getElementById('changeType').value;
    var min = (t === 'adjust') ? 0 : 1;
    var n = parseInt(raw, 10);
    if (isNaN(n) || n < min) {
        err.textContent = '数量必须' + (min === 0 ? '≥0' : '为正整数');
        err.classList.add('show');
        q.classList.add('input-invalid');
        if (btn) btn.disabled = true;
        return;
    }
    if (t === 'manual_out' || t === 'damaged') {
        var cur = parseInt(document.getElementById('s_cur').textContent, 10) || 0;
        if (n > cur) {
            err.textContent = '库存不足，当前库存 ' + cur;
            err.classList.add('show');
            q.classList.add('input-invalid');
            if (btn) btn.disabled = true;
        }
    }
}
function validateStockQty(){
    var t = document.getElementById('changeType').value;
    var n = parseInt(document.getElementById('s_qty').value, 10) || 0;
    var cur = parseInt(document.getElementById('s_cur').textContent, 10) || 0;
    if (t === 'manual_out' || t === 'damaged') {
        if (n > cur) { LCSC.toast('库存不足，当前库存 ' + cur, 'error'); return false; }
    }
    if (n < (t === 'adjust' ? 0 : 1)) { LCSC.toast('数量输入无效', 'error'); return false; }
    return true;
}
// 备注快捷填充
function fillRemark(txt){
    var rem = document.getElementById('s_remark');
    if (rem) rem.value = txt;
}
// 提交入口：高危操作（出库/报损/负调整）二次确认
function submitStock(){
    if (!validateStockQty()) return;
    var t = document.getElementById('changeType').value;
    var qty = parseInt(document.getElementById('s_qty').value, 10) || 0;
    var cur = parseInt(document.getElementById('s_cur').textContent, 10) || 0;
    var dangerous = (t === 'manual_out' || t === 'damaged') || (t === 'adjust' && qty < cur);
    if (dangerous) {
        AdminUIConfirm({
            title: '操作确认',
            message: '确认执行该操作？库存将发生变动，不可撤销。',
            okText: '确认执行',
            onOk: function(){ stockFormSubmit(); }
        });
    } else {
        stockFormSubmit();
    }
}
// 实际提交：loading 防重复 + 库位联动
function stockFormSubmit(){
    var btn = document.getElementById('stockSubmitBtn');
    var form = document.getElementById('stockForm');
    if (!btn || !form) return;
    btn.disabled = true;
    var origTxt = btn.textContent;
    btn.textContent = '提交中…';
    var fd = new FormData(form);
    LCSC.post('api.php', fd, function(data, msg){
        // 库位联动：提交后若目标库位已调整，同步更新物料绑定库位（不影响库存逻辑）
        if (typeof LocPicker !== 'undefined' && LocPicker.available() && _stkInfo) {
            var lidEl = form.querySelector('.loc-picker-id');
            var newLid = lidEl ? (lidEl.value || '') : '';
            var oldLidEl = document.getElementById('s_orig_locid');
            var oldLid = oldLidEl ? (oldLidEl.value || '') : '';
            if (String(newLid) !== String(oldLid)) {
                LCSC.post('app/api/router.php', {
                    action: 'location.bind_part',
                    part_id: _stkInfo.id,
                    location_id: newLid === '' ? oldLid : newLid
                }, function(){}, function(){});
            }
        }
        LCSC.toast(msg, 'success');
        closeOverlay('modalStock');
        loadPartsList();
    }, function(msg){
        btn.disabled = false;
        btn.textContent = origTxt;
        LCSC.toast(msg, 'error');
    });
}
document.querySelectorAll('.overlay').forEach(el=>el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('open');}));

// ── 详情抽屉 ──
function openDrawer(id){
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('drawer').classList.add('open');
    document.getElementById('drawerTitle').textContent='加载中...';
    document.getElementById('drawerBody').innerHTML='<div class="empty-state"><div class="icon">⏳</div>加载中...</div>';
    LCSC.fetchJson('detail_ajax.php?id='+id)
        .then(function(resp){
            if(resp.code!==0) throw new Error(resp.msg || '加载失败');
            var d=resp.data;
            document.getElementById('drawerTitle').textContent=d.model||'元件详情';
            document.getElementById('drawerBody').innerHTML=d.html;
            if(d.stock_data) renderCharts(id, d.stock_data, d.price_data, d.cost_data);
            // 联动：抽屉内同步展示「相似物料推荐」（替代料模糊匹配结果）
            loadAltSuggest(id, d.model || '');
        })
        .catch(function(e){
            if (e && e.message === '鉴权失败') return;
            document.getElementById('drawerBody').innerHTML='<div class="flash err">加载失败：'+(e.message||'请重试')+'</div>';
        });
}
// ── 抽屉联动：相似物料推荐（按型号模糊匹配，支持一键设为替代料） ──
function loadAltSuggest(partId, model){
    var box = document.getElementById('drawerBody');
    if (!box || !box.innerHTML) return;
    if (typeof _featureMap === 'undefined' || !_featureMap.location_manage) return; // 个人版不展示
    var kw = (model || '').trim();
    if (!kw) return;
    var holder = document.createElement('div');
    holder.id = 'altSuggestBox';
    box.appendChild(holder);
    holder.innerHTML = '<div class="sec-title">相似物料推荐</div><div class="alt-s-loading">加载中…</div>';
    LCSC.fetchJson('app/api/router.php?action=parts.alt_search&q=' + encodeURIComponent(kw))
        .then(function(resp){
            var data = resp.data ? resp.data.items : (resp.items || []);
            if (!data || !data.length) { holder.remove(); return; }
            var rows = data.filter(function(it){ return it.id != partId; }).slice(0, 8);
            if (!rows.length) { holder.remove(); return; }
            holder.innerHTML = '<div class="sec-title">相似物料推荐 <span class="alt-s-hint">（按型号匹配，点击可设为替代料）</span></div>';
            var tbl = '<table class="info-table"><thead><tr>'
                + '<th class="alt-s-th">型号</th>'
                + '<th class="alt-s-th">品牌</th>'
                + '<th class="alt-s-th">封装</th>'
                + '<th class="alt-s-th">库存</th>'
                + '<th class="alt-s-th-end"></th></tr></thead><tbody>';
            rows.forEach(function(it){
                var pid = it.id;
                tbl += '<tr>'
                    + '<td class="alt-s-td"><a href="javascript:void(0)" onclick="openDrawer(' + pid + ')" class="alt-s-link">' + esc(it.model || '—') + '</a></td>'
                    + '<td class="alt-s-td">' + (it.brand ? esc(it.brand) : '—') + '</td>'
                    + '<td class="alt-s-td">' + (it.package ? esc(it.package) : '—') + '</td>'
                    + '<td class="alt-s-td ' + ((it.stock > 0) ? 'is-in' : 'is-out') + '">' + (it.stock !== undefined ? it.stock : '—') + '</td>'
                    + '<td class="alt-s-td is-act"><button type="button" class="btn btn-ghost btn-sm" onclick="addDrawerAlt(' + partId + ',' + pid + ',this)">设为替代料</button></td>'
                    + '</tr>';
            });
            tbl += '</tbody></table>';
            holder.innerHTML += tbl;
        })
        .catch(function(){ holder.remove(); });
}
function addDrawerAlt(srcId, tgtId, btn){
    if (btn) { btn.disabled = true; }
    LCSC.post('app/api/router.php', {
        action: 'parts.alt_add',
        part_id: srcId,
        alt_ids: JSON.stringify([tgtId])
    }, function(data, msg){
        LCSC.toast(msg, 'success');
        if (btn) {
            btn.textContent = '已添加';
            btn.classList.remove('btn-ghost');
            btn.classList.add('btn-success');
            btn.disabled = true;
        }
        // 刷新抽屉替代料区块
        openDrawer(srcId);
    }, function(msg){
        if (btn) btn.disabled = false;
        LCSC.toast(msg, 'error');
    });
}
function closeDrawer(){
    destroyDrawerCharts();
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('drawer').classList.remove('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDrawer();document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));}});

// ── 抽屉增强：卡片 Tab 切换 + 左侧拖拽调宽 + 底部操作按钮 ──
(function(){
    // Tab（替代料/所在BOM/操作日志）
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.dc-tabs button');
        if (!btn) return;
        var card = btn.closest('.drawer-card');
        if (!card) return;
        card.querySelectorAll('.dc-tabs button').forEach(function(b){ b.classList.toggle('on', b === btn); });
        var tab = btn.getAttribute('data-tab');
        card.querySelectorAll('.dc-panel').forEach(function(p){ p.style.display = p.getAttribute('data-panel') === tab ? '' : 'none'; });
    });
    // 拖拽调宽（480px ~ 92vw）
    var drawer = document.getElementById('drawer');
    if (drawer && !drawer.querySelector('.drawer-resize')) {
        var h = document.createElement('div');
        h.className = 'drawer-resize';
        h.title = '拖动调整宽度';
        drawer.insertBefore(h, drawer.firstChild);
        h.addEventListener('mousedown', function(ev){
            ev.preventDefault(); ev.stopPropagation();
            var startX = ev.clientX, startW = drawer.offsetWidth;
            h.classList.add('dragging');
            document.body.style.userSelect = 'none';
            function move(e2){
                var w = Math.max(520, Math.min(window.innerWidth * 0.92, startW + (startX - e2.clientX)));
                drawer.style.width = w + 'px';
            }
            function up(){
                h.classList.remove('dragging');
                document.body.style.userSelect = '';
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }
})();
// 抽屉底部「编辑」：构造轻量触发元素复用现有编辑流程
function openEditPartFromDrawer(id) {
    var fake = document.createElement('button');
    fake.setAttribute('data-part', JSON.stringify({ id: id, platform_type: 'standard' }));
    fake.setAttribute('data-platform-type', 'standard');
    if (typeof openEditModal === 'function') openEditModal(fake);
    else LCSC.toast('编辑入口不可用', 'warning');
}
// 抽屉底部「出入库」：拉取实时库存后打开操作弹窗
function drawerStock(id) {
    LCSC.fetchJson('api.php?action=parts.edit_detail&part_id=' + encodeURIComponent(id))
        .then(function(resp) {
            var p = (resp.data || {}).part || {};
            if (typeof openStockModal === 'function') {
                openStockModal({ id: id, model: p.model || '', ppn: p.platform_part_no || '', location_id: p.location_id || '', stock: parseInt(p.stock || 0, 10) || 0, damaged: parseInt(p.damaged || 0, 10) || 0 });
            } else { LCSC.toast('出入库入口不可用', 'warning'); }
        })
        .catch(function() { LCSC.toast('获取物料信息失败', 'error'); });
}

// ── 抽屉图表实例管理 ──
var _drawerCharts = [];
function destroyDrawerCharts() {
    _drawerCharts.forEach(function (c) { try { c.destroy(); } catch (e) {} });
    _drawerCharts = [];
}

function renderCharts(partId, sd, pd, cd){
    var anyCanvas = document.getElementById('stockChartD_' + partId) || document.getElementById('priceChartD_' + partId) || document.getElementById('costChartD_' + partId);
    if (!anyCanvas) return;
    if (typeof Chart === 'undefined') {
        var chartUrl = (window.PartsPageConfig && window.PartsPageConfig.chartUrl) || '';
        if (!chartUrl) return;
        LCSC.loadScriptOnce(chartUrl, function () { renderCharts(partId, sd, pd, cd); });
        return;
    }
    destroyDrawerCharts();
    const base={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
        scales:{x:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10},maxTicksLimit:6}},
                y:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10}},beginAtZero:true}}};
    const sc=document.getElementById('stockChartD_' + partId);
    if(sc&&sd&&sd.labels&&sd.labels.length>=2)
        _drawerCharts.push(new Chart(sc,{type:'line',data:{labels:sd.labels,datasets:[{data:sd.values,borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,.08)',tension:.3,fill:true,pointRadius:2}]},options:base}));
    const pc=document.getElementById('priceChartD_' + partId);
    if(pc&&pd&&pd.labels&&pd.labels.length>=2){
        const opt=JSON.parse(JSON.stringify(base));
        opt.scales.y.ticks.callback=function(v){return '¥'+v;};
        _drawerCharts.push(new Chart(pc,{type:'line',data:{labels:pd.labels,datasets:[{data:pd.values,borderColor:'#4f8ef7',backgroundColor:'rgba(79,142,247,.08)',tension:.3,fill:true,pointRadius:2}]},options:opt}));
    }
    const cc=document.getElementById('costChartD_' + partId);
    if(cc&&cd&&cd.labels&&cd.labels.length>=2){
        _drawerCharts.push(new Chart(cc,{type:'line',data:{labels:cd.labels,datasets:[
            {label:'库存数量',data:cd.qty_values,borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,.08)',tension:.3,fill:true,pointRadius:2,yAxisID:'y'},
            {label:'采购单价',data:cd.cost_values,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.08)',tension:.3,fill:false,pointRadius:2,yAxisID:'y1'}
        ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,labels:{color:'#7a86a8',font:{size:10},boxWidth:12}}},
            scales:{x:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10},maxTicksLimit:6}},
                    y:{type:'linear',position:'left',grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#22c55e',font:{size:10}},beginAtZero:true},
                    y1:{type:'linear',position:'right',grid:{drawOnChartArea:false},ticks:{color:'#f59e0b',font:{size:10},callback:function(v){return '¥'+v;}},beginAtZero:true}}}}));
    }
}
