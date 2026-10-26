// modules/scan/Assets/js/scan-camera.js — 扫码出入库页 · 设备与图像识别


var scannerConnected = false;
var scannerVerifyTimer = null;
var scannerVerifyListening = false;

function initScanner() {
    // 检查 localStorage 是否已连接
    if (localStorage.getItem('lcsc_scanner_connected') === '1') {
        scannerConnected = true;
        updateScannerStatus();
    } else {
        updateScannerStatus();
    }
}

function updateScannerStatus() {
    var dot = document.getElementById('scannerDot');
    var text = document.getElementById('scannerText');
    var btn = document.getElementById('scannerBtn');
    var status = document.getElementById('scannerStatus');
    if (scannerConnected) {
        dot.className = 'dot ok';
        text.textContent = '扫码枪已连接';
        text.style.color = 'var(--green)';
        btn.textContent = '断开';
        btn.onclick = function(e) { e.stopPropagation(); disconnectScanner(); };
        status.title = '扫码枪已连接，点击断开';
    } else {
        dot.className = 'dot off';
        text.textContent = '扫码枪未连接';
        text.style.color = '';
        btn.textContent = '连接';
        btn.onclick = function(e) { e.stopPropagation(); connectScanner(); };
        status.title = '点击连接扫码枪';
    }
}

function connectScanner() {
    if (scannerConnected) return;
    // 显示连接验证弹窗
    var body = document.getElementById('deviceModalBody');
    var footer = document.getElementById('deviceModalFooter');
    var title = document.getElementById('deviceModalTitle');
    title.textContent = '🔫 连接扫码枪';
    footer.classList.add('is-off');
    body.innerHTML = '<div class="scanner-verify-area">' +
        '<div class="scanner-verify-icon">🔫</div>' +
        '<p class="sc-verify-lead">请使用扫码枪扫描任意条码以验证连接</p>' +
        '<p class="sc-verify-sub">用扫码枪扫描任意条码进行验证（系统已自动接收输入）</p>' +
        '<div class="scanner-verify-timer"><span id="verifyTimer">15</span> 秒</div>' +
        '<div class="scanner-verify-bar"><div class="scanner-verify-bar-fill sc-verify-full" id="verifyBar"></div></div>' +
        '<button class="btn btn-ghost btn-sm sc-verify-btn" onclick="closeDeviceModal()">取消</button>' +
    '</div>';
    document.getElementById('deviceModal').classList.remove('is-off');

    // 聚焦输入框
    var input = document.getElementById('barcodeInput');
    input.focus();

    // 开始监听输入
    scannerVerifyListening = true;
    var seconds = 15;
    var timerEl = document.getElementById('verifyTimer');
    var barEl = document.getElementById('verifyBar');

    scannerVerifyTimer = setInterval(function() {
        seconds--;
        if (timerEl) timerEl.textContent = seconds;
        if (barEl) barEl.style.width = (seconds / 15 * 100) + '%';
        if (seconds <= 0) {
            clearInterval(scannerVerifyTimer);
            scannerVerifyListening = false;
            closeDeviceModal();
            showToast('连接超时，请重试', 'warning');
        }
    }, 1000);
}

function onScannerVerifyInput() {
    if (!scannerVerifyListening) return;
    // 收到输入 → 连接成功
    clearInterval(scannerVerifyTimer);
    scannerVerifyListening = false;
    scannerConnected = true;
    localStorage.setItem('lcsc_scanner_connected', '1');
    closeDeviceModal();
    updateScannerStatus();
    showToast('✓ 扫码枪连接成功', 'success');
    playSuccessSound();
    vibrate(30);
}

function disconnectScanner() {
    scannerConnected = false;
    localStorage.removeItem('lcsc_scanner_connected');
    updateScannerStatus();
    showToast('扫码枪已断开', 'warning');
}

// ── 摄像头设备选择 ──
var videoDevices = [];
var selectedCameraId = null;
var cameraActive = false;
var cameraStarting = false;
var html5QrCode = null;
var cameraAvailable = null;

// ── HTTPS 提示条（仅摄像头不可用时显示，可关闭并记忆）──
var HTTPS_NOTICE_KEY = 'lcsc_https_notice_dismissed';
function showHttpsNotice() {
    var el = document.getElementById('httpsNotice');
    if (!el) return;
    var dismissed = false;
    try { dismissed = localStorage.getItem(HTTPS_NOTICE_KEY) === '1'; } catch (e) {}
    if (dismissed) return;
    el.classList.add('show');
}
function dismissHttpsNotice() {
    try { localStorage.setItem(HTTPS_NOTICE_KEY, '1'); } catch (e) {}
    var el = document.getElementById('httpsNotice');
    if (el) el.classList.remove('show');
}

