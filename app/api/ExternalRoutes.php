<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

/** 外部服务（非核心功能）异步补齐 API */
final class ExternalRoutes
{
    public static function handle(string $method, array $user): void
    {
        switch ($method) {
            case 'wb':
                self::handleWorkbench();
                break;

            default:
                Response::error('未知 external 方法：' . $method, 404);
        }
    }

    /** GET external.wb — 工作台问候区的天气 + 一言 */
    private static function handleWorkbench(): void
    {
        $db = \getDB();

        // 一言
        $sloganFetch = \App\Core\Http\ProviderDispatcher::fetch($db, 'slogan');
        $slogan = $sloganFetch['available'] ? (string)$sloganFetch['payload'] : $sloganFetch['fallback'];

        if (\App\Core\Http\WeatherResolver::isIntranetHost()) {
            Response::ok([
                'slogan'  => $slogan,
                'weather' => [
                    'display' => false,
                    'live'    => false,
                    'text'    => '',
                    'city'    => '',
                    'summary' => [],
                    'detail'  => [],
                    'advice'  => [],
                    'icon'    => '',
                    'temp'    => '',
                    'source'  => '',
                    'at'      => '',
                ],
            ]);
            return;
        }

        // 天气：两段式取地名 → 算城市级缓存键 → 取数
        $ip = \App\Core\Http\WeatherResolver::resolveClientIp();
        if ($ip !== '') {
            \App\Core\Http\WeatherResolver::resolvePlace($ip);
        }
        $cacheKey = \App\Core\Http\WeatherCityCache::keyFor($ip);
        $weatherFetch = \App\Core\Http\ProviderDispatcher::fetch($db, 'weather', $cacheKey);

        // 回填 IP→地名映射：响应里带城市名时记下来，下次该 IP 直接命中缓存
        if ($weatherFetch['available'] && is_array($weatherFetch['payload']) && $ip !== '') {
            \App\Core\Http\WeatherCityCache::rememberFromPayload($ip, $weatherFetch['payload']);
        }

        $fmt = ($weatherFetch['available'] && is_array($weatherFetch['payload']))
            ? \App\Core\Http\WeatherFormatter::format($weatherFetch['payload'], $weatherFetch['provider'])
            : null;

        $metrics = ['city' => '', 'summary' => [], 'detail' => [], 'advice' => []];
        if ($fmt !== null && is_array($weatherFetch['payload'])) {
            $metrics = \App\Core\Http\WeatherMetrics::extract($weatherFetch['payload'], $weatherFetch['provider']);
        }
        // 地名**优先显示到县区级**（IP 定位主源能给到「唐河」这一级），
        // 取不到时保留天气响应里的城市名。纯展示替换，不影响缓存键与查询。
        $place = $ip !== '' ? \App\Core\Http\WeatherCityCache::displayPlace($ip) : '';
        if ($place !== '') {
            $metrics['city'] = $place;
        }

        Response::ok([
            'slogan'  => $slogan,
            'weather' => [
                // 有真实数据才给前端可交互标记：兜底文案时不给假入口，

                'display' => true,
                'live'    => $fmt !== null,
                'text'    => $fmt !== null ? \App\Core\Http\WeatherFormatter::sentence($fmt) : $weatherFetch['fallback'],
                'city'    => $metrics['city'],
                'summary' => $metrics['summary'],
                'detail'  => $metrics['detail'],
                'advice'  => $metrics['advice'],
                'icon'    => $fmt['icon'] ?? '',
                'temp'    => $fmt['temp'] ?? '',
                'source'  => (string)($weatherFetch['provider'] ?? ''),
                'at'      => (string)($weatherFetch['fetched_at'] ?? ''),
            ],
        ]);
    }
}
