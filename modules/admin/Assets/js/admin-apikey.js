// modules/admin/Assets/js/admin-apikey.js — 后台管理页（?v=admin）· API Key 管理

function tplResetBuiltin(){
    AdminUIConfirm({
        title: '重置为内置模板',
        message: '将丢弃你对该平台模板的手动修改，恢复为系统预置（立创商城）模板。是否继续？',
        okText: '确认重置',
        onOk: function(){
            LCSC.post('app/api/router.php?action=import.template_reset', { platform_id: TplState.platformId }, function(data, msg){
                LCSC.toast(msg || '已重置为内置模板', 'success');
                tplClose();
                setTimeout(function(){ location.reload(); }, 600);
            }, function(msg){ LCSC.toast(String(msg), 'error'); });
        }
    });
}

// 点击蒙层关闭模板弹窗（与全站弹窗行为一致）
document.addEventListener('click', function(e){
    var ov = e.target;
    if (ov && ov.classList && ov.classList.contains('overlay') && ov.id === 'tplModal') tplClose();
});

// ── WebDAV 配置保存（V2.0）──
function saveWebdavConfig(btn){
    var url = document.getElementById('webdavUrl').value.trim();
    var user = document.getElementById('webdavUser').value.trim();
    var pass = document.getElementById('webdavPass').value;
    var path = document.getElementById('webdavPath') ? document.getElementById('webdavPath').value.trim() : '';
    // 地址 / 用户名输入框留空 = 沿用已保存配置（页面只回显掩码，不回显明文）
    var payload = {action: 'backup.webdav_save', webdav_url: url, webdav_user: user, webdav_path: path};
    // 密码框留空 = 不修改（服务端按缺省处理）；已填则提交新值
    if (pass !== '') payload.webdav_pass = pass;
    LCSC.post('api.php', payload, function(data, msg){
        LCSC.toast('WebDAV 配置已保存', 'success');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
        // 保存后立即测连通性；成功则弹出远端目录内容（失败只提示，不回滚已保存配置）
        testWebdavConnection();
    }, function(err){
        LCSC.toast(err || '保存失败', 'error');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    });
}

// ── WebDAV 连通性测试与远端目录弹窗 ──
function testWebdavConnection(){
    LCSC.post('api.php', {action: 'backup.webdav_test'}, function(data){
        renderWebdavTest(data || {});
    }, function(err){
        LCSC.toast('配置已保存，但连接测试失败：' + (err || '未知错误'), 'error');
    });
}

function renderWebdavTest(d){
    var ov = document.getElementById('webdavTestOverlay');
    if (!ov) return;
    var meta = document.getElementById('wdTestMeta');
    var list = document.getElementById('wdTestList');
    var entries = d.entries || [];

    if (meta) {
        meta.textContent = '备份目录：' + (d.path || '—') + ' · ' + (typeof d.count === 'number' ? d.count : entries.length) + ' 项';
    }
    if (list) {
        if (!entries.length) {
            list.innerHTML = '<div class="wd-empty">目录为空，备份将存放在这里</div>';
        } else {
            list.innerHTML = entries.map(function(e){
                var isDir = e.type === 'dir';
                var size  = isDir ? '文件夹' : (typeof fmtSizeAdmin === 'function' ? fmtSizeAdmin(e.size) : String(e.size || 0) + ' B');
                var time  = '';
                if (e.modified) {
                    var dt = new Date(e.modified);
                    if (!isNaN(dt.getTime())) {
                        time = dt.toLocaleString('zh-CN', {hour12: false});
                    }
                }
                var name = typeof escAdmin === 'function' ? escAdmin(e.name) : String(e.name || '');
                return '<div class="wd-row">'
                    + '<span class="wd-ico" aria-hidden="true">' + (isDir ? '📁' : '📄') + '</span>'
                    + '<span class="wd-name" title="' + name + '">' + name + '</span>'
                    + '<span class="wd-meta">' + size + (time ? ' · ' + time : '') + '</span>'
                    + '</div>';
            }).join('');
        }
    }
    ov.classList.remove('is-hidden');
}

