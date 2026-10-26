// modules/bom/Assets/js/bom-core.js — BOM 管理页（?v=bom）· 配置读取、缺料分析刷新

var _bomCfg = window.BomPageConfig || {};

function refreshShortage() {
    var qty = parseInt(document.getElementById('shortageQty').value) || 1;
    var btn = document.querySelector('#shortageQty + button');
    var originalText = btn ? btn.textContent : '';

    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
        btn.textContent = '计算中...';
    }

    fetch('api.php?action=bom.shortage&project_id=' + _bomCfg.selId + '&version_id=' + ((_bomCfg.viewVersionId) || 0) + '&quantity=' + qty)
        .then(r => r.json())
        .then(function(res) {
            if (res.code !== 0) {
                LCSC.toast(res.msg || '计算失败', 'error');
                return;
            }
            var d = res.data;
            var html = d.shortage_count > 0
                ? '<span class="bom-summary-danger">缺 ' + d.shortage_count + ' 种物料</span> · 共 ' + d.total_items + ' 种'
                : '<span class="bom-summary-ok">库存充足</span> · 共 ' + d.total_items + ' 种';
            document.getElementById('shortageSummary').innerHTML = html;

            var groups = document.getElementById('shortageGroups');
            if (groups) {
                groups.innerHTML =
                    '<div class="bom-metric"><span class="bom-metric-k">未绑定</span>'
                    + '<span class="bom-metric-v is-warn">' + (d.group_incomplete_count || 0) + '<i>种</i></span></div>'
                    + '<div class="bom-metric"><span class="bom-metric-k">库存不足</span>'
                    + '<span class="bom-metric-v is-danger">' + (d.group_stock_count || 0) + '<i>种</i></span></div>'
                    + '<div class="bom-metric"><span class="bom-metric-k">已计入替代料</span>'
                    + '<span class="bom-metric-v is-ok">' + (d.alt_used_total || 0) + '<i>件</i></span></div>';
            }
            var cap = document.getElementById('shortageCapacity');
            if (cap) {
                if ((d.blocked_by_pending || 0) > 0) {
                    cap.innerHTML = '存在 <b>' + d.blocked_by_pending + '</b> 条未绑定物料（未关联主库，可用量按 0 计），无法核算产能';
                    cap.className = 'bom-capacity warn';
                } else {
                    cap.innerHTML = '按当前库存最大可生产 <b>' + (d.max_producible || 0) + '</b> 份'
                                  + '<span class="bom-capacity-sub">单机用量口径，已含替代料可用量</span>';
                    cap.className = 'bom-capacity';
                }
                // 头部「缺料」KPI 同步
                var headShort = document.getElementById('bomShortageKpi');
                if (headShort) headShort.textContent = d.shortage_count || 0;
            }

            var tbody = '';
            d.shortage_list.forEach(function(s) {
                var catCell = (s.category === 'incomplete')
                    ? '<span class="bom-st unbound" title="未绑定主库物料，可用量按 0 计；到明细表点「替代料」绑定或替换"><i></i>未绑定</span>'
                    : '<span class="bom-st err"><i></i>库存不足</span>';
                var altCell = (s.alt_qty > 0) ? s.alt_qty : '—';
                tbody += '<tr>'
                    + '<td>' + catCell + '</td>'
                    + '<td class="bom-s-model">' + (s.model||'') + '</td>'
                    + '<td class="bom-s-no">' + (s.part_no||'') + '</td>'
                    + '<td>' + (s.brand||'') + '</td>'
                    + '<td class="bom-s-pkg">' + (s.package||'') + '</td>'
                    + '<td class="ta-r mono">' + s.need_qty + '</td>'
                    + '<td class="ta-r mono">' + s.have_qty + '</td>'
                    + '<td class="ta-r alt">' + altCell + '</td>'
                    + '<td class="ta-r gap">' + s.shortage_qty + '</td>'
                    + '<td>' + (s.location||'') + '</td>'
                    + '</tr>';
            });
            if (!tbody) tbody = '<tr><td colspan="10" class="bom-empty-row">无缺料</td></tr>';
            document.getElementById('shortageTable').querySelector('tbody').innerHTML = tbody;
            // 出库前置校验条数同步（供一键出库本地拦截）
            var coForm = document.getElementById('bomCheckoutForm');
            if (coForm && typeof d.pending_count !== 'undefined') coForm.setAttribute('data-pending-count', d.pending_count);
            // 「更多」菜单里的采购清单导出：份数与分组随核算参数联动
            var qtyNow = parseInt(document.getElementById('shortageQty').value, 10) || 1;
            var baseNow = 'api.php?action=bom.shortage_csv&project_id=' + _bomCfg.selId + '&version_id=' + ((_bomCfg.viewVersionId) || 0) + '&quantity=' + qtyNow;
            var map = { bomCsvAll: baseNow, bomCsvStock: baseNow + '&category=stock', bomCsvInc: baseNow + '&category=incomplete' };
            Object.keys(map).forEach(function(id){
                var a = document.getElementById(id);
                if (a) a.setAttribute('href', map[id]);
            });

            if (d.shortage_count > 0) {
                LCSC.toast('计算完成：缺 ' + d.shortage_count + ' 种物料', 'warning');
            } else {
                LCSC.toast('计算完成：库存充足', 'success');
            }
        })
        .catch(function(e) {
            LCSC.toast('网络错误：' + e.message, 'error');
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
                btn.textContent = originalText;
            }
        });
}
