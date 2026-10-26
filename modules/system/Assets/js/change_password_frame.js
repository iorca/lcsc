(function () {
    if (window.self !== window.top) {
        window.top.location.href = location.href;
    }
})();
