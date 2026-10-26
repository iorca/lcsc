// modules/stock/Assets/js/checklist.js — 库存盘点页（?v=checklist）交互脚本
function switchMode(mode) {
    var online = document.getElementById('onlineMode');
    var excel  = document.getElementById('excelMode');
    var tabO   = document.getElementById('tabOnline');
    var tabE   = document.getElementById('tabExcel');
    online.classList.toggle('active', mode === 'online');
    excel.classList.toggle('active',  mode === 'excel');
    tabO.classList.toggle('active',   mode === 'online');
    tabE.classList.toggle('active',   mode === 'excel');
    if (mode === 'online') CS.loadSessions();
}
document.addEventListener('DOMContentLoaded', function () { CS.loadSessions(); });

function exportChecklist() {
    var cat = document.getElementById('exportCat').value;
    var loc = document.getElementById('exportLoc').value;
    var plat = document.getElementById('exportPlat').value;
    var params = new URLSearchParams({
        action: 'stock.export_checklist',
        category_id: cat,
        location: loc,
        platform_id: plat
    });
    var a = document.createElement('a');
    a.href = 'app/api/router.php?' + params.toString();
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

var dropZone = document.getElementById('dropZone');
if (dropZone) {
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });
    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
    });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            document.getElementById('checkFile').files = e.dataTransfer.files;
            handleFileSelect({files: e.dataTransfer.files});
        }
    });
}

function handleFileSelect(input) {
    var file = input.files && input.files[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.csv')) {
        showImportResult('请选择 CSV 文件', 'error');
        return;
    }
    var reader = new FileReader();
    reader.onload = function(e) {
        var text = e.target.result;
        if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
        var rows = parseCSV(text);
        if (rows.length < 2) {
            showImportResult('CSV 文件为空或格式错误', 'error');
            return;
        }
        var headers = rows[0].map(function(h){ return h.trim().toLowerCase(); });
        var partIdIdx = headers.indexOf('part_id');
        if (partIdIdx < 0) partIdIdx = headers.indexOf('id');
        var actualQtyIdx = headers.indexOf('actual_qty');
        if (partIdIdx < 0 || actualQtyIdx < 0) {
            showImportResult('CSV 缺少必需列：需要 part_id 和 actual_qty 列', 'error');
            return;
        }
        var items = [];
        for (var i = 1; i < rows.length; i++) {
            var row = rows[i];
            var partId = parseInt(row[partIdIdx], 10);
            var actualQty = parseInt(row[actualQtyIdx], 10);
            if (!isNaN(partId) && partId > 0 && !isNaN(actualQty) && actualQty >= 0) {
                items.push({part_id: partId, actual_qty: actualQty, remark: '盘点导入'});
            }
        }
        if (items.length === 0) {
            showImportResult('没有有效的盘点数据行', 'error');
            return;
        }
        importChecklist(items);
    };
    reader.readAsText(file, 'UTF-8');
}

function parseCSV(text) {
    var rows = [];
    var cur = [];
    var field = '';
    var inQuote = false;
    for (var i = 0; i < text.length; i++) {
        var c = text[i];
        if (inQuote) {
            if (c === '"') {
                if (text[i+1] === '"') { field += '"'; i++; }
                else { inQuote = false; }
            } else { field += c; }
        } else {
            if (c === '"') inQuote = true;
            else if (c === ',') { cur.push(field); field = ''; }
            else if (c === '\n') { cur.push(field); rows.push(cur); cur = []; field = ''; }
            else if (c === '\r') { /* skip */ }
            else { field += c; }
        }
    }
    if (field || cur.length > 0) { cur.push(field); rows.push(cur); }
    return rows;
}

function importChecklist(items) {
    var resultEl = document.getElementById('importResult');
    resultEl.className = 'check-result';
    resultEl.innerHTML = '<strong>正在导入...</strong> (' + items.length + ' 条)';
    fetch('app/api/router.php?action=stock.check_import', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': (window.LCSC && LCSC.csrf) || ''
        },
        body: JSON.stringify(items),
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.code === 0) {
            var res = data.data || {};
            var msg = '✓ 导入完成：调整 ' + (res.adjusted || 0) + ' 条，跳过 ' + (res.skipped || 0) + ' 条';
            if (res.errors && res.errors.length > 0) {
                msg += '<pre>' + res.errors.join('\n') + '</pre>';
            }
            showImportResult(msg, 'success');
        } else {
            showImportResult('导入失败：' + (data.msg || '未知错误'), 'error');
        }
    })
    .catch(function(e) {
        showImportResult('导入失败：' + e.message, 'error');
    });
}

function showImportResult(msg, type) {
    var el = document.getElementById('importResult');
    if (!el) return;
    el.className = 'check-result ' + type;
    el.innerHTML = msg;
}