function initCamera() {
    var proto = window.location.protocol;
    var isSecure = (proto === 'https:');
    var isLocal = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    var camAvailable = isSecure || isLocal;

    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');

    document.getElementById('currentProto').textContent = proto + '//' + window.location.hostname;
    var statusEl = document.getElementById('httpsStatus');
    if (!camAvailable) {
        cameraAvailable = false;
        dot.className = 'dot off';
        text.textContent = '摄像头需要 HTTPS';
        text.style.color = 'var(--red)';
        statusEl.innerHTML = '<strong class="sc-verify-state is-bad">不可用</strong>';
        showHttpsNotice();
        return;
    }
    statusEl.innerHTML = '<strong class="sc-verify-state is-ok">可用</strong>';

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        cameraAvailable = false;
        dot.className = 'dot off';
        text.textContent = '浏览器不支持摄像头';
        text.style.color = 'var(--red)';
        return;
    }

    cameraAvailable = true;

    // 枚举设备，检查 localStorage 是否有已保存的设备
    var savedId = localStorage.getItem('lcsc_camera_device');
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            dot.className = 'dot off';
            text.textContent = '未检测到摄像头';
            text.style.color = 'var(--yellow)';
            return;
        }
        if (savedId) {
            var savedDev = videoDevices.find(function(d) { return d.deviceId === savedId; });
            if (savedDev) {
                selectedCameraId = savedId;
                dot.className = 'dot off';
                text.textContent = getShortLabel(savedDev);
                text.style.color = 'var(--green)';
                document.getElementById('cameraBtn').textContent = '切换';
                return;
            }
            // 已保存的设备不存在了，清除
            localStorage.removeItem('lcsc_camera_device');
        }
        dot.className = 'dot off';
        text.textContent = '摄像头未选择 (' + videoDevices.length + '个可用)';
        text.style.color = '';
    }).catch(function() {
        dot.className = 'dot off';
        text.textContent = '点击选择摄像头';
        text.style.color = '';
    });
}

function selectCamera() {
    if (cameraAvailable === false) {
        showToast('摄像头不可用（需要 HTTPS 或 localhost）', 'warning');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        showToast('浏览器不支持设备枚举', 'error');
        return;
    }
    showToast('正在检测摄像头设备...', 'warning');
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            showToast('未检测到摄像头设备', 'warning');
            return;
        }
        showCameraSelector();
    }).catch(function() {
        showToast('无法枚举设备', 'error');
    });
}

function showCameraSelector() {
    var body = document.getElementById('deviceModalBody');
    var footer = document.getElementById('deviceModalFooter');
    var title = document.getElementById('deviceModalTitle');
    title.textContent = '📷 选择摄像头';
    footer.classList.remove('is-off');

    // 排序：后置摄像头排在前面，方便移动端用户选择
    var sorted = videoDevices.slice().sort(function(a, b) {
        var aRear = isRearCamera(a), bRear = isRearCamera(b);
        if (aRear && !bRear) return -1;
        if (!aRear && bRear) return 1;
        var aFront = isFrontCamera(a), bFront = isFrontCamera(b);
        if (aFront && !bFront) return 1;
        if (!aFront && bFront) return -1;
        return 0;
    });

    var html = '<p class="device-modal-desc">请选择要使用的摄像头设备（后置摄像头推荐）：</p><div class="device-list">';
    sorted.forEach(function(device, idx) {
        var rawLabel = device.label || ('摄像头 ' + (idx + 1));
        var icon = isRearCamera(device) ? '🔄' : (isFrontCamera(device) ? '📱' : getDeviceIcon(rawLabel));
        var labelSuffix = isRearCamera(device) ? ' (后置)' : (isFrontCamera(device) ? ' (前置)' : '');
        var label = rawLabel + labelSuffix;
        var devId = device.deviceId || ('idx_' + idx);
        var isSelected = devId === selectedCameraId;
        var idDisplay = device.deviceId ? device.deviceId.substring(0, 16) + '...' : '设备 #' + (idx + 1);
        html += '<div class="device-item' + (isSelected ? ' selected' : '') + '" data-device-id="' + devId + '" onclick="selectCameraDevice(\'' + devId + '\')">' +
            '<span class="device-item-icon">' + icon + '</span>' +
            '<div class="device-item-info">' +
            '  <div class="device-item-name">' + label + '</div>' +
            '  <div class="device-item-id">' + idDisplay + '</div>' +
            '</div>' +
            '<span class="device-item-check">✓</span>' +
        '</div>';
    });
    html += '</div>';
    body.innerHTML = html;
    document.getElementById('deviceModal').classList.remove('is-off');
}

