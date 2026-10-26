// ── 生成二维码（系统标识）──
document.querySelectorAll('.label-qr').forEach(function(container) {
    var value = container.getAttribute('data-value');
    try {
        var qr = qrcode(0, 'M');
        qr.addData(value);
        qr.make();
        container.innerHTML = qr.createSvgTag(4, 0);
    } catch (e) {
        container.innerHTML = '<div class="label-qr-fail">QR生成失败</div>';
    }
});
