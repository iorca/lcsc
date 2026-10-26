<?php
declare(strict_types=1);

namespace App\Core\Update;

/** 发布排除清单 —— **唯一来源** */
final class ReleaseExclusions
{
    /** 完整清单（结构与原 `hcReleaseExclusions()` 完全一致：paths / patterns / keep） */
    public static function all(): array
    {
        return [
            // 整个目录不进包
            'paths' => [
                'dev',
                'docs',
                '.trae',              // AI 助手规则

                'LSCS',

                'updates',

                'uploads',
            ],
            // 文件模式（相对项目根，glob 语义）
            'patterns' => [
                '*.md',
                'config.db.php',     // 数据库凭据（安装时由 install.php 重新生成）
                '.installed',        // 安装锁（应由目标站 install.php 生成）
                '.secret_key',       // 外部服务 API Key 的 AES 主密钥（发出去等于所有 Key 失守）
                'healthcheck.php',
                'cookies_verify.txt',// 调试残留
                '.updating',         // 在线升级维护锁（P2）：运行时产物，进包毫无意义
                '.backup_parts_*.sql',
                '*.zip',             // 提示项：根目录打包产物可能含整站源码
                'backups/*.sql',
                'backups/*.xls',
                'backups/*.xlsx',
                'backups/*.csv',
                'backups/.backup.lock',
            ],

            'keep' => [
                'third_party',
                'uploads/.htaccess', 'app/.htaccess', 'ui/.htaccess',

                'updates/.htaccess',
                'deploy/nginx.conf', 'deploy/.htaccess',
            ],
        ];
    }

    /** 判断**相对项目根**的路径是否应被排除（供升级时过滤包内条目） */
    public static function isExcluded(string $relPath): bool
    {
        $rel = self::normalize($relPath);
        if ($rel === '') return true;

        $list = self::all();

        // keep 优先：命中 keep 目录（自身或其后代）或精确文件 → 放行
        foreach ($list['keep'] as $keep) {
            $keep = self::normalize($keep);
            if ($keep === '') continue;
            if ($rel === $keep || str_starts_with($rel, $keep . '/')) return false;
        }

        // paths：整个目录（自身或其后代）
        foreach ($list['paths'] as $p) {
            $p = self::normalize($p);
            if ($p === '') continue;
            if ($rel === $p || str_starts_with($rel, $p . '/')) return true;
        }

        foreach ($list['patterns'] as $pat) {
            $pat = self::normalize($pat);
            if ($pat === '') continue;
            if (str_contains($pat, '/')) {

                if (fnmatch($pat, $rel, FNM_CASEFOLD)) return true;
                continue;
            }

            $base = basename($rel);
            if (fnmatch($pat, $base, FNM_CASEFOLD)) return true;
        }

        return false;
    }

    /** 归一化：统一分隔符、去掉前导 `./` 与多余斜杠 */
    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return ltrim($path, '/');
    }
}
