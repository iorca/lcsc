<?php
declare(strict_types=1);

namespace App\Core\Http;

/** 天气详细指标 —— 把候选源原始响应拆成「悬浮速览 / 点击详情 / 生活建议」三组 */
final class WeatherMetrics
{
    /** 取出详细指标，按展示位置分三组返回 */
    public static function extract(array $raw, string $provider): array
    {
        return match ($provider) {
            'uapis'       => self::fromUapis($raw),
            'open-meteo'  => self::fromOpenMeteo($raw),
            default       => self::fromWttr($raw),   // 用户自建源按 wttr 形状试
        };
    }

    /** uapis.cn 形状 —— **首选源** */
    private static function fromUapis(array $raw): array
    {
        $out = self::blank((string)($raw['city'] ?? ''));

        $today = self::firstDay(is_array($raw['forecast'] ?? null) ? $raw['forecast'] : []);

        self::put($out, 'summary', '最高', self::degC($raw['temp_max'] ?? ($today['temp_max'] ?? null)));
        self::put($out, 'summary', '最低', self::degC($raw['temp_min'] ?? ($today['temp_min'] ?? null)));
        self::put($out, 'summary', '湿度', self::pct($raw['humidity'] ?? null));
        self::put($out, 'summary', '风速', self::windPower($raw['wind_power'] ?? null));
        self::put($out, 'summary', '气压', self::unit($raw['pressure'] ?? null, 'hPa', 1));

        self::put($out, 'detail', '体感', self::degC($raw['feels_like'] ?? null));
        self::put($out, 'detail', '能见度', self::unit($raw['visibility'] ?? null, 'km', 1));
        self::put($out, 'detail', '降水', self::unit($raw['precipitation'] ?? null, 'mm', 1));
        self::put($out, 'detail', '风向风力', self::text($raw['wind_direction'] ?? null, '风'));
        // AQI：把等级描述与主要污染物并成一项（「良· PM10」），比裸数字可读
        self::put($out, 'detail', '空气质量', self::aqiText($raw));
        self::put($out, 'detail', '紫外线', self::uvText($raw['uv'] ?? null));
        self::put($out, 'detail', '云量', self::pct($raw['cloud'] ?? null));
        self::put($out, 'detail', '日出', self::hhmm($today['sunrise'] ?? null));
        self::put($out, 'detail', '日落', self::hhmm($today['sunset'] ?? null));

        // 生活指数：只挑与「出门/上班」最相关的几项，卡片放不下 18 项。

        $li = $raw['life_indices'] ?? null;
        if (is_array($li)) {
            self::putLifeIndex($out, 'clothing', '穿衣', $li['clothing'] ?? null);
            self::putLifeIndex($out, 'umbrella', '雨伞', $li['umbrella'] ?? null);
            self::putLifeIndex($out, 'travel', '出行', $li['travel'] ?? null);
            self::putLifeIndex($out, 'exercise', '运动', $li['exercise'] ?? null);
            self::putLifeIndex($out, 'air_purifier', '空气净化', $li['air_purifier'] ?? null);
        }

        // 气象预警：只取第一条的标题（详情卡片放不下整篇正文）
        $alert = $raw['alerts'][0] ?? null;
        if (is_array($alert)) {
            $title = trim((string)($alert['title'] ?? ''));
            if ($title !== '') {
                $out['detail']['气象预警'] = $title;   // 标题本身已含级别与类型，不重复拼接
            }
        }

        return $out;
    }

