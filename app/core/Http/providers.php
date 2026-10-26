<?php
declare(strict_types=1);

/** 外部服务候选源配置 —— **需要自定义的用户改这个文件** */

return [

    'geo' => [

        'fallback'      => '',
        'fallback_tpl'  => '',

        'ttl'           => 86400,
        'runtime'       => ['{client_ip}' => ''],
        'providers'     => [
            [
                // 主源：dwo.cc 公益聚合平台的「IP 高精度地理位置查询（县区级）」。

                'name'     => 'dwo',
                'base'     => 'https://openapi.dwo.cc',
                'endpoint' => 'api/zijh_ip',
                'query'    => ['ip' => '{client_ip}'],
                'fields'   => '',
                'type'     => 'object',
                'enabled'  => true,
            ],
            [
                // 备源：百度开放数据平台的 IP 定位（`resource_id=6006`）。

                'name'     => 'baidu',
                'base'     => 'https://opendata.baidu.com',
                'endpoint' => 'api.php',
                'query'    => [
                    'query'        => '{client_ip}',
                    'resource_id'  => '6006',

                    'co'           => '',
                    'oe'           => 'utf8',
                ],
                'fields'   => '',
                'type'     => 'object',
                'enabled'  => true,
            ],
        ],
    ],

    'slogan' => [
        // 失败时的内置文案。支持占位符：{today} = 当前日期，如 10月04日
        'fallback'      => '今天也要好好工作呀',
        'fallback_tpl'  => '{today} · 今天也要好好工作呀',
        'ttl'           => 1800,   // 缓存新鲜期（秒）。官方 QPS 上限仅 2，故别调太小
        'providers'     => [
            [

                'name'    => 'hitokoto',
                'base'    => 'https://v1.hitokoto.cn',
                'query'   => ['c' => ['i', 'k']],
                'fields'  => 'hitokoto',
                'type'    => 'string',
                'enabled' => true,
            ],

        ],
    ],

    'weather' => [
        'fallback'      => '天气暂不可用',
        'fallback_tpl'  => '{today} · 天气暂不可用',

        'ttl'           => 10800,
        // 运行时占位符：由 WeatherResolver 填充。
        // `{client_ip}` = 访客可公网定位的 IP

        'runtime'       => ['{client_ip}' => '', '{city}' => '', '{place}' => ''],

        'providers'     => [
            [

                'name'     => 'uapis',
                'base'     => 'https://uapis.cn',
                'endpoint' => 'api/v1/misc/weather',
                'query'    => [

                    'city'      => '{place}',
                    'extended' => 'true',
                    'indices'  => 'true',
                    'forecast' => 'true',
                    'lang'     => 'zh',
                ],
                'fields'   => '',
                'type'     => 'array',
                'enabled'  => true,
            ],
            [

                'name'     => 'wttr',
                'base'     => 'https://wttr.in',
                'endpoint' => '{client_ip}',
                'query'    => ['format' => 'j1'],
                'fields'   => '',
                'type'     => 'array',
                'enabled'  => true,
            ],
            [
                // 备选源：免密钥的 Open-Meteo（官方接口，有 SLA，约 0.6s）。

                'name'     => 'open-meteo',
                'base'     => 'https://api.open-meteo.com/v1/forecast',
                'query'    => [
                    'latitude'      => '39.9042',   // 北京。改这里 = 改降级时的城市
                    'longitude'     => '116.4074',
                    'current'       => 'temperature_2m,relative_humidity_2m,apparent_temperature,'
                                     . 'precipitation,weather_code,wind_speed_10m,wind_direction_10m,surface_pressure',
                    'daily'         => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,uv_index_max,sunrise,sunset',
                    'forecast_days' => '1',
                    'timezone'      => 'auto',
                ],
                'fields'   => '',
                'type'     => 'array',
                'enabled'  => true,
            ],
        ],
    ],

    'express_track' => [
        'fallback'      => '暂无物流信息',
        'fallback_tpl'  => '',
        // 物流轨迹时效性要求高，但缓存只是防重复查询，1 小时足够
        'ttl'           => 3600,
        'providers'     => [
            [

                'name'     => 'uapis',
                'base'     => 'https://uapis.cn',
                'endpoint' => 'api/v1/misc/tracking/query',
                'query'    => [
                    'tracking_number' => '{tracking_number}',
                    'carrier_code'    => '{carrier_code}',   // 查不到公司时调用方传空串 → 该参数不出现
                ],

                'headers'  => ['Authorization' => 'Bearer {secret:uapis}'],
                'fields'   => '',
                'type'     => 'array',
                'enabled'  => false,   // ← 用户存好 Key 后自行改成 true
            ],
            [
                // 自建源模板：把 base / headers 换成你自己的快递接口即可。

                'name'     => 'my-express',
                'base'     => 'https://api.example.com',   // ← 换成你的接口（必须 https）
                'endpoint' => 'track',
                'query'    => ['sn' => '{tracking_number}'],
                'headers'  => ['Authorization' => 'Bearer {secret:my-express}'],
                'fields'   => '',
                'type'     => 'array',
                'enabled'  => false,
            ],
        ],
    ],

];
