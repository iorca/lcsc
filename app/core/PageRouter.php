<?php
declare(strict_types=1);

namespace App\Core;

/** PageRouter — 统一页面调度器 */
final class PageRouter
{
    /** 页面路由表（key = ?v= 参数，value = 模块视图相对路径） */
    private const PAGES = [
        'home'            => 'modules/inventory/Views/dashboard.php',
        'parts'           => 'modules/inventory/Views/index.php',
        'admin'           => 'modules/admin/Views/index.php',
        'bom'             => 'modules/bom/Views/index.php',
        'scan'            => 'modules/scan/Views/index.php',
        'categories'      => 'modules/category/Views/index.php',
        'locations'       => 'modules/location/Views/index.php',
        'stale'           => 'modules/stock/Views/stale.php',
        'import'          => 'modules/import/Views/index.php',
        'import_errors'   => 'modules/import/Views/errors.php',
        'stock'           => 'modules/stock/Views/index.php',
        'checklist'       => 'modules/stock/Views/checklist.php',
        'assets'          => 'modules/assets/Views/index.php',
        'data_health'     => 'modules/system/Views/data_health.php',
        'export'          => 'modules/system/Views/export.php',
        'log'             => 'modules/system/Views/log.php',
        'pending'         => 'modules/system/Views/pending.php',
        'print'           => 'modules/system/Views/print.php',
        'change_password' => 'modules/system/Views/change_password.php',

        'migration'       => 'modules/system/Views/migration.php',
        // 旧个人版首页的**兼容别名**：工作台合并后与 `home` 指向同一页面。
        // 保留该键（而非删掉）是为了让个人版用户的旧书签 / 收藏仍落到工作台，
        // 而不是掉进 FALLBACK（物料列表）。
        'personal'        => 'modules/inventory/Views/dashboard.php',
    ];

    /** 兜底页 */
    private const FALLBACK = 'modules/inventory/Views/index.php';

    /** 页面守卫表：key = ?v= 参数 */
    private const GUARDS = [
        'home'            => ['feature' => 'parts'],
        'parts'           => ['feature' => 'parts'],
        'bom'             => ['feature' => 'bom_basic', 'perm' => 'can_export'],
        'scan'            => ['feature' => 'scan',      'perm' => 'can_scan'],
        'categories'      => ['feature' => 'categories'],
        'locations'       => ['feature' => 'location_manage'],
        'stale'           => ['feature' => 'warning_low_stock'],
        'import'          => ['feature' => 'import',    'perm' => 'can_import'],
        'import_errors'   => ['feature' => 'import',    'perm' => 'can_import'],
        'stock'           => ['feature' => 'manual_stock'],

        'checklist'       => ['feature' => 'checklist', 'perm' => 'can_edit'],
        'assets'          => ['feature' => 'feature_asset_stats'],
        'data_health'     => ['feature' => 'parts'],
        'export'          => ['feature' => 'export_simple'],
        'print'           => ['feature' => 'print_label'],
        'pending'         => ['feature' => 'parts'],
        'change_password' => [],
        'admin'           => [],
        'log'             => [],

        'migration'       => [],

        'personal'        => [],
    ];
    /** 页面守卫：不满足版本/权限条件时 302 回本版本默认页并终止 */
    public static function guard(string $page): void
    {
        if (self::denyReason($page) !== null) self::redirectToDefault();
    }

    /** 求值页面守卫，返回拒绝原因；放行时返回 null */
    public static function denyReason(string $page): ?string
    {
        if ($page === '' || !isset(self::GUARDS[$page])) return null;   // 默认页与兜底页本就无条件开放
        $rule = self::GUARDS[$page];

        if (isset($rule['feature']) && !\hasFeature($rule['feature'])) return '功能未开启：' . $rule['feature'];
        if (isset($rule['perm']) && !\hasPermission($rule['perm'])) return '缺少权限：' . $rule['perm'];

        return null;
    }

    /** 302 回默认页（终止当前请求） */
    private static function redirectToDefault(): void
    {
        header('Location: ' . \siteUrl('index.php'));
        exit;
    }

    /** 分发页面请求 */
    public static function dispatch(string $page): string
    {
        // 无参数：两版默认页已统一为工作台
        if ($page === '') return self::PAGES['home'];

        // 已注册页面
        if (isset(self::PAGES[$page])) {
            return self::PAGES[$page];
        }

        // 兜底
        return self::FALLBACK;
    }

    /** 获取全部路由表（供 healthcheck 等工具使用） */
    public static function getRoutes(): array
    {
        return self::PAGES;
    }

    /** 获取页面守卫表（供 healthcheck 巡检「注册了路由但漏登记守卫」的页面） */
    public static function getGuards(): array
    {
        return self::GUARDS;
    }

    /** 检查页面是否已注册 */
    public static function exists(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }
}
