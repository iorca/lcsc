// modules/bom/Assets/js/bom-list.js — BOM 管理页（?v=bom）· 物料列表渲染 / 筛选 / 分页 / URL 同步

var _bomFilterState = {
    id: _bomCfg.selId || 0,
    filter: _bomCfg.filter || '',
    page: _bomCfg.bomPage || 1,
    per_page: _bomCfg.bomPerPage || 25
};

var _bomPlatUrls = _bomCfg.platUrlMap || {};
var _bomSelId = _bomCfg.selId || 0;
var _bomCsrf = _bomCfg.csrf || '';

/* 该行的商城跳转链接：无匹配物料/无平台模板/无编号 → ''（渲染为纯文本） */
function bomPartUrl(it){
    if (!it || !it.part || (it.part.platform_part_no || '') === '') return '';
    var tpl = _bomPlatUrls[String(it.part.platform_id || 0)] || '';
    return tpl ? platformUrl(tpl, it.part.platform_part_no) : '';
}

function esc(s){ if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function platformUrl(template, partNo){ if (!template || !partNo) return ''; return template.replace('{part_no}', partNo); }

function bomPartJson(it){
    var p = it && it.part;
    if (!p) return '';
    return JSON.stringify({
        model:            p.model || '',
        brand:            p.brand || '',
        package:          p.package || '',
        product_type:     p.product_type || '',
        product_name:     p.product_name || '',
        customer_part_no: p.customer_part_no || '',
        location:         p.location || '',
        parameters:       p.parameters || ''
    });
}

function loadBomItems(){
    if (!_bomFilterState.id) return;
    var tableWrap = document.getElementById('bomDesktopTbody');
    if (tableWrap) {
        tableWrap.style.opacity = '0.5';
        tableWrap.style.transition = 'opacity .2s';
    }
    var params = 'action=bom.items&_csrf=' + LCSC.csrf
        + '&id=' + _bomFilterState.id
        + '&filter=' + encodeURIComponent(_bomFilterState.filter)
        + '&page=' + _bomFilterState.page
        + '&per_page=' + _bomFilterState.per_page;
    LCSC.get('api.php?' + params, function(data){
        renderBomTable(data.items);
        renderBomPagination(data.total, data.page, data.per_page, data.total_page);
        renderBomSummary(data.summary);
        updateBomUrl();
        if (tableWrap) {
            tableWrap.style.opacity = '1';
        }
    }, function(msg){
        LCSC.toast('加载失败: ' + msg, 'error');
        if (tableWrap) {
            tableWrap.style.opacity = '1';
        }
    });
}

function renderBomTable(items){
    var tbody = document.getElementById('bomDesktopTbody');
    if (!tbody) return;
    if (!items || items.length === 0){
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><div class="icon">📋</div>暂无物料，点击上方「添加物料」或「导入」开始</div></td></tr>';
        return;
    }
    var html = '';
    items.forEach(function(it){
        var status = it.status;
        var partUrl = bomPartUrl(it);
        var pName  = (it.part && it.part.product_name) ? it.part.product_name : '';
        var pBrand = (it.part && it.part.brand) ? it.part.brand : '';
        var pPkg   = (it.part && it.part.package) ? it.part.package : '';
        var pType  = (it.part && it.part.product_type) ? it.part.product_type : '';
        var pPartId   = parseInt(it.part_id || 0);
        var isPending = (parseInt(it.is_incomplete_part || 0) === 1 && pPartId === 0) ? 1 : 0;
        var batchReady = (status === 'not_found' && isPending === 1
            && String(pName).trim() !== '') ? 1 : 0;
        var refTxt = it.reference || '';
        var remarkTxt = it.remark || '';
        html += '<tr data-status="'+esc(status)+'" data-part-id="'+pPartId+'" data-pending="'+isPending+'" data-batch-ready="'+batchReady+'">';
        html += '<td class="bom-col-chk"><input type="checkbox" class="bom-item-chk" name="item_ids[]" value="'+esc(it.id)+'" onchange="bomOnItemChkChange(this)"></td>';
        html += '<td class="bom-col-part" data-label="物料"><div class="bom-p-main">';
        if (partUrl){
            html += '<a href="'+esc(partUrl)+'" target="_blank" rel="noopener" class="bom-p-no">'+esc(it.platform_part_no)+'</a>';
        } else {
            html += '<span class="bom-p-no">'+esc(it.platform_part_no)+'</span>';
        }
        html += '<span class="bom-p-model">'+esc(it.model)+'</span></div>';
        if (pName !== '') html += '<div class="bom-p-name">'+esc(pName)+'</div>';
        if (isPending === 1 && it.pending_missing && it.pending_missing.length){
            html += '<div class="pm-chips">';
            it.pending_missing.forEach(function(mf){ html += '<span class="pm-chip">缺 '+esc(mf)+'</span>'; });
            html += '</div>';
        }
        html += '</td>';
        html += '<td class="bom-col-brand" data-label="品牌 / 封装"><div class="bom-b-name">'+ (pBrand !== '' ? esc(pBrand) : '<span class="bom-dim">—</span>') +'</div>';
        html += '<div class="bom-b-sub">' + (pPkg !== '' ? esc(pPkg) : '<span class="bom-dim">—</span>') + '</div></td>';
        html += '<td class="bom-col-cat" data-label="分类">' + (pType !== '' ? '<span class="bom-cat">'+esc(pType)+'</span>' : '<span class="bom-dim">—</span>') + '</td>';
        html += '<td class="bom-col-qty" data-label="数量"><span class="bom-qty">'+esc(it.qty)+'</span></td>';
        html += '<td class="bom-col-note" data-label="位号 / 备注">';
        if (refTxt !== '')    html += '<div class="bom-n-ref" title="'+esc(refTxt)+'">'+esc(refTxt)+'</div>';
        if (remarkTxt !== '') html += '<div class="bom-n-rmk" title="'+esc(remarkTxt)+'">'+esc(remarkTxt)+'</div>';
        if (refTxt === '' && remarkTxt === '') html += '<span class="bom-dim">—</span>';
        html += '</td>';
        html += '<td class="bom-col-status" data-label="状态">';
        if (status === 'ok'){
            html += '<span class="bom-st ok"><i></i>充足</span>';
        } else if (status === 'unbound'){
            // title 说明该状态含义与处理路径（用户反馈：只看「未绑定」不知所措）
            html += '<span class="bom-st unbound" title="导入时未匹配到主库物料，尚未经「替代料」绑定/替换；缺料分析按可用量 0 计。点右侧「替代料」从物料库选择并绑定即可"><i></i>未绑定<span class="bom-seg-tip">ⓘ</span></span>';
        } else if (status === 'insufficient'){
            html += '<span class="bom-st warn"><i></i>缺 '+esc(it.gap)+' 件</span>';
        } else {
            html += '<span class="bom-st err"><i></i>未匹配</span>';
        }
        html += '</td>';
        html += '<td class="bom-col-act"><div class="bom-row-acts">';
        html += '<button type="button" class="btn btn-ghost btn-xs item-edit-trigger" data-item-id="'+parseInt(it.id)+'" data-qty="'+parseInt(it.qty)+'" data-ref="'+esc(refTxt)+'" data-remark="'+esc(remarkTxt)+'" data-part="'+esc(bomPartJson(it))+'" title="编辑数量 / 位号 / 备注，并维护本 BOM 的物料参数">编辑</button>';
        html += '<button type="button" class="btn btn-ghost btn-xs alt-trigger'+(isPending===1?' is-primary':'')+'" data-item-id="'+parseInt(it.id)+'" data-model="'+esc(it.model)+'" data-name="'+esc(pName)+'" data-partno="'+esc(it.platform_part_no||'')+'" data-qty="'+parseInt(it.qty)+'" data-current-pid="'+pPartId+'" data-package="'+esc(pPkg)+'" data-category="'+esc(pType)+'" title="'+(isPending===1?'从主库选择物料并拉取其参数到本行':'绑定或替换替代料')+'">替代料</button>';
        html += '<form method="post" action="api.php" onsubmit="AdminUIConfirmSubmit(event, \'确认删除该物料？\', \'确认删除\')" class="bom-inline-form">';
        html += '<input type="hidden" name="_csrf" value="'+LCSC.csrf+'">';
        html += '<input type="hidden" name="action" value="bom.delete_item">';
        html += '<input type="hidden" name="project_id" value="'+_bomSelId+'">';
        html += '<input type="hidden" name="item_id" value="'+esc(it.id)+'">';
        if (_bomFilterState.filter){
            html += '<input type="hidden" name="filter" value="'+esc(_bomFilterState.filter)+'">';
        }
        html += '<button type="submit" class="btn btn-danger btn-xs">删除</button>';
        html += '</form></div></td></tr>';
    });
    tbody.innerHTML = html;
    var selectAll = document.getElementById('bomSelectAll');
    if (selectAll) selectAll.checked = false;
    bomUpdateBatchBar();
}

function renderBomSummary(summary){
    if (!summary) return;
    document.querySelectorAll('[data-summary]').forEach(function(el){
        var st = el.getAttribute('data-summary');
        if (summary[st] !== undefined) el.textContent = summary[st];
    });
    // 状态筛选分段控件高亮
    document.querySelectorAll('#bomSummaryArea [data-status]').forEach(function(seg){
        var st = seg.getAttribute('data-status');
        seg.classList.toggle('active', st === _bomFilterState.filter);
        if (seg.hasAttribute('aria-selected')) seg.setAttribute('aria-selected', st === _bomFilterState.filter ? 'true' : 'false');
    });
    // 头部「N 项物料」随筛选联动
    var headCount = document.getElementById('bomTotalCount');
    if (headCount){
        var totalAll = summary.ok + summary.insufficient + summary.not_found + (summary.unbound || 0);
        headCount.textContent = _bomFilterState.filter ? summary[_bomFilterState.filter] : totalAll;
    }
}

function renderBomPagination(total, page, perPage, totalPage){
    var area = document.getElementById('bomPaginationArea');
    if (!area) return;
    if (totalPage <= 1 && total <= 0){ area.innerHTML = ''; return; }
    var html = '';
    html += '<span class="page-jump">第 <input type="number" min="1" max="' + totalPage + '" value="' + page + '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();goBomPage(parseInt(this.value)||1);}"> 页</span>';
    html += '<a href="javascript:void(0)" onclick="goBomPage(' + Math.max(1,page-1) + ')" class="page-btn ' + (page<=1?'disabled':'') + '">‹</a>';
    var s = Math.max(1, page-2), e = Math.min(totalPage, page+2);
    if (s > 1) html += '<a href="javascript:void(0)" onclick="goBomPage(1)" class="page-btn">1</a>';
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += '<a href="javascript:void(0)" onclick="goBomPage(' + i + ')" class="page-btn ' + (i===page?'active':'') + '">' + i + '</a>';
    if (e < totalPage-1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += '<a href="javascript:void(0)" onclick="goBomPage(' + totalPage + ')" class="page-btn">' + totalPage + '</a>';
    html += '<a href="javascript:void(0)" onclick="goBomPage(' + Math.min(totalPage,page+1) + ')" class="page-btn ' + (page>=totalPage?'disabled':'') + '">›</a>';
    html += '<span class="page-info">共 <span class="bom-page-total">' + total + '</span> 条</span>';
    html += '<select onchange="changeBomPerPage(this.value)" class="per-page-select">';
    [10,15,20,25,30,35,40,45,50].forEach(function(pp){
        html += '<option value="' + pp + '" ' + (pp===perPage?'selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

function applyBomFilter(st){
    var wasActive = _bomFilterState.filter === st;
    _bomFilterState.filter = wasActive ? '' : st;
    _bomFilterState.page = 1;
    if (wasActive) {
        LCSC.toast('已显示全部物料', 'info');
    } else {
        var labelMap = {ok:'库存充足', insufficient:'库存不足', not_found:'未匹配', unbound:'未绑定'};
        LCSC.toast('已筛选：' + (labelMap[st] || st) + (st === 'unbound' ? '（未匹配主库、待替代料绑定/替换的行）' : ''), 'info');
    }
    loadBomItems();
}

function goBomPage(p){
    _bomFilterState.page = p;
    loadBomItems();
}

function changeBomPerPage(val){
    _bomFilterState.per_page = parseInt(val) || 25;
    _bomFilterState.page = 1;
    document.cookie = 'per_page_bom=' + val + ';max-age=2592000;path=/';
    loadBomItems();
}

function updateBomUrl(){
    var qs = 'id=' + _bomFilterState.id;
    if (_bomFilterState.filter) qs += '&filter=' + _bomFilterState.filter;
    qs += '&bom_per_page=' + _bomFilterState.per_page + '&bom_page=' + _bomFilterState.page;
    if (/[?&]tab_mode=studio(&|$)/.test(location.search)) qs += '&tab_mode=studio';
    var url = 'index.php?v=bom&' + qs + '';
    try { history.pushState(null, '', url); } catch(e) {}
}

window.addEventListener('popstate', function(){
    var params = new URLSearchParams(window.location.search);
    _bomFilterState.filter = params.get('filter') || '';
    _bomFilterState.page = parseInt(params.get('bom_page')) || 1;
    _bomFilterState.per_page = parseInt(params.get('bom_per_page')) || 25;
    loadBomItems();
});

var _bomProjectFilter = { q: '', status: '', type: '' };

function bomCollectProjectTypes(cards){
    var seen = {}, out = [];
    cards.forEach(function(c){
        var t = c.getAttribute('data-type') || '';
        if (t !== '' && !seen[t]) { seen[t] = 1; out.push(t); }
    });
    out.sort();
    return out;
}

function filterBomProjects(){
    var list = document.getElementById('bomProjectList');
    if (!list) return;
    var qEl = document.getElementById('bomProjectSearch');
    var sEl = document.getElementById('bomProjectStatusFilter');
    var tEl = document.getElementById('bomProjectTypeFilter');
    var cards = Array.prototype.slice.call(list.querySelectorAll('.bom-grid > .bom-card'));

    // 类型下拉按卡片实际类型填充（自定义类型也进得来）；仅当还是初始空态时建一次
    if (tEl && tEl.options.length <= 1){
        bomCollectProjectTypes(cards).forEach(function(t){
            var o = document.createElement('option'); o.value = t; o.textContent = t; tEl.appendChild(o);
        });
        tEl.value = _bomProjectFilter.type || '';
    }

    var qRaw = (qEl ? qEl.value : '').trim();
    var ql   = qRaw.toLowerCase();
    var st   = sEl ? sEl.value : '';
    var ty   = tEl ? tEl.value : '';
    _bomProjectFilter = { q: qRaw, status: st, type: ty };

    var shown = 0;
    cards.forEach(function(c){
        var name = (c.getAttribute('data-name') || '').toLowerCase();
        var desc = (c.getAttribute('data-desc') || '').toLowerCase();
        var ok = (!ql || name.indexOf(ql) >= 0 || desc.indexOf(ql) >= 0)
              && (!st || c.getAttribute('data-status') === st)
              && (!ty || c.getAttribute('data-type') === ty);
        c.classList.toggle('is-filtered-out', !ok);
        if (ok) shown++;
    });

    var emptyEl = document.getElementById('bomProjectEmpty');
    if (emptyEl) emptyEl.hidden = (shown !== 0);
    var countEl = document.getElementById('bomProjectCount');
    if (countEl){
        countEl.textContent = (ql || st || ty)
            ? ('筛选后 ' + shown + ' / 共 ' + cards.length + ' 个项目')
            : (cards.length + ' 个项目');
    }
}

/* 列表被无感刷新（新建/编辑/删除项目后）时恢复筛选条件并重新过滤 */
function bomRestoreProjectFilter(){
    var qEl = document.getElementById('bomProjectSearch');
    var sEl = document.getElementById('bomProjectStatusFilter');
    var tEl = document.getElementById('bomProjectTypeFilter');
    if (qEl) qEl.value = _bomProjectFilter.q;
    if (sEl) sEl.value = _bomProjectFilter.status;
    if (tEl){
        var list = document.getElementById('bomProjectList');
        var cards = list ? Array.prototype.slice.call(list.querySelectorAll('.bom-grid > .bom-card')) : [];
        tEl.innerHTML = '<option value="">全部类型</option>';
        bomCollectProjectTypes(cards).forEach(function(t){
            var o = document.createElement('option'); o.value = t; o.textContent = t; tEl.appendChild(o);
        });
        var want = _bomProjectFilter.type, has = false;
        Array.prototype.forEach.call(tEl.options, function(o){ if (o.value === want) has = true; });
        tEl.value = has ? want : '';
    }
    filterBomProjects();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
        if (document.getElementById('bomProjectList')) filterBomProjects();
    });
} else if (document.getElementById('bomProjectList')) {
    filterBomProjects();
}

function bomInitListFromCfg(){
    if (!_bomFilterState.id) return;   // 未选中项目（无物料表）→ 不渲染
    renderBomTable(_bomCfg.items || []);
    renderBomPagination(_bomCfg.bomTotalItems || 0, _bomFilterState.page, _bomFilterState.per_page, _bomCfg.bomTotalPage || 1);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bomInitListFromCfg);
} else {
    bomInitListFromCfg();
}
