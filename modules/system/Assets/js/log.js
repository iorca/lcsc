// CSV 导出：POST 到 api.php (action=export.logs)，由 LogRoutes 取数并输出 CSV 流
function batchExportLogs() {
    var checked = document.querySelectorAll('.logCheckbox:checked');
    if (checked.length === 0) {
        LCSC.toast('请先勾选要导出的记录', 'warning');
        return;
    }
    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'api.php';
    var actInput = document.createElement('input');
    actInput.type = 'hidden';
    actInput.name = 'action';
    actInput.value = 'export.logs';
    form.appendChild(actInput);
    // CSRF token
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_csrf';
    csrfInput.value = window.LogPageConfig.csrf;
    form.appendChild(csrfInput);
    checked.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'log_ids[]';
        inp.value = cb.value;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function toggleSelectAll(cb) {
    var checkboxes = document.querySelectorAll('.logCheckbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = cb.checked;
    }
    updateSelectedCount();
}

function updateSelectedCount() {
    var checked = document.querySelectorAll('.logCheckbox:checked');
    var el = document.getElementById('selectedCount');
    if (el) el.textContent = '已选 ' + checked.length + ' 条';
    var selAll = document.getElementById('selectAll');
    if (selAll) selAll.checked = checked.length > 0 && checked.length === document.querySelectorAll('.logCheckbox').length;
}

// 监听复选框变化
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('logCheckbox')) {
        updateSelectedCount();
    }
});

(function(){
    var batchForm = document.getElementById('batchForm');
    if (batchForm && !batchForm.hasAttribute('data-ajax-bound')) {
        batchForm.setAttribute('data-ajax-bound', '1');
        document.addEventListener('submit', function(e){
            if (e.target !== batchForm) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            var checked = document.querySelectorAll('.logCheckbox:checked');
            if (checked.length === 0) {
                LCSC.toast('请至少选择一条记录', 'warning');
                return;
            }
            AdminUIConfirm({
                title: '批量删除',
                message: '确认删除选中的 ' + checked.length + ' 条记录？删除后不可恢复。',
                okText: '确认删除',
                onOk: function(){
                    var fd = new FormData(batchForm);
                    LCSC.post(batchForm.getAttribute('action'), fd, function(data, msg){
                        LCSC.toast(msg || '批量删除成功', 'success');
                        location.reload();
                    });
                }
            });
        }, true);
    }
})();

// 清空搜索框
function clearLogSearch() {
    var input = document.getElementById('logSearchInput');
    if (input) input.value = '';
    document.getElementById('logSearchForm').submit();
}

// 搜索框清除按钮显隐（供首屏与内容区无感刷新后重复调用）
function initLogSearchBox() {
    var input = document.getElementById('logSearchInput');
    var btn = document.getElementById('logClearBtn');
    if (!input || !btn) return;
    if (input.dataset.logSearchBound === '1') return;
    input.dataset.logSearchBound = '1';
    btn.style.display = input.value.trim() !== '' ? 'flex' : 'none';
    input.addEventListener('input', function() {
        btn.style.display = this.value.trim() !== '' ? 'flex' : 'none';
    });
    // 回车提交（流水中心内容区为局部刷新时由 flow.js 在捕获阶段接管）
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('logSearchForm').submit();
        }
    });
}
initLogSearchBox();
window.initLogSearchBox = initLogSearchBox;

// 页面加载完成后初始化选中计数
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});


// ── 字段中文标签映射 ──
var _fieldLabels = {
    model: '型号', product_name: '产品名称', brand: '品牌', package: '封装',
    product_type: '类型', stock: '库存', defective: '不良品', location: '库位',
    remark: '备注', low_stock_threshold: '安全库存', platform_part_no: '平台编号',
    purchase_url: '采购链接', parameters_text: '参数文本',
    status: '状态', avg_cost: '平均成本',
    name: '名称', code: '编码', url: '链接', type: '类型',
    parent_id: '父级ID', sort_order: '排序', description: '描述'
};

function _fieldLabel(key) {
    return _fieldLabels[key] || key;
}

