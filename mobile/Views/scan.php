<?php
declare(strict_types=1);
/** mobile/Views/scan.php — 移动端「扫码出入库」 */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

use Modules\Scan\Service\ScanService;

$canScan = hasFeature('scan') && hasPermission('can_scan');

$today  = ['in' => 0, 'out' => 0, 'inQty' => 0, 'outQty' => 0];
$recent = [];
if ($canScan) {
    $svc    = new ScanService(getDB());
    $uid    = (int)$user['id'];
    $today  = $svc->getTodayStats($uid);
    $recent = $svc->getRecentScans($uid, 20);
}

$canViewCost = can_view_cost();
$enforceUnit = isEnforceUnitPrice();

$__h5qr = __DIR__ . '/../../third_party/js/html5-qrcode.min.js';

if (!$canScan):
    ?>
    <div class="m-card m-placeholder">
        <div class="m-ph-icon"><span class="m-ico m-ico--lock" aria-hidden="true"></span></div>
        <div class="m-ph-title">扫码出入库未启用</div>
        <div class="m-ph-text">当前账号缺少扫码权限（或该功能未开启）。<br>请在电脑端「用户管理」中为主管/仓管角色勾选「扫码出入库」权限。</div>
    </div>
    <?php
    return;
endif;

/** 扫码记录时间：只取到分 */
function mScanTime(string $ts): string {
    return $ts !== '' ? substr($ts, 0, 16) : '';
}
?>


<div id="mScanRoot" hidden
     data-qr-aliases="<?= h(json_encode(\App\Helper\Parser\FieldRegistry::qrAliases(), JSON_UNESCAPED_UNICODE)) ?>"
     data-can-view-cost="<?= $canViewCost ? '1' : '0' ?>"
     data-enforce-unit-price="<?= $enforceUnit ? '1' : '0' ?>"
     data-h5qr-url="<?= h('../third_party/js/html5-qrcode.min.js?v=' . APP_VERSION . '.' . (is_file($__h5qr) ? filemtime($__h5qr) : '0')) ?>"></div>


<div class="m-card m-scan-today" id="mTodayCollapse">
    <div class="m-scan-today-head">
        <span class="m-card-title">今日扫码统计</span>
        <span class="m-collapse-badge is-neutral" id="mTodayBadge"><?= (int)$today['in'] + (int)$today['out'] ?> 笔</span>
    </div>
    <div class="m-scan-today-grid">
        <div class="m-today-item"><b id="stIn"><?= (int)$today['in'] ?></b><span>入库笔数</span></div>
        <div class="m-today-item"><b id="stInQty"><?= (int)$today['inQty'] ?></b><span>入库件数</span></div>
        <div class="m-today-item"><b id="stOut"><?= (int)$today['out'] ?></b><span>出库笔数</span></div>
        <div class="m-today-item"><b id="stOutQty"><?= (int)$today['outQty'] ?></b><span>出库件数</span></div>
    </div>
</div>

<!-- ══ 步骤 1：方向 + 扫码输入（数量在扫到后才出现，见确认面板）══ -->
<div class="m-card">
    <div class="m-seg" id="scanDirBar" role="group" aria-label="操作方向">
        <button type="button" class="m-seg-btn is-on" data-dir="in">入库</button>
        <button type="button" class="m-seg-btn" data-dir="out">出库</button>
    </div>

    <div class="m-field">
        <input type="text" id="scanInput" class="m-input" autocomplete="off"
               enterkeyhint="go" placeholder="扫码枪 / 手动输入编号或型号后回车" aria-label="扫描或输入条码">
    </div>

    <div class="m-scan-ops">
        <button type="button" class="m-btn m-btn-primary m-scan-cam-btn" id="camToggle"><span class="m-ico m-ico--camera" aria-hidden="true"></span> 开启摄像头扫码</button>
        <label class="m-switch"><input type="checkbox" id="continuousSwitch"> 连续扫码</label>
    </div>

    <div class="m-hintbar is-hidden" id="continuousHint">
        <span class="m-hintbar-text">连续扫码：扫到即记账，不再逐条确认；入库按「不计成本」记账（真实单价可在电脑端补录）。</span>
        <button type="button" class="m-hintbar-close" data-hint-close="continuousHint" aria-label="关闭提示"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>
