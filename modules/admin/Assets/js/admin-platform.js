// modules/admin/Assets/js/admin-platform.js — 后台管理页（?v=admin）· 平台与子用户权限编辑、版本检测、成本重建

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.plat-edit-btn');
    if (!btn) return;
    e.preventDefault();
    var id   = btn.getAttribute('data-id');
    var code = btn.getAttribute('data-code');
    var name = btn.getAttribute('data-name');
    var url  = btn.getAttribute('data-url');
    var ptype = btn.getAttribute('data-type') || 'standard';
    editPlatform(id, code, name, url, ptype);
});

// ── 删除/重置类表单确认（平台 / 用户 / 子用户）──
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.plat-delete-form, .user-delete-form, .sub-delete-form, .user-reset-pw-form, .sub-reset-pw-form');
    if (!form) return;
    // 一律阻止原生提交与后续监听器，确认后再以 AJAX 方式提交
    e.preventDefault();
    e.stopImmediatePropagation();
    var title, tip, okText;
    if (form.classList.contains('plat-delete-form')) {
        var name = form.getAttribute('data-name') || '此平台';
        title = '删除平台';
        okText = '确认删除';
        tip = '确认删除平台「' + name + '」？\n\n该平台下的所有元件数据将一并删除，不可恢复！\n如需保留数据，请先导出。';
    } else if (form.classList.contains('user-delete-form')) {
        var uname = form.getAttribute('data-username') || '此用户';
        title = '删除用户';
        okText = '确认删除';
        tip = '删除用户「' + uname + '」及其所有数据？\n此操作不可撤销！';
    } else if (form.classList.contains('user-reset-pw-form')) {
        var rname = form.getAttribute('data-username');
        title = '重置密码';
        okText = '确认重置';
        tip = rname
            ? '确认重置用户「' + rname + '」的密码？\n新密码将随机生成，请通知用户及时修改。'
            : '确认重置该用户密码？\n新密码将随机生成。';
    } else if (form.classList.contains('sub-reset-pw-form')) {
        var sname2 = form.getAttribute('data-username') || '此子用户';
        title = '重置密码';
        okText = '确认重置';
        tip = '确认重置子用户「' + sname2 + '」的密码？\n新密码将随机生成。';
    } else {
        var sname = form.getAttribute('data-username') || '此子用户';
        title = '删除子用户';
        okText = '确认删除';
        tip = '确认删除子用户「' + sname + '」？';
    }
    // 统一 AJAX 提交（确认后调用；兜底路径也复用）
    function submitDeleteForm(form) {
        var fd = new FormData(form);
        LCSC.post(form.getAttribute('action'), fd, function(data, msg) {
            LCSC.toast(msg || '操作成功', 'success');
            location.reload();
        });
    }
    try {
        AdminUIConfirm({
            title: title,
            message: tip,
            okText: okText,
            onOk: function() {
                submitDeleteForm(form);
            }
        });
    } catch (err) {
        // 弹窗异常兜底：直接按 AJAX 提交，保证操作不静默失效
        console.error('AdminUIConfirm failed:', err);
        submitDeleteForm(form);
    }
}, true);

// ── 子用户权限编辑 ──
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.sub-perm-btn');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-id');
    var username = btn.getAttribute('data-username');
    var perms = JSON.parse(btn.getAttribute('data-perms') || '{}');
    editSubPerms(id, username, perms);
});

// ── 子用户权限弹窗 ──
function editSubPerms(id, name, perms) {
    document.getElementById('subPermUid').value = id;
    document.getElementById('subPermName').textContent = name;
    // 重置所有复选框
    document.querySelectorAll('#subPermForm input[type=checkbox]').forEach(function(cb){ cb.checked = false; });
    // 勾选已有权限
    perms.forEach(function(p){
        var cb = document.getElementById('sp_' + p);
        if (cb) cb.checked = true;
    });
    document.getElementById('subPermModal').classList.add('open');
}
function closeSubPermModal() {
    document.getElementById('subPermModal').classList.remove('open');
}
// 点击遮罩关闭
(function(){
    var modal = document.getElementById('subPermModal');
    if (modal) {
        modal.addEventListener('click', function(e){
            if (e.target === this) closeSubPermModal();
        });
    }
})();

