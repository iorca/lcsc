// modules/bom/Assets/js/bom-version.js — BOM 管理页（?v=bom）· 版本 Tab 交互、版本管理动作、版本差异对比

window._bomCurVersion = (BomPageConfig && BomPageConfig.viewVersionId) || 0;   // 当前视图版本
function bomSwitchVersion(vid) {
    var vidN = parseInt(vid, 10) || 0;
    var pid = (BomPageConfig && BomPageConfig.selId) || 0;
    if (!pid) return;
    var url = 'index.php?v=bom&id=' + pid + (vidN > 0 ? '&bom_ver=' + vidN : '');
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    fetch(url + sep + 'tab_mode=studio', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            bomSwapRegion('bomStickyBar', doc);
            bomSwapRegion('bomRegion', doc);
            bomSwapRegion('bomVersionHint', doc);
            bomSwapRegion('bomCostSummary', doc);
            // 同步 JS 配置与筛选态（版本变了，分页/筛选需归零）
            if (BomPageConfig) {
                BomPageConfig.viewVersionId = vidN;
                var vv = (BomPageConfig.bomVersions || []).find(function(v){ return parseInt(v.id, 10) === vidN; });
                BomPageConfig.viewVersion = vv || { id: vidN, status: '' };
                BomPageConfig.bomReadonly = !!(vv && (vv.status === 'released' || vv.status === 'archived'));
            }
            _bomFilterState.page = 1;
            _bomFilterState.filter = '';
            try { history.pushState(null, '', url); } catch(e) {}
            LCSC.toast('已切换版本视图', 'info');
        })
        .catch(function(){
            // 兜底：整页跳转
            window.location.href = url;
        });
}