</div>

<!-- ══ 摄像头不可用提示条（顶部统一样式；HTTP / 库不可用时显示，不出黑框）══ -->
<div class="m-hintbar is-hidden" id="camHttpsHint">
    <span class="m-hintbar-text">当前访问方式不支持摄像头（需 HTTPS 或 localhost）。请改用扫码枪或手动输入。</span>
    <button type="button" class="m-hintbar-close" data-hint-close="camHttpsHint" aria-label="关闭提示"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
</div>


<div class="m-drawer-overlay" id="camOverlay"></div>
<div class="m-drawer m-cam-drawer" id="camDrawer" role="dialog" aria-modal="true" aria-label="摄像头扫码">
    <div class="m-drawer-handle"></div>
    <div class="m-drawer-head">
        <span class="m-drawer-title">摄像头扫码</span>
        <button type="button" class="m-drawer-close" id="camClose" aria-label="关闭摄像头"><span class="m-ico m-ico--close" aria-hidden="true"></span></button>
    </div>
    <div class="m-drawer-body">

        <div id="camView" class="m-cam-view">
            <div class="m-cam-placeholder" id="camPlaceholder"><span class="m-ico m-ico--camera" aria-hidden="true"></span> 正在启动摄像头…</div>
            <div class="m-cam-frame" aria-hidden="true">
                <span class="m-cam-corner tl"></span><span class="m-cam-corner tr"></span>
                <span class="m-cam-corner bl"></span><span class="m-cam-corner br"></span>
            </div>
            <div class="m-cam-hint">将二维码对准框内（框内均可识别）</div>
        </div>
        <div class="m-scan-cam-bar">
            <span class="m-hint"><span class="m-dot is-off" id="camDot"></span> <span id="camStatus"></span></span>
        </div>
    </div>
</div>

<!-- ══ 步骤 2：确认面板（扫到后内联展开；此处才定数量）══ -->
<div class="m-card is-hidden" id="confirmPanel">
    <div class="m-card-title" id="cfTitle">确认入库</div>
    <div class="m-kv">
        <div class="m-kv-row"><span class="m-kv-k">型号</span><span class="m-kv-v is-mono" id="cfModel">—</span></div>
        <div class="m-kv-row"><span class="m-kv-k">编号</span><span class="m-kv-v is-mono" id="cfPartNo">—</span></div>
        <div class="m-kv-row"><span class="m-kv-k">当前库存</span><span class="m-kv-v" id="cfStock">—</span></div>
    </div>
    <div class="m-scan-qty">
        <span class="m-label">数量</span>
        <div class="m-stepper">
            <button type="button" class="m-step-btn" id="qtyMinus" aria-label="减少数量">−</button>
            <input type="number" id="scanQty" class="m-step-input" value="1" min="1" inputmode="numeric" aria-label="数量">
            <button type="button" class="m-step-btn" id="qtyPlus" aria-label="增加数量">+</button>
        </div>
    </div>
    <?php if ($canViewCost): ?>
    <div class="m-field" id="cfPriceWrap">
        <span class="m-label">单价（团队版入库必填）</span>
        <input type="number" id="cfPrice" class="m-input" step="0.0001" min="0" inputmode="decimal" placeholder="含税单价">
        <span class="m-hint" id="cfPriceHint"></span>
    </div>
    <label class="m-switch"><input type="checkbox" id="cfSkipCost"> 不计成本（该笔不计入加权平均成本）</label>
    <?php endif; ?>
    <div class="m-scan-confirm-acts">
        <button type="button" class="m-btn" id="cfCancel">取消</button>
        <button type="button" class="m-btn m-btn-primary" id="cfSubmit">确认入库</button>
    </div>
</div>

<!-- ══ 候选列表（型号命中多条，内联让用户挑，不擅自取第一条）══ -->
<div class="m-card is-hidden" id="candidatePanel">
    <div class="m-card-title">匹配到多个物料，请选择</div>
    <div class="m-cands" id="candList"></div>
    <button type="button" class="m-btn m-btn-sm" id="candCancel">取消</button>