// ── 平台编辑函数 ──
function editPlatform(id, code, name, url, ptype) {
    document.getElementById('platAction').value = 'platform.edit';
    document.getElementById('platId').value = id;
    document.getElementById('platCode').value = code;
    document.getElementById('platName').value = name;
    document.getElementById('platUrl').value = url;
    document.getElementById('platType').value = ptype || 'standard';
    onPlatTypeChange();
    document.getElementById('platFormTitle').textContent = '编辑平台：' + name;
    document.getElementById('platSubmitBtn').textContent = '保存修改';
    document.getElementById('platForm').scrollIntoView({ behavior: 'smooth' });
}

function onPlatTypeChange() {
    var ptype = document.getElementById('platType');
    if (!ptype) return;
    var urlGroup = document.getElementById('platUrlGroup');
    if (ptype.tagName === 'SELECT' && urlGroup && !window.__platJsBound) {
        urlGroup.style.display = (ptype.value === 'loose') ? 'none' : '';
    }
}

function cancelEditPlatform() {
    document.getElementById('platAction').value = 'platform.add';
    document.getElementById('platId').value = '';
    document.getElementById('platCode').value = '';
    document.getElementById('platName').value = '';
    document.getElementById('platUrl').value = '';
    document.getElementById('platType').value = 'standard';
    onPlatTypeChange();
    document.getElementById('platFormTitle').textContent = '添加新平台';
    document.getElementById('platSubmitBtn').textContent = '添加平台';
}

// ── 移动端更多菜单关闭 ──
document.addEventListener('click', function(e) {
    var menu = document.getElementById('moreMenu');
    var more = document.getElementById('navMore');
    if (menu && menu.classList.contains('show') && more && !more.contains(e.target)) {
        menu.classList.remove('show');
    }
});

// ── 系统监控：登录记录批量操作 ──