    /** wttr.in 形状 */
    private static function fromWttr(array $raw): array
    {
        $cur = is_array($raw['current_condition'][0] ?? null) ? $raw['current_condition'][0] : [];
        if (!$cur && isset($raw['temp_C'])) $cur = $raw;   // 兼容只存了 current_condition 元素的旧缓存
        $today = is_array($raw['weather'][0] ?? null) ? $raw['weather'][0] : [];
        $astro = is_array($today['astronomy'][0] ?? null) ? $today['astronomy'][0] : [];

        $out = self::blank(self::nearestArea($raw));

        self::put($out, 'summary', '最高', self::degC($today['maxtempC'] ?? null));
        self::put($out, 'summary', '最低', self::degC($today['mintempC'] ?? null));
        self::put($out, 'summary', '湿度', self::pct($cur['humidity'] ?? null));
        self::put($out, 'summary', '风速', self::unit($cur['windspeedKmph'] ?? null, 'km/h'));
        self::put($out, 'summary', '气压', self::unit($cur['pressure'] ?? null, 'hPa'));

        self::put($out, 'detail', '体感', self::degC($cur['FeelsLikeC'] ?? null));
        self::put($out, 'detail', '能见度', self::unit($cur['visibility'] ?? null, 'km'));
        self::put($out, 'detail', '降水', self::unit($cur['precipMM'] ?? null, 'mm', 1));
        self::put($out, 'detail', '降水概率', self::pct(self::hourlyMax($today['hourly'] ?? null, 'chanceofrain')));
        self::put($out, 'detail', '风向', self::compass($cur['winddir16Point'] ?? null));
        self::put($out, 'detail', '云量', self::pct($cur['cloudcover'] ?? null));
        self::put($out, 'detail', '日出', self::hhmm($astro['sunrise'] ?? null));
        self::put($out, 'detail', '日落', self::hhmm($astro['sunset'] ?? null));

        // 生活指数：wttr 不提供 → 该组留空（前端自动省略）。**不按阈值编造。**
        return $out;
    }

    /** open-meteo 形状 */
    private static function fromOpenMeteo(array $raw): array
    {
        $cur = is_array($raw['current'] ?? null) ? $raw['current'] : [];
        if (!$cur && isset($raw['temperature_2m'])) $cur = $raw;   // 兼容只存了 current 的旧缓存
        $daily = is_array($raw['daily'] ?? null) ? $raw['daily'] : [];

        $out = self::blank('');

        self::put($out, 'summary', '最高', self::degC(self::at($daily, 'temperature_2m_max')));
        self::put($out, 'summary', '最低', self::degC(self::at($daily, 'temperature_2m_min')));
        self::put($out, 'summary', '湿度', self::pct($cur['relative_humidity_2m'] ?? null));
        self::put($out, 'summary', '风速', self::unit($cur['wind_speed_10m'] ?? null, 'km/h'));
        self::put($out, 'summary', '气压', self::unit($cur['surface_pressure'] ?? null, 'hPa'));

        self::put($out, 'detail', '体感', self::degC($cur['apparent_temperature'] ?? null));
        self::put($out, 'detail', '降水', self::unit($cur['precipitation'] ?? null, 'mm', 1));
        self::put($out, 'detail', '降水概率', self::pct(self::at($daily, 'precipitation_probability_max')));
        self::put($out, 'detail', '风向', self::compass($cur['wind_direction_10m'] ?? null));
        self::put($out, 'detail', '紫外线', self::uvText(self::at($daily, 'uv_index_max')));
        self::put($out, 'detail', '日出', self::hhmm(self::at($daily, 'sunrise')));
        self::put($out, 'detail', '日落', self::hhmm(self::at($daily, 'sunset')));

        return $out;
    }

    // ══ 内部工具 ══════════════════════════════════════════

    /** 空骨架（三组都已初始化，调用方按组追加不必判 isset） */
    private static function blank(string $city): array
    {
        return ['city' => $city, 'summary' => [], 'detail' => [], 'advice' => []];
    }

    /** 写入一项；值为空串直接丢弃（这就是「缺字段就不显示」的实现点） */
    private static function put(array &$out, string $group, string $key, string $value): void
    {
        if ($value !== '') $out[$group][$key] = $value;
    }

    /** 填「生活建议」组 —— **仅当源真的提供了生活指数时才填** */
    private static function putLifeIndex(array &$out, string $key, string $label, $idx): void
    {
        if (!is_array($idx)) return;

        $val = trim((string)($idx['brief'] ?? ''));
        if ($val === '') $val = trim((string)($idx['level'] ?? ''));
        if ($val !== '') $out['advice'][$label] = $val;
    }

    /** 风力等级：uapis 给的是「微风 / 3级」这类**已算好的描述**，直接用，别自己换算 */
    private static function windPower($v): string
    {
        $s = trim((string)$v);
        return $s === '' ? '' : $s;
    }

