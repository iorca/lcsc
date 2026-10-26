// modules/scan/Assets/js/scan-intent.js — 扫码出入库页 · 扫描意图中枢

function setScanType(type) {
    document.getElementById('scanAction').value = (type === 'scan_in' ? 'stock.scan_in' : 'stock.scan_out');
}


var CONFIRM_KEY = 'scan_confirm_in';
function isConfirmEnabled() {
    try {
        var v = localStorage.getItem(CONFIRM_KEY);
        return v === null ? true : v === '1';
    } catch(e) { return true; }
}
function setConfirmEnabled(on) {
    try { localStorage.setItem(CONFIRM_KEY, on ? '1' : '0'); } catch(e) {}
}

/* 团队版单价「回车沿用上次」：仅本次页面会话有效，不落存储 */
var lastUnitPrice = '';

// ── 连续扫码 / 入库不计成本（勾选态记忆，与 CONFIRM_KEY 同模式）──
/* 连续扫码：默认关闭 —— 不改变既有「扫一条、看一眼」的体验，由用户主动开启 */
var CONTINUOUS_KEY = 'scan_continuous';
function isContinuous() {
    try { return localStorage.getItem(CONTINUOUS_KEY) === '1'; } catch(e) { return false; }
}
function setContinuous(on) {
    try { localStorage.setItem(CONTINUOUS_KEY, on ? '1' : '0'); } catch(e) {}
}
/* 入库不计成本：仅团队版渲染该控件；默认关闭 */
var SKIP_COST_KEY = 'scan_skip_cost';
function isSkipCost() {
    try { return localStorage.getItem(SKIP_COST_KEY) === '1'; } catch(e) { return false; }
}
function setSkipCost(on) {
    try { localStorage.setItem(SKIP_COST_KEY, on ? '1' : '0'); } catch(e) {}
}
/* 「不计成本」仅在连续扫码开启时可用 —— 单扫场景仍应逐条确认成本 */
function syncSkipCostControl() {
    var box = document.getElementById('skipCostMode');
    if (!box) return;
    var on = isContinuous();
    box.disabled = !on;
    var label = document.getElementById('skipCostLabel');
    if (label) label.classList.toggle('is-off', !on);
    if (!on && box.checked) { box.checked = false; setSkipCost(false); }
}

/* 扫描意图统一处理入口 */
function handleScanIntent(rawText, source) {
    var aliases = (window.ScanPageConfig && window.ScanPageConfig.qrAliases) || {};
    var intent = ScanDecoder.toIntent(rawText, aliases);
    if (intent.kind === 'empty') return;

    // ── 自带操作类型与数量：可直接落库 ──
    if (intent.executable) {
        applyIntentToForm(intent);
        if (intent.scanType) {
            setScanType(intent.scanType === 'out' ? 'scan_out' : 'scan_in');
        } else {
            // 立创采购码强制入库
            setScanType('scan_in');
        }
        document.getElementById('scanSource').value = intent.decoded.type;
        // 输入框仅作视觉反馈：内部标签显示 P00001，立创码显示商品编号
        document.getElementById('barcodeInput').value = intent.partNo || LCSC.partCode(intent.internalId);
        updateScanStatus(document.getElementById('scanAction').value, intent.model, intent.qty);

        // 入库 + 需要成本录入：仍走单价弹窗（成本不可省）
        if (needsUnitPrice()) {
            var d = collectScanData();
            showUnitPriceDialog(d, function(price) { submitScanIn(d, price); });
            return;
        }
        doScan();
        return;
    }

    // ── 仅编号 / 型号线索：先预检 ──
    resolveIntent(intent, source);
}

function needsUnitPrice() {
    return document.getElementById('scanAction').value === 'stock.scan_in'
        && typeof _featureMap !== 'undefined' && _featureMap.cost_view
        && !isSkipCost();
}

/* 把意图写入表单隐藏字段 */
function applyIntentToForm(intent) {
    document.getElementById('scanInternalId').value = intent.internalId || 0;
    document.getElementById('scanPlatformCode').value = intent.platformCode || '';
    document.getElementById('scanQty').value = intent.qty || 1;
    document.getElementById('scanModel').value = intent.model || '';
    document.getElementById('scanOrderNo').value = intent.orderNo || '';
    setScanFields(intent.fields);
    setScanBarcode(intent.partNo || '');
}

/* 平台二维码原始键值（唯一数据源：表单内隐藏域 scanFields） */
function setScanFields(fields) {
    var el = document.getElementById('scanFields');
    if (!el) return;
    el.value = fields ? JSON.stringify(fields) : '';
}
function getScanFields() {
    var el = document.getElementById('scanFields');
    return (el && el.value) ? el.value : '';
}

/* 条码唯一数据源：表单内隐藏域 scanBarcode */
function setScanBarcode(v) {
    document.getElementById('scanBarcode').value = v || '';
}
function getScanBarcode() {
    return document.getElementById('scanBarcode').value.trim();
}

/* 收集当前表单为提交对象 */
function collectScanData() {
    var d = {
        barcode: getScanBarcode(),
        internal_id: document.getElementById('scanInternalId').value,
        model: document.getElementById('scanModel').value,
        qty: document.getElementById('scanQty').value,
        order_no: document.getElementById('scanOrderNo').value,
        platform_code: document.getElementById('scanPlatformCode').value,
        fields: getScanFields(),
        scan_source: document.getElementById('scanSource').value
    };
    if (lastResolvedPrice) d.last_purchase_price = lastResolvedPrice;
    return d;
}

var lastResolvedPrice = 0;

/* 预检：查物料是否存在 */
function resolveIntent(intent, source) {
    var fd = new FormData();
    fd.append('action', 'stock.scan_resolve');
    fd.append('_csrf', window.ScanPageConfig.csrfToken);
    fd.append('barcode', intent.partNo || '');
    fd.append('model', intent.model || '');
    fd.append('internal_id', intent.internalId || 0);
    fd.append('platform_code', intent.platformCode || '');
    if (intent.fields) fd.append('fields', JSON.stringify(intent.fields));

    LCSC.fetchJson('api.php', { method: 'POST', body: fd })
    .then(function(res) {
        // 扫码类接口返回扁平结构，无 code 字段
        var json = (res && typeof res === 'object') ? res : {};
        if (json.code !== undefined) {          // 走到了标准信封（如鉴权失败）
            LCSC.toast(json.msg || '预检失败', 'error');
            playErrorSound();
            return;
        }
        if (json.ok === false) {
            LCSC.toast(json.error || '预检失败', 'error');
            playErrorSound();
            return;
        }
        // 型号命中多条：让用户挑，不擅自取第一条
        if (json.ambiguous && json.candidates && json.candidates.length > 1) {
            showCandidateDialog(intent, json.candidates, source);
            return;
        }
        if (json.found) {
            onResolved(intent, json, source);
        } else {
            showCreatePartDialog(intent, source);
        }
    })
    .catch(function(e) {
        LCSC.toast('网络错误：' + e.message, 'error');
        playErrorSound();
    });
}
