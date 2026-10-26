<?php
declare(strict_types=1);

namespace App\Core\Migration;

use PDO;
use Throwable;

/** 迁移闸门 —— 「是否处于待迁移（封印）状态」的唯一判据 */
final class MigrationGate
{
    /** 进程内缓存的探测结果（null = 尚未探测） */
    private static ?bool $pending = null;

    /** 是否处于待迁移状态 */
    public static function pending(PDO $db, bool $refresh = false): bool
    {
        if (self::$pending !== null && !$refresh) return self::$pending;

        self::$pending = false;
        try {
            $engine  = new MigrationEngine($db);
            $current = $engine->getCurrentVersion();

            if ($current === '0.0' || $current === '0') return self::$pending = false;

            self::$pending = $engine->needsMigration() && $engine->getMigrationPath() !== [];
        } catch (Throwable $e) {

            error_log('MigrationGate::pending: ' . $e->getMessage());
            self::$pending = false;
        }
        return self::$pending;
    }

    /** 预演数据（页面 / API 复用同一份探测结果） */
    public static function probe(PDO $db): ?array
    {
        if (!self::pending($db)) return null;
        try {
            return (new MigrationEngine($db))->dryRun();
        } catch (Throwable $e) {
            error_log('MigrationGate::probe: ' . $e->getMessage());
            return null;
        }
    }

    /** 「落后但缺步骤文件」** —— 必须显式提示、不可静默放行的状态 */
    public static function stalled(PDO $db): bool
    {
        try {
            $engine  = new MigrationEngine($db);
            $current = $engine->getCurrentVersion();
            if ($current === '0.0' || $current === '0') return false;   // 空库 / 全新安装
            return $engine->needsMigration() && $engine->getMigrationPath() === [];
        } catch (Throwable $e) {
            error_log('MigrationGate::stalled: ' . $e->getMessage());
            return false;
        }
    }
}
