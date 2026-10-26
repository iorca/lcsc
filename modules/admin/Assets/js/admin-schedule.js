// modules/admin/Assets/js/admin-schedule.js — 后台管理页（?v=admin）· 定时备份配置、一键升级、清理过期备份、页面初始化


function saveBackupSchedule(btn){
    var modeEl = document.getElementById('scheduleMode');
    var mode = modeEl ? modeEl.value : 'schedule';
    var frequency = document.getElementById('scheduleFrequency').value;
    var keepCount = parseInt(document.getElementById('scheduleKeepCount').value, 10);
    var timeEl = document.getElementById('scheduleTime');
    var backupTime = timeEl ? timeEl.value.trim() : '';
    if (isNaN(keepCount) || keepCount < 1 || keepCount > 30) {
        LCSC.toast('保留份数须为 1-30 的整数', 'warning');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
        return;
    }
    LCSC.post('api.php', {
        action: 'backup.schedule',
        mode: mode,
        frequency: frequency,
        keep_count: keepCount,
        backup_time: backupTime
    }, function(data, msg){
        var due = (data && data.next_due) ? '，下次 ' + data.next_due : '';
        LCSC.toast((msg || '配置已保存') + due, 'success');
        refreshScheduleStatus();
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    }, function(err){
        LCSC.toast(err || '保存失败', 'error');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    });
}

// 刷新「上次自动备份 / 时间窗起点」状态行
function refreshScheduleStatus(){
    var el = document.getElementById('scheduleLastRun');
    if (!el || typeof LCSC.get !== 'function') return;
    LCSC.get('api.php?action=backup.schedule_status', function(d){
        if (!d) return;
        var next = d.next_due || '';
        // 执行方式必须显示：两种模式下「下次」的含义不同（自动 vs 等访问）
        var modeTxt = d.mode === 'on_request' ? '周期内首次请求执行' : '定时备份（到点自动）';
        el.innerHTML =
            '执行方式：<b>' + modeTxt + '</b>' +
            ' · 上次自动备份：<b>' + (d.last_run_at ? d.last_run_at : '尚未执行') + '</b>' +
            ' · 时间窗起点：<b>' + (d.window_start || '—') + '</b>' +
            ' · 自动备份文件：<b>' + (d.auto_file_count || 0) + '</b> 份' +
            (next ? ' · 下次：<b>' + next + '</b>' : '');
    }, function(){ /* 静默失败：状态行非关键信息 */ });
}


// ── 清理过期备份 ──
function cleanupBackup(btn){
    AdminUIConfirm({
        title: '清理过期备份',
        message: '确认清理过期备份文件？\n将根据保留份数配置删除最旧的备份。',
        okText: '开始清理',
        onOk: function(){
            LCSC.post('api.php', {
                action: 'backup.cleanup'
            }, function(data, msg){
                var removed = (data && data.removed) ? '（已清理 ' + data.removed + ' 份）' : '';
                LCSC.toast((msg || '清理完成') + removed, 'success');
                if (typeof bkBtnDone === 'function') bkBtnDone(btn);
                loadBackupFiles();
            }, function(err){
                LCSC.toast(err || '清理失败', 'error');
                if (typeof bkBtnDone === 'function') bkBtnDone(btn);
            });
        }
    });
    if (typeof bkBtnDone === 'function') bkBtnDone(btn);
}

// 页面加载时初始化备份文件列表和API Key列表
(function(){
    if (document.getElementById('backupFilesWrap')) loadBackupFiles();
    if (document.getElementById('apiKeyListWrap')) loadApiKeys();
})();