function selectCameraDevice(deviceId) {
    selectedCameraId = deviceId;
    // 更新选中状态
    var items = document.querySelectorAll('.device-item');
    items.forEach(function(item) {
        if (item.getAttribute('data-device-id') === deviceId) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
    });
}

function confirmDeviceSelection() {
    if (!selectedCameraId) {
        showToast('请先点击选择一个摄像头设备', 'warning');
        return;
    }
    // 如果是索引标识（idx_N），转换为真实的 deviceId
    if (selectedCameraId.indexOf('idx_') === 0) {
        var idx = parseInt(selectedCameraId.substring(4), 10);
        if (!isNaN(idx) && videoDevices[idx] && videoDevices[idx].deviceId) {
            selectedCameraId = videoDevices[idx].deviceId;
        } else {
        }
    }
    // 只保存真实 deviceId 到 localStorage
    if (document.getElementById('rememberDevice').checked && selectedCameraId.indexOf('idx_') !== 0) {
        localStorage.setItem('lcsc_camera_device', selectedCameraId);
    }
    closeDeviceModal();

    // 更新状态
    var chosen = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
    if (!chosen && videoDevices.length > 0) chosen = videoDevices[0];
    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');
    dot.className = 'dot off';
    text.textContent = getShortLabel(chosen);
    text.style.color = 'var(--green)';
    document.getElementById('cameraBtn').textContent = '切换';

    showToast('摄像头已选择，点击 📷 按钮开启', 'success');
}

function closeDeviceModal() {
    document.getElementById('deviceModal').classList.add('is-off');
    // 清理扫码枪验证定时器
    if (scannerVerifyTimer) {
        clearInterval(scannerVerifyTimer);
        scannerVerifyTimer = null;
    }
    scannerVerifyListening = false;
}

function getShortLabel(device) {
    var label = (device && device.label || '').trim();
    if (!label) return '摄像头';
    label = label.replace(/^(USB|Integrated|Built-in|HD|FHD|UHD)\s*/i, '');
    if (label.length > 20) label = label.substring(0, 20) + '...';
    return label;
}

function getDeviceIcon(label) {
    var l = (label || '').toLowerCase();
    if (l.indexOf('virtual') >= 0 || l.indexOf('obs') >= 0) return '🖥️';
    if (l.indexOf('usb') >= 0 || l.indexOf('webcam') >= 0) return '📷';
    if (l.indexOf('integrated') >= 0 || l.indexOf('built-in') >= 0) return '💻';
    return '📹';
}

// 判断是否为后置摄像头（移动端）
function isRearCamera(device) {
    var l = ((device && device.label) || '').toLowerCase();
    return l.indexOf('back') >= 0 || l.indexOf('rear') >= 0 || l.indexOf('environment') >= 0 || l.indexOf('后置') >= 0 || l.indexOf('后摄') >= 0;
}

// 判断是否为前置摄像头（移动端）
function isFrontCamera(device) {
    var l = ((device && device.label) || '').toLowerCase();
    return l.indexOf('front') >= 0 || l.indexOf('user') >= 0 || l.indexOf('facing') >= 0 || l.indexOf('前置') >= 0 || l.indexOf('前摄') >= 0;
}

// 从设备列表中优先选择后置摄像头
function pickRearCamera(devices) {
    if (!devices || devices.length === 0) return null;
    // 1. 优先匹配标签含 back/rear/environment 的
    for (var i = 0; i < devices.length; i++) {
        if (isRearCamera(devices[i])) return devices[i];
    }
    // 2. 排除前置摄像头，取第一个非前置的
    for (var j = 0; j < devices.length; j++) {
        if (!isFrontCamera(devices[j])) return devices[j];
    }
    // 3. 兜底取最后一个（移动端后置通常排在后面）
    return devices[devices.length - 1];
}

function isMobile() {
    return /Android|iPhone|iPad|iPod|Mobile|Windows Phone/i.test(navigator.userAgent) || window.innerWidth <= 768;
}

function toggleCamera() {
    if (cameraAvailable === false) {
        showToast('摄像头不可用（需要 HTTPS 或 localhost）', 'warning');
        return;
    }
    if (cameraActive || cameraStarting) {
        stopCamera();
    } else {
        if (!selectedCameraId && videoDevices.length > 1 && !isMobile()) {
            selectCamera();
            return;
        }
        startCamera();
    }
}

/* 关闭摄像头扫描窗口 */
function closeCameraModal() {
    stopCamera();
}

