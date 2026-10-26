<?php
declare(strict_types=1);

namespace App\Core\Update;

/** 在线升级的**维护锁**（根目录 `.updating`） */
final class UpdateLock
{
    /** 锁文件名（根目录，`.` 开头 → `.htaccess` 的点文件规则同时挡住 web 访问） */
    public const FILE = '.updating';

    /** 判定为「残留」的秒数（升级正常应在分钟级完成） */
    public const STALE_SECONDS = 600;

    public static function path(string $rootDir): string
    {
        return rtrim(str_replace('\\', '/', $rootDir), '/') . '/' . self::FILE;
    }

    /** 抢锁；已被占用返回 false */
    public static function acquire(string $rootDir, string $info = ''): bool
    {
        if (self::isStale($rootDir)) self::release($rootDir);   // 先清残留

        $fh = @fopen(self::path($rootDir), 'x');
        if ($fh === false) return false;
        @fwrite($fh, date('c') . ' ' . $info . "\n");
        @fclose($fh);
        return true;
    }

    public static function release(string $rootDir): void
    {
        @unlink(self::path($rootDir));
    }

    /** 是否处于「有效锁」状态（残留自动清除并返回 false） */
    public static function active(string $rootDir): bool
    {
        if (!is_file(self::path($rootDir))) return false;
        if (self::isStale($rootDir)) {
            self::release($rootDir);
            return false;
        }
        return true;
    }

    /** 锁文件是否存在且已超时（残留） */
    public static function isStale(string $rootDir): bool
    {
        $m = @filemtime(self::path($rootDir));
        return $m !== false && (time() - $m) > self::STALE_SECONDS;
    }
}
