<?php
declare(strict_types=1);

namespace App\Core\Update;

/** 在线升级的**包源唯一来源**（product_scope §5.2.4 硬约束第 1 条） */
final class UpdateSource
{
    /** 版本检测允许访问的主机（保留原 `VERSION_DOMAIN_WHITELIST` 的取值与语义） */
    public const VERSION_HOSTS = ['gitee.com', 'raw.githubusercontent.com', 'github.com'];

    /** 仓库与 URL 模板（**代码内常量**） */
    public const REPOS = [
        'gitee' => [
            'version'   => 'https://gitee.com/xiaoxu798/lcsc/raw/master/version.json',
            'changelog' => 'https://gitee.com/xiaoxu798/lcsc/raw/master/CHANGELOG.md',
            'release'   => 'https://gitee.com/xiaoxu798/lcsc/releases',
            'asset'     => 'https://gitee.com/xiaoxu798/lcsc/releases/download/v{ver}/{file}',
        ],
        'github' => [
            'version'   => 'https://raw.githubusercontent.com/xiaoxu798/lcsc/main/version.json',
            'changelog' => 'https://raw.githubusercontent.com/xiaoxu798/lcsc/main/CHANGELOG.md',
            'release'   => 'https://github.com/xiaoxu798/lcsc/releases',
            'asset'     => 'https://github.com/xiaoxu798/lcsc/releases/download/v{ver}/{file}',
        ],
    ];

    /** 附件下载**允许落地**的主机白名单（含 302 目标） */
    public const ASSET_HOSTS = [
        'gitee.com',
        'github.com',
        'raw.githubusercontent.com',
        'objects.githubusercontent.com',
    ];

    /** 包下载硬上限（字节）：发布包不该超过此值，超了当场中止（防意外拉爆磁盘/内存） */
    public const MAX_PACKAGE_BYTES = 200 * 1024 * 1024;   // 200 MB

    /** 允许的最大重定向跳数 */
    public const MAX_REDIRECTS = 3;

    public static function repos(): array
    {

        $local = self::localSource();
        if ($local !== null) return ['local' => $local];
        return self::REPOS;
    }

    /** 开发期本地测试源（未启用返回 null） */
    public static function localSource(): ?array
    {
        if (!defined('LCSC_UPDATE_LOCAL_SOURCE')) return null;
        $src = constant('LCSC_UPDATE_LOCAL_SOURCE');
        return is_array($src) && !empty($src['version']) ? $src : null;
    }

    public static function isLocalDev(): bool
    {
        return self::localSource() !== null;
    }

    /** 版本检测允许访问的主机（正式白名单 + 本地测试源主机） */
    public static function versionHosts(): array
    {
        return self::isLocalDev()
            ? array_values(array_unique(array_merge(self::VERSION_HOSTS, self::localHosts())))
            : self::VERSION_HOSTS;
    }

    /** 由仓库 key + 版本号 + 包文件名拼出附件 URL */
    public static function assetUrl(string $repoKey, string $version, string $file): string
    {
        $repo = self::repos()[$repoKey] ?? [];
        $tpl = (string)($repo['asset'] ?? '');
        if ($tpl === '' || $version === '' || $file === '') {
            throw new UpdateException('无法生成下载地址：缺少仓库 / 版本 / 包文件名');
        }
        return str_replace(['{ver}', '{file}'], [$version, $file], $tpl);
    }

    public static function allowedHosts(): array
    {
        return self::isLocalDev()
            ? array_values(array_unique(array_merge(self::ASSET_HOSTS, self::localHosts())))
            : self::ASSET_HOSTS;
    }

    /** 本地测试源 URL 里出现过的主机（version / changelog / asset） */
    private static function localHosts(): array
    {
        $hosts = [];
        foreach (self::repos() as $repo) {
            foreach (['version', 'changelog', 'asset'] as $key) {
                $host = parse_url((string)($repo[$key] ?? ''), PHP_URL_HOST);
                if ($host !== null && $host !== '') $hosts[] = strtolower($host);
            }
        }
        return array_values(array_unique($hosts));
    }
}
