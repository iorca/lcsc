// modules/scan/Assets/js/scan-core.js — 扫码出入库页（?v=scan）· 公共基础

var audioCtx = null;
function getAudioCtx() {
    if (!audioCtx) {
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
        catch(e) { return null; }
    }
    return audioCtx;
}
function playBeep(freq, duration, type) {
    if (!document.getElementById('soundEnabled').checked) return;
    try {
        var ctx = getAudioCtx();
        if (!ctx) return;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type || 'sine';
        osc.frequency.value = freq || 880;
        var dur = duration || 0.1;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + dur);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + dur);
    } catch(e) {}
}
function playSuccessSound() { playBeep(880, 0.15, 'sine'); setTimeout(function(){ playBeep(1100, 0.2, 'sine'); }, 100); }
function playErrorSound()   { playBeep(200, 0.3, 'square'); }

var _voiceActivated = false;
function activateVoice() {
    if (_voiceActivated) return;
    if (!('speechSynthesis' in window)) return;
    try {
        var utter = new SpeechSynthesisUtterance(' ');
        utter.volume = 0;
        utter.lang = 'zh-CN';
        utter.rate = 1;
        window.speechSynthesis.speak(utter);
        _voiceActivated = true;
        try { localStorage.setItem('scan_voice_activated', '1'); } catch(e) {}
    } catch(e) {}
    var btn = document.getElementById('voiceActivateBtn');
    if (btn) btn.classList.remove('is-on');
}
function checkVoicePermission() {
    if (!('speechSynthesis' in window)) return;
    var isMobile = window.innerWidth <= 768;
    if (!isMobile) { _voiceActivated = true; return; }
    // 移动端：勾选语音播报时，直接同步激活（在 change 事件的用户手势上下文中）
    var cb = document.getElementById('voiceEnabled');
    if (cb && cb.checked && !_voiceActivated) {
        activateVoice();
    }
    var btn = document.getElementById('voiceActivateBtn');
    if (btn) {
        // 激活失败时仍显示按钮作为兜底（用户可手动点击）
        btn.classList.toggle('is-on', !_voiceActivated && !!(cb && cb.checked));
    }
}
// 语音播报checkbox变化时同步激活权限（必须在用户手势上下文中）
document.addEventListener('DOMContentLoaded', function() {
    try {
        if (localStorage.getItem('scan_voice_activated') === '1') _voiceActivated = true;
    } catch(e) {}
    var cb = document.getElementById('voiceEnabled');
    if (cb) cb.addEventListener('change', checkVoicePermission);
});

function speakResult(data) {
    if (!document.getElementById('voiceEnabled').checked) return;
    if (!data || !data.ok) return;
    if (!('speechSynthesis' in window)) return;
    // 移动端隐藏了语音播报选项，此处兜底防止异常触发
    if (window.innerWidth <= 768) return;
    if (!_voiceActivated) {
        // 移动端未激活，显示激活按钮供用户手动点击
        var btn = document.getElementById('voiceActivateBtn');
        if (btn) btn.classList.add('is-on');
        return;
    }
    try {
        var isIn = data.type === 'scan_in';
        var action = isIn ? '入库' : '出库';
        var qty = data.qty || 0;
        var text = action + qty + '件';
        var utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'zh-CN';
        utter.rate = 1.2;
        utter.volume = 1;
        window.speechSynthesis.cancel();
        window.speechSynthesis.resume();
        window.speechSynthesis.speak(utter);
    } catch(e) {}
}

function vibrate(pattern) {
    if (navigator.vibrate) { try { navigator.vibrate(pattern); } catch(e) {} }
}

// 更新扫码状态显示区（只读展示解析结果）
function updateScanStatus(scanAction, model, qty) {
    var display = document.getElementById('scanStatusDisplay');
    var typeEl  = document.getElementById('statusType');
    var modelEl = document.getElementById('statusModel');
    var qtyEl   = document.getElementById('statusQty');
    if (!display || !typeEl || !modelEl || !qtyEl) return;
    var isIn = scanAction === 'scan_in';
    typeEl.textContent = isIn ? '📥 入库' : '📤 出库';
    typeEl.classList.toggle('is-in', isIn);
    typeEl.classList.toggle('is-out', !isIn);
    modelEl.textContent = model || '-';
    qtyEl.textContent = qty || 1;
    qtyEl.classList.toggle('is-in', isIn);
    qtyEl.classList.toggle('is-out', !isIn);
    display.classList.add('is-on');
}

