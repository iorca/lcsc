// modules/scan/Assets/js/scan-submit.js — 扫码出入库页 · 提交与结果

function doScan() {
    var barcode = getScanBarcode();
    var internalId = parseInt(document.getElementById('scanInternalId').value, 10) || 0;
    if (barcode === '' && internalId <= 0) return;

    // 全局扫描锁：防止 AJAX 期间重复提交
    if (scanInProgress) return;

    // 防重复扫描
    var orderNo = document.getElementById('scanOrderNo').value;
    var dupResult = checkDuplicate(barcode, orderNo, internalId);
    if (dupResult === 'rapid') {
        // 快速重复（抖动）：静默忽略，不弹窗
        return;
    }
    if (dupResult === 'cooldown') {
        // 冷却期内重复：温和提示，不阻塞后续扫描
        showToast('5秒内请勿重复扫码，请稍后再试', 'warning');
        return;
    }

    // 「不计成本」随表单提交：仅在入库时置 1（出库不读该参数），保证服务端能放行
    var skipEl = document.getElementById('scanSkipCost');
    if (skipEl) {
        skipEl.value = (isSkipCost() && document.getElementById('scanAction').value === 'stock.scan_in') ? '1' : '0';
    }

    // ── 扫码入库版本分支 ──
    if (needsUnitPrice()) {
        // 团队版：扫码入库弹出单价输入框，不自动入库
        var scanData = collectScanData();
        showUnitPriceDialog(scanData, function(unitPrice) {
            submitScanIn(scanData, unitPrice);
        });
        return;
    }

    // 个人版/出库：直接提交
    submitScanDirect();
}

// ── 直接提交扫码（个人版入库/所有出库）──
function submitScanDirect() {
    scanInProgress = true;
    var form = document.getElementById('scanForm');
    var formData = new FormData(form);

    // ── 扫码缓存：命中时即时反馈，不等AJAX ──
    var cacheKey = getScanCacheKey();
    var cached = getCachedPart(cacheKey);
    var scanQty = parseInt(document.getElementById('scanQty').value, 10) || 1;
    var isIn = document.getElementById('scanAction').value === 'stock.scan_in';

    if (cached) {
        // 缓存命中：立即显示预测结果（即时反馈，体验如专业扫码设备）
        var predicted = {};
        for (var k in cached) { if (cached.hasOwnProperty(k)) predicted[k] = cached[k]; }
        predicted.qty = scanQty;
        predicted.qty_before = cached.qty_after;
        predicted.qty_after = isIn ? cached.qty_after + scanQty : Math.max(0, cached.qty_after - scanQty);
        playSuccessSound();
        speakResult(predicted);
        vibrate(30);
        if (!isContinuous()) showScanResultModal(predicted);
    }

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        scanInProgress = false;
        if (data.ok) {
            // 更新缓存
            setCachedPart(cacheKey, data);
            if (!cached) {
                // 无缓存时正常反馈
                playSuccessSound();
                speakResult(data);
                vibrate(30);
            }
            prependScanRecord(data);
            afterScanFeedback(data);
        } else {
            // 失败时清除该缓存
            invalidateScanCache(cacheKey);
            playErrorSound();
            vibrate([100, 50, 100]);
            showScanResultModal(data);
        }
    })
    .catch(function(err){
        scanInProgress = false;
        playErrorSound();
        vibrate([100, 50, 100]);
        showScanResultModal({ ok: false, error: '网络错误，请重试' });
    });
}

