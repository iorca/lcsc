<?php
declare(strict_types=1);
/** 扫码出入库页面 */
require_once __DIR__ . '/../../../config.php';
$user = requireLogin();
if (!hasPermission('can_scan')) { header('Location: ' . siteUrl('index.php')); exit; }
$db   = getDB();
$uid  = $user['id'];

use Modules\Scan\Controller\ScanController;

// 数据准备
$viewData = ScanController::prepareViewData($db, $uid);
extract($viewData);

$pageTitle          = '扫码出入库';
$activePage         = 'scan';
$extraTopbarRight   = '<a href="index.php" class="btn btn-ghost btn-sm">← 返回库存</a>';

\App\Core\AssetManager::addCss('modules/scan/Assets/css/scan.css');
require __DIR__ . '/../../../ui/layouts/head.php';
?>
<?php $__h5qr = __DIR__ . '/../../../third_party/js/html5-qrcode.min.js'; ?>

<script src="scan_decoder.js?v=<?=APP_VERSION?>"></script>
<div class="main">
<div class="glass-box">

<?php if ($flash === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif ($flash === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php endif; ?>

<?php if ($scanError): ?>
<div class="flash err"><?= h($scanError) ?></div>
<?php endif; ?>

<!-- ── 页头 ── -->
<div class="page-header">
    <h2>扫码出入库</h2>
    <div class="page-desc">摄像头、扫码枪、手动输入三种方式走同一套解析与落库逻辑；扫错的记录可在下方逐条撤销。</div>
</div>


<div class="sd-stats sd-mb16">
    <div class="stat-card c-green"><div class="stat-label">今日入库笔数</div><div class="stat-value" id="stTodayIn"><?= (int)($todayIn ?? 0) ?></div></div>
    <div class="stat-card c-green"><div class="stat-label">今日入库数量</div><div class="stat-value" id="stTodayInQty"><?= (int)($todayInQty ?? 0) ?></div></div>
    <div class="stat-card c-red"><div class="stat-label">今日出库笔数</div><div class="stat-value" id="stTodayOut"><?= (int)($todayOut ?? 0) ?></div></div>
    <div class="stat-card c-red"><div class="stat-label">今日出库数量</div><div class="stat-value" id="stTodayOutQty"><?= (int)($todayOutQty ?? 0) ?></div></div>
</div>

<?php if ($scanResult): ?>
<!-- ── 扫描结果卡片 ── -->
<div class="card card-pad sd-mb16 sd-result-card<?= $scanResult['type'] === 'scan_in' ? '' : ' is-out' ?>">
    <div class="sd-result-head">
        <h3 class="sd-result-title">
            <?= $scanResult['type'] === 'scan_in' ? '📥 扫码入库成功' : '📤 扫码出库成功' ?>
        </h3>
        <span class="badge <?= $scanResult['type'] === 'scan_in' ? 'badge-green' : 'badge-red' ?>">
            <?= $scanResult['type'] === 'scan_in' ? '入库' : '出库' ?>
        </span>
    </div>
    <table class="info-table sd-result-table">
        <tr>
            <td>商品编号</td>
            <td class="sd-res-ppn-cell"><?= h($scanResult['part_no']) ?></td>
        </tr>
        <tr>
            <td>型号</td>
            <td class="sd-res-mono"><?= h($scanResult['model'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>数量变化</td>
            <td class="sd-res-qty-cell<?= $scanResult['type'] === 'scan_in' ? ' is-in' : ' is-out' ?>">
                <?= $scanResult['type'] === 'scan_in' ? '+' : '-' ?><?= $scanResult['qty'] ?>（当前库存 <?= $scanResult['qty_after'] ?>）
            </td>
        </tr>
    </table>
</div>
<?php endif; ?>

<!-- ── HTTPS 摄像头提示：仅摄像头不可用时显示，可关闭并记忆（localStorage）── -->
<div class="https-notice" id="httpsNotice">
    <span class="https-notice-text">
        <strong>摄像头扫码需要 HTTPS</strong> —— 当前访问方式 <code id="currentProto"></code>，<span id="httpsStatus"></span>。
        浏览器安全策略要求 <code>getUserMedia</code> 仅允许在 HTTPS 或 <code>localhost</code> 下使用，建议部署 SSL 证书。
    </span>
    <button type="button" class="https-notice-close" onclick="dismissHttpsNotice()" title="关闭提示" aria-label="关闭提示">✕</button>
</div>

<!-- ── 设备选择弹窗 ── -->
<div class="device-modal-overlay is-off" id="deviceModal">
    <div class="device-modal">
        <div class="device-modal-header">
            <span id="deviceModalTitle">选择设备</span>
            <button class="device-modal-close" onclick="closeDeviceModal()">&times;</button>
        </div>
        <div class="device-modal-body" id="deviceModalBody">
            <!-- 动态填充 -->
        </div>
        <div class="device-modal-footer is-off" id="deviceModalFooter">
            <label class="device-remember">
                <input type="checkbox" id="rememberDevice" checked> 记住选择（下次自动使用）
            </label>
            <button class="btn btn-primary btn-sm" id="deviceModalConfirm" onclick="confirmDeviceSelection()">✓ 确认</button>
        </div>
    </div>
</div>

<!-- ── 扫码卡片 ── -->
<div class="card card-pad sd-mb16">

    <div class="scan-input-bar">
        <input type="text" id="barcodeInput" class="scan-input" autocomplete="off"
               placeholder="扫码 / 输入型号、编号后回车" aria-label="扫描或输入条码">
    </div>

    <!-- 扫码核心操作行：左为设备状态，右为醒目的摄像头入口（两件主操作相邻） -->
    <div class="sd-device-row">
        <div class="device-status-bar">
            <div class="device-status" id="scannerStatus" onclick="connectScanner()" title="点击连接扫码枪">
                <span class="dot off" id="scannerDot"></span>
                <span id="scannerText">扫码枪未连接</span>
                <button class="ds-btn" id="scannerBtn">连接</button>
            </div>
            <div class="device-status" id="cameraStatus2" onclick="selectCamera()" title="点击选择摄像头">
                <span class="dot off" id="cameraDot"></span>
                <span id="cameraDevText">摄像头未选择</span>
                <button class="ds-btn" id="cameraBtn">选择</button>
            </div>
        </div>
        <button type="button" class="scan-cam-toggle" id="scanCamToggle" onclick="toggleCamera()">
            <span class="cam-icon">📷</span>
            <span class="cam-text" id="scanCamToggleText">开启摄像头扫码</span>
        </button>
    </div>


    <div class="scan-options">
        <div class="scan-opt-group">
            <span class="scan-opt-group-title">入库设置</span>
            <label title="扫到编号或型号后先弹确认卡片再入库；取消勾选则扫到即入库">
                <input type="checkbox" id="confirmScanIn" checked> 入库前确认
            </label>
            <label title="打开后连续作业：扫到即记账，不再逐条弹结果窗（改由下方会话条累计）；未建档的码仍会弹建档表单">
                <input type="checkbox" id="continuousMode"> 连续扫码
            </label>
            <?php if (can_view_cost()): ?>
            <label title="仅连续扫码时可用：入库不再逐条填单价，以「不计成本」记账（该笔不进加权平均成本）" id="skipCostLabel">
                <input type="checkbox" id="skipCostMode"> 入库不计成本
            </label>
            <?php endif; ?>
        </div>
        <div class="scan-opt-group">
            <span class="scan-opt-group-title">播报设置</span>
            <label title="扫码成功后播放提示音">
                <input type="checkbox" id="soundEnabled" checked> 声音提示
            </label>
            <label title="扫码成功后语音播报结果" class="voice-only-desktop">
                <input type="checkbox" id="voiceEnabled"> 语音播报
            </label>
            <button type="button" id="voiceActivateBtn" class="btn btn-ghost btn-xs voice-only-desktop sd-voice-btn" onclick="activateVoice()">开启语音权限</button>
        </div>
        <div class="scan-shortcuts">
            <span class="shortcut-label">快捷键：</span>
            <span class="shortcut-hint">F3</span> 摄像头
        </div>
    </div>

    <!-- 扫码表单：操作参数由解析结果与确认卡片填充，此处仅承载提交字段 -->
    <form method="post" action="api.php" id="scanForm">
        <input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" id="scanAction" value="stock.scan_out">
        <input type="hidden" name="ajax" id="scanAjax" value="1">
        <input type="hidden" name="order_no" id="scanOrderNo" value="">
        <input type="hidden" name="scan_source" id="scanSource" value="">
        <input type="hidden" name="internal_id" id="scanInternalId" value="0">
        <input type="hidden" name="platform_code" id="scanPlatformCode" value="">
        <input type="hidden" name="qty" id="scanQty" value="1">
        <input type="hidden" name="model" id="scanModel" value="">
        <input type="hidden" name="unit_cost" id="scanUnitCost" value="0">
        <input type="hidden" name="skip_cost" id="scanSkipCost" value="0">
        <input type="hidden" name="barcode" id="scanBarcode" value="">

        <input type="hidden" name="fields" id="scanFields" value="">
    </form>

    <!-- 扫码状态显示区（自动填充，只读展示解析结果） -->
    <div class="sd-status-box" id="scanStatusDisplay">
        <div class="sd-status-rows">
            <div class="sd-status-row">
                <span class="sd-status-key">操作类型</span>
                <span id="statusType" class="sd-status-val-type">入库</span>
            </div>
            <div class="sd-status-row is-wide">
                <span class="sd-status-key">型号</span>
                <span id="statusModel" class="sd-status-val-model">-</span>
            </div>
            <div class="sd-status-row">
                <span class="sd-status-key">数量</span>
                <span id="statusQty" class="sd-status-val-qty">1</span>
            </div>
        </div>
    </div>

    <!-- 连续扫码会话条（开关打开后才显示）：累计本次条数与数量，可撤销最近一条 -->
    <div class="scan-session-bar" id="scanSessionBar">
        <div class="ssb-info">
            <span class="ssb-title">连续扫码中</span>
            <span class="ssb-stat">本次已扫 <b id="ssbCount">0</b> 条 · 入库 <b id="ssbInQty" class="is-in">0</b> 件 · 出库 <b id="ssbOutQty" class="is-out">0</b> 件</span>
        </div>
        <div class="ssb-acts">
            <button type="button" class="btn btn-ghost btn-xs" id="ssbUndoBtn" onclick="undoLastSessionScan()" disabled>↩ 撤销最近一条</button>
            <button type="button" class="btn btn-ghost btn-xs" onclick="endScanSession()">结束本次</button>
        </div>
    </div>
</div>


<div class="overlay sd-cam-overlay" id="cameraModal">
    <div class="modal sd-cam-modal">
        <h3>摄像头扫码</h3>
        <div class="sd-cam-body">
            <div class="camera-section" id="cameraSection">
                <div class="camera-view-outer">
                    <div class="camera-view-wrap">
                        <div id="cameraView"></div>
                        <div class="camera-placeholder" id="cameraPlaceholder">
                            <div>
                                <div class="ph-icon">📷</div>
                                <div class="ph-text">等待摄像头连接…</div>
                            </div>
                        </div>
                        <div class="scan-overlay" id="scanOverlay">
                            <div class="scan-box-outer"></div>
                            <div class="scan-box-inner"></div>
                            <div class="scan-hint-text">将二维码对准框内（灰色区域均可识别）</div>
                        </div>
                    </div>
                </div>
                <div class="camera-controls">
                    <button type="button" class="btn btn-ghost btn-sm sd-torch-btn" id="torchBtn" onclick="toggleTorch()">🔦 闪光灯</button>
                    <span class="camera-status" id="cameraStatusText">📷 摄像头就绪</span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <span class="sd-cam-tip">扫错可在下方记录里点 ↩ 撤销</span>
            <button type="button" class="btn btn-ghost btn-sm" onclick="closeCameraModal()">关闭摄像头</button>
        </div>
    </div>
</div>

<!-- ── 扫码结果弹窗（仅显示信息，自动关闭） ── -->
<div class="modal-overlay sd-result-overlay" id="scanResultModal">
    <div class="modal sd-result-modal">
        <div class="modal-header">
            <h3 id="scanResultTitle" class="sd-modal-title">扫码结果</h3>
        </div>
        <div class="modal-body sd-modal-body" id="scanResultBody">
            <!-- 动态内容 -->
        </div>
    </div>
</div>

<!-- ── 最近扫描记录（30条，10条一页） ── -->
<div class="card card-pad sd-mb16">
    <h3 class="sd-sec-title is-spaced">最近扫描记录</h3>
    <div id="recentScanList">
    <?php if (empty($recentScans)): ?>
    <div class="empty-state sd-empty-dense">暂无扫描记录</div>
    <?php else: ?>
    <div class="table-wrap sd-recent-wrap">
        <table class="data-table" id="recentScanTable">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>商品编号</th>
                    <th>型号</th>
                    <th>类型</th>
                    <th class="sd-th-r">数量</th>
                    <th>备注</th>
                    <th class="sd-th-ops">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentScans as $i => $r): ?>
                <tr class="scan-row" data-page="<?= floor($i / 10) + 1 ?>">
                    <td class="sd-td-time"><?= h(substr($r['created_at'], 0, 16)) ?></td>
                    <td class="sd-td-ppn"><?= h($r['platform_part_no']) ?></td>
                    <td class="sd-td-model"><?= h($r['model'] ?? '-') ?></td>
                    <td>
                        <?php if ($r['scan_type'] === 'in'): ?>
                        <span class="badge badge-green">入库</span>
                        <?php else: ?>
                        <span class="badge badge-red">出库</span>
                        <?php endif; ?>
                    </td>
                    <td class="sd-td-qty <?= $r['scan_type']==='in' ? 'is-in' : 'is-out' ?>"><?= $r['qty'] ?></td>
                    <td class="sd-td-remark"><?= h($r['remark']) ?></td>
                    <td class="sd-td-c">
                        <button type="button" class="btn btn-ghost btn-xs sd-undo" onclick="undoScan(<?= (int)$r['id'] ?>, this)" title="撤销此记录">↩</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $totalScanRows = count($recentScans);
    $totalScanPages = ceil($totalScanRows / 10);
    ?>
    <?php if ($totalScanPages > 1): ?>
    <div class="pagination sd-pagination" id="scanPagination">
        <button type="button" class="page-btn" id="scanPrevBtn" onclick="changeScanPage(-1)">‹</button>
        <?php for ($p = 1; $p <= $totalScanPages; $p++): ?>
            <a class="page-btn <?= $p === 1 ? 'active' : '' ?>" onclick="goToScanPage(<?= $p ?>)" data-scan-page="<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
        <button type="button" class="page-btn" id="scanNextBtn" onclick="changeScanPage(1)">›</button>
        <span class="page-info">共 <?= $totalScanRows ?> 条</span>
        <span class="page-jump"><input type="number" min="1" max="<?= $totalScanPages ?>" placeholder="页码" onkeydown="scanPageJump(event)"></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div>
</div>


<div class="card sd-help" id="sdHelp">
    <button type="button" class="sd-help-toggle" id="sdHelpToggle" onclick="toggleScanHelp()" aria-expanded="false" aria-controls="sdHelpBody">
        <span class="sd-help-title">帮助说明</span>
        <span class="sd-help-sub">扫码方式、匹配规则、撤销与限制</span>
        <span class="sd-help-arrow" aria-hidden="true">▾</span>
    </button>
    <div class="sd-help-body" id="sdHelpBody">
        <ul class="sd-tips-list">
            <li><strong>怎么扫</strong>：点上方「开启摄像头扫码」弹出扫描窗口，对准二维码自动识别；也可直接用扫码枪或手动敲入型号 / 编号后回车。三者走同一套解析逻辑。</li>
            <li><strong>怎么找物料</strong>：按内部ID → 编号 → 型号依次匹配；型号忽略大小写与连字符差异，命中多条时会列候选让你选，不会乱取。</li>
            <li><strong>入库前确认</strong>（默认开启）：先弹确认卡片，可改方向与数量。取消勾选则扫到即入库，适合批量作业；<strong>误扫后请在下方记录点 ↩ 撤销</strong>。</li>
            <li><strong>扫到新料</strong>：库里没有该物料时直接弹新建表单，选「型号 / 编号」与所属平台后保存，按同一数量入库并建档。</li>
            <li><strong>支持的码</strong>：内部物料码 <code class="sd-tips-code">{id:内部ID,pid:平台代码,model:型号,qty:数量,type:in|out}</code>（方向锁定、数量可改）、立创商城二维码（自动入库并读订单号）、纯编号与文本码（弹确认卡片）。</li>
            <li><strong>限制</strong>：5 秒内扫同一物料会被拒绝；出库库存不足自动扣到 0，不会出现负数。</li>
            <li><strong>其他</strong>：语音播报仅 PC 端；<?=\App\Core\Edition::displayName()?>入库须填单价（弹窗会用上次采购价预填）；建档入库的记录不在下方列表里，需到流水中心调整。</li>
        </ul>
        <div class="sd-tips-foot">
            <a href="index.php?v=stock&view=log" class="btn btn-ghost btn-sm">📋 查看全部记录</a>
        </div>
    </div>
</div>

</div>
</div>

<script>
window.ScanPageConfig = <?= json_encode([
    'csrfToken' => csrf(),

    'h5qrUrl' => 'third_party/js/html5-qrcode.min.js?v=' . APP_VERSION . '.' . (is_file($__h5qr) ? filemtime($__h5qr) : '0'),

    'qrAliases' => \App\Helper\Parser\FieldRegistry::qrAliases(),
    // 平台列表：新建物料表单需要用户指定物料归属平台
    'platforms' => array_map(static function($p) {
        return [
            'id'           => (int)$p['id'],
            'code'         => (string)$p['code'],
            'name'         => (string)$p['name'],
            'platformType' => (string)$p['platform_type'],
            'isDefault'    => (int)$p['is_default'] === 1,
        ];
    }, $platforms ?? []),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php
// 扫码页脚本：按功能域拆为 6 个文件。
// **加载顺序不可调换**：core 定义音频/Toast/记录等基础，其余文件依赖它；

$scanJsBase = 'modules/scan/Assets/js/';
$scanJsFiles = ['scan-core', 'scan-camera', 'scan-intent', 'scan-dialog', 'scan-submit', 'scan-init'];
foreach ($scanJsFiles as $sj) {
    $sjPath = __DIR__ . '/../Assets/js/' . $sj . '.js';
    echo '<script src="' . h($scanJsBase . $sj . '.js') . '?v='
        . h(APP_VERSION . '.' . filemtime($sjPath)) . '"></script>' . "\n";
}
?>

</body></html>