// 重置扫码表单隐藏字段（每次扫码完成后调用）
function resetScanFormFields() {
    document.getElementById('scanInternalId').value = '0';
    document.getElementById('scanPlatformCode').value = '';
    document.getElementById('scanQty').value = '1';
    document.getElementById('scanModel').value = '';
    document.getElementById('scanOrderNo').value = '';
    document.getElementById('scanSource').value = '';
    document.getElementById('scanAction').value = 'stock.scan_out';
    document.getElementById('scanUnitCost').value = '0';
    document.getElementById('barcodeInput').value = '';
    setScanFields(null);
    setScanBarcode('');
    lastResolvedPrice = 0;
    var display = document.getElementById('scanStatusDisplay');
    if (display) display.classList.remove('is-on');
}

function prependScanRecord(data) {
    var listEl = document.getElementById('recentScanList');
    if (!listEl) return;
    // 如果当前显示「暂无扫描记录」，清空占位
    var empty = listEl.querySelector('.empty-state');
    if (empty) empty.remove();

    var tbody = listEl.querySelector('#recentScanTable tbody');
    if (!tbody) {
        // 表格不存在（首次记录），需要创建完整表格结构。
        // **必须带 `class="data-table"`** —— 与首屏 `Views/index.php` 的那张表保持一致。
        listEl.innerHTML = '<div class="table-wrap sd-recent-wrap">' +
            '<table class="data-table" id="recentScanTable"><thead><tr>' +
            '<th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>' +
            '<th class="sd-th-r">数量</th><th>备注</th><th class="sd-th-ops">操作</th>' +
            '</tr></thead><tbody></tbody></table></div>';
        tbody = listEl.querySelector('#recentScanTable tbody');
    }

    var isIn = data.type === 'scan_in';
    var typeBadge = isIn ? '<span class="badge badge-green">入库</span>' : '<span class="badge badge-red">出库</span>';
    var qtyCls = isIn ? 'is-in' : 'is-out';
    var undoCell = data.scan_log_id
        ? '<button type="button" class="btn btn-ghost btn-xs sd-undo" onclick="undoScan(' + (data.scan_log_id) + ', this)" title="撤销此记录">↩</button>'
        : '<span class="sd-dash">—</span>';
    var tr = document.createElement('tr');
    tr.className = 'scan-row';
    tr.dataset.page = '1';
    tr.innerHTML =
        '<td class="sd-td-time">' + escapeHtml(data.created_at || '') + '</td>' +
        '<td class="sd-td-ppn">' + escapeHtml(data.part_no || '') + '</td>' +
        '<td class="sd-td-model">' + escapeHtml(data.model || '-') + '</td>' +
        '<td>' + typeBadge + '</td>' +
        '<td class="sd-td-qty ' + qtyCls + '">' + (data.qty || 0) + '</td>' +
        '<td class="sd-td-remark">' + escapeHtml(data.remark || '') + '</td>' +
        '<td class="sd-td-c">' + undoCell + '</td>';
    // 插入到 tbody 顶部
    if (tbody.firstChild) {
        tbody.insertBefore(tr, tbody.firstChild);
    } else {
        tbody.appendChild(tr);
    }
    // 限制记录数为30条
    var rows = tbody.querySelectorAll('tr.scan-row');
    if (rows.length > 30) {
        rows[rows.length - 1].remove();
    }
    // 重新计算分页
    rebuildScanPagination(tbody);
    // 今日统计卡同步累加
    bumpTodayStats(data);
    // 高亮新行（淡入效果）
    setTimeout(function(){ tr.classList.add('scan-row-highlight'); }, 10);
    setTimeout(function(){ tr.classList.remove('scan-row-highlight'); }, 2000);
}

/* 累加今日出入库统计卡 */
function bumpTodayStats(data) {
    var isIn = data.type === 'scan_in';
    var qty = parseInt(data.qty, 10) || 0;
    var cntIds = isIn ? ['stTodayIn', 'stTodayInQty'] : ['stTodayOut', 'stTodayOutQty'];
    var qtyIds = isIn ? ['stTodayInQty'] : ['stTodayOutQty'];

    // 笔数：仅扫码（写 scan_log）才增
    if (data.scan_log_id) bumpNum(cntIds[0], 1);
    bumpNum(qtyIds[0], qty);
}

