// modules/scan/Assets/js/scan-dialog.js — 扫码出入库页 · 各类弹窗流程

function showCandidateDialog(intent, candidates, source) {
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'scanCandidateOverlay';

    var rows = candidates.map(function(c, i) {
        return '<button type="button" class="cand-item" data-idx="' + i + '">'
            + '<span class="cand-model">' + escapeHtml(c.model || '（无型号）') + '</span>'
            + '<span class="cand-meta">' + escapeHtml(c.part_no || '-')
            + (c.platform_name ? ' · ' + escapeHtml(c.platform_name) : '')
            + ' · 库存 ' + (c.stock || 0) + '</span>'
            + '</button>';
    }).join('');

    overlay.innerHTML =
        '<div class="modal confirm-card">'
        + '<div class="modal-header"><h3 class="sd-modal-title">找到多个相近物料</h3></div>'
        + '<div class="modal-body sd-modal-body">'
        + '<div class="create-reason">型号 <code>' + escapeHtml(intent.keyword || '') + '</code> 匹配到 ' + candidates.length + ' 条已有物料，请选择：</div>'
        + '<div class="cand-list">' + rows + '</div>'
        + '<button type="button" class="btn btn-ghost btn-sm cand-new sd-cand-new">都不对，另建为新物料</button>'
        + '</div>'
        + '<div class="modal-footer sd-modal-footer">'
        + '<button type="button" class="btn btn-ghost" id="candCancel">取消</button>'
        + '</div>'
        + '</div>';

    document.body.appendChild(overlay);

    overlay.querySelectorAll('.cand-item').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var info = candidates[parseInt(this.getAttribute('data-idx'), 10)];
            overlay.remove();
            onResolved(intent, info, source);
        });
    });
    overlay.querySelector('.cand-new').addEventListener('click', function() {
        overlay.remove();
        showCreatePartDialog(intent, source);
    });
    overlay.querySelector('#candCancel').addEventListener('click', function() { closeCandidateDialog(); });
    overlay.addEventListener('click', function(e) { if (e.target === this) closeCandidateDialog(); });
}

function closeCandidateDialog() {
    var el = document.getElementById('scanCandidateOverlay');
    if (el) el.remove();
    document.getElementById('barcodeInput').value = '';
    resumeCameraScanning();
}

/* 预检命中已有物料 */
function onResolved(intent, info, source) {
    // 沿用旧的写表单路径，保证 doScan/submitScanIn 的行为完全不变
    document.getElementById('scanInternalId').value = info.internal_id || 0;
    document.getElementById('scanModel').value = info.model || '';
    document.getElementById('scanQty').value = intent.qty || 1;
    document.getElementById('scanOrderNo').value = intent.orderNo || '';
    setScanBarcode(info.part_no || intent.keyword || '');
    document.getElementById('barcodeInput').value = info.part_no || intent.keyword || '';
    lastResolvedPrice = parseFloat(info.last_purchase_price) || 0;

    // 未指定方向时默认入库（个人用户主要场景）
    setScanType(intent.scanType === 'out' ? 'scan_out' : 'scan_in');
    document.getElementById('scanSource').value = source || 'manual';
    updateScanStatus(document.getElementById('scanAction').value, info.model, intent.qty);

    // 取消勾选确认 → 沿用扫码即入库的快速流
    if (!isConfirmEnabled()) {
        dispatchConfirmed(info);
        return;
    }
    showConfirmCard(intent, info, source);
}

/* 确认卡片与新建表单确定后，按版本门控分流到落库 */
function dispatchConfirmed(info) {
    if (needsUnitPrice()) {
        var d = collectScanData();
        showUnitPriceDialog(d, function(price) { submitScanIn(d, price); });
        return;
    }
    doScan();
}

var confirmCtx = null;

