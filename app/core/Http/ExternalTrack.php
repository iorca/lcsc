<?php
declare(strict_types=1);

namespace App\Core\Http;

use PDO;

/** 快递物流轨迹查询 —— 通道已通，业务未接 */
final class ExternalTrack
{
    /** 查一条运单的物流轨迹 */
    public static function track(PDO $db, string $trackingNo, string $company = '', string $phone = ''): array
    {
        $no = self::normNo($trackingNo);
        if ($no === '') {
            return self::fail('运单号为空');
        }

        // 中文名 → 编码；查不到就让接口自动识别（不猜，猜错会查到别家）
        $code = ExpressCarrier::toCode($company);
        if ($code === '') {
            $code = ExpressCarrier::guessByNumber($no);
        }

        // 运行时参数：与天气那条 {client_ip} 同一套占位符机制
        $runtime = [
            '{tracking_number}' => $no,
            '{carrier_code}'    => $code,
            '{phone}'           => preg_replace('/\D/', '', $phone) ?? '',   // 只留数字
        ];

        $cfg   = ProviderRegistry::serviceConfig('express_track');
        $key   = ExternalCache::key('express_track', $no . ($code !== '' ? ':' . $code : ''));

        $r = ExternalCache::remember(
            $db,
            $key,
            (int)$cfg['ttl'],
            static function () use ($cfg, $runtime): ?array {
                foreach ($cfg['providers'] as $p) {
                    $url = ProviderRegistry::buildUrl($p, $runtime);
                    if ($url === '') {
                        continue;
                    }
                    // **空值参数直接去掉**：`carrier_code` 为空时拼出 `carrier_code=`

                    $url = self::dropEmptyParams($url);
                    $headers = !empty($p['headers'])
                        ? SecretStore::resolvePlaceholders((array)$p['headers'])
                        : [];
                    $raw = HttpClient::getJson($url, $headers);
                    if (!is_array($raw)) {
                        continue;
                    }
                    return $raw;
                }
                return null;
            }
        );

        $payload = $r['payload'];
        if (!is_array($payload)) {
            return self::fail('外部接口未返回数据（可能额度用尽 / Key 无效 / 单号不存在）');
        }
        return self::normalize($payload, $code);
    }

    /** 把各家形状不一的响应归一成统一轨迹列表 */
    private static function normalize(array $raw, string $fallbackCarrier): array
    {
        $carrier = (string)($raw['carrier_code'] ?? $raw['carrier'] ?? $fallbackCarrier);

        $rows = [];
        foreach ([$raw, $raw['data'] ?? null, $raw['traces'] ?? null, $raw['logistics'] ?? null,
                  $raw['result'] ?? null, $raw['data']['items'] ?? null] as $cand) {
            if (is_array($cand) && $cand && array_is_list($cand)) {
                $rows = $cand;
                break;
            }
        }

        $tracks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $t = (string)($row['time'] ?? $row['datetime'] ?? $row['date'] ?? $row['created_at'] ?? '');
            $d = (string)($row['desc'] ?? $row['description'] ?? $row['content'] ?? $row['text'] ?? '');
            if ($t === '' && $d === '') continue;
            $tracks[] = ['time' => $t, 'desc' => $d];
        }
        usort($tracks, static fn($a, $b) => strcmp($a['time'], $b['time']));

        return [
            'ok'          => $tracks !== [],
            'carrier'     => $carrier,
            'carrierName' => ExpressCarrier::toName($carrier),
            'status'      => (string)($raw['state'] ?? $raw['status'] ?? $raw['logistics_state'] ?? ''),
            'tracks'      => $tracks,
            'message'     => $tracks === []
                ? (string)($raw['message'] ?? $raw['msg'] ?? '未查询到轨迹（可能单号刚下单，或需要手机尾号验证）')
                : '',
        ];
    }

    /** 剔除 query 里值为空的参数 */
    private static function dropEmptyParams(string $url): string
    {
        if (!str_contains($url, '?')) {
            return $url;
        }
        [$base, $qs] = explode('?', $url, 2);
        $keep = [];
        foreach (explode('&', $qs) as $pair) {
            if ($pair === '') continue;
            $eq = strpos($pair, '=');
            if ($eq === false) { $keep[] = $pair; continue; }
            if (urldecode(substr($pair, $eq + 1)) === '') continue;   // 值为空 → 丢
            $keep[] = $pair;
        }
        return $keep === [] ? $base : $base . '?' . implode('&', $keep);
    }

    /** 运单号归一：去空白与常见分隔符（导入文件里常有全角空格/换行） */
    private static function normNo(string $no): string
    {
        $s = (string)preg_replace('/[\s\x{3000}\-—]+/u', '', trim($no));
        return $s;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'carrier' => '', 'carrierName' => '', 'status' => '',
                'tracks' => [], 'message' => $msg];
    }
}
