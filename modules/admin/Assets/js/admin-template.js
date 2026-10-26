// modules/admin/Assets/js/admin-template.js — 后台管理页（?v=admin）· 平台导入模板（上传样本 → 自动识别 → 人工确认 → 保存）

var TplState = { platformId: 0, code: '', headers: [], detected: [], fields: {}, matchKeys: [], matchKeyOptions: [], matchKeyRows: [] };

function tplOpen(){ var o = document.getElementById('tplModal'); if (o) o.classList.add('open'); }
function tplClose(){ var o = document.getElementById('tplModal'); if (o) o.classList.remove('open'); }

function tplBusy(btn, busy, label){
    if (!btn) return;
    if (busy) {
        if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = label || '处理中...';
    } else {
        btn.disabled = false;
        if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
    }
}

/* 打开模板弹窗 */
function openTplModal(platformId, platformName, platformCode){
    TplState = { platformId: platformId, code: platformCode || '', headers: [], detected: [], fields: {}, matchKeys: [], matchKeyOptions: [], matchKeyRows: [] };
    document.getElementById('tplPlatName').textContent = '— ' + platformName;
    document.getElementById('tplStatusBar').innerHTML = '<span class="tpl-note">加载中…</span>';
    document.getElementById('tplMatchKeys').innerHTML = '<span class="tpl-note is-muted">加载中…</span>';
    document.getElementById('tplMapWrap').style.display = 'none';
    document.getElementById('tplSaveBtn').style.display = 'none';
    document.getElementById('tplResetBtn').style.display = 'none';
    document.getElementById('tplMapBody').innerHTML = '';
    document.getElementById('tplPreviewWrap').innerHTML = '';
    document.getElementById('tplFile').value = '';
    tplOpen();

    LCSC.fetchJson('app/api/router.php?action=import.template_get&platform_id=' + encodeURIComponent(platformId))
        .then(function(resp){
            var d = (resp && resp.data) || {};
            TplState.fields = d.fields || {};
            TplState.matchKeys = d.match_keys || [];
            TplState.matchKeyOptions = d.match_key_options || [];
            tplRenderMatchKeys();
            var bar = '';
            if (d.has_template) {
                bar = '<span class="badge badge-green">已配置模板</span>'
                    + '<span class="tpl-note is-spaced">'
                    + '格式 ' + ((d.profiles || []).length) + ' 套 · 修订 r' + (d.revision || 1)
                    + (d.is_builtin ? ' · 内置模板' : '') + '</span>';
                if (d.is_builtin) document.getElementById('tplResetBtn').style.display = '';
            } else {
                bar = '<span class="badge badge-yellow">未配置模板</span>'
                    + '<span class="tpl-note is-muted is-spaced">未配置也可直接导入（走智能识别），上传样本保存后可按模板精准匹配</span>';
            }
            document.getElementById('tplStatusBar').innerHTML = bar;
        })
        .catch(function(msg){
            document.getElementById('tplStatusBar').innerHTML = '<span class="badge badge-red">加载失败</span>';
            document.getElementById('tplMatchKeys').innerHTML = '<span class="tpl-note is-muted">—</span>';
            LCSC.toast(String(msg), 'error');
        });
}

