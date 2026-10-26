<?php
declare(strict_types=1);

namespace Modules\Inventory\Components;

/** 物料详情抽屉组件（卡片化分组） */
final class PartDetail
{
    /** 详情页图片 src：本地文件走 asset.thumb 缩略图接口（省流量），外链原样返回 */
    public static function thumbUrl(string $filepath, int $size = 800): string
    {
        $filepath = trim($filepath);
        if ($filepath === '') return '';
        if (preg_match('~^https?://~i', $filepath)) return $filepath;
        return 'app/api/router.php?action=asset.thumb&size=' . $size
             . '&path=' . rawurlencode($filepath);
    }

    /**
     * 规格书（PDF）链接：本地文件走 asset.datasheet_file 接口输出，
     * 外链原样返回。
     *
     * 为什么不用静态链接：规格书可能存在应用目录之外（设置里配的绝对路径），
     * 而且桌面版 nginx 把整个 uploads/ 禁了（deny all）→ 静态链接必然 403。
     */
    public static function datasheetUrl(string $filepath): string
    {
        $filepath = trim($filepath);
        if ($filepath === '') return '';
        if (preg_match('~^https?://~i', $filepath)) return $filepath;
        return 'app/api/router.php?action=asset.datasheet_file&path=' . rawurlencode($filepath);
    }
    public static function render(array $ctx): string
    {
        $part        = $ctx['part']        ?? [];
        $platType    = (string)($ctx['platType']    ?? 'standard');
        $linkedPart  = $ctx['linkedPart']  ?? null;
        $bulkParts   = $ctx['bulkParts']   ?? [];
        $altParts    = $ctx['altParts']    ?? [];
        $cats        = $ctx['cats']        ?? [];
        $prices      = $ctx['prices']      ?? [];
        $stockHist   = $ctx['stockHist']   ?? [];
        $costHist    = $ctx['costHist']    ?? [];
        $logs        = $ctx['logs']        ?? [];
        $logTotal    = (int)($ctx['logTotal']    ?? 0);
        $totalAsset  = (float)($ctx['totalAsset']  ?? 0);
        $latestCost  = (float)($ctx['latestCost']  ?? 0);
        $globalThr   = (int)($ctx['globalThr']   ?? 0);
        $typeLabel   = (string)($ctx['typeLabel']   ?? '');
        $sc          = (string)($ctx['sc']          ?? 's-ok');
        $canViewCost = (bool)($ctx['canViewCost'] ?? false);
        $id          = (int)($ctx['id']          ?? 0);
        $boms        = $ctx['boms']        ?? [];
        $traceLogs   = $ctx['traceLogs']   ?? [];

$attachments = $ctx['attachments'] ?? [];

        $model       = (string)($part['model']            ?? '');
        $productName = (string)($part['product_name']     ?? '');
        $brand       = (string)($part['brand']            ?? '');
        $package     = (string)($part['package']          ?? '');
        $unit        = (string)($part['unit']             ?? '');
        $ppn         = (string)($part['platform_part_no'] ?? '');
        $stock       = (int)($part['stock']              ?? 0);
        $damaged     = (int)($part['damaged']            ?? 0);
        $location    = (string)($part['location']         ?? '');
        $remark      = (string)($part['remark']           ?? '');
        $avgCost     = (float)($part['avg_cost']         ?? 0);
        $status      = (string)($part['status']           ?? 'active');
        $threshold   = (int)($part['low_stock_threshold'] ?? 0);
        $customerPn  = (string)($part['customer_part_no'] ?? '');
        $updateTime  = (string)($part['update_time']      ?? '');
        $createTime  = (string)($part['create_time']      ?? '');
        $urlTemplate = (string)($part['url_template']     ?? '');
        $platformName = (string)($part['platform_name']    ?? '');
        $internalId  = (int)($part['internal_id']        ?? 0);

        $effThreshold = $threshold > 0 ? $threshold : $globalThr;

        $typeLabelMap = [
            'import'       => ['订单导入', '#4f8ef7'],
            'manual_in'    => ['手动入库', '#22c55e'],
            'manual_out'   => ['手动出库', '#ef4444'],
            'adjust'       => ['库存调整', '#f59e0b'],
            'scan_in'      => ['扫码入库', '#22c55e'],
            'scan_out'     => ['扫码出库', '#ef4444'],
            'damaged'      => ['报损', '#8b5cf6'],
            'repair'       => ['修复', '#8b5cf6'],
            'scan_undo_in' => ['撤销入库', '#f59e0b'],
            'scan_undo_out'=> ['撤销出库', '#f59e0b'],
            'bom_out'      => ['BOM出库', '#ef4444'],
        ];

        $ppnUrl = ($platType === 'standard' && $urlTemplate !== '') ? \platformUrl($urlTemplate, $ppn) : '';

        $catNames = [];
        foreach ($cats as $ct) {
            $catNames[] = (string)($ct['name'] ?? $ct);
        }

        $images = [];
        $pdfs   = [];
        foreach ($attachments as $att) {
            if ((string)($att['filetype'] ?? '') === 'pdf') {
                $pdfs[] = $att;
            } else {
                $images[] = $att;
            }
        }

        // 文件体积展示（组件内自足，不跨模块调 Service 静态方法）
        $fmtSize = static function (int $bytes): string {
            if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
            if ($bytes >= 1024)    return round($bytes / 1024) . 'KB';
            return $bytes . 'B';
        };

        // 问号 tooltip（字段说明，仅展示）
        $costTip = function (string $txt): string {
            return '<span class="q-tip">?<span>' . $txt . '</span></span>';
        };

        \ob_start();
        ?>
<!-- 物料详情抽屉内容（卡片化分组） -->

<!-- 头部快览 -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;gap:12px">
<div style="flex:1;min-width:0;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap">
        <span style="font-weight:700;font-size:18px;color:var(--text)"><?= \h($model) ?></span>
        <?php if ($typeLabel !== ''): ?>
        <span style="background:var(--surface2);color:var(--text2);padding:1px 7px;border-radius:4px;font-size:11px"><?= \h($typeLabel) ?></span>
        <?php endif; ?>
        <span style="display:inline-flex;align-items:center;gap:4px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:600;font-family:'JetBrains Mono',monospace;" title="内部编号（搜索可输 P00001 或 #1）"><?= h(\partCode($internalId)) ?></span>
    </div>
    <?php if($productName !== ''): ?><div style="color:var(--text2);font-size:13px;margin-bottom:6px"><?= \h($productName) ?></div><?php endif; ?>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <?php foreach($catNames as $ct) echo '<span class="cat-tag">'.\h($ct).'</span>'; ?>
        <?php if ($brand !== ''): ?><span style="color:var(--text2);font-size:12px">· <?= \h($brand) ?></span><?php endif; ?>
    </div>
</div>
<div style="text-align:right;flex-shrink:0">
    <div class="stock-num <?=$sc?>" style="font-size:28px;font-weight:700"><?= $stock ?></div>
    <div style="font-size:11px;color:var(--text3)">良品库存</div>
    <?php if($damaged>0): ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:600;color:#8b5cf6;margin-top:4px"><?= $damaged ?></div>
    <div style="font-size:11px;color:var(--text3)">不良品</div>
    <?php endif; ?>
</div>
</div>

<!-- 关键信息快览条 -->
<?php
$facts = ['品牌' => $brand, '封装' => $package, '库位' => $location, '客户料号' => $customerPn, '商品编号' => $ppn];
$facts = array_filter($facts, fn($v) => (string)$v !== '');
if (count($facts) > 0):
?>
<div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-bottom:16px;padding:10px 14px;background:var(--surface2);border-radius:8px;font-size:12px">
    <?php foreach ($facts as $k => $v): ?>
    <span><span style="color:var(--text3)"><?= \h($k) ?>：</span><span style="color:var(--text2);font-family:'JetBrains Mono',monospace"><?= \h($v) ?></span></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 关联标准物料提示 -->
<?php if ($linkedPart !== null): ?>
<div style="background:var(--accent-dim);border:1px solid var(--accent);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;">
    <span style="color:var(--accent);">🔗</span>
    <span style="color:var(--text2);">参数参考自：</span>
    <a href="javascript:void(0)" onclick="openDrawer(<?= (int)$linkedPart['id'] ?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;text-decoration:underline;"><?= \h((string)($linkedPart['model'] ?? '')) ?></a>
    <?php if(!empty($linkedPart['product_name'])): ?><span style="color:var(--text3);font-size:12px;"><?= \h((string)($linkedPart['product_name'])) ?></span><?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ 基础信息卡 ═══ -->
<div class="drawer-card">
    <div class="drawer-card-title">📌 基础信息</div>
    <div class="dc-grid">
        <?php
        $baseItems = [
            '平台'      => $platformName,
            '商品编号'  => $ppn,
            '客户料号'  => $customerPn,
            '型号'      => $model,
            '品牌'      => $brand,
            '封装'      => $package,
            '单位'      => $unit,
            '商品名称'  => $productName,
            '库位'      => $location,
        ];
        foreach ($baseItems as $k => $v):
            if ((string)($v ?? '') === '') continue;
            $mono = 'font-family:var(--font-mono);font-size:12px;';
            $col  = ($k === '商品编号') ? 'color:var(--accent);' : '';
        ?>
        <div class="dc-item"><span class="k"><?= \h($k) ?></span>
            <span class="v" style="<?= $mono ?> <?= $col ?>"><?php if ($k === '商品编号' && $ppnUrl !== ''): ?><a href="<?= \h($ppnUrl) ?>" target="_blank" rel="noopener noreferrer"><?= \h($v) ?> 🔄</a><?php else: ?><?= \h($v) ?><?php endif; ?></span>
        </div>
        <?php endforeach; ?>
        <div class="dc-item"><span class="k">低库存阈值</span>
            <span class="v"><?= $threshold > 0 ? $threshold : '—' ?></span>
        </div>
        <div class="dc-item"><span class="k">最近更新</span>
            <span class="v" title="<?= \h($updateTime) ?>"><?= \h(substr($updateTime, 0, 16)) ?></span>
        </div>
    </div>
    <?php if ($remark !== ''): ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);font-size:12px;color:var(--text2)"><span style="color:var(--text3)">备注：</span><?= \h($remark) ?></div>
    <?php endif; ?>
</div>

<!-- ═══ 图片与手册卡 ═══ -->
<?php
// 图片显示开关（站点级设置，默认关闭）。与列表页读同一个键——

$showImages = \getSetting('parts_show_images', '0') === '1';

// 数据手册链接：导入可带站外URL（FieldRegistry 已注册该字段）。
// 存相对路径的是上传的老附件，存在 attachments 里已单独渲染，

$datasheetRaw = (string)($part['datasheet'] ?? '');
$datasheetUrl = (preg_match('~^https?://~i', $datasheetRaw) === 1) ? $datasheetRaw : '';
?>
<div class="drawer-card">
    <div class="drawer-card-title">📷 图片与手册
        <span class="dc-title-acts">
            <?php // 附件上传/删除是 can_edit 写操作（API 同权限），无编辑权不渲染入口?>
            <?php if (hasPermission('can_edit')): ?>
            <?php if ($showImages): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="attPick(<?= $id ?>, 'image')">＋ 图片</button>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="attPick(<?= $id ?>, 'pdf')">＋ PDF 手册</button>
            <?php // 立创规格书自动下载：按 C 编号从立创抓 PDF 存本地并自动绑定
            if ($ppn !== ''): ?>
            <button type="button" class="btn btn-ghost btn-sm" onclick="attFetchDatasheet(<?= $id ?>)" title="从立创商城下载该型号的规格书 PDF，保存到设置里的规格书目录并自动绑定">⬇ 立创规格书</button>
            <?php endif; ?>
            <?php endif; ?>
        </span>
    </div>

