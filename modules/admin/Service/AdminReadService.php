<?php
declare(strict_types=1);

namespace Modules\Admin\Service;

use PDO;

/** 后台读模型服务 */
final class AdminReadService
{
    private PDO $db;
    private int $uid;
    private int $wsId;

    public function __construct(PDO $db, int $uid, int $wsId)
    {
        $this->db   = $db;
        $this->uid  = $uid;
        $this->wsId = $wsId;
    }

    /** 全局设置（`settings` 表 k/v）+ 子用户公告（`user_settings`，挂当前 uid） */
    public function getSettings(): array
    {
        $settings = [];
        try {
            $stmt = $this->db->prepare("SELECT k, v FROM settings");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[(string)$row['k']] = (string)$row['v'];
            }
        } catch (\Throwable $e) {
            error_log('AdminReadService::getSettings: ' . $e->getMessage());
        }
        try {
            $settings['sub_notice_content'] = \getUserSetting($this->uid, 'sub_notice_content', '');
            $settings['sub_notice_mode']    = \getUserSetting($this->uid, 'sub_notice_mode', 'off');
        } catch (\Throwable $e) {
            error_log('AdminReadService::getSettings(subNotice): ' . $e->getMessage());
        }
        return $settings;
    }

    /** 平台列表 */
    public function getPlatforms(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, code, name, url_template, is_default, platform_type
                 FROM platforms WHERE user_id=? AND workspace_id=?
                 ORDER BY id"
            );
            $stmt->execute([$this->uid, $this->wsId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('AdminReadService::getPlatforms: ' . $e->getMessage());
            return [];
        }
        $seen = [];
        return array_values(array_filter($rows, function ($p) use (&$seen) {
            $code = (string)$p['code'];
            if (isset($seen[$code]) && $seen[$code] < (int)$p['id']) return false;
            $seen[$code] = (int)$p['id'];
            return true;
        }));
    }

    /** 全部用户（仅主管理员可见，含自身） */
    public function getUsers(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, username, role, parent_id, must_change_pw, created_at, last_login
                 FROM users WHERE workspace_id=? ORDER BY id"
            );
            $stmt->execute([$this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('AdminReadService::getUsers: ' . $e->getMessage());
            return [];
        }
    }

    /** 子用户列表（`parent_id` 指向当前用户） */
    public function getSubUsers(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, username, role, must_change_pw, permissions, created_at, last_login
                 FROM users WHERE parent_id=? AND workspace_id=? ORDER BY id"
            );
            $stmt->execute([$this->uid, $this->wsId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('AdminReadService::getSubUsers: ' . $e->getMessage());
            return [];
        }
    }

    /** 库版本与「结构对齐状态」—— 供网站设置页显示，并为**手工覆盖升级漏文件**兜底提示 */
    public function getDbAlignment(): array
    {
        $out = [
            'code_version' => \defined('DATABASE_VERSION') ? (string)\DATABASE_VERSION : '—',
            'db_version'   => '—',
            'state'        => 'unknown',
        ];
        try {
            $engine = new \App\Core\Migration\MigrationEngine($this->db);
            $cur = $engine->getCurrentVersion();
            $out['db_version'] = $cur;

            if ($cur === '0.0' || $cur === '0') {          // 空库 / 全新安装：不判状态
                $out['state'] = 'unknown';
                return $out;
            }
            if (!$engine->needsMigration()) {               // 已对齐
                $out['state'] = 'ok';
                return $out;
            }
            $out['state'] = $engine->getMigrationPath() !== [] ? 'pending' : 'stalled';
        } catch (\Throwable $e) {
            error_log('AdminReadService::getDbAlignment: ' . $e->getMessage());
        }
        return $out;
    }

    /** 系统监控数据（仅主管理员调用） */
    public function getMonitorData(int $logPage, int $logPerPage): array
    {
        $today = date('Y-m-d');

        // 今日访问统计
        $visStmt = $this->db->prepare("SELECT * FROM daily_stats WHERE stat_date=?");
        $visStmt->execute([$today]);
        $todayStats = $visStmt->fetch() ?: ['total_visits' => 0, 'unique_ips' => 0];

        // 近 7 天访问趋势
        $visitTrend = $this->db->query(
            "SELECT stat_date, total_visits, unique_ips FROM daily_stats
             WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY stat_date ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // 在线用户（5 分钟内活动）—— 按 workspace 隔离
        $onlineStmt = $this->db->prepare(
            "SELECT id, username, role, last_activity FROM users
             WHERE workspace_id=?
               AND last_activity IS NOT NULL
               AND last_activity > NOW() - INTERVAL 5 MINUTE
             ORDER BY last_activity DESC"
        );
        $onlineStmt->execute([$this->wsId]);
        $onlineUsers = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);

        $logPerPage = max(10, min(50, $logPerPage));
        $logTotal   = (int)$this->db->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
        $logTotalPage = max(1, (int)ceil($logTotal / $logPerPage));
        $logPage = min(max(1, $logPage), $logTotalPage);
        $logOffset = ($logPage - 1) * $logPerPage;

        $loginStmt = $this->db->prepare(
            "SELECT la.*, u.username FROM login_attempts la
             LEFT JOIN users u ON u.username=la.username
             ORDER BY la.created_at DESC LIMIT $logPerPage OFFSET $logOffset"
        );
        $loginStmt->execute();
        $loginLogs = $loginStmt->fetchAll(PDO::FETCH_ASSOC);

        // 今日登录成功 / 失败统计
        $loginStatsStmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN success=1 THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN success=0 THEN 1 ELSE 0 END) AS fail_count,
                COUNT(DISTINCT username) AS unique_users,
                COUNT(DISTINCT ip) AS unique_ips_today
             FROM login_attempts WHERE DATE(created_at)=?"
        );
        $loginStatsStmt->execute([$today]);
        $loginStats = $loginStatsStmt->fetch()
            ?: ['success_count' => 0, 'fail_count' => 0, 'unique_users' => 0, 'unique_ips_today' => 0];

        // 近 24h 登录时段分布：按 4 小时一段分 6 段（大屏环形时段图）
        $loginSlots = array_fill(0, 6, 0);
        $slotStmt = $this->db->query(
            "SELECT FLOOR(HOUR(created_at)/4) AS slot, COUNT(*) AS cnt
             FROM login_attempts
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY slot"
        );
        foreach ($slotStmt->fetchAll(PDO::FETCH_ASSOC) as $slotRow) {
            $slotIdx = (int)$slotRow['slot'];
            if ($slotIdx >= 0 && $slotIdx <= 5) $loginSlots[$slotIdx] = (int)$slotRow['cnt'];
        }

        // 近 30 天总访问量
        $monthStats = $this->db->query(
            "SELECT SUM(total_visits) AS month_visits, SUM(unique_ips) AS month_ips
             FROM daily_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)"
        )->fetch() ?: ['month_visits' => 0, 'month_ips' => 0];

        // 最活跃用户（近 7 天登录次数）
        $activeUsers = $this->db->query(
            "SELECT username, COUNT(*) AS login_count, MAX(created_at) AS last_login
             FROM login_attempts
             WHERE success=1 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
             GROUP BY username ORDER BY login_count DESC LIMIT 5"
        )->fetchAll(PDO::FETCH_ASSOC);

        $serverInfo = $this->getServerInfo();

        return [
            'todayStats'    => $todayStats,
            'visitTrend'    => $visitTrend,
            'onlineUsers'   => $onlineUsers,
            'loginLogs'     => $loginLogs,
            'serverInfo'    => $serverInfo,
            'loginStats'    => $loginStats,
            'loginSlots'    => $loginSlots,
            'monthStats'    => $monthStats,
            'activeUsers'   => $activeUsers,
            'logPage'       => $logPage,
            'logTotalPage'  => $logTotalPage,
            'logTotal'      => $logTotal,
            'logPerPage'    => $logPerPage,
        ];
    }

    /** 服务器与资源信息 */
    public function getServerInfo(): array
    {
        $mysqlVer = $this->db->query("SELECT VERSION()")->fetchColumn() ?: '未知';

        $info = [
            'php'         => phpversion(),
            'server'      => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'mysql'       => $mysqlVer,
            'os'          => php_uname('s') . ' ' . php_uname('r'),
            'max_upload'  => ini_get('upload_max_filesize') ?: '未知',
            'max_post'    => ini_get('post_max_size') ?: '未知',
            'memory'      => ini_get('memory_limit') ?: '未知',
        ];

        // 磁盘使用（站点所在分区；获取失败为 0，前端隐藏进度条）
        $diskTotal = @disk_total_space('.');
        $diskFree  = @disk_free_space('.');
        $info['disk_total'] = $diskTotal !== false ? (int)$diskTotal : 0;
        $info['disk_free']  = $diskFree !== false ? (int)$diskFree : 0;

        // 物理内存：仅 Linux 可读 /proc/meminfo；其余平台保持 0
        $memTotal = 0;
        $memFree  = 0;
        if (is_readable('/proc/meminfo')) {
            $mi = @file_get_contents('/proc/meminfo');
            if ($mi
                && preg_match('/MemTotal:\s+(\d+)\s+kB/', $mi, $m1)
                && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $mi, $m2)) {
                $memTotal = (int)$m1[1] * 1024;
                $memFree  = (int)$m2[1] * 1024;
            }
        }
        $info['mem_total'] = $memTotal;
        $info['mem_used']  = $memTotal > 0 ? $memTotal - $memFree : 0;

        return $info;
    }

    /** 出站环境自检（`HttpClient::diagnose()`） */
    public function getOutboundEnv(): array
    {
        return \App\Core\Http\HttpClient::diagnose();
    }
}
