// modules/bom/Assets/js/bom-import.js — BOM 管理页（?v=bom）· BOM 导入表单拦截（multipart → api.php）

function importSetBar(pct, status){
    var bar = document.getElementById('importBar');
    var per = document.getElementById('importPercent');
    var st  = document.getElementById('importStatus');
    if (bar) bar.style.width = pct + '%';
    if (per) per.textContent = pct + '%';
    if (status && st) st.textContent = status;
}
function importResetUI(){
    var wrap = document.getElementById('importProgressWrap');
    var box = document.getElementById('importResultBox');
    var done = document.getElementById('importDoneBtn');
    var submit = document.getElementById('importSubmitBtn');
    if (wrap) wrap.classList.remove('is-on');
    if (box) box.className = 'bom-import-box';
    if (done) done.classList.remove('is-on');
    // 「开始导入」按钮：导入结束后让位给「完成」按钮。
    if (submit){ submit.classList.remove('is-hidden'); submit.disabled = false; }
}
function bomImportDone(){
    closeOverlay('importModal');
    bomReload();
}
// 导入完成后直达未绑定清单（filter=unbound）
function bomImportGoPending(){
    closeOverlay('importModal');
    bomReload();   // 先刷新明细 + 缺料分析（导入后二者都是旧数据）
    _bomFilterState.filter = 'unbound';
    _bomFilterState.page = 1;
    loadBomItems();
    LCSC.toast('已筛选：未绑定（待替代料绑定/替换）', 'info');
}
(function(){
    var f = document.getElementById('importForm');
    if (!f) return;
    f.addEventListener('submit', function(e){
        e.preventDefault();
        var act = f.getAttribute('action') || 'api.php?action=bom.import_bom';
        var fd = new FormData(f);
        var submit = document.getElementById('importSubmitBtn');
        var wrap = document.getElementById('importProgressWrap');
        var box = document.getElementById('importResultBox');
        var done = document.getElementById('importDoneBtn');
        if (wrap) wrap.classList.add('is-on');
        if (box) box.className = 'bom-import-box';
        if (done) done.classList.remove('is-on');
        if (submit){ submit.disabled = true; }
        importSetBar(0, '正在解析并导入…');
        importSetBar(12, '正在解析并导入…');
        var t1 = setTimeout(function(){ importSetBar(45, '正在解析并导入…'); }, 200);
        var t2 = setTimeout(function(){ importSetBar(72, '正在解析并导入…'); }, 700);
        fetch(act, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(function(r){
                var ct = (r.headers.get('content-type') || '');
                if (ct.indexOf('application/json') === -1) {
                    throw new Error('服务器返回了非 JSON 响应（可能被重定向或已超时）');
                }
                return r.json();
            })
            .then(function(d){
                clearTimeout(t1); clearTimeout(t2);
                if (d.code === 0) {
                    importSetBar(100, '导入完成');
                    var data = d.data || {};
                    var pendingCnt = parseInt(data.pending || 0, 10);
                    var cell = function(num, label, tone){
                        return '<div class="bom-imp-cell">'
                            + '<div class="bom-imp-cell-v ' + tone + '">' + num + '</div>'
                            + '<div class="bom-imp-cell-k">' + label + '</div></div>';
                    };
                    var html = '<div class="bom-imp-msg is-ok">✅ ' + escHtml(d.msg || '导入成功') + '</div>'
                        + '<div class="bom-imp-grid">'
                        + cell(parseInt(data.matched || 0, 10), '已匹配入库', 'is-ok')
                        + cell(pendingCnt, '未绑定', 'is-warn')
                        + cell(parseInt(data.skipped || 0, 10), '跳过行', 'is-muted')
                        + '</div>';
                    if (pendingCnt > 0) {
                        html += '<div class="bom-imp-tip">未绑定物料仅保存在本 BOM（未进入主库），缺料分析按可用量 0 计。逐行点「替代料」从物料库选择并绑定/替换即可处理。</div>'
                            + '<button type="button" class="btn btn-warning btn-sm bom-imp-go" onclick="bomImportGoPending()">查看未绑定（' + pendingCnt + '）</button>';
                    }
                    box.className = 'bom-import-box is-on is-ok';
                    box.innerHTML = html;
                    if (done) done.classList.add('is-on');
                    if (submit){ submit.classList.add('is-hidden'); }
                } else {
                    box.innerHTML = '<div class="bom-imp-msg is-bad">❌ ' + escHtml(d.msg || '导入失败') + '</div>';
                    box.className = 'bom-import-box is-on is-bad';
                    if (wrap) wrap.classList.remove('is-on');
                    if (done) done.classList.remove('is-on');
                    if (submit){ submit.classList.remove('is-hidden'); submit.disabled = false; }
                }
            })
            .catch(function(err){
                clearTimeout(t1); clearTimeout(t2);
                importSetBar(100, '导入中止');
                box.innerHTML = '<div class="bom-imp-msg is-bad">❌ ' + escHtml(err.message || '导入失败') + '</div>'
                    + '<div class="bom-imp-tip">请确认文件格式正确、服务器 PHP error_log 无异常，重试。</div>';
                box.className = 'bom-import-box is-on is-bad';
                if (done) done.classList.remove('is-on');
                if (submit){ submit.classList.remove('is-hidden'); submit.disabled = false; }
            });
    });
})();