    /** AQI 合成一句话：「良 · PM10」；只给等级时输出「良」 */
    private static function aqiText(array $raw): string
    {
        $cat = trim((string)($raw['aqi_category'] ?? ''));
        $pri = trim((string)($raw['aqi_primary'] ?? ''));
        $val = is_numeric($raw['aqi'] ?? null) ? (string)(int)$raw['aqi'] : '';
        $s = $cat !== '' ? $cat : $val;
        if ($s === '') return '';
        if ($pri !== '') $s .= ' · ' . $pri;
        return $s;
    }

    /** 文本值 + 可选后缀（如风向「西南风」缺后缀时补「风」） */
    private static function text($v, string $suffix = ''): string
    {
        $s = trim((string)$v);
        if ($s === '') return '';
        return ($suffix !== '' && !str_contains($s, $suffix)) ? $s . $suffix : $s;
    }

    /** wttr 最近城市：areaName 与 region 拼成「深圳 广东」 */
    private static function nearestArea(array $raw): string
    {
        $na = $raw['nearest_area'][0] ?? null;
        if (!is_array($na)) return '';
        return trim(
            (string)($na['areaName'][0]['value'] ?? '') . ' '
            . (string)($na['region'][0]['value'] ?? '')
        );
    }

    /** 取「按天数组」里某字段的第 0 天值（open-meteo 的 `daily` 形状） */
    private static function at(array $arr, string $key)
    {
        return is_array($arr[$key] ?? null) ? ($arr[$key][0] ?? null) : null;
    }

    /** 取「数组本身即各天」的形状里的第 0 天（uapis 的 `forecast`） */
    private static function firstDay(array $rows)
    {
        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /** 逐时数组里某字段的当天最大值（降水概率） */
    private static function hourlyMax($hourly, string $key)
    {
        if (!is_array($hourly)) return null;
        $max = null;
        foreach ($hourly as $h) {
            if (is_array($h) && isset($h[$key]) && is_numeric($h[$key])) {
                $v = (float)$h[$key];
                if ($max === null || $v > $max) $max = $v;
            }
        }
        return $max;
    }

    // ── 标量格式化：非数字一律回空串，由 put() 丢弃 ──

    private static function degC($v): string
    {
        return is_numeric($v) ? round((float)$v) . '°C' : '';
    }

    private static function pct($v): string
    {
        return is_numeric($v) ? round((float)$v) . '%' : '';
    }

    /** 通用「数值 + 单位」；$dec 指定小数位（降水要 1 位，风速气压取整） */
    private static function unit($v, string $unit, int $dec = 0): string
    {
        return is_numeric($v) ? round((float)$v, $dec) . ' ' . $unit : '';
    }

    /** 紫外线指数附中文等级（3~5 中等 / 6~7 强 / 8~10 很强 / 11+ 极强） */
    private static function uvText($v): string
    {
        if (!is_numeric($v)) return '';
        $n = round((float)$v);
        $lv = $n >= 11 ? '极强' : ($n >= 8 ? '很强' : ($n >= 6 ? '强' : ($n >= 3 ? '中等' : '弱')));
        return $n . '（' . $lv . '）';
    }

    /** 时间只取时分：open-meteo 给 `2026-10-07T06:12`，wttr 给 `06:12` */
    private static function hhmm($v): string
    {
        $s = (string)$v;
        if ($s === '') return '';
        $p = strpos($s, 'T');
        return substr($p !== false ? substr($s, $p + 1) : $s, 0, 5);
    }

    /** 风向 → 中文 */
    private static function compass($v): string
    {
        $cn = ['N' => '北', 'NNE' => '北东北', 'NE' => '东北', 'ENE' => '东东北',
               'E' => '东', 'ESE' => '东东南', 'SE' => '东南', 'SSE' => '南东南',
               'S' => '南', 'SSW' => '南西南', 'SW' => '西南', 'WSW' => '西西南',
               'W' => '西', 'WNW' => '西西北', 'NW' => '西北', 'NNW' => '北西北'];
        if (is_string($v) && isset($cn[strtoupper($v)])) return $cn[strtoupper($v)] . '风';
        if (!is_numeric($v)) return '';
        // 角度 → 16 方位：每 22.5° 一档，取最接近的档而非区间起点
        $abbr = array_keys($cn);
        return $cn[$abbr[(int)round(((float)$v % 360) / 22.5) % 16]] . '风';
    }
}
