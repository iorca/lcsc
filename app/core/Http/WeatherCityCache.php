<?php
declare(strict_types=1);

namespace App\Core\Http;

use Throwable;

/** 天气缓存的「地点级分键」 —— 同地所有人共用一份缓存 */
final class WeatherCityCache
{
    /** IP → 地名 的映射表在 settings 里的键名 */
    private const GEO_MAP_KEY = 'weather_ip_city_map_v4';

    /** IP → 地名映射的保存上限（防止 settings 行无限膨胀） */
    private const GEO_MAP_LIMIT = 400;

    /** 解析本次请求该用哪个缓存分键 */
    public static function keyFor(string $clientIp): string
    {
        $ip = trim($clientIp);
        if ($ip === '') {
            return '{no-ip}';       // 内网 / 反代：所有人共用一份，语义上就是「服务器天气」
        }
        $p = self::mappedPlace($ip);

        $place = $p['district'] !== '' ? $p['district'] : $p['city'];
        if ($place !== '') {
            return self::normalizePlace($place);
        }
        return '{ip:' . $ip . '}';
    }

    /** 取数成功后回填「IP → 位置」映射 */
    public static function rememberCity(string $clientIp, string $city, string $district = ''): void
    {
        $ip = trim($clientIp);
        $city = trim($city);
        $district = trim($district);
        if ($ip === '' || $city === '' || $district === '') return;
        self::write($ip, $city, $district);
    }

    /** 已记录的「IP → 位置」映射（命中则零出站，供 WeatherResolver 先查这里） */
    public static function mappedPlace(string $clientIp): array
    {
        $ip = trim($clientIp);
        $blank = ['city' => '', 'district' => ''];
        if ($ip === '') return $blank;
        $v = self::ipCityMap()[$ip] ?? null;
        if (!is_array($v)) return $blank;
        return [
            'city'     => trim((string)($v['city'] ?? '')),
            'district' => trim((string)($v['district'] ?? '')),
        ];
    }

    /** 已记录的「IP → 地名」映射（**只要城市**，给 wttr / open-meteo 用 —— */
    public static function mappedCity(string $clientIp): string
    {
        return self::mappedPlace($clientIp)['city'];
    }

    /** 展示用地名：**县区级优先**，无县区时退回城市 */
    public static function displayPlace(string $clientIp): string
    {
        $p = self::mappedPlace($clientIp);
        return $p['district'] !== '' ? $p['district'] : $p['city'];
    }

    /** 从天气响应里取城市名并回填映射（wttr 这类「响应里自带城市名」的源） */
    public static function rememberFromPayload(string $clientIp, ?array $payload): void
    {
        $city = self::cityFromPayload($payload);
        if ($city !== '') {
            self::rememberCity($clientIp, $city);
        }
    }

    /** 从天气响应里取城市名；各源字段名不同故都收，取不到返回空串（由调用方退到 IP 分键） */
    public static function cityFromPayload(?array $payload): string
    {
        if (!$payload) return '';
        // uapis：省 + 市 + 区县
        $city = (string)($payload['city'] ?? '');
        if ($city !== '') return $city;
        // wttr：nearest_area[0].areaName / region
        $na = $payload['nearest_area'][0] ?? null;
        if (is_array($na)) {
            $t = trim((string)($na['areaName'][0]['value'] ?? '') . ' ' . (string)($na['region'][0]['value'] ?? ''));
            if ($t !== '') return $t;
        }
        return '';
    }

    /** 地名 → 稳定的缓存分键 */
    private static function normalizePlace(string $place): string
    {
        $c = preg_replace('/\s+/u', '', trim($place)) ?? '';
        $c = preg_replace('/(自治州|自治县|地区|盟|县|区|市)$/u', '', $c) ?? $c;

        if ($c === '') {
            $c = preg_replace('/\s+/u', '', trim($place)) ?? '';
        }
        return $c !== '' ? '{place:' . $c . '}' : '{unknown-place}';
    }

    // ── IP → 地名 映射（settings 单行 JSON）──────────────

    /** 读映射表；损坏或读失败一律当空（宁可多一次出站，不要因脏数据整站报错） */
    private static function ipCityMap(): array
    {
        try {
            $raw = (string)\getSetting(self::GEO_MAP_KEY, '');
            if ($raw === '') return [];
            $m = json_decode($raw, true);
            return is_array($m) ? $m : [];
        } catch (Throwable $e) {
            error_log('[WeatherCityCache] 读 IP→地名映射失败：' . $e->getMessage());
            return [];
        }
    }

    /** 写映射表；失败只记日志（不影响本次取数） */
    private static function write(string $ip, string $city, string $district = ''): void
    {
        try {
            $m = self::ipCityMap();
            $old = is_array($m[$ip] ?? null) ? $m[$ip] : [];
            if ((string)($old['city'] ?? '') === $city
                && (string)($old['district'] ?? '') === $district) {
                return;    // 没变就不写，省一次 UPDATE
            }
            $m[$ip] = ['city' => $city, 'district' => $district];
            if (count($m) > self::GEO_MAP_LIMIT) {
                $m = array_slice($m, -self::GEO_MAP_LIMIT, null, true);   // 丢最旧的
            }

            \setSetting(self::GEO_MAP_KEY, json_encode($m, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            error_log('[WeatherCityCache] 写 IP→地名映射失败：' . $e->getMessage());
        }
    }

    /** 供排障：当前映射表条数（不含具体内容，避免把 IP 打进日志） */
    public static function geoMapSize(): int
    {
        return count(self::ipCityMap());
    }

    /** 忘掉某个 IP 已记录的映射（下次 `resolvePlace()` 会重新出站解析） */
    public static function forgetPlace(string $clientIp): void
    {
        $ip = trim($clientIp);
        if ($ip === '') return;
        try {
            $m = self::ipCityMap();
            if (!array_key_exists($ip, $m)) return;
            unset($m[$ip]);
            \setSetting(self::GEO_MAP_KEY, json_encode($m, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            error_log('[WeatherCityCache] 清除 IP 映射失败：' . $e->getMessage());
        }
    }
}