    <?php
    // 无图、无 PDF、无外链手册 → 空态
    $hasAny = ($showImages && !empty($images)) || !empty($pdfs) || $datasheetUrl !== '';
    if (!$hasAny):
    ?>
    <div class="dc-empty">
        <?= $showImages
            ? '还没有上传图片或手册 —— 视觉检索比文本快，认不出料时一张图就够'
            : '还没有上传 PDF 手册 —— 可上传本地文件，也可直接填站外链接（厂商官网 / 数据手册页）' ?>
    </div>
    <?php else: ?>

    <?php // 图区：开关关闭时整块不输出，不留 220px 高的空白框
    if ($showImages && !empty($images)): ?>
    <div class="att-gallery">
        <?php

        ?>
        <button type="button" class="att-main" onclick="attPreview('<?= \h(self::thumbUrl((string)$images[0]['filepath'])) ?>')" title="点击放大">
<img id="attMainImg_<?= $id ?>" src="<?= \h(self::thumbUrl((string)$images[0]['filepath'])) ?>" alt="<?= \h($model) ?> 图片" loading="lazy">
        </button>
        <?php if (count($images) > 1): ?>
        <div class="att-thumbs">
            <?php foreach ($images as $idx => $img): ?>
            <button type="button" class="att-thumb<?= $idx === 0 ? ' on' : '' ?>"
                    onclick="attSwitch(<?= $id ?>, '<?= \h((string)$img['filepath']) ?>', this)"
                    title="<?= \h((string)$img['filename']) ?>">
                <img src="<?= \h(self::thumbUrl((string)$img['filepath'], 160)) ?>" alt="" loading="lazy">
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php // 图片明细行：图区负责「看」，这里负责「管」（删除 / 新窗口看原图）。