// ── 团队版扫码入库提交（携带单价）──
function submitScanIn(scanData, unitPrice) {
    scanInProgress = true;
    var fd = new FormData();
    fd.append('_csrf', window.ScanPageConfig.csrfToken);
    fd.append('action', 'stock.scan_in');
    fd.append('ajax', '1');
    fd.append('barcode', scanData.barcode || '');
    fd.append('internal_id', scanData.internal_id || 0);
    fd.append('platform_code', scanData.platform_code || '');
    fd.append('qty', scanData.qty || 1);
    fd.append('order_no', scanData.order_no || '');
    fd.append('scan_source', scanData.scan_source || '');
    fd.append('model', scanData.model || '');
    fd.append('fields', scanData.fields || '');
    fd.append('unit_cost', unitPrice);
    fd.append('skip_cost', isSkipCost() ? '1' : '0');

    // ── 扫码缓存：命中时即时反馈 ──
    var cacheKey = getScanCacheKeyFromData(scanData);
    var cached = getCachedPart(cacheKey);
    var scanQty = parseInt(scanData.qty, 10) || 1;

    if (cached) {
        var predicted = {};
        for (var k in cached) { if (cached.hasOwnProperty(k)) predicted[k] = cached[k]; }
        predicted.qty = scanQty;
        predicted.qty_before = cached.qty_after;
        predicted.qty_after = cached.qty_after + scanQty;
        playSuccessSound();
        speakResult(predicted);
        vibrate(30);
        if (!isContinuous()) showScanResultModal(predicted);
    }

    fetch('api.php', {
        method: 'POST',
        body: fd
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        scanInProgress = false;
        if (data.ok) {
            setCachedPart(cacheKey, data);
            if (!cached) {
                playSuccessSound();
                speakResult(data);
                vibrate(30);
            }
            prependScanRecord(data);
            afterScanFeedback(data);
        } else {
            invalidateScanCache(cacheKey);
            playErrorSound();
            vibrate([100, 50, 100]);
            showScanResultModal(data);
        }
    })
    .catch(function(err){
        scanInProgress = false;
        playErrorSound();
        vibrate([100, 50, 100]);
        showScanResultModal({ ok: false, error: '网络错误，请重试' });
    });
}

// ── 团队版扫码入库单价弹窗 ──
function showUnitPriceDialog(data, callback) {
    var historyPrice = parseFloat(data.last_purchase_price) || 0;
    var preset = lastUnitPrice || (historyPrice > 0 ? String(historyPrice) : '');

    var overlay = document.createElement('div');
    overlay.id = 'scanPriceOverlay';
    overlay.className = 'modal-overlay';
    overlay.innerHTML =
        '<div class="modal confirm-card">'
        + '<div class="modal-header"><h3 class="sd-modal-title">扫码入库 — ' + escapeHtml(data.model || data.barcode || '') + '</h3></div>'
        + '<div class="modal-body sd-modal-body">'
        + '<div class="confirm-fields">'
        + '<div class="confirm-field"><span class="confirm-field-label">数量</span>'
        + '<input type="text" value="' + escapeHtml(String(data.qty || 1)) + '" readonly></div>'
        + '<div class="confirm-field"><span class="confirm-field-label">单价（必填）</span>'
        + '<input type="number" id="scanUnitPrice" step="0.0001" min="0.0001" inputmode="decimal" placeholder="请输入含税单价" value="' + preset + '">'
        + '<span class="confirm-hint sd-hint-left">'
        + (historyPrice > 0 ? '历史采购价 ¥' + historyPrice.toFixed(4) : '')
        + (historyPrice > 0 && lastUnitPrice ? ' · ' : '')
        + (lastUnitPrice ? '回车沿用上次 ¥' + lastUnitPrice : '')
        + '</span>'
        + '</div>'
        + '</div></div>'
        + '<div class="modal-footer sd-modal-footer">'
        + '<button type="button" class="btn btn-ghost" id="scanPriceCancel">取消</button>'
        + '<button type="button" class="btn btn-primary" id="scanConfirmBtn">确认入库</button>'
        + '</div></div>';
    document.body.appendChild(overlay);

    var priceInput = document.getElementById('scanUnitPrice');
    var confirmBtn = document.getElementById('scanConfirmBtn');

    function syncBtnState() {
        var ok = parseFloat(priceInput.value) > 0;
        confirmBtn.disabled = !ok;
        confirmBtn.style.opacity = ok ? '1' : '.5';
        confirmBtn.style.pointerEvents = ok ? 'auto' : 'none';
    }
    priceInput.addEventListener('input', syncBtnState);
    syncBtnState();

    // 回车即确认（单价沿用时的快速流）
    priceInput.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (parseFloat(priceInput.value) <= 0) return;
        confirmScanIn();
    });
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeScanDialog();
    });

    document.getElementById('scanPriceCancel').addEventListener('click', closeScanDialog);
    confirmBtn.addEventListener('click', confirmScanIn);

    priceInput.focus();
    priceInput.select();

    window._scanConfirmCallback = callback;
    window._scanData = data;
}