</div>

<!-- ══ 未找到物料（新料：引导到电脑端建档）══ -->
<div class="m-card m-note-card is-hidden" id="notFoundPanel">
    <div class="m-note-title"><span class="m-ico m-ico--alert" aria-hidden="true"></span> 未找到该物料</div>
    <div class="m-note-text">扫码内容：<b id="nfKeyword"></b><br>库中暂无此物料，请到电脑端「物料管理」建档后再扫码；若编号/型号有误也可手动核对。</div>
    <button type="button" class="m-btn m-btn-sm m-mt10" id="nfClose">知道了</button>
</div>

<!-- ══ 连续扫码会话条（连续模式下显示）══ -->
<div class="m-card is-hidden" id="sessionBar">
    <div class="m-session-info">连续扫码中 · 本次 <b id="ssbCount">0</b> 条 · 入 <b id="ssbIn">0</b> 件 · 出 <b id="ssbOut">0</b> 件</div>
    <div class="m-scan-confirm-acts">
        <button type="button" class="m-btn m-btn-sm m-btn--inline" id="ssbUndo" disabled><span class="m-ico m-ico--undo" aria-hidden="true"></span> 撤销最近一条</button>
        <button type="button" class="m-btn m-btn-sm m-btn--inline" id="ssbEnd">结束本次</button>
    </div>
</div>

<!-- ══ 结果提示（内联，自动消退）══ -->
<div class="m-card is-hidden" id="resultPanel">
    <div class="m-result-head" id="resHead"></div>
    <div class="m-result-body" id="resBody"></div>
</div>

<!-- ══ 最近扫描记录（今日，可逐条撤销；可折叠：默认收起）══ -->
<section class="m-collapse" id="mRecentCollapse">
    <button type="button" class="m-collapse-head" id="mRecentCollapseHead"
            aria-expanded="false" aria-controls="mRecentCollapseBody">
        <span class="m-collapse-title">最近扫描（今日）</span>
        <span class="m-collapse-badge is-neutral" id="mRecentBadge"><?= count($recent) ?> 条</span>
        <span class="m-collapse-arrow" aria-hidden="true">›</span>
    </button>
    <div class="m-collapse-body is-hidden" id="mRecentCollapseBody">
        <div class="m-card">
            <div class="m-scan-list" id="recentList">
            <?php foreach ($recent as $r):
                $isIn     = ($r['scan_type'] ?? '') === 'in';
                $revoked  = strpos((string)($r['remark'] ?? ''), '[已撤销]') !== false;
            ?>
                <div class="m-scan-row<?= $revoked ? ' is-revoked' : '' ?>" data-id="<?= (int)$r['id'] ?>">
                    <div class="m-scan-row-main">
                        <div class="m-scan-row-name"><?= h(($r['model'] ?? '') !== '' ? $r['model'] : ($r['platform_part_no'] ?? '')) ?></div>
                        <div class="m-scan-row-meta"><?= h(mScanTime((string)($r['created_at'] ?? ''))) ?> · <?= h((string)($r['platform_part_no'] ?? '')) ?></div>
                    </div>
                    <span class="m-scan-badge <?= $isIn ? 'is-in' : 'is-out' ?>"><?= $isIn ? '入' : '出' ?></span>
                    <span class="m-scan-row-qty <?= $isIn ? 'is-in' : 'is-out' ?>"><?= $isIn ? '+' : '-' ?><?= (int)$r['qty'] ?></span>
                    <button type="button" class="m-scan-undo" data-id="<?= (int)$r['id'] ?>" <?= $revoked ? 'disabled' : '' ?> title="撤销此记录" aria-label="撤销此记录"><span class="m-ico m-ico--undo" aria-hidden="true"></span></button>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="m-empty <?= empty($recent) ? '' : 'is-hidden' ?>" id="recentEmpty">
                <div class="m-empty-ico"><span class="m-ico m-ico--inbox" aria-hidden="true"></span></div>
                <div class="m-empty-text">今日暂无扫描记录</div>
            </div>
        </div>
    </div>
</section>
