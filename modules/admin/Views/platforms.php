<?php
declare(strict_types=1);
/** 平台 URL Tab */
$tplStatus  = $tplStatus ?? [];
$tplFields  = $tplFields ?? [];
?>

<div class="card card-pad">

<!-- 说明卡（可折叠） -->
<div class="plat-guide is-open">
    <button type="button" class="plat-guide-head" data-toggle-guide>
        <span class="plat-guide-caret">▸</span>
        <span class="plat-guide-title">平台类型说明与导入模板规则</span>
    </button>
    <div class="plat-guide-body">
        <div class="plat-types">
            <div class="plat-type is-standard">
                <div class="plat-type-name">标准商城</div>
                <div class="plat-type-desc">
                    立创等统一编号的平台。配置 <code>{part_no}</code> 跳转模板后，
                    用户点击商品编号即可跳到官方详情页。
                </div>
            </div>
            <div class="plat-type is-loose">
                <div class="plat-type-name">散货渠道</div>
                <div class="plat-type-desc">
                    淘宝 / 线下等无统一编号的渠道。不需要跳转模板，
                    每个物料单独存储采购链接，适合非标采购场景。
                </div>
            </div>
            <div class="plat-type">
                <div class="plat-type-name">导入模板</div>
                <div class="plat-type-desc">
                    每个平台需上传一次该平台导出的样本文件，系统自动识别列含义并保存为模板；
                    之后导入该平台文件时按模板精准匹配。
                </div>
            </div>
        </div>
        <div class="plat-warn">
            未配置导入模板的平台<strong>不允许上传导入</strong>；平台代码需唯一，重复时无法添加。
        </div>
    </div>
</div>

<!-- 扫码参数配置包导入（全页统一一个入口；按文件里的平台代码匹配） -->
<div class="plat-toolbar">
    <button type="button" class="btn btn-ghost btn-sm" id="qrImportOpenBtn">导入扫码参数配置</button>
    <span class="plat-hint">上传 .json 配置包：按文件里的平台代码匹配，命中即覆盖、未命中会引导新建平台</span>
</div>

<!-- 平台列表 -->
<div class="table-wrap plat-table">
<table>
    <thead><tr>
        <th class="plat-col-def">默认</th>
        <th>代码</th>
        <th>名称</th>
        <th>类型</th>
        <th>URL 跳转模板</th>
        <th class="plat-col-tpl">导入模板</th>
        <th class="plat-col-act">操作</th>
    </tr></thead>
    <tbody>
<?php if (!$platforms): ?>
    <tr>
        <td colspan="7">
            <div class="empty-state">
                <div class="icon">🏬</div>
                <div class="es-desc">还没有平台。用下方表单添加第一个平台后才能导入订单</div>
            </div>
        </td>
    </tr>
<?php endif; ?>
<?php foreach ($platforms as $p):
    $pId      = (int)$p['id'];
    $pType    = $p['platform_type'] ?? 'standard';
    $isStd    = ($pType === 'standard');
    $isDef    = !empty($p['is_default']);
    $st       = $tplStatus[$pId] ?? null;
    $hasTpl   = !empty($st['has']);
    $isBuiltin = !empty($st['is_builtin']);
    $fmtCount = (int)($st['formats'] ?? 0);
?>
<tr>
    <td class="plat-def">
        <form method="post" action="api.php" class="set-default-plat-form">
            <input type="hidden" name="action" value="platform.set_default">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="plat_id" value="<?=$pId?>">
            <input type="radio" name="dummy" class="plat-radio js-set-default"
                   data-id="<?=$pId?>" <?=$isDef ? 'checked' : ''?>
                   title="设为默认平台">
        </form>
    </td>
    <td>
        <span class="plat-code"><?=h($p['code'])?></span>
        <?php if ($isDef): ?><span class="badge badge-green">默认</span><?php endif; ?>
    </td>
    <td><?=h($p['name'])?></td>
    <td>
        <span class="badge <?=$isStd ? 'badge-blue' : 'badge-yellow' ?>">
            <?=$isStd ? '标准商城' : '散货渠道'?>
        </span>
    </td>
    <td>
        <?php if ($isStd && $p['url_template']): ?>
        <span class="plat-url" title="<?=h($p['url_template'])?>"><?=h($p['url_template'])?></span>
        <?php else: ?>
        <span class="plat-url"><?= $isStd ? '未配置' : '不适用' ?></span>
        <?php endif; ?>
    </td>
    <td>
        <div class="plat-tpl">
            <?php if ($hasTpl): ?>
            <div class="plat-tpl-badges">
                <span class="badge badge-green">已配置</span>
                <?php if ($isBuiltin && (int)($st['revision'] ?? 1) <= 1): ?>
                <span class="badge badge-blue" title="系统预置模板，可直接使用">内置</span>
                <?php endif; ?>
            </div>
            <?php if ($fmtCount > 0): ?>
            <span class="plat-tpl-meta"><?=$fmtCount?> 套文件格式</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="badge badge-yellow">未配置</span>
            <?php endif; ?>
        </div>
    </td>
    <td class="td-actions">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs js-tpl-open"
                    data-id="<?=$pId?>"
                    data-name="<?=h($p['name'])?>"
                    data-code="<?=h($p['code'])?>"
                    title="上传样本自动识别列映射"><?= $hasTpl ? '模板' : '配置模板' ?></button>
            <button type="button" class="btn btn-ghost btn-xs plat-edit-btn"
                    data-id="<?=$pId?>"
                    data-code="<?=h($p['code'])?>"
                    data-name="<?=h($p['name'])?>"
                    data-url="<?=h($p['url_template'])?>"
                    data-type="<?=h($pType)?>">编辑</button>
            <form method="post" action="api.php" class="plat-delete-form" data-name="<?=h($p['name'])?>">
                <input type="hidden" name="action" value="platform.delete">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="plat_id" value="<?=$pId?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- 添加 / 编辑平台 -->
