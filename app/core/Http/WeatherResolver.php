<?php
declare(strict_types=1);

namespace App\Core\Http;

/** 天气查询的访问者定位 —— 决定「这次天气按谁的位置查」 */
final class WeatherResolver
{
    /** 本进程内已解析过的 IP → 地名（防同一请求重复出站） */
    private static array $resolvedPlaces = [];

    /** 判断一个 IP 是否可用于公网地理定位 */
    public static function isPublicIp(string $ip): bool
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // 交给 PHP 的定义型过滤：它按 IP 版本分别处理 v4 / v6 的保留段，

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (str_contains($ip, '.')) {
            $long = ip2long($ip);
            if ($long !== false) {
                $cgNatLow  = ip2long('100.64.0.0');
                $cgNatHigh = ip2long('100.127.255.255');
                if ($long !== false && $cgNatLow !== false && $cgNatHigh !== false
                    && $long >= $cgNatLow && $long <= $cgNatHigh) {
                    return false;
                }
            }
        }
        return true;
    }

    /** 本次请求实际使用的站点主机（去掉端口，兼容 IPv6 方括号写法） */
    public static function requestHost(): string
    {
        $raw = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($raw === '') $raw = (string)($_SERVER['SERVER_NAME'] ?? '');
        if ($raw === '') $raw = (string)($_SERVER['SERVER_ADDR'] ?? '');
        $host = trim($raw);
        if ($host === '') {
            return '';
        }
        // `[::1]:8080` → `::1`
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m) === 1) {
            return trim($m[1], '[]');
        }

        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return trim($host);
    }

    /** 是否**内网环境访问**（本机 / 局域网地址） */
    public static function isIntranetHost(): bool
    {
        $host = strtolower(self::requestHost());
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || $host === 'localhost.localdomain') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;   // 普通域名（含内网 DNS 名）：无法判定，沿用原逻辑
        }
        return !self::isPublicIp($host);
    }

    /** 解析出本次天气查询该用的 IP */
    public static function resolveClientIp(): string
    {

        if (self::isIntranetHost()) {
            return '';
        }
        if (\function_exists('getClientIP')) {
            $ip = (string)\getClientIP();
            if (self::isPublicIp($ip)) {
                return $ip;
            }
        }
        return self::ipFromProxyHeaders();
    }

    /** 从代理头里取**第一个可公网定位**的 IP */
    private static function ipFromProxyHeaders(): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_CLIENT_IP',
        ];
        $cands = [];
        foreach ($headers as $h) {
            $raw = (string)($_SERVER[$h] ?? '');
            if ($raw === '') {
                continue;
            }
            // 逗号分隔的链（XFF 可能有多跳）
            foreach (explode(',', $raw) as $one) {
                $one = trim($one);
                // 去掉 IPv6 的方括号端口写法 `[::1]:80`
                $one = (string)preg_replace('/^\[(.+)\](?::\d+)?$/', '$1', $one);
                if ($one !== '') {
                    $cands[] = $one;
                }
            }
        }
        foreach ($cands as $c) {
            if (self::isPublicIp($c)) {
                return $c;
            }
        }
        return '';
    }

    /** 生成某个候选源的运行时占位符取值 */
    public static function runtimeValues(array $tpl): array
    {
        $out = [];
        $needIp = false;
        $needCity = false;
        $needPlace = false;
        foreach (array_keys($tpl) as $ph) {
            if ($ph === '{client_ip}') $needIp   = true;
            if ($ph === '{city}')      $needCity = true;
            if ($ph === '{place}')     $needPlace = true;
        }

        $ip = $needIp || $needCity || $needPlace ? self::resolveClientIp() : '';
        if ($ip !== '') {
            if ($needIp) {
                $out['{client_ip}'] = $ip;
            }

            if ($needCity || $needPlace) {
                $p = self::resolvePlace($ip);
                if ($needCity && $p['city'] !== '') {
                    $out['{city}'] = $p['city'];
                }
                if ($needPlace) {
                    // 县区优先（更精确）；本次来源给不出县区时退回城市。

                    $place = $p['district'] !== '' ? $p['district'] : $p['city'];
                    if ($place !== '') {
                        $out['{place}'] = $place;
                    }
                }
            }
        }
        return $out;
    }

    /** 访客 IP → 城市名（给只需城市的调用方用） */
    public static function resolveCity(string $ip): string
    {

        $mapped = WeatherCityCache::mappedCity($ip);
        if ($mapped !== '') {
            return $mapped;
        }
        return self::resolvePlace($ip)['city'];
    }

    /** 访客 IP → 地名（**城市 + 县区**） */
    public static function resolvePlace(string $ip): array
    {
        $blank = ['city' => '', 'district' => ''];
        $ip = trim($ip);
        if ($ip === '') {
            return $blank;
        }

        $cached = WeatherCityCache::mappedPlace($ip);
        if ($cached['city'] !== '') {
            return $cached;
        }

        if (array_key_exists($ip, self::$resolvedPlaces)) {
            return self::$resolvedPlaces[$ip];
        }

        $place = $blank;
        try {
            $db = \getDB();

            $r = \App\Core\Http\ProviderDispatcher::fetch(
                $db,
                'geo',
                '{ip:' . $ip . '}'
            );
            if (!empty($r['available']) && is_array($r['payload'])) {

                $provider = (string)($r['provider'] ?? '');
                $place = self::placeFromGeoPayload($r['payload'], $provider);
                if ($place['city'] !== '') {
                    WeatherCityCache::rememberCity($ip, $place['city'], $place['district']);
                } elseif (self::isTransientGeoFailure($provider, $r['payload'])) {
                    // 限频可恢复，但这份响应刚被缓存 —— 不清掉的话接下来一整天

                    self::purgeGeoCache($ip);
                }
            }
        } catch (\Throwable $e) {
            error_log('[WeatherResolver] IP→城市解析失败：' . $e->getMessage());
        }

        self::$resolvedPlaces[$ip] = $place;
        return $place;
    }

    /** 清掉某个 IP 的 geo 缓存行（**下次访问会重新出站解析**） */
    public static function purgeGeoCache(string $ip): int
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 0;
        }

        unset(self::$resolvedPlaces[$ip]);
        try {
            $stmt = \getDB()->prepare('DELETE FROM external_cache WHERE cache_key = ?');
            $stmt->execute(['geo:' . str_replace(':', '_', '{ip:' . $ip . '}')]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[WeatherResolver] 清理 geo 缓存失败：' . $e->getMessage());
            return 0;
        }
    }

    /** 这次「取不到地名」是不是**可恢复的临时失败**（限频），而非接口的真实回答 */
    private static function isTransientGeoFailure(string $provider, array $payload): bool
    {
        // 只有 dwo 有「HTTP 200 里带业务错误码」这种响应结构
        return $provider === 'dwo' && (int)($payload['code'] ?? 0) === 219;
    }

    /** 从 `geo` 服务的响应里取地名（**按 provider 分派**） */
    private static function placeFromGeoPayload(array $payload, string $provider = ''): array
    {
        return match ($provider) {
            'dwo'   => self::placeFromDwoPayload($payload),
            'baidu' => ['city' => self::cityFromBaiduPayload($payload), 'district' => ''],
            default => ['city' => self::cityFromIpwhoisPayload($payload), 'district' => ''],
        };
    }

    /** dwo.cc：**县区级精度**，境内判据用 `iso_code` */
    private static function placeFromDwoPayload(array $p): array
    {
        $blank = ['city' => '', 'district' => ''];
        if ((int)($p['code'] ?? 0) !== 200) {
            return $blank;
        }
        $loc = $p['data']['location'] ?? null;
        if (!is_array($loc)) {
            return $blank;
        }

        if ((string)($loc['iso_code'] ?? '') !== 'CN') {
            return $blank;
        }

        return [
            'city'     => trim((string)($loc['city'] ?? '')),
            'district' => trim((string)($loc['district'] ?? '')),
        ];
    }

    /** 百度开放数据：只到市级，且**没有国家代码字段** */
    private static function cityFromBaiduPayload(array $p): string
    {
        if ((string)($p['status'] ?? '') !== '0') {
            return '';
        }
        $first = $p['data'][0] ?? null;
        if (!is_array($first)) {
            return '';       
        }
        $loc = trim((string)($first['location'] ?? ''));
        if ($loc === '') {
            return '';
        }
        if (!preg_match('/(省|市|自治区|特别行政区)/u', $loc)) {
            return '';       // 境外只给国名 → 交 wttr
        }
        // 切出城市名：`河南省南阳市 联通` → `南阳市`。

        $head = preg_split('/\s+/u', $loc)[0] ?? '';       // 去掉尾部的运营商
        $city = preg_replace('/^.*?(?:省|自治区|特别行政区)/u', '', $head);
        if ($city === null || $city === '') {
            return '';
        }

        return preg_match('/^[\p{Han}]{2,8}市$/u', $city) === 1 ? $city : '';
    }

    /** ipwho.is：旧源，已从候选里移除 */
    private static function cityFromIpwhoisPayload(array $p): string
    {

        if (empty($p['success'])) {
            return '';
        }
        if ((string)($p['country_code'] ?? '') !== 'CN') {
            return '';       // 境外 → 交 wttr
        }

        return trim((string)($p['city'] ?? ''));
    }

    /** 判断某个候选源在本次请求下是否应参与尝试 */
    public static function shouldSkip(array $p, array $runtime): bool
    {
        $blobs = [(string)($p['endpoint'] ?? '')];
        foreach ((array)($p['query'] ?? []) as $v) {
            foreach (is_array($v) ? $v : [$v] as $one) {
                $blobs[] = (string)$one;
            }
        }
        foreach ($blobs as $s) {
            foreach (['{client_ip}', '{city}', '{place}'] as $ph) {
                if (str_contains($s, $ph) && (!isset($runtime[$ph]) || $runtime[$ph] === '')) {
                    return true;
                }
            }
        }
        return false;
    }
}