function showConfirmCard(intent, info, source) {
    var dirLocked = !!intent.scanType;
    var isIn = intent.scanType !== 'out';

    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'scanConfirmOverlay';

    var dirBtns = dirLocked
        ? '<div class="confirm-dir"><button type="button" class="confirm-dir-btn ' + (isIn ? 'active-in' : 'active-out') + '" disabled>'
          + (isIn ? '📥 入库（标签指定）' : '📤 出库（标签指定）') + '</button></div>'
        : '<div class="confirm-dir">'
          + '<button type="button" class="confirm-dir-btn ' + (isIn ? 'active-in' : '') + '" data-dir="scan_in">📥 入库</button>'
          + '<button type="button" class="confirm-dir-btn ' + (!isIn ? 'active-out' : '') + '" data-dir="scan_out">📤 出库</button>'
          + '</div>';

    overlay.innerHTML =
        '<div class="modal confirm-card">'
        + '<div class="modal-header"><h3 class="sd-modal-title">确认操作</h3></div>'
        + '<div class="modal-body sd-modal-body">'
        + '<div class="confirm-head">'
        + '<span class="confirm-head-icon">📦</span>'
        + '<div><p class="confirm-head-title">' + escapeHtml(info.model || '未命名物料') + '</p>'
        + '<p class="confirm-head-sub">' + escapeHtml(info.product_name || '') + '</p></div>'
        + '</div>'
        + '<div class="confirm-part">'
        + '<div class="confirm-part-model">' + escapeHtml(info.part_no || '-') + '</div>'
        + '<div class="confirm-part-meta">'
        + '<span>当前库存 <b>' + (info.stock || 0) + '</b></span>'
        + (info.brand ? '<span>品牌 <b>' + escapeHtml(info.brand) + '</b></span>' : '')
        + (info.package ? '<span>封装 <b>' + escapeHtml(info.package) + '</b></span>' : '')
        + '</div></div>'
        + '<div class="confirm-fields">'
        + '<div class="confirm-field"><span class="confirm-field-label">操作方向</span>' + dirBtns + '</div>'
        + '<div class="confirm-field"><span class="confirm-field-label">数量</span>'
        + '<input type="number" id="cfQty" min="1" step="1" value="' + (intent.qty || 1) + '" inputmode="numeric"></div>'
        + '</div>'
        + '</div>'
        + '<div class="modal-footer sd-modal-footer">'
        + '<button type="button" class="btn btn-ghost" id="cfCancel">取消</button>'
        + '<button type="button" class="btn btn-primary" id="cfOk">确认</button>'
        + '</div>'
        + '</div>';

    document.body.appendChild(overlay);
    confirmCtx = { overlay: overlay, intent: intent, info: info, source: source, isIn: isIn, dirLocked: dirLocked };

    // 方向切换
    overlay.querySelectorAll('.confirm-dir-btn[data-dir]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var toIn = this.getAttribute('data-dir') === 'scan_in';
            if (toIn === confirmCtx.isIn) return;
            confirmCtx.isIn = toIn;
            overlay.querySelectorAll('.confirm-dir-btn[data-dir]').forEach(function(b) {
                var bIn = b.getAttribute('data-dir') === 'scan_in';
                b.className = 'confirm-dir-btn ' + (bIn === toIn ? (toIn ? 'active-in' : 'active-out') : '');
            });
        });
    });

    overlay.querySelector('#cfCancel').addEventListener('click', closeConfirmCard);
    overlay.querySelector('#cfOk').addEventListener('click', function() {
        var qty = parseInt(document.getElementById('cfQty').value, 10);
        if (!qty || qty < 1) { LCSC.toast('数量必须大于 0', 'warning'); return; }
        if (!confirmCtx.isIn && confirmCtx.info.stock > 0 && qty > confirmCtx.info.stock) {
            LCSC.toast('出库数量超过当前库存（' + confirmCtx.info.stock + '），将扣至 0', 'warning');
        }
        applyConfirmDecision(qty);
    });
    overlay.addEventListener('click', function(e) { if (e.target === this) closeConfirmCard(); });

    var qtyInput = document.getElementById('cfQty');
    qtyInput.focus();
    qtyInput.select();
}

