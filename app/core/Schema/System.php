<?php
declare(strict_types=1);

namespace App\Core\Schema;

use Throwable;

/** 数据结构定义：系统与用户域 */
function schemaSystem(\PDO $db): void
{
    // ── 系统设置 ──
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 用户表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') DEFAULT 'admin',
        must_change_pw TINYINT(1) DEFAULT 0,
        parent_id     INT DEFAULT NULL,
        permissions   TEXT DEFAULT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login    DATETIME,
        last_activity DATETIME DEFAULT NULL,
        INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 登录失败记录（防暴力破解）──
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50) NOT NULL,
        ip         VARCHAR(45) NOT NULL,
        success    TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username_time (username, created_at),
        INDEX idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 公告已读 ──
    $db->exec("CREATE TABLE IF NOT EXISTS notice_seen (
        user_id    INT NOT NULL,
        version    VARCHAR(32) NOT NULL,
        seen_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 用户级设置表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT NOT NULL,
        k       VARCHAR(100) NOT NULL,
        v       TEXT,
        PRIMARY KEY (user_id, k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 每日访问统计 ──
    $db->exec("CREATE TABLE IF NOT EXISTS daily_stats (
        stat_date    DATE PRIMARY KEY,
        total_visits INT DEFAULT 0,
        unique_ips   INT DEFAULT 0,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 每日IP记录 ──
    $db->exec("CREATE TABLE IF NOT EXISTS daily_ip_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE NOT NULL,
        ip        VARCHAR(45) NOT NULL,
        UNIQUE KEY uk_date_ip (stat_date, ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 操作溯源追责日志（全系统写操作留存）──

    $db->exec("CREATE TABLE IF NOT EXISTS trace_log (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        user_id         INT NOT NULL DEFAULT 0,
        action          VARCHAR(100) NOT NULL,
        target_type     VARCHAR(50) NOT NULL DEFAULT '',
        target_id       INT NOT NULL DEFAULT 0,
        detail          TEXT,
        ip              VARCHAR(45),
        business_table  VARCHAR(50) DEFAULT NULL COMMENT '关联业务表名',
        business_id     INT DEFAULT NULL COMMENT '关联业务记录ID',
        snapshot_before JSON DEFAULT NULL COMMENT '操作前数据快照',
        snapshot_after  JSON DEFAULT NULL COMMENT '操作后数据快照',
        operator_id     INT DEFAULT NULL COMMENT '操作人ID',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_target (target_type, target_id),
        INDEX idx_time (created_at),
        INDEX idx_operator (operator_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 外部服务缓存（天气 / 一言 / 物流轨迹等出站数据的降级底座）──
    // 缓存是必需项而非优化项：免费接口 QPS 限制很紧，且官方会封禁大流量站点。

    $db->exec("CREATE TABLE IF NOT EXISTS external_cache (
        cache_key  VARCHAR(190) PRIMARY KEY,
        payload    MEDIUMTEXT,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── users 表缺失列迁移 ──
    try { $db->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL AFTER created_at"); } catch(Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL AFTER last_login"); } catch(Throwable $e) {}

    // ── 默认配置插入 ──
    $defaults = [
        'site_title'        => 'LCSC 硬件资产管理',
        'site_logo'         => '',
        'notice_content'    => '',
        'notice_show_mode'  => 'once',
        'default_low_stock' => '10',
        'theme_default'     => 'light',
        'session_timeout'   => '0',
        'label_template'    => 'default',
        'label_qr_content'  => 'internal_id',
    ];
    foreach ($defaults as $k => $v) {
        $db->prepare("INSERT IGNORE INTO settings (k,v) VALUES (?,?)")->execute([$k,$v]);

        \clearSettingCache($k);
    }

    // ── 默认管理员（仅非安装向导模式且无任何用户时创建，作为兜底）──

    $existing = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ((int)$existing === 0 && !defined('INSTALL_MODE')) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username,password_hash,role,must_change_pw) VALUES (?,?,?,?)")
           ->execute(['admin', $hash, 'admin', 1]);
    }

    // ── uploads 目录自动创建 ──

    $uploadDir = dirname(__DIR__, 3) . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
}
