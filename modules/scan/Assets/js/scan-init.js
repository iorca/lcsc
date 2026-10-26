// modules/scan/Assets/js/scan-init.js — 扫码出入库页 · 启动与输入

document.getElementById('barcodeInput').addEventListener('keydown', function(e) {
    // 扫码枪验证模式：任何按键输入都算验证成功
    if (scannerVerifyListening && e.key !== 'Enter' && e.key.length === 1) {
        onScannerVerifyInput();
    }
    // 回车提交
    if (e.key === 'Enter') {
        e.preventDefault();
        var val = this.value.trim();
        if (val === '') return;
        if (scannerVerifyListening) {
            onScannerVerifyInput();
        }
        this.value = '';
        handleScanIntent(val, 'scanner');
        var keep = document.getElementById('barcodeInput');
        if (keep && !cameraActive) keep.focus();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'F3') { e.preventDefault(); toggleCamera(); }
});

(function() {
    initScanner();
    initCamera();

    var input = document.getElementById('barcodeInput');
    input.value = '';

    // 「入库前确认」勾选态：默认开启，用户取消后记忆
    var confirmBox = document.getElementById('confirmScanIn');
    if (confirmBox) {
        confirmBox.checked = isConfirmEnabled();
        confirmBox.addEventListener('change', function() {
            setConfirmEnabled(this.checked);
            if (this.checked) {
                LCSC.toast('已开启：扫到物料后先确认再入库', 'info');
            } else {
                LCSC.toast('已关闭：扫到即入库，取消请及时撤销', 'warning');
            }
        });
    }

    // 连续扫码 / 入库不计成本：勾选态记忆；「不计成本」仅在连续扫码开启时可用
    var contBox = document.getElementById('continuousMode');
    if (contBox) {
        contBox.checked = isContinuous();
        contBox.addEventListener('change', function() {
            setContinuous(this.checked);
            syncSkipCostControl();
            if (this.checked) {
                showToast('已开启连续扫码：扫到即记账，不再逐条弹窗', 'info');
            } else {
                scanSession.entries = [];
                showToast('已关闭连续扫码', 'info');
            }
            renderScanSession();
        });
    }
    var skipBox = document.getElementById('skipCostMode');
    if (skipBox) {
        skipBox.checked = isSkipCost();
        skipBox.addEventListener('change', function() {
            setSkipCost(this.checked);
            showToast(this.checked ? '已开启：入库不计成本（该笔不进加权平均成本）' : '已关闭：入库需填单价', this.checked ? 'warning' : 'info');
        });
    }
    syncSkipCostControl();
    renderScanSession();

    // 扫码枪需要输入框保持焦点（摄像头开启时让位给相机）
    document.addEventListener('click', function(e) {
        if (cameraActive) return;
        var tag = e.target.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'BUTTON' && tag !== 'TEXTAREA' && tag !== 'LABEL') {
            input.focus();
        }
    });

    // 摄像头扫描窗口：关窗必须停摄像头。
    var camModal = document.getElementById('cameraModal');
    if (camModal) {
        new MutationObserver(function() {
            if (!camModal.classList.contains('open') && (cameraActive || cameraStarting)) {
                forceStopCamera();
            }
        }).observe(camModal, { attributes: true, attributeFilter: ['class'] });
    }

    // 弹窗点击遮罩可手动关闭（也会自动关闭）
    document.getElementById('scanResultModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeScanResultModal();
        }
    });

    // 页面离开时清理
    window.addEventListener('beforeunload', function() {
        if (cameraActive && html5QrCode) {
            html5QrCode.stop().catch(function(){});
        }
        if (scannerVerifyTimer) clearInterval(scannerVerifyTimer);
        if (toastTimer) clearTimeout(toastTimer);
    });

    // 初始化扫描记录翻页
    initScanPagination();
})();

var currentScanPage = 1;
function initScanPagination() {
    var rows = document.querySelectorAll('.scan-row');
    if (rows.length === 0) return;
    showScanPage(1);
}

function showScanPage(page) {
    var rows = document.querySelectorAll('.scan-row');
    var totalRows = rows.length;
    var totalPages = Math.ceil(totalRows / 10);
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    currentScanPage = page;

    for (var i = 0; i < rows.length; i++) {
        var rowPage = parseInt(rows[i].getAttribute('data-page'));
        rows[i].style.display = (rowPage === page) ? '' : 'none';
    }

    // 更新分页按钮状态
    var pageBtns = document.querySelectorAll('#scanPagination [data-scan-page]');
    for (var j = 0; j < pageBtns.length; j++) {
        pageBtns[j].classList.toggle('active', parseInt(pageBtns[j].getAttribute('data-scan-page')) === page);
    }

    var prevBtn = document.getElementById('scanPrevBtn');
    var nextBtn = document.getElementById('scanNextBtn');
    if (prevBtn) prevBtn.classList.toggle('disabled', page <= 1);
    if (nextBtn) nextBtn.classList.toggle('disabled', page >= totalPages);
}

function goToScanPage(page) {
    showScanPage(page);
}

function changeScanPage(delta) {
    showScanPage(currentScanPage + delta);
}

function scanPageJump(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if (raw === '') return;
    var p = parseInt(raw, 10);
    var rows = document.querySelectorAll('.scan-row');
    var totalPages = Math.max(1, Math.ceil(rows.length / 10));
    if (isNaN(p) || p < 1) { e.target.value = ''; LCSC.toast('请输入有效页码', 'warning'); return; }
    if (p > totalPages) p = totalPages;
    e.target.value = p;
    goToScanPage(p);
}
