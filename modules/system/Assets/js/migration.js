// 独立数据库迁移页交互（P1）
(function () {
    'use strict';

    var cfg = window.MigrationPageConfig || {};
    var btn = document.getElementById('migRunBtn');
    var chk = document.getElementById('migConfirm');
    var out = document.getElementById('migResult');
    // 封印态或元素缺失：不绑定任何交互
    if (!cfg.pending || !btn || !chk || !out) return;

    chk.addEventListener('change', function () { btn.disabled = !chk.checked; });

    btn.addEventListener('click', function () {
        var ok = window.confirm(
            '确认执行数据库迁移？\n将从 ' + cfg.current + ' 迁移到 ' + cfg.target +
            '，会改动数据结构与数据。迁移过程中请勿关闭页面。'
        );
        if (!ok) return;

        btn.disabled = true;
        btn.textContent = '迁移中…';

        var fd = new FormData();
        fd.append('_csrf', cfg.csrf || '');
        fd.append('ajax', '1');

        fetch('api.php?action=migration.execute', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': cfg.csrf || ''
            },
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (json) {
            if (json && json.code === 0) {
                var ok = json.data && json.data.status === 'success';
                render(json.data, json.msg);
                if (ok) {
                    // 不自动跳转/刷新：保留结果面板（步骤 / 备份文件名）供用户查看，
                    // 何时刷新由面板内的「刷新页面」按钮决定。
                    btn.textContent = '迁移已完成';
                } else {
                    btn.disabled = false;
                    btn.textContent = '执行迁移';
                }
            } else {
                btn.disabled = false;
                btn.textContent = '执行迁移';
                render(null, (json && json.msg) || '操作失败');
            }
        }).catch(function (e) {
            btn.disabled = false;
            btn.textContent = '执行迁移';
            render(null, '网络错误：' + e.message);
        });
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function render(data, msg) {
        data = data || {};
        var ok = data.status === 'success';

        var html = '<div class="flash ' + (ok ? 'ok' : 'err') + '">' +
                   (ok ? '✓ 迁移' : '✗ 迁移') + esc(data.status_text || msg || '') + '</div>';

        if (data.backup) {
            html += '<div class="mig-res-line">迁移前备份：<code>' + esc(data.backup) + '</code>（位于 backups/）</div>';
        }

        if (data.steps && data.steps.length) {
            html += '<ul class="mig-res-steps">';
            data.steps.forEach(function (s) {
                html += '<li>' + esc(s.from) + ' → ' + esc(s.to) + ' ' +
                        '<span class="badge ' + (s.status === 'success' ? 'badge-green' : 'badge-red') + '">' +
                        esc(s.status) + '</span>' +
                        (s.records ? '（' + esc(s.records) + ' 条）' : '') + '</li>';
            });
            html += '</ul>';
        }

        if (data.messages && data.messages.length) {
            html += '<ul class="mig-res-msgs">';
            data.messages.forEach(function (m) {
                html += '<li>[' + esc(m.level) + '] ' + esc(m.message) + '</li>';
            });
            html += '</ul>';
        }

        html += ok
            ? '<div class="mig-res-line">迁移完成，数据库已解封。可点击下方按钮刷新本页（将显示「已是最新」与更新后的历史），或直接返回系统。</div>'
              + '<button type="button" class="btn btn-primary" id="migReloadBtn">刷新页面</button>'
            : '<div class="mig-res-line">版本号未提交，可用上方备份回退；修复后回到本页重试。</div>';

        out.classList.remove('is-hidden');
        out.innerHTML = html;

        var reloadBtn = out.querySelector('#migReloadBtn');
        if (reloadBtn) reloadBtn.addEventListener('click', function () { location.reload(); });

        out.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
})();
