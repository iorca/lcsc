// modules/bom/Assets/js/bom-checkout.js — BOM 管理页（?v=bom）· 利润率保存、一键出库（模式 + 二次确认 + 结果卡）

function bomCheckoutMode() {
    var sel = document.getElementById('bomCheckoutModeSelect');
    return (sel && sel.value) ? sel.value : 'all_or_nothing';
}
function bomCheckoutCopies() {
    var el = document.getElementById('bomCheckoutCopies');
    return Math.max(1, Math.min(999, parseInt(el && el.value, 10) || 1));
}
function bomConfirmCheckout(e) {
    var form = e.target.closest ? e.target.closest('form') : e.target;
    if (form && form._confirmPassed) { form._confirmPassed = false; return; }
    var mode = bomCheckoutMode();
    var copies = bomCheckoutCopies();
    var pendCnt = form ? parseInt(form.getAttribute('data-pending-count') || '0', 10) : 0;
    if (pendCnt > 0) {
        // 整单出库前提：全部行已匹配主库物料 → 存在未匹配/待补行时直接阻断（弹窗提示）
        e.preventDefault();
        e.stopImmediatePropagation();
        bomCheckoutResultCard(false, {
            msg: '出库已取消：本版本有 ' + pendCnt + ' 行未匹配主库物料，请先「匹配库料」绑定或删除这些行',
            data: { kind: 'unmatched', reasons: [{ model: '（共 ' + pendCnt + ' 行未匹配）', part_no: '—', reason: '请先匹配库料绑定或删除这些行' }] }
        });
        return;
    }
    var msg = '出库份数：' + copies + ' 份（每行需求量 = 清单数量 × ' + copies + '）\n'
        + (mode === 'partial'
            ? '模式：按可用量部分扣减（不足的行只扣可用量）\n'
            : '模式：严格（任一行库存不足则整单失败、不做任何扣减）\n')
        + '\n确认出库？';
    e.preventDefault();
    e.stopImmediatePropagation();
    AdminUIConfirm({
        title: 'BOM 一键出库',
        message: msg,
        okText: '确认出库',
        onOk: function(){
            form._confirmPassed = true;
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        }
    });
}

function bomCheckoutResultCard(ok, payload){
    var title = ok ? '出库成功' : '出库失败';
    var body = '';
    if (ok) {
        var st = payload.stats || {};
        body = '<div class="bco-result-msg">'
             + esc(payload.message ? payload.message : '出库完成')
             + '</div>'
             + '<div class="bco-stat-grid">'
             +   '<div class="bco-stat"><div class="bco-stat-k">物料种数</div><div class="bco-stat-v">' + (st.matched || 0) + '</div></div>'
             +   '<div class="bco-stat"><div class="bco-stat-k">扣减件数</div><div class="bco-stat-v">' + (st.total_qty || 0) + '</div></div>'
             +   '<div class="bco-stat"><div class="bco-stat-k">其中替代料</div><div class="bco-stat-v is-alt">' + (st.alt_qty || 0) + '</div></div>'
             +   '<div class="bco-stat"><div class="bco-stat-k">清单行数</div><div class="bco-stat-v">' + (st.total_rows || 0) + '</div></div>'
             + '</div>';
    } else {
        var d = payload.data || {};
        var rows = d.reasons || [];
        var body2 = '';
        for (var i = 0; i < rows.length && i < 50; i++) {
            var r = rows[i];
            body2 += '<tr><td>' + esc(r.model || '') + '</td><td class="mono">' + esc(r.part_no || '')
                  +  '</td><td>' + esc(r.reason || '') + '</td></tr>';
        }
        body = '<div class="bco-result-msg">' + esc(payload.msg || '出库失败') + '</div>'
             + (body2
                 ? '<div class="table-wrap bco-shortage-wrap"><table class="bom-shortage-table"><thead><tr><th>型号</th><th>编号</th><th>原因</th></tr></thead><tbody>' + body2 + '</tbody></table></div>'
                 : '')
             + (rows.length > 50 ? '<div class="bco-truncated">仅显示前 50 条，共 ' + rows.length + ' 条</div>' : '');
    }
    bomDialog({
        title: title,
        icon: ok ? '✅' : '⚠️',
        tone: ok ? 'ok' : 'err',
        body: body,
        extraBtn: (!ok && (payload.data || {}).kind === 'unmatched')
            ? { text: '查看未匹配行', onClick: function(){ bomImportGoPending(); } }
            : null
    });
}

// 轻量结果弹窗（复用 AdminUIConfirm 的遮罩与动效，内容可含表格）
function bomDialog(opts){
    var old = document.getElementById('bomResultOverlay');
    if (old) old.remove();
    var ov = document.createElement('div');
    ov.id = 'bomResultOverlay';
    ov.className = 'acm-overlay';
    ov.innerHTML =
        '<div class="acm-dialog is-wide" role="dialog" aria-modal="true">'
            + '<div class="acm-icon is-plain ' + (opts.tone === 'ok' ? 'info' : 'danger') + '">' + (opts.icon || 'ℹ️') + '</div>'
            + '<div class="acm-title ' + (opts.tone === 'ok' ? 'is-ok' : 'is-err') + '">' + esc(opts.title || '提示') + '</div>'
            + '<div class="acm-body">' + (opts.body || '') + '</div>'
            + '<div class="acm-btns">' +
                (opts.extraBtn ? '<button type="button" class="acm-btn" data-act="extra">' + esc(opts.extraBtn.text) + '</button>' : '') +
                '<button type="button" class="acm-btn ok' + (opts.tone === 'ok' ? ' primary' : '') + '" data-act="ok">' + esc(opts.okText || '知道了') + '</button>' +
            '</div>' +
        '</div>';
    ov.addEventListener('click', function(e){
        if (e.target === ov) { ov.remove(); return; }
        var act = e.target.getAttribute && e.target.getAttribute('data-act');
        if (act === 'ok') ov.remove();
        else if (act === 'extra') { ov.remove(); if (opts.extraBtn && opts.extraBtn.onClick) opts.extraBtn.onClick(); }
    });
    document.body.appendChild(ov);
    var okBtn = ov.querySelector('[data-act=ok]');
    if (okBtn) okBtn.focus();
}