function confirmScanIn() {
    var input = document.getElementById('scanUnitPrice');
    if (!input) return;
    var price = parseFloat(input.value);
    if (price <= 0) return;
    // 记住本页上次单价，供下一条预填
    lastUnitPrice = String(price);
    // 先取回调再关弹窗：closeScanDialog() 会把全局回调置 null
    var cb = window._scanConfirmCallback;
    closeScanDialog();
    if (cb) cb(price);
}

function closeScanDialog() {
    var overlay = document.getElementById('scanPriceOverlay');
    if (overlay) overlay.remove();
    window._scanConfirmCallback = null;
    window._scanData = null;
    resumeCameraScanning();
}

/* 扫码完成后的反馈分派 */
function afterScanFeedback(data) {
    if (!data || !data.ok) { showScanResultModal(data); return; }
    if (isContinuous()) {
        scanSessionAdd(data);
        showToast((data.type === 'scan_in' ? '入库 +' : '出库 -') + (parseInt(data.qty, 10) || 0) + ' · ' + (data.part_no || data.model || ''), 'success');
        var input = document.getElementById('barcodeInput');
        if (input && !cameraActive) input.focus();
        resumeCameraScanning();
        return;
    }
    showScanResultModal(data);
}

// ── 扫码结果弹窗 ──
function showScanResultModal(data) {
    var modal = document.getElementById('scanResultModal');
    var title = document.getElementById('scanResultTitle');
    var body = document.getElementById('scanResultBody');

    if (data.ok) {
        var isIn = data.type === 'scan_in';
        var typeLabel = isIn ? '📥 扫码入库成功' : '📤 扫码出库成功';
        var qtyLabel = isIn ? '+' + data.qty : '-' + data.qty;
        var qtyColor = isIn ? 'var(--green)' : 'var(--red)';
        var qtyCls = isIn ? 'is-in' : 'is-out';
        title.textContent = typeLabel;
        title.style.color = qtyColor;
        body.innerHTML =
            '<table class="info-table sd-res-table">' +
            '<tr><td class="sd-res-td">商品编号</td><td class="sd-res-td sd-res-ppn">' + escapeHtml(data.part_no || '') + '</td></tr>' +
            '<tr><td class="sd-res-td">型号</td><td class="sd-res-td sd-res-mono">' + escapeHtml(data.model || '-') + '</td></tr>' +
            '<tr><td class="sd-res-td">数量变化</td><td class="sd-res-td sd-res-mono sd-res-qty ' + qtyCls + '">' + qtyLabel + '</td></tr>' +
            '<tr><td class="sd-res-td">变化前</td><td class="sd-res-td sd-res-mono">' + (data.qty_before !== undefined ? escapeHtml(String(data.qty_before)) : '-') + '</td></tr>' +
            '<tr><td class="sd-res-td">变化后</td><td class="sd-res-td sd-res-mono sd-res-after">' + (data.qty_after !== undefined ? escapeHtml(String(data.qty_after)) : '-') + '</td></tr>' +
            '</table>';
    } else {
        title.textContent = '⚠ 扫码失败';
        title.style.color = 'var(--red)';
        body.innerHTML = '<div class="sd-res-err">' + escapeHtml(data.error || '未知错误') + '</div>';
    }
    modal.classList.add('is-on');
    // 自动关闭：成功2秒，失败3秒
    var delay = data.ok ? 2000 : 3000;
    clearTimeout(window._scanModalTimer);
    window._scanModalTimer = setTimeout(closeScanResultModal, delay);
}

function closeScanResultModal() {
    clearTimeout(window._scanModalTimer);
    document.getElementById('scanResultModal').classList.remove('is-on');
    // 清空输入框，准备下一次扫描
    var input = document.getElementById('barcodeInput');
    input.value = '';
    resetScanFormFields();
    // 回焦输入框：扫码枪连续录入的前提（摄像头开启时让位给相机）
    if (!cameraActive && input) input.focus();
    // 恢复摄像头扫描
    resumeCameraScanning();
}