<div class="plat-form-box" id="platFormBox">
    <h4 class="plat-form-title" id="platFormTitle">添加新平台</h4>
    <form method="post" action="api.php" id="platForm">
        <input type="hidden" name="action" id="platAction" value="platform.add">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="plat_id" id="platId" value="">
        <div class="plat-form-grid">
            <div class="form-group">
                <label>平台代码 <span class="plat-req">*</span>（英文标识）</label>
                <input name="plat_code" id="platCode" class="plat-mono" placeholder="taobao" required>
            </div>
            <div class="form-group">
                <label>平台名称 <span class="plat-req">*</span></label>
                <input name="plat_name" id="platName" placeholder="淘宝" required>
            </div>
            <div class="form-group">
                <label>平台类型 <span class="plat-req">*</span></label>
                <select name="platform_type" id="platType">
                    <option value="standard">标准商城（配置编号跳转模板）</option>
                    <option value="loose">散货渠道（物料单独存采购链接）</option>
                </select>
            </div>
            <div class="form-group" id="platUrlGroup">
                <label>URL 跳转模板 <span class="plat-hint">（使用 {part_no} 占位符）</span></label>
                <input name="plat_url" id="platUrl" class="plat-mono"
                       placeholder="https://search.example.com?q={part_no}">
                <div class="plat-preview is-empty" id="platUrlPreview">填写模板后此处显示点击商品编号时的实际跳转地址</div>
            </div>
        </div>

        <!-- 扫码参数设置（仅编辑平台时出现；默认收起，点标题展开） -->
        <div class="plat-qr-block is-hidden" id="platQrBlock">
            <div class="plat-guide" id="platQrGuide">
                <button type="button" class="plat-guide-head" id="platQrHead">
                    <span class="plat-guide-caret">▸</span>
                    <span class="plat-guide-title">扫码参数设置</span>
                    <span class="plat-hint">键名即系统字段 · 默认收起</span>
                </button>
                <div class="plat-guide-body">
                    <div class="plat-qr-desc">
                        这里定义「该平台二维码里的原始键 → 系统字段」的映射，直接决定扫码出入库能否识别该平台的码。
                        <strong>写错会让该平台扫码失效</strong>：不确定请勿改动，可随时「重置为内置」还原。
                        完整参数说明、配置生成器与源码见
                        <a href="https://lcsc798.pages.dev/qr-config" class="plat-qr-link" target="_blank" rel="noopener noreferrer">项目官网</a>。
                    </div>
                    <textarea id="platQrJson" class="plat-mono plat-qr-json" rows="10" spellcheck="false" placeholder="[]"></textarea>
                    <div class="plat-qr-actions">
                        <button type="button" class="btn btn-ghost btn-sm" id="platQrLoadBtn">重新加载</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="platQrResetBtn">重置为内置</button>
                        <button type="button" class="btn btn-primary btn-sm" id="platQrSaveBtn">保存扫码参数</button>
                        <span class="plat-qr-status" id="platQrStatus"></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="plat-actions">
            <button type="submit" class="btn btn-primary btn-sm" id="platSubmitBtn">添加平台</button>
            <button type="button" class="btn btn-ghost btn-sm is-hidden" id="platCancelBtn">取消编辑</button>
        </div>
    </form>
</div>
</div>

