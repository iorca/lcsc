<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use PDO;

/** NoticeService — 公告可见性判定与「已读」打标 */
final class NoticeService
{
    private PDO $db;
    private int $uid;

    public function __construct(PDO $db, int $uid)
    {
        $this->db  = $db;
        $this->uid = $uid;
    }

    /** 解析公告可见性 */
    public function getNotices(): array
    {
        $out = [
            'noticeContent'    => '',
            'noticeMode'       => 'off',
            'showNotice'       => false,
            'subNoticeContent' => '',
            'subNoticeMode'    => 'off',
            'showSubNotice'    => false,
        ];

        // ── 站点公告 ──
        $content = (string)\getSetting('notice_content', '');
        $mode    = (string)\getSetting('notice_show_mode', 'off');
        $out['noticeContent'] = $content;
        $out['noticeMode']    = $mode;
        if ($content !== '' && $mode !== 'off') {
            if ($mode === 'always') {
                $out['showNotice'] = true;
            } else {
                $ver = md5($content);
                try {
                    if (!$this->isSeen($ver)) {
                        $out['showNotice'] = true;
                        $this->markSeen($ver);
                    }
                } catch (\Throwable $e) { error_log('NoticeService global: ' . $e->getMessage()); }
            }
        }

        return $out;
    }

    /** 该用户是否已看过指定版本 */
    private function isSeen(string $version): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM notice_seen WHERE user_id=? AND version=?");
        $stmt->execute([$this->uid, $version]);
        return (bool)$stmt->fetchColumn();
    }

    /** 打「已看」标记（`INSERT IGNORE` 保证并发下只留一条） */
    private function markSeen(string $version): void
    {
        $this->db->prepare("INSERT IGNORE INTO notice_seen (user_id,version) VALUES (?,?)")
                  ->execute([$this->uid, $version]);
    }
}
