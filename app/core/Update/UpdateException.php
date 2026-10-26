<?php
declare(strict_types=1);

namespace App\Core\Update;

use RuntimeException;

/** 在线升级过程中的可预期失败（校验不符 / 包结构非法 / 写盘失败等）；由调用方转成用户可读提示 */
final class UpdateException extends RuntimeException
{
}