    if ($showImages && !empty($images)): ?>
    <div class="att-list">
        <?php foreach ($images as $img): ?>
        <div class="att-row">
            <span class="att-ic">&#128444;</span>
            <span class="att-name" title="<?= \h((string)$img['filename']) ?>"><?= \h((string)$img['filename']) ?></span>
            <span class="att-size"><?= \h($fmtSize((int)$img['filesize'])) ?></span>
            <span class="att-acts">
                <a class="btn btn-ghost btn-sm" href="<?= \h((string)$img['filepath']) ?>" target="_blank" rel="noopener noreferrer">原图</a>
                <?php if (hasPermission('can_edit')): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="attDelete(<?= $id ?>, <?= (int)$img['id'] ?>, this)">删除</button>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php // 站外手册链接：单行，不进 uploads 故不参与删除/缩略图逻辑
    if ($datasheetUrl !== ''): ?>
    <div class="att-list">
        <div class="att-row">
            <span class="att-ic">🔗</span>
            <span class="att-name" title="<?= \h($datasheetUrl) ?>">站外手册链接</span>
            <span class="att-size"><?= \h(parse_url($datasheetUrl, PHP_URL_HOST) ?: '外部链接') ?></span>
            <span class="att-acts">
                <?php ?>
                <a class="btn btn-ghost btn-sm" href="<?= \h($datasheetUrl) ?>" target="_blank" rel="noopener noreferrer">打开链接</a>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($pdfs)): ?>
    <div class="att-list">
        <?php foreach ($pdfs as $pdf): ?>
        <div class="att-row">
            <span class="att-ic">📄</span>
            <span class="att-name" title="<?= \h((string)$pdf['filename']) ?>"><?= \h((string)$pdf['filename']) ?></span>
            <span class="att-size"><?= \h($fmtSize((int)$pdf['filesize'])) ?></span>
            <span class="att-acts">
                <?php ?>
                <a class="btn btn-ghost btn-sm" href="<?= \h(self::datasheetUrl((string)$pdf['filepath'])) ?>" target="_blank" rel="noopener noreferrer">打开</a>
                <?php if (hasPermission('can_edit')): ?>
                <button type="button" class="btn btn-danger btn-sm" onclick="attDelete(<?= $id ?>, <?= (int)$pdf['id'] ?>, this)">删除</button>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ═══ 资产成本卡（团队版）═══ -->
