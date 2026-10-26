<?php
declare(strict_types=1);

namespace App\Core\Http;

use Throwable;

/** 出站 HTTP 客户端 —— **全项目唯一持有 curl 的地方**（业务代码不得再自行 curl_init） */
final class HttpClient
{
    /** 连接超时（秒）——短，避免本地机器上页面久等 */
    private const CONNECT_TIMEOUT = 3;

    /** 总超时（秒）——含传输；比连接超时长，给慢响应留余量 */
    private const TOTAL_TIMEOUT = 5;

    /** 被阻断的**内网 / 特殊用途**地址段。 */
    private const BLOCKED_HOSTS = [
        'localhost',
        'host.docker.internal',   // Docker 内访问宿主机的惯用名
        'metadata.google.internal',
    ];

    /** 取 JSON（失败返回 null，不抛异常） */
    public static function getJson(string $url, array $headers = [], int $timeout = self::TOTAL_TIMEOUT): ?array
    {
        $body = self::getBody($url, $headers, $timeout);
        if ($body === null) return null;

        $data = json_decode($body, true);
        if (!is_array($data)) {
            self::logFailure(HttpException::decode($url));
            return null;
        }
        return $data;
    }

    /**
     * POST JSON 并解析响应（AI 等需要请求体的接口用；失败返回 null，不抛异常）
     *
     * 与 getJson() 同风格：所有失败都收敛成 null，错误写日志。
     */
    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = self::TOTAL_TIMEOUT): ?array
    {
        try {
            $body = self::postBody($url, $payload, $headers, $timeout);
            if ($body === null) return null;

            $data = json_decode($body, true);
            if (!is_array($data)) {
                self::logFailure(HttpException::decode($url));
                return null;
            }
            return $data;
        } catch (HttpException $e) {
            self::logFailure($e);
            return null;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[HttpClient] 未预期异常 %s @ %s:%d | %s',
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return null;
        }
    }

    /** POST 请求返回原始响应体（失败返回 null，不抛异常） */
    public static function postBody(string $url, array $payload, array $headers = [], int $timeout = self::TOTAL_TIMEOUT): ?string
    {
        try {
            return self::requestPost($url, $payload, $headers, $timeout);
        } catch (HttpException $e) {
            self::logFailure($e);
            return null;
        } catch (Throwable $e) {
            error_log(sprintf(
                '[HttpClient] 未预期异常 %s @ %s:%d | %s',
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return null;
        }
    }

    /** 实际发起 POST（JSON 体），失败抛 HttpException */
    private static function requestPost(string $url, array $payload, array $headers, int $timeout): string
    {
        if (self::isBlockedUrl($url)) {
            throw HttpException::blocked($url, '协议非 https 或主机命中内网地址段');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw HttpException::transport($url, '请求体 JSON 序列化失败');
        }
        $ua = 'LCSC-Inventory/' . (defined('APP_VERSION') ? APP_VERSION : '1.0');
        $headers = $headers + ['Content-Type' => 'application/json'];
        if (function_exists('curl_init')) {
            return self::postViaCurl($url, $body, $headers, $timeout, $ua);
        }
        return self::postViaStream($url, $body, $headers, $timeout, $ua);
    }

    /** POST · curl 实现 */
    private static function postViaCurl(string $url, string $body, array $headers, int $timeout, string $ua): string
    {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
        ];
        $hdr = self::formatHeaders($headers);
        if ($hdr !== []) $opt[CURLOPT_HTTPHEADER] = $hdr;
        curl_setopt_array($ch, $opt);

        $resp   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) throw HttpException::timeout($url);
        if ($errno !== 0 || $resp === false)  throw HttpException::transport($url, $err !== '' ? $err : "errno {$errno}");
        if ($status < 200 || $status >= 300)   throw HttpException::httpStatus($url, $status);

        return (string)$resp;
    }

    /** POST · stream context 兜底（无 curl 扩展时） */
    private static function postViaStream(string $url, string $body, array $headers, int $timeout, string $ua): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'         => 'POST',
                'timeout'        => $timeout,
                'header'         => implode("\r\n", self::formatHeaders($headers + ['User-Agent' => $ua])),
                'content'        => $body,
                'ignore_errors'  => true,
                'follow_location'=> 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => (string)ini_get('curl.cainfo') ?: (string)ini_get('openssl.cafile'),
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $err = error_get_last()['message'] ?? '';
            if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
                throw HttpException::timeout($url);
            }
            throw HttpException::transport($url, 'file_get_contents 返回 false');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
        }
        if ($status < 200 || $status >= 300) throw HttpException::httpStatus($url, $status);

        return (string)$resp;
    }

    /** 取原始响应体（失败返回 null，不抛异常） */
    public static function getBody(string $url, array $headers = [], int $timeout = self::TOTAL_TIMEOUT): ?string
    {
        try {
            return self::request($url, $headers, $timeout);
        } catch (HttpException $e) {

            self::logFailure($e);
            return null;
        } catch (Throwable $e) {
            // 未预期异常同样不抛给上层，但**必须把类名与文件行打出来** ——

            error_log(sprintf(
                '[HttpClient] 未预期异常 %s @ %s:%d | %s',
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
            return null;
        }
    }

    /** 流式下载到文件**（在线升级专用；失败返回 false，不抛异常） */
    public static function downloadToFile(string $url, string $dest, array $opts = []): bool
    {
        $timeout      = (int)($opts['timeout'] ?? 120);
        $maxBytes     = (int)($opts['max_bytes'] ?? 0);
        $allowedHosts = array_map('strtolower', (array)($opts['allowed_hosts'] ?? []));
        $maxRedirects = (int)($opts['max_redirects'] ?? 3);

        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            error_log('[HttpClient] 下载目标不可写：' . $dest);
            return false;
        }

        try {
            $current = $url;
            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                $redirect = self::downloadHop($current, $fh, $timeout, $maxBytes, $allowedHosts);
                if ($redirect === null) {
                    return true;   // downloadHop 非 2xx 时会抛，走到这里即成功
                }
                // 3xx：丢弃本次写入的响应体，跟随跳转
                @ftruncate($fh, 0);
                @rewind($fh);
                $current = $redirect;
            }
            throw HttpException::httpStatus($current, 302);   // 跳转层数超限
        } catch (HttpException $e) {
            self::logFailure($e);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[HttpClient] 下载未预期异常 %s @ %s:%d | %s',
                get_class($e), $e->getFile(), $e->getLine(), $e->getMessage()
            ));
        }

        if (is_resource($fh)) { fclose($fh); $fh = null; }
        @unlink($dest);
        return false;
    }

    /** 单跳下载：2xx 写入文件返回 null；3xx 返回跳转目标；其余抛 HttpException */
    private static function downloadHop(string $url, $fh, int $timeout, int $maxBytes, array $allowedHosts): ?string
    {
        if (self::isBlockedUrl($url)) {
            throw HttpException::blocked($url, '协议非 https 或主机命中内网地址段');
        }
        if ($allowedHosts !== []) {
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host === '' || !in_array($host, $allowedHosts, true)) {
                throw HttpException::blocked($url, '下载主机不在代码白名单内');
            }
        }
        if (!function_exists('curl_init')) {
            // stream 兜底不支持流式写盘 + 跳转控制，在线升级明确要求 curl
            throw HttpException::transport($url, '在线升级需要 curl 扩展');
        }

        $location = null;
        $ch = curl_init($url);
        $opt = [
            CURLOPT_FILE           => $fh,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LCSC-Inventory/' . (defined('APP_VERSION') ? APP_VERSION : '1.0'),
            CURLOPT_FOLLOWLOCATION => false,   // 跳转由本类自己走，才能逐跳校验主机
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            },
        ];
        if ($maxBytes > 0) {
            $opt[CURLOPT_MAXFILESIZE] = $maxBytes;

            $xfer = defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION')
                  : (defined('CURLOPT_PROGRESSFUNCTION') ? constant('CURLOPT_PROGRESSFUNCTION') : null);
            if ($xfer !== null) {
                $opt[CURLOPT_NOPROGRESS] = false;
                $opt[$xfer] = static fn($ch, int $dlTotal): int => $dlTotal > $maxBytes ? 1 : 0;
            }
        }
        curl_setopt_array($ch, $opt);

        curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) throw HttpException::timeout($url);
        if ($errno !== 0) {
            if ($maxBytes > 0 && defined('CURLE_ABORTED_BY_CALLBACK') && $errno === CURLE_ABORTED_BY_CALLBACK) {
                throw HttpException::transport($url, "超过大小上限 {$maxBytes} 字节");
            }
            throw HttpException::transport($url, $err !== '' ? $err : "errno {$errno}");
        }
        if ($status === 0) throw HttpException::transport($url, '无响应状态码');

        if ($status >= 300 && $status < 400) {
            if ($location === null || $location === '') throw HttpException::httpStatus($url, $status);
            return self::resolveRedirect($url, $location);
        }
        if ($status < 200 || $status >= 300) throw HttpException::httpStatus($url, $status);

        return null;
    }

    /** 解析 Location 为绝对 URL（支持绝对地址 / `//host/path` / `/path` / 相对路径） */
    private static function resolveRedirect(string $base, string $loc): string
    {
        if (preg_match('~^https?://~i', $loc)) return $loc;
        $p = parse_url($base);
        if ($p === false || empty($p['host'])) return $loc;
        $scheme = $p['scheme'] ?? 'https';
        $port   = isset($p['port']) ? ':' . (int)$p['port'] : '';
        if (str_starts_with($loc, '//')) return $scheme . ':' . $loc;
        if (str_starts_with($loc, '/'))  return $scheme . '://' . $p['host'] . $port . $loc;
        $dir = rtrim(str_replace('\\', '/', dirname($p['path'] ?? '/')), '/');
        return $scheme . '://' . $p['host'] . $port . $dir . '/' . $loc;
    }

    /** 出站环境自检**：出站前能否正常工作？不可用时给出**可查的原因**而不是空泛的失败 */
    public static function diagnose(): array
    {
        $curlCainfo  = (string)ini_get('curl.cainfo');
        $opensslCa   = (string)ini_get('openssl.cafile');
        $info = ['curl_cainfo' => $curlCainfo, 'openssl_cafile' => $opensslCa];

        if (!function_exists('curl_init')) {
            // 无 curl 时走 stream 兜底，只需要 openssl.cafile
            if ($opensslCa !== '' && is_file($opensslCa)) {
                return $info + ['ok' => true, 'reason' => '无 curl 扩展，走 stream 兜底且已配 CA'];
            }
            return $info + ['ok' => false,
                'reason' => '既无 curl 扩展，openssl.cafile 也未配置（PHP 无法校验 https 证书）'];
        }

        if ($curlCainfo !== '' && is_file($curlCainfo)) {
            return $info + ['ok' => true, 'reason' => "curl.cainfo 已配置：{$curlCainfo}"];
        }
        if ($opensslCa !== '' && is_file($opensslCa)) {
            return $info + ['ok' => true, 'reason' => "openssl.cafile 已配置：{$opensslCa}"];
        }

        return $info + ['ok' => false, 'reason' =>
            '未配置 CA 证书包（curl.cainfo / openssl.cafile 均为空或文件不存在），'
            . 'PHP 无法校验 https 证书，所有 https 出站会失败。'
            . '修法：下载 https://curl.se/ca/cacert.pem，在 php.ini 的 [curl] 段设 '
            . 'curl.cainfo = "证书路径"，或 [openssl] 段设 openssl.cafile。'
            . '本机既有功能 checkRemoteVersion()（版本检测）同样受此影响。'];
    }

    /** diagnose() 的简写：环境是否可用于出站 */
    public static function isEnvironmentReady(): bool
    {
        return self::diagnose()['ok'];
    }

    /** 实际发起请求，失败一律抛 HttpException（由 getBody 统一收口） */
    private static function request(string $url, array $headers, int $timeout): string
    {

        if (self::isBlockedUrl($url)) {
            throw HttpException::blocked($url, '协议非 https 或主机命中内网地址段');
        }

        $ua = 'LCSC-Inventory/' . (defined('APP_VERSION') ? APP_VERSION : '1.0');

        // curl 扩展不一定存在（Windows 本地部署常见），故必须检查并有兜底
        if (function_exists('curl_init')) {
            return self::requestViaCurl($url, $headers, $timeout, $ua);
        }
        return self::requestViaStream($url, $headers, $timeout, $ua);
    }

    /** curl 实现（首选） */
    private static function requestViaCurl(string $url, array $headers, int $timeout, string $ua): string
    {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_FOLLOWLOCATION => false,   // 见下方说明
        ];

        $hdr = self::formatHeaders($headers);
        if ($hdr !== []) {
            $opt[CURLOPT_HTTPHEADER] = $hdr;
        }
        curl_setopt_array($ch, $opt);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) throw HttpException::timeout($url);
        if ($errno !== 0 || $body === false)   throw HttpException::transport($url, $err !== '' ? $err : "errno {$errno}");
        if ($status < 200 || $status >= 300)    throw HttpException::httpStatus($url, $status);

        return (string)$body;
    }

    /** stream context 兜底（无 curl 扩展时） */
    private static function requestViaStream(string $url, array $headers, int $timeout, string $ua): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $timeout,
                'header'        => implode("\r\n", self::formatHeaders($headers + ['User-Agent' => $ua])),
                'ignore_errors' => true,   // 让 4xx/5xx 也返回 body，由下方统一判状态码
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,

                'cafile' => (string)ini_get('curl.cainfo') ?: (string)ini_get('openssl.cafile'),
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            // stream 方式拿不到细粒度错误码，只能按超时/传输失败归类
            $err = error_get_last()['message'] ?? '';
            if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
                throw HttpException::timeout($url);
            }
            throw HttpException::transport($url, $err !== '' ? $err : 'file_get_contents 返回 false');
        }

        // 从响应头取状态码
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) $status = (int)$m[1];
        }
        if ($status < 200 || $status >= 300) throw HttpException::httpStatus($url, $status);

        return $body;
    }

    /** 出站安全校验（SSRF 唯一防线）** */
    public static function isBlockedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return true;   // 解析不出来 = 不可信 = 阻断
        }
        if (strtolower($parts['scheme']) !== 'https') return true;

        $host = strtolower(trim($parts['host'], '.'));
        if ($host === '') return true;

        // ① 特殊用途主机名（字面量，非 DNS）
        if (in_array($host, self::BLOCKED_HOSTS, true)) return true;

        // 主机名是 IP 字面量 → 直接数值判读，不查 DNS
        if (self::isIpLiteral($host)) {
            return !self::isPublicIp(self::stripIpBrackets($host));
        }

        // ② 域名 → 解析后逐个判读（任一解析结果落在内网即阻断）
        $ips = @gethostbynamel($host);
        if ($ips === false || $ips === []) {
            // 解析失败不阻断：本项目允许内网自建域名（可能是 hosts 文件里的名字），
            // 但这种情况本来就打不通，会在传输层失败并降级，不构成 SSRF。
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) return true;
        }
        return false;
    }

    /** 判断是否为 IP 字面量（含 IPv4 短写、IPv6） */
    private static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('~^(\d{1,3}\.){1,3}\d{1,3}$~', $host) === 1
            || str_contains($host, ':');
    }

    /** 剥掉 IPv6 字面量外层的方括号（`[::1]` → `::1`） */
    private static function stripIpBrackets(string $host): string
    {
        return trim($host, '[]');
    }

    /** 判断 IP 是否为**公网可路由**地址（内网 / 回环 / 链路本地 / 保留段一律 false） */
    private static function isPublicIp(string $ip): bool
    {
        $ip = self::stripIpBrackets($ip);

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
        if (!$isPublic) {
            return false;
        }

        if (str_contains($ip, '.')) {
            $long = ip2long($ip);
            $low  = ip2long('100.64.0.0');
            $high = ip2long('100.127.255.255');
            if ($long !== false && $low !== false && $high !== false
                && $long >= $low && $long <= $high) {
                return false;
            }
        }
        return true;
    }

    /** 统一请求头格式 */
    private static function formatHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }

    /** 记录出站失败 */
    private static function logFailure(HttpException $e): void
    {
        error_log('[HttpClient] ' . $e->reason() . ' | ' . self::redactUrl($e->getMessage()));
    }

    /** 把 URL 里的敏感参数值替换为掩码 */
    private static function redactUrl(string $text): string
    {
        $sensitive = 'key|api_?key|apikey|token|access_?token|secret|password|passwd|pwd|sign|signature|auth';
        // 只替换「敏感参数的值」，保留参数名本身，便于排障时知道是哪个参数
        return (string)preg_replace(
            '/([?&](?:' . $sensitive . ')=)([^&\s"\']+)/i',
            '$1***',
            $text
        );
    }
}
