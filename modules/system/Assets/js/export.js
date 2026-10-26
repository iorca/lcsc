// modules/system/Assets/js/export.js — 数据导出中心（?v=export）交互脚本
function exportChecklist() {
    var params = new URLSearchParams({
        action: 'stock.export_checklist',
        category_id: document.getElementById('expChkCat').value,
        location: '',
        platform_id: document.getElementById('expChkPlat').value
    });
    fetch('api.php?' + params.toString(), {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data && typeof data.code !== 'undefined' && data.code !== 0) {
            LCSC.toast('导出失败：' + (data.msg || ('错误码 ' + data.code)), 'error');
            return;
        }
        var rows = (data && data.data) ? data.data : (data || []);
        if (!Array.isArray(rows)) rows = [];
        if (rows.length === 0) {
            LCSC.toast('当前筛选条件下没有可导出的盘点数据', 'warning');
            return;
        }
        var headers = ['id','internal_id','platform_part_no','model','product_name','brand','package','location','system_qty','actual_qty','snapshot_time'];
        var csv = '\uFEFF' + headers.join(',') + '\n';
        rows.forEach(function(row) {
            var line = headers.map(function(h) {
                var v = row[h] ?? '';
                v = String(v).replace(/"/g, '""');
                if (v.indexOf(',') >= 0 || v.indexOf('\n') >= 0) v = '"' + v + '"';
                return v;
            }).join(',');
            csv += line + '\n';
        });
        var blob = new Blob([csv], {type: 'text/csv;charset=utf-8'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'checklist_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
    })
    .catch(function(e) {
        LCSC.toast('导出失败：' + e.message, 'error');
    });
}

// ── BOM 缺料清单导出：动态拼装 api.php?action=bom.shortage_csv 链接 ──
var expBomProj = document.getElementById('expBomProj');
var expBomQty  = document.getElementById('expBomQty');
var expBomLink = document.getElementById('expBomLink');
function updateBomLink() {
    if (!expBomProj || !expBomLink) return;
    var qty = parseInt(expBomQty.value, 10);
    if (isNaN(qty) || qty < 1) qty = 1;
    expBomLink.href = 'api.php?action=bom.shortage_csv&project_id=' + expBomProj.value + '&quantity=' + qty;
}
if (expBomProj) {
    expBomProj.addEventListener('change', updateBomLink);
    expBomQty.addEventListener('change', updateBomLink);
    updateBomLink();
}