// ═══════ 版本管理侧滑面板（替代原 verManageModal 菜单弹窗） ═══════
function bomOpenVerPanel(kind) {
    var drawer = document.getElementById('bomVerDrawer');
    if (!drawer) return;
    drawer.classList.add('open');
    var ov = document.getElementById('bomVerDrawerOverlay');
    if (ov) ov.classList.add('open');
    if (!drawer.dataset.escBound) {
        drawer.dataset.escBound = '1';
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape' && drawer.classList.contains('open')) bomCloseVerPanel();
        });
    }
    var open = function(){ if (kind) bomVerShowForm(kind); };
    if (!bomVerFetchList(function(list){ bomRenderVerPanel(list); open(); })) {
        bomRenderVerPanel((BomPageConfig && BomPageConfig.bomVersions) || []);
        open();
    }
}
function bomCloseVerPanel() {
    var drawer = document.getElementById('bomVerDrawer');
    if (drawer) drawer.classList.remove('open');
    var ov = document.getElementById('bomVerDrawerOverlay');
    if (ov) ov.classList.remove('open');
}
function bomVerFetchList(cb) {
    var pid = bomProjectId();
    if (!pid) return false;
    fetch('api.php?action=bom.list_versions&project_id=' + pid, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.code !== 0) { LCSC.toast(d.msg || '版本列表加载失败', 'error'); return; }
            var list = (d.data && d.data.versions) || [];
            if (BomPageConfig) BomPageConfig.bomVersions = list;
            if (cb) cb(list);
        })
        .catch(function(err){ LCSC.toast('版本列表加载失败：' + err.message, 'error'); });
    return true;
}
// 内联表单：新建 / 发布 / 描述 / 对比 全部在面板内完成，不另开弹窗
function bomVerShowForm(kind, vid) {
    var box = document.getElementById('bomVerAction');
    if (!box) return;
    var list = (BomPageConfig && BomPageConfig.bomVersions) || [];
    vid = bomVerTargetId(vid);
    var v = bomVerById(vid) || {};
    var h = '';
    if (kind === 'create') {
        var major = 1, minor = 0, has = false;
        list.forEach(function(it){
            var m = /V?(\d+)\.(\d+)/i.exec(it.version || '');
            if (m) { has = true; var ma = parseInt(m[1], 10), mi = parseInt(m[2], 10); if (ma > major || (ma === major && mi >= minor)) { major = ma; minor = mi + 1; } }
        });
        var suggest = has ? ('V' + major + '.' + minor) : 'V1.0';
        var baseSel = (BomPageConfig && BomPageConfig.bomReadonly && vid) ? vid : 0; // 查看只读版本时默认基于它迭代
        var opts = '<option value="0">当前编辑草稿</option>' + list.map(function(it){
            var tag = it.status === 'released' ? '已发布' : (it.status === 'archived' ? '已归档' : '草稿');
            return '<option value="' + parseInt(it.id, 10) + '"' + (parseInt(it.id, 10) === parseInt(baseSel, 10) ? ' selected' : '') + '>' + escHtml(it.version || '') + '（' + tag + '）' + (it.is_current ? ' - 当前' : '') + '</option>';
        }).join('');
        h = '<b class="bom-ver-form-t">＋ 新版本</b>'
          + '<div class="form-group"><label>基于版本</label><select id="bomVerBase">' + opts + '</select></div>'
          + '<div class="form-group"><label>新版本号 <span class="bom-req-star">*</span></label><input type="text" id="bomVerNewVersion" placeholder="如 V1.1 / V2.0 / Rev.A" maxlength="20" value="' + escHtml(suggest) + '"></div>'
          + '<div class="form-group"><label>变更描述</label><textarea id="bomVerNewDesc" rows="3" placeholder="相对上一版本改了什么（可选）"></textarea></div>'
          + '<div class="form-hint">复制所选版本的完整清单，生成一份<b>可编辑</b>的新草稿；来源版本保持只读不变。</div>'
          + '<div class="bom-ver-form-btns"><button type="button" class="btn btn-ghost btn-sm" onclick="bomVerShowFormCancel()">取消</button>'
          + '<button type="button" class="btn btn-primary btn-sm" id="bomVerCreateBtn" onclick="bomVerSubmitCreate()">创建版本</button></div>';
    } else if (kind === 'release') {
        if (!v || v.status !== 'draft') { LCSC.toast('仅草稿版本可发布', 'error'); return; }
        h = '<b class="bom-ver-form-t">🚀 发布草稿「' + escHtml(v.version || '') + '」</b>'
          + '<div class="form-group"><label>发布变更说明（ECN）</label><textarea id="bomVerReleaseNote" rows="3" placeholder="本次发布改了什么（可选，将记录为版本变更说明）"></textarea></div>'
          + '<div class="form-hint">当前清单将被<b>定版为只读留档</b>，之后永不被改；同时自动生成下一份可编辑草稿（版本号 +1.x）继续迭代。</div>'
          + '<div class="bom-ver-form-btns"><button type="button" class="btn btn-ghost btn-sm" onclick="bomVerShowFormCancel()">取消</button>'
          + '<button type="button" class="btn btn-success btn-sm" id="bomVerReleaseBtn" onclick="bomVerSubmitRelease(' + vid + ')">确认发布</button></div>';
    } else if (kind === 'desc') {
        if (!v) { LCSC.toast('版本不存在', 'error'); return; }
        h = '<b class="bom-ver-form-t">编辑「' + escHtml(v.version || '') + '」描述</b>'
          + '<div class="form-group"><textarea id="bomVerDescInput" rows="3" maxlength="500" placeholder="本次迭代说明，如：替换 LDO、增加滤波电容">' + escHtml(v.description || '') + '</textarea></div>'
          + '<div class="bom-ver-form-btns"><button type="button" class="btn btn-ghost btn-sm" onclick="bomVerShowFormCancel()">取消</button>'
          + '<button type="button" class="btn btn-primary btn-sm" id="bomVerDescBtn" onclick="bomVerSubmitDesc(' + vid + ')">保存描述</button></div>';
    } else if (kind === 'diff') {
        var selOpts = function(name, selId) {
            return '<select id="' + name + '"><option value="">请选择…</option>' + list.map(function(it){
                var tag = it.status === 'released' ? '已发布' : (it.status === 'archived' ? '已归档' : '草稿');
                return '<option value="' + parseInt(it.id, 10) + '"' + (parseInt(it.id, 10) === parseInt(selId, 10) ? ' selected' : '') + '>' + escHtml(it.version || '') + '（' + tag + '）</option>';
            }).join('') + '</select>';
        };
        h = '<b class="bom-ver-form-t">📊 版本对比</b>'
          + '<div class="form-group bom-diff-bar"><div class="flex-1"><label>版本 A</label>' + selOpts('diffVersionA', vid) + '</div>'
          + '<div class="flex-1"><label>版本 B</label>' + selOpts('diffVersionB', bomCurVersionId()) + '</div>'
          + '<button type="button" class="btn btn-primary" onclick="bomRunDiff()">对比</button></div>'
          + '<div id="diffResult" class="bom-diff-result"></div>'
          + '<div class="bom-ver-form-btns"><button type="button" class="btn btn-ghost btn-sm" onclick="bomVerShowFormCancel()">收起</button></div>';
        // 行内「对比」预填 A=该行版本、B=当前查看，直接出结果
        if (vid) bomRunDiff();
    }
    box.innerHTML = h;
    box.classList.add('is-on');
}
function bomVerShowFormCancel() {
    var box = document.getElementById('bomVerAction');
    if (box) { box.innerHTML = ''; box.classList.remove('is-on'); }
}
function bomVerSubmitCreate() {
    var ver = ((document.getElementById('bomVerNewVersion') || {}).value || '').trim();
    if (!ver) { LCSC.toast('请填写新版本号', 'error'); return; }
    var btn = document.getElementById('bomVerCreateBtn');
    if (btn) btn.disabled = true;
    setTimeout(function(){ if (btn) btn.disabled = false; }, 3000); // 失败路径兜底复位
    bomApi('clone_version', { project_id: bomProjectId(), base_version_id: (document.getElementById('bomVerBase') || {}).value || 0, version: ver, description: (document.getElementById('bomVerNewDesc') || {}).value || '' }, '版本已创建', false, function(){
        if (btn) btn.disabled = false;
        bomVerShowFormCancel();
        bomVerSoftRefresh();
    });
}
function bomVerSubmitRelease(vid) {
    var btn = document.getElementById('bomVerReleaseBtn');
    if (btn) btn.disabled = true;
    setTimeout(function(){ if (btn) btn.disabled = false; }, 3000);
    bomApi('release_version', { version_id: vid, change_note: (document.getElementById('bomVerReleaseNote') || {}).value || '' }, '发布成功', false, function(){
        if (btn) btn.disabled = false;
        bomVerShowFormCancel();
        bomVerSoftRefresh();
    });
}
function bomVerSubmitDesc(vid) {
    var btn = document.getElementById('bomVerDescBtn');
    if (btn) btn.disabled = true;
    setTimeout(function(){ if (btn) btn.disabled = false; }, 3000);
    bomApi('update_version', { version_id: vid, description: (document.getElementById('bomVerDescInput') || {}).value || '' }, '描述已保存', false, function(){
        if (btn) btn.disabled = false;
        bomVerShowFormCancel();
        bomVerSoftRefresh();
    });
}
function bomRenderVerPanel(list) {
    var tl = document.getElementById('bomVerTimeline');
    if (!tl) return;
    var sum = document.getElementById('bomVerSummary');
    if (sum) {
        var viewingId = bomCurVersionId();
        var viewing = list.find(function(v){ return parseInt(v.id, 10) === viewingId; }) || null;
        var draft = list.find(function(v){ return v.status === 'draft' && v.is_current; }) || null;
        var h = '';
        if (draft) {
            h += '<div class="bom-ver-sum-line"><span class="bom-ver-sum-k">当前编辑</span><b>' + escHtml(draft.version || '') + '</b><span class="bom-ver-badge is-draft">编辑中</span></div>';
            if (draft.description) h += '<div class="bom-ver-desc">' + escHtml(draft.description) + '</div>';
        }
        if (viewing && !(draft && parseInt(viewing.id, 10) === parseInt(draft.id, 10))) {
            var vtag = viewing.status === 'released' ? '已发布' : '已归档';
            h += '<div class="bom-ver-sum-line is-readonly"><span class="bom-ver-sum-k">正在查看</span><b>' + escHtml(viewing.version || '') + '</b><span class="bom-ver-badge is-' + escHtml(viewing.status) + '">' + vtag + '</span></div>';
            if (viewing.description) h += '<div class="bom-ver-desc">' + escHtml(viewing.description) + '</div>';
        }
        sum.innerHTML = h || '<div class="bom-ver-sum-line">暂无版本</div>';
    }
    if (!list.length) {
        tl.innerHTML = '<div class="empty-state"><div class="icon">🗂</div><div>还没有版本。点「＋ 新版本」从当前清单创建一份可编辑草稿。</div></div>';
        return;
    }
    tl.innerHTML = list.map(bomVerRow).join('');
}
function bomVerRow(v) {
    var vid = parseInt(v.id, 10);
    var isViewing = vid === bomCurVersionId();
    var isDraft = v.status === 'draft';
    var tag = isDraft ? '编辑中' : (v.status === 'released' ? '已发布' : '已归档');
    var acts = [];
    if (!isViewing) acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomSwitchVersion(' + vid + ')">查看</button>');
    if (isDraft) {
        if (v.is_current) acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomVerShowForm(\'release\',' + vid + ')">发布留档</button>');
        acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomVerShowForm(\'desc\',' + vid + ')">描述</button>');
        if (v.editable) acts.push('<button type="button" class="btn btn-ghost btn-xs bom-more-danger" onclick="bomDeleteVersion(' + vid + ')">删除</button>');
    } else {
        acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomVerShowForm(\'diff\',' + vid + ')">对比</button>');
        acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomOpenRollback(' + vid + ')">回滚</button>');
        if (v.status === 'released') acts.push('<button type="button" class="btn btn-ghost btn-xs" onclick="bomArchiveVersion(' + vid + ')">归档</button>');
    }
    return '<div class="bom-ver-row' + (isViewing ? ' is-viewing' : '') + (isDraft ? ' is-draft' : '') + '">'
        + '<div class="bom-ver-row-main">'
        + '<div class="bom-ver-row-top"><b class="bom-ver-no">' + escHtml(v.version || ('#' + vid)) + '</b>'
        + '<span class="bom-ver-badge is-' + escHtml(v.status) + '">' + tag + '</span>'
        + (v.released_at ? '<span class="bom-ver-time">' + escHtml(v.released_at) + '</span>' : '')
        + '</div>'
        + (v.description ? '<div class="bom-ver-desc">' + escHtml(v.description) + '</div>' : '')
        + '</div>'
        + '<div class="bom-ver-row-acts">' + acts.join('') + '</div>'
        + '</div>';
}
function bomVerSoftRefresh() {
    var pid = bomProjectId();
    if (!pid) return;
    fetch('index.php?v=bom&id=' + pid + '&tab_mode=studio', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
        .then(function(r){ return r.text(); })
        .then(function(html){
            var doc = new DOMParser().parseFromString(html, 'text/html');
            bomSwapRegion('bomStickyBar', doc);
            bomSwapRegion('bomRegion', doc);
            bomSwapRegion('bomVersionHint', doc);
            bomSwapRegion('bomCostSummary', doc);
            bomVerFetchList(function(list){
                var draft = list.find(function(v){ return v.status === 'draft' && v.is_current; });
                var targetId = draft ? parseInt(draft.id, 10) : bomCurVersionId();
                var vv = list.find(function(v){ return parseInt(v.id, 10) === targetId; }) || null;
                if (BomPageConfig) {
                    BomPageConfig.viewVersionId = targetId;
                    BomPageConfig.viewVersion = vv || { id: targetId, status: '' };
                    BomPageConfig.bomReadonly = !!(vv && (vv.status === 'released' || vv.status === 'archived'));
                }
                var region = document.getElementById('bomRegion');
                if (region) {
                    region.setAttribute('data-version-id', String(targetId));
                    region.setAttribute('data-version-status', (vv && vv.status) || '');
                }
                _bomFilterState.page = 1;
                _bomFilterState.filter = '';
                bomRenderVerPanel(list);
            });
        })
        .catch(function(){ bomReload(); }); // 兜底：软刷新失败退回整页
}
function bomVerTargetId(vid) {
    vid = parseInt(vid, 10);
    if (vid > 0) return vid;
    var vers = (BomPageConfig && BomPageConfig.bomVersions) || [];
    var d = vers.find(function(v){ return v.status === 'draft' && v.is_current; });
    return d ? parseInt(d.id, 10) : bomCurVersionId();
}
function bomVerById(vid) {
    var vers = (BomPageConfig && BomPageConfig.bomVersions) || [];
    return vers.find(function(v){ return parseInt(v.id, 10) === parseInt(vid, 10); }) || null;
}

// 更多操作菜单
function bomToggleMore() {
    var m = document.getElementById('bomMoreMenu');
    // **判据从 `style.display` 换成 `classList`** —— 视图里那条
    if (m) m.classList.toggle('is-on');
}
document.addEventListener('click', function(e) {
    var menu = document.getElementById('bomMoreMenu');
    if (!menu) return;
    if (menu.classList.contains('is-on') && !e.target.closest('#bomMoreBtn') && !e.target.closest('#bomMoreMenu')) {
        menu.classList.remove('is-on');
    }
});

// ═══════ 版本管理入口 ═══════（动作全部收进侧滑版本面板 bomOpenVerPanel，表单内联）

function bomApi(action, data, okMsg, reload, after) {
    var fd = new FormData();
    fd.append('_csrf', (BomPageConfig && BomPageConfig.csrf) || '');
    for (var k in data) fd.append(k, data[k]);
    fetch('api.php?action=bom.' + action, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.code === 0) {
                LCSC.toast(okMsg || d.msg || '操作成功', 'success');
                if (after) after();
                else if (reload) setTimeout(function(){ bomReload(); }, 400);
            } else {
                LCSC.toast(d.msg || '操作失败', 'error');
            }
        })
        .catch(function(err){ LCSC.toast('操作失败：' + err.message, 'error'); });
}
function bomCurVersionId() {
    // 优先读内容区标记（软切换版本后 DOM 会被替换，config 可能滞后）
    var el = document.getElementById('bomRegion');
    if (el && el.getAttribute('data-version-id')) return parseInt(el.getAttribute('data-version-id'), 10) || 0;
    return (BomPageConfig && BomPageConfig.viewVersionId) || 0;
}
function bomProjectId() { return (BomPageConfig && BomPageConfig.selId) || 0; }