/* 摄像头扫描窗口的显隐 */
function setCameraModalOpen(open) {
    var ov = document.getElementById('cameraModal');
    if (ov) ov.classList.toggle('open', !!open);
}

// 更新摄像头开关按钮的视觉状态（active=true 表示摄像头工作中）
function updateScanCamToggle(active) {
    var btn = document.getElementById('scanCamToggle');
    var txt = document.getElementById('scanCamToggleText');
    if (!btn || !txt) return;
    if (active) {
        btn.classList.add('active');
        txt.textContent = '关闭摄像头';
    } else {
        btn.classList.remove('active');
        txt.textContent = '开启摄像头扫码';
    }
}

function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showToast('浏览器不支持摄像头', 'error');
        return;
    }
    if (typeof Html5Qrcode === 'undefined') {
        var h5url = (window.ScanPageConfig && window.ScanPageConfig.h5qrUrl) || '';
        if (!h5url) { showToast('扫码组件地址缺失，无法开启摄像头', 'error'); return; }
        showToast('正在加载扫码组件…', 'info');
        LCSC.loadScriptOnce(h5url,
            function () { startCamera(); },
            function () { showToast('扫码组件加载失败，请检查网络后重试', 'error'); });
        return;
    }
    var cameraStatus = document.getElementById('cameraStatusText');
    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');

    // 如果正在启动中，先强制停止
    if (cameraStarting) {
        forceStopCamera();
    }

    cameraStarting = true;
    // 先弹窗再请求权限：授权对话框是浏览器级的，弹窗在后面挡着也不影响，
    // 但先弹窗能让用户看到「正在请求权限」的提示而不是页面原地不动
    setCameraModalOpen(true);
    cameraStatus.textContent = '⏳ 正在请求摄像头权限...';
    cameraStatus.style.color = 'var(--text2)';
    dot.className = 'dot warn';
    dot.classList.add('pulse');
    updateScanCamToggle(true);

    // 超时保护：15秒未完成则强制停止
    var timeoutId = setTimeout(function() {
        if (cameraStarting) {
            forceStopCamera();
            showToast('摄像头启动超时，请检查浏览器权限设置', 'error');
        }
    }, 15000);

    var constraints = { video: { facingMode: { ideal: 'environment' } } };
    if (selectedCameraId && selectedCameraId.indexOf('idx_') !== 0) {
        constraints.video = { deviceId: { exact: selectedCameraId } };
    }

    // 第1步：请求摄像头权限（会弹出浏览器权限对话框）
    navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
        stream.getTracks().forEach(function(t) { t.stop(); });
        cameraStatus.textContent = '⏳ 正在启动摄像头...';

        // 第2步：权限已获取，枚举设备获取真实 deviceId
        return navigator.mediaDevices.enumerateDevices();
    }).then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            throw new Error('未检测到摄像头');
        }
        // 用真实 deviceId 更新 selectedCameraId
        if (!selectedCameraId || selectedCameraId.indexOf('idx_') === 0) {
            // 自动选择：移动端优先后置摄像头
            var rear = pickRearCamera(videoDevices);
            selectedCameraId = (rear && rear.deviceId) ? rear.deviceId : videoDevices[0].deviceId;
        } else {
            // 验证已选设备是否还存在
            var found = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
            if (!found) {
                var rear2 = pickRearCamera(videoDevices);
                selectedCameraId = (rear2 && rear2.deviceId) ? rear2.deviceId : videoDevices[0].deviceId;
            }
        }

        document.getElementById('cameraView').innerHTML = '';
        html5QrCode = new Html5Qrcode("cameraView");

        var config = {
            fps: 15,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                // 解码区域 = 正方形（以容器短边为基准），占容器 85%
                // 对应外层灰色识别框，二维码落在灰色区域内均可识别
                // 底层 video 原始分辨率不受此尺寸影响，仅决定解码裁切范围
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var boxSize = Math.floor(minEdge * 0.85);
                return { width: boxSize, height: boxSize };
            },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            },
            // 降低单帧解码失败日志噪音，提升性能
            verbose: false,
        };

        // 第4步：用真实 deviceId 启动扫码
        return html5QrCode.start(selectedCameraId, config, onScanSuccess, onScanFailure);
    }).then(function() {
        clearTimeout(timeoutId);
        cameraStarting = false;
        cameraActive = true;
        // 隐藏占位符，显示视频画面
        var ph = document.getElementById('cameraPlaceholder');
        if (ph) ph.style.display = 'none';
        cameraStatus.textContent = '✓ 摄像头就绪 - 对准条码';
        cameraStatus.style.color = 'var(--green)';
        dot.className = 'dot ok';
        dot.classList.remove('pulse');
        text.textContent = '摄像头工作中';
        text.style.color = 'var(--green)';
        updateScanCamToggle(true);
        // 显示自定义双层取景框
        var overlay = document.getElementById('scanOverlay');
        if (overlay) overlay.classList.add('show');
        // 检测是否支持闪光灯（torch）
        checkTorchSupport();
    }).catch(function(err) {
        clearTimeout(timeoutId);
        cameraStarting = false;
        cameraActive = false;
        var msg = '摄像头启动失败';
        if (err && (err.name || err.message)) {
            var errStr = (err.message || err.name || '').toLowerCase();
            if (errStr.indexOf('notallowed') >= 0 || errStr.indexOf('permission') >= 0) {
                msg = '⚠ 摄像头权限被拒绝，请在浏览器设置中允许';
            } else if (errStr.indexOf('notfound') >= 0 || errStr.indexOf('overconstrained') >= 0) {
                msg = '⚠ 摄像头设备未找到，请重新选择';
                localStorage.removeItem('lcsc_camera_device');
                selectedCameraId = null;
            } else if (errStr.indexOf('notreadable') >= 0) {
                msg = '⚠ 摄像头被其他应用占用';
            } else {
                msg = '⚠ ' + (err.message || err.name || '摄像头错误');
            }
        }
        cameraStatus.textContent = msg;
        cameraStatus.style.color = 'var(--red)';
        dot.className = 'dot off';
        dot.classList.remove('pulse');
        setCameraModalOpen(false);
        updateScanCamToggle(false);
        // 恢复占位符显示
        var phErr = document.getElementById('cameraPlaceholder');
        if (phErr) phErr.style.display = '';
        // 启动失败时隐藏取景框
        var overlayEl = document.getElementById('scanOverlay');
        if (overlayEl) overlayEl.classList.remove('show');
        showToast(msg, 'error');
    });
}

