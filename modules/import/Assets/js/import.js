var ImportWizard = {
    cfg: window.ImportPageConfig || {},
    state: {
        step: 1, platformId: 0, platformCode: '', docId: 0, headersSig: '',
        importMode: 'stock',
        headers: [], detected: [], autoMap: {}, preview: [], pptFields: {},
        batchId: 0, paused: false, running: false, file: null,
        detailBatch: 0, lastDetail: null, errors: [], fromFlow: false, focusErrors: false,
        rowFilter: 'insert', rowData: null, rowPage: 1, rowTotalPages: 1, rowPerPage: 20,
        customFields: []
    },

    esc: function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    },

    // ─ 初始化 ──
    init: function () {
        var fi = document.getElementById('fileInput');
        var dz = document.getElementById('dropZone');

        if (dz && fi) {
            dz.addEventListener('click', function () { fi.click(); });
            dz.addEventListener('dragover', function (e) { e.preventDefault(); dz.classList.add('dragging'); });
            dz.addEventListener('dragleave', function () { dz.classList.remove('dragging'); });
            dz.addEventListener('drop', function (e) {
                e.preventDefault(); dz.classList.remove('dragging');
                var f = e.dataTransfer.files[0];
                if (f) ImportWizard.setFile(f);
            });
            fi.addEventListener('change', function () { if (fi.files[0]) ImportWizard.setFile(fi.files[0]); });
        }

        // 导入类型选择（采购入库 / 物料建档）
        document.querySelectorAll('#modeCards .plat-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('#modeCards .plat-card').forEach(function (c) { c.classList.remove('selected'); });
                card.classList.add('selected');
                var r = card.querySelector('input[type=radio]');
                if (r) r.checked = true;
                ImportWizard.state.importMode = card.getAttribute('data-mode') || 'stock';
                ImportWizard.renderReqBar();
                ImportWizard.updateCounterLabel();
            });
        });

        // 平台卡片选择（未配置模板的平台同样可选：走智能识别兜底）
        document.querySelectorAll('#platCards .plat-card').forEach(function (card) {
            if (card.classList.contains('plat-card-add')) return;
            card.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('#platCards .plat-card').forEach(function (c) { c.classList.remove('selected'); });
                card.classList.add('selected');
                var r = card.querySelector('input[type=radio]');
                if (r) r.checked = true;
                ImportWizard.state.platformId   = parseInt(card.getAttribute('data-id'), 10) || 0;
                ImportWizard.state.platformCode = card.getAttribute('data-code') || '';
                if (card.getAttribute('data-has-tpl') !== '1') {
                    LCSC.toast('该平台未配置模板，将使用智能识别（可在映射步骤逐列确认）', 'warning');
                }
                ImportWizard.autoSelectDefaultDone = true;
            });
        });

        if (!ImportWizard.autoSelectDefaultDone) {
            var plats = ImportWizard.cfg.platforms || [];
            var picked = null;
            plats.forEach(function (p) { if (!picked && p.is_default && p.has_tpl) picked = p; });
            if (!picked) plats.forEach(function (p) { if (!picked && p.is_default) picked = p; });
            if (!picked) plats.forEach(function (p) { if (!picked && p.has_tpl) picked = p; });
            if (!picked && plats.length) picked = plats[0];
            if (picked) {
                var card = document.querySelector('.plat-card[data-id="' + picked.id + '"]');
                if (card) card.click();
            }
        }

        // 步骤条：已完成步骤可点击回退，当前步骤忽略，未来步骤禁用
        document.querySelectorAll('#impSteps .import-step').forEach(function (el) {
            el.addEventListener('click', function () {
                var n = parseInt(el.getAttribute('data-step'), 10);
                if (ImportWizard.state.running) {
                    LCSC.toast('正在处理中：请等待当前批次完成，或先暂停再回退', 'warning');
                    return;
                }
                if (n === ImportWizard.state.step) return;
                if (!el.classList.contains('done')) return;      // 未来步骤：禁用
                ImportWizard._hideDetailModal();
                ImportWizard.state.fromFlow = false;
                ImportWizard.goStep(n);
            });
        });

        ImportWizard.renderReqBar();
        ImportWizard.updateCounterLabel();
    },

    // ─ 步骤切换（1~3 为页面区块，4 为详情弹窗）──
    goStep: function (n) {
        this.state.step = n;
        ['1', '2', '3'].forEach(function (s) {
            var el = document.getElementById('impStep' + s);
            // **必须用 classList.toggle，不能写 style.display** ——
            if (el) el.classList.toggle('is-on', String(n) === s);
        });
        document.querySelectorAll('#impSteps .import-step').forEach(function (el) {
            var sn = parseInt(el.getAttribute('data-step'), 10);
            el.classList.toggle('active', sn === n);
            el.classList.toggle('done', sn < n);
            el.setAttribute('title', sn < n ? '点击返回该步骤' : (sn === n ? '当前步骤' : '完成前序步骤后可用'));
        });
        document.getElementById('impSteps').classList.toggle('locked', !!this.state.running);
    },

    // ── 文件 ─
    setFile: function (f) {
        this.state.file = f;
        document.getElementById('fileName').textContent = f.name + '（' + (f.size / 1024).toFixed(1) + ' KB）';
        document.getElementById('fileInfo').classList.add('is-on');
    },
    clearFile: function () {
        this.state.file = null;
        var fi = document.getElementById('fileInput');
        if (fi) fi.value = '';
        document.getElementById('fileInfo').classList.remove('is-on');
    },

    scrollToStep1: function () {
        this.goStep(1);
        var el = document.getElementById('impStep1');
        if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    // ── ① 解析并识别 ──
    parse: function () {
        var st = this.state;
        if (!st.platformId) { LCSC.toast('请先选择平台', 'warning'); return; }
        if (!st.file) { LCSC.toast('请先选择要导入的文件', 'warning'); return; }

        var btn = document.getElementById('btnParse');
        this._busy(btn, true, '解析中...');
        var fd = new FormData();
        fd.append('platform_id', st.platformId);
        fd.append('platform_code', st.platformCode);
        fd.append('source_type', st.platformCode);
        fd.append('excel', st.file);
        fd.append('file', st.file);

        LCSC.post('app/api/router.php?action=import.parse', fd, function (data) {
            ImportWizard._busy(btn, false);
            st.docId      = data.document_id || 0;
            st.headersSig = data.headers_sig || '';
            st.headers    = data.headers || [];
            st.detected   = data.detected || {};
            st.preview    = data.preview || [];
            st.pptFields  = data.fields || ImportWizard.cfg.fields || {};
            if (data.has_template === false) {
                LCSC.toast('该平台未配置模板，已使用智能识别，请在下一步逐列确认', 'warning');
            }
            if (!st.headers.length) { LCSC.toast('未能识别表头，请确认文件内容', 'error'); return; }
            // 先取自定义字段再渲染映射页：下拉里要同时有系统字段与自定义字段
            ImportWizard.loadCustomFields(function () {
                ImportWizard.renderMapping(data);
                ImportWizard.goStep(2);
            });
        }, function (msg) {
            ImportWizard._busy(btn, false);
            LCSC.toast(String(msg), 'error');
        });
    },


    /* 映射页下拉的完整字段表 = 系统字段 + 启用中的自定义字段 */
    mappingFields: function () {
        var base = this.state.pptFields || this.cfg.fields || {};
        var out = {};
        Object.keys(base).forEach(function (k) { out[k] = base[k]; });
        (this.state.customFields || []).forEach(function (f) {
            if (!f || !f.key || !f.enabled) return;
            out['ext:' + f.key] = (f.label || f.key) + '（自定义）';
        });
        return out;
    },

    /* 拉取自定义字段定义并按需重渲染映射页（下拉需含自定义字段） */
    loadCustomFields: function (after) {
        var st = this.state;
        LCSC.fetchJson('app/api/router.php?action=import.custom_fields&op=list')
            .then(function (resp) {
                st.customFields = ((resp && resp.data) || {}).fields || [];
                if (typeof after === 'function') after(st.customFields);
            })
            .catch(function (msg) { LCSC.toast('自定义字段加载失败：' + msg, 'error'); });
    },

    renderMapping: function (data) {
        var st = this.state;
        var prev = st.preview || [];
        var body = '';
        // 系统字段 + 自定义字段（自定义字段在下拉里单成一组，值带 ext: 前缀）
        var fields = this.mappingFields();

        st.autoMap = {};
        st.headers.forEach(function (h, i) {
            var d = st.detected[i] || st.detected[String(i)] || null;
            var cur = d ? (d.field || '') : '';
            var isEmpty = !!(d && d.empty);          // 整列为空：后端已判定自动忽略
            st.autoMap[String(i)] = cur;

            // 示例值：预览行里该列第一个非空值
            var sample = '';
            for (var p = 0; p < prev.length; p++) {
                var v = prev[p] ? prev[p][i] : '';
                if (v != null && String(v).trim() !== '') { sample = String(v).trim(); break; }
            }

            var opts = '<option value="">— 忽略此列 —</option>';
            Object.keys(fields).forEach(function (k) {
                opts += '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + ImportWizard.esc(fields[k]) + '</option>';
            });

            var rule = d ? (d.rule || '') : '';
            var conf = d ? Number(d.confidence || 0) : 0;
            var ruleHtml = rule ? ImportWizard.esc(rule) : '—';
            if (conf > 0 && conf < 1) ruleHtml += '<span class="imp-conf">' + Math.round(conf * 100) + '%</span>';

            var rowCls = (h === '' || isEmpty) ? 'row-ignored' : (cur === '' ? 'row-unknown' : '');
            body += '<tr data-idx="' + i + '" data-empty="' + (isEmpty ? '1' : '0') + '" class="' + rowCls + '">'
                + '<td class="imp-col-name' + (h === '' ? ' is-empty' : '') + '" title="' + ImportWizard.esc(h) + '">'
                + (h === '' ? '（空列）' : ImportWizard.esc(h)) + '</td>'
                + '<td><div class="imp-sample" title="' + ImportWizard.esc(sample) + '">' + (sample === '' ? '—' : ImportWizard.esc(sample)) + '</div></td>'
                + '<td><select class="imp-col-sel" data-idx="' + i + '">' + opts + '</select></td>'
                + '<td class="imp-rule">' + ruleHtml + '</td>'
                + '</tr>';
        });

        document.getElementById('mapBody').innerHTML = body;
        document.querySelectorAll('#mapBody .imp-col-sel').forEach(function (sel) {
            sel.addEventListener('change', function () { ImportWizard.refreshMapState(); });
        });

        document.getElementById('mapSummary').innerHTML =
            '共 ' + st.headers.length + ' 列，文件共 ' + (data.total_rows || 0) + ' 行'
            + (data.sheet_name ? '（Sheet: ' + ImportWizard.esc(data.sheet_name) + '）' : '');

        // 预览
        var ph = '';
        if (prev.length) {
            ph = '<div class="imp-prev-title">数据预览（前 ' + Math.min(prev.length, 5) + ' 行）</div>'
               + '<div class="imp-scroll-box">'
               + '<table class="data-table imp-tb-xs"><thead><tr>';
            st.headers.forEach(function (h) { ph += '<th>' + ImportWizard.esc(h || '') + '</th>'; });
            ph += '</tr></thead><tbody>';
            prev.slice(0, 5).forEach(function (row) {
                ph += '<tr>';
                st.headers.forEach(function (_, i) { ph += '<td>' + ImportWizard.esc(row[i] == null ? '' : row[i]) + '</td>'; });
                ph += '</tr>';
            });
            ph += '</tbody></table></div>';
        }
        document.getElementById('mapPreview').innerHTML = ph;

        ImportWizard.refreshMapState();
    },

    readMapping: function () {
        var columns = {}, ignored = [], mapped = {};
        document.querySelectorAll('#mapBody .imp-col-sel').forEach(function (sel) {
            var idx = sel.getAttribute('data-idx');
            var v = sel.value || '';
            if (v === '') ignored.push(parseInt(idx, 10));
            else { columns[idx] = v; mapped[v] = true; }
        });
        return { columns: columns, ignored: ignored, mapped: mapped };
    },

    /* 未识别列统计 + 警示条 + 必填提示条 + 行样式联动 */
    refreshMapState: function () {
        var r = this.readMapping();
        var unknown = 0, mappedCount = 0, ignoredCount = 0, emptyCount = 0;

        document.querySelectorAll('#mapBody tr').forEach(function (tr) {
            var sel = tr.querySelector('.imp-col-sel');
            var hasHeader = (tr.querySelector('.imp-col-name').getAttribute('title') || '') !== '';
            var isEmpty = tr.getAttribute('data-empty') === '1';      // 整列为空：不计入「待处理」
            var v = sel ? (sel.value || '') : '';
            var isUnknown = (v === '' && hasHeader && !isEmpty);
            tr.classList.toggle('row-unknown', isUnknown);
            tr.classList.toggle('row-ignored', v === '' && (!hasHeader || isEmpty));
            if (isEmpty && v === '') emptyCount++;
            if (isUnknown) unknown++; else if (v !== '') mappedCount++; else ignoredCount++;
        });

        var warn = document.getElementById('mapWarn');
        if (warn) {
            warn.classList.toggle('is-on', unknown > 0);
            if (unknown > 0) {
                warn.innerHTML = '⚠ 有 <strong>' + unknown + '</strong> 列有数据但未识别：默认按「忽略此列」处理（不会写入任何字段），'
                    + '如需导入请在上方逐列指派系统字段。';
            }
        }
        var sum = document.getElementById('mapSummary');
        if (sum) {
            var base = sum.getAttribute('data-base');
            if (base == null) { base = sum.innerHTML; sum.setAttribute('data-base', base); }
            sum.innerHTML = base + '　·　已映射 <strong>' + mappedCount + '</strong> 列'
                + (unknown > 0 ? '，<span class="imp-sum-warn">待处理 ' + unknown + ' 列</span>' : '')
                + (ignoredCount > 0 ? '，忽略 ' + ignoredCount + ' 列' + (emptyCount > 0 ? '（其中 ' + emptyCount + ' 列整列为空）' : '') : '');
        }
        this.renderReqBar();
    },

    renderReqBar: function () {
        var el = document.getElementById('mapReq');
        if (!el) return;
        if (!document.querySelectorAll('#mapBody .imp-col-sel').length) { el.innerHTML = ''; return; }
        var mapped = this.readMapping().mapped;
        var mode = this.state.importMode || 'stock';
        var cfg = this.cfg || {};
        var items = [];

        items.push({ label: '商品编号 或 型号', ok: !!(mapped['platform_part_no'] || mapped['model']) });
        if (mode === 'stock') {
            items.push({ label: '数量（> 0）', ok: !!mapped['quantity'] });
            if (cfg.canViewCost !== false && cfg.enforcePrice) items.push({ label: '单价（' + (window._editionName || '当前版本') + '强制 > 0）', ok: !!mapped['unit_price'] });
        }

        var html = '<span><strong>' + (mode === 'profile' ? '物料建档' : '采购入库') + '</strong> 必填：</span>';
        items.forEach(function (it) {
            html += '<span class="badge ' + (it.ok ? 'badge-green' : 'badge-yellow') + '">'
                + (it.ok ? '✔ ' : '✗ ') + ImportWizard.esc(it.label) + '</span>';
        });
        if (mode === 'profile') html += '<span class="imp-tip">建档模式不校验数量与单价，也不写库存与成本</span>';
        else if (cfg.canViewCost === false) html += '<span class="imp-tip">个人版：文件中的单价将被忽略</span>';
        el.innerHTML = html;
    },

    // ── 自定义字段管理（映射页工具栏入口）──

    /* 打开自定义字段管理弹窗 */
    openCustomFields: function () {
        var modal = document.getElementById('impCfModal');
        if (!modal) return;
        var canEdit = (this.cfg.canEdit !== false);
        var keyEl = document.getElementById('cfKey');
        var labelEl = document.getElementById('cfLabel');
        if (keyEl) { keyEl.value = ''; keyEl.disabled = !canEdit; }
        if (labelEl) { labelEl.value = ''; labelEl.disabled = !canEdit; }
        var addBtn = document.getElementById('cfAddBtn');
        if (addBtn) addBtn.style.display = canEdit ? '' : 'none';
        var tip = document.getElementById('cfTip');
        if (tip) {
            tip.textContent = canEdit
                ? '新增后在上方「对应系统字段」下拉里即可把平台文件的列绑定到该字段，导入时按订单号写入。'
                : '当前账号只有导入权限，无法新增字段（可让有编辑权限的账号添加）。';
        }
        modal.classList.add('open');
        this.renderCustomFields();
    },

    closeCustomFields: function () {
        var modal = document.getElementById('impCfModal');
        if (modal) modal.classList.remove('open');
        // 关掉后把映射页下拉刷一遍（可能刚新增/启用了字段）
        var self = this;
        if (this.state.step === 2) {
            var sels = document.querySelectorAll('#mapBody .imp-col-sel');
            if (sels.length) {
                var picked = [];
                sels.forEach(function (sel) { picked.push([sel.getAttribute('data-idx'), sel.value]); });
                this.loadCustomFields(function () { self.renderMapping({ total_rows: 0, sheet_name: '' }); });
                var apply = function () {
                    var map = {};
                    document.querySelectorAll('#mapBody .imp-col-sel').forEach(function (sel) {
                        map[sel.getAttribute('data-idx')] = sel.value;
                    });
                    picked.forEach(function (p) { if (map[p[0]] !== undefined && p[1]) map[p[0]] = p[1]; });
                    document.querySelectorAll('#mapBody .imp-col-sel').forEach(function (sel) {
                        var v = map[sel.getAttribute('data-idx')];
                        if (v) sel.value = v;
                    });
                    self.refreshMapState();
                };
                setTimeout(apply, 300);
            }
        }
    },

    /* 渲染自定义字段列表 */
    renderCustomFields: function () {
        var host = document.getElementById('cfList');
        if (!host) return;
        var list = this.state.customFields || [];
        if (!list.length) {
            host.innerHTML = '<div class="imp-hint imp-hint-pad">还没有自定义字段</div>';
            return;
        }
        var canEdit = (this.cfg.canEdit !== false);
        var h = '<table class="data-table imp-tb-sm"><thead><tr>'
              + '<th>字段名</th><th>显示名</th><th class="imp-th-70">状态</th>'
              + (canEdit ? '<th class="imp-th-150">操作</th>' : '') + '</tr></thead><tbody>';
        list.forEach(function (f) {
            var k = ImportWizard.esc(f.key || '');
            h += '<tr><td class="mono">' + k + '</td>'
                + '<td>' + ImportWizard.esc(f.label || '') + '</td>'
                + '<td>' + (f.enabled ? '<span class="badge badge-green">启用</span>' : '<span class="badge badge-gray">停用</span>') + '</td>';
            if (canEdit) {
                h += '<td class="td-actions">'
                    + '<button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.cfToggle(\'' + k + '\',' + (f.enabled ? '0' : '1') + ')">'
                    + (f.enabled ? '停用' : '启用') + '</button> '
                    + '<button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.cfDelete(\'' + k + '\',0)">删除</button>'
                    + '<button type="button" class="btn btn-danger btn-xs" onclick="ImportWizard.cfDelete(\'' + k + '\',1)">清理数据</button>'
                    + '</td>';
            }
            h += '</tr>';
        });
        host.innerHTML = h + '</tbody></table>';
    },

    /* 新增自定义字段 */
    cfAdd: function () {
        var key = ((document.getElementById('cfKey') || {}).value || '').trim();
        var label = ((document.getElementById('cfLabel') || {}).value || '').trim();
        if (!key) { LCSC.toast('请填写字段名', 'warning'); return; }
        if (!label) { LCSC.toast('请填写显示名', 'warning'); return; }
        var self = this;
        LCSC.post('app/api/router.php?action=import.custom_fields', { op: 'add', key: key, label: label }, function (data, msg) {
            self.state.customFields = data.fields || [];
            self.renderCustomFields();
            var k = document.getElementById('cfKey');
            var l = document.getElementById('cfLabel');
            if (k) k.value = '';
            if (l) l.value = '';
            LCSC.toast(msg || '已新增', 'success');
        }, function (msg) { LCSC.toast(String(msg), 'error'); });
    },

    /* 启用 / 停用自定义字段 */
    cfToggle: function (key, enabled) {
        var self = this;
        LCSC.post('app/api/router.php?action=import.custom_fields', { op: 'toggle', key: key, enabled: enabled }, function (data, msg) {
            self.state.customFields = data.fields || [];
            self.renderCustomFields();
            LCSC.toast(msg || '已更新', 'success');
        }, function (msg) { LCSC.toast(String(msg), 'error'); });
    },

    cfDelete: function (key, purge) {
        var self = this;
        var run = function () {
            LCSC.post('app/api/router.php?action=import.custom_fields', { op: 'delete', key: key, purge: purge ? '1' : '0' }, function (data, msg) {
                self.state.customFields = data.fields || [];
                self.renderCustomFields();
                LCSC.toast(msg || '已删除', 'success');
            }, function (msg) { LCSC.toast(String(msg), 'error'); });
        };
        AdminUIConfirm({
            title: '删除自定义字段',
            message: purge
                ? ('将删除字段「' + key + '」并清理它在所有订单上的历史值（订单记录本身保留）。此操作不可撤销，是否继续？')
                : ('将删除字段定义「' + key + '」，历史值保留但不再显示。是否继续？'),
            okText: '确认删除',
            onOk: run
        });
    },

    /* 批量动作：忽略全部未识别（无自动识别建议的列一律按忽略处理） */
    ignoreAllUnknown: function () {
        var n = 0;
        document.querySelectorAll('#mapBody tr').forEach(function (tr) {
            var sel = tr.querySelector('.imp-col-sel');
            if (!sel) return;
            var hasHeader = (tr.querySelector('.imp-col-name').getAttribute('title') || '') !== '';
            if (!hasHeader) return;
            if ((this.autoValueOf(sel) || '') !== '') return;     // 已识别列不动
            if (sel.value !== '') n++;
            sel.value = '';
        }.bind(this));
        this.refreshMapState();
        LCSC.toast(n > 0 ? ('已忽略 ' + n + ' 个未识别列') : '没有需要忽略的未识别列', 'info');
    },

    /* 批量动作：重置为模板建议（自动识别结果） */
    resetMapping: function () {
        var auto = this.state.autoMap || {};
        document.querySelectorAll('#mapBody .imp-col-sel').forEach(function (sel) {
            sel.value = auto[sel.getAttribute('data-idx')] || '';
        });
        this.refreshMapState();
        LCSC.toast('已重置为模板建议', 'info');
    },

    /* 该列的自动识别建议值 */
    autoValueOf: function (sel) {
        return (this.state.autoMap || {})[String(sel.getAttribute('data-idx'))] || '';
    },

    backToStep1: function () { this.goStep(1); },

    // ── ③ 建批次 + 分批处理 ──
    stage: function () {
        var st = this.state;
        var r = this.readMapping();
        if (!Object.keys(r.columns).length) { LCSC.toast('请先完成映射识别', 'warning'); return; }
        if (!r.mapped['platform_part_no'] && !r.mapped['model']) {
            LCSC.toast('至少需要映射「商品编号」或「型号」其中一列', 'error'); return;
        }
        if ((st.importMode || 'stock') === 'stock') {
            if (!r.mapped['quantity']) {
                LCSC.toast('采购入库需要映射「数量」列（未映射时所有行都会因数量为 0 失败）', 'error'); return;
            }
            if (this.cfg.canViewCost !== false && this.cfg.enforcePrice && !r.mapped['unit_price']) {
                LCSC.toast((window._editionName || '当前版本') + '强制单价 > 0：请映射「单价」列', 'error'); return;
            }
        }

        st.isSample = !!(document.getElementById('isSample') || {}).checked;
        var wantTemplate = !!(document.getElementById('saveAsTemplate') || {}).checked;
        var btn = document.getElementById('btnStage');
        this._busy(btn, true, '提交中...');

        LCSC.post('app/api/router.php?action=import.stage', {
            document_id: st.docId,
            headers_sig: st.headersSig || '',
            platform_id: st.platformId,
            platform_code: st.platformCode,
            import_mode: st.importMode || 'stock',
            default_category: ((document.getElementById('defaultCategory') || {}).value || '').trim(),
            headers: JSON.stringify(st.headers),
            detected: JSON.stringify(st.detected || []),
            columns: JSON.stringify(r.columns),
            ignored: JSON.stringify(r.ignored),
            is_sample: st.isSample ? 1 : 0
        }, function (data) {
            ImportWizard._busy(btn, false);
            st.batchId = data.batch_id || 0;
            if (!st.batchId) { LCSC.toast('建批次失败', 'error'); return; }
            if (data.ignored_cols > 0) { LCSC.toast('已忽略 ' + data.ignored_cols + ' 列未映射字段', 'info'); }
            if (wantTemplate) ImportWizard.persistTemplate(r.columns, r.ignored);
            ImportWizard.state.paused = false;
            ImportWizard.state.running = true;
            // 新一轮从0% 起算：上轮残留的补间值与 rAF 句柄必须清掉，
            ImportWizard._pctShown = 0;
            if (ImportWizard._pctRaf) { cancelAnimationFrame(ImportWizard._pctRaf); ImportWizard._pctRaf = 0; }
            ImportWizard.updateProgress({ processed: 0, total: data.queued || data.total || 0, percent: 0, inserted: 0, updated: 0, skipped: 0, errors: data.errors || 0 });
            ImportWizard.goStep(3);
            ImportWizard.tick();
        }, function (msg) {
            ImportWizard._busy(btn, false);
            LCSC.toast(String(msg), 'error');
        });
    },

    persistTemplate: function (columns, ignored) {
        var st = this.state;
        function doSave(matchKeys) {
            LCSC.post('app/api/router.php?action=import.template_save', {
                platform_id: st.platformId,
                platform_code: st.platformCode,
                headers: JSON.stringify(st.headers),
                columns: JSON.stringify(columns),
                ignored: JSON.stringify(ignored),
                match_keys: JSON.stringify(matchKeys || [])
            }, function () {
                LCSC.toast('已把本次列映射保存为该平台模板', 'success');
            }, function (msg) {
                LCSC.toast('模板保存失败：' + msg, 'warning');
            });
        }
        LCSC.fetchJson('app/api/router.php?action=import.template_get&platform_id=' + encodeURIComponent(st.platformId))
            .then(function (resp) { doSave(((resp && resp.data) || {}).match_keys || []); })
            .catch(function () { doSave([]); });
    },

    tick: function () {
        var st = this.state;
        if (!st.batchId || !st.running) return;
        if (st.paused) return;

        LCSC.post('app/api/router.php?action=import.chunk', { batch_id: st.batchId }, function (data) {
            ImportWizard.updateProgress(data);
            if (data.done) {
                ImportWizard.state.running = false;
                ImportWizard.updateProgress(data);
                var w = (ImportWizard.state.importMode === 'profile') ? '补充' : '叠加';
                LCSC.toast('导入完成：新增' + data.inserted + ' / ' + w + ' ' + data.updated + ' / 跳过 ' + data.skipped + ' / 失败 ' + data.errors, 'success');
                // 详情弹窗先开：它要盖在页面上，而下面的「回第一步 + 刷新批次列表」
                // 都不涉及重载，两者不会互相打断（早先把 goStep(4) 放在弹窗之后，
                // 结果 1/2/3 三个面板全隐藏、页面主体空白，只剩一个浮层）。
                ImportWizard.detail(st.batchId, true);
                ImportWizard.finishFlow();
            } else {
                setTimeout(function () { ImportWizard.tick(); }, 120);
            }
        }, function (msg) {
            ImportWizard.state.running = false;
            ImportWizard.goStep(3);                       // 刷新步骤条锁定态（保留已完成的进度与计数）
            var t = document.getElementById('progText');
            if (t) t.textContent = '处理中断：' + msg;
            LCSC.toast('处理失败：' + msg, 'error');
        });
    },

    /* 导入完成后的收尾：回第一步 + 复位进度区 */
    finishFlow: function () {
        this.state.running = false;
        this.state.paused = false;
        this.resetProgressView();
        this.goStep(1);
    },

    /* 局部刷新「最近导入」列表（状态 / 计数在导入完成后会变） */
    refreshBatchList: function () {
        var box = document.getElementById('impBatchList');
        if (!box) return;
        LCSC.fragmentRefresh(box, 'index.php?v=import&fragment=batch_list', {
            json: false,                        // 片段接口回传裸 HTML，不走 {code,msg,data} 信封
            busyKey: 'impBatchList',
            mustContain: 'imp-batch-list',      // 确认后端真的走了片段分支（没走会把整页塞进来）
            onFail: function (err) {
                LCSC.toast('批次列表刷新失败：' + ((err && err.message) ? err.message : '网络异常'), 'error');
            }
        });
    },

    /* 复位进度区显示（下次导入从 0% / 计数全 0 开始，不残留上一次的数字） */
    resetProgressView: function () {
        this.updateProgress({ processed: 0, total: 0, percent: 0, inserted: 0, updated: 0, skipped: 0, errors: 0, done: false });
        var wrap = document.getElementById('progWrap');
        if (wrap) wrap.classList.remove('is-done');
        var pause = document.getElementById('btnPause');
        if (pause) pause.style.display = '';
        this.clearFile();
    },

    togglePause: function () {
        var st = this.state;
        st.paused = !st.paused;
        var btn = document.getElementById('btnPause');
        if (btn) {
            btn.textContent = st.paused ? '▶ 继续' : '⏸ 暂停';
            btn.className = st.paused ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm';
        }
        var t = document.getElementById('progText');
        if (t && st.running) t.textContent = st.paused ? '已暂停（数据不会丢失，可继续）' : '正在写入数据库…';
        if (!st.paused) { st.running = true; this.tick(); }
    },

    /* 进度与计数（计数为批次累计值） */
    updateProgress: function (d) {
        d = d || {};
        this.tweenTo((d.percent != null) ? Number(d.percent) : 0);

        var wrap = document.getElementById('progWrap');
        if (wrap) wrap.classList.toggle('is-done', !!d.done);

        var t = document.getElementById('progText');
        if (t) {
            if (d.done) t.textContent = '处理完成';
            else if (this.state.paused) t.textContent = '已暂停（数据不会丢失，可继续）';
            else t.textContent = '正在写入数据库…';
        }

        this.setCount('cProcessed', d.processed);
        this.setCount('cTotal', d.total);
        this.setCount('cInserted', d.inserted);
        this.setCount('cUpdated', d.updated);
        this.setCount('cSkipped', d.skipped);
        this.setCount('cErrors', d.errors);

        var toDetail = document.getElementById('btnToDetail');
        if (toDetail) toDetail.classList.toggle('is-on', !!d.done);
        var pause = document.getElementById('btnPause');
        if (pause) pause.style.display = d.done ? 'none' : '';
    },

    /* 进度条补间：把当前显示值平滑推向新的真实值 */
    tweenTo: function (target) {
        var self = this;
        var to = Math.max(0, Math.min(100, target));
        var inner = document.getElementById('progInner');
        var pctEl = document.getElementById('progPercent');
        if (!inner) return;

        if (this._pctRaf) { cancelAnimationFrame(this._pctRaf); this._pctRaf = 0; }
        var from = (typeof this._pctShown === 'number') ? this._pctShown : 0;
        if (from >= to) {                       // 进度只增不减；目标变小（如重置）时直接落位
            this._pctShown = to;
            inner.style.width = to + '%';
            if (pctEl) pctEl.textContent = Math.round(to) + '%';
            return;
        }

        var dur = 260;                         // 与 CSS 的 .25s 过渡同量级，不产生拖尾感
        var t0 = null;
        var step = function (ts) {
            if (t0 === null) t0 = ts;
            var k = Math.min(1, (ts - t0) / dur);
            var v = from + (to - from) * k;
            self._pctShown = v;
            inner.style.width = v.toFixed(2) + '%';
            if (pctEl) pctEl.textContent = Math.round(v) + '%';
            if (k < 1) {
                self._pctRaf = requestAnimationFrame(step);
            } else {
                self._pctShown = to;
                self._pctRaf = 0;
                inner.style.width = to + '%';
                if (pctEl) pctEl.textContent = Math.round(to) + '%';
            }
        };
        this._pctRaf = requestAnimationFrame(step);
    },

    setCount: function (id, v) {
        var el = document.getElementById(id);
        if (el) el.textContent = (v == null ? 0 : v);
    },

    /* 计数标签随导入类型变化（叠加 ↔ 补充字段） */
    updateCounterLabel: function () {
        var el = document.getElementById('cUpdLabel');
        if (el) el.textContent = (this.state.importMode === 'profile') ? '补充字段' : '叠加';
    },

    // ─ ④ 处理详情 ──
    detail: function (batchId, fromFlow, focusErrors) {
        this.state.detailBatch = batchId;
        this.state.fromFlow = !!fromFlow;
        this.state.focusErrors = !!focusErrors;
        var modal = document.getElementById('impDetailModal');
        document.getElementById('detailHead').innerHTML = '<span class="imp-detail-meta">加载中…</span>';
        document.getElementById('detailStats').innerHTML = '';
        document.getElementById('detailRows').innerHTML = '';
        if (modal) modal.classList.add('open');

        LCSC.fetchJson('app/api/router.php?action=import.batch_detail&batch_id=' + encodeURIComponent(batchId))
            .then(function (resp) {
                var d = (resp && resp.data) || {};
                ImportWizard.state.lastDetail = d;
                ImportWizard.renderDetail(d, fromFlow);
            })
            .catch(function (msg) {
                document.getElementById('detailHead').innerHTML = '<span class="badge badge-red">加载失败</span>';
                LCSC.toast(String(msg), 'error');
            });
    },

    renderDetail: function (d, fromFlow) {
        var b = d.batch || {};
        var sm = (this.cfg.batchLabels || {})[b.status] || [b.status || '', 'badge-gray'];
        var isProfile = (b.import_mode === 'profile');

        document.getElementById('detailHead').innerHTML =
            '<div class="imp-detail-name">' + ImportWizard.esc(b.file_name || '') + '</div>'
            + '<div class="imp-detail-meta">平台：' + ImportWizard.esc(b.platform || '') + '　'
            + '类型：' + (isProfile ? '物料建档' : '采购入库') + '　'
            + '总行数：' + (b.total_rows || 0) + '　时间：' + ImportWizard.esc(String(b.created_at || '').substring(0, 16)) + '　'
            + '<span class="badge ' + sm[1] + '">' + sm[0] + '</span></div>';

        // 四张卡片可点击：点哪张就下方明细切到哪类结论
        var cells = [
            ['insert', '新增物料', b.inserted || 0, 'v-ins', '本次新建的物料（点击查看逐行明细）'],
            ['update', isProfile ? '补充字段' : '叠加库存', b.updated || 0, 'v-upd', isProfile ? '命中既有物料并补充了空字段' : '命中既有物料并叠加了库存（点击查看逐行明细）'],
            ['skip', '跳过', b.skipped || 0, 'v-skip', '重复行（同订单+同编号已导入过）与非物料行（无商品编号且无型号，如配送费/说明行）'],
            ['error', '失败', b.errors || 0, 'v-err', '行校验或写库失败，点击查看逐行原因']
        ];
        var html = '';
        cells.forEach(function (c) {
            html += '<button type="button" class="imp-detail-cell" data-act="' + c[0] + '" title="' + ImportWizard.esc(c[4]) + '">'
                + '<div class="k">' + c[1] + '</div>'
                + '<div class="v ' + c[3] + '">' + c[2] + '</div></button>';
        });
        var stats = document.getElementById('detailStats');
        stats.innerHTML = html;
        stats.querySelectorAll('.imp-detail-cell').forEach(function (el) {
            el.addEventListener('click', function () { ImportWizard.showRows(el.getAttribute('data-act')); });
        });

        this.state.errors = d.errors || [];

        var exportBtn = document.getElementById('detailExportBtn');
        var rbBtn = document.getElementById('detailRollbackBtn');
        if (exportBtn) exportBtn.classList.toggle('is-on', this.state.errors.length > 0);
        if (rbBtn) rbBtn.classList.toggle('is-on', !!d.can_rollback);

        var first = (b.errors > 0) ? 'error' : 'insert';
        this.state.rowFilter = this.state.focusErrors ? 'error' : first;
        this.state.rowPage = 1;
        this.loadRows(this.state.rowFilter, 1);
    },

    /* 切换明细区到指定结论并加载（卡片点击 / 分页都走这里） */
    showRows: function (act) {
        if (!this.state.detailBatch) return;
        this.state.rowFilter = act;
        this.state.rowPage = 1;
        this.loadRows(act, 1);
    },

    /* 拉取某结论的逐行明细并渲染 */
    loadRows: function (act, page) {
        var st = this.state;
        var host = document.getElementById('detailRows');
        if (!host) return;
        host.innerHTML = '<div class="imp-hint imp-hint-spaced">加载中…</div>';
        document.querySelectorAll('#detailStats .imp-detail-cell').forEach(function (el) {
            el.classList.toggle('active', el.getAttribute('data-act') === act);
        });

        var url = 'app/api/router.php?action=import.batch_rows&batch_id=' + encodeURIComponent(st.detailBatch)
                + '&action_filter=' + encodeURIComponent(act) + '&page=' + encodeURIComponent(page);
        LCSC.fetchJson(url)
            .then(function (resp) {
                var d = (resp && resp.data) || {};
                st.rowData = d;
                st.rowPage = d.page || 1;
                st.rowTotalPages = d.total_pages || 1;
                ImportWizard.renderRows(act);
            })
            .catch(function (msg) {
                host.innerHTML = '<div class="imp-hint-error">明细加载失败：' + ImportWizard.esc(String(msg)) + '</div>';
            });
    },

    rowGo: function (page) {
        this.loadRows(this.state.rowFilter, page);
    },

    renderRows: function (act) {
        var host = document.getElementById('detailRows');
        if (!host) return;
        var d = this.state.rowData || {};
        var rows = d.rows || [];
        var total = Number(d.total || 0);

        var titles = {
            insert: '新增物料明细', update: (this.state.lastDetail && this.state.lastDetail.batch && this.state.lastDetail.batch.import_mode === 'profile') ? '补充字段明细' : '叠加库存明细',
            skip: '跳过明细', error: '失败明细'
        };
        if (!rows.length) {
            host.innerHTML = '<div class="imp-rows-title">' + titles[act] + '（共 0 条）</div>'
                + '<div class="imp-hint">该批次没有此类记录</div>';
            return;
        }

        var h = '<div class="imp-rows-title">' + titles[act] + '（共 ' + total + ' 条'
              + (total >= 500 ? '，仅显示前 500 条' : '') + '）</div>'
              + '<div class="imp-scroll-box-tall">'
              + '<table class="data-table imp-tb-sm"><thead><tr>'
              + '<th class="imp-th-60">行号</th>';

        // 叠加/新增有物料名可展示；跳过与失败的行没有 part_id，退回展示原始数据
        if (act === 'insert' || act === 'update') {
            h += '<th>物料</th><th class="imp-th-150">' + (act === 'update' ? '库存变化' : '入库数量') + '</th>';
        } else {
            h += '<th>原始数据</th>';
        }
        if (act === 'skip' || act === 'error') h += '<th class="imp-th-pct34">' + (act === 'error' ? '失败原因' : '跳过原因') + '</th>';
        h += '</tr></thead><tbody>';

        rows.forEach(function (r) {
            h += '<tr><td class="mono">' + (r.row_num || '') + '</td>';
            if (act === 'insert' || act === 'update') {
                var label = r.part_label || '';
                h += '<td class="imp-err-raw" title="' + ImportWizard.esc(label) + '">' + (ImportWizard.esc(label) || '—') + '</td>';
                if (act === 'update') {
                    // 叠加：简略展示库存前→后
                    h += '<td class="mono">' + Number(r.qty_before || 0) + ' → ' + Number(r.qty_after || 0)
                       + '<span class="imp-qty-delta"> (+' + Number(r.qty_change || 0) + ')</span></td>';
                } else {
                    h += '<td class="mono">' + Number(r.qty_change || 0) + '</td>';
                }
            } else {
                h += '<td class="imp-err-raw" title="' + ImportWizard.esc(r.raw_data || '') + '">' + (ImportWizard.esc(r.raw_data || '') || '—') + '</td>'
                   + '<td class="imp-reason' + (act === 'error' ? ' is-err' : '') + '">'
                   + (ImportWizard.esc(r.reason || '') || (act === 'error' ? '（历史批次无逐行原因）' : '—')) + '</td>';
            }
            h += '</tr>';
        });
        h += '</tbody></table></div>';

        var pages = Math.max(1, Number(d.total_pages || 1));
        var page = Number(d.page || 1);
        if (pages > 1) {
            h += '<div class="imp-pager">'
                + '<button type="button" class="btn btn-ghost btn-xs" ' + (page <= 1 ? 'disabled' : '')
                + ' onclick="ImportWizard.rowGo(' + (page - 1) + ')">上一页</button>'
                + '<span class="pg-info">第 ' + page + ' / ' + pages + ' 页</span>'
                + '<button type="button" class="btn btn-ghost btn-xs" ' + (page >= pages ? 'disabled' : '')
                + ' onclick="ImportWizard.rowGo(' + (page + 1) + ')">下一页</button>'
                + '</div>';
        }
        host.innerHTML = h;
    },

    _hideDetailModal: function () {
        var modal = document.getElementById('impDetailModal');
        if (modal) modal.classList.remove('open');
    },

    closeDetail: function () {
        this._hideDetailModal();
        // 从导入流程进入 → 批次列表需要刷新（状态/计数刚变）。
        // **只局部刷新，不整页重载**：重载会把用户正在看的明细弹窗刷掉，
        if (this.state.fromFlow) {
            this.state.fromFlow = false;
            this.refreshBatchList();
        }
    },

    exportErrors: function (batchId) {
        var id = batchId || this.state.detailBatch || this.state.batchId;
        if (!id) { LCSC.toast('缺少批次', 'warning'); return; }
        LCSC.toast('正在生成失败清单，请稍候…', 'info');
        window.location = 'app/api/router.php?action=import.errors_export&batch_id=' + encodeURIComponent(id);
    },

    rollback: function (batchId) {
        var id = batchId || this.state.detailBatch || this.state.batchId;
        if (!id) { LCSC.toast('缺少批次', 'warning'); return; }

        var d = this.state.lastDetail || {};
        var isProfile = !!(d.batch && d.batch.import_mode === 'profile' && String(d.batch.id) === String(id));
        AdminUIConfirm({
            title: '撤销本次导入（高危）',
            message: (isProfile
                ? '将回退该批次的影响：\n· 本次「新增」的物料会被标记删除\n· 建档模式只补主档字段，字段补充不会自动回滚（需人工处理）\n\n操作不可重复执行，是否继续？'
                : '将回退该批次的全部影响：\n· 本次「新增」的物料会被标记删除\n· 本次「叠加」的库存会按可用量扣回\n· 生成反向流水留痕（可追溯）\n\n操作不可重复执行，是否继续？'),
            okText: '确认撤销',
            onOk: function () {
                LCSC.post('app/api/router.php?action=import.rollback', { batch_id: id }, function (data, msg) {
                    LCSC.toast(msg || '已撤销', 'success');
                    ImportWizard.state.fromFlow = false;
                    ImportWizard.closeDetail();
                    // 撤销后批次状态与计数都变了 —— 局部刷新列表即可，不整页重载
                    ImportWizard.refreshBatchList();
                }, function (msg) { LCSC.toast(String(msg), 'error'); });
            }
        });
    },

    // ── 工具 ──
    _busy: function (btn, busy, label) {
        if (!btn) return;
        if (busy) {
            if (!btn.dataset.origText) btn.dataset.origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = label || '处理中...';
        } else {
            btn.disabled = false;
            if (btn.dataset.origText) btn.textContent = btn.dataset.origText;
        }
    }
};

document.addEventListener('DOMContentLoaded', function () {
    ImportWizard.init();
    // 蒙层点击关闭详情弹窗
    var modal = document.getElementById('impDetailModal');
    if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) ImportWizard.closeDetail(); });
    // ESC 关闭详情弹窗
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('open')) ImportWizard.closeDetail();
    });
});
