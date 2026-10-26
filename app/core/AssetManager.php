<?php
declare(strict_types=1);

namespace App\Core;

/** 页面资源管理器 */
final class AssetManager
{

    private static array $css = [];

    private static array $js = [];

    /** 添加 CSS 依赖 */
    public static function addCss(string $path): void
    {
        self::$css[$path] = true;
    }

    /** 添加 JS 依赖 */
    public static function addJs(string $path): void
    {
        self::$js[$path] = true;
    }

    /** 批量添加 CSS 依赖 */
    public static function addCssList(array $paths): void
    {
        foreach ($paths as $path) {
            self::$css[$path] = true;
        }
    }

    /** 批量添加 JS 依赖 */
    public static function addJsList(array $paths): void
    {
        foreach ($paths as $path) {
            self::$js[$path] = true;
        }
    }

    /** 输出所有 CSS link 标签 */
    public static function renderCss(): void
    {
        foreach (array_keys(self::$css) as $path) {
            echo '<link rel="stylesheet" href="' . h($path) . '?v=' . h(self::versionFor($path)) . '">' . "\n";
        }
    }

    /** 输出所有 JS script 标签 */
    public static function renderJs(): void
    {
        foreach (array_keys(self::$js) as $path) {
            echo '<script src="' . h($path) . '?v=' . h(self::versionFor($path)) . '"></script>' . "\n";
        }
    }

    /** 获取所有已声明的 CSS 列表 */
    public static function getCssList(): array
    {
        return array_keys(self::$css);
    }

    /** 获取所有已声明的 JS 列表 */
    public static function getJsList(): array
    {
        return array_keys(self::$js);
    }

    /** 清空已声明的资源（每次请求开始时调用） */
    public static function reset(): void
    {
        self::$css = [];
        self::$js  = [];
    }

    /** 生成资源版本戳：APP_VERSION + 文件修改时间 */
    private static function versionFor(string $path): string
    {
        $base = defined('APP_VERSION') ? APP_VERSION : '1.2.0';
        $root = dirname(__DIR__, 2);
        $mt = @filemtime($root . '/' . ltrim($path, '/'));
        return $mt ? $base . '.' . $mt : $base;
    }
}