// ── 版本校验：手动检测 ──
// 反馈统一走全站顶部 toast
function checkVersionManual(){
    LCSC.toast('正在检测（并行测速 Gitee + GitHub）...', 'info');
    fetch('detail_ajax.php?version_check=1', {cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(j){
            // 标准响应格式 {code, msg, data}
            if (j.code !== 0){
                LCSC.toast(j.msg || '检测失败', 'warning');
                return;
            }
            var d = j.data || {};
            if (!d.ok){
                LCSC.toast(d.error || '检测失败（出站环境不可用？）', 'warning');
                return;
            }
            if (d.has_update){
                LCSC.toast('发现新版本：' + d.remote + '（当前 ' + d.current + '）', 'success');
                showVersionModal(d);
            } else {
                LCSC.toast('已是最新版本（' + d.current + '）', 'success');
            }
        })
        .catch(function(e){
            LCSC.toast('检测失败：' + e.message, 'error');
        });
}
function showVersionModal(d){
    document.getElementById('vModalTitle').textContent = '🔄 有新版本更新 ' + (d.remote || '');
    document.getElementById('vCur').textContent = d.current || '—';
    document.getElementById('vNew').textContent = d.remote || '—';
    document.getElementById('vSource').textContent = d.source ? '（源: ' + d.source + '）' : '';
    var cl = document.getElementById('vChangelogContent');
    if (d.changelog){
        cl.textContent = d.changelog;
    } else {
        cl.textContent = '暂无更新日志';
    }
    var btn = document.getElementById('vReleaseBtn');
    if (d.release_url){
        btn.href = d.release_url;
        btn.style.display = '';
    } else {
        btn.style.display = 'none';
    }
    // 在线升级按钮（P2）：只在弹窗出现时绑定一次
    var up = document.getElementById('vUpgradeBtn');
    if (up && !up._bound){
        up._bound = true;
        up.addEventListener('click', upgradeOnline);
    }
    var start = document.getElementById('vUpgradeStart');
    if (start && !start._bound){
        start._bound = true;
        start.addEventListener('click', startOnlineUpgrade);
    }
    var cancel = document.getElementById('vUpgradeCancel');
    if (cancel && !cancel._bound){
        cancel._bound = true;
        cancel.addEventListener('click', cancelUpgradeConfirm);
    }
    resetUpgradeUi();
    openOverlay('versionModal');
}

// ── 在线升级 UI 状态（全部内联在版本弹窗内，不叠第二层弹窗）──
function resetUpgradeUi(){
    var confirm = document.getElementById('vUpgradeConfirm');
    var progress = document.getElementById('vUpgradeProgress');
    var btn = document.getElementById('vUpgradeBtn');
    var dismiss = document.getElementById('vUpgradeDismiss');
    if (confirm) confirm.hidden = true;
    if (progress){ progress.hidden = true; progress.classList.remove('is-error'); }
    if (btn){ btn.disabled = false; btn.textContent = '⬇ 下载并升级'; }
    if (dismiss) dismiss.disabled = false;
    setUpgradeProgress(0, '准备升级…');
}

function setUpgradeProgress(pct, text){
    pct = Math.max(0, Math.min(100, pct));
    var stage = document.getElementById('vUpgradeStage');
    var pctEl = document.getElementById('vUpgradePct');
    var bar = document.getElementById('vUpgradeBar');
    if (text && stage) stage.textContent = text;
    if (pctEl) pctEl.textContent = Math.round(pct) + '%';
    if (bar) bar.style.width = pct + '%';
}

/* 第一次点击：只展开内联确认，不调用原生 confirm */
function upgradeOnline(){
    var confirm = document.getElementById('vUpgradeConfirm');
    var btn = document.getElementById('vUpgradeBtn');
    if (!confirm || !btn) return;
    var ver = (document.getElementById('vNew') || {}).textContent || '';
    var verEl = document.getElementById('vUpgradeVer');
    if (verEl) verEl.textContent = ver || '新版本';
    confirm.hidden = false;
    btn.disabled = true;
    try { confirm.scrollIntoView({ block: 'nearest' }); } catch (e) {}
}

function cancelUpgradeConfirm(){
    var confirm = document.getElementById('vUpgradeConfirm');
    var btn = document.getElementById('vUpgradeBtn');
    if (confirm) confirm.hidden = true;
    if (btn) btn.disabled = false;
}

/* 确认后真正执行升级；进度为前端阶段提示，最终以服务端返回为准 */
function startOnlineUpgrade(){
    var confirm = document.getElementById('vUpgradeConfirm');
    var progress = document.getElementById('vUpgradeProgress');
    var btn = document.getElementById('vUpgradeBtn');
    var dismiss = document.getElementById('vUpgradeDismiss');
    if (!progress || !btn) return;

    if (confirm) confirm.hidden = true;
    progress.hidden = false;
    progress.classList.remove('is-error');
    setUpgradeProgress(3, '正在连接更新源…');
    btn.disabled = true;
    btn.textContent = '升级中，请勿操作...';
    if (dismiss) dismiss.disabled = true;
    LCSC.toast('正在下载并校验安装包（可能持续数分钟，请勿关闭页面）...', 'info');

    var pct = 3;
    var timer = setInterval(function(){
        if (pct >= 90) return;
        pct = Math.min(90, pct + 2);
        var text = pct < 18 ? '正在连接更新源…'
            : pct < 46 ? '正在下载安装包…'
            : pct < 64 ? '正在校验 SHA-256 并解压…'
            : pct < 80 ? '正在备份数据库…'
            : '正在替换程序文件…';
        setUpgradeProgress(pct, text);
    }, 400);

    LCSC.post('api.php?action=update.apply', {confirm: '1'}, function(data, msg){
        clearInterval(timer);
        setUpgradeProgress(100, '升级完成，正在跳转…');
        LCSC.toast((msg || '升级完成') + '，正在跳转...', 'success');
        var target = (data && data.redirect) ? data.redirect : 'index.php';
        setTimeout(function(){ location.href = target; }, 1500);
    }, function(msg){
        clearInterval(timer);
        progress.classList.add('is-error');
        setUpgradeProgress(100, '升级失败：' + (msg || '未知错误'));
        btn.disabled = false;
        btn.textContent = '⬇ 下载并升级';
        if (dismiss) dismiss.disabled = false;
        LCSC.toast('升级失败：' + msg + '（程序文件已回滚，库备份见 backups/）', 'error');
    });
}

// ── AJAX 表单拦截器 ──
(function(){
    function adminReload(){
        if (!document.getElementById('lcscAdminReloadMask')) {
            var mask = document.createElement('div');
            mask.id = 'lcscAdminReloadMask';
            mask.className = 'admin-reload-mask';
            mask.innerHTML = '<div class="admin-reload-box">'
                + '<span class="admin-reload-spin"></span>'
                + '<span class="admin-reload-text">正在保存…</span></div>';
            document.body.appendChild(mask);
            if (!document.getElementById('lcscSpinKey')) {
                var st = document.createElement('style');
                st.id = 'lcscSpinKey';
                st.textContent = '@keyframes lcscSpin{to{transform:rotate(360deg)}}';
                document.head.appendChild(st);
            }
            setTimeout(function(){
                var m = document.getElementById('lcscAdminReloadMask');
                if (m && m.parentNode) m.parentNode.removeChild(m);
            }, 8000);
        }
        location.reload();
    }

    window.setDefaultPlatform = function(platId){
        LCSC.post('app/api/router.php', {
            action: 'platform.set_default',
            plat_id: platId
        }, function(data, msg){
            LCSC.toast(msg || '默认平台已设置', 'success');
            location.reload();
        });
    };

    var csf = document.getElementById('createSubForm');
    if (csf) {
        csf.addEventListener('submit', function(e){
            var un = (csf.querySelector('[name="sub_username"]') || {}).value || '';
            var pw = (csf.querySelector('[name="sub_password"]') || {}).value || '';
            if (un.trim().length < 3) {
                e.preventDefault();
                e.stopImmediatePropagation();
                LCSC.toast('用户名至少 3 位', 'warning');
                return;
            }
            if (!LCSC.isStrongPassword(pw)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                LCSC.toast('密码至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种', 'warning');
            }
        });
    }

    // ── 平台添加/编辑表单（成功后重置表单）──
    var platForm = document.getElementById('platForm');
    if (platForm && !platForm.hasAttribute('data-ajax-bound')) {
        platForm.setAttribute('data-ajax-bound', '1');
        LCSC.interceptForm(platForm, function(data, msg){
            LCSC.toast(msg || '操作成功', 'success');
            if (typeof cancelEditPlatform === 'function') cancelEditPlatform();
            adminReload();
        });
    }

    // ── 带有 id 的独立表单（创建子用户 / 子用户权限）──
    document.querySelectorAll('form#createSubForm, form#subPermForm').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');
        var actInput = f.querySelector('input[name="action"]');
        var act = actInput ? actInput.value : '';
        LCSC.interceptForm(f, function(data, msg){
            LCSC.toast(msg || '操作成功', 'success');
            if (act === 'update_sub_user') {
                if (typeof closeSubPermModal === 'function') closeSubPermModal();
            }
            if (act === 'create_sub_user') {
                f.reset();
                var defaultPerms = ['can_edit', 'can_scan', 'can_print', 'can_export'];
                f.querySelectorAll('input[name="sub_perms[]"]').forEach(function(cb){
                    cb.checked = defaultPerms.indexOf(cb.value) !== -1;
                });
            }
            adminReload();
        });
    });

    // ── 通用拦截器：处理所有其他 form[action="api.php"] 和 form[action="app/api/router.php"] ──
    document.querySelectorAll('form[action="api.php"], form[action="app/api/router.php"]').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');

        var actInput = f.querySelector('input[name="action"]');
        if (!actInput) return;
        var act = actInput.value;

        // 跳过 CSV 导出类表单（保留原生提交触发文件下载）
        if (act === 'export.trace' || act === 'export.logs' || act === 'export.assets') return;

        // 跳过已由独立函数处理的操作（setDefaultPlatform）
        if (act === 'platform.set_default') return;

        // 跳过删除类表单（capture 阶段确认后由专用处理器 AJAX 提交）
        if (f.classList.contains('plat-delete-form') || f.classList.contains('user-delete-form') || f.classList.contains('sub-delete-form')) return;

        // 跳过已独立处理的表单
        if (f.id === 'platForm' || f.id === 'createSubForm' || f.id === 'subPermForm'
            || f.id === 'logBatchForm') return;

        if (f.matches('form.settings-card[data-card-id="feature"]')) return;

        LCSC.interceptForm(f, function(data, msg){
            // 重置密码：后端返回新密码，用统一弹窗展示（不用原生 alert，与全站风格一致）
            if (act === 'admin.user_reset_pw' && data && data.new_password){
                var who = f.getAttribute('data-username') || '该用户';
                AdminUIConfirm({
                    title: '密码已重置',
                    danger: false,
                    message: '用户「' + who + '」的新密码： ' + data.new_password
                             + '  —  仅显示这一次，请立即告知用户并提醒其登录后修改。',
                    okText: '我已记录'
                });
            } else {
                LCSC.toast(msg || '操作成功', 'success');
            }
            // 网站设置：无感保存，不整页刷新（表单值保留在页面）。
            if (act === 'save_settings' || act === 'admin.save_settings'
                || f.classList.contains('settings-card')) return;
            adminReload();
        });
    });
})();


