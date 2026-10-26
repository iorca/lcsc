// 近12个月总资产折线图
var assetLineCtx = document.getElementById('assetLineChart');
if(assetLineCtx){
    new Chart(assetLineCtx, {
        type: 'line',
        data: {
            labels: window.AssetsPageConfig.assetLabels,
            datasets: [{
                label: '库存总成本',
                data: window.AssetsPageConfig.assetValues,
                borderColor: '#4f8ef7',
                backgroundColor: 'rgba(79,142,247,.10)',
                tension: .3, fill: true, pointRadius: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 } } },
                y: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 }, callback: function(v){return '¥'+v;} }, beginAtZero: true }
            }
        }
    });
}
// 月度入库出库金额对比柱状图
var assetBarCtx = document.getElementById('assetBarChart');
if(assetBarCtx){
    new Chart(assetBarCtx, {
        type: 'bar',
        data: {
            labels: window.AssetsPageConfig.barLabels,
            datasets: [
                { label: '入库金额', data: window.AssetsPageConfig.barInValues, backgroundColor: 'rgba(34,197,94,.7)', borderColor: '#22c55e', borderWidth: 1 },
                { label: '出库金额', data: window.AssetsPageConfig.barOutValues, backgroundColor: 'rgba(239,68,68,.7)', borderColor: '#ef4444', borderWidth: 1 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: true, labels: { color: '#7a86a8', font: { size: 10 }, boxWidth: 12 } } },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 } } },
                y: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 }, callback: function(v){return '¥'+v;} }, beginAtZero: true }
            }
        }
    });
}
var assetPieCtx = document.getElementById('assetPieChart');
var catDistData = (window.AssetsPageConfig.catDist || []).slice().sort(function(a,b){ return (b.value||0) - (a.value||0); });
if(assetPieCtx){
    if(catDistData.length === 0){
        // 无成本数据或库存为 0：显示空状态占位
        assetPieCtx.parentNode.innerHTML = '<div class="as-pie-empty">'
            + '<span class="as-pie-empty-ico">🍰</span>'
            + '<span>暂无可统计的分类资产</span>'
            + '<span class="as-pie-empty-sub">需物料有库存且已录入成本单价</span>'
            + '</div>';
    } else {
        var pieColors = ['#4f8ef7','#22c55e','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16','#f97316','#14b8a6','#6366f1','#a3e635'];
        // 边框色不能用 CSS 变量（Canvas 无法解析），按主题取实际色值
        var isDark = document.documentElement.getAttribute('data-theme') !== 'light';
        var pieBorder = isDark ? '#1e2030' : '#ffffff';
        new Chart(assetPieCtx, {
            type: 'doughnut',
            data: {
                labels: catDistData.map(function(d){ return d.name; }),
                datasets: [{
                    data: catDistData.map(function(d){ return d.value; }),
                    backgroundColor: pieColors.slice(0, catDistData.length),
                    borderWidth: 2, borderColor: pieBorder
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { position: 'right', labels: { color: '#7a86a8', font: { size: 10 }, boxWidth: 10, padding: 6 } },
                    tooltip: { callbacks: { label: function(ctx){ return ctx.label + ': ¥' + formatNum(ctx.parsed, 2); } } }
                }
            }
        });
    }
}

// ── 流水查询（AJAX 走 api.php?action=asset.logs 返回 JSON）──
function submitFilter(e){
    e.preventDefault();
    loadLogs(1);
    return false;
}
function loadLogs(page){
    var form = document.getElementById('assetFilterForm');
    var params = new URLSearchParams(new FormData(form));
    params.set('action', 'asset.logs');
    params.set('page', page);
    var ppSel = document.querySelector('#logsTableWrap .per-page-select');
    if(ppSel) params.set('per_page', ppSel.value);
    var wrap = document.getElementById('logsTableWrap');
    if(wrap) wrap.style.opacity = '.5';
    fetch('api.php?' + params.toString())
        .then(function(r){ return r.json(); })
        .then(function(res){
            if(wrap) wrap.style.opacity = '1';
            if(!res || res.code !== 0){
                LCSC.toast((res && res.msg) ? res.msg : '加载失败', 'error');
                return;
            }
            renderLogs(res.data);
        })
        .catch(function(){
            if(wrap) wrap.style.opacity = '1';
            LCSC.toast('网络错误，请稍后重试', 'error');
        });
}
// 从 JSON 渲染流水表格（视觉与原 PHP 输出完全一致）
function renderLogs(data){
    var logs = data.logs || [];
    var total = data.total;
    var page = data.page;
    var totalPages = data.total_pages;
    var perPage = data.per_page;
    var typeLabels = data.type_labels || {};

    var html = '<div class="sec-title">出入库流水 <span class="as-count-note">（共 ' + total + ' 条，第 ' + page + '/' + totalPages + ' 页）</span></div>';
    html += '<div class="as-scroll-x"><table class="data-table as-tb-sm"><thead><tr>';
    html += '<th class="as-th-pad as-th-c as-th-check"><input type="checkbox" id="selectAll" onchange="toggleAll(this)" title="全选/取消全选"></th>';
    ['入库时间','物料','分类','采购平台','操作类型'].forEach(function(th){
        html += '<th class="as-th-pad">' + th + '</th>';
    });
    html += '<th class="as-th-pad as-th-r">变动数量</th><th class="as-th-pad as-th-r">入库单价</th><th class="as-th-pad as-th-r">含税小计</th>';
    html += '</tr></thead><tbody>';

    if(logs.length === 0){
        html += '<tr><td colspan="9" class="as-td-empty">暂无数据</td></tr>';
    } else {
        logs.forEach(function(l){
            var tl = typeLabels[l.change_type] || [l.change_type, ''];
            var lab = tl[0], col = tl[1];
            var isSmp = l.is_sample === 1;
            var isUp = l.qty_change >= 0;
            var qtySign = isUp ? '+' : '';
            var unitCost = (l.unit_cost > 0) ? ('¥' + formatNum(l.unit_cost, 4)) : '—';
            var subtotal = (l.subtotal > 0) ? ('¥' + formatNum(l.subtotal, 2)) : '—';
            var ppn = l.platform_part_no || '—';
            var subName = l.model || l.product_name || '';
            var pname = l.pname || '—';
            var cats = l.cat_names || '';

            html += '<tr' + (isSmp ? ' class="as-row-sample"' : '') + '>';
            html += '<td class="as-td-pad as-td-c"><input type="checkbox" class="row-check" value="' + l.id + '" onchange="updateExportBtn()"></td>';
            html += '<td class="as-td-pad as-td-nowrap">' + escapeHtml(String(l.create_time).substr(0,16)) + '</td>';
            html += '<td class="as-td-pad"><div class="as-ppn">' + escapeHtml(ppn) + '</div><div class="as-ppn-sub">' + escapeHtml(subName) + '</div></td>';
            html += '<td class="as-td-pad as-td-xs">' + escapeHtml(cats) + '</td>';
            html += '<td class="as-td-pad">' + escapeHtml(pname) + '</td>';
            html += '<td class="as-td-pad"><span class="as-tag ' + tagColorClass(col) + '">' + escapeHtml(lab) + '</span>';
            if(isSmp) html += '<span class="as-tag-star" title="样品不计资产">★</span>';
            html += '</td>';
            html += '<td class="as-td-pad as-td-r as-mono as-qty ' + (isUp ? 'is-up' : 'is-down') + '">' + qtySign + escapeHtml(String(l.qty_change)) + '</td>';
            html += '<td class="as-td-pad as-td-r as-mono">' + unitCost + '</td>';
            html += '<td class="as-td-pad as-td-r as-mono as-subtotal">' + subtotal + '</td>';
            html += '</tr>';
        });
    }
    html += '</tbody></table></div>';

    // 分页
    html += '<div class="pagination">';
    html += '<span class="page-jump">第 <input type="number" min="1" max="' + totalPages + '" placeholder="页码" onkeydown="assetPageJump(event,' + totalPages + ')"> 页</span>';
    if(totalPages > 1){
        html += '<a href="javascript:void(0)" onclick="loadLogs(' + Math.max(1,page-1) + ')" class="page-btn' + (page<=1?' disabled':'') + '">‹</a>';
        var s = Math.max(1, page-2), e = Math.min(totalPages, page+2);
        if(s > 1) html += '<a href="javascript:void(0)" onclick="loadLogs(1)" class="page-btn">1</a>';
        if(s > 2) html += '<span class="page-info">…</span>';
        for(var i=s; i<=e; i++){
            html += '<a href="javascript:void(0)" onclick="loadLogs(' + i + ')" class="page-btn' + (i===page?' active':'') + '">' + i + '</a>';
        }
        if(e < totalPages-1) html += '<span class="page-info">…</span>';
        if(e < totalPages) html += '<a href="javascript:void(0)" onclick="loadLogs(' + totalPages + ')" class="page-btn">' + totalPages + '</a>';
        html += '<a href="javascript:void(0)" onclick="loadLogs(' + Math.min(totalPages,page+1) + ')" class="page-btn' + (page>=totalPages?' disabled':'') + '">›</a>';
    }
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    html += '<select onchange="changeAssetPerPage()" class="per-page-select">';
    [15,30,50,100].forEach(function(pp){
        html += '<option value="' + pp + '"' + (pp===perPage?' selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    html += '</div>';

    var wrap = document.getElementById('logsTableWrap');
    if(wrap) wrap.innerHTML = html;
    updateExportBtn();
}
/* 操作类型标签色 → 语义 class。 */
function tagColorClass(hex){
    var map = {
        '#4f8ef7': 'is-blue',
        '#22c55e': 'is-green',
        '#ef4444': 'is-red',
        '#f59e0b': 'is-amber',
        '#8b5cf6': 'is-purple'
    };
    return map[String(hex || '').toLowerCase()] || '';
}
function escapeHtml(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}
function formatNum(v, dec){
    var n = Number(v);
    if(isNaN(n)) return '0';
    var parts = n.toFixed(dec).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');
}
function assetPageJump(e, totalPages){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if(raw === '') return;
    var p = parseInt(raw, 10);
    if(isNaN(p) || p < 1){ e.target.value = ''; LCSC.toast('请输入有效页码', 'warning'); return; }
    if(p > totalPages) p = totalPages;
    e.target.value = p;
    loadLogs(p);
}
function changeAssetPerPage(){
    loadLogs(1);
}
function toggleAll(master){
    document.querySelectorAll('.row-check').forEach(function(cb){ cb.checked = master.checked; });
    updateExportBtn();
}
function updateExportBtn(){
    var checked = document.querySelectorAll('.row-check:checked');
    var btn = document.getElementById('exportBtn');
    if(checked.length > 0){
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.textContent = '导出 Excel（'+checked.length+'条）';
    } else {
        btn.disabled = true;
        btn.style.opacity = '.5';
        btn.style.cursor = 'not-allowed';
        btn.textContent = '导出 Excel';
        var master = document.getElementById('selectAll');
        if(master) master.checked = false;
    }
}
function exportSelected(){
    var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb){ return cb.value; });
    if(ids.length === 0) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api.php';
    var actInput = document.createElement('input');
    actInput.type = 'hidden';
    actInput.name = 'action';
    actInput.value = 'export.assets';
    form.appendChild(actInput);
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'ids';
    input.value = ids.join(',');
    form.appendChild(input);
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_csrf';
    csrfInput.value = window.AssetsPageConfig.csrf;
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