/* 渲染匹配键优先级列表（勾选 + 上下移；顺序即优先级） */
function tplRenderMatchKeys(){
    var opts = TplState.matchKeyOptions || [];
    if (!opts.length) {
        // 后端未返回选项时的兜底（老版本兼容）
        opts = [
            { field: 'platform_part_no', label: '商品编号（平台编号）', hint: '同平台同编号 = 同一物料' },
            { field: 'model', label: '型号', hint: '型号等价比较（去分隔符/大写）' },
            { field: 'customer_part_no', label: '客户料号', hint: '以自有料号为准' }
        ];
    }
    if (!TplState.matchKeyRows || !TplState.matchKeyRows.length) {
        // 首次：已选键按优先级在前，其余按选项顺序追加
        var rows = [];
        (TplState.matchKeys || []).forEach(function(k){
            if (opts.some(function(o){ return o.field === k; }) && rows.indexOf(k) < 0) rows.push(k);
        });
        opts.forEach(function(o){ if (rows.indexOf(o.field) < 0) rows.push(o.field); });
        TplState.matchKeyRows = rows;
    }

    var keys = TplState.matchKeys || [];
    var html = '';
    TplState.matchKeyRows.forEach(function(f, i){
        var meta = null;
        opts.forEach(function(o){ if (o.field === f) meta = o; });
        if (!meta) return;
        var checked = keys.indexOf(f) >= 0;
        html += '<div class="tpl-key-row">'
             +  '<label class="tpl-key-label">'
             +    '<input type="checkbox" class="tpl-key-chk" data-field="' + escAdmin(f) + '"' + (checked ? ' checked' : '') + ' onchange="tplKeyToggle()">'
             +    '<span class="tpl-key-name">' + escAdmin(meta.label) + '</span>'
             +    '<span class="tpl-key-hint">' + escAdmin(meta.hint || '') + '</span>'
             +  '</label>'
             +  '<span class="tpl-key-prio">' + (checked ? ('优先级 ' + (keys.indexOf(f) + 1)) : '未启用') + '</span>'
             +  '<button type="button" class="btn btn-ghost btn-xs" onclick="tplKeyMove(' + i + ',-1)">↑</button>'
             +  '<button type="button" class="btn btn-ghost btn-xs" onclick="tplKeyMove(' + i + ',1)">↓</button>'
             +  '</div>';
    });
    document.getElementById('tplMatchKeys').innerHTML = html;
}

function tplKeySync(){
    var keys = [];
    TplState.matchKeyRows.forEach(function(f){
        var el = document.querySelector('#tplMatchKeys .tpl-key-chk[data-field="' + f + '"]');
        if (el && el.checked) keys.push(f);
    });
    TplState.matchKeys = keys;
    return keys;
}

/* 勾选变化：同步状态并刷新优先级标签 */
function tplKeyToggle(){
    tplKeySync();
    tplRenderMatchKeys();
}

function tplKeyMove(i, dir){
    var j = i + dir;
    var rows = TplState.matchKeyRows.slice();
    if (j < 0 || j >= rows.length) return;
    var keys = tplKeySync();   // 换序前先取当前勾选（旧行序）
    var tmp = rows[i]; rows[i] = rows[j]; rows[j] = tmp;
    TplState.matchKeyRows = rows;

    var reordered = [];
    rows.forEach(function(f){ if (keys.indexOf(f) >= 0) reordered.push(f); });
    TplState.matchKeys = reordered;
    tplRenderMatchKeys();
}

/* 上传样本并识别列映射 */
function tplParseSample(){
    var f = document.getElementById('tplFile');
    if (!f || !f.files || !f.files[0]) { LCSC.toast('请先选择该平台的样本文件', 'warning'); return; }

    var btn = document.getElementById('tplParseBtn');
    tplBusy(btn, true, '识别中...');
    var fd = new FormData();
    fd.append('platform_id', TplState.platformId);
    fd.append('platform_code', TplState.code);
    fd.append('file', f.files[0]);

    LCSC.post('app/api/router.php?action=import.template_parse', fd, function(data){
        tplBusy(btn, false);
        TplState.headers  = data.headers || [];
        TplState.detected = data.detected || [];
        tplRenderMapping(data);
        LCSC.toast('已识别 ' + TplState.headers.length + ' 列，请确认映射', 'success');
    }, function(msg){
        tplBusy(btn, false);
        LCSC.toast(String(msg), 'error');
    });
}