// ── 全局成本重建（分批 AJAX 执行）──
var rebuildRunning = false;
var rebuildOffset  = 0;   // 已完成的批偏移：暂停/中断后从断点继续，不从头重跑
var rebuildSeq     = 0;   // 运行代次：防止「暂停后已排队的下一批」与新循环并发跑两遍
// 二次确认弹窗（统一居中模态框）
function confirmRebuildCost(){
    if (rebuildRunning || rebuildOffset > 0) { rebuildAllCost(); return; }
    AdminUIConfirm({
        title: '重建全部物料成本',
        message: '将强制重算所有物料的加权平均成本，用于修复异常数据。\n\n此操作不可撤销，确认执行？',
        okText: '开始重建',
        onOk: rebuildAllCost
    });
}
function rebuildAllCost(){
    var btn = document.getElementById('rebuildCostBtn');
    if (rebuildRunning) {
        // 暂停：仅置标志位，正在飞的一批回来即止（结果作废，断点保留）
        rebuildRunning = false;
        btn.textContent = '继续重建';
        return;
    }
    rebuildRunning = true;
    rebuildSeq++;
    var seq = rebuildSeq;
    btn.textContent = '暂停';
    var progressEl = document.getElementById('rebuildCostProgress');
    progressEl.style.display = '';
    if (rebuildOffset === 0) progressEl.textContent = '正在重建…';

    function doBatch(){
        if (!rebuildRunning || seq !== rebuildSeq) return;
        LCSC.post('api.php', 'action=bom.rebuild_all_cost&confirm=1&offset=' + rebuildOffset, function(data) {
            if (!rebuildRunning || seq !== rebuildSeq) return;
            var d = data || {};
            rebuildOffset = d.offset || 0;
            var total = d.total || 0;
            var done  = Math.min(rebuildOffset, total);
            progressEl.textContent = '进度: ' + done + '/' + total
                + (total > 0 ? ' (' + Math.round(done / total * 100) + '%)' : '');
            if (d.done) {
                rebuildRunning = false;
                rebuildOffset  = 0;
                btn.textContent = '重建全部物料成本';
                progressEl.textContent = '已完成：共重建 ' + total + ' 个物料';
                LCSC.toast('成本重建完成');
            } else {
                setTimeout(doBatch, 200);
            }
        }, function(msg) {
            if (seq !== rebuildSeq) return;
            rebuildRunning = false;
            btn.textContent = '继续重建';
            progressEl.textContent = '已中断：' + (msg || '请求失败');
        });
    }
    doBatch();
}
