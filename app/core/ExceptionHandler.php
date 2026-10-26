<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/** 全局异常处理器 */
final class ExceptionHandler
{
    private static bool $registered = false;
    private static bool $handled = false;

    /** 注册全局异常、错误与致命错误（shutdown）处理器 */
    public static function register(): void
    {
        if (self::$registered) return;
        self::$registered = true;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);

        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** 未捕获异常处理 */
    public static function handleException(Throwable $e): void
    {
        self::$handled = true;

        error_log(sprintf(
            '[ExceptionHandler] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (self::isApiRequest()) {
            self::sendJsonError($e);
        } else {
            self::sendHtmlError($e);
        }
    }

    /** shutdown 兜底：捕获致命错误 */
    public static function handleShutdown(): void
    {

        if (self::$handled) return;

        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::$handled = true;
        $detail = sprintf('%s @ %s:%d', $err['message'] ?? '', $err['file'] ?? '?', $err['line'] ?? 0);
        error_log('[ExceptionHandler] SHUTDOWN FATAL: ' . $detail);

        if (self::isApiRequest()) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['code' => 1, 'msg' => '服务器内部错误，请稍后重试', 'data' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>系统错误</title></head>'
               . '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0">'
               . '<div style="text-align:center;max-width:480px;padding:40px">'
               . '<h2 style="color:#dc2626">系统错误</h2>'
               . '<p style="color:#666">服务器处理请求时发生错误，请刷新页面重试或联系管理员。</p>'
               . '<a href="index.php" style="display:inline-block;margin-top:16px;padding:8px 24px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none">返回首页</a>'
               . '</div></body></html>';
        }

        self::emitDebugComment($detail);
    }

    /** 错误转异常（E_ERROR / E_PARSE / E_CORE_ERROR 等） */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // 仅处理指定的错误级别，@ 抑制符跳过
        if (!(error_reporting() & $errno)) return false;

        error_log(sprintf('[ErrorHandler] %d: %s in %s:%d', $errno, $errstr, $errfile, $errline));

        // 严重错误转为异常
        if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        // 不中断脚本的警告/提示：仅记录日志
        return true;
    }

    /** 判断当前请求是否为 API/AJAX */
    private static function isApiRequest(): bool
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

        if (in_array($script, ['api.php', 'external_api.php'], true)) return true;
        if (str_contains($script, '_ajax') || str_contains($script, '_api')) return true;
        // AJAX 标识
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
        return false;
    }

    /** API 请求：输出标准 JSON 错误 */
    private static function sendJsonError(Throwable $e): void
    {
        $code = 1;
        if ($e instanceof \App\Core\BusinessException) {
            $code = $e->getErrorCode();
        } elseif (method_exists($e, 'getCode') && $e->getCode() > 0) {
            $code = (int)$e->getCode();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => $code,
            'msg'  => '服务器内部错误，请稍后重试',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** 页面请求：输出友好 HTML 错误页 */
    private static function sendHtmlError(Throwable $e): void
    {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>系统错误</title></head>'
           . '<body style="font-family:system-ui;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0">'
           . '<div style="text-align:center;max-width:480px;padding:40px">'
           . '<h2 style="color:#dc2626">系统错误</h2>'
           . '<p style="color:#666">服务器处理请求时发生错误，请刷新页面重试或联系管理员。</p>'
           . '<a href="index.php" style="display:inline-block;margin-top:16px;padding:8px 24px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none">返回首页</a>'
           . '</div></body></html>';

        self::emitDebugComment(get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        exit;
    }

    /** hc_debug 管理员请求：把错误详情写入 HTML 注释，供 healthcheck.php T9 解析 */
    private static function emitDebugComment(string $detail): void
    {
        if (isset($_GET['hc_debug']) && (($_SESSION['user']['role'] ?? '') === 'admin')) {
            echo "\n<!-- HC-ERROR " . htmlspecialchars($detail) . " -->\n";
        }
    }
}
