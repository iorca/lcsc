// modules/admin/Assets/js/backup.js — 后台「数据备份」Tab 交互脚本
(function(){
    // ── 按钮 loading 辅助：显示 spinner + 忙碌文案，防止重复点击 ──
    function bkBtnBusy(btn, busy){
        if (!btn) return;
        if (busy) {
            if (!btn.getAttribute('data-idle-label')) btn.setAttribute('data-idle-label', btn.innerHTML);
            btn.disabled = true;
            btn.innerHTML = '<span class="bk-spin"></span>' + (btn.getAttribute('data-busy-label') || '处理中...');
        } else {
            btn.disabled = false;
            btn.innerHTML = btn.getAttribute('data-idle-label') || btn.innerHTML;
        }
    }

    // ── 下载备份表单：fetch 接收 SQL → blob 本地下载，全程 loading 状态，
    var bkForm = document.getElementById('backupForm');
    if (bkForm) {
        bkForm.addEventListener('submit', function(e){
            e.preventDefault();
            var btn = bkForm.querySelector('button[type=submit]');
            bkBtnBusy(btn, true);
            var fd = new FormData(bkForm);
            fetch('index.php?v=admin&tab=backup', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            }).then(function(r){
                if (!r.ok) {
                    return r.text().then(function(t){ throw new Error(t && t.indexOf('备份失败') > -1 ? '备份生成失败' : ('HTTP ' + r.status)); });
                }
                var disp = r.headers.get('Content-Disposition') || '';
                var name = 'lcsc_backup_' + new Date().toISOString().slice(0, 10) + '.sql';
                var m = /filename="?([^";]+)"?/.exec(disp);
                if (m) name = m[1];
                var clen = parseInt(r.headers.get('Content-Length') || '0', 10);
                var sizeTxt = clen > 0 ? ('（' + fmtSizeLocal(clen) + '）') : '';
                return r.blob().then(function(blob){
                    var a = document.createElement('a');
                    var url = URL.createObjectURL(blob);
                    a.href = url;
                    a.download = name;
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function(){ URL.revokeObjectURL(url); if (a.parentNode) a.parentNode.removeChild(a); }, 800);
                    if (window.LCSC && LCSC.toast) LCSC.toast('备份已生成并开始下载 ' + sizeTxt, 'success');
                    else if (window.console) console.warn('备份已生成并开始下载：' + name);
                });
            }).catch(function(err){
                if (window.LCSC && LCSC.toast) LCSC.toast('生成备份失败：' + (err.message || '请重试'), 'error');
                else if (window.console) console.warn('生成备份失败：' + (err.message || '请重试'));
            }).finally(function(){
                bkBtnBusy(btn, false);
            });
        });
    }
    function fmtSizeLocal(size){
        size = parseInt(size, 10) || 0;
        if (size >= 1048576) return (size / 1048576).toFixed(2) + ' MB';
        if (size >= 1024) return (size / 1024).toFixed(1) + ' KB';
        return size + ' B';
    }

    // ── 恢复备份表单：二次确认（高危）→ loading → 提交后页面刷新 ──
    var rsForm = document.getElementById('restoreForm');
    if (rsForm) {
        rsForm.addEventListener('submit', function(e){
            e.preventDefault();
            var fileInput = rsForm.querySelector('input[type=file]');
            if (!fileInput || !fileInput.value) {
                LCSC.toast('请先选择要恢复的 .sql 备份文件', 'warning');
                return;
            }
            AdminUIConfirm({
                title: '恢复备份',
                message: '⚠️ 高危操作确认\n\n恢复会覆盖全部现有业务数据，操作不可撤销！\n\n请确认已创建最新备份。\n\n是否继续恢复？',
                okText: '确认恢复',
                onOk: function(){
                    var btn = rsForm.querySelector('button[type=submit]');
                    bkBtnBusy(btn, true);
                    rsForm.submit();
                }
            });
        });
    }

    // ── 按钮 onclick 与 loading 绑定（耗时后端操作统一防重复点击） ──
    function bindBusy(id, fn){
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('click', function(){
            if (el.disabled) return;
            bkBtnBusy(el, true);
            try { fn(el); } catch (err) { bkBtnBusy(el, false); }
        });
    }
    window.bkBtnBusy = bkBtnBusy;
    window.bkBtnDone = function(btn){ bkBtnBusy(btn, false); };
    // 兼容：无按钮引用时按 ID 释放
    window.bkBtnDoneById = function(id){ bkBtnBusy(document.getElementById(id), false); };

    bindBusy('oneClickBackupBtn', function(btn){ oneClickBackup(btn); });
    bindBusy('webdavSaveBtn',     function(btn){ saveWebdavConfig(btn); });
    bindBusy('webdavUploadBtn',   function(btn){ backupWebdav(btn); });
    bindBusy('apiKeyCreateBtn',   function(btn){ createApiKey(btn); });
    bindBusy('scheduleSaveBtn',   function(btn){ saveBackupSchedule(btn); });
    bindBusy('backupCleanupBtn',  function(btn){ cleanupBackup(btn); });
})();
