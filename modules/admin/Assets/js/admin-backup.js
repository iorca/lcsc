// modules/admin/Assets/js/admin-backup.js — 后台管理页（?v=admin）· 一键备份、备份文件、WebDAV、恢复


function oneClickBackup(btn){
    LCSC.post('api.php', {action: 'backup.database'}, function(data, msg){
        LCSC.toast((msg || '备份成功') + ' (' + fmtSizeAdmin(data.size || 0) + ')', 'success');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
        loadBackupFiles();
    }, function(err){
        LCSC.toast(err || '备份失败', 'error');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    });
}

// ── 加载备份文件列表（V1.5）──
function loadBackupFiles(){
    var wrap = document.getElementById('backupFilesWrap');
    if (!wrap) return;
    LCSC.post('api.php', {action: 'backup.list'}, function(data){
        var files = data.files || [];
        if (files.length === 0) {
            wrap.innerHTML = '<div class="bk-empty">' +
                '<div class="bk-empty-icon">📦</div>' +
                '<div class="bk-empty-title">暂无服务器备份</div>' +
                '<div class="bk-empty-text">点击上方「一键备份到服务器」创建第一个备份</div>' +
                '</div>';
            return;
        }
        var html = '<div class="table-wrap"><table><thead><tr>'
            + '<th>文件名</th><th class="bk-w-file-size">大小</th><th class="bk-w-file-time">时间</th><th class="bk-w-file-ops">操作</th>'
            + '</tr></thead><tbody>';
        files.forEach(function(f){
            html += '<tr>'
                + '<td class="bk-file-name">' + escAdmin(f.filename) + '</td>'
                + '<td class="bk-file-meta">' + fmtSizeAdmin(f.size) + '</td>'
                + '<td class="bk-file-meta">' + escAdmin(f.time) + '</td>' +
                '<td class="bk-file-ops">'
                + '<button type="button" class="btn btn-ghost btn-xs" data-busy-label="恢复中..." onclick="restoreFromServer(\'' + escAdmin(f.filename) + '\', this)">恢复</button>'
                + '<button type="button" class="btn btn-danger btn-xs" onclick="deleteBackupFile(\'' + escAdmin(f.filename) + '\', this)">删除</button>'
                + '</td></tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    });
}
function restoreFromServer(filename, btn){
    AdminUIConfirm({
        title: '恢复备份（高危）',
        message: '恢复会覆盖全部现有业务数据，操作不可撤销！\n\n备份文件：' + filename + '\n\n是否继续恢复？',
        okText: '继续恢复',
        onOk: function(){
            if (btn && typeof bkBtnBusy === 'function') bkBtnBusy(btn, true);
            LCSC.post('api.php', {action: 'backup.restore', filename: filename}, function(data, msg){
                LCSC.toast(msg || '恢复成功', 'success');
                if (btn) restoreBtnDone(btn);
                // 恢复覆盖全部业务数据，本页数据已过期：刷新备份文件列表
                loadBackupFiles();
            }, function(err){
                LCSC.toast(err || '恢复失败', 'error');
                if (btn) restoreBtnDone(btn);
            });
        }
    });
}
function restoreBtnDone(btn){
    if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    else { btn.disabled = false; }
}

// 删除单个服务器备份（仅主管理员；后端只接受系统生成的备份文件名）
function deleteBackupFile(filename, btn){
    AdminUIConfirm({
        title: '删除备份',
        message: '确认删除备份文件？\n\n' + filename + '\n\n删除后不可恢复，只影响服务器 backups/ 目录中的这一份文件。',
        okText: '确认删除',
        onOk: function(){
            if (btn) btn.disabled = true;
            LCSC.post('api.php', {action: 'backup.delete', filename: filename}, function(data, msg){
                LCSC.toast(msg || '备份已删除', 'success');
                loadBackupFiles();
            }, function(err){
                if (btn) btn.disabled = false;
                LCSC.toast(err || '删除失败', 'error');
            });
        }
    });
}
