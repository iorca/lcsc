<?php
declare(strict_types=1);

namespace App\Core\Http;

use Throwable;

/** 外部服务候选源注册表（阶段 2 底座 + 阶段 3 服务配置读取） */
final class ProviderRegistry
{
    /** 用户配置文件名（与本类同目录） */
    private const CFG_FILE = 'providers.php';

    /** 候选源字段路径分隔符 */
    private const PATH_SEP = '.';

    /** 读配置文件 */
private static function config(): array
{
    $file = __DIR__ . '/' . self::CFG_FILE;
    if (!is_file($file)) {
        error_log('[ProviderRegistry] 配置文件不存在，全部外部服务将使用内置兜底文案：' . $file);
        return [];
    }
    try {

        $cfg = require $file;
    } catch (Throwable $e) {
        // 用户改坏了文件 —— 只记日志、走空配置（显示内置文案），不抛给上层
        error_log('[ProviderRegistry] 配置文件解析失败（' . $file . '），'
            . '外部服务将使用内置兜底文案：' . $e->getMessage());
        return [];
    }
    return is_array($cfg) ? $cfg : [];
}

    /** 某服务的完整配置（fallback 文案、ttl、runtime 模板、候选源列表） */
    public static function serviceConfig(string $service): array
    {
        $raw = self::config()[$service] ?? [];
        $ps  = is_array($raw['providers'] ?? null) ? array_values($raw['providers']) : [];

        return [

            'fallback'      => (string)($raw['fallback'] ?? '外部服务暂不可用'),
            'fallback_tpl'  => (string)($raw['fallback_tpl'] ?? ''),
            'ttl'           => max(60, (int)($raw['ttl'] ?? 1800)),

            'runtime'       => is_array($raw['runtime'] ?? null) ? $raw['runtime'] : [],
            'providers'     => self::normalize($ps),
        ];
    }

    /** 取某服务的候选源列表（已剔除禁用项与不合规项） */
    public static function providers(string $service): array
    {
        return self::serviceConfig($service)['providers'];
    }

