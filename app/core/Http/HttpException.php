<?php
declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/** 出站 HTTP 异常 */
final class HttpException extends RuntimeException
{
    /** URL 未通过出站安全校验（协议非 https，或命中内网地址段） */
    public const E_BLOCKED = 'blocked';

    /** 连接或总超时 */
    public const E_TIMEOUT = 'timeout';

    /** 传输层失败（DNS 解析失败、连接被拒、SSL 错误等） */
    public const E_TRANSPORT = 'transport';

    /** 拿到了响应但 HTTP 状态码非 2xx */
    public const E_HTTP = 'http';

    /** 响应体不是预期的 JSON */
    public const E_DECODE = 'decode';

    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** 便于统一登记到错误分支的工厂方法 */
    public static function blocked(string $url, string $why): self
    {
        return new self(self::E_BLOCKED, "出站被阻断：{$why}（{$url}）");
    }

    public static function timeout(string $url): self
    {
        return new self(self::E_TIMEOUT, "出站超时（{$url}）");
    }

    public static function transport(string $url, string $err): self
    {
        return new self(self::E_TRANSPORT, "出站传输失败（{$url}）：{$err}");
    }

    public static function httpStatus(string $url, int $code): self
    {
        return new self(self::E_HTTP, "出站返回 HTTP {$code}（{$url}）");
    }

    public static function decode(string $url): self
    {
        return new self(self::E_DECODE, "出站响应不是合法 JSON（{$url}）");
    }
}