/* 数字元素自增并做千分位格式化 */
function bumpNum(id, delta) {
    var el = document.getElementById(id);
    if (!el || !delta) return;
    var cur = parseInt(String(el.textContent).replace(/,/g, ''), 10);
    if (isNaN(cur)) cur = 0;
    var next = cur + delta;
    el.textContent = (id.indexOf('Qty') > 0) ? number_format(next) : String(next);
}

/* 千分位格式化（与后端 number_format 一致） */
function number_format(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// 重建分页控件
function rebuildScanPagination(tbody) {
    var rows = tbody.querySelectorAll('tr.scan-row');
    var total = rows.length;
    var perPage = 10;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    // 重新分配 data-page
    rows.forEach(function(row, i) {
        row.dataset.page = Math.floor(i / perPage) + 1;
    });
    // 显示第一页
    goToScanPage(1);
    // 更新分页控件
    var pagination = document.getElementById('scanPagination');
    if (!pagination) return;
    // 重建分页按钮
    var prevBtn = pagination.querySelector('#scanPrevBtn');
    var nextBtn = pagination.querySelector('#scanNextBtn');
    var info = pagination.querySelector('.page-info');
    // 移除旧页码按钮
    pagination.querySelectorAll('[data-scan-page]').forEach(function(el){ el.remove(); });
    // 插入新页码按钮
    for (var p = 1; p <= totalPages; p++) {
        var a = document.createElement('a');
        a.className = 'page-btn' + (p === 1 ? ' active' : '');
        a.setAttribute('data-scan-page', p);
        a.textContent = p;
        a.onclick = function(){ goToScanPage(parseInt(this.textContent)); };
        if (nextBtn) {
            pagination.insertBefore(a, nextBtn);
        } else {
            pagination.appendChild(a);
        }
    }
    // 更新总数
    if (info) info.textContent = '共 ' + total + ' 条';
    // 更新页码直达输入框的 max 属性
    var jumpInput = pagination.querySelector('.page-jump input');
    if (jumpInput) jumpInput.setAttribute('max', totalPages);
    // 隐藏分页如果只有1页
    pagination.style.display = totalPages > 1 ? '' : 'none';
}

// 撤销指定扫码记录（从最近扫描记录列表调用）
function undoScan(scanLogId, btn) {
    if (!scanLogId || scanLogId <= 0) {
        showToast('无效的记录', 'warning');
        return;
    }
    AdminUIConfirm({
        title: '撤销扫码记录',
        message: '确认撤销此条扫码记录？库存将回滚。',
        okText: '确认撤销',
        onOk: function(){ doUndoScan(scanLogId, btn); }
    });
}
function doUndoScan(scanLogId, btn) {
    if (btn) { btn.disabled = true; btn.textContent = '撤销中...'; }
    var fd = new FormData();
    fd.append('action', 'stock.scan_undo');
    fd.append('scan_log_id', scanLogId);
    fd.append('ajax', '1');
    fd.append('_csrf', window.ScanPageConfig.csrfToken);
    fetch('api.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.ok) {
            if (btn) { btn.disabled = true; btn.textContent = '✓ 已撤销'; }
            showToast('已撤销: ' + (data.part_no || ''), 'success');
            playSuccessSound();
            vibrate(30);
            // 撤销操作改变库存，清除全部扫码缓存以确保数据一致
            invalidateScanCache();
            // 会话条计数同步回退（若该条属于本次会话）
            scanSessionDrop(scanLogId);
            // 动态移除被撤销的行（无需刷新页面）
            var tr = btn ? btn.closest('tr') : null;
            if (tr) {
                tr.style.transition = 'opacity .3s';
                tr.style.opacity = '0.3';
                setTimeout(function(){
                    tr.remove();
                    var tbody = document.querySelector('#recentScanTable tbody');
                    if (tbody) {
                        var remaining = tbody.querySelectorAll('tr.scan-row');
                        if (remaining.length === 0) {
                            // 无记录时显示空状态
                            var listEl = document.getElementById('recentScanList');
                            if (listEl) listEl.innerHTML = '<div class="empty-state sd-empty">暂无扫描记录</div>';
                        } else {
                            rebuildScanPagination(tbody);
                        }
                    }
                }, 300);
            }
        } else {
            if (btn) { btn.disabled = false; btn.textContent = '↩'; }
            showToast('撤销失败: ' + (data.error || '未知错误'), 'error');
            playErrorSound();
        }
    })
    .catch(function(err){
        if (btn) { btn.disabled = false; btn.textContent = '↩'; }
        showToast('网络错误，请重试', 'error');
    });
}