// 回滚到指定版本：克隆为恢复草稿（面板行内触发；确认框浮于面板之上，面板保持打开）
function bomOpenRollback(vid) {
    vid = bomVerTargetId(vid);
    var cur = bomVerById(vid) || {};
    var name = cur.version || ('#' + vid);
    AdminUIConfirm({
        title: '回滚到版本',
        message: '将基于版本「' + name + '」克隆生成一个恢复草稿，并作为当前编辑（原版本保持不变）。历史版本始终保留。确认回滚？',
        okText: '确认回滚',
        onOk: function(){ bomApi('rollback_version', { project_id: bomProjectId(), version_id: vid }, '已生成回滚草稿', false, bomVerSoftRefresh); }
    });
}

function bomArchiveVersion(vid) {
    vid = bomVerTargetId(vid);
    var cur = bomVerById(vid);
    if (!cur || cur.status === 'draft') { LCSC.toast('草稿版本请直接发布或删除，无法归档', 'error'); return; }
    AdminUIConfirm({
        title: '归档版本',
        message: '归档后版本以只读方式留存，不再出现在版本迭代默认视图。确定归档？',
        okText: '确认归档',
        onOk: function(){ bomApi('archive_version', { version_id: vid }, '已归档', false, bomVerSoftRefresh); }
    });
}