function forceStopCamera() {
    // 强制停止，不管当前状态
    if (html5QrCode) {
        try { html5QrCode.stop().catch(function(){}); } catch(e) {}
    }
    cameraActive = false;
    cameraStarting = false;
    document.getElementById('cameraView').innerHTML = '';
    // 显示占位符，恢复等待连接状态
    var ph = document.getElementById('cameraPlaceholder');
    if (ph) ph.style.display = '';
    setCameraModalOpen(false);
    updateScanCamToggle(false);
    var torchBtn = document.getElementById('torchBtn');
    if (torchBtn) torchBtn.classList.remove('is-on');
    // 隐藏自定义取景框
    var overlay = document.getElementById('scanOverlay');
    if (overlay) overlay.classList.remove('show');
    var dot = document.getElementById('cameraDot');
    dot.className = 'dot off';
    dot.classList.remove('pulse');
    var text2 = document.getElementById('cameraDevText');
    if (selectedCameraId) {
        var chosen = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
        text2.textContent = getShortLabel(chosen);
        text2.style.color = 'var(--green)';
    } else {
        text2.textContent = '摄像头未选择';
        text2.style.color = '';
    }
}

// ── 闪光灯（torch）支持 ──
var torchEnabled = false;
var torchTrack = null;

function checkTorchSupport() {
    var torchBtn = document.getElementById('torchBtn');
    if (!torchBtn || !html5QrCode) return;
    try {
        var videoEl = document.querySelector('#cameraView video');
        if (videoEl && videoEl.srcObject) {
            var tracks = videoEl.srcObject.getVideoTracks();
            if (tracks.length > 0) {
                torchTrack = tracks[0];
                var caps = torchTrack.getCapabilities ? torchTrack.getCapabilities() : {};
                if (caps && caps.torch) {
                    torchBtn.classList.add('is-on');
                    torchEnabled = false;
                    torchBtn.textContent = '🔦 闪光灯';
                } else {
                    torchBtn.classList.remove('is-on');
                }
                return;
            }
        }
    } catch(e) {}
    torchBtn.classList.remove('is-on');
}

function toggleTorch() {
    if (!torchTrack) return;
    try {
        torchEnabled = !torchEnabled;
        torchTrack.applyConstraints({ advanced: [{ torch: torchEnabled }] });
        document.getElementById('torchBtn').textContent = torchEnabled ? '🔦 关闭闪光' : '🔦 闪光灯';
    } catch(e) {
        showToast('闪光灯切换失败', 'error');
    }
}

function stopCamera() {
    forceStopCamera();
}

