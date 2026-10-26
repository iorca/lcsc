<?php
declare(strict_types=1);
/** modules/import/Views/index.php — 导入中心（模板化向导） */
use Modules\Import\Controller\ImportController;
require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
if (!hasPermission('can_import')) { header('Location: ' . siteUrl('index.php')); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid     = getDataUserId();
$workspaceId = getWorkspaceId();
ensureImportTemplates((int)$uid);

// ── 视图数据（Controller）──
$platforms     = [];
$defaultPlat   = 'lcsc';
$recentErrors  = [];
$importedFiles = [];
$tplStatus     = [];
$tplFields     = [];
try {
    extract(ImportController::prepareViewData($db, $uid, $dataUid));
} catch (\Throwable $e) {
    error_log('ImportController::prepareViewData failed: ' . $e->getMessage());
}

// 平台卡片数据（含模板状态：未配置模板走智能识别）
$platCards = [];
foreach ($platforms as $pl) {
    $pid  = (int)$pl['id'];
    $stat = $tplStatus[$pid] ?? null;
    $platCards[] = [
        'id'         => $pid,
        'code'       => (string)$pl['code'],
        'name'       => (string)$pl['name'],
        'type'       => (string)($pl['platform_type'] ?? 'standard'),
        'is_default' => (int)($pl['is_default'] ?? 0),
        'has_tpl'    => !empty($stat['has']),
        'builtin'    => !empty($stat['is_builtin']),
    ];
}
$canImport = false;
foreach ($platCards as $c) { if ($c['has_tpl']) { $canImport = true; break; } }

// ── 片段渲染（无感刷新）：只回传「最近导入」列表，不输出整页框架──
// 导入完成后批次状态与计数会变（处理中 → 已完成），但此时「导入成功详情弹窗」

if (($_GET['fragment'] ?? '') === 'batch_list') {
    require __DIR__ . '/partials/batch_list.php';
    exit;
}

$pageTitle = '导入中心';
$activePage = 'import';

\App\Core\AssetManager::addCss('modules/import/Assets/css/import.css');

$useLayoutFoot = true;
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<!-- 导入中心专属 CSS -->

<div class="main">
<div class="glass-box">
    <div class="page-header imp-page-head">
        <h2 class="imp-page-title">导入订单 Excel / CSV</h2>
        <div class="page-desc imp-page-desc">
            模板化导入：按平台模板精准匹配列 → 映射确认 → 分批入库（带真实进度）→ 处理详情可一键撤销
        </div>
    </div>

    <!-- 步骤条（已完成可点击回退，当前高亮，未来禁用） -->
    <div class="import-steps" id="impSteps">
        <div class="import-step active" data-step="1" title="选择平台 / 上传文件"><span class="step-no">1</span><span class="step-label">选择平台 / 上传文件</span></div>
        <div class="import-step" data-step="2" title="映射确认"><span class="step-no">2</span><span class="step-label">映射确认</span></div>
        <div class="import-step" data-step="3" title="处理进度"><span class="step-no">3</span><span class="step-label">处理进度</span></div>
        <div class="import-step" data-step="4" title="处理详情（导入完成后自动弹出）"><span class="step-no">4</span><span class="step-label">处理详情</span></div>
    </div>

    <?php if (!$canImport): ?>
    <div class="flash warn">
        当前没有平台配置导入模板：本次导入将使用<strong>智能识别</strong>（表头关键词 + 格式推断），
        请在「映射确认」步骤逐列核对；建议到
        <a href="index.php?v=admin&tab=platforms" class="u-underline">系统设置 → 平台管理</a>
        为该平台上传一次样本文件配置模板，后续导入即可自动识别。
    </div>
    <?php endif; ?>

    <!-- ═══ 步骤1：导入类型 + 选择平台 + 上传 ═══ -->
    <div id="impStep1" class="imp-step is-on">
        <div class="sec-title imp-sec-4">导入类型 <span class="imp-sec-note">（决定本次导入是否影响库存与成本）</span></div>
        <div class="plat-cards imp-mode-cards" id="modeCards">
            <label class="plat-card selected" data-mode="stock" title="带数量与单价：叠加或新建物料，写库存流水与采购价">
                <input type="radio" name="import_mode" value="stock" checked>
                <span class="plat-card-name">采购入库</span>
                <span class="plat-card-meta"><span class="badge badge-blue">叠加/新建 · 写库存流水</span></span>
            </label>
            <label class="plat-card" data-mode="profile" title="只落物料主档（型号/品牌/封装/分类/库位/备注/参数），不写库存与成本">
                <input type="radio" name="import_mode" value="profile">
                <span class="plat-card-name">物料建档</span>
                <span class="plat-card-meta"><span class="badge badge-gray">只建主档 · 不动库存</span></span>
            </label>
        </div>

        <div class="sec-title imp-sec-16">① 选择平台 <span class="imp-sec-note">（未配置模板的平台走智能识别，可在下一步逐列确认）</span></div>
        <div class="plat-cards" id="platCards">
            <?php foreach ($platCards as $c): $sel = $c['has_tpl'] && $c['is_default']; ?>
            <label class="plat-card<?=$sel ? ' selected' : ''?>"
                   data-id="<?=$c['id']?>" data-code="<?=h($c['code'])?>" data-has-tpl="<?=$c['has_tpl'] ? '1' : '0'?>"
                   title="<?=$c['has_tpl'] ? '使用该平台模板导入' : '该平台未配置模板，将使用智能识别导入'?>">
                <input type="radio" name="platform" value="<?=h($c['code'])?>" <?=$sel ? 'checked' : ''?>>
                <span class="plat-card-name"><?=h($c['name'])?></span>
                <span class="plat-card-meta">
                    <?php if ($c['has_tpl']): ?>
                        <span class="badge badge-green">模板已配置<?=$c['builtin'] ? '·内置' : ''?></span>
                    <?php else: ?>
                        <span class="badge badge-yellow">未配置模板 · 智能识别</span>
                    <?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
            <a class="plat-card plat-card-add" href="index.php?v=admin&tab=platforms" title="新增平台 / 配置模板">
                <span class="plat-card-name">＋ 配置模板</span>
                <span class="plat-card-meta imp-card-meta-sm">平台管理</span>
            </a>
        </div>

        <div class="sec-title imp-sec-18">② 上传该平台导出的文件</div>
        <div class="drop-zone" id="dropZone">
            <div class="drop-zone-icon">📄</div>
            <div class="drop-zone-text">点击选择或拖拽文件到此处</div>
            <div class="drop-zone-hint">支持 .xlsx / .xls / .csv，建议使用平台导出的原始文件</div>
            <input type="file" id="fileInput" accept=".xlsx,.xls,.csv,.tsv" class="imp-file-input">
        </div>
        <div id="fileInfo" class="imp-file-info">
            📎 <strong id="fileName"></strong>
            <button type="button" class="btn btn-ghost btn-xs imp-file-clear" onclick="ImportWizard.clearFile()">移除</button>
        </div>

        <div class="imp-act-row">
            <label class="imp-check-label">
                <input type="checkbox" id="isSample" class="imp-cb-accent">
                样品不计资产（本次导入的所有物料不计入总资产与成本统计）
            </label>
            <span class="imp-flex-spacer"></span>
            <button type="button" class="btn btn-primary btn-sm" id="btnParse" onclick="ImportWizard.parse()">
                ▼ 下一步：识别并确认映射
            </button>
        </div>
    </div>

    <!-- ══ 步骤2：映射确认 ═══ -->
    <div id="impStep2" class="imp-step">
        <div class="sec-title imp-sec-4">③ 确认列映射
            <span class="imp-sec-note">（自动识别结果，可逐列纠正；未识别列请指派或忽略）</span>
        </div>
        <div id="mapSummary" class="imp-map-summary"></div>
        <div class="imp-bar imp-bar-req" id="mapReq"></div>
        <div class="imp-bar imp-bar-warn" id="mapWarn"></div>

        <div class="imp-map-toolbar">
            <label class="imp-plain-label">默认分类
                <span class="imp-hint">（可空；仅当文件里没有分类列时套用，不猜）</span>
            </label>
            <input type="text" id="defaultCategory" placeholder="如：电阻 / 电容" class="imp-cat-input">
            <button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.openCustomFields()">自定义字段…</button>
            <span class="imp-flex-spacer"></span>
            <button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.ignoreAllUnknown()">忽略全部未识别</button>
            <button type="button" class="btn btn-ghost btn-xs" onclick="ImportWizard.resetMapping()">重置为模板建议</button>
        </div>

        <div class="imp-scroll-tall">
            <table class="data-table imp-map-table imp-tb-sm imp-map-full">
                <thead><tr>
                    <th class="imp-th-pct24">文件列名</th>
                    <th class="imp-th-pct30">示例值</th>
                    <th class="imp-th-pct28">对应系统字段</th>
                    <th class="imp-th-pct18">识别依据</th>
                </tr></thead>
                <tbody id="mapBody"></tbody>
            </table>
        </div>
        <div id="mapPreview" class="imp-preview"></div>

        <div class="imp-act-row">
            <label class="imp-check-label">
                <input type="checkbox" id="saveAsTemplate" class="imp-cb-accent">
                把本次列映射保存为该平台模板（下次导入自动识别，匹配键沿用平台模板设置）
            </label>
            <span class="imp-flex-spacer"></span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="ImportWizard.backToStep1()">← 返回重选</button>
            <button type="button" class="btn btn-primary btn-sm" id="btnStage" onclick="ImportWizard.stage()">✓ 确认导入</button>
        </div>
    </div>

    <!-- ══ 步骤3：处理进度 ═══ -->
    <div id="impStep3" class="imp-step">
        <div class="sec-title imp-sec-4">④ 处理中…</div>
        <div class="imp-progress-wrap" id="progWrap">
            <div class="imp-progress-bar"><div class="imp-progress-inner imp-progress-init" id="progInner"></div></div>
            <div class="imp-progress-text">
                <span id="progText">准备中…</span>
                <span id="progPercent" class="mono imp-percent">0%</span>
            </div>
            <div class="imp-counts">
                <span class="imp-count">已处理 <b id="cProcessed">0</b> / <b id="cTotal">0</b></span>
                <span class="imp-count c-ins">新增 <b id="cInserted">0</b></span>
                <span class="imp-count c-upd"><span id="cUpdLabel">叠加</span> <b id="cUpdated">0</b></span>
                <span class="imp-count">跳过 <b id="cSkipped">0</b></span>
                <span class="imp-count c-err">失败 <b id="cErrors">0</b></span>
            </div>
        </div>
        <div class="imp-act-row is-tight imp-progress-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="btnPause" onclick="ImportWizard.togglePause()">⏸ 暂停</button>
            <button type="button" class="btn btn-ghost btn-sm imp-btn-detail" id="btnToDetail" onclick="ImportWizard.detail(ImportWizard.state.batchId)">查看处理详情</button>
            <span class="imp-hint-inline">处理中请勿关闭页面；暂停后数据不会丢失，可继续。</span>
        </div>
    </div>

    <!-- ═══ 最近导入批次 ══ -->
    <div class="sec-title imp-sec-24">最近导入</div>
    <div id="impBatchList">
<?php require __DIR__ . '/partials/batch_list.php'; ?>
    </div>

    <!-- 支持的格式说明 -->
    <div class="sec-title imp-sec-24">支持的格式说明</div>
    <div class="imp-format-note">
        › 立创商城「订单明细」：我的订单 → 全部订单 → 导出订单明细<br>
        › 立创商城「物料明细对账单」：订单管理 → 物料明细对账单 → 导出<br>
        › 其他平台：可直接导入（自动智能识别），也建议在「系统设置 → 平台管理」上传该平台导出的样本完成模板配置<br>
        › 两种格式均自动识别，「数量」与「型号发货数量」都支持；导入前可预览并逐列确认映射
    </div>
</div>
</div>

<!-- ══ 处理详情弹窗 ═══ -->
<div class="overlay" id="impDetailModal">
<div class="modal modal-lg">
    <h3>📋 处理详情</h3>
    <div id="detailHead" class="imp-detail-head"></div>
    <div class="imp-detail-stats" id="detailStats"></div>
    <div id="detailRows" class="imp-detail-rows"></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="ImportWizard.closeDetail()">关闭</button>
        <button type="button" class="btn btn-ghost btn-sm imp-detail-btn" id="detailExportBtn" onclick="ImportWizard.exportErrors()">导出失败清单</button>
        <button type="button" class="btn btn-danger btn-sm imp-detail-btn" id="detailRollbackBtn" onclick="ImportWizard.rollback()">撤销本次导入</button>
    </div>
</div>
</div>

<!-- ══ 自定义字段管理弹窗 ══ -->
<div class="overlay" id="impCfModal">
<div class="modal">
    <h3>⚙️ 订单自定义字段</h3>
    <div class="imp-cf-add">
        <input type="text" id="cfKey" placeholder="字段名（如 po_no，仅英文/数字/下划线）">
        <input type="text" id="cfLabel" placeholder="显示名（如 内部 PO 号）">
        <button type="button" class="btn btn-primary btn-sm" id="cfAddBtn" onclick="ImportWizard.cfAdd()">新增</button>
    </div>
    <div class="imp-cf-tip" id="cfTip"></div>
    <div class="imp-cf-list" id="cfList"></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" onclick="ImportWizard.closeCustomFields()">关闭</button>
    </div>
</div>
</div>

<script>
window.ImportPageConfig = {
    platforms: <?=json_encode($platCards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>,
    fields: <?=json_encode($tplFields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>,
    defaultPlat: <?=json_encode($defaultPlat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>,
    canImport: <?=$canImport ? 'true' : 'false'?>,
    canEdit: <?=hasPermission('can_edit') ? 'true' : 'false'?>,
    canViewCost: <?=can_view_cost() ? 'true' : 'false'?>,
    enforcePrice: <?=isEnforceUnitPrice() ? 'true' : 'false'?>,
    batchLabels: <?=json_encode(\App\Core\Enum\ImportStatus::batchLabels(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>
};
</script>
<script src="modules/import/Assets/js/import.js?v=<?=filemtime(__DIR__ . '/../Assets/js/import.js')?>"></script>
<?php require __DIR__ . '/../../../ui/layouts/foot.php'; ?>