// 删除草稿版本（仅非当前草稿；当前编辑草稿服务端也会拦截）
function bomDeleteVersion(vid) {
    vid = bomVerTargetId(vid);
    var cur = bomVerById(vid);
    if (!cur || cur.status !== 'draft') { LCSC.toast('仅草稿版本可删除', 'error'); return; }
    AdminUIConfirm({
        title: '删除草稿版本',
        message: '将删除草稿版本「' + (cur.version || '') + '」及其全部明细（不可恢复）。确定删除？',
        okText: '确认删除',
        onOk: function(){ bomApi('delete_version', { version_id: vid }, '草稿已删除', false, bomVerSoftRefresh); }
    });
}

function bomRunDiff() {
    var a = document.getElementById('diffVersionA').value;
    var b = document.getElementById('diffVersionB').value;
    if (!a || !b || a === b) { LCSC.toast('请选择两个不同版本进行对比', 'error'); return; }
    var fd = new FormData();
    fd.append('_csrf', (BomPageConfig && BomPageConfig.csrf) || '');
    fd.append('version_a', a);
    fd.append('version_b', b);
    fetch('api.php?action=bom.diff_versions', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.code !== 0) { LCSC.toast(d.msg || '对比失败', 'error'); return; }
            renderDiff(d.data);
        })
        .catch(function(err){ LCSC.toast('对比失败：' + err.message, 'error'); });
}
function verName(id) {
    var vers = (BomPageConfig && BomPageConfig.bomVersions) || [];
    var v = vers.find(function(x){ return parseInt(x.id, 10) === parseInt(id, 10); });
    return v ? v.version : ('#' + id);
}
function escDiff(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
}
function renderDiff(data) {
    var html = '';
    var added = data.added || [], removed = data.removed || [], changed = data.qty_changed || [];
    html += '<div class="bom-diff-summary-bar">A（'+ escDiff(verName(data.a.id)) +'）→ B（'+ escDiff(verName(data.b.id)) +'）</div>';
    function row(kind, title, cnt, items) {
        var mark = kind === 'added' ? '🟢' : (kind === 'removed' ? '🔴' : '🟡');
        var cls = kind === 'added' ? 'bom-diff-add' : (kind === 'removed' ? 'bom-diff-del' : 'bom-diff-chg');
        html += '<details class="bom-diff-group is-spaced">'
             + '<summary class="bom-diff-summary">' + mark + ' ' + title + '（' + cnt + '）</summary>'
             + '<table class="data-table bom-diff-tb bom-diff-table"><thead><tr>'
             + '<th>编号</th><th>型号</th><th>数量</th><th>位号</th><th>变更</th></tr></thead><tbody>';
        items.forEach(function(it){
            var pn = it.platform_part_no || '—', md = it.model || '—';
            var qty, ref, chg;
            if (kind === 'qty_changed') {
                qty = (it.a ? it.a.qty : '—') + ' → ' + (it.b ? it.b.qty : '—');
                ref = (it.a ? escDiff(it.a.reference) : '') + ' → ' + (it.b ? escDiff(it.b.reference) : '');
                chg = (it.changed || []).map(function(f){ return ({qty:'数量',reference:'位号',remark:'备注'}[f]||f); }).join('、');
            } else {
                qty = it.qty; ref = escDiff(it.reference || '—'); chg = kind === 'added' ? '新增' : '移除';
            }
            html += '<tr class="' + cls + '"><td>' + escDiff(pn) + '</td><td>' + escDiff(md) + '</td><td>' + qty + '</td><td>' + ref + '</td><td>' + chg + '</td></tr>';
        });
        html += '</tbody></table></details>';
    }
    if (added.length) row('added', '新增物料', added.length, added);
    if (removed.length) row('removed', '移除物料', removed.length, removed);
    if (changed.length) row('qty_changed', '数量/位号/备注变更', changed.length, changed);
    if (!added.length && !removed.length && !changed.length) html += '<div class="bom-diff-none">两个版本明细完全一致，无差异。</div>';
    document.getElementById('diffResult').innerHTML = html;
}