function onScanSuccess(decodedText, decodedResult) {
    var cameraView = document.getElementById('cameraView');

    deepScanSeq++;
    captureScanning = false;

    // 重置自动扫描失败计数
    autoScanFailCount = 0;

    // 闪光反馈
    cameraView.classList.add('camera-flash');
    setTimeout(function() { cameraView.classList.remove('camera-flash'); }, 300);
    playBeep(880, 0.1, 'sine');

    // 暂停摄像头扫描（防止弹窗期间重复识别）
    pauseCameraScanning();

    handleScanIntent(decodedText, 'camera');
}

// ── 暂停/恢复摄像头扫描（弹窗期间暂停，关闭后恢复） ──
function pauseCameraScanning() {
    if (cameraActive && html5QrCode) {
        try { html5QrCode.pause(); } catch(e) {}
    }
}
function resumeCameraScanning() {
    if (cameraActive && html5QrCode) {
        try { html5QrCode.resume(); } catch(e) {}
    }
}

function onScanFailure(error) {
    // 每帧未识别到条码时递增失败计数
    // 连续失败达到阈值后自动触发深度识别（八种预处理容错）
    autoScanFailCount++;
    if (autoScanFailCount >= AUTO_DEEP_SCAN_THRESHOLD && !deepScanCooldown && !captureScanning && cameraActive) {
        autoDeepScan();
    }
}

// ── 自动深度识别（多轮预处理容错）──
// 当自动扫描连续失败时，自动截取当前视频帧进行八种预处理容错解码
var captureScanning = false;
var autoScanFailCount = 0;           // 自动扫描失败计数
var AUTO_DEEP_SCAN_THRESHOLD = 4;
var deepScanCooldown = false;        // 深度识别冷却标志
var DEEP_SCAN_COOLDOWN_MS = 1200;
var deepScanSeq = 0;

function autoDeepScan() {
    if (captureScanning || !cameraActive || !html5QrCode) return;
    deepScanCooldown = true;
    autoScanFailCount = 0;

    var videoEl = document.querySelector('#cameraView video');
    if (!videoEl || !videoEl.videoWidth) {
        setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
        return;
    }

    captureScanning = true;
    // 不暂停实时扫描：深度识别单次 <0.2 秒，期间保留实时通道，
    // 用户继续微调角度时实时扫描可抢先命中（成功率与响应速度都更好）。
    var myToken = ++deepScanSeq;

    try {
        // 截取当前视频帧到 canvas（使用原始分辨率）
        var canvas = document.createElement('canvas');
        canvas.width = videoEl.videoWidth;
        canvas.height = videoEl.videoHeight;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

        // 惰性变体：第1轮只试原图，失败才按段生成二值化 / 中值滤波变体
        var provider = createVariantProvider(canvas, ctx);

        // 依次尝试每个预处理变体
        tryDecodeVariants(provider, 0, function(decodedText, methodName) {
            captureScanning = false;
            if (myToken !== deepScanSeq) return;
            showToast('✓ 识别成功（通道: ' + methodName + '）', 'success');
            onScanSuccess(decodedText, null);
            deepScanCooldown = false;
        }, function() {
            captureScanning = false;
            // 未识别：实时扫描全程未被暂停，无需恢复；进入冷却等待下一次重试
            setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
        });
    } catch(e) {
        captureScanning = false;
        setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
    }
}

