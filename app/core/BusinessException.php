<?php
declare(strict_types=1);

namespace App\Core;

/** 业务异常 */
class BusinessException extends \RuntimeException
{
    private int $errorCode;

    public function __construct(string $message, int $errorCode = 1, ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}
