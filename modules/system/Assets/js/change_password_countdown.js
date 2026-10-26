(function () {
    var cd = 3;
    var timer = setInterval(function () {
        cd--;
        var el = document.getElementById('countdown');
        if (el) el.textContent = cd + ' 秒后自动跳转库存页...';
        if (cd <= 0) {
            clearInterval(timer);
            (window._iframeNavigate || function (u) { window.location.href = u; })('index.php');
        }
    }, 1000);
})();