// ── 生成预处理图像变体（惰性、按轮次按需）──
function createVariantProvider(srcCanvas, srcCtx) {
    var names = ['原图', 'OTSU二值化', 'OTSU反转', '低阈值(100)', '低阈值反转', '高阈值(160)', '中值滤波+OTSU', '中值滤波+OTSU反转'];
    var cache = new Array(names.length);
    var w = srcCanvas.width, h = srcCanvas.height;
    var gray = null, otsuThresh = 0;
    var blurReady = false, blurGray = null, blurW = 0, blurH = 0, blurOtsuThresh = 0;
    var genMs = 0;   // 累计生成耗时（供基准页读取）

    function ensureGray() {
        if (gray) return;
        var srcData = srcCtx.getImageData(0, 0, w, h);
        gray = new Uint8ClampedArray(w * h);
        for (var i = 0, j = 0; i < srcData.data.length; i += 4, j++) {
            gray[j] = Math.round(0.299 * srcData.data[i] + 0.587 * srcData.data[i+1] + 0.114 * srcData.data[i+2]);
        }
        otsuThresh = calcOtsuThreshold(gray);
    }

    function ensureBlur() {
        if (blurReady) return;
        ensureGray();
        blurW = w; blurH = h; blurGray = gray;
        if (w > 800) {
            var scale = 800 / w;
            blurW = Math.round(w * scale);
            blurH = Math.round(h * scale);
            blurGray = downscaleGray(gray, w, h, blurW, blurH);
        }
        blurGray = medianBlurGray(blurGray, blurW, blurH, 3);
        blurOtsuThresh = calcOtsuThreshold(blurGray);
        blurReady = true;
    }

    function build(index) {
        switch (index) {
            case 0: return { name: names[0], canvas: srcCanvas, format: 'image/jpeg' };
            case 1: ensureGray(); return { name: names[1], canvas: makeBinaryCanvas(gray, w, h, otsuThresh, false), format: 'image/png' };
            case 2: ensureGray(); return { name: names[2], canvas: makeBinaryCanvas(gray, w, h, otsuThresh, true),  format: 'image/png' };
            case 3: ensureGray(); return { name: names[3], canvas: makeBinaryCanvas(gray, w, h, 100, false), format: 'image/png' };
            case 4: ensureGray(); return { name: names[4], canvas: makeBinaryCanvas(gray, w, h, 100, true),  format: 'image/png' };
            case 5: ensureGray(); return { name: names[5], canvas: makeBinaryCanvas(gray, w, h, 160, false), format: 'image/png' };
            case 6: ensureBlur();  return { name: names[6], canvas: makeBinaryCanvas(blurGray, blurW, blurH, blurOtsuThresh, false), format: 'image/png' };
            case 7: ensureBlur();  return { name: names[7], canvas: makeBinaryCanvas(blurGray, blurW, blurH, blurOtsuThresh, true),  format: 'image/png' };
            default: return null;
        }
    }

    return {
        names: names,
        count: names.length,
        genMs: function() { return genMs; },
        get: function(index) {
            if (index < 0 || index >= names.length) return null;
            if (cache[index]) return cache[index];
            var t0 = performance.now();
            var v = build(index);
            genMs += performance.now() - t0;
            cache[index] = v;
            return v;
        }
    };
}

// OTSU 大津法自动阈值
function calcOtsuThreshold(gray) {
    var histogram = new Array(256).fill(0);
    for (var i = 0; i < gray.length; i++) histogram[gray[i]]++;
    var total = gray.length, sum = 0;
    for (var t = 0; t < 256; t++) sum += t * histogram[t];
    var sumB = 0, wB = 0, maxVar = 0, threshold = 127;
    for (var t = 0; t < 256; t++) {
        wB += histogram[t];
        if (wB === 0) continue;
        var wF = total - wB;
        if (wF === 0) break;
        sumB += t * histogram[t];
        var mB = sumB / wB, mF = (sum - sumB) / wF;
        var betweenVar = wB * wF * (mB - mF) * (mB - mF);
        if (betweenVar > maxVar) { maxVar = betweenVar; threshold = t; }
    }
    return threshold;
}

// 生成二值化 canvas（invert=true 时反转黑白）
function makeBinaryCanvas(gray, w, h, threshold, invert) {
    var canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    var imageData = ctx.createImageData(w, h);
    for (var i = 0, j = 0; i < imageData.data.length; i += 4, j++) {
        var val = gray[j] >= threshold ? 255 : 0;
        if (invert) val = 255 - val;
        imageData.data[i] = val;
        imageData.data[i+1] = val;
        imageData.data[i+2] = val;
        imageData.data[i+3] = 255;
    }
    ctx.putImageData(imageData, 0, 0);
    return canvas;
}

// 灰度图降采样（近邻采样）
function downscaleGray(gray, srcW, srcH, dstW, dstH) {
    var result = new Uint8ClampedArray(dstW * dstH);
    var xRatio = srcW / dstW, yRatio = srcH / dstH;
    for (var y = 0; y < dstH; y++) {
        for (var x = 0; x < dstW; x++) {
            result[y * dstW + x] = gray[Math.floor(y * yRatio) * srcW + Math.floor(x * xRatio)];
        }
    }
    return result;
}

// 3x3 中值滤波（消除打印机断针造成的细白线）
function medianBlurGray(gray, w, h, ksize) {
    var half = Math.floor(ksize / 2);
    var result = new Uint8ClampedArray(gray.length);
    for (var y = 0; y < h; y++) {
        for (var x = 0; x < w; x++) {
            var values = [];
            for (var dy = -half; dy <= half; dy++) {
                for (var dx = -half; dx <= half; dx++) {
                    var nx = x + dx, ny = y + dy;
                    if (nx >= 0 && nx < w && ny >= 0 && ny < h) {
                        values.push(gray[ny * w + nx]);
                    }
                }
            }
            values.sort(function(a, b) { return a - b; });
            result[y * w + x] = values[Math.floor(values.length / 2)];
        }
    }
    return result;
}

