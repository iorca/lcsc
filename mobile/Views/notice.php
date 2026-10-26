<?php
declare(strict_types=1);
/** mobile/Views/notice.php — 移动端「公告」（二级页） */
if (!defined('MOBILE_FRAME')) { http_response_code(403); exit; }

$noticeContent = (string)getSetting('notice_content', '');
$noticeMode    = (string)getSetting('notice_show_mode', 'off');
?>
<?php if ($noticeContent === '' || $noticeMode === 'off'): ?>
<div class="m-card m-placeholder">
    <div class="m-ph-icon"><span class="m-ico m-ico--megaphone" aria-hidden="true"></span></div>
    <div class="m-ph-title">暂无公告</div>
    <div class="m-ph-text">管理员尚未发布公告。</div>
</div>
<?php else: ?>
<div class="m-card m-note-card">
    <div class="m-note-meta"><?= $noticeMode === 'always' ? '每次登录提醒' : '仅提醒一次' ?></div>
    <div class="m-note-body"><?= nl2br(h($noticeContent)) ?></div>
</div>
<?php endif; ?>