function closeWebdavTest(){
    var ov = document.getElementById('webdavTestOverlay');
    if (ov) ov.classList.add('is-hidden');
}
document.addEventListener('click', function(e){
    if (e.target && e.target.id === 'webdavTestOverlay') closeWebdavTest();
});
// 反馈统一走全站顶部 toast
function backupWebdav(btn){
    LCSC.toast('上传中，请稍候...', 'info');
    LCSC.post('api.php', {action: 'backup.webdav'}, function(data, msg){
        LCSC.toast(msg || '上传成功', 'success');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
        loadBackupFiles();
    }, function(err){
        LCSC.toast(err || '上传失败', 'error');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    });
}

// ── API Key 管理（V2.0）──
var API_KEY_SCOPES = [
    { val: 'read_part',       name: '读取物料',  desc: '物料信息/分类/BOM 查询' },
    { val: 'read_stock',      name: '读取库存',  desc: '库存流水/统计/警告查询' },
    { val: 'write_stock',     name: '修改库存',  desc: '出入库/扫码操作' },
    { val: 'manage_category', name: '分类管理',  desc: '分类增删改' },
    { val: 'manage_platform', name: '平台管理',  desc: '平台增删改' },
    { val: 'manage_api',      name: 'Key 管理',  desc: 'API Key 增删查' },
    { val: 'admin',           name: '管理员',    desc: '备份/升级等高危操作' }
];

// ── 权限范围选择弹窗 ──
function openScopePicker() {
    var hidden = document.getElementById('apiKeyScope');
    var current = (hidden ? hidden.value : '').split(',').filter(Boolean);
    var list = document.getElementById('scopePickerList');
    if (!list) return;
    list.innerHTML = API_KEY_SCOPES.map(function(s) {
        var checked = current.indexOf(s.val) !== -1 ? ' checked' : '';
        return '<label class="bks-item">' +
            '<input type="checkbox" value="' + s.val + '"' + checked + '>' +
            '<span class="bks-name">' + escAdmin(s.name) + '</span>' +
            '<span class="bks-val">' + escAdmin(s.val) + '</span>' +
            '<span class="bks-desc">' + escAdmin(s.desc) + '</span>' +
            '</label>';
    }).join('');
    var overlay = document.getElementById('scopePickerOverlay');
    if (overlay) overlay.classList.remove('is-hidden');
}
function closeScopePicker() {
    var overlay = document.getElementById('scopePickerOverlay');
    if (overlay) overlay.classList.add('is-hidden');
}
function confirmScopePicker() {
    var checked = [];
    document.querySelectorAll('#scopePickerList input[type=checkbox]:checked').forEach(function(cb) {
        checked.push(cb.value);
    });
    var hidden = document.getElementById('apiKeyScope');
    var textEl = document.getElementById('apiKeyScopeText');
    if (hidden) hidden.value = checked.join(',');
    if (textEl) {
        if (checked.length === 0) {
            textEl.textContent = '无权限';
            textEl.style.color = 'var(--text3)';
        } else {
            textEl.textContent = checked.map(function(v) {
                var s = API_KEY_SCOPES.find(function(x){ return x.val === v; });
                return s ? s.name : v;
            }).join('、');
            textEl.style.color = '';
        }
    }
    closeScopePicker();
}
// 点击遮罩关闭弹窗
document.addEventListener('click', function(e) {
    var overlay = document.getElementById('scopePickerOverlay');
    if (overlay && e.target === overlay) closeScopePicker();
});

