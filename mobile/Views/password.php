<?php
declare(strict_types=1);
/** mobile/Views/password.php — 移动端「修改密码」（二级页） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }
?>
<form class="m-card m-form" id="mPwForm" autocomplete="off">
    <div class="m-field">
        <label class="m-label" for="mPwOld">当前密码</label>
        <input class="m-input" type="password" id="mPwOld" name="old_password" autocomplete="current-password" required>
    </div>
    <div class="m-field">
        <label class="m-label" for="mPwNew">新密码</label>
        <input class="m-input" type="password" id="mPwNew" name="new_password" autocomplete="new-password" required>
        <div class="m-hint">至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种</div>
    </div>
    <div class="m-field">
        <label class="m-label" for="mPwConfirm">确认新密码</label>
        <input class="m-input" type="password" id="mPwConfirm" name="confirm" autocomplete="new-password" required>
    </div>
    <button type="submit" class="m-btn m-btn-primary">保存新密码</button>
</form>
