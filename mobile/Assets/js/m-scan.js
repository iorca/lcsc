// mobile/Assets/js/m-scan.js — 移动端「扫码出入库」交互
(function () {
    'use strict';

    var root = document.getElementById('mScanRoot');
    if (!root || !window.MUI) return;

    if (!window.ScanDecoder) {
        var el0 = document.getElementById('scanInput');
        if (el0) el0.setAttribute('disabled', 'disabled');
        MUI.toast('扫码解析组件未加载，请刷新页面', 'error');
        return;
    }

    var MUI  = window.MUI;
    var esc  = MUI.esc;
    var el   = function (id) { return document.getElementById(id); };
    var hide = function (node, h) { if (node) node.classList.toggle('is-hidden', !!h); };
    var showDrawer = function (ovId, drId, onClose, openerId) { MUI.drawer.open(ovId, drId, onClose, openerId); };
    var hideDrawer = function (ovId, drId) { MUI.drawer.close(ovId, drId); };

    // ── 配置（data-* 注入，见视图）──
    var CFG = {
        aliases:     parseJson(root.getAttribute('data-qr-aliases')) || {},
        canViewCost: root.getAttribute('data-can-view-cost') === '1',
        enforceUnit: root.getAttribute('data-enforce-unit-price') === '1',
        h5qrUrl:     root.getAttribute('data-h5qr-url') || ''
    };
    function parseJson(s) { try { return JSON.parse(s); } catch (e) { return null; } }

    // ── 状态 ──
    var dir            = 'in';          // 当前方向 in | out
    var busy           = false;         // 扫描循环锁（防重复识别 / 重复提交）
    var current        = null;          // 当前待确认的物料（预检结果）
    var lastUnitPrice  = '';            // 本页上次录入单价（连扫同批货可沿用）
    var lastScanLogId  = 0;             // 本次会话最近一条 scan_log_id（连续模式撤销用）
    var session        = { count: 0, inQty: 0, outQty: 0 };
    var camera         = null;          // Html5Qrcode 实例
    var cameraOpen     = false;

    var LS_CONTINUOUS = 'scan_continuous';
    var LS_SKIP_COST  = 'scan_skip_cost';
    function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    function isContinuous() { return lsGet(LS_CONTINUOUS) === '1'; }
    function isSkipCost()   { return lsGet(LS_SKIP_COST) === '1'; }

    // ── 声音 / 震动反馈 ──
    var audioCtx = null;
    function sound(ok) {
        try {
            if (!audioCtx) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume();
            var o = audioCtx.createOscillator();
            var g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = ok ? 880 : 220;
            g.gain.value = 0.08;
            o.connect(g); g.connect(audioCtx.destination);
            o.start();
            o.stop(audioCtx.currentTime + (ok ? 0.09 : 0.22));
        } catch (e) { /* 静默：提示音失败不影响业务 */ }
    }
    function vibrate(p) { try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) {} }

    // ── API（两个信封都要认）──
    function api(action, data) {
        return MUI.ajax(action, data).then(function (res) {
            if (res && typeof res === 'object' && res.code !== undefined) {
                return { ok: false, error: res.msg || '请求失败（' + res.code + '）' };
            }
            return res || { ok: false, error: '空响应' };
        });
    }

    function setDir(next) {
        dir = (next === 'out') ? 'out' : 'in';
        var bar = el('scanDirBar');
        if (bar) {
            Array.prototype.forEach.call(bar.querySelectorAll('.m-seg-btn'), function (b) {
                b.classList.toggle('is-on', b.getAttribute('data-dir') === dir);
            });
        }
        syncConfirmLabels();
    }
    function getQty() {
        var n = parseInt(el('scanQty') ? el('scanQty').value : '1', 10) || 1;
        return n < 1 ? 1 : n;
    }
    function setQty(n) {
        var i = el('scanQty');
        if (i) i.value = String(n < 1 ? 1 : n);
    }
    function syncConfirmLabels() {
        var t = el('cfTitle');
        var b = el('cfSubmit');
        if (t) t.textContent = dir === 'in' ? '确认入库' : '确认出库';
        if (b) b.textContent = dir === 'in' ? '确认入库' : '确认出库';
        // 单价区仅入库且团队版需要
        var wrap = el('cfPriceWrap');
        if (wrap) hide(wrap, !(dir === 'in' && CFG.canViewCost));
        var skipLabel = el('cfSkipCost') ? el('cfSkipCost').closest('.m-switch') : null;
        if (skipLabel) hide(skipLabel, !(dir === 'in' && CFG.canViewCost));
    }

    function bind() {
        var bar = el('scanDirBar');
        if (bar) bar.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-seg-btn') : null;
            if (b) setDir(b.getAttribute('data-dir'));
        });

        // 顶部提示条关闭按钮（事件委托，兼容 JS 动态插入的提示条）
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-hint-close]') : null;
            if (!b) return;
            var target = el(b.getAttribute('data-hint-close'));
            if (target) {
                target.classList.add('is-hidden');
                if (b.getAttribute('data-hint-close') === 'continuousHint') {
                    var cs = el('continuousSwitch');
                    if (cs) { cs.checked = false; lsSet(LS_CONTINUOUS, '0'); syncContinuousUI(); }
                }
            }
        });

        var minus = el('qtyMinus'), plus = el('qtyPlus');
        if (minus) minus.addEventListener('click', function () { setQty(getQty() - 1); });
        if (plus)  plus.addEventListener('click', function () { setQty(getQty() + 1); });

        var input = el('scanInput');
        if (input) input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); handleRaw(input.value, 'manual'); input.value = ''; }
        });

        var camBtn = el('camToggle');
        if (camBtn) camBtn.addEventListener('click', toggleCamera);
        var camClose = el('camClose');
        if (camClose) camClose.addEventListener('click', closeCamera);
        var camOverlay = el('camOverlay');
        if (camOverlay) camOverlay.addEventListener('click', closeCamera);

        var cs = el('continuousSwitch');
        if (cs) {
            cs.checked = isContinuous();
            cs.addEventListener('change', function () {
                lsSet(LS_CONTINUOUS, cs.checked ? '1' : '0');
                syncContinuousUI();
                if (cs.checked) MUI.toast('已开启连续扫码', 'success');
            });
        }
        var ssbEnd = el('ssbEnd');
        if (ssbEnd) ssbEnd.addEventListener('click', endSession);

        var ssbUndo = el('ssbUndo');
        if (ssbUndo) ssbUndo.addEventListener('click', function () {
            if (lastScanLogId) doUndo(lastScanLogId, null, true);
        });

        // 确认面板
        var submit = el('cfSubmit');
        if (submit) submit.addEventListener('click', confirmSubmit);
        var cancel = el('cfCancel');
        if (cancel) cancel.addEventListener('click', function () { closeConfirm(); finishCycle(); });
        var skip = el('cfSkipCost');
        if (skip) skip.addEventListener('change', syncPriceState);
        var price = el('cfPrice');
        if (price) price.addEventListener('input', syncPriceState);

        var candCancel = el('candCancel');
        if (candCancel) candCancel.addEventListener('click', function () { hide(el('candidatePanel'), true); finishCycle(); });
        var nfClose = el('nfClose');
        if (nfClose) nfClose.addEventListener('click', function () { hide(el('notFoundPanel'), true); finishCycle(); });
    }

    function syncContinuousUI() {
        var on = isContinuous();
        hide(el('continuousHint'), !on);
        hide(el('sessionBar'), !on);
        if (on) renderSession();
    }

    /* 折叠区（今日统计 / 最近扫描）：点标题展开收起 */
    function bindCollapse(headId, bodyId) {
        var head = el(headId), body = el(bodyId);
        if (!head || !body) return;
        head.addEventListener('click', function () {
            var open = body.classList.contains('is-hidden');
            body.classList.toggle('is-hidden', !open);
            head.classList.toggle('is-open', open);
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    function syncPriceState() {
        var skip = el('cfSkipCost'), price = el('cfPrice'), btn = el('cfSubmit');
        if (!skip || !price || !btn) return;
        var isIn = dir === 'in';
        if (!isIn) { btn.disabled = false; return; }
        if (skip.checked) {
            price.value = '';
            price.setAttribute('disabled', 'disabled');
            btn.disabled = false;
            return;
        }
        price.removeAttribute('disabled');
        btn.disabled = !(parseFloat(price.value) > 0);
    }

    function handleRaw(raw, source) {
        if (busy) return;
        var text = (raw == null ? '' : String(raw)).trim();
        if (text === '') return;

        var intent = window.ScanDecoder.toIntent(text, CFG.aliases);
        if (!intent || intent.kind === 'empty') return;

        busy = true;
        pauseCamera();

        // 二维码自带方向 / 数量：方向立即生效
        if (intent.scanType === 'in' || intent.scanType === 'out') setDir(intent.scanType);
        setQty(intent.executable && intent.qty > 0 ? intent.qty : 1);
        if (intent.decoded && intent.decoded.type) {
            var si = el('scanInput');
            if (si && intent.partNo) si.value = intent.partNo;   // 视觉反馈
        }

        resolve(intent);
    }

    /* 预检：查物料是否存在（只查不改库存） */
    function resolve(intent) {
        var params = {
            barcode: intent.partNo || '',
            model: intent.model || '',
            internal_id: intent.internalId || 0,
            platform_code: intent.platformCode || ''
        };
        if (intent.fields) params.fields = JSON.stringify(intent.fields);

        api('stock.scan_resolve', params).then(function (res) {
            if (!res || res.ok === false) { fail(res && res.error); return; }

            if (res.ambiguous && res.candidates && res.candidates.length > 1) {
                showCandidates(intent, res.candidates);
                return;
            }
            if (res.found) { onResolved(intent, res); return; }
            showNotFound(intent.keyword || intent.partNo || intent.model || '');
        }).catch(function () { fail('网络错误，请重试'); });
    }

    function onResolved(intent, part) {
        current = { intent: intent, part: part };
        if (isContinuous()) {
            submit(dir === 'in' && CFG.canViewCost ? { skip_cost: 1 } : {});
            return;
        }
        if (part.ambiguous && part.candidates && part.candidates.length > 1) {
            showCandidates(intent, part.candidates);
            return;
        }
        renderConfirm(intent, part);
    }

    function renderConfirm(intent, part) {
        el('cfModel').textContent  = part.model || '—';
        el('cfPartNo').textContent = part.part_no || intent.partNo || '—';
        el('cfStock').textContent  = (part.stock === undefined ? '—' : String(part.stock));
        el('cfTitle').textContent  = dir === 'in' ? '确认入库' : '确认出库';
        el('cfSubmit').textContent = dir === 'in' ? '确认入库' : '确认出库';

        var price = el('cfPrice');
        if (price) {
            var hist = parseFloat(part.last_purchase_price) || 0;
            price.value = lastUnitPrice || (hist > 0 ? String(hist) : '');
            var hint = el('cfPriceHint');
            if (hint) hint.textContent = hist > 0 ? ('上次采购价 ¥' + hist.toFixed(4)) : '';
        }
        var skip = el('cfSkipCost');
        if (skip) skip.checked = isSkipCost();

        syncConfirmLabels();
        syncPriceState();
        hide(el('confirmPanel'), false);
        hide(el('candidatePanel'), true);
        hide(el('notFoundPanel'), true);
    }

    function showCandidates(intent, list) {
        var box = el('candList');
        var html = '';
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            html += '<button type="button" class="m-cand" data-idx="' + i + '">'
                 + '<span class="m-cand-name">' + esc(c.model || c.part_no || ('#' + c.part_id)) + '</span>'
                 + '<span class="m-cand-meta">' + esc(c.part_no || '') + (c.stock === undefined ? '' : (' · 库存 ' + esc(String(c.stock)))) + '</span>'
                 + '</button>';
        }
        box.innerHTML = html;
        box.onclick = function (e) {
            var b = e.target.closest ? e.target.closest('.m-cand') : null;
            if (!b) return;
            var pick = list[parseInt(b.getAttribute('data-idx'), 10)];
            hide(el('candidatePanel'), true);
            if (pick) { current = null; onResolved(intent, pick); }
        };
        hide(el('candidatePanel'), false);
    }

    function showNotFound(keyword) {
        var k = el('nfKeyword');
        if (k) k.textContent = keyword;
        hide(el('notFoundPanel'), false);
        sound(false); vibrate(60);
    }

    function closeConfirm() {
        hide(el('confirmPanel'), true);
        current = null;
    }

    /* 结束一次扫描周期：解锁 + 恢复摄像头 */
    function finishCycle() {
        busy = false;
        resumeCamera();
        var si = el('scanInput');
        if (si && !cameraOpen) si.focus();
    }

    function fail(msg) {
        hide(el('confirmPanel'), true);
        hide(el('candidatePanel'), true);
        hide(el('notFoundPanel'), true);
        sound(false); vibrate([80, 40, 80]);
        showResult(false, { error: msg || '操作失败' });
        finishCycle();
    }

    function submit(extra) {
        if (!current) { finishCycle(); return; }
        var intent = current.intent;
        var part   = current.part;
        var opts   = extra || {};

        // 团队版入库单价校验（不计成本时不拦）
        if (dir === 'in' && CFG.enforceUnit && !opts.skip_cost) {
            var price = parseFloat(el('cfPrice') ? el('cfPrice').value : '0');
            if (!(price > 0)) {
                MUI.toast('团队版入库需填写有效单价（或勾选「不计成本」）', 'warning');
                return;
            }
            lastUnitPrice = String(price);
            opts.unit_cost = price;
        }

        var action = dir === 'in' ? 'stock.scan_in' : 'stock.scan_out';
        var data = {
            ajax: '1',
            barcode: intent.partNo || '',
            internal_id: intent.internalId || 0,
            platform_code: intent.platformCode || '',
            model: intent.model || '',
            qty: getQty(),
            order_no: intent.orderNo || '',
            scan_source: intent.decoded ? (intent.decoded.type || '') : ''
        };
        if (intent.fields) data.fields = JSON.stringify(intent.fields);
        if (opts.unit_cost) data.unit_cost = opts.unit_cost;
        if (opts.skip_cost) data.skip_cost = '1';

        setBusyUI(true);
        api(action, data).then(function (res) {
            setBusyUI(false);
            if (!res || res.ok === false) { fail(res && res.error); return; }
            onSuccess(res);
        }).catch(function () { setBusyUI(false); fail('网络错误，请重试'); });
    }

    function confirmSubmit() { submit({ skip_cost: el('cfSkipCost') && el('cfSkipCost').checked ? 1 : 0 }); }

    function setBusyUI(on) {
        var btn = el('cfSubmit');
        if (btn) btn.disabled = on;
    }

    function onSuccess(data) {
        closeConfirm();
        prependRow(data);
        bumpStats(data.type === 'scan_in' ? 'in' : 'out', parseInt(data.qty, 10) || 0);
        if (data.scan_log_id) lastScanLogId = data.scan_log_id;
        sound(true); vibrate(30); flashCam();

        if (isContinuous()) {
            session.count += 1;
            if (data.type === 'scan_in') session.inQty += parseInt(data.qty, 10) || 0;
            else session.outQty += parseInt(data.qty, 10) || 0;
            renderSession();
            MUI.toast((data.type === 'scan_in' ? '入库 +' : '出库 -') + (parseInt(data.qty, 10) || 0)
                      + ' · ' + (data.part_no || data.model || ''), 'success');
        } else {
            showResult(true, data);
            // 提示条带「撤销」动作位：刚扫错时可一键回退（不用去记录里翻那条）
            if (data.scan_log_id) {
                var sid = data.scan_log_id;
                MUI.toast((data.type === 'scan_in' ? '入库 +' : '出库 -') + (parseInt(data.qty, 10) || 0)
                          + ' · ' + (data.part_no || data.model || ''), 'success', {
                    label: '撤销',
                    onClick: function () { doUndo(sid, null, false); }
                });
            }
        }
        finishCycle();
    }

    function renderSession() {
        el('ssbCount').textContent = String(session.count);
        el('ssbIn').textContent    = String(session.inQty);
        el('ssbOut').textContent   = String(session.outQty);
        el('ssbUndo').disabled     = session.count === 0;
    }

    function endSession() {
        session = { count: 0, inQty: 0, outQty: 0 };
        lastScanLogId = 0;
        // 结束本次 = 同时退出连续模式：只清计数而保留勾选，会出现
        // 「提示已结束、开关仍勾选、会话条还在」的自相矛盾。
        var cs = el('continuousSwitch');
        if (cs) { cs.checked = false; lsSet(LS_CONTINUOUS, '0'); }
        renderSession();
        syncContinuousUI();
        MUI.toast('本次连续扫码已结束', 'success');
    }

    var resultTimer = null;
    function showResult(ok, data) {
        var head = el('resHead'), body = el('resBody');
        if (!head || !body) return;
        clearTimeout(resultTimer);

        if (ok) {
            var isIn = data.type === 'scan_in';
            head.className = 'm-result-head ' + (isIn ? 'is-in' : 'is-out');
            head.innerHTML = (isIn
                ? '<span class="m-ico m-ico--in" aria-hidden="true"></span> 扫码入库成功'
                : '<span class="m-ico m-ico--out" aria-hidden="true"></span> 扫码出库成功');
            body.innerHTML =
                '<div class="m-result-row"><span>型号</span><b>' + esc(data.model || '-') + '</b></div>'
              + '<div class="m-result-row"><span>编号</span><b>' + esc(data.part_no || '-') + '</b></div>'
              + '<div class="m-result-row"><span>数量变化</span><b class="' + (isIn ? 'is-in' : 'is-out') + '">'
              +      (isIn ? '+' : '-') + esc(String(parseInt(data.qty, 10) || 0)) + '</b></div>'
              + '<div class="m-result-row"><span>当前库存</span><b>' + esc(String(data.qty_after === undefined ? '-' : data.qty_after)) + '</b></div>';
        } else {
            head.className = 'm-result-head is-err';
            head.innerHTML = '<span class="m-ico m-ico--alert" aria-hidden="true"></span> 操作失败';
            body.innerHTML = '<div class="m-result-err">' + esc(data.error || '未知错误') + '</div>';
        }
        hide(el('resultPanel'), false);
        resultTimer = setTimeout(function () { hide(el('resultPanel'), true); }, ok ? 3000 : 5000);
    }

    function prependRow(data) {
        var list = el('recentList');
        if (!list) return;
        hide(el('recentEmpty'), true);
        var isIn = data.type === 'scan_in';
        var row = document.createElement('div');
        row.className = 'm-scan-row';
        row.setAttribute('data-id', String(data.scan_log_id || ''));
        row.setAttribute('data-type', isIn ? 'in' : 'out');
        row.setAttribute('data-qty', String(parseInt(data.qty, 10) || 0));
        row.innerHTML =
            '<div class="m-scan-row-main">'
          + '<div class="m-scan-row-name">' + esc(data.model || data.part_no || '-') + '</div>'
          + '<div class="m-scan-row-meta">' + esc(nowStr()) + ' · ' + esc(data.part_no || '') + '</div>'
          + '</div>'
          + '<span class="m-scan-badge ' + (isIn ? 'is-in' : 'is-out') + '">' + (isIn ? '入' : '出') + '</span>'
          + '<span class="m-scan-row-qty ' + (isIn ? 'is-in' : 'is-out') + '">' + (isIn ? '+' : '-') + (parseInt(data.qty, 10) || 0) + '</span>'
          + '<button type="button" class="m-scan-undo" data-id="' + String(data.scan_log_id || '') + '" title="撤销此记录" aria-label="撤销此记录"><span class="m-ico m-ico--undo" aria-hidden="true"></span></button>';
        list.insertBefore(row, list.firstChild);
        // 只保留最近 20 条
        while (list.children.length > 20) list.removeChild(list.lastChild);
        syncRecentBadge();
    }

    function nowStr() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function bumpStats(type, qty) {
        var cnt = el(type === 'in' ? 'stIn' : 'stOut');
        var q   = el(type === 'in' ? 'stInQty' : 'stOutQty');
        if (cnt) cnt.textContent = String((parseInt(cnt.textContent, 10) || 0) + 1);
        if (q)   q.textContent   = String((parseInt(q.textContent, 10) || 0) + (qty || 0));
        syncTodayBadge();
    }
    function dropStats(type, qty) {
        var cnt = el(type === 'in' ? 'stIn' : 'stOut');
        var q   = el(type === 'in' ? 'stInQty' : 'stOutQty');
        if (cnt) cnt.textContent = String(Math.max(0, (parseInt(cnt.textContent, 10) || 0) - 1));
        if (q)   q.textContent   = String(Math.max(0, (parseInt(q.textContent, 10) || 0) - (qty || 0)));
        syncTodayBadge();
    }
    /* 今日统计折叠标题上的笔数徽标（统计在折叠区内，折叠时也能看到概况） */
    function syncTodayBadge() {
        var badge = el('mTodayBadge');
        if (!badge) return;
        var n = (parseInt(el('stIn') ? el('stIn').textContent : '0', 10) || 0)
              + (parseInt(el('stOut') ? el('stOut').textContent : '0', 10) || 0);
        badge.textContent = n + ' 笔';
    }
    /* 最近扫描折叠标题上的条数徽标 */
    function syncRecentBadge() {
        var badge = el('mRecentBadge');
        var list = el('recentList');
        if (!badge || !list) return;
        badge.textContent = list.children.length + ' 条';
    }

    function doUndo(scanLogId, btn, fromSession) {
        if (!scanLogId) return;
        if (btn) btn.disabled = true;
        api('stock.scan_undo', { ajax: '1', scan_log_id: scanLogId }).then(function (res) {
            if (!res || res.ok === false) {
                if (btn) btn.disabled = false;
                MUI.toast((res && res.error) || '撤销失败', 'error');
                return;
            }
            // 行内撤销：移除行 + 回退统计
            var row = document.querySelector('.m-scan-row[data-id="' + scanLogId + '"]');
            if (row) {
                dropStats(row.getAttribute('data-type') === 'in' ? 'in' : 'out', parseInt(row.getAttribute('data-qty'), 10) || 0);
                row.parentNode.removeChild(row);
            }
            var list = el('recentList');
            if (list && list.children.length === 0) hide(el('recentEmpty'), false);
            syncRecentBadge();
            if (fromSession && lastScanLogId === scanLogId) { session.count = Math.max(0, session.count - 1); renderSession(); }
            sound(false);
            MUI.toast('已撤销', 'success');
        }).catch(function () {
            if (btn) btn.disabled = false;
            MUI.toast('网络错误，请重试', 'error');
        });
    }

    function bindUndoDelegation() {
        var list = el('recentList');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-scan-undo') : null;
            if (!b || b.disabled) return;
            doUndo(parseInt(b.getAttribute('data-id'), 10), b, false);
        });
    }

    var scriptLoading = null;
    function loadScriptOnce(url) {
        if (scriptLoading) return scriptLoading;
        scriptLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = url; s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('load failed')); };
            document.head.appendChild(s);
        });
        return scriptLoading;
    }

    function toggleCamera() { if (cameraOpen) { closeCamera(); } else { openCamera(); } }

    /* 摄像头不可用：顶部提示条（自动消退），不打开抽屉、不出黑框 */
    var camHintTimer = null;
    function showCamHint(text) {
        var bar = el('camHttpsHint');
        if (!bar) return;
        if (text) bar.textContent = text;
        bar.classList.remove('is-hidden');
        clearTimeout(camHintTimer);
        camHintTimer = setTimeout(function () { bar.classList.add('is-hidden'); }, 5000);
    }

    function openCamera() {
        hide(el('camHttpsHint'), true);   // 复位顶部提示

        if (!window.isSecureContext) {
            showCamHint('当前访问方式不支持摄像头（需 HTTPS 或 localhost）。请改用扫码枪或手动输入。');
            return;
        }
        if (!CFG.h5qrUrl) {
            showCamHint('摄像头组件地址缺失，无法启动。请改用扫码枪或手动输入。');
            return;
        }

        loadScriptOnce(CFG.h5qrUrl).then(function () {
            if (!window.Html5Qrcode) {
                showCamHint('摄像头组件加载失败。请改用扫码枪或手动输入。');
                return;
            }
            // 库就绪 → 打开抽屉承载取景框
            showDrawer('camOverlay', 'camDrawer', closeCamera, 'camToggle');
            var status = el('camStatus');
            hide(el('camPlaceholder'), false);
            if (status) status.textContent = '正在启动…';
            try { camera = new window.Html5Qrcode('camView'); }
            catch (e) { if (status) status.textContent = '摄像头初始化失败'; return; }
            camera.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                function (text) { if (!busy) handleRaw(text, 'camera'); },
                function () { /* 单帧未识别：无需处理 */ }
            ).then(function () {
                cameraOpen = true;
                hide(el('camPlaceholder'), true);
                if (status) status.textContent = '摄像头就绪';
                setCamDot(true);
                var btn = el('camToggle');
                if (btn) { btn.innerHTML = '<span class="m-ico m-ico--camera" aria-hidden="true"></span> 关闭摄像头扫码'; btn.classList.add('is-on'); }
            }).catch(function () {
                // 授权被拒 / 无设备：降级提示，不打断页面其余功能
                if (status) status.textContent = '无法启动（请检查摄像头权限）';
                MUI.toast('无法启动摄像头，可改用扫码枪或手动输入', 'warning');
                hide(el('camPlaceholder'), false);
            });
        }).catch(function () {
            showCamHint('摄像头组件加载失败。请改用扫码枪或手动输入。');
        });
    }

    function closeCamera() {
        if (camera) {
            try {
                var done = cameraOpen ? camera.stop() : Promise.resolve();
                done.then(function () {
                    try { camera.clear(); } catch (e) {}
                    camera = null;
                }).catch(function () { camera = null; });
            } catch (e) { camera = null; try { camera.clear(); } catch (e2) {} }
        }
        cameraOpen = false;
        hide(el('camPlaceholder'), false);
        hideDrawer('camOverlay', 'camDrawer');
        setCamDot(false);
        var btn = el('camToggle');
        if (btn) { btn.innerHTML = '<span class="m-ico m-ico--camera" aria-hidden="true"></span> 开启摄像头扫码'; btn.classList.remove('is-on'); }
    }

    function setCamDot(on) {
        var d = el('camDot');
        if (d) { d.classList.toggle('is-ok', !!on); d.classList.toggle('is-off', !on); }
    }

    /* 识别/落库成功时给取景框一次绿闪（视觉确认，与提示音/震动互补） */
    function flashCam() {
        if (!cameraOpen) return;
        var v = el('camView');
        if (!v) return;
        v.classList.remove('is-flash');
        void v.offsetWidth;                 // 强制重排以重放动画
        v.classList.add('is-flash');
        setTimeout(function () { v.classList.remove('is-flash'); }, 360);
    }

    function pauseCamera() { try { if (cameraOpen && camera && camera.pause) camera.pause(true); } catch (e) {} }
    function resumeCamera() { try { if (cameraOpen && camera && camera.resume) camera.resume(); } catch (e) {} }

    // ── 初始化 ──
    bind();
    bindUndoDelegation();
    bindCollapse('mRecentCollapseHead', 'mRecentCollapseBody');
    setDir('in');
    syncContinuousUI();

    window.__mScanInit = true;   // 供自测 / 排障观测
})();