function createApiKey(btn){
    var name = document.getElementById('apiKeyName').value.trim();
    if (!name) { LCSC.toast('请输入 Key 名称', 'warning'); if (typeof bkBtnDone === 'function') bkBtnDone(btn); return; }
    // 权限范围：读取隐藏域（由权限范围选择弹窗写入），空值回退默认只读权限
    var scopeHidden = document.getElementById('apiKeyScope');
    var scope = (scopeHidden && scopeHidden.value) ? scopeHidden.value : 'read_stock,read_part';
    // 有效期：读创建表单的 select（0 = 永不过期）
    var expSel = document.getElementById('apiKeyExpire');
    var expiresDays = expSel ? expSel.value : '0';
    LCSC.post('app/api/router.php', {action: 'apikey.create', name: name, scope: scope, expires_days: expiresDays}, function(data){
        showApiKeyResult(data || {});
        document.getElementById('apiKeyName').value = '';
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
        loadApiKeys();
    }, function(err){
        LCSC.toast(err || '生成失败', 'error');
        if (typeof bkBtnDone === 'function') bkBtnDone(btn);
    });
}

// ── 生成结果弹窗（替代原生 alert：居中、带密钥一键复制）──
function showApiKeyResult(data){
    var overlay = document.getElementById('apiKeyShowOverlay');
    if (!overlay) return;                     // 视图未渲染（非主管理员）时静默降级为 toast
    var key = data.api_key || '';
    var scopeText = data.scope
        ? String(data.scope).split(',').filter(Boolean).map(function(v){
              var s = API_KEY_SCOPES.find(function(x){ return x.val === v.trim(); });
              return s ? s.name : v.trim();
          }).join('、')
        : '—';
    document.getElementById('apiKeyShowValue').textContent = key;
    document.getElementById('akShowScope').textContent = scopeText;
    document.getElementById('akShowExpire').textContent = data.expires_at ? data.expires_at : '永不过期';
    var copyBtn = document.getElementById('apiKeyCopyBtn');
    if (copyBtn) { copyBtn.disabled = false; copyBtn.textContent = '复制'; }
    overlay.classList.remove('is-hidden');
    if (copyBtn) copyBtn.focus();
}
function closeApiKeyShow(){
    var overlay = document.getElementById('apiKeyShowOverlay');
    if (overlay) overlay.classList.add('is-hidden');
    // 密钥只在弹窗内留存，关闭即清空（页面别处不留明文副本）
    var val = document.getElementById('apiKeyShowValue');
    if (val) val.textContent = '';
}
function copyApiKey(btn){
    var val = document.getElementById('apiKeyShowValue');
    var text = val ? val.textContent.trim() : '';
    if (!text) return;
    var ok = function(){
        LCSC.toast('API Key 已复制到剪贴板', 'success');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '已复制 ✓';
            setTimeout(function(){
                if (document.body.contains(btn)) { btn.disabled = false; btn.textContent = '复制'; }
            }, 2000);
        }
    };
    var fallback = function(){
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', 'readonly');
            ta.className = 'ak-ta-fallback';
            document.body.appendChild(ta);
            ta.select();
            var done = document.execCommand('copy');
            ta.remove();
            if (done) ok(); else LCSC.toast('复制失败，请手动选中密钥复制', 'error');
        } catch (e) {
            LCSC.toast('复制失败，请手动选中密钥复制', 'error');
        }
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok).catch(fallback);
    } else {
        fallback();
    }
}
document.addEventListener('click', function(e){
    if (e.target && e.target.id === 'apiKeyShowOverlay') closeApiKeyShow();
});
document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    var overlay = document.getElementById('apiKeyShowOverlay');
    if (overlay && !overlay.classList.contains('is-hidden')) closeApiKeyShow();
});
// 行内调整有效期（只改 expires_at，不动密钥值——集成无需重新对接）
// 语义：从**当前时刻**重新起算 N 天；改后服务端重算列表并回显
function updateApiKeyExpiry(id, days){
    if (days === '') return;
    LCSC.post('app/api/router.php', {action: 'apikey.update_expiry', id: id, expires_days: days}, function(data, msg){
        LCSC.toast(msg || '有效期已更新', 'success');
        loadApiKeys();
    }, function(err){
        LCSC.toast(err || '更新失败', 'error');
        loadApiKeys();
    });
}
function deleteApiKey(id, btn){
    AdminUIConfirm({
        title: '删除 API Key',
        message: '确认删除该 API Key？\n删除后使用该 Key 的集成将无法访问。',
        okText: '确认删除',
        onOk: function(){
            if (btn && typeof bkBtnBusy === 'function') bkBtnBusy(btn, true);
            LCSC.post('app/api/router.php', {action: 'apikey.delete', id: id}, function(data, msg){
                LCSC.toast(msg || '已删除', 'success');
                loadApiKeys();
            }, function(err){
                LCSC.toast(err || '删除失败', 'error');
                if (btn && typeof bkBtnDone === 'function') bkBtnDone(btn);
            });
        }
    });
}
function loadApiKeys(){
    var wrap = document.getElementById('apiKeyListWrap');
    if (!wrap) return;
    LCSC.post('app/api/router.php', {action: 'apikey.list'}, function(data){
        var keys = data.keys || [];
        if (keys.length === 0) {
            wrap.innerHTML = '<div class="bk-empty">' +
                '<div class="bk-empty-icon">🔑</div>' +
                '<div class="bk-empty-title">暂无 API Key</div>' +
                '<div class="bk-empty-text">填写名称与权限范围后，点击「生成 Key」创建</div>' +
                '</div>';
            return;
        }
        var html = '<div class="table-wrap"><table><thead><tr>'
            + '<th>名称</th><th>API Key（掩码）</th><th>权限</th><th>有效期</th><th>最近使用</th><th>来源IP</th><th>创建时间</th><th class="bk-w-key-ops">操作</th>'
            + '</tr></thead><tbody>';
        keys.forEach(function(k){
            var masked = k.api_key_mask || '—';
            // 权限范围转中文名展示（未知值原样显示）
            var scopeText = (k.scope || 'read_stock,read_part').split(',').filter(Boolean).map(function(v) {
                var s = API_KEY_SCOPES.find(function(x){ return x.val === v.trim(); });
                return s ? s.name : v.trim();
            }).join('、');
            var expText;
            if (!k.expires_at) {
                expText = '永不过期';
            } else {
                expText = escAdmin(k.expires_at.substring(0,16));
                if (k.is_expired) expText = '<span class="badge-red">已过期</span> ' + expText;
            }
            // 行内调整：从当前时刻重新起算；改后服务端刷新整行
            var expSel = '<select onchange="updateApiKeyExpiry(' + k.id + ', this.value)" aria-label="调整有效期">'
                + '<option value="" selected disabled>调整有效期…</option>'
                + '<option value="0">永不过期</option>'
                + '<option value="30">30 天</option>'
                + '<option value="90">90 天</option>'
                + '<option value="365">365 天</option>'
                + '</select>';
            html += '<tr>'
                + '<td class="bk-key-name">' + escAdmin(k.name) + '</td>'
                + '<td class="bk-key-token">' + escAdmin(masked) + '</td>'
                + '<td class="bk-key-scope">' + escAdmin(scopeText) + '</td>'
                + '<td class="bk-key-time">' + expText + '<br>' + expSel + '</td>'
                + '<td class="bk-key-time">' + (k.last_used ? escAdmin(k.last_used.substring(0,16)) : '—') + '</td>'
                + '<td class="bk-key-time">' + (k.last_used_ip ? escAdmin(k.last_used_ip) : '—') + '</td>'
                + '<td class="bk-key-time">' + escAdmin((k.created_at || '').substring(0,16)) + '</td>'
                + '<td><button type="button" class="btn btn-danger btn-xs" onclick="deleteApiKey(' + k.id + ', this)">删除</button></td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    });
}
