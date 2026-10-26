<?php
declare(strict_types=1);

namespace App\Core\Http;

/** 天气数据格式化 —— 把两家接口的原始结构统一成「温度 + 中文描述 + 图标」的展示形态 */
final class WeatherFormatter
{
    /** WMO 码（open-meteo 用）→ 中文描述与图标 */
    private const WMO = [
        0  => ['晴',       '☀'],
        1  => ['大部晴朗', '🌤'],
        2  => ['多云',     '⛅'],
        3  => ['阴',       '☁'],
        45 => ['雾',       '🌫'],
        48 => ['雾凇',     '🌫'],
        51 => ['小毛毛雨', '🌦'],
        53 => ['毛毛雨',   '🌦'],
        55 => ['大毛毛雨', '🌦'],
        56 => ['冻毛毛雨', '🌧'],
        57 => ['强冻毛毛雨', '🌧'],
        61 => ['小雨',     '🌦'],
        63 => ['中雨',     '🌧'],
        65 => ['大雨',     '🌧'],
        66 => ['冻雨',     '🌧'],
        67 => ['强冻雨',   '🌧'],
        71 => ['小雪',     '🌨'],
        73 => ['中雪',     '🌨'],
        75 => ['大雪',     '❄'],
        77 => ['雪粒',     '🌨'],
        80 => ['小阵雨',   '🌦'],
        81 => ['阵雨',     '🌧'],
        82 => ['强阵雨',   '⛈'],
        85 => ['小阵雪',   '🌨'],
        86 => ['强阵雪',   '❄'],
        95 => ['雷阵雨',   '⛈'],
        96 => ['雷阵雨伴冰雹', '⛈'],
        99 => ['强雷阵雨伴冰雹', '⛈'],
    ];

    /** WWO 码（wttr.in 用）→ 中文描述与图标 */
    private const WWO = [
        113 => ['晴',         '☀'],
        116 => ['多云',       '⛅'],
        119 => ['多云',       '⛅'],
        122 => ['阴',         '☁'],
        125 => ['雾',         '🌫'],
        128 => ['浮尘',       '🌫'],
        131 => ['扬沙',       '🌫'],
        134 => ['沙尘暴',     '🌫'],
        137 => ['强沙尘暴',   '🌫'],
        140 => ['强沙尘暴',   '🌫'],
        143 => ['雾',         '🌫'],
        146 => ['烟霾',       '🌫'],
        149 => ['烟霾',       '🌫'],
        152 => ['雾霾',       '🌫'],
        155 => ['重度雾霾',   '🌫'],
        158 => ['浮尘',       '🌫'],
        161 => ['浮尘',       '🌫'],
        176 => ['附近有阵雨', '🌦'],
        179 => ['附近有阵雪', '🌨'],
        182 => ['附近有雨夹雪', '🌨'],
        185 => ['附近有冻毛毛雨', '🌧'],
        200 => ['附近有雷雨', '⛈'],
        227 => ['吹雪',       '❄'],
        230 => ['暴风雪',     '❄'],
        248 => ['雾',         '🌫'],
        260 => ['冻雾',       '🌫'],
        263 => ['零星毛毛雨', '🌦'],
        266 => ['小雨',       '🌦'],
        281 => ['冻毛毛雨',   '🌧'],
        284 => ['强冻毛毛雨', '🌧'],
        293 => ['零星小雨',   '🌦'],
        296 => ['小雨',       '🌦'],
        299 => ['间歇小雨',   '🌦'],
        302 => ['中雨',       '🌧'],
        305 => ['间歇大雨',   '🌧'],
        308 => ['大雨',       '🌧'],
        311 => ['小冻雨',     '🌧'],
        314 => ['中到大冻雨', '🌧'],
        317 => ['小雨夹雪',   '🌨'],
        320 => ['中到大雨夹雪', '🌨'],
        323 => ['零星小雪',   '🌨'],
        326 => ['小雪',       '🌨'],
        329 => ['零星中雪',   '🌨'],
        332 => ['中雪',       '❄'],
        335 => ['零星大雪',   '❄'],
        338 => ['大雪',       '❄'],
        350 => ['冰粒',       '🌨'],
        353 => ['小阵雨',     '🌦'],
        356 => ['中到大阵雨', '🌧'],
        359 => ['暴雨',       '🌧'],
        362 => ['小阵雨夹雪', '🌨'],
        365 => ['中到大阵雨夹雪', '🌨'],
        368 => ['小阵雪',     '🌨'],
        371 => ['中到大阵雪', '❄'],
        374 => ['小阵冰粒',   '🌨'],
        377 => ['中到大阵冰粒', '🌨'],
        386 => ['附近有雷阵雨', '⛈'],
        389 => ['强雷阵雨',   '⛈'],
        392 => ['附近有雷阵雪', '⛈'],
        395 => ['强雷阵雪',   '⛈'],
    ];

    /** 缺码时的显示文案 —— 不显示空串，宁可显示「未知天气」 */
    private const UNKNOWN = ['未知天气', '🌡'];