    /** 规范化候选源：逐项校验，剔除不合规的 */
    private static function normalize(array $list): array
    {
        $out = [];
        foreach ($list as $p) {
            if (!is_array($p)) continue;

            $name = trim((string)($p['name'] ?? ''));
            $base = rtrim(trim((string)($p['base'] ?? '')), '/');
            if ($name === '' || $base === '') {
                error_log("[ProviderRegistry] 候选源缺name 或 base，已跳过：" . json_encode($p, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if (empty($p['enabled'])) continue;   // 保留配置但不走

            $v = self::validateProviders([$p]);
            if ($v['rejected'] !== []) {
                error_log("[ProviderRegistry] 候选源 {$name} 配置不合法，已跳过：" . $v['rejected'][0]['reason']);
                continue;
            }
            $out[] = $v['ok'][0];
        }
        return $out;
    }

    /** 某服务是否有可用候选源（无源时调用方应直接走兜底，不发请求） */
    public static function hasProviders(string $service): bool
    {
        return self::providers($service) !== [];
    }

    /** 校验候选源配置，返回规范化后的合法项 + 被拒项及原因 */
    public static function validateProviders(array $providers): array
    {
        $ok = []; $rejected = [];
        foreach ($providers as $p) {
            if (!is_array($p)) { $rejected[] = ['name' => '(非数组)', 'reason' => '格式错误']; continue; }
            $name = trim((string)($p['name'] ?? ''));
            $base = rtrim(trim((string)($p['base'] ?? '')), '/');

            if ($name === '') { $rejected[] = ['name' => '(空)', 'reason' => '缺 name']; continue; }
            if ($base === '') { $rejected[] = ['name' => $name, 'reason' => '缺 base 地址']; continue; }
            if (!preg_match('~^https://~i', $base)) {
                $rejected[] = ['name' => $name, 'reason' => '必须 https（出站只允许 https）'];
                continue;
            }
            if (HttpClient::isBlockedUrl($base)) {
                $rejected[] = ['name' => $name, 'reason' => '指向内网地址，出站安全策略禁止'];
                continue;
            }
            $type = (string)($p['type'] ?? 'string');
            if (!in_array($type, ['string', 'number', 'array', 'object'], true)) {
                $rejected[] = ['name' => $name, 'reason' => "未知 type：{$type}（可选 string/number/array/object）"];
                continue;
            }
            $q = $p['query'] ?? [];
            if (!is_array($q)) {
                $rejected[] = ['name' => $name, 'reason' => 'query 必须是数组'];
                continue;
            }
            $ok[] = [
                'name'     => $name,
                'base'     => $base,
                'endpoint' => trim((string)($p['endpoint'] ?? '')),
                'fields'   => trim((string)($p['fields'] ?? '')),
                'type'     => $type,
                'query'    => $q,

                'headers'  => self::normalizeHeaders($p['headers'] ?? null),
                'enabled'  => !empty($p['enabled']),
            ];
        }
        return ['ok' => $ok, 'rejected' => $rejected];
    }

    /** 规范化自定义请求头 */
    private static function normalizeHeaders($h): array
    {
        if (!is_array($h)) {
            return [];
        }
        $out = [];
        foreach ($h as $k => $v) {
            $name = trim((string)$k);
            // 只放行合法 header 名，防注入（换行 / 冒号）
            if ($name === '' || !preg_match('~^[A-Za-z0-9\-_]+$~', $name)) {
                continue;
            }
            $val = is_array($v) ? (string)($v[0] ?? '') : (string)$v;
            // header 值里不允许换行（HTTP 头注入）
            $out[$name] = trim(str_replace(["\r", "\n"], '', $val));
        }
        return $out;
    }

    /** 拼出候选源的完整 URL（base + endpoint + query 参数） */
public static function buildUrl(array $p, array $runtime = []): string
{

    $base = (string)($p['base'] ?? '');
    if ($base === '') {
        return '';
    }
    $url = $base . '/' . ltrim((string)($p['endpoint'] ?? ''), '/');
    // **先替换 endpoint 里的占位符**（如 wttr 的 `/{client_ip}?format=j1` 把 IP 放路径上）
    if ($runtime !== []) {
        $url = strtr($url, $runtime);
    }

    $q = (array)($p['query'] ?? []);
    if ($runtime !== []) {
        $q = self::substitute($q, $runtime);
    }
    $q = self::buildQuery($q);
    if ($q !== '') {
        $url .= '?' . $q;
    }
    return $url;
}

/** 递归替换 query 数组里的占位符（**在 urlencode 之前做**） */
private static function substitute(array $q, array $runtime): array
{
    foreach ($q as $k => $v) {
        $q[$k] = is_array($v)
            ? array_map(static fn($x) => strtr((string)$x, $runtime), $v)
            : strtr((string)$v, $runtime);
    }
    return $q;
}

/** 拼 query string，支持重复键 */
private static function buildQuery(array $q): string
{
    if ($q === []) return '';

    $parts = [];
    foreach ($q as $name => $value) {
        // 数字键只是 PHP 数组的位置，没有「参数名」含义 —— 跳过，

        if (is_int($name)) continue;

        // 数组值 → 展开为同名重复键（这正是表达重复参数的方式）
        if (is_array($value)) {
            foreach ($value as $v) {
                $parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$v);
            }
            continue;
        }
        $parts[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
    }
    return implode('&', $parts);
}

    /** 按字段路径从响应数组取值 */
    public static function pick(array $data, string $path): mixed
    {
        if ($path === '') return $data;

        $cur = $data;
        foreach (explode(self::PATH_SEP, $path) as $seg) {
            if (!is_array($cur)) return null;
            // 数组下标
            if (is_int($cur) || ctype_digit($seg)) {
                $idx = (int)$seg;
                if (!array_key_exists($idx, $cur)) return null;
                $cur = $cur[$idx];
                continue;
            }
            if (!array_key_exists($seg, $cur)) return null;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /** 校验取到的值是否符合该候选源声明的 type */
    public static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value) && $value !== '',
            'number' => is_numeric($value),
            'array'  => is_array($value) && $value !== [],
            'object' => is_array($value),
            default  => $value !== null && $value !== '',
        };
    }

    /** 填充兜底文案里的占位符 */
    public static function fillFallback(string $tpl, string $plain): string
    {
        $text = $tpl !== '' ? $tpl : $plain;
        if ($text === '') return '';

        return str_replace('{today}', date('n月j日'), $text);
    }
}
