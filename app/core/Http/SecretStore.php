<?php
declare(strict_types=1);

namespace App\Core\Http;

use Throwable;

/** 敏感凭据的加密存储 —— 让用户自填的密码 / API Key 不再明文躺在数据库里 */
final class SecretStore
{
    /** 密钥文件名（放项目根目录，与 index.php 同级） */
    private const KEY_FILE = '.secret_key';

    /** settings 表里存密文的键前缀 */
    private const SETTING_PREFIX = 'ext_secret_';

    /** 槽位名索引的键（只存槽位名，不存 Key） */
    private const SETTING_INDEX_KEY = 'ext_secret_slots';

    /** 进程内缓存的本机密钥（同一请求内只读一次文件） */
    private static ?string $cachedKey = null;

    /** 读本机密钥；不存在则生成 */
    private static function key(): string
    {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }
        $path = self::path();
        try {
            if (is_file($path)) {
                $raw = trim((string)@file_get_contents($path));
                if ($raw !== '') {
                    // 十六进制 → 二进制；长度必须够 32 字节
                    $bin = @hex2bin($raw);
                    if ($bin !== false && strlen($bin) === 32) {
                        return self::$cachedKey = $bin;
                    }
                }
            }
            // 首次使用：生成并落盘
            $bin = random_bytes(32);
            $tmp = $path . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, bin2hex($bin)) !== false) {
                @chmod($tmp, 0600);                 // 仅属主可读写
                if (@rename($tmp, $path)) {
                    @chmod($path, 0600);
                } else {
                    @unlink($tmp);
                }
            }

            $raw = trim((string)@file_get_contents($path));
            $bin = @hex2bin($raw);
            if ($bin !== false && strlen($bin) === 32) {
                return self::$cachedKey = $bin;
            }

            return self::$cachedKey = random_bytes(32);
        } catch (Throwable $e) {
            error_log('[SecretStore] 密钥文件读写失败：' . $e->getMessage());
            return '';
        }
    }

    /** 密钥文件绝对路径 */
    private static function path(): string
    {
        return dirname(__DIR__, 3) . '/' . self::KEY_FILE;
    }

    /** 存一个密钥（加密后落库） */
    public static function put(string $slot, string $plain): bool
    {
        $slot = self::normSlot($slot);
        $key  = self::key();
        if ($slot === '' || $key === '') {
            return false;
        }
        try {
            $iv  = random_bytes(12);                       // GCM 推荐的 96 位 nonce
            $tag = '';
            $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($ct === false) {
                error_log('[SecretStore] 加密失败：' . openssl_error_string());
                return false;
            }
            // nonce 与 tag 必须一起存 —— 少了任一个都解不开
            \setSetting(self::SETTING_PREFIX . $slot, base64_encode($iv . $tag . $ct));
            self::indexAdd($slot);
            return true;
        } catch (Throwable $e) {
            error_log('[SecretStore] 存密钥失败：' . $e->getMessage());
            return false;
        }
    }

    /** 取一个密钥（解密） */
    public static function get(string $slot): string
    {
        $slot = self::normSlot($slot);
        $key  = self::key();
        if ($slot === '' || $key === '') {
            return '';
        }
        try {
            $raw = \getSetting(self::SETTING_PREFIX . $slot, '');
            if ($raw === '') {
                return '';
            }
            $blob = base64_decode($raw, true);
            if ($blob === false || strlen($blob) < 29) {   // 12(iv)+16(tag)+至少1
                error_log('[SecretStore] 密文格式异常（槽位 ' . $slot . '）');
                return '';
            }
            $iv  = substr($blob, 0, 12);
            $tag = substr($blob, 12, 16);
            $ct  = substr($blob, 28);
            $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($out === false) {

                error_log('[SecretStore] 解密失败（槽位 ' . $slot . '）—— 若曾更换 .secret_key，需重新填写该 Key');
                return '';
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[SecretStore] 取密钥失败：' . $e->getMessage());
            return '';
        }
    }

    /** 是否已设置（不暴露明文，供设置页显示「已配置」） */
    public static function has(string $slot): bool
    {
        return self::get($slot) !== '';
    }

    /** 删除一个槽位（连密文与索引一起清） */
    public static function delete(string $slot): bool
    {
        $slot = self::normSlot($slot);
        if ($slot === '') {
            return false;
        }
        try {
            $db = \getDB();
            $st = $db->prepare('DELETE FROM settings WHERE k=?');
            $st->execute([self::SETTING_PREFIX . $slot]);
            \clearSettingCache(self::SETTING_PREFIX . $slot);
            self::indexRemove($slot);
            return true;
        } catch (Throwable $e) {
            error_log('[SecretStore] 删除密钥失败：' . $e->getMessage());
            return false;
        }
    }

    /** 掩码形式（供界面显示）：`sk-abcdefghijklmnop` → `••••mnop` */
    public static function masked(string $slot): string
    {
        $v = self::get($slot);
        if ($v === '') {
            return '';
        }
        $n = strlen($v);
        if ($n <= 4) {
            return str_repeat('•', max(4, $n));
        }
        return str_repeat('•', min(12, $n - 4)) . substr($v, -4);
    }

    /** 解析配置里写的 `{secret:槽位}` 占位符 */
    public static function resolvePlaceholders(array $tpl): array
    {
        $out = [];
        foreach ($tpl as $k => $v) {
            $out[$k] = preg_replace_callback(
                '~\{secret:([a-zA-Z0-9_\-]+)\}~',
                static function (array $m): string {
                    $s = self::get($m[1]);
                    return $s === '' ? '' : $s;
                },
                (string)$v
            ) ?? '';
        }
        return $out;
    }

    /** 槽位名白名单化：只留字母数字下划线连字符，杜绝把任意字符串拼进 settings 键名 */
    private static function normSlot(string $slot): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($slot));
    }

    /** 登记槽位名到索引（供 `dev/secret_list.php` 列举） */
    private static function indexAdd(string $slot): void
    {
        try {
            $raw = \getSetting(self::SETTING_INDEX_KEY, '');
            $arr = $raw === '' ? [] : (json_decode($raw, true) ?: []);
            if (!is_array($arr)) $arr = [];
            if (!in_array($slot, $arr, true)) {
                $arr[] = $slot;
                \setSetting(self::SETTING_INDEX_KEY, json_encode($arr, JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable $e) {
            error_log('[SecretStore] 写槽位索引失败：' . $e->getMessage());
        }
    }

    /** 已登记的槽位列表 */
    public static function slots(): array
    {
        try {
            $raw = \getSetting(self::SETTING_INDEX_KEY, '');
            $arr = $raw === '' ? [] : (json_decode($raw, true) ?: []);
            return is_array($arr) ? array_values(array_filter($arr, 'is_string')) : [];
        } catch (Throwable $e) {
            error_log('[SecretStore] 读槽位索引失败：' . $e->getMessage());
            return [];
        }
    }

    /** 从索引里移除一个槽位名（`delete()` 配套） */
    private static function indexRemove(string $slot): void
    {
        try {
            $raw = \getSetting(self::SETTING_INDEX_KEY, '');
            $arr = $raw === '' ? [] : (json_decode($raw, true) ?: []);
            if (!is_array($arr)) return;
            $arr = array_values(array_filter($arr, static fn($s) => $s !== $slot));
            if ($arr) {
                \setSetting(self::SETTING_INDEX_KEY, json_encode($arr, JSON_UNESCAPED_UNICODE));
            } else {
                $db = \getDB();
                $st = $db->prepare('DELETE FROM settings WHERE k=?');
                $st->execute([self::SETTING_INDEX_KEY]);
                \clearSettingCache(self::SETTING_INDEX_KEY);
            }
        } catch (Throwable $e) {
            error_log('[SecretStore] 写槽位索引失败：' . $e->getMessage());
        }
    }
}