var scanSession = { entries: [] };

function scanSessionAdd(data) {
    if (!data || !data.ok) return;
    scanSession.entries.push({
        logId: parseInt(data.scan_log_id, 10) || 0,
        type: data.type,
        qty: parseInt(data.qty, 10) || 0
    });
    renderScanSession();
}

/* 按 scan_log_id 摘除一条（撤销成功后调用）；不在本次会话内则不动 */
function scanSessionDrop(logId) {
    var id = parseInt(logId, 10) || 0;
    if (!id) return;
    for (var i = 0; i < scanSession.entries.length; i++) {
        if (scanSession.entries[i].logId === id) {
            scanSession.entries.splice(i, 1);
            renderScanSession();
            return;
        }
    }
}

/* 重算合计并刷新会话条（数量按剩余条目求和，撤销后自然回退） */
function renderScanSession() {
    var bar = document.getElementById('scanSessionBar');
    if (!bar) return;
    // 开关关闭时不显示会话条（保留条目无意义，由开关 change 处清空）
    if (!isContinuous()) { bar.classList.remove('is-on'); return; }
    bar.classList.add('is-on');
    var cnt = 0, inQty = 0, outQty = 0, lastLogId = 0;
    for (var i = 0; i < scanSession.entries.length; i++) {
        var e = scanSession.entries[i];
        cnt++;
        if (e.type === 'scan_in') { inQty += e.qty; } else { outQty += e.qty; }
        if (e.logId) lastLogId = e.logId;
    }
    var cEl = document.getElementById('ssbCount');
    var iEl = document.getElementById('ssbInQty');
    var oEl = document.getElementById('ssbOutQty');
    if (cEl) cEl.textContent = String(cnt);
    if (iEl) iEl.textContent = String(inQty);
    if (oEl) oEl.textContent = String(outQty);
    var btn = document.getElementById('ssbUndoBtn');
    if (btn) btn.disabled = !lastLogId;
}

/* 撤销会话里最近一条已落库记录（复用既有 undoScan 的二次确认） */
function undoLastSessionScan() {
    var lastLogId = 0;
    for (var i = 0; i < scanSession.entries.length; i++) {
        if (scanSession.entries[i].logId) lastLogId = scanSession.entries[i].logId;
    }
    if (!lastLogId) { showToast('本次会话暂无可撤销的记录', 'warning'); return; }
    undoScan(lastLogId, null);
}

/* 结束本次会话：清空累计**并退出连续扫码**。 */
function endScanSession() {
    var n = scanSession.entries.length;
    scanSession.entries = [];
    var box = document.getElementById('continuousMode');
    if (box && box.checked) {
        box.checked = false;
        setContinuous(false);
        syncSkipCostControl();
    }
    renderScanSession();
    showToast(n > 0 ? ('本次会话结束，共 ' + n + ' 条已记账') : '本次会话已结束', 'info');
}

var toastTimer = null;
function showToast(msg, type) {
    var existing = document.querySelector('.scan-toast');
    if (existing) existing.remove();
    if (toastTimer) clearTimeout(toastTimer);
    var el = document.createElement('div');
    el.className = 'scan-toast ' + (type || 'success');
    el.textContent = msg;
    document.body.appendChild(el);
    toastTimer = setTimeout(function(){
        el.classList.add('fadeout');
        setTimeout(function(){ el.remove(); }, 500);
    }, 2000);
}

function toggleScanHelp() {
    var body = document.getElementById('sdHelpBody');
    var box  = document.getElementById('sdHelp');
    var btn  = document.getElementById('sdHelpToggle');
    if (!body) return;
    var open = body.classList.toggle('is-on');
    if (box) box.classList.toggle('is-open', open);
    if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
