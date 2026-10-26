<?php
declare(strict_types=1);
/** mobile/Views/profile.php — 移动端「我的」 */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$editionName = \App\Core\Edition::displayName();   // 展示名单一来源（Edition::displayName）
$roleLabel   = isPrimaryAdmin() ? '主管理员' : '子用户';
?>
<!-- 账号信息（横向紧凑：头像左 + 名字与徽章右） -->
<div class="m-card m-profile-card">
    <div class="m-profile-avatar"><?= h(mb_substr((string)($user['username'] ?? '?'), 0, 1)) ?></div>
    <div class="m-profile-main">
        <div class="m-profile-name"><?= h($user['username'] ?? '') ?></div>
        <div class="m-profile-badges">
            <span class="m-badge m-badge-blue"><?= h($roleLabel) ?></span>
            <span class="m-badge m-badge-gray"><?= h($editionName) ?></span>
        </div>
    </div>
</div>


<div class="m-card">
    <div class="m-slogan is-loading" id="mSlogan">···</div>
    <?php if (!\App\Core\Http\WeatherResolver::isIntranetHost()): ?>
    <button type="button" class="m-weather" id="mWeather" disabled title="查看天气详情">
        
        <span class="m-weather-ico" id="mWeatherIcon"><span class="m-ico m-ico--weather" aria-hidden="true"></span></span>
        <span class="m-weather-main">
            <span class="m-weather-text" id="mWeatherText">天气加载中…</span>
            <span class="m-weather-city" id="mWeatherCity"></span>
        </span>
        <span class="m-weather-temp" id="mWeatherTemp"></span>
        <span class="m-weather-arrow" aria-hidden="true">›</span>
    </button>
    <?php endif; ?>
</div>

<!-- 天气详情弹窗（信息类浮层；用户明确要求；非表单，不触「表单嵌套弹窗」禁区） -->
<div class="m-modal is-hidden" id="mWeatherModal" role="dialog" aria-modal="true" aria-labelledby="mWeatherModalTitle">
    <div class="m-modal-mask" id="mWeatherModalMask"></div>
    <div class="m-modal-panel">
        <div class="m-modal-head">
            <span class="m-modal-title" id="mWeatherModalTitle">天气详情</span>
            <button type="button" class="m-modal-close" id="mWeatherModalClose" aria-label="关闭">×</button>
        </div>
        <div class="m-modal-body" id="mWeatherModalBody"></div>
    </div>
</div>

<!-- 功能入口（除主题开关外均为二级页，顶栏带返回） -->
<div class="m-card m-list">
    <a class="m-list-item" href="index.php?v=password">
        <span class="m-list-icon"><span class="m-ico m-ico--key" aria-hidden="true"></span></span>
        <span class="m-list-text">修改密码</span>
        <span class="m-list-arrow">›</span>
    </a>
    <?php ?>
    <?php if (hasFeature('feature_log_view')): ?>
    <a class="m-list-item" href="index.php?v=my_logs">
        <span class="m-list-icon"><span class="m-ico m-ico--clipboard" aria-hidden="true"></span></span>
        <span class="m-list-text">我的操作记录</span>
        <span class="m-list-arrow">›</span>
    </a>
    <?php endif; ?>
    <a class="m-list-item" href="index.php?v=notice">
        <span class="m-list-icon"><span class="m-ico m-ico--megaphone" aria-hidden="true"></span></span>
        <span class="m-list-text">公告</span>
        <span class="m-list-arrow">›</span>
    </a>
    <a class="m-list-item" href="index.php?v=about">
        <span class="m-list-icon"><span class="m-ico m-ico--info" aria-hidden="true"></span></span>
        <span class="m-list-text">关于</span>
        <span class="m-list-arrow">›</span>
    </a>
    <button type="button" class="m-list-item" onclick="MUI.toggleTheme()">
        <span class="m-list-icon"><span class="m-ico m-ico--moon" aria-hidden="true"></span></span>
        <span class="m-list-text">切换主题</span>
        <span class="m-list-arrow">›</span>
    </button>
</div>

<!-- 退出登录 -->
<form method="post" action="../logout.php" class="m-card m-logout-form">
    <input type="hidden" name="_csrf" value="<?= h(csrf()) ?>">
    <button type="submit" class="m-btn m-btn-danger">退出登录</button>
</form>