function tryDecodeVariants(provider, index, onSuccess, onAllFail) {
    var variant = provider.get(index);
    if (!variant) { onAllFail(); return; }
    var isJpeg = variant.format === 'image/jpeg';
    variant.canvas.toBlob(function(blob) {
        if (!blob) { tryDecodeVariants(provider, index + 1, onSuccess, onAllFail); return; }
        var imageFile = new File([blob], isJpeg ? 'capture.jpg' : 'capture.png', { type: variant.format });
        var tempDiv = document.createElement('div');
        tempDiv.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
        tempDiv.id = 'tempScanDiv_' + Date.now() + '_' + index;
        document.body.appendChild(tempDiv);
        var tempScanner = new Html5Qrcode(tempDiv.id);
        tempScanner.scanFile(imageFile, false).then(function(decodedText) {
            try { tempScanner.clear(); } catch(e) {}
            try { document.body.removeChild(tempDiv); } catch(e) {}
            onSuccess(decodedText, variant.name);
        }).catch(function(err) {
            try { tempScanner.clear(); } catch(e) {}
            try { document.body.removeChild(tempDiv); } catch(e) {}
            tryDecodeVariants(provider, index + 1, onSuccess, onAllFail);
        });
    }, variant.format, isJpeg ? 0.92 : undefined);
}

// ── 防重复扫描（对标商业扫码枪逻辑） ──
// 商业扫码枪特征：同一码高速连续扫只处理一次；不同码可连续扫；
// 同码间隔超过冷却期后允许再次扫描（入库流水线场景）
var recentScans = []; // {key, time, count}
var DUPLICATE_WINDOW = 5000;    // 同码冷却期 5 秒（用户要求：5秒内请勿重复扫码）
var RAPID_REPEAT_MS = 800;      // 800ms 内同码视为"按键抖动/摄像头抖动"，静默忽略
var scanInProgress = false;     // 全局扫描锁：AJAX 期间禁止新扫描

function checkDuplicate(barcode, orderNo, internalId) {
    var key = internalId > 0
        ? ('iid:' + internalId)
        : (orderNo ? (orderNo + ':' + barcode) : barcode);
    var now = Date.now();
    // 清理过期记录
    recentScans = recentScans.filter(function(item) { return now - item.time < DUPLICATE_WINDOW; });
    // 检查是否重复
    for (var i = 0; i < recentScans.length; i++) {
        if (recentScans[i].key === key) {
            var elapsed = now - recentScans[i].time;
            if (elapsed < RAPID_REPEAT_MS) {
                // 快速重复：摄像头抖动或手抖，静默忽略（不弹窗打扰）
                return 'rapid';
            }
            // 冷却期内重复：提示用户
            return 'cooldown';
        }
    }
    recentScans.push({ key: key, time: now, count: 1 });
    return false;
}

// ── 扫码缓存：连续扫同物料不重复查询 ──
var _scanCache = {};
var SCAN_CACHE_TTL = 30000; // 缓存30秒

function getScanCacheKey() {
    var internalId = parseInt(document.getElementById('scanInternalId').value, 10) || 0;
    var barcode = getScanBarcode();
    var orderNo = document.getElementById('scanOrderNo').value;
    return internalId > 0 ? ('iid:' + internalId) : (orderNo ? ('ord:' + orderNo + ':' + barcode) : ('bc:' + barcode));
}

function getScanCacheKeyFromData(scanData) {
    var internalId = parseInt(scanData.internal_id, 10) || 0;
    var barcode = (scanData.barcode || '').trim();
    var orderNo = scanData.order_no || '';
    return internalId > 0 ? ('iid:' + internalId) : (orderNo ? ('ord:' + orderNo + ':' + barcode) : ('bc:' + barcode));
}

function getCachedPart(cacheKey) {
    var cached = _scanCache[cacheKey];
    if (cached && (Date.now() - cached.timestamp) < SCAN_CACHE_TTL) {
        return cached.data;
    }
    delete _scanCache[cacheKey];
    return null;
}

function setCachedPart(cacheKey, data) {
    _scanCache[cacheKey] = { data: data, timestamp: Date.now() };
    // 限制缓存大小（最多50个物料）
    var keys = Object.keys(_scanCache);
    if (keys.length > 50) {
        delete _scanCache[keys[0]]; // 删除最早的
    }
}

function invalidateScanCache(cacheKey) {
    if (cacheKey) {
        delete _scanCache[cacheKey];
    } else {
        _scanCache = {}; // 无指定key时清除全部缓存
    }
}
