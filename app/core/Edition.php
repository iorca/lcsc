<?php
declare(strict_types=1);

namespace App\Core;

/** 版本/授权封装类 */
final class Edition
{
    /** 版本**展示名**的唯一来源 */
    public static function displayName(): string
    {
        return \getEdition() === 'personal' ? '个人版' : '团队版';
    }

    /** 是否为个人版 */
    public static function isPersonal(): bool
    {
        return \getEdition() === 'personal';
    }

    /** 是否为团队版（标识符 `studio`） */
    public static function isStudio(): bool
    {
        return \getEdition() === 'studio';
    }

    /** 是否拥有指定功能特性 */
    public static function feature(string $name): bool
    {
        return \hasFeature($name);
    }

    /** 是否可查看成本 */
    public static function canViewCost(): bool
    {
        return \can_view_cost();
    }

    /** 是否拥有指定权限 */
    public static function can(string $perm): bool
    {
        return \hasPermission($perm);
    }

    /** 是否强制单价模式 */
    public static function enforceUnitPrice(): bool
    {
        return \isEnforceUnitPrice();
    }

    /** 是否为单仓库模式 */
    public static function isSingleWarehouse(): bool
    {
        return \is_single_warehouse();
    }

    /** 版本是否锁定 */
    public static function isLocked(): bool
    {
        return \isEditionLocked();
    }
}
