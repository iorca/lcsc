<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** 迁移状态枚举 */
final class MigrationStatus
{
    const PENDING = 'pending';
    const RUNNING = 'running';
    const SUCCESS = 'success';
    const FAILED  = 'failed';
    const SKIPPED = 'skipped';
    const PARTIAL = 'partial';

    public static function all(): array
    {
        return [self::PENDING, self::RUNNING, self::SUCCESS, self::FAILED, self::SKIPPED, self::PARTIAL];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function getLabel(string $status): string
    {
        return match ($status) {
            self::PENDING => '待执行',
            self::RUNNING => '执行中',
            self::SUCCESS => '成功',
            self::FAILED  => '失败',
            self::SKIPPED => '跳过',
            self::PARTIAL => '部分成功',
            default       => $status,
        };
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::SUCCESS, self::FAILED, self::SKIPPED, self::PARTIAL], true);
    }
}