function applyConfirmDecision(qty) {
    var ctx = confirmCtx;
    if (!ctx) return;
    closeConfirmCard();

    setScanType(ctx.isIn ? 'scan_in' : 'scan_out');
    document.getElementById('scanQty').value = qty;
    document.getElementById('scanSource').value = ctx.source || 'manual';
    updateScanStatus(document.getElementById('scanAction').value, ctx.info.model, qty);

    dispatchConfirmed(ctx.info);
}

function closeConfirmCard() {
    if (confirmCtx && confirmCtx.overlay) confirmCtx.overlay.remove();
    confirmCtx = null;
    document.getElementById('barcodeInput').value = '';
    resumeCameraScanning();
}

function showCreatePartDialog(intent, source) {
    playErrorSound();
    var overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'scanCreateOverlay';

    var kw = intent.keyword || '';

    var guessIsModel = !!intent.model && !intent.partNo;
    var modelVal = guessIsModel ? (intent.model || '') : '';
    var ppnVal   = guessIsModel ? '' : kw;

    var platforms = (window.ScanPageConfig && window.ScanPageConfig.platforms) || [];
    var platOpts = '';
    platforms.forEach(function(p, i) {
        var sel = (p.isDefault || i === 0) ? ' selected' : '';
        platOpts += '<option value="' + p.id + '" data-type="' + escapeHtml(p.platformType) + '"' + sel + '>'
            + escapeHtml(p.name) + '（' + escapeHtml(p.code) + '）</option>';
    });
    if (!platOpts) {
        platOpts = '<option value="">（尚未配置平台，请先到平台管理新增）</option>';
    }
    var looseWrap = '<div class="create-field full sd-optional" id="cpUrlWrap">'
        + '<label>采购链接<span class="req">*</span></label>'
        + '<input type="url" id="cpUrl" placeholder="https://…"></div>';

    overlay.innerHTML =
        '<div class="modal create-card">'
        + '<div class="modal-header"><h3 class="sd-modal-title">新物料入库</h3></div>'
        + '<div class="modal-body sd-modal-body">'
        + '<div class="create-reason">未在库中找到 <code>' + escapeHtml(kw) + '</code>。'
        + '请确认它是型号还是商品编号，保存后将以 <b>' + (intent.qty || 1) + '</b> 件入库。</div>'
        + '<div class="create-field sd-field-gap">'
        + '<span class="confirm-field-label">这串内容是</span>'
        + '<div class="confirm-dir" id="cpKind">'
        + '<button type="button" class="confirm-dir-btn' + (guessIsModel ? ' active-in' : '') + '" data-kind="model">型号</button>'
        + '<button type="button" class="confirm-dir-btn' + (guessIsModel ? '' : ' active-in') + '" data-kind="part_no">商品编号</button>'
        + '</div></div>'
        + '<div class="create-grid">'
        + '<div class="create-field full"><label>所属平台<span class="req">*</span></label>'
        + '<select id="cpPlat">' + platOpts + '</select></div>'
        + '<div class="create-field full" id="cpModelWrap"><label>型号<span class="req" id="cpModelReq">*</span></label>'
        + '<input type="text" id="cpModel" class="mono" value="' + escapeHtml(modelVal) + '" placeholder="如 STM32F103C8T6"></div>'
        + '<div class="create-field full" id="cpPpnWrap"><label>商品编号<span class="req" id="cpPpnReq">*</span></label>'
        + '<input type="text" id="cpPpn" class="mono" value="' + escapeHtml(ppnVal) + '" placeholder="如 C25804"></div>'
        + '<div class="create-field"><label>数量<span class="req">*</span></label>'
        + '<input type="number" id="cpQty" min="1" step="1" value="' + (intent.qty || 1) + '" inputmode="numeric"></div>'
        + '<div class="create-field"><label>品牌</label><input type="text" id="cpBrand" placeholder="选填"></div>'
        + '<div class="create-field"><label>入库单价' + (window._featureMap && window._featureMap.cost_view ? '<span class="req">*</span>' : '') + '</label>'
        + '<input type="number" id="cpCost" min="0" step="0.0001" value="' + escapeHtml(lastUnitPrice || '') + '" placeholder="0.0000" inputmode="decimal"></div>'
        + '<div class="create-field"><label>存放位置</label><input type="text" id="cpLoc" placeholder="选填，如 主仓/A区"></div>'
        + looseWrap
        + '</div></div>'
        + '<div class="modal-footer sd-modal-footer">'
        + '<button type="button" class="btn btn-ghost" id="cpCancel">取消</button>'
        + '<button type="button" class="btn btn-primary" id="cpOk">保存并入库</button>'
        + '</div>'
        + '</div>';

    document.body.appendChild(overlay);

    // 型号 / 编号互斥：标 * 的一栏必填，另一栏隐藏
    var kind = guessIsModel ? 'model' : 'part_no';
    function syncKind() {
        var isModel = kind === 'model';
        document.getElementById('cpModelWrap').style.display = isModel ? '' : 'none';
        document.getElementById('cpPpnWrap').style.display = isModel ? 'none' : '';
        overlay.querySelectorAll('#cpKind .confirm-dir-btn').forEach(function(b) {
            b.className = 'confirm-dir-btn' + (b.getAttribute('data-kind') === kind ? ' active-in' : '');
        });
    }

    var platSel = document.getElementById('cpPlat');
    function syncPlat() {
        var opt = platSel.options[platSel.selectedIndex];
        var isLoose = opt && opt.getAttribute('data-type') === 'loose';
        document.getElementById('cpUrlWrap').classList.toggle('is-on', !!isLoose);
        // 散货渠道没有商品编号概念，型号成为必填项
        if (isLoose && kind !== 'model') {
            kind = 'model';
            syncKind();
        }
    }
    platSel.addEventListener('change', syncPlat);

    overlay.querySelectorAll('#cpKind .confirm-dir-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var m = document.getElementById('cpModel').value.trim();
            var p = document.getElementById('cpPpn').value.trim();
            if (this.getAttribute('data-kind') === 'model') {
                if (!m && p) document.getElementById('cpModel').value = p;
            } else {
                if (!p && m) document.getElementById('cpPpn').value = m;
            }
            kind = this.getAttribute('data-kind');
            syncKind();
        });
    });

    syncKind();
    syncPlat();

    overlay.querySelector('#cpCancel').addEventListener('click', function() { closeCreateDialog(); });
    overlay.querySelector('#cpOk').addEventListener('click', function() { submitCreatePart(intent, source); });
    overlay.addEventListener('click', function(e) { if (e.target === this) closeCreateDialog(); });

    // 回车提交（扫码枪式连续录入）
    overlay.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
            e.preventDefault();
            submitCreatePart(intent, source);
        }
    });

    var firstInput = kind === 'model' ? 'cpModel' : 'cpPpn';
    document.getElementById(firstInput).focus();
    document.getElementById(firstInput).select();
}

