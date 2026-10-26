var CS = {
    currentId: 0,
    filter: 'all',
    q: '',
    page: 1,
    dirty: {},        // item_id -> counted_qty（本页未保存的录数）
    STATUS: { counting: '盘点中', confirmed: '已确认', cancelled: '已取消' },

    esc: function (s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    _post: function (method, data, done) {
        var body = 'action=stock.' + method + '&_csrf=' + (window.LCSC ? LCSC.csrf : '');
        Object.keys(data || {}).forEach(function (k) { body += '&' + k + '=' + encodeURIComponent(data[k]); });
        LCSC.fetchJson('app/api/router.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(function (resp) {
            if (resp.code === 0) { if (done) done(resp.data || {}, resp.msg || 'ok'); }
            else LCSC.toast(resp.msg || '操作失败', 'error');
        }).catch(function (msg) { LCSC.toast(String(msg), 'error'); });
    },

    // ── 盘点单列表 ──
    loadSessions: function () {
        var wrap = document.getElementById('csSessionList');
        if (wrap) wrap.innerHTML = '<div class="cs-note is-loading">⏳ 加载盘点记录…</div>';
        var status = document.getElementById('csListStatus') ? document.getElementById('csListStatus').value : '';
        var sort   = document.getElementById('csListSort') ? document.getElementById('csListSort').value : 'status';
        this._post('session_list', { page: 1, per_page: 20, status: status, sort: sort }, function (data) {
            CS.renderSessionList(data);
        });
    },

    renderSessionList: function (data) {
        var wrap = document.getElementById('csSessionList');
        if (!wrap) return;
        var items = data.items || [];
        var curStatus = document.getElementById('csListStatus') ? document.getElementById('csListStatus').value : '';
        var curSort   = document.getElementById('csListSort') ? document.getElementById('csListSort').value : 'status';
        var html = '<div class="cs-list-tools">'
            + '<div class="cs-pills">'
            + ['', 'counting', 'confirmed', 'cancelled'].map(function (s) {
                var lab = { '': '全部', counting: '进行中', confirmed: '已确认', cancelled: '已取消' }[s];
                var act = (curStatus === s) ? 'btn-primary' : 'btn-ghost';
                return '<button type="button" class="btn ' + act + ' btn-sm" onclick="CS.setStatus(\'' + s + '\')">' + lab + '</button>';
            }).join('')
            + '</div>'
            + '<select id="csListStatus" onchange="CS.loadSessions()">'
            + '<option value="">全部状态</option>'
            + ['counting', 'confirmed', 'cancelled'].map(function (s) {
                return '<option value="' + s + '"' + (curStatus === s ? ' selected' : '') + '>' + CS.STATUS[s] + '</option>';
            }).join('') + '</select>'
            + '<select id="csListSort" onchange="CS.loadSessions()">'
            + '<option value="status"' + (curSort === 'status' ? ' selected' : '') + '>排序：盘点中优先</option>'
            + '<option value="recent"' + (curSort === 'recent' ? ' selected' : '') + '>排序：最近创建</option>'
            + '</select></div>';
        if (!items.length) {
            html += '<div class="cs-note">暂无盘点单，先在上方发起一次在线盘点</div>';
        } else {
            html += '<div class="cs-table-wrap"><table class="data-table cs-table cs-session-table">'
                + '<colgroup>'
                + '<col class="cs-col-title">'      // 盘点单（弹性）
                + '<col class="cs-col-status">'     // 状态
                + '<col class="cs-col-progress">'   // 进度
                + '<col class="cs-col-diff">'       // 差异/应用
                + '<col class="cs-col-creator">'    // 发起人
                + '<col class="cs-col-time">'       // 时间
                + '<col class="cs-col-ops">'        // 操作
                + '</colgroup>'
                + '<thead><tr>'
                + '<th class="ta-l">盘点单</th><th>状态</th><th>进度</th><th>差异/应用</th><th>发起人</th><th>时间</th><th class="ta-r">操作</th>'
                + '</tr></thead><tbody>';
            items.forEach(function (s) {
                var ops = '';
                if (s.status === 'counting') {
                    ops = '<button class="btn btn-primary btn-sm" onclick="CS.openSession(' + s.id + ')">继续盘点</button> '
                        + '<button class="btn btn-ghost btn-sm" onclick="CS.preview(' + s.id + ')">差异预览</button> '
                        + '<button class="btn btn-ghost btn-sm" onclick="CS.cancel(' + s.id + ')">取消</button>';
                } else {
                    ops = '<button class="btn btn-ghost btn-sm" onclick="CS.openSession(' + s.id + ')">查看</button>';
                }
                html += '<tr>'
                    + '<td class="cs-cell-title" title="' + CS.esc(s.title) + (s.remark ? ' · ' + CS.esc(s.remark) : '') + '">#' + s.id + ' ' + CS.esc(s.title) + (s.remark ? '<div class="cs-cell-sub" title="' + CS.esc(s.remark) + '">' + CS.esc(s.remark) + '</div>' : '') + '</td>'
                    + '<td class="ta-c"><span class="cs-status is-' + s.status + '">' + CS.STATUS[s.status] + '</span></td>'
                    + '<td class="ta-c num">' + s.counted_items + ' / ' + s.total_items + '</td>'
                    + '<td class="ta-c num">' + (s.status === 'confirmed' ? (s.diff_items + ' / ' + s.adjusted_items) : '—') + '</td>'
                    + '<td class="ta-c">' + CS.esc(s.creator) + '</td>'
                    + '<td class="ta-c mono">' + CS.esc(s.create_time) + '</td>'
                    + '<td class="cs-cell-ops">' + ops + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        wrap.innerHTML = html;
    },

    // ── 发起盘点 ──
    createSession: function () {
        var data = {
            title: (document.getElementById('csTitle').value || '').trim(),
            category_id: document.getElementById('csCat').value,
            location: document.getElementById('csLoc').value,
            platform_id: document.getElementById('csPlat').value
        };
        this._post('session_create', data, function (d) {
            LCSC.toast('盘点单已创建（' + d.total + ' 项）', 'success');
            document.getElementById('csTitle').value = '';
            CS.loadSessions();
            CS.openSession(d.session_id);
        });
    },

    // ── 盘点明细 ──
    openSession: function (id, page) {
        this.currentId = id;
        this.page = page || 1;
        this.dirty = {};
        this._loadDetail();
    },

    _loadDetail: function () {
        this._post('session_detail', {
            session_id: this.currentId, filter: this.filter, q: this.q,
            page: this.page, per_page: 50
        }, function (data) { CS.renderDetail(data); });
    },

    setFilter: function (f) { this.filter = f; this.page = 1; this._loadDetail(); },
    setStatus: function (s) {
        var sel = document.getElementById('csListStatus');
        if (sel) sel.value = s;
        this.loadSessions();
    },
    setSearch: function () {
        this.q = (document.getElementById('csSearch').value || '').trim();
        this.page = 1; this._loadDetail();
    },
    setPage: function (p) { this.page = p; this._loadDetail(); },

    renderDetail: function (data) {
        var wrap = document.getElementById('csDetailView');
        if (!wrap) return;
        var s = data.session, stats = data.stats, items = data.items || [];
        var counting = s.status === 'counting';

        var html = '<div class="check-section">'
            + '<div class="step-title"><span class="step-num">3</span> 盘点明细 <span class="cs-step-tail">#' + s.id + ' ' + CS.esc(s.title) + '（' + CS.STATUS[s.status] + '）</span></div>';

        // 统计条
        html += '<div class="cs-bar is-spaced">'
            + this._statChip('总数', stats.total, '')
            + this._statChip('已盘', stats.counted, 'is-up')
            + this._statChip('差异', stats.diff, 'is-down')
            + this._statChip('跳过', stats.skipped, '')
            + '</div>';

        if (counting) {
            // 工具行：筛选 + 搜索 + 保存
            var filters = [['all','全部'],['pending','未盘'],['counted','已盘'],['diff','仅差异'],['skipped','已跳过']];
            html += '<div class="cs-bar">'
                + filters.map(function (f) {
                    return '<button class="btn ' + (CS.filter === f[0] ? 'btn-primary' : 'btn-ghost') + ' btn-sm" onclick="CS.setFilter(\'' + f[0] + '\')">' + f[1] + '</button>';
                }).join('')
                + '<input type="text" id="csSearch" class="cs-input cs-input-search" placeholder="搜索型号/编号" value="' + CS.esc(CS.q) + '" onkeydown="if(event.key===\'Enter\')CS.setSearch()">'
                + '<button class="btn btn-ghost btn-sm" onclick="CS.setSearch()">🔍</button>'
                + '<span class="cs-flex-1"></span>'
                + '<button class="btn btn-primary btn-sm" onclick="CS.saveCount()">💾 保存本页录数</button>'
                + '<button class="btn btn-ghost btn-sm" onclick="CS.skipSelected()">跳过勾选</button>'
                + '<button class="btn btn-primary btn-sm" onclick="CS.preview(' + s.id + ')">差异预览 / 确认</button>'
                + '</div>';
        } else {
            html += '<div class="cs-bar">'
                + (s.status === 'confirmed' && s.diff_items > 0
                    ? '<span class="cs-step-tail">确认于 ' + CS.esc(s.confirm_time || '') + '：差异 ' + s.diff_items + ' 行，应用调整 ' + s.adjusted_items + ' 行（调整流水 change_type=checklist_adjust）</span>'
                    : '<span class="cs-step-tail">' + (s.status === 'cancelled' ? '已取消，未应用任何调整（明细保留备查）' : '已确认，无差异') + '</span>')
                + '</div>';
        }

        // 明细表
        html += '<table class="data-table cs-table"><thead><tr>'
            + (counting ? '<th><input type="checkbox" onclick="document.querySelectorAll(\'input.cs-row-cb\').forEach(function(cb){cb.checked=this.checked},this)"></th>' : '')
            + '<th class="ta-l">物料</th><th>库位</th><th>快照库存</th><th>实盘数</th><th>差异</th><th>当前库存</th><th>状态</th>'
            + (counting ? '<th>操作</th>' : '')
            + '</tr></thead><tbody>';
        if (!items.length) {
            html += '<tr><td colspan="' + (counting ? 9 : 7) + '" class="ta-c cs-note">' + '无匹配明细' + '</td></tr>';
        }
        items.forEach(function (it) {
            var pending = it.item_status === 'pending';
            var skipped = it.item_status === 'skipped';
            var diffMod = it.diff > 0 ? 'is-up' : (it.diff < 0 ? 'is-down' : 'is-zero');
            var conflict = it.item_status === 'counted' && parseInt(it.current_qty) !== parseInt(it.snapshot_qty);
            var nameCell = CS.esc(it.model || it.platform_part_no || ('#' + it.part_id))
                + '<div class="cs-sub">' + CS.esc(it.platform_part_no || '') + (it.brand ? ' · ' + CS.esc(it.brand) : '') + '</div>';
            html += '<tr>'
                + (counting ? '<td class="ta-c"><input type="checkbox" class="cs-row-cb" data-item="' + it.id + '" ' + (skipped ? 'disabled' : '') + '></td>' : '')
                + '<td class="ta-l">' + nameCell + '</td>'
                + '<td class="ta-c">' + CS.esc(it.location || '—') + '</td>'
                + '<td class="ta-c num">' + it.snapshot_qty + '</td>'
                + '<td class="ta-c">'
                + (counting && !skipped
                    ? '<input type="number" min="0" class="cs-input cs-input-count" data-item="' + it.id + '" value="' + (it.counted_qty === null || it.counted_qty === undefined ? '' : it.counted_qty) + '" placeholder="未盘" oninput="CS.markDirty(' + it.id + ',this.value)">'
                    : (it.counted_qty === null || it.counted_qty === undefined ? '—' : it.counted_qty))
                + '</td>'
                + '<td class="ta-c num"><span class="cs-diff ' + diffMod + '">' + (it.item_status === 'counted' ? (it.diff > 0 ? '+' + it.diff : it.diff) : '—') + '</span></td>'
                + '<td class="ta-c num">' + it.current_qty + (conflict ? ' <span title="盘点期间库存有变动" class="cs-diff-warn">⚠</span>' : '') + '</td>'
                + '<td class="ta-c">' + (skipped ? '跳过' : (pending ? '未盘' : '已盘')) + '</td>'
                + (counting ? '<td class="ta-c">' + (skipped ? '<button class="btn btn-ghost btn-sm" onclick="CS.unskip(' + it.id + ')">恢复</button>' : '<button class="btn btn-ghost btn-sm" onclick="CS.skipOne(' + it.id + ')">跳过</button>') + '</td>' : '')
                + '</tr>';
        });
        html += '</tbody></table>';

        // 分页
        if ((data.total_page || 1) > 1) {
            html += '<div class="cs-bar is-centered">';
            for (var p = 1; p <= data.total_page; p++) {
                html += '<button class="btn ' + (p === data.page ? 'btn-primary' : 'btn-ghost') + ' btn-sm" onclick="CS.setPage(' + p + ')">' + p + '</button>';
            }
            html += '</div>';
        }
        html += '</div>';
        wrap.innerHTML = html;
    },

    _statChip: function (label, val, mod) {
        return '<div class="cs-chip">'
            + '<div class="cs-chip-label">' + label + '</div>'
            + '<div class="cs-chip-value ' + (mod || 'is-zero') + '">' + val + '</div></div>';
    },

    // ── 录数 ──
    markDirty: function (itemId, val) { this.dirty[itemId] = val; },

    saveCount: function () {
        var items = [];
        Object.keys(this.dirty).forEach(function (id) {
            var v = parseInt(CS.dirty[id], 10);
            if (!isNaN(v) && v >= 0) items.push({ item_id: parseInt(id, 10), counted_qty: v });
        });
        if (!items.length) { LCSC.toast('本页没有新填写的实盘数', 'warning'); return; }
        this._post('session_count', { session_id: this.currentId, items: JSON.stringify(items) }, function () {
            LCSC.toast('实盘数已保存', 'success');
            CS._loadDetail();
            CS.loadSessions();
        });
    },

    _selectedIds: function () {
        return Array.prototype.slice.call(document.querySelectorAll('input.cs-row-cb:checked')).map(function (cb) {
            return parseInt(cb.getAttribute('data-item'), 10);
        });
    },

    skipSelected: function () {
        var ids = this._selectedIds();
        if (!ids.length) { LCSC.toast('请先勾选要跳过的行', 'warning'); return; }
        this._post('session_skip', { session_id: this.currentId, item_ids: JSON.stringify(ids) }, function () { CS._loadDetail(); });
    },
    skipOne: function (itemId) {
        this._post('session_skip', { session_id: this.currentId, item_ids: JSON.stringify([itemId]) }, function () { CS._loadDetail(); });
    },
    unskip: function (itemId) {
        this._post('session_unskip', { session_id: this.currentId, item_ids: JSON.stringify([itemId]) }, function () { CS._loadDetail(); });
    },

    // ── 差异单预览 / 确认 ──
    preview: function (sessionId) {
        this._post('session_preview', { session_id: sessionId }, function (data) { CS.renderPreview(sessionId, data); });
    },

    renderPreview: function (sessionId, data) {
        var wrap = document.getElementById('csDetailView');
        if (!wrap) return;
        var diffs = data.diffs || [], conflicts = data.conflicts || [], s = data.session;
        var html = '<div class="check-section">'
            + '<div class="step-title"><span class="step-num">4</span> 差异单确认 <span class="cs-step-tail">#' + sessionId + ' ' + CS.esc(s.title) + '</span></div>';

        if (!diffs.length && !conflicts.length) {
            html += '<p class="cs-text">无账实差异（已盘项实盘数与快照一致）。可直接封单。</p>';
        }
        if (diffs.length) {
            html += '<p class="cs-text is-tight"><strong>待应用差异（' + diffs.length + ' 行）</strong>：确认后将按「实盘 − 快照」调整库存，写入流水（checklist_adjust）。</p>';
            html += '<table class="data-table cs-table"><thead><tr><th class="ta-l">物料</th><th>快照</th><th>实盘</th><th>差异</th></tr></thead><tbody>';
            diffs.forEach(function (r) {
                var d = parseInt(r.diff, 10);
                html += '<tr><td class="ta-l">' + CS.esc(r.model || r.platform_part_no) + '<div class="cs-sub">' + CS.esc(r.platform_part_no || '') + '</div></td>'
                    + '<td class="ta-c num">' + r.snapshot_qty + '</td>'
                    + '<td class="ta-c num">' + r.counted_qty + '</td>'
                    + '<td class="ta-c num"><span class="cs-diff ' + (d > 0 ? 'is-up' : 'is-down') + '">' + (d > 0 ? '+' + d : d) + '</span></td></tr>';
            });
            html += '</tbody></table>';
        }
        if (conflicts.length) {
            html += '<p class="cs-text is-danger"><strong>⚠ 冲突行（' + conflicts.length + ' 行，将跳过）</strong>：盘点期间库存发生变动（快照 ≠ 当前），为避免误调整不自动应用，可稍后发起局部复盘。</p>';
            html += '<table class="data-table cs-table"><thead><tr><th class="ta-l">物料</th><th>快照</th><th>实盘</th><th>当前库存</th></tr></thead><tbody>';
            conflicts.forEach(function (r) {
                html += '<tr><td class="ta-l">' + CS.esc(r.model || r.platform_part_no) + '</td>'
                    + '<td class="ta-c num">' + r.snapshot_qty + '</td>'
                    + '<td class="ta-c num">' + r.counted_qty + '</td>'
                    + '<td class="ta-c num"><span class="cs-diff is-down">' + r.current_qty + '</span></td></tr>';
            });
            html += '</tbody></table>';
        }

        if (s.status === 'counting') {
            html += '<div class="cs-bar is-actions">'
                + '<button class="btn btn-primary" onclick="CS.confirm(' + sessionId + ')">✓ 确认并应用差异</button>'
                + '<button class="btn btn-ghost" onclick="CS.openSession(' + sessionId + ')">← 返回明细</button>'
                + '</div>';
        }
        html += '</div>';
        wrap.innerHTML = html;
        wrap.scrollIntoView({ behavior: 'smooth' });
    },

    confirm: function (sessionId) {
        AdminUIConfirm({
            title: '应用盘点差异',
            message: '将按「实盘 − 快照」调整库存并写入调整流水，此操作不可撤销。',
            okText: '确认应用',
            onOk: function () {
                CS._post('session_confirm', { session_id: sessionId }, function () {
                    LCSC.toast('盘点确认完成', 'success');
                    CS.loadSessions();
                    CS.openSession(sessionId);
                });
            }
        });
    },

    cancel: function (sessionId) {
        AdminUIConfirm({
            title: '取消盘点单',
            message: '已盘数据会保留备查，不会应用任何库存调整。',
            okText: '确认取消',
            onOk: function () {
                CS._post('session_cancel', { session_id: sessionId }, function () {
                    LCSC.toast('盘点单已取消', 'success');
                    CS.loadSessions();
                    var detail = document.getElementById('csDetailView');
                    if (detail) detail.innerHTML = '';
                });
            }
        });
    }
};
