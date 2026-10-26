<?php
declare(strict_types=1);

?>
<!-- ── 兼容路径（非工作室主框架、非个人版）：保留原有顶栏导航 ── -->

<div class="topbar">
    <div class="logo">
        <?php if($siteLogo): ?><img src="<?=h($siteLogo)?>" alt=""><?php endif; ?>
        <?=h($siteTitle)?>
    </div>
    <nav class="topbar-nav" id="topbarNav">
        <!-- 首页 -->
        <a href="index.php" <?=in_array($activePage,['index',''],true)?'class="active"':''?>>首页</a>
        <!-- 物料 -->
        <a href="index.php?v=categories" <?=$activePage==='categories'?'class="active"':''?>>物料</a>
        <!-- 项目 -->
        <?php if(hasFeature('bom_basic')): ?><a href="index.php?v=bom" <?=$activePage==='bom'?'class="active"':''?>>项目</a><?php else: ?><a href="javascript:void(0)" class="is-off" onclick="LCSC.toast('当前版本不支持此功能')">项目</a><?php endif; ?>
        <!-- 库存（下拉） -->
        <div class="nav-dropdown">
            <a href="index.php?v=scan" class="<?=in_array($activePage,['scan','stock'],true)?'active':''?>">库存 ▾</a>
            <div class="nav-dropdown-menu">
                <a href="index.php?v=scan">出入库</a>
                <a href="index.php?v=stock">流水中心</a>
                <?php if(hasFeature('checklist')): ?>
                <a href="index.php?v=checklist">盘点</a>
                <?php endif; ?>
            </div>
        </div>
        <!-- 工具（下拉） -->
        <div class="nav-dropdown">
            <a href="javascript:void(0)" class="<?=in_array($activePage,['import','export','assets','data_health'],true)?'active':''?>">工具 ▾</a>
            <div class="nav-dropdown-menu">
                <a href="index.php?v=import">导入</a>
                <?php if(hasFeature('export_simple')): ?><a href="index.php?v=export">数据导出中心</a><?php else: ?><a href="javascript:void(0)" class="is-off" onclick="LCSC.toast('当前版本不支持此功能')">数据导出中心</a><?php endif; ?>
                <?php if(hasFeature('feature_asset_stats')): ?><a href="index.php?v=assets">资产总览</a><?php endif; ?>
                <a href="index.php?v=data_health">数据质量</a>
            </div>
        </div>
        <?php if(isPrimaryAdmin()): ?>
        <!-- 设置 -->
        <a href="index.php?v=admin" <?=$activePage==='admin'?'class="active"':''?>>设置</a>
        <?php endif; ?>
    </nav>
    <div class="topbar-right">
        <button class="icon-btn" id="themeBtn" onclick="toggleTheme()" title="切换主题">🌓</button>
        <?= $extraTopbarRight ?>
        <form method="post" action="logout.php" class="form-inline" id="logoutForm">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <button type="submit" class="icon-btn is-plain">退出</button>
        </form>
    </div>
</div>
