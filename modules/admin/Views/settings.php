<?php
declare(strict_types=1);
/** 网站设置 Tab（仅主管理员） */
if (!\isPrimaryAdmin()) return;

$tip = function (string $text): string {
    return '<span class="help-tip" title="' . h($text) . '">?</span>';
};

$sget = function (string $key, string $default = '') use ($settings): string {
    $v = $settings[$key] ?? '';
    return is_string($v) ? $v : (string)$default;
};

// 其余卡片的当前值（隐藏域保护，防止分卡片保存时被默认值覆盖）
$retentionCur = h($sget('retention_days') !== '' ? $sget('retention_days') : '30');
$staleCur     = h($sget('stale_days') !== '' ? $sget('stale_days') : '90');
$marginCur    = h($sget('default_profit_margin') !== '' ? $sget('default_profit_margin') : '0');
$keepRawCur   = $sget('import_keep_raw') === '1' ? '1' : '0';
// 图片开关同理：默认值 '0'（不显示），故未配置时也按「关闭」处理
$showImgCur   = $sget('parts_show_images') === '1' ? '1' : '0';

// 功能开关定义（双版本）
// 键 => [标签, 说明, 默认值]

$__fd = featureDefaults();
$featToggles = [
    'feature_cost_view'       => ['成本管理', '显示采购单价、加权平均成本、库存金额', $__fd['feature_cost_view']],
    'feature_asset_stats'     => ['资产总览', '按分类/平台统计资产金额与高价值物料排行', $__fd['feature_asset_stats']],
    'feature_bom_version'     => ['BOM 多版本', '同一物料保留多个 BOM 版本并可切换/对比', $__fd['feature_bom_version']],
    'feature_log_view'        => ['操作日志', '记录并显示出入库流水与操作溯源（含 IP）', $__fd['feature_log_view']],
    'enable_location_manage'  => ['库位管理', '启用库位树与结构化绑定；关闭时仅用文本库位备注', $__fd['enable_location_manage']],
];
?>

<div class="card card-pad settings-wrap">

<div class="page-header">
    <h2>网站设置</h2>
    <div class="page-desc">站点基础信息、库存默认值、系统参数与功能开关；每张卡片独立保存，保存后即时生效</div>
</div>

<nav class="settings-anchors">
    <a href="#set-basic" class="is-active">基本设置</a>
    <a href="#set-stock">库存设置</a>
    <a href="#set-system">系统设置</a>
    <a href="#set-version">版本信息</a>
    <a href="#set-feature">功能开关</a>
</nav>

<!-- ═══ 卡片1：基本设置 ═══ -->
<form method="post" action="api.php" enctype="multipart/form-data" class="settings-card" data-card-id="basic">
<input type="hidden" name="action" value="admin.save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<input type="hidden" name="retention_days" value="<?=$retentionCur?>">
<input type="hidden" name="stale_days" value="<?=$staleCur?>">
<input type="hidden" name="default_profit_margin" value="<?=$marginCur?>">
<input type="hidden" name="import_keep_raw" value="<?=$keepRawCur?>">
<input type="hidden" name="parts_show_images" value="<?=$showImgCur?>">
<div class="settings-card-title">
    <span>📌 基本设置</span>
    <span class="settings-card-desc">站点标识与公告</span>
</div>
<div class="settings-grid" id="set-basic">
    <div class="form-group"><label>网站标题</label>
        <input name="site_title" value="<?=h($sget('site_title'))?>">
    </div>
    <div class="form-group"><label>Logo 图片 <?=$tip('可选，建议高度 30px；仅支持图片，最大 2MB')?></label>
        <div>
            <?php if ($sget('site_logo')): ?>
            <div class="settings-logo-preview">
                <img src="<?=h($sget('site_logo'))?>" alt="Logo">
            </div>
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*">
        </div>
    </div>
    <div class="form-group"><label>公告弹出方式</label>
        <select name="notice_show_mode">
            <option value="off"    <?=$sget('notice_show_mode')==='off'   ?'selected':''?>>不显示</option>
            <option value="once"   <?=$sget('notice_show_mode')==='once'  ?'selected':''?>>仅显示一次</option>
            <option value="always" <?=$sget('notice_show_mode')==='always'?'selected':''?>>每次登录显示</option>
        </select>
    </div>
    <div class="form-group sg-full"><label>公告内容 <?=$tip('显示在登录后的公告弹窗中，留空则不显示公告；全体用户（含子用户）统一看到这一条')?></label>
        <textarea name="notice_content" rows="3" placeholder="留空则不显示公告"><?=h($sget('notice_content'))?></textarea>
    </div>
    <?php
          ?>
