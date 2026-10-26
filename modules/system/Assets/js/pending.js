// 待处理工作台交互逻辑
(function() {
    const C = window.PendingPageConfig || {};
    const $ = s => document.querySelector(s);
    const $$ = s => document.querySelectorAll(s);

    let currentPage = 1;
    let currentReason = '';
    let selectedIds = new Set();

    // ── 扫描 ──
    $('#btn-scan')?.addEventListener('click', async () => {
        $('#btn-scan').disabled = true;
        $('#scan-result').textContent = '扫描中...';
        try {
            const res = await apiPost(C.apiBase + 'scan', {});
            $('#scan-result').textContent = `扫描完成：新增 ${res.data.new} 条，已存在 ${res.data.already} 条`;
            loadList();
            // 更新统计卡片
            if (res.data.new > 0) location.reload();
        } catch (e) {
            $('#scan-result').textContent = '扫描失败：' + (e.message || '未知错误');
        } finally {
            $('#btn-scan').disabled = false;
        }
    });

    // ── 加载列表 ──
    async function loadList(page = 1) {
        currentPage = page;
        const params = { page, per_page: 25, reason: currentReason, status: 'pending' };
        try {
            const res = await apiPost(C.apiBase + 'list', params);
            renderTable(res.data.items || []);
            renderPagination(res.data.total || 0, res.data.page || 1, res.data.per_page || 25);
        } catch (e) {
            $('#pending-body').innerHTML = `<tr><td colspan="9" class="td-msg is-load">加载失败：${e.message}</td></tr>`;
        }
    }

    // ── 渲染表格 ──
    function renderTable(items) {
        if (!items.length) {
            $('#pending-body').innerHTML = '<tr><td colspan="9" class="td-msg is-empty">暂无待处理物料</td></tr>';
            return;
        }
        selectedIds.clear();
        updateBatchButtons();

        $('#pending-body').innerHTML = items.map(item => `
            <tr data-id="${item.id}">
                <td><input type="checkbox" class="row-check" value="${item.id}"></td>
                <td><a href="index.php?v=parts&q=${encodeURIComponent(item.model || '')}">${esc(item.model || '-')}</a></td>
                <td>${esc(item.brand || '-')}</td>
                <td>${esc(item.package || '-')}</td>
                <td>${item.stock}</td>
                <td><span class="tag tag-${item.reason === 'negative_stock' ? 'red' : item.reason === 'duplicate' ? 'orange' : 'blue'}">${C.reasonLabels[item.reason] || item.reason}</span></td>
                <td class="td-ellip" title="${esc(item.detail || '')}">${esc(formatDetail(item.detail, item.reason))}</td>
                <td>${item.created_at || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-success btn-fix-one" data-id="${item.id}">修正</button>
                    <button class="btn btn-sm btn-secondary btn-ignore-one" data-id="${item.id}">忽略</button>
                </td>
            </tr>
        `).join('');

        // 单条操作
        $$('.btn-fix-one').forEach(btn => btn.onclick = () => batchAction('fix', [btn.dataset.id]));
        $$('.btn-ignore-one').forEach(btn => btn.onclick = () => batchAction('ignore', [btn.dataset.id]));
        $$('.row-check').forEach(cb => cb.onchange = onCheckChange);
    }

    // ── 分页 ──
    function renderPagination(total, page, perPage) {
        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) { $('#pending-pagination').innerHTML = ''; return; }
        let html = '<div class="pagination">';
        for (let i = 1; i <= totalPages; i++) {
            html += `<button class="page-btn ${i === page ? 'active' : ''}" data-page="${i}">${i}</button>`;
        }
        html += '</div>';
        $('#pending-pagination').innerHTML = html;
        $$('.page-btn').forEach(btn => btn.onclick = () => loadList(parseInt(btn.dataset.page)));
    }

    // ── 筛选 ──
    $('#filter-reason')?.addEventListener('change', (e) => {
        currentReason = e.target.value;
        loadList(1);
    });

    // ── 统计卡片点击筛选 ──
    $$('.stat-card').forEach(card => {
        card.addEventListener('click', () => {
            const reason = card.dataset.reason;
            $('#filter-reason').value = reason;
            currentReason = reason;
            loadList(1);
        });
    });

    // ── 全选 ──
    $('#check-all')?.addEventListener('change', (e) => {
        $$('.row-check').forEach(cb => { cb.checked = e.target.checked; });
        onCheckChange();
    });

    function onCheckChange() {
        selectedIds.clear();
        $$('.row-check:checked').forEach(cb => selectedIds.add(cb.value));
        updateBatchButtons();
    }

    function updateBatchButtons() {
        const has = selectedIds.size > 0;
        $('#btn-fix').disabled = !has;
        $('#btn-ignore').disabled = !has;
    }

    // ── 批量操作 ──
    $('#btn-fix')?.addEventListener('click', () => batchAction('fix', [...selectedIds]));
    $('#btn-ignore')?.addEventListener('click', () => batchAction('ignore', [...selectedIds]));

    async function batchAction(action, ids) {
        try {
            const res = await apiPost(C.apiBase + action, { ids: JSON.stringify(ids) });
            const msg = action === 'fix' ? `已修正 ${res.data.fixed_count} 条` : `已忽略 ${res.data.ignored_count} 条`;
            showToast(msg, 'success');
            loadList(currentPage);
        } catch (e) {
            showToast('操作失败：' + e.message, 'error');
        }
    }

    // ── 工具函数 ──
    function apiPost(url, data) {
        const body = new URLSearchParams();
        body.append('_csrf', C.csrf);
        Object.entries(data).forEach(([k, v]) => body.append(k, v));
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
            .then(r => r.json())
            .then(r => { if (r.code !== 0) throw new Error(r.msg); return r; });
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function formatDetail(detail, reason) {
        if (!detail) return '-';
        try {
            const d = JSON.parse(detail);
            switch (reason) {
                case 'param_missing': return '缺: ' + (d.field || '参数');
                case 'negative_stock': return '库存: ' + (d.stock || '?');
                case 'duplicate': return (d.model || '') + ' ×' + (d.count || '?');
                case 'no_flow': return (d.days || '?') + '天无流动';
                case 'alt_unconfirmed': return '替代料 #' + (d.target_part_id || '?');
                default: return JSON.stringify(d);
            }
        } catch { return detail; }
    }

    function showToast(msg, type = 'info') {
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = `position:fixed;top:20px;right:20px;padding:10px 20px;border-radius:8px;color:#fff;z-index:9999;font-size:14px;
            background:${type === 'success' ? '#52c41a' : type === 'error' ? '#ff4d4f' : '#1890ff'}`;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3000);
    }

    // 初始加载
    loadList(1);
})();
