<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** 物料状态枚举 — parts.status 的唯一事实来源 */
final class PartStatus
{
    const ACTIVE   = 'active';
    const FROZEN   = 'frozen';
    const SCRAPPED = 'scrapped';
    const ARCHIVED = 'archived';
    const DELETED  = 'deleted';

    public static function all(): array
    {
        return [self::ACTIVE, self::FROZEN, self::SCRAPPED, self::ARCHIVED, self::DELETED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function getLabel(string $status): string
    {
        return match ($status) {
            self::ACTIVE   => '正常',
            self::FROZEN   => '冻结',
            self::SCRAPPED => '报废',
            self::ARCHIVED => '归档',
            self::DELETED  => '已删除',
            default        => $status,
        };
    }

    public static function canCheckout(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::ARCHIVED], true);
    }

    public static function isVisible(string $status): bool
    {
        return $status !== self::DELETED;
    }
}