/* 渲染映射确认表 */
function tplRenderMapping(data){
    var fields = data.fields || TplState.fields || {};
    var detected = data.detected || [];
    var body = '';
    var unknown = 0, mapped = 0;

    TplState.headers.forEach(function(h, i){
        // detected 的键是列序（JSON 后为字符串）
        var d = detected[i] || detected[String(i)] || null;
        var cur = d ? (d.field || '') : '';
        if (cur === '' && h !== '') unknown++; else if (cur !== '') mapped++;

        var opts = '<option value="">— 忽略此列 —</option>';
        Object.keys(fields).forEach(function(k){
            opts += '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + escAdmin(fields[k]) + '（' + k + '）</option>';
        });

        // 空列 / 有数据但未指派：两种状态色class 切换，不拼 style 串
        var nameCls = (h === '') ? 'is-empty' : (cur === '' ? 'is-unmapped' : '');
        body += '<tr>'
            + '<td class="tpl-map-col-name ' + nameCls + '">' + (h === '' ? '（空列）' : escAdmin(h)) + '</td>'
            + '<td><select class="tpl-col-sel tpl-map-col-sel" data-idx="' + i + '">' + opts + '</select></td>'
            + '<td class="tpl-map-col-why">' + (d ? escAdmin(d.rule || '') : '') + '</td>'
            + '</tr>';
    });

    document.getElementById('tplMapBody').innerHTML = body;
    document.getElementById('tplMapSummary').innerHTML =
        '共 ' + TplState.headers.length + ' 列，已识别 <strong>' + mapped + '</strong> 列'
        + (unknown > 0 ? '，<span class="tpl-map-warn">待处理 ' + unknown + ' 列</span>（请指派或保持忽略）' : '')
        + '；样本 ' + (data.total_rows || 0) + ' 行数据' + (data.sheet_name ? '（Sheet: ' + escAdmin(data.sheet_name) + '）' : '');

    // 预览
    var prev = data.preview || [];
    if (prev.length) {
        var ph = '<div class="tpl-prev-title">样本前 ' + Math.min(prev.length, 5) + ' 行预览</div>'
               + '<div class="tpl-prev-scroll">'
               + '<table class="data-table tpl-prev-tb"><thead><tr>';
        TplState.headers.forEach(function(h){ ph += '<th>' + escAdmin(h || '') + '</th>'; });
        ph += '</tr></thead><tbody>';
        prev.slice(0, 5).forEach(function(row){
            ph += '<tr>';
            TplState.headers.forEach(function(_, i){ ph += '<td>' + escAdmin(row[i] == null ? '' : row[i]) + '</td>'; });
            ph += '</tr>';
        });
        ph += '</tbody></table></div>';
        document.getElementById('tplPreviewWrap').innerHTML = ph;
    } else {
        document.getElementById('tplPreviewWrap').innerHTML = '';
    }

    document.getElementById('tplMapWrap').style.display = '';
    document.getElementById('tplSaveBtn').style.display = '';
}

/* 保存模板 */
function tplSave(){
    var sels = document.querySelectorAll('#tplMapBody .tpl-col-sel');
    if (!sels.length) { LCSC.toast('请先上传样本并识别', 'warning'); return; }

    var columns = {}, ignored = [], hasKey = false;
    sels.forEach(function(sel){
        var idx = sel.getAttribute('data-idx');
        var v = sel.value || '';
        if (v === '') { ignored.push(parseInt(idx, 10)); }
        else {
            columns[idx] = v;
            if (v === 'platform_part_no' || v === 'model') hasKey = true;
        }
    });
    if (!hasKey) { LCSC.toast('至少需要映射「商品编号」或「型号」其中一列', 'error'); return; }

    var matchKeys = tplKeySync();
    if (!matchKeys.length) { LCSC.toast('请至少勾选 1 个匹配键（决定「哪些行算同一物料」）', 'error'); return; }

    var btn = document.getElementById('tplSaveBtn');
    tplBusy(btn, true, '保存中...');
    LCSC.post('app/api/router.php?action=import.template_save', {
        platform_id: TplState.platformId,
        platform_code: TplState.code,
        headers: JSON.stringify(TplState.headers),
        columns: JSON.stringify(columns),
        ignored: JSON.stringify(ignored),
        match_keys: JSON.stringify(matchKeys)
    }, function(data, msg){
        tplBusy(btn, false);
        LCSC.toast(msg || ('模板已保存（修订 r' + (data.revision || 1) + '）'), 'success');
        tplClose();
        if (typeof loadTemplateStatus === 'function') loadTemplateStatus();
        else setTimeout(function(){ location.reload(); }, 600);
    }, function(msg){
        tplBusy(btn, false);
        LCSC.toast(String(msg), 'error');
    });
}

/* 重置为内置模板 */