</div>
<div class="settings-card-footer">
    <span class="settings-foot-hint">改动后需保存才会生效</span>
    <button type="submit" class="btn btn-primary">保存设置</button>
</div>
</form>

<!-- ═══ 卡片2：库存设置 ═══ -->
<form method="post" action="api.php" enctype="multipart/form-data" class="settings-card" data-card-id="stock">
<input type="hidden" name="action" value="admin.save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<input type="hidden" name="retention_days" value="<?=$retentionCur?>">
<input type="hidden" name="import_keep_raw" value="<?=$keepRawCur?>">
<input type="hidden" name="parts_show_images" value="<?=$showImgCur?>">
<div class="settings-card-title">
    <span>📦 库存设置</span>
    <span class="settings-card-desc">阈值、呆滞判定与标签</span>
</div>
<div class="settings-grid" id="set-stock">
    <div class="form-group"><label>低库存阈值 <?=$tip('全局默认低库存预警阈值，优先级：单品 > 分类 > 全局')?></label>
        <input name="default_low_stock" type="number" min="0" value="<?=h($sget('default_low_stock'))?>">
    </div>
    <div class="form-group"><label>呆滞料判定天数 <?=$tip('超过 N 天无出入库且有库存的物料标记为呆滞料，取值 30-365')?></label>
        <input name="stale_days" type="number" min="30" max="365" step="1" value="<?=h($sget('stale_days') !== '' ? $sget('stale_days') : '90')?>">
    </div>
    <div class="form-group"><label>默认利润率 <?=$tip('BOM 项目默认利润率（%），新建项目时自动填入，取值 0-99.99')?></label>
        <input name="default_profit_margin" type="number" min="0" max="99.99" step="0.01" value="<?=h($sget('default_profit_margin') !== '' ? $sget('default_profit_margin') : '0')?>">
    </div>
    <div class="form-group"><label>标签模板 <?=$tip('控制打印标签的整体尺寸与排版：迷你（小标签）、默认（标准）、大标签；保存后打印页即时生效')?></label>
        <select name="label_template">
            <option value="default" <?=$sget('label_template')==='default'?'selected':''?>>默认（标准标签）</option>
            <option value="mini"    <?=$sget('label_template')==='mini'?'selected':''?>>迷你（小标签）</option>
            <option value="large"   <?=$sget('label_template')==='large'?'selected':''?>>大标签</option>
        </select>
    </div>
    <div class="form-group"><label>标签编号内容 <?=$tip('二维码为保证扫码出入库兼容，固定为系统编码格式；此设置控制标签上二维码旁突出显示的编号字段，并作为二维码损坏时的人工核对编号')?></label>
        <select name="label_qr_content">
            <option value="internal_id"     <?=$sget('label_qr_content')==='internal_id'?'selected':''?>>内部编号（打印显示为 P00001）</option>
            <option value="model"           <?=$sget('label_qr_content')==='model'?'selected':''?>>型号</option>
            <option value="platform_part_no"<?=$sget('label_qr_content')==='platform_part_no'?'selected':''?>>商品编号</option>
        </select>
    </div>
</div>
<div class="settings-card-footer">
    <span class="settings-foot-hint">影响预警、补货与标签打印</span>
    <button type="submit" class="btn btn-primary">保存设置</button>
</div>
</form>

<!-- ═══ 卡片3：系统设置 ═══ -->
<form method="post" action="api.php" enctype="multipart/form-data" class="settings-card" data-card-id="system">
<input type="hidden" name="action" value="admin.save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<input type="hidden" name="stale_days" value="<?=$staleCur?>">
<input type="hidden" name="default_profit_margin" value="<?=$marginCur?>">
<div class="settings-card-title">
    <span>⚙️ 系统设置</span>
    <span class="settings-card-desc">外观、会话、留存与版本</span>
