<?php
declare(strict_types=1);

namespace App\Core;

/** 统一 HTTP 响应类 */
final class Response
{
    /** 成功响应 */
    public static function ok(mixed $data = [], string $msg = 'ok'): void
    {
        self::send(0, $msg, $data);
    }

    /** 错误响应 */
    public static function error(string $msg, int $code = 1, array $data = []): void
    {
        self::send($code, $msg, $data);
    }

    /** 未授权响应（401） */
    public static function unauthorized(string $msg = '未授权'): void
    {
        \http_response_code(401);
        self::error($msg, 401);
    }

    /** 权限不足响应（403） */
    public static function forbidden(string $msg = '权限不足'): void
    {
        \http_response_code(403);
        self::error($msg, 403);
    }

    /** 资源不存在响应（404） */
    public static function notFound(string $msg = '资源不存在'): void
    {
        \http_response_code(404);
        self::error($msg, 404);
    }

    /** 参数校验失败响应（422） */
    public static function validationError(string $msg = '参数校验失败'): void
    {
        \http_response_code(422);
        self::error($msg, 422);
    }

    /** 通用 JSON 响应输出 */
    public static function send(int $code, string $msg, mixed $data): void
    {
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 扫码成功响应（扁平结构） */
    public static function scanOk(array $data = []): void
    {
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 扫码失败响应（扁平结构） */
    public static function scanError(string $msg): void
    {
        \header('Content-Type: application/json; charset=utf-8');
        echo \json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 重定向 */
    public static function redirect(string $url): void
    {
        if (function_exists('siteUrl')) {
            $url = siteUrl($url);
        }
        \header('Location: ' . $url);
        exit;
    }
}
