<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** BOM 版本状态枚举 */
final class BomVersionStatus
{
    const DRAFT    = 'draft';
    const RELEASED = 'released';
    const ARCHIVED = 'archived';

    public static function all(): array
    {
        return [self::DRAFT, self::RELEASED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function getLabel(string $status): string
    {
        return match ($status) {
            self::DRAFT    => '草稿',
            self::RELEASED => '已发布',
            self::ARCHIVED => '已归档',
            default        => $status,
        };
    }

    public static function isEditable(string $status): bool
    {
        return $status === self::DRAFT;
    }

    public static function isReadonly(string $status): bool
    {
        return in_array($status, [self::RELEASED, self::ARCHIVED], true);
    }
}