</div>
<div class="settings-grid" id="set-system">
    <div class="form-group"><label>默认主题</label>
        <select name="theme_default">
            <option value="dark"  <?=$sget('theme_default')==='dark' ?'selected':''?>>暗色 (Dark)</option>
            <option value="light" <?=$sget('theme_default')==='light'?'selected':''?>>亮色 (Light)</option>
        </select>
    </div>
    <div class="form-group"><label>会话超时 <?=$tip('单位：秒。0 = 默认 30 分钟，建议 1800-86400')?></label>
        <input name="session_timeout" type="number" min="0" step="60" value="<?=h($sget('session_timeout'))?>">
    </div>
    <div class="form-group"><label>记录留存天数 <?=$tip('操作记录最低留存天数，最小 7 天；全系统单据/日志/物料记录未满该天数禁止删除')?></label>
        <input name="retention_days" type="number" min="7" step="1" value="<?=$retentionCur?>">
    </div>
    <div class="form-group"><label>版本检测 <?=$tip('检测远端 version.json，与代码内白名单的发布源比对（Gitee 国内 + GitHub 海外，并行择优）。检测到新版本会弹出更新详情，弹窗内可直接「下载并升级」')?></label>
        <div class="settings-inline">
            <button type="button" class="btn btn-ghost btn-sm" id="btnCheckVersion">手动检测</button>
        </div>
    </div>
    <div class="form-group sg-full"><label>出站环境 <?=$tip('天气 / 一言等外部服务与上方版本检测都走 HTTPS 出站；缺 CA 证书包时全部静默失败，且失败原因不会显示在页面上。此处只读，改 php.ini 后刷新本页即重新检测')?></label>
        <div class="settings-inline">
            <span class="settings-env <?= $outboundEnv['ok'] ? 'is-ok' : 'is-bad' ?>"><?= $outboundEnv['ok'] ? '环境正常' : '环境不可用' ?></span>
            <span class="settings-result"><?= $outboundEnv['ok'] ? 'HTTPS 出站可用' : 'HTTPS 出站会失败' ?></span>
        </div>
    </div>
    <div class="form-group"><label>导入设置 <?=$tip('开启后导入时按行级 JSON 落盘原始数据（import_raw_data），供审计与重放；关闭时零写入，仅保留标准化数据')?></label>
        <div class="settings-inline">
            <label class="settings-check">
                <input type="checkbox" name="import_keep_raw" value="1" <?=$sget('import_keep_raw')==='1'?'checked':''?>>
                <span>保留导入原始数据（行级快照）</span>
            </label>
        </div>
    </div>
    <div class="form-group"><label>物料图片 <?=$tip('默认关闭。关闭后物料列表不渲染图片列、详情页不渲染图区——列表更紧凑、加载更快；已上传的图片与手册仍完整保留，开启后立即恢复')?></label>
        <div class="settings-inline">
            <label class="settings-check">
                <input type="checkbox" name="parts_show_images" value="1" <?=$sget('parts_show_images')==='1'?'checked':''?>>
                <span>在列表与详情页显示图片</span>
            </label>
        </div>
    </div>
<?php  ?>
</div>
<?php if (!$outboundEnv['ok']): ?>
<div class="settings-note is-warn">出站环境不可用：<?=h($outboundEnv['reason'])?></div>
<?php endif; ?>
<div class="settings-note is-info">原始数据与标准化数据均保留 7 天后自动清理（标准化数据是撤销 / 审计的依据，建议仅在排查问题时开启原始层）。</div>
<div class="settings-note">双仓库择优：Gitee 国内 + GitHub 海外，并行测速选低延迟源。检测到新版本会弹出更新详情，弹窗内可「下载并升级」（校验 SHA-256 → 整库备份 → 覆盖程序文件，失败自动回滚；不会覆盖数据库凭据与用户数据）。</div>
<div class="settings-card-footer">
    <span class="settings-foot-hint">主题与会话超时影响全站</span>
    <button type="submit" class="btn btn-primary">保存设置</button>
</div>
</form>

<!-- ═══ 卡片4：版本信息（只读，无保存按钮） ═══ -->
<div class="settings-card" data-card-id="version">
<div class="settings-card-title">
    <span>ℹ️ 版本信息</span>
    <span class="settings-card-desc">安装时设定，日常不可切换</span>