<!-- ═══ 导入模板弹窗（上传样本 → 自动识别 → 人工确认）═══ -->
<div class="overlay" id="tplModal">
<div class="modal modal-lg plat-modal-lg">
    <h3 class="plat-modal-title">导入模板 <small id="tplPlatName"></small></h3>
    <button type="button" class="modal-close" data-tpl-close aria-label="关闭">×</button>

    <div class="tpl-status" id="tplStatusBar"></div>

    <!-- 步骤0：匹配键优先级（决定「哪些行算同一物料」） -->
    <div class="tpl-step">
        <div class="tpl-step-title">⓪ 匹配键优先级 <small>（按顺序逐个匹配，命中第一个即为同一物料；行内该键为空时自动跳过）</small></div>
        <div class="tpl-match-keys" id="tplMatchKeys">加载中…</div>
    </div>

    <!-- 步骤1：上传样本 -->
    <div class="tpl-step">
        <div class="tpl-step-title">① 上传该平台导出的样本文件</div>
        <div class="tpl-step-desc">
            建议直接使用一次真实导出的订单 / 对账单文件（只读表头，不会写入任何数据）。<br>
            系统会自动识别每一列的含义，识别不准时可在下一步手动纠正。
        </div>
        <div class="tpl-file-row">
            <input type="file" id="tplFile" accept=".xlsx,.xls,.csv,.tsv">
            <button type="button" class="btn btn-primary btn-sm" id="tplParseBtn">解析并识别</button>
        </div>
    </div>

    <!-- 步骤2：映射确认 -->
    <div class="tpl-map-wrap is-hidden" id="tplMapWrap">
        <div class="tpl-step-title">② 确认列映射 <small>（标红的列需人工指派或选择「忽略此列」）</small></div>
        <div class="tpl-map-summary" id="tplMapSummary"></div>
        <div class="tpl-map-scroll">
            <table class="data-table">
                <thead><tr>
                    <th class="tpl-col-src">文件列名</th>
                    <th class="tpl-col-tgt">对应系统字段</th>
                    <th class="tpl-col-why">识别依据</th>
                </tr></thead>
                <tbody id="tplMapBody"></tbody>
            </table>
        </div>
        <div class="tpl-preview-wrap" id="tplPreviewWrap"></div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" data-tpl-close>关闭</button>
        <button type="button" class="btn btn-ghost btn-sm is-hidden" id="tplResetBtn">重置为内置模板</button>
        <button type="button" class="btn btn-primary btn-sm is-hidden" id="tplSaveBtn">保存模板</button>
    </div>
</div>
</div>

<!-- ═══ 扫码参数配置包导入弹窗（上传 .json）═══ -->
<div class="overlay" id="qrCfgModal">
<div class="modal modal-lg plat-modal-lg">
    <h3 class="plat-modal-title">导入扫码参数配置</h3>
    <button type="button" class="modal-close" data-qr-close aria-label="关闭">×</button>

    <div class="tpl-step">
        <div class="tpl-step-title">功能说明</div>
        <div class="tpl-step-desc">
            上传一份平台扫码参数配置文件（<code>.json</code>）。系统会读取文件里的 <code>platform.code</code>：<br>
            · <strong>匹配到现有平台</strong> → 直接<strong>覆盖</strong>该平台的扫码参数（等同套用模板）。<br>
            · <strong>匹配不到</strong> → 请你补充「名称 / 类型 / 跳转模板」后<strong>新建平台</strong>再应用（不会静默建平台）。
        </div>
    </div>

    <div class="tpl-step">
        <div class="tpl-step-title">① 上传配置文件</div>
        <div class="tpl-file-row">
            <input type="file" id="qrCfgFile" accept=".json,application/json">
            <button type="button" class="btn btn-primary btn-sm" id="qrCfgImportBtn">导入并覆盖</button>
        </div>
        <div class="tpl-status" id="qrCfgStatus"></div>
    </div>

    <div class="tpl-step is-hidden" id="qrCfgCreateBlock">
        <div class="tpl-step-title">② 未匹配到平台，请补充后创建</div>
        <div class="tpl-step-desc">配置文件里已带的信息会自动预填，可按需修改。</div>
        <div class="plat-form-grid">
            <div class="form-group"><label>平台代码</label>
                <input id="qrCfgCode" class="plat-mono" readonly>
            </div>
            <div class="form-group"><label>平台名称 <span class="plat-req">*</span></label>
                <input id="qrCfgName" placeholder="平台名称">
            </div>
            <div class="form-group"><label>平台类型</label>
                <select id="qrCfgType">
                    <option value="standard">标准商城（配置编号跳转模板）</option>
                    <option value="loose">散货渠道（物料单独存采购链接）</option>
                </select>
            </div>
            <div class="form-group"><label>URL 跳转模板</label>
                <input id="qrCfgUrl" class="plat-mono" placeholder="https://search.example.com?q={part_no}">
            </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="qrCfgCreateBtn">创建平台并应用</button>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost btn-sm" data-qr-close>关闭</button>
    </div>
</div>
</div>

<script src="modules/admin/Assets/js/platforms.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/platforms.js')?>" defer></script>
