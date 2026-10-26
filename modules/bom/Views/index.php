<?php
declare(strict_types=1);
/** BOM 管理页面 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../modules/bom/Service/BomService.php';
$user = requireLogin();
if (!hasPermission('can_export')) { header('Location: ' . siteUrl('index.php')); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$bom  = new BomManager($db, $uid, $dataUid);

use Modules\Bom\Controller\BomController;

// ── 全部写操作走 api.php → BomRoutes，本页不处理页面级 POST ──
$msg = null;
$msgType = 'ok';

// ── GET：导出 BOM 物料 CSV（保留页面直下载） ──
if (($_GET['export_bom'] ?? '') !== '') {
    $expResult = BomController::handleExport($db, $uid, $dataUid, $bom);
    if ($expResult['msg'] !== null) {
        $msg = $expResult['msg'];
        $msgType = $expResult['msgType'];
    }
}

// ── 数据准备 ──
$viewData = BomController::prepareViewData($db, $uid, $dataUid, $bom);
extract($viewData);

$pageTitle = $selProject ? $selProject['name'] : 'BOM管理';
$activePage = 'bom';

\App\Core\AssetManager::addCss('modules/bom/Assets/css/bom_manager.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<!-- BOM 专属 CSS -->

<!-- BOM 页面 HTML -->
<div class="main">
<div class="glass-box">

<?php if ($msg): ?>
<div class="flash <?=$msgType==='ok'?'ok':($msgType==='warn'?'warn':'err')?>"><?=h($msg)?></div>
<?php endif; ?>

<?php if ($selProject): ?>
    <!-- ════════ 项目头：标识区 + 关键指标簇 ════════ -->
    <div class="bom-hero" id="bomHeadBar">
        <div class="bom-hero-id">
            <a href="index.php?v=bom" class="bom-back" title="返回 BOM 项目列表">
                <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M10 3.2 5.4 8l4.6 4.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>列表</span>
            </a>
            <div class="bom-hero-txt">
                <div class="bom-hero-name">
                    <h2 id="bomProjectName"><?=h($selProject['name'])?></h2>
                    <span class="bom-hero-n"><b id="bomTotalCount"><?=$totalItems?></b><span>项物料</span></span>
                </div>
                <div class="bom-hero-sub<?= $selProject['description'] === '' ? ' is-empty' : '' ?>" id="bomProjectDesc"><?=h($selProject['description'])?></div>
            </div>
        </div>

        <div class="bom-kpis">
            <?php if(\App\Core\Edition::canViewCost()): ?>
            <div class="bom-kpi" id="bomCostInline" title="Σ 数量 × 加权平均单价（实时计算）">
                <span class="bom-kpi-k">物料成本</span>
                <span class="bom-kpi-v">¥<?=number_format((float)$bomCost['total_cost'], 2)?></span>
                <?php if(!empty($bomCost['no_cost_count'])): ?>
                <span class="bom-kpi-tag" title="这些物料的加权平均成本为 0，未计入总成本"><?=$bomCost['no_cost_count']?> 项无成本</span>
                <?php endif; ?>
            </div>
            <?php if($bomCost['total_cost'] > 0): ?>
            <div class="bom-kpi">
                <span class="bom-kpi-k">利润率 <span class="q-tip" data-tip="按利润率折算对外报价，修改后自动保存。">?</span></span>
                <span class="bom-kpi-v bom-kpi-input">
                    <input type="number" id="bomProfitMargin" value="<?=htmlspecialchars((string)$bomCost['profit_margin'])?>" min="0" max="99.99" step="0.01" onchange="saveBomProfitMargin(this.value)">
                    <i>%</i>
                </span>
            </div>
            <div class="bom-kpi">
                <span class="bom-kpi-k">对外报价</span>
                <span class="bom-kpi-v is-green" id="bomQuotePrice">¥<?=number_format(max(0, $bomCost['total_cost'] * (1 + $bomCost['profit_margin'] / 100)), 2)?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <div class="bom-kpi<?= (int)($shortage['shortage_count'] ?? 0) > 0 ? ' is-danger' : ' is-ok' ?>">
                <span class="bom-kpi-k">缺料</span>
                <span class="bom-kpi-v"><b id="bomShortageKpi"><?=(int)($shortage['shortage_count'] ?? 0)?></b><i>种</i></span>
            </div>
        </div>
    </div>

    <!-- ══ 吸顶控制条：状态分段筛选 + 版本切换 ══ -->
    <div class="bom-sticky" id="bomStickyBar">
    <div class="bom-controlbar">
        <div class="bom-seg" id="bomSummaryArea" role="tablist" aria-label="按库存状态筛选">
            <?php


            $bomCards = [
                'unbound'     => ['label' => '未绑定',   'color' => 'var(--purple)',
                                  'tip'   => '导入时未匹配到主库物料、也未经「替代料」绑定/替换的行；缺料分析按可用量 0 计，点该行「替代料」从物料库选择并绑定即可转为正常行'],
                'ok'          => ['label' => '库存充足', 'color' => 'var(--green)', 'tip' => '已匹配主库且本料+替代料库存 ≥ 需求量'],
                'insufficient'=> ['label' => '库存不足', 'color' => 'var(--yellow)', 'tip' => '已匹配主库，但本料+替代料可用量 < 需求量'],
            ];
            ?>
            <button type="button" class="bom-seg-all<?= $filter === '' ? ' active' : '' ?>" data-status="" onclick="applyBomFilter('')">全部</button>
            <?php foreach ($bomCards as $st => $cs): ?>
            <button type="button" class="bom-seg-i<?= $filter === $st ? ' active' : '' ?>" data-status="<?= $st ?>" onclick="applyBomFilter('<?= $st ?>')" role="tab" aria-selected="<?= $filter === $st ? 'true' : 'false' ?>">
                <i class="bom-seg-dot"></i><b data-summary="<?= $st ?>"><?= (int)($summary[$st] ?? 0) ?></b><span><?= $cs['label'] ?></span><?php if (!empty($cs['tip'])): ?><span class="bom-seg-tip" title="<?= h($cs['tip']) ?>">ⓘ</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php if ($selProject && $versionManaged && !empty($bomVersions) && hasFeature('feature_bom_version')): ?>
        <div class="bom-verbar" id="bomVersionBar">
            <span class="bom-ver-label">版本</span>
            <div class="bom-seg bom-seg-ver" id="bomVersionTabs">
                <?php
                $curVerId = (int)($viewVersionId ?? 0);
                $curVer = $viewVersion ?? null;
                // 版本模型简化：草稿=「编辑中」（唯一可写），已发布/已归档=只读留档
                $currentDraft = null;
                foreach ($bomVersions as $bv) { if ($bv['is_current'] && $bv['status']==='draft') { $currentDraft = $bv; break; } }
                $currentDraft = $currentDraft ?: ($curVer && $curVer['status']==='draft' ? ['id'=>(int)$curVer['id'],'version'=>$curVer['version']] : null);
                ?>
                <?php if ($currentDraft): ?>
                <button type="button" class="bom-ver-i is-draft<?= $curVerId===(int)$currentDraft['id'] ? ' active' : '' ?>" data-version="<?=(int)$currentDraft['id']?>" onclick="bomSwitchVersion(<?=(int)$currentDraft['id']?>)">
                    <b><?=h($currentDraft['version'])?></b><span>编辑中</span>
                </button>
                <?php endif; ?>
                <?php foreach ($bomVersions as $bv):
                    if ($bv['is_current'] && $bv['status']==='draft') continue;
                    $isViewing = $curVerId === (int)$bv['id'];
                    $tag = $bv['status'] === 'released' ? '已发布' : '已归档';
                ?>
                <button type="button" class="bom-ver-i<?= $isViewing ? ' active' : '' ?>" data-version="<?=intval($bv['id'])?>" onclick="bomSwitchVersion(<?=intval($bv['id'])?>)" title="<?=$tag?>留档（只读）">
                    <b><?=h($bv['version'])?></b><span><?=$tag?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="bom-ver-actions">
                <button type="button" class="btn btn-ghost btn-xs" onclick="bomOpenVerPanel('release')" title="把当前草稿定版为只读留档">发布留档</button>
                <button type="button" class="btn btn-ghost btn-xs" onclick="bomOpenVerPanel('create')">＋ 新版本</button>
                <button type="button" class="btn btn-ghost btn-xs bom-ver-more" onclick="bomOpenVerPanel()" title="版本管理面板">⋯</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($bomReadonly): ?>
    <div id="bomVersionHint" class="bom-hint-line">
        🔒 正在查看只读留档「<b><?=h($curVer['version'] ?? '')?></b>」。要改物料请点「＋ 新版本」复制一份可编辑草稿。
    </div>
    <?php endif; ?>
    </div><!-- /吸顶控制条 -->

    <!-- ══ 第三段：内容区（无感刷新 #bomRegion） ══ -->
    <div id="bomRegion" data-version-id="<?=(int)$viewVersionId?>" data-version-status="<?=h((string)($viewVersion['status'] ?? ''))?>">
    <!-- 操作栏：左侧增删改物料，右侧出库命令簇 -->
    <div class="bom-actionbar">
        <div class="bom-action-l">
            <button type="button" class="btn btn-primary btn-sm<?= $bomReadonly ? ' is-hidden' : '' ?>" onclick="openAddItem()">
                <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M8 3.4v9.2M3.4 8h9.2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                添加物料
            </button>
            <button type="button" class="btn btn-ghost btn-sm<?= $bomReadonly ? ' is-hidden' : '' ?>" onclick="openImport()">
                <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M8 2.6v7.2m0 0L5.2 7M8 9.8 10.8 7M3 12.2h10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                导入
            </button>
        </div>


        <form method="post" action="api.php" id="bomCheckoutForm" class="bom-checkout<?= $bomReadonly ? ' is-hidden' : '' ?>" onsubmit="bomConfirmCheckout(event)" data-pending-count="<?=(int)($shortage['pending_count'] ?? 0)?>">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="bom.checkout">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <input type="hidden" name="version_id" value="<?=$viewVersionId?>">
            <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?=h($filter)?>"><?php endif; ?>
            <div class="bom-checkout-fields">
                <label class="bom-field">
                    <span>出库份数</span>
                    <input type="number" name="copies" id="bomCheckoutCopies" value="1" min="1" max="999" title="每行需求量 = 清单数量 × 份数">
                </label>
                <label class="bom-field">
                    <span>扣减策略 <span class="q-tip" data-tip="严格：任一行库存不足则整单失败且不扣减；部分扣减：按实际可用量扣减。">?</span></span>
                    <select id="bomCheckoutModeSelect" name="checkout_mode">
                        <option value="all_or_nothing" selected>不足即失败</option>
                        <option value="partial">按可用量扣减</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="btn btn-success btn-sm bom-checkout-btn">
                <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M9.2 1.8 3.6 9h3.4l-.4 5.2L12.4 7H9l.2-5.2Z" fill="currentColor"/></svg>
                一键出库
            </button>
        </form>

        <div class="bom-more">
            <button type="button" class="btn btn-ghost btn-sm" id="bomMoreBtn" onclick="bomToggleMore()" title="导出与低频操作">
                <svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><circle cx="3.4" cy="8" r="1.3" fill="currentColor"/><circle cx="8" cy="8" r="1.3" fill="currentColor"/><circle cx="12.6" cy="8" r="1.3" fill="currentColor"/></svg>
                更多
            </button>
            <div class="bom-more-menu" id="bomMoreMenu">
                <div class="bom-more-group">导出</div>
                <a id="bomCsvAll" href="api.php?action=bom.shortage_csv&project_id=<?=$selId?>&version_id=<?=$viewVersionId?>&quantity=1">采购清单（全部缺料）</a>
                <a id="bomCsvStock" href="api.php?action=bom.shortage_csv&project_id=<?=$selId?>&version_id=<?=$viewVersionId?>&quantity=1&category=stock">仅库存不足</a>
                <a href="api.php?action=bom.pending_csv&project_id=<?=$selId?>&version_id=<?=$viewVersionId?>">待补料清单</a>
                <?php if (hasFeature('feature_bom_version')): // 版本开关关闭时这组低频操作同样隐藏（点了也会 403）?>
                <div class="bom-more-group">版本（低频）</div>
                <a href="javascript:void(0)" onclick="bomOpenRollback();return false">基于当前版本回滚</a>
                <a href="javascript:void(0)" onclick="bomArchiveVersion();return false">归档当前留档</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 批量操作浮框（勾选物料后浮出，样式对齐物料列表 batch-bar） -->
    <div class="batch-bar bom-batch-bar<?= $bomReadonly ? ' is-hidden' : '' ?>" id="bomBatchBar">
        <span>已选 <span class="batch-count" id="bomBatchCount">0</span> 项</span>
        <form method="post" action="api.php" id="batchDeleteForm" onsubmit="AdminUIConfirmSubmit(event, '确认删除选中的物料？', '确认删除')" class="bom-inline-form">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="bom.batch_delete_items">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <button type="submit" class="btn btn-danger btn-sm">批量删除</button>
        </form>
        <button type="button" class="btn btn-ghost btn-sm" onclick="bomClearSelection()">取消选择</button>
    </div>

    <!-- 物料明细表（容器恒在：导入/编辑后由 JS 无感刷新，空态也占位） -->
    <!-- PC端表格 -->
    <div class="table-wrap bom-desktop-table">
    <table class="bom-table">
    <thead><tr>
        <th class="bom-col-chk"><input type="checkbox" id="bomSelectAll" onchange="bomToggleAll(this)" title="全选"></th>
        <th class="bom-col-part">物料</th>
        <th class="bom-col-brand">品牌 / 封装</th>
        <th class="bom-col-cat">分类</th>
        <th class="bom-col-qty">数量</th>
        <th class="bom-col-note">位号 / 备注</th>
        <th class="bom-col-status">状态</th>
        <th class="bom-col-act">操作</th>
    </tr></thead>
    <?php ?>
    <?php $platUrlById = array_column($platforms ?? [], 'url_template', 'id'); ?>
    <tbody id="bomDesktopTbody">
    <?php

          ?>
    <?php for ($_i = 0; $_i < 5; $_i++): ?>
    <tr class="bom-skel-row"><td colspan="8"><span class="bom-skel-bar"></span></td></tr>
    <?php endfor; unset($_i); ?>
    </tbody>
    </table>
    </div>
    <?php

          ?>


    <div class="pagination" id="bomPaginationArea"></div>


    <section class="bom-panel" id="bomShortagePanel">
        <div class="bom-panel-head">
            <div class="bom-panel-title">
                <h3>缺料分析</h3>
                <div id="shortageSummary" class="bom-panel-sub">
                    <?php if($shortage['shortage_count'] > 0): ?>
                    <span class="bom-summary-danger">缺 <?=$shortage['shortage_count']?> 种物料</span> · 共 <?=$shortage['total_items']?> 种
                    <?php else: ?>
                    <span class="bom-summary-ok">库存充足</span> · 共 <?=$shortage['total_items']?> 种
                    <?php endif; ?>
                </div>
            </div>
            <div class="bom-panel-actions">
                <label class="bom-field is-inline">
                    <span>核算份数</span>
                    <input type="number" id="shortageQty" value="1" min="1" title="按多少份 BOM 核算需求与产能">
                </label>
                <button type="button" class="btn btn-ghost btn-sm" onclick="refreshShortage()">重新核算</button>
            </div>
        </div>

        <div class="bom-metrics" id="shortageGroups">
            <div class="bom-metric">
                <span class="bom-metric-k">未绑定</span>
                <span class="bom-metric-v is-warn"><?=(int)($shortage['group_incomplete_count'] ?? 0)?><i>种</i></span>
            </div>
            <div class="bom-metric">
                <span class="bom-metric-k">库存不足</span>
                <span class="bom-metric-v is-danger"><?=(int)($shortage['group_stock_count'] ?? 0)?><i>种</i></span>
            </div>
            <div class="bom-metric">
                <span class="bom-metric-k">已计入替代料</span>
                <span class="bom-metric-v is-ok"><?=(int)($shortage['alt_used_total'] ?? 0)?><i>件</i></span>
            </div>
        </div>

        <div id="shortageCapacity" class="bom-capacity<?= (int)($shortage['blocked_by_pending'] ?? 0) > 0 ? ' warn' : '' ?>">
            <?php if((int)($shortage['blocked_by_pending'] ?? 0) > 0): ?>
            存在 <b><?=(int)$shortage['blocked_by_pending']?></b> 条未绑定物料（未关联主库，可用量按 0 计），无法核算产能
            <?php else: ?>
            按当前库存最大可生产 <b><?=(int)($shortage['max_producible'] ?? 0)?></b> 份<span class="bom-capacity-sub">单机用量口径，已含替代料可用量</span>
            <?php endif; ?>
        </div>

        <div class="table-wrap bom-shortage-wrap">
        <table id="shortageTable" class="bom-shortage-table">
            <thead>
                <tr>
                    <th>类型</th>
                    <th>型号</th>
                    <th>平台编号</th>
                    <th>品牌</th>
                    <th>封装</th>
                    <th class="ta-r">需求</th>
                    <th class="ta-r">库存</th>
                    <th class="ta-r">替代料可用</th>
                    <th class="ta-r">缺口</th>
                    <th>库位</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($shortage['shortage_list'] as $s): ?>
                <tr>
                    <td><?=($s['category'] ?? '') === 'incomplete' ? '<span class="bom-st unbound" title="未绑定主库物料，可用量按 0 计；到明细表点「替代料」绑定或替换"><i></i>未绑定</span>' : '<span class="bom-st err"><i></i>库存不足</span>'?></td>
                    <td class="bom-s-model"><?=htmlspecialchars($s['model'])?></td>
                    <td class="bom-s-no"><?=htmlspecialchars($s['part_no'])?></td>
                    <td><?=htmlspecialchars($s['brand'])?></td>
                    <td class="bom-s-pkg"><?=htmlspecialchars($s['package'])?></td>
                    <td class="ta-r mono"><?=$s['need_qty']?></td>
                    <td class="ta-r mono"><?=$s['have_qty']?></td>
                    <td class="ta-r alt"><?= (int)($s['alt_qty'] ?? 0) > 0 ? (int)$s['alt_qty'] : '—' ?></td>
                    <td class="ta-r gap"><?=$s['shortage_qty']?></td>
                    <td><?=htmlspecialchars($s['location'])?></td>
                </tr>
            <?php endforeach; ?>
            <?php if(empty($shortage['shortage_list'])): ?>
                <tr><td colspan="10" class="bom-empty-row">无缺料</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    </div><!-- /#bomRegion -->

<?php else: ?>
    <!-- ════════ BOM 项目列表 ════════ -->
    <div class="page-header">
        <h2>BOM 物料清单管理</h2>
        <button type="button" class="btn btn-primary btn-sm" onclick="openProjectModal(0)">＋ 新建项目</button>
    </div>
    <p class="page-subtitle">创建 BOM 项目后可导入物料清单、预校验库存并一键出库。</p>

    <?php if (empty($projects)): ?>
    <div id="bomProjectList">
    <div class="empty-state">
        <div class="icon">📋</div>
        <div>暂无 BOM 项目，点击"新建项目"开始</div>
    </div>
    </div>
    <?php else: ?>
    <div id="bomProjectList">
    <?php
    $typeLabels0     = ['bom'=>'BOM','production'=>'生产','prototype'=>'样品'];
    $priorityLabels  = [0=>'',1=>'高优先',2=>'紧急'];
    $bomStatusLabels = ['draft'=>'草稿','testing'=>'测试','production'=>'生产','finished'=>'完成','archived'=>'归档'];
    ?>
    <div class="bom-filter-bar">
        <input type="text" id="bomProjectSearch" class="bom-filter-input" placeholder="搜索项目名称 / 描述…" oninput="filterBomProjects()">
        <select id="bomProjectStatusFilter" class="bom-filter-select" onchange="filterBomProjects()">
            <option value="">全部状态</option>
            <?php foreach ($bomStatusLabels as $stKey => $stLabel): ?><option value="<?=h($stKey)?>"><?=h($stLabel)?></option><?php endforeach; ?>
        </select>
        <select id="bomProjectTypeFilter" class="bom-filter-select" onchange="filterBomProjects()"><option value="">全部类型</option></select>
        <span class="bom-filter-count" id="bomProjectCount"></span>
    </div>
    <div class="bom-grid">
    <?php foreach ($projects as $p):
        $pManaged = (int)($p['version_managed'] ?? 1) === 1;
        $pType    = $typeLabels0[$p['project_type'] ?? 'bom'] ?? ((string)($p['project_type'] ?: 'BOM'));   // 自定义类型直接回显原文（原兜底 'BOM' 会把自定义值全显示成 BOM）
        $pStatus  = $bomProjStatus = $p['status'] ?? 'draft';
    ?>
    <div class="bom-card" data-name="<?=h($p['name'])?>" data-desc="<?=h($p['description'])?>" data-status="<?=h($pStatus)?>" data-type="<?=h($pType)?>">
        <div class="bom-card-top">
            <a href="index.php?v=bom&id=<?=h($p['id'])?>" class="bom-card-name"><?=h($p['name'])?></a>
            <div class="bom-card-tags">
                <span class="bom-tag"><?=h($pType)?></span>
                <span class="bom-tag is-<?=h($pStatus)?>"><?= $bomStatusLabels[$pStatus] ?? $pStatus ?></span>
                <?php if(($p['priority'] ?? 0) > 0): ?>
                <span class="bom-tag is-p<?=intval($p['priority'])?>"><?= $priorityLabels[$p['priority']] ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($p['description'] !== ''): ?>
        <div class="bom-card-desc"><?=h($p['description'])?></div>
        <?php endif; ?>
        <div class="bom-card-meta">
            <div class="bom-card-metric">
                <span>物料</span><b><?=h($p['item_count'])?></b>
            </div>
            <?php ?>
            <?php if ($pManaged && hasFeature('feature_bom_version')): ?>
            <div class="bom-card-metric">
                <span>当前版本</span><b class="is-accent"><?=h($p['current_version'] ?: ($p['start_version'] ?? 'V1.0'))?></b>
            </div>
            <div class="bom-card-metric">
                <span>已留档</span><b><?=h($p['released_count'] ?? 0)?></b>
            </div>
            <?php endif; ?>
            <div class="bom-card-metric">
                <span>创建</span><b><?=h(substr($p['created_at'],0,10))?></b>
            </div>
        </div>
        <div class="bom-card-foot">
            <a href="index.php?v=bom&id=<?=h($p['id'])?>" class="btn btn-ghost btn-xs bom-view-link">查看清单</a>
            <?php

            ?>
            <button type="button" class="btn btn-ghost btn-xs" onclick='openProjectModal(<?=h(json_encode(["id"=>(int)$p["id"],"name"=>$p["name"],"description"=>$p["description"],"version_managed"=>(int)($p["version_managed"]??1),"start_version"=>(string)($p["start_version"]??"V1.0"),"project_type"=>(string)($p["project_type"]??"bom"),"status"=>(string)($p["status"]??"draft"),"priority"=>(int)($p["priority"]??0)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))?>)'>编辑</button>
            <form method="post" action="api.php" onsubmit="AdminUIConfirmSubmit(event, '确认删除项目及其所有物料？该操作不可恢复！', '确认删除')" class="bom-inline-form">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="bom.delete_project">
                <input type="hidden" name="project_id" value="<?=h($p['id'])?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div class="bom-filter-empty" id="bomProjectEmpty" hidden>没有匹配的项目</div>
    </div>
    <?php endif; ?>
<?php endif; ?>

</div>
</div>

<!-- ════════ 版本管理 侧滑面板（时间线列表 + 行内操作，替代原菜单弹窗） ════════ -->
<?php if (hasFeature('feature_bom_version')): ?>
<div class="drawer-overlay" id="bomVerDrawerOverlay" onclick="bomCloseVerPanel()"></div>
<div class="drawer bom-ver-drawer" id="bomVerDrawer" role="dialog" aria-modal="true" aria-label="版本管理面板">
    <div class="drawer-header">
        <h3>版本管理</h3>
        <button type="button" class="drawer-close" onclick="bomCloseVerPanel()" title="关闭（ESC）">✕</button>
    </div>
    <div class="drawer-body">
        <div id="bomVerSummary" class="bom-ver-summary"><!-- 当前版本摘要：JS 渲染 --></div>
        <div class="bom-ver-guide">
            <div class="bom-ver-guide-item" onclick="bomVerShowForm('release')">
                <b class="bom-ver-step">1</b>
                <div class="bom-ver-guide-txt"><strong>发布留档</strong><span>把当前编辑中的清单定版为只读存档（改物料前先做这步，历史版本永不被改坏）</span></div>
                <span class="bom-ver-go" aria-hidden="true">›</span>
            </div>
            <div class="bom-ver-guide-item" onclick="bomVerShowForm('create')">
                <b class="bom-ver-step">2</b>
                <div class="bom-ver-guide-txt"><strong>新版本</strong><span>自动复制当前清单生成一份可编辑草稿，接着改物料</span></div>
                <span class="bom-ver-go" aria-hidden="true">›</span>
            </div>
            <div class="bom-ver-guide-item" onclick="bomVerShowForm('diff')">
                <b class="bom-ver-step">3</b>
                <div class="bom-ver-guide-txt"><strong>版本对比</strong><span>两个版本之间物料增减、数量差异一目了然</span></div>
                <span class="bom-ver-go" aria-hidden="true">›</span>
            </div>
            <div class="bom-ver-guide-item" onclick="bomVerShowForm('desc')">
                <b class="bom-ver-step">4</b>
                <div class="bom-ver-guide-txt"><strong>编辑描述</strong><span>给版本写一句变更说明，便于日后追溯</span></div>
                <span class="bom-ver-go" aria-hidden="true">›</span>
            </div>
        </div>
        <div id="bomVerAction" class="bom-ver-action"><!-- 内联表单（新建/发布/描述/对比）：JS 渲染，不另开弹窗 --></div>
        <div id="bomVerTimeline" class="bom-ver-timeline"><!-- 版本时间线：JS 渲染 --></div>
    </div>
</div>
<?php endif; ?>

<!-- ════════ 新建/编辑项目 弹窗 ════════ -->
<div class="overlay" id="projectModal">
<div class="modal">
    <h3 id="projectModalTitle">新建 BOM 项目</h3>
    <form method="post" action="api.php" id="projectForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" id="projectAction" value="bom.create_project">
        <input type="hidden" name="project_id" id="projectId" value="0">
        <div class="form-group">
            <label>项目名称</label>
            <input type="text" name="name" id="projectName" maxlength="200" required>
        </div>
        <div class="form-group">
            <label>描述（可选）</label>
            <textarea name="description" id="projectDesc" maxlength="500"></textarea>
        </div>
        <?php ?>
        <?php if (hasFeature('feature_bom_version')): ?>
        <div class="form-group">
            <label class="bom-ver-toggle">
                <?php

                      ?>
                <input type="hidden" name="version_managed" value="0">
                <input type="checkbox" name="version_managed" id="projectVersionManaged" value="1" checked onchange="document.getElementById('projectStartVersion').disabled=this.checked?false:true"> 启用版本管理（多版本迭代 / 发布留存）
                <span class="q-tip" data-tip="停用则仅保留单一编辑列表，不显示版本栏。建议新项目默认开启。">?</span>
            </label>
        </div>
        <div class="form-group">
            <label>初始版本号 <span class="q-tip" data-tip="如 V1.0 / 2.0；新版本号必须大于上一版本。">?</span></label>
            <input type="text" name="start_version" id="projectStartVersion" placeholder="默认 V1.0" maxlength="12">
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>项目类型 <span class="q-tip" data-tip="自由填写，如 BOM / 生产 / 样品 / 打样验证；留空默认 BOM。创建后仍可在「编辑」里修改。">?</span></label>
            <?php
                  ?>
            <input type="text" name="project_type" id="projectType" list="projectTypeList" maxlength="20" placeholder="留空默认 BOM">
            <datalist id="projectTypeList">
                <option value="bom"></option>
                <option value="production"></option>
                <option value="prototype"></option>
            </datalist>
        </div>
        <div id="projectExtraFields" class="bom-extra-fields">
            <div class="form-row">
                <div class="form-group"><label>项目状态</label>
                    <select name="status" id="projectStatus">
                        <option value="draft">草稿</option>
                        <option value="testing">测试</option>
                        <option value="production">生产</option>
                        <option value="finished">完成</option>
                        <option value="archived">归档</option>
                    </select>
                </div>
                <div class="form-group"><label>优先级</label>
                    <select name="priority" id="projectPriority">
                        <option value="0">普通</option>
                        <option value="1">⚠ 高优先</option>
                        <option value="2">🔴 紧急</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('projectModal')">取消</button>
            <button type="submit" class="btn btn-primary">保存</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 添加物料弹窗 ════════ -->
<div class="overlay" id="addItemModal">
<div class="modal bom-modal-wide">
    <h3 id="addItemModalTitle">添加物料到 BOM</h3>
    <div class="form-hint is-hidden" id="addItemModalHint"></div>
    <div class="form-group">
        <label>搜索库内物料（匹配内部编号/型号/名称/备注/外部编号）
            <span class="q-tip" id="addItemTip" data-tip="仅可选择库内已有物料添加到 BOM，无法新建元件。如需添加新元件，请到首页库存新增。">?</span>
        </label>
        <input type="text" id="addItemSearchInput" placeholder="输入关键词，支持 #内部编号 精确匹配" autocomplete="off">
    </div>
    <div id="addItemResultBox" class="bom-result-box">
        <div id="addItemResultList" class="bom-result-list"></div>
        <div id="addItemLoading" class="bom-result-loading">加载中…</div>
        <div id="addItemLoadMore" class="bom-result-more">点击加载更多</div>
    </div>
    <div class="bom-pager">
        <span id="addItemPageInfo">第 1 页 / 共 1 页 · 共 0 条</span>
        <span class="page-jump">跳转 <input type="number" id="addItemPageInput" min="1" value="1" class="bom-page-input"> 页 <button type="button" class="btn btn-ghost btn-xs" onclick="addItemJumpPage()">Go</button></span>
    </div>
    <div id="addItemSelected" class="bom-sel-box">
        <div class="bom-sel-box-title">✓ 已选择物料</div>
        <div id="addItemSelectedInfo" class="bom-sel-box-info"></div>
    </div>
    <form method="post" action="api.php" id="addItemForm" class="mt-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" id="addItemAction" value="bom.add_item_by_part_id">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="part_id" id="addItemPartId" value="0">
        <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?=h($filter)?>"><?php endif; ?>
        <div class="form-group" id="addItemQtyGroup">
            <label>数量</label>
            <input type="number" name="qty" id="addItemQty" value="1" min="1" required>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('addItemModal')">取消</button>
            <button type="submit" class="btn btn-primary" id="addItemSubmit" disabled>添加到 BOM</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 清单行编辑弹窗（清单信息 + 物料参数两块合一） ════════ -->
<div class="overlay" id="itemEditModal">
<div class="modal bom-modal-mid">
    <h3>编辑清单行 <span class="q-tip" id="itemEditInfo" data-tip="">?</span></h3>
    <form method="post" action="api.php" id="itemEditForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="bom.update_item">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="item_id" id="ei_item_id" value="0">
        <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?=h($filter)?>"><?php endif; ?>

        <div class="bom-edit-sec">
            <div class="bom-edit-sec-h">清单信息</div>
            <div class="form-row">
                <div class="form-group"><label>数量 <span class="bom-req-star">*</span></label>
                    <input type="number" name="qty" id="ei_qty" min="1" required>
                </div>
                <div class="form-group"><label>位号 <span class="q-tip" data-tip="如 R1,C12,U3；多个用逗号分隔。">?</span></label>
                    <input type="text" name="reference" id="ei_ref" maxlength="500" placeholder="R1,C12">
                </div>
            </div>
            <div class="form-group"><label>备注</label>
                <textarea name="remark" id="ei_remark" maxlength="500" rows="2"></textarea>
            </div>
        </div>

        <div class="bom-edit-sec bom-edit-sec-tog" id="ei_partSec">
            <div class="bom-edit-sec-h">
                物料参数（本 BOM）
                <span class="bom-edit-sec-tip">仅写入本 BOM 清单行，不回写物料主库；主库仅用于一键出库扣减</span>
            </div>
            <div class="form-row">
                <div class="form-group"><label>型号</label><input name="p_model" id="ei_p_model" maxlength="255"></div>
                <div class="form-group"><label>品牌</label><input name="p_brand" id="ei_p_brand" maxlength="255"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>封装</label><input name="p_package" id="ei_p_package" maxlength="255" placeholder="SOT-23"></div>
                <div class="form-group"><label>分类 <span class="q-tip" data-tip="如「线性稳压器 / 3.3V」，用于本 BOM 内的分类归类与展示。">?</span></label>
                    <input name="p_product_type" id="ei_p_product_type" maxlength="255" placeholder="集成电路" autocomplete="off">
                </div>
            </div>
            <div class="form-group"><label>商品名称</label>
                <input name="p_product_name" id="ei_p_product_name" maxlength="255">
            </div>
            <div class="form-row">
                <div class="form-group"><label>客户料号</label><input name="p_customer_part_no" id="ei_p_customer_part_no" maxlength="255"></div>
                <div class="form-group"><label>库位 / 描述</label><input name="p_location" id="ei_p_location" maxlength="255" placeholder="抽屉A1"></div>
            </div>
            <div class="form-group"><label>规格参数
                <span class="q-tip" data-tip="本 BOM 行的物料描述信息，如「3.3V 1A」。">?</span>
            </label>
                <input name="p_parameters" id="ei_p_parameters" maxlength="255" placeholder="3.3V 1A">
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('itemEditModal')">取消</button>
            <button type="submit" class="btn btn-primary" id="ei_submit">保存</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 导入 BOM 弹窗 ════════ -->
<div class="overlay" id="importModal">
<div class="modal">
    <h3>从文件导入 BOM</h3>
    <form method="post" enctype="multipart/form-data" id="importForm" action="api.php?action=bom.import_bom">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <div class="form-group">
            <label>BOM 文件</label>
            <input type="file" name="bom_file" id="importFile" accept=".xlsx,.xls,.csv" required>
            <div class="form-hint">支持 .xlsx / .xls / .csv；未安装 PhpSpreadsheet 时仅支持 CSV；匹配范围为全平台物料</div>
        </div>
        <div id="importFileName" class="bom-imp-file">
            📎 <span id="importFileLabel"></span>
        </div>
        <div id="importProgressWrap" class="bom-imp-prog">
            <div class="bom-imp-prog-head">
                <span id="importStatus">正在解析并导入…</span>
                <span id="importPercent">0%</span>
            </div>
            <div class="bom-imp-track">
                <div id="importBar" class="bom-imp-bar"></div>
            </div>
        </div>
        <div id="importResultBox" class="bom-import-box"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('importModal')">取消</button>
            <button type="button" class="btn btn-primary bom-imp-done" id="importDoneBtn" onclick="bomImportDone()">完成</button>
            <button type="submit" class="btn btn-primary" id="importSubmitBtn">开始导入</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 绑定替代料弹窗 ════════ -->
<div class="overlay" id="altModal">
<div class="modal bom-modal-wide">
    <h3>绑定替代料</h3>
    <div class="form-group">
        <div class="bom-alt-cur">
            <div id="altCurInfo" class="bom-alt-cur-info"></div>
        </div>
    </div>
    <div class="form-group">
        <label>搜索物料
            <span class="q-tip" data-tip="支持内部编号 / 型号 / 名称 / 备注 / 外部编号；输入 #编号 可精确匹配。搜索框默认留空，请自行搜索确认后选择。">?</span>
        </label>
        <input type="text" id="altSearchInput" placeholder="输入关键词查找物料，例如 LM358 或 #128" autocomplete="off">
    </div>
    <div id="altResultBox" class="bom-result-box is-tall">
        <div id="altResultList" class="bom-result-list"></div>
        <div id="altLoading" class="bom-result-loading">加载中…</div>
        <div id="altLoadMore" class="bom-result-more">点击加载更多</div>
    </div>
    <div class="bom-pager">
        <span id="altPageInfo">第 1 页 / 共 1 页 · 共 0 条</span>
        <span class="page-jump">跳转 <input type="number" id="altPageInput" min="1" value="1" class="bom-page-input"> 页 <button type="button" class="btn btn-ghost btn-xs" onclick="altJumpPage()">Go</button></span>
    </div>
    <div id="altSelHint" class="bom-alt-hint"></div>
    <form method="post" action="api.php" id="altUseForm" class="is-hidden">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="bom.use_alt">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="item_id" id="altItemId" value="0">
        <input type="hidden" name="alt_part_id" id="altPartId" value="0">
    </form>
    <form method="post" action="api.php" id="altReplaceForm" class="is-hidden">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="bom.replace_alt">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="item_id" id="altReplaceItemId" value="0">
        <input type="hidden" name="alt_part_id" id="altReplacePartId" value="0">
    </form>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('altModal')">关闭</button>
        <button type="button" class="btn btn-warning" id="altReplaceBtn" disabled title="单向拉取：该主库物料的参数复制到本 BOM 行（不动库存池）" onclick="AltPicker.replaceSelect()">替换</button>
        <button type="button" class="btn btn-primary" id="altBindBtn" disabled title="仅建立关联：出库时按绑定物料扣减其库存" onclick="AltPicker.bindSelect()">绑定</button>
    </div>
</div>



</div>

<!-- JS 配置注入 + 外部脚本 -->
<script>
window.BomPageConfig = <?= json_encode([
    'selId'           => $selId,
    'filter'          => $filter,
    'bomPage'         => $bomPage,
    'bomPerPage'      => $bomPerPage,
    // 首屏物料行数据：JS 单一模板渲染
    'items'           => $selItems,
    'bomTotalItems'   => $totalItems,
    'bomTotalPage'    => $bomTotalPage,
    'platUrlMap'      => $platUrlById ?? [],
    'csrf'            => csrf(),
    'selProjectId'    => $selId,
    'bomCostTotal'    => $bomCost['total_cost'] ?? 0,
    'bomCostNoCost'   => $bomCost['no_cost_count'] ?? 0,
    'bomVersions'     => $bomVersions ?? [],
    'viewVersionId'   => $viewVersionId ?? 0,
    'viewVersion'     => $viewVersion,
    'bomReadonly'     => $bomReadonly ?? false,
    'versionManaged'  => $versionManaged ?? 1,
    'startVersion'    => $startVersion ?? 'V1.0',
    'releasedCount'   => $releasedCount ?? 0,

], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php
// BOM 页脚本：按功能域拆为 9 个文件。
// **加载顺序不是行号顺序** —— bom.js 有多处页面加载即执行的 IIFE，它们会调用
// 其它文件的函数，故顺序由「前置依赖」决定：

$bomJsBase = 'modules/bom/Assets/js/';
$bomJsOrder = [
    'bom-core',      // 配置与缺料分析（须最先）
    'bom-list',      // 物料列表渲染 / 筛选 / 分页
    'bom-modal',     // 通用弹窗 / 项目 / 导入
    'bom-checkout',  // 利润率 / 一键出库（须早于 form）
    'bom-form',      // 各表单 AJAX 拦截
    'bom-alt',       // 绑定替代料弹窗
    'bom-item',      // 清单行编辑 / 手动添加物料
    'bom-import',    // BOM 导入
    'bom-version',   // 版本 Tab / 版本管理（须最后）
];
foreach ($bomJsOrder as $bj) {
    $bjPath = __DIR__ . '/../Assets/js/' . $bj . '.js';
    echo '<script src="' . h($bomJsBase . $bj . '.js') . '?v='
        . h(APP_VERSION . '.' . filemtime($bjPath)) . '"></script>' . "\n";
}
?>
</body></html>