// ── 快照 Diff 弹窗 ──
function showSnapshotDiff(beforeRaw, afterRaw) {
    var before = {}, after = {};
    try { before = beforeRaw ? JSON.parse(beforeRaw) : {}; } catch(e) { before = {}; }
    try { after = afterRaw ? JSON.parse(afterRaw) : {}; } catch(e) { after = {}; }

    var allKeys = [];
    var keySet = {};
    Object.keys(before).forEach(function(k) { if (!keySet[k]) { keySet[k] = true; allKeys.push(k); } });
    Object.keys(after).forEach(function(k) { if (!keySet[k]) { keySet[k] = true; allKeys.push(k); } });

    // 过滤不重要的字段
    var skipKeys = { id: true, user_id: true, created_at: true, updated_at: true, cost_dirty: true, last_cost_recalc: true };
    var rows = '';
    var hasDiff = false;

    allKeys.forEach(function(key) {
        if (skipKeys[key]) return;
        var bVal = before.hasOwnProperty(key) ? before[key] : '';
        var aVal = after.hasOwnProperty(key) ? after[key] : '';
        var bStr = String(bVal);
        var aStr = String(aVal);
        if (bStr === aStr) return;

        hasDiff = true;
        var isAdded = !before.hasOwnProperty(key);
        var isRemoved = !after.hasOwnProperty(key);
        var rowCls = isAdded ? 'is-added' : (isRemoved ? 'is-removed' : 'is-changed');

        var beforeCell = isAdded
            ? '<span class="trace-diff-none">—</span>'
            : htmlescape(bStr);
        var afterCell = isAdded
            ? '<span class="trace-diff-add">+ ' + htmlescape(aStr) + '</span>'
            : (isRemoved ? '<span class="trace-diff-del">- 已删除</span>' : htmlescape(aStr));

        rows += '<tr class="' + rowCls + '">' +
            '<td class="trace-diff-k">' + _fieldLabel(key) + '</td>' +
            '<td class="trace-diff-b">' + beforeCell + '</td>' +
            '<td class="trace-diff-a">' + afterCell + '</td></tr>';
    });

    if (!hasDiff) {
        rows = '<tr><td colspan="3" class="trace-diff-none-cell">操作前后数据无差异</td></tr>';
    }

    var html = '<div class="trace-diff-head"><span>操作前</span><span>操作后</span></div>' +
        '<table class="trace-diff-table">' +
        '<thead><tr>' +
        '<th>字段</th><th>操作前</th><th>操作后</th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>';

    document.getElementById('snapshotDiffContent').innerHTML = html;
    document.getElementById('snapshotModal').classList.add('open');
}

function htmlescape(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function closeSnapshotModal() {
    document.getElementById('snapshotModal').classList.remove('open');
}

// ── 回滚确认弹窗 ──
var _pendingRollbackId = null;

function showRollbackConfirm(traceId, targetType, detail) {
    _pendingRollbackId = traceId;
    var typeLabels = { part: '物料', category: '分类', platform: '平台' };
    var typeLabel = typeLabels[targetType] || targetType;
    var msg = '即将回滚 ' + typeLabel + ' 操作：<strong>' + htmlescape(detail || '未知操作') + '</strong><br><br>' +
        '数据将恢复到操作前的状态，此操作不可撤销。<br>确认回滚？';
    document.getElementById('rollbackConfirmMsg').innerHTML = msg;
    document.getElementById('rollbackConfirmModal').classList.add('open');
}

function closeRollbackConfirm() {
    document.getElementById('rollbackConfirmModal').classList.remove('open');
    _pendingRollbackId = null;
}

function executeRollback() {
    if (!_pendingRollbackId) return;
    var traceId = _pendingRollbackId;
    closeRollbackConfirm();

    var btn = document.getElementById('rollbackConfirmBtn');
    btn.disabled = true;
    btn.textContent = '回滚中...';

    LCSC.post('api.php?action=admin.rollback', {
        trace_id: traceId
    }, function(data, msg) {
        if (typeof LCSC !== 'undefined' && LCSC.toast) {
            LCSC.toast(msg || '物料数据已回滚', 'success');
        }
        setTimeout(function() { location.reload(); }, 800);
    }, function(msg) {
        if (typeof LCSC !== 'undefined' && LCSC.toast) {
            LCSC.toast(msg || '回滚失败', 'error');
        }
    });
}

// ── 事件绑定 ──
document.addEventListener('click', function(e) {
    // 快照按钮
    var snapBtn = e.target.closest('.trace-snapshot-btn');
    if (snapBtn) {
        e.preventDefault();
        showSnapshotDiff(
            snapBtn.getAttribute('data-before'),
            snapBtn.getAttribute('data-after')
        );
        return;
    }
    // 回滚按钮
    var rbBtn = e.target.closest('.trace-rollback-btn');
    if (rbBtn) {
        e.preventDefault();
        showRollbackConfirm(
            rbBtn.getAttribute('data-id'),
            rbBtn.getAttribute('data-type'),
            rbBtn.getAttribute('data-detail')
        );
        return;
    }
});

// 回滚确认按钮绑定
document.addEventListener('DOMContentLoaded', function() {
    var confirmBtn = document.getElementById('rollbackConfirmBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', executeRollback);
    }
});