function closeCreateDialog() {
    var el = document.getElementById('scanCreateOverlay');
    if (el) el.remove();
    document.getElementById('barcodeInput').value = '';
    resumeCameraScanning();
}

function submitCreatePart(intent, source) {
    var model    = document.getElementById('cpModel').value.trim();
    var ppn      = document.getElementById('cpPpn').value.trim();
    var qty      = parseInt(document.getElementById('cpQty').value, 10);
    var brand    = document.getElementById('cpBrand').value.trim();
    var cost     = document.getElementById('cpCost').value.trim();
    var loc      = document.getElementById('cpLoc').value.trim();
    var platId   = document.getElementById('cpPlat').value;
    var platType = (function() {
        var sel = document.getElementById('cpPlat');
        var opt = sel.options[sel.selectedIndex];
        return opt ? (opt.getAttribute('data-type') || 'standard') : 'standard';
    })();
    var url      = document.getElementById('cpUrl') ? document.getElementById('cpUrl').value.trim() : '';
    var kind     = document.querySelector('#cpKind .confirm-dir-btn.active-in');

    // 按用户选定的类别校验必填项，未选的一栏不参与
    var kindLabel = (kind && kind.getAttribute('data-kind') === 'model') ? '型号' : '商品编号';
    if (kindLabel === '型号' && !model) {
        LCSC.toast('请填写型号', 'warning'); document.getElementById('cpModel').focus(); return;
    }
    if (!platId) { LCSC.toast('请先选择所属平台（可在平台管理中新增）', 'warning'); return; }
    // 散货渠道无商品编号概念，型号即编号；标准商城必须有商品编号
    if (kindLabel === '商品编号' && !ppn) {
        LCSC.toast('请填写商品编号', 'warning'); document.getElementById('cpPpn').focus(); return;
    }
    if (platType === 'loose' && !/^https?:\/\//i.test(url)) {
        LCSC.toast('散货渠道平台必须填写 http:// 或 https:// 开头的采购链接', 'warning');
        document.getElementById('cpUrl').focus();
        return;
    }
    if (!qty || qty < 1) { LCSC.toast('数量必须大于 0', 'warning'); return; }
    var needCost = !!(window._featureMap && window._featureMap.cost_view);
    if (needCost && !(parseFloat(cost) > 0)) {
        LCSC.toast((window._editionName || '当前版本') + '入库必须填写有效单价', 'warning');
        document.getElementById('cpCost').focus();
        return;
    }

    var btn = document.getElementById('cpOk');
    btn.disabled = true;
    btn.textContent = '保存中…';

    var fd = new FormData();
    fd.append('action', 'parts.create');
    fd.append('_csrf', window.ScanPageConfig.csrfToken);
    fd.append('ajax', '1');
    fd.append('platform_id', platId);
    // 只提交用户选定的类别：判错类别会污染字段（型号写进编号栏会被后续扫码误匹配）
    if (kindLabel === '型号') {
        fd.append('model', model);
        fd.append('platform_part_no', '');
    } else {
        fd.append('model', '');
        fd.append('platform_part_no', ppn);
    }
    fd.append('brand', brand);
    fd.append('location', loc);
    fd.append('purchase_url', platType === 'loose' ? url : '');
    fd.append('stock', qty);          // addPart 内部记为「初始入库」manual_in 流水
    fd.append('unit_cost', needCost ? cost : '0');

    LCSC.fetchJson('api.php', { method: 'POST', body: fd })
    .then(function(res) {
        btn.disabled = false;
        btn.textContent = '保存并入库';
        if (!res || res.code !== 0) {
            LCSC.toast((res && res.msg) || '保存失败', 'error');
            return;
        }
        if (needCost && parseFloat(cost) > 0) lastUnitPrice = cost;
        closeCreateDialog();

        var partId = (res.data && res.data.part_id) || 0;
        // 补齐「最近扫描记录」行与今日统计，无需整页刷新打断连续作业
        prependScanRecord({
            type: 'scan_in',
            part_no: kindLabel === '型号' ? ('#' + partId + '内部') : ppn,
            model: model,
            qty: qty,
            created_at: nowText(),
            scan_log_id: 0,
            remark: '新建入库'
        });
        updateScanStatus('stock.scan_in', model, qty);
        document.getElementById('scanQty').value = qty;

        LCSC.toast('已建档并入库 ' + qty + ' 件', 'success');
        playSuccessSound();
        speakResult({ ok: true, type: 'scan_in', qty: qty });
        vibrate(30);
        // 预检缓存已失效，下次扫同一料需重新查询
        invalidateScanCache();
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = '保存并入库';
        LCSC.toast('网络错误：' + e.message, 'error');
    });
}

/* 当前时间 Y-m-d H:i（与后端 date('Y-m-d H:i') 对齐） */
function nowText() {
    var d = new Date();
    function p(n) { return n < 10 ? '0' + n : String(n); }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
        + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
}

/* 文本转义，防止物料信息注入 HTML */
function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