</div>
<div class="settings-grid is-4" id="set-version">
    <div class="form-group"><label>系统版本 <?=$tip('版本在安装时设定，日常不可切换；如需变更请联系部署人员')?></label>
        <span class="settings-ro"><?=\App\Core\Edition::displayName()?></span>
    </div>
    <div class="form-group"><label>当前版本号</label>
        <span class="settings-ro"><?=APP_VERSION?></span>
    </div>
    <div class="form-group"><label>数据库版本 <?=$tip('库中记录的结构版本（system_meta.database_version）与代码要求的版本。若库版本落后且找不到迁移步骤文件，多为手工覆盖升级时漏了 app/core/Migration/Versions/ —— 那种情况数据迁移会被静默跳过')?></label>
        <span class="settings-ro"><span class="mono"><?=h($dbAlignment['db_version'])?></span></span>
        <?php if ($dbAlignment['state'] === 'stalled'): ?>
        <span class="settings-env is-bad">落后但缺迁移步骤文件</span>
        <?php elseif ($dbAlignment['state'] === 'pending'): ?>
        <span class="settings-env is-bad">有待执行的迁移</span>
        <?php endif; ?>
        <?php if ($dbAlignment['state'] === 'stalled'): ?>
        <div class="settings-note is-warn">
            库版本 <b><?=h($dbAlignment['db_version'])?></b> 落后于代码 <b><?=h($dbAlignment['code_version'])?></b>，
            但<b>找不到对应的迁移步骤文件</b>。若是用「只覆盖变更文件」的方式升级，请确认
            <code>app/core/Migration/Versions/</code> 已一并覆盖 —— 漏掉它会让<b>数据迁移被静默跳过</b>
            （结构会被对齐、版本号会被改写成新值，而数据搬迁从未发生且不报错）。
            若本次发布只是纯加列（无数据搬迁），则属正常。
        </div>
        <?php elseif ($dbAlignment['state'] === 'pending'): ?>
        <div class="settings-note is-warn">
            检测到待执行的数据库迁移 —— 系统会暂时冻结业务页并把请求引导到
            <a href="index.php?v=migration">数据库迁移页</a>，在那里执行。
        </div>
        <?php endif; ?>
    </div>
    <?php if (\App\Core\Edition::canViewCost()): ?>
    <div class="form-group"><label>成本缓存重建 <?=$tip('强制重算所有物料的加权平均成本，修复异常数据')?></label>
        <div>
            <button type="button" id="rebuildCostBtn" class="btn btn-ghost btn-full">重建全部物料成本</button>
            <div id="rebuildCostProgress" class="is-hidden settings-progress"></div>
        </div>
    </div>
    <?php else: ?>
    <div class="form-group"><label>成本功能 <?=$tip('开启「功能开关 → 成本管理」后，本页将出现重建按钮')?></label>
        <span class="settings-ro">未开启</span>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ═══ 卡片5：功能开关 ═══ -->
<?php /* 双版本均渲染：所有能力开关都不按版本拆分 —— */ ?>
<form method="post" action="api.php" enctype="multipart/form-data" class="settings-card" data-card-id="feature">
<input type="hidden" name="action" value="admin.save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<input type="hidden" name="retention_days" value="<?=$retentionCur?>">
<input type="hidden" name="stale_days" value="<?=$staleCur?>">
<input type="hidden" name="default_profit_margin" value="<?=$marginCur?>">
<input type="hidden" name="import_keep_raw" value="<?=$keepRawCur?>">
<input type="hidden" name="parts_show_images" value="<?=$showImgCur?>">
<div class="settings-card-title">
    <span>🔧 功能开关</span>
    <span class="settings-card-desc">即时生效，影响菜单与接口</span>
</div>
<div class="feat-grid" id="set-feature">
<?php foreach ($featToggles as $key => $info):

    $val  = getSetting($key, $info[2]);
    $isOn = ($val === '1');
?>
<div class="feat-card <?= $isOn ? 'on' : 'off' ?>" title="点击切换「<?= h($info[0]) ?>」">
    <div class="feat-card-body">
        <div class="feat-label"><?= h($info[0]) ?></div>
        <div class="feat-desc"><?= h($info[1]) ?></div>
    </div>
    <span class="feat-toggle"></span>
    <input type="hidden" name="<?= h($key) ?>" value="<?= $isOn ? '1' : '0' ?>">
</div>
<?php endforeach; ?>
</div>
<div class="settings-card-footer">
    <span class="settings-foot-hint">功能开关可随时切换，保存后即时生效</span>
    <button type="submit" class="btn btn-primary">保存设置</button>
</div>
</form>

</div>

<script src="modules/admin/Assets/js/settings.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/settings.js')?>" defer></script>
