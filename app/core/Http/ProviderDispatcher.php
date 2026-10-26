<?php
declare(strict_types=1);

namespace App\Core\Http;

use PDO;

/** 多源冗余调度器 */
final class ProviderDispatcher
{
    /** 取某服务的数据：多源顺序试 + 缓存降级 + 可用性判定 */
    public static function fetch(PDO $db, string $service, string $cacheSuffix = ''): array
    {
        $cfg = ProviderRegistry::serviceConfig($service);
        $key = ExternalCache::key($service, $cacheSuffix);

        $providers = $cfg['providers'];

        $runtime = WeatherResolver::runtimeValues((array)($cfg['runtime'] ?? []));
        $before  = count($providers);
        $providers = array_values(array_filter(
            $providers,
            static fn(array $p): bool => !WeatherResolver::shouldSkip($p, $runtime)
        ));
        $skipped = $before - count($providers);

        if ($providers === []) {

            $r = ExternalCache::remember($db, $key, $cfg['ttl'], static fn(): ?array => null);
            $why = $skipped > 0
                ? '所有源都依赖访问者 IP，但本次拿不到可公网定位的地址（内网 / 反代访问）'
                : '未配置候选源';
            return self::assemble($r, 'no_provider', '', [], $cfg, $why);
        }

        $tried = [];
        $hit   = '';

        $r = ExternalCache::remember($db, $key, $cfg['ttl'], static function () use ($providers, $runtime, &$tried, &$hit): ?array {
            foreach ($providers as $p) {
                $url = ProviderRegistry::buildUrl($p, $runtime);
                if ($url === '') {
                    // 配置不完整（如缺 base）→ 这个源不可能用，跳过而不是拿空串去请求
                    $tried[$p['name']] = '配置不完整（缺 base 地址），已跳过';
                    continue;
                }
                $raw = HttpClient::getJson($url, self::headersOf($p));

                if ($raw === null) {
                    $tried[$p['name']] = '请求失败（网络不通 / 被出站安全策略阻断 / 响应非 JSON）';
                    continue;
                }
                $val = ProviderRegistry::pick($raw, $p['fields']);
                if ($val === null) {
                    $tried[$p['name']] = "取不到值（fields={$p['fields']} 与返回结构不匹配）";
                    continue;
                }

                if (!ProviderRegistry::matchesType($val, $p['type'])) {
                    $tried[$p['name']] = "类型不符（声明 {$p['type']}，实际 " . gettype($val) . '）';
                    continue;
                }
                $tried[$p['name']] = 'ok';
                $hit = $p['name'];
                // 成功即返回，外层 remember 会把它写进缓存
                return ['value' => $val, 'provider' => $p['name']];
            }
            return null;   // 全部失败 → remember 走缓存降级
        });

        return self::assemble($r, $r['source'], $hit, $tried, $cfg);
    }

    /** 取候选源的自定义请求头，并把 `{secret:槽位}` 换成真实 Key */
    private static function headersOf(array $p): array
    {
        $h = is_array($p['headers'] ?? null) ? $p['headers'] : [];
        return $h === [] ? [] : SecretStore::resolvePlaceholders($h);
    }

    /** 组装对外返回结构：把内部状态翻译成「能不能展示 + 展示什么」 */
    private static function assemble(array $r, string $source, string $hit, array $tried, array $cfg, string $why = ''): array
    {

        $payload = $r['payload'];
        if (is_array($payload)
            && array_key_exists('value', $payload)
            && array_key_exists('provider', $payload)) {
            $hit     = (string)$payload['provider'];
            $payload = $payload['value'];
        }

        // 有 payload 就有东西可展示 —— 这里只判「有/无」，
        // 不判「是不是实时的」，那个交给 source 表达，两者各自独立。
        $available = ($payload !== null && $payload !== '' && $payload !== []);

        return [
            'available'   => $available,
            'payload'     => $available ? $payload : null,
            // 前端只需「有就渲染、没有就显示我」，不必知道任何业务细节
            'fallback'    => ProviderRegistry::fillFallback(
                (string)$cfg['fallback_tpl'],
                (string)$cfg['fallback']
            ),
            'source'      => $source,
            'provider'    => $hit,
            'tried'       => $tried,
            'fetched_at'  => $r['fetched_at'],
            'expires_at'  => $r['expires_at'],
        ] + ($why !== '' ? ['reason' => $why] : []);
    }

    /** 探测单个候选源是否可用（「这个源能不能用」的自查） */
    public static function probe(string $service, string $providerName): array
    {
        $target = null;
        foreach (ProviderRegistry::providers($service) as $p) {
            if ($p['name'] === $providerName) { $target = $p; break; }
        }
        if ($target === null) {
            return ['ok' => false, 'reason' => '候选源不存在或未启用', 'latency_ms' => 0];
        }

        $url = ProviderRegistry::buildUrl($target);
        $t0  = microtime(true);
        $raw = HttpClient::getJson($url);
        $ms  = (int)round((microtime(true) - $t0) * 1000);

        if ($raw === null) {
            return ['ok' => false, 'reason' => '请求失败（网络不通 / 被出站安全策略阻断 / 响应非 JSON）', 'latency_ms' => $ms];
        }
        $val = ProviderRegistry::pick($raw, $target['fields']);
        if ($val === null) {
            return ['ok' => false, 'reason' => "能连通但取不到值（fields={$target['fields']} 不匹配该接口的返回结构）", 'latency_ms' => $ms];
        }
        if (!ProviderRegistry::matchesType($val, $target['type'])) {
            return ['ok' => false, 'reason' => "能连通但类型不符（声明 {$target['type']}，实际 " . gettype($val) . '）', 'latency_ms' => $ms];
        }
        return ['ok' => true, 'reason' => '可用', 'latency_ms' => $ms];
    }
}