<?php if ($canViewCost): ?>
<div class="drawer-card">
    <div class="drawer-card-title">💰 资产成本</div>
    <div style="display:flex;gap:20px;flex-wrap:wrap">
        <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:2px">物料累计资产总额 <?= $costTip('所有有效入库流水（含税）小计之和；「样品不计资产」的入库记录不计入') ?></div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:600;color:var(--green)">¥<?= number_format($totalAsset, 2) ?></div>
        </div>
        <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:2px">最新采购含税单价 <?= $costTip('最近一次入库流水中的采购单价，用于资产估值与成本核算口径') ?></div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:600;color:var(--accent)"><?= $latestCost > 0 ? '¥'.number_format($latestCost, 4) : '—' ?></div>
            <?php if ($latestCost <= 0): ?><div style="font-size:10px;color:var(--text3);margin-top:2px">暂无采购记录</div><?php endif; ?>
        </div>
        <?php if ($avgCost > 0): ?>
        <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:2px">库存均价 <?= $costTip('当前库存物料的加权平均采购成本') ?></div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:600;color:#f59e0b">¥<?= number_format($avgCost, 4) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══ 图表卡（无数据隐藏图表并显示空态）═══ -->
<div class="drawer-card">
    <div class="drawer-card-title">📈 趋势图表</div>
    <div class="detail-charts" style="margin-bottom:0">
        <div>
            <div class="sec-title" style="margin-bottom:8px"><?= $canViewCost ? '库存变化' : '库存趋势' ?></div>
            <?php if (count($stockHist) >= 2): ?>
            <div class="chart-box"><canvas id="stockChartD_<?= $id ?>"></canvas></div>
            <?php else: ?><div class="dc-empty">暂无足够的库存变化记录</div><?php endif; ?>
        </div>
        <?php if ($canViewCost): ?>
        <div>
            <div class="sec-title" style="margin-bottom:8px">历次采购含税单价曲线</div>
            <?php if (count($prices) >= 2): ?>
            <div class="chart-box"><canvas id="priceChartD_<?= $id ?>"></canvas></div>
            <?php else: ?><div class="dc-empty">暂无采购价格记录</div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canViewCost && count($costHist) >= 2): ?>
    <div class="detail-charts" style="margin:12px 0 0">
        <div style="grid-column:1/-1">
            <div class="sec-title" style="margin-bottom:8px">成本折线 <span style="font-size:10px;color:var(--text3);font-weight:400">（库存数量 vs 采购单价，排除样品）</span></div>
            <div class="chart-box"><canvas id="costChartD_<?= $id ?>"></canvas></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ 关联信息卡（Tab 切换）═══ -->