    /** 中文天气文本 → 图标（**优先路径**） */
    private const TEXT_ICON = [
        '晴' => '☀', '多云' => '⛅', '阴' => '☁',
        '小雨' => '🌧', '中雨' => '🌧', '大雨' => '🌧', '暴雨' => '⛈',
        '雷阵雨' => '⛈', '雷阵雨伴冰雹' => '⛈', '阵雨' => '🌦',
        '小雨转中雨' => '🌧', '中雨转大雨' => '🌧', '大雨转暴雨' => '⛈',
        '阴转多云' => '⛅', '多云转晴' => '⛅', '晴转多云' => '⛅',
        '小雪' => '🌨', '中雪' => '🌨', '大雪' => '❄', '雨夹雪' => '🌨',
        '阵雪' => '🌨', '小到中雪' => '🌨', '中到大雪' => '❄', '暴雪' => '❄',
        '雾' => '🌫', '霾' => '🌫', '浮尘' => '🌫', '扬沙' => '🌫', '沙尘暴' => '🌫',
        '冻雨' => '🌧', '雷暴' => '⛈',
    ];

    /** uapis 的 `weather_icon` 三位码 → 图标（**兜底路径**） */
    private const UAPIS_ICON = [
        '100' => '☀',   // 晴
        '101' => '⛅',   // 多云
        '102' => '☁',   // 阴
        '103' => '🌦',   // 阵雨
        '104' => '⛈',   // 雷阵雨
    ];

    /** WMO 码 → [描述, 图标]（open-meteo 的码表） */
public static function describeWmo(int $code): array
{
    return self::WMO[$code] ?? self::UNKNOWN;
}

/** WWO 码 → [描述, 图标]（wttr.in 的码表） */
public static function describeWwo(int $code): array
{
    return self::WWO[$code] ?? self::UNKNOWN;
}

    /** 把候选源返回的原始结构格式化为统一形状 */
    public static function format(array $raw, string $provider): ?array
    {
        return match ($provider) {
            'uapis'       => self::fromUapis($raw),
            'wttr'        => self::fromWttr($raw),
            'open-meteo'  => self::fromOpenMeteo($raw),
            default       => self::fromWttr($raw),   // 用户自建源按 wttr 形状试，试不成再说
        };
    }

    /** uapis.cn 形状 —— **首选源** */
    private static function fromUapis(array $raw): ?array
    {
        $text = trim((string)($raw['weather'] ?? ''));
        $temp = self::temp($raw['temperature'] ?? null, 'C');
        if ($text === '' && $temp === '') return null;   // 两样都没有 → 交给上层兜底

        // 图标：先按 weather_icon 码查（覆盖已知码），查不到再按中文文本反查
        $icon = self::TEXT_ICON[$text] ?? '';
        if ($icon === '') {
            $icon = self::UAPIS_ICON[self::uapisIconKey($raw)] ?? '🌡';
        }
        return ['text' => $text, 'temp' => $temp, 'icon' => $icon];
    }

    /** uapis 的 weather_icon 转成"码段"形态：`"100"` → `100`，非数字返回空串 */
    private static function uapisIconKey(array $raw): string
    {
        $v = (string)($raw['weather_icon'] ?? '');
        return ctype_digit($v) ? $v : '';
    }

    /** wttr.in 形状：`current_condition[0]` 或直接该元素 */
    private static function fromWttr(array $raw): ?array
{
        $c = $raw['current_condition'][0] ?? $raw;
        if (!is_array($c) || !isset($c['temp_C'])) return null;

        if (!isset($c['weatherCode'])) return null;
        [$text, $icon] = self::describeWwo((int)$c['weatherCode']);

        return [
            'text' => $text,
            'temp' => self::temp($c['temp_C'], 'C'),
            'icon' => $icon,
        ];
    }

    /** open-meteo 形状 */
private static function fromOpenMeteo(array $raw): ?array
{
        // 有无天气码 / 温度 —— 只要有其一就能出内容
        $hasCode = isset($raw['weather_code']) || isset($raw['weathercode']);
        $t = $raw['temperature_2m'] ?? $raw['temperature'] ?? null;

        // 都没有 → 再看是不是整个响应（current 包装形态）
        if (!$hasCode && $t === null) {
            $inner = $raw['current'] ?? $raw['current_weather'] ?? null;
            if (!is_array($inner)) return null;
            $hasCode = isset($inner['weather_code']) || isset($inner['weathercode']);
            $t = $inner['temperature_2m'] ?? $inner['temperature'] ?? null;
            if (!$hasCode && $t === null) return null;
        }

        $code = (int)($raw['weather_code'] ?? $raw['weathercode'] ?? 0);
        [$text, $icon] = self::describeWmo($code);

        return [
            'text' => $hasCode ? $text : '未知天气',
            'temp' => $t === null ? '' : self::temp($t, 'C'),
            'icon' => $icon,
        ];
    }

    /** 温度取整 + 单位 */
    private static function temp(mixed $v, string $unit): string
    {
        if (!is_numeric($v)) return '';
        return round((float)$v) . '°' . $unit;
    }

    /** 拼成一句可直接展示的话 */
    public static function sentence(array $w): string
    {
        $parts = [];
        if ($w['icon'] !== '') $parts[] = $w['icon'];
        if ($w['text'] !== '') $parts[] = $w['text'];
        if ($w['temp'] !== '') $parts[] = $w['temp'];
        return implode(' ', $parts);
    }
}