<div class="drawer-card">
    <div class="drawer-card-title">🔁 关联信息</div>
    <div class="dc-tabs">
        <button type="button" data-tab="alt" class="on">替代料<?= !empty($altParts) ? '（'.count($altParts).'）' : '' ?></button>
        <button type="button" data-tab="bom">所在 BOM<?= !empty($boms) ? '（'.count($boms).'）' : '' ?></button>
        <button type="button" data-tab="trace">操作日志<?= !empty($traceLogs) ? '（'.count($traceLogs).'）' : '' ?></button>
    </div>

    <!-- Tab: 替代料 + 散料 -->
    <div class="dc-panel" data-panel="alt">
        <?php if (empty($altParts) && empty($bulkParts)): ?>
        <div class="dc-empty">暂无替代料 / 散货渠道，可在编辑弹窗中添加</div>
        <?php else: ?>
        <table class="info-table">
        <?php foreach ($altParts as $ap): ?>
        <tr>
            <td>
                <a href="javascript:void(0)" onclick="openDrawer(<?= (int)($ap['id'] ?? 0) ?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;"><?= \h((string)($ap['model'] ?? '')) ?></a>
                <span style="display:inline-block;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:4px;padding:1px 5px;font-size:10px;font-weight:600;font-family:'JetBrains Mono',monospace;margin-left:6px" title="内部编号"><?= h(\partCode($ap['internal_id'] ?? 0)) ?></span>
            </td>
            <td style="font-size:12px;color:var(--text2)"><?= \h((string)($ap['product_name'] ?? '')) ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:12px;">库存: <?= (int)($ap['stock'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($bulkParts as $bp): ?>
        <tr>
            <td><a href="javascript:void(0)" onclick="openDrawer(<?= (int)($bp['id'] ?? 0) ?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;"><?= \h((string)($bp['model'] ?? '')) ?></a> <span style="color:var(--text3);font-size:11px">散料</span></td>
            <td><?php if (!empty($bp['purchase_url'])): ?><a href="<?= \h($bp['purchase_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent);font-size:12px;word-break:break-all;">采购链接 🔄</a><?php else: ?><span style="color:var(--text3);font-size:12px">—</span><?php endif; ?></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?= (int)($bp['stock'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <!-- Tab: 所在 BOM -->
    <div class="dc-panel" data-panel="bom" style="display:none">
        <?php if (empty($boms)): ?>
        <div class="dc-empty">该物料暂未出现在任何 BOM 项目中</div>
        <?php else: ?>
        <table class="info-table">
        <?php foreach ($boms as $bm): ?>
        <tr>
            <td><a href="index.php?v=bom&id=<?= (int)($bm['project_id'] ?? 0) ?>" target="_self" style="color:var(--accent);font-family:'JetBrains Mono',monospace;"><?= \h((string)($bm['project_name'] ?? '—')) ?></a></td>
            <td style="font-size:12px;color:var(--text2)">数量: <?= (int)($bm['qty'] ?? 0) ?></td>
            <td style="font-size:12px;color:var(--text3)"><?= \h(substr((string)($bm['update_time'] ?? ''), 0, 10)) ?></td>
        </tr>
        <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <!-- Tab: 操作日志 -->
    <div class="dc-panel" data-panel="trace" style="display:none">
        <?php if (empty($traceLogs)): ?>
        <div class="dc-empty">暂无操作日志</div>
        <?php else: ?>
        <table class="drawer-table">
        <colgroup><col style="width:18%"><col style="width:16%"><col style="width:14%"><col style="width:52%"></colgroup>
        <thead><tr><th>时间</th><th>操作人</th><th>动作</th><th>详情</th></tr></thead>
        <tbody>
        <?php foreach ($traceLogs as $tl): ?>
        <tr>
            <td class="td-time" title="<?= \h((string)($tl['created_at'] ?? '')) ?>"><?= \h(substr((string)($tl['created_at'] ?? ''), 0, 16)) ?></td>
            <td style="font-size:12px;color:var(--text2)"><?= \h((string)($tl['username'] ?? '—')) ?></td>
            <td><span class="badge badge-blue"><?= \h((string)($tl['action'] ?? '')) ?></span></td>
            <td class="td-remark" title="<?= \h((string)($tl['detail'] ?? '')) ?>" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px"><?= \h((string)($tl['detail'] ?? '—')) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
        <?php // 只展示最近几条（查询侧 LIMIT 5）：全量日志会把抽屉拉得很长。
              // 个人版没有溯源日志页（Tab 被隐藏），故入口只给管理员?>
        <?php if (count($traceLogs) >= 5 && isAdmin()): ?>
        <div class="dc-log-more">仅显示最近 5 条 · <a href="index.php?v=admin&tab=trace" onclick="event.preventDefault();(window._iframeNavigate||function(u){location.href=u})(this.href)">查看全部日志 →</a></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ 库存与流水卡 ═══ -->
<div class="drawer-card">
    <div class="drawer-card-title">📦 库存与流水
        <a class="dc-more" href="index.php?v=stock" onclick="event.preventDefault();(window._iframeNavigate||function(u){location.href=u})(this.href)" style="color:var(--accent);text-decoration:underline">查看全部流水 →</a>
    </div>
    <?php if (empty($logs)): ?>
    <div class="dc-empty">暂无出入库记录</div>
    <?php else: ?>
    <table class="drawer-table drawer-log-table">
    <colgroup>
    <col style="width:15%"><col style="width:12%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:11%"><col style="width:20%">
    </colgroup>
    <thead><tr>
    <?php
    $logHeaders = ['时间','类型','变化','之前','之后'];
    if ($canViewCost) { $logHeaders[] = '含税单价'; }
    if (hasFeature('multi_user')) { $logHeaders[] = '操作人'; }
    $logHeaders[] = '备注';
    foreach ($logHeaders as $h): ?>
    <th><?= $h ?></th>
    <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach (array_slice($logs, 0, 5) as $l):
        $changeType = (string)($l['change_type'] ?? '');
        [$lab, $col] = $typeLabelMap[$changeType] ?? [$changeType, '#7a86a8'];
        $c = (int)($l['qty_change'] ?? 0);
        $isSmp = (int)($l['is_sample'] ?? 0) === 1;
        $rowStyle = $isSmp ? 'background:rgba(245,158,11,.06);opacity:.7;' : '';
    ?>
    <tr style="<?= $rowStyle ?>">
    <td class="td-time" title="<?= \h(substr(($l['order_time'] ?? null) ?: ($l['create_time'] ?? ''), 0, 16)) ?>"><?= \h(substr(($l['order_time'] ?? null) ?: ($l['create_time'] ?? ''), 0, 16)) ?></td>
    <td><span style="background:<?= $col ?>22;color:<?= $col ?>;padding:1px 7px;border-radius:4px;font-size:11px"><?= $lab ?></span><?php if ($isSmp): ?><span style="color:var(--yellow);font-size:9px;margin-left:3px" title="样品不计资产">★</span><?php endif; ?></td>
    <td class="td-mono" style="color:<?= $c >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= $c >= 0 ? '+' : '' ?><?= $c ?></td>
    <td class="td-mono"><?= (int)($l['qty_before'] ?? 0) ?></td>
    <td class="td-mono"><?= (int)($l['qty_after'] ?? 0) ?></td>
    <?php if ($canViewCost): ?><td class="td-mono"><?= (($l['unit_cost'] ?? 0) > 0) ? '¥'.number_format((float)($l['unit_cost']), 4) : '—' ?></td><?php endif; ?>
    <?php if (hasFeature('multi_user')): ?><td class="td-mono"><?= \h((string)($l['operator_name'] ?? '')) ?></td><?php endif; ?>
    <td class="td-remark" title="<?= \h((string)($l['remark'] ?? '')) ?>" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= \h((string)($l['remark'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ═══ 底部操作区 ═══ -->
<div class="dc-ops">
    <?php if (hasPermission('can_edit')): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="openEditPartFromDrawer(<?= $id ?>)">✏️ 编辑</button>
    <?php endif; ?>
    <?php if (hasPermission('can_print')): ?>
    <button type="button" class="btn btn-ghost btn-sm" onclick="printLabel(<?= $id ?>)">🖨 打印标签</button>
    <?php endif; ?>
    <?php if (hasPermission('can_edit')): ?>
    <button type="button" class="btn btn-ghost btn-sm" onclick="drawerStock(<?= $id ?>)">出入库</button>
    <?php endif; ?>
</div>
<?php


?>
        <?php
        return (string)\ob_get_clean();
    }
}
