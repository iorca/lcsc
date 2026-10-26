<?php
declare(strict_types=1);

namespace App\Core\Migration;

/** 迁移结果值对象（三个类同文件，避免 Engine 拆成多个小文件） */

/** 整体迁移结果 */
final class MigrationResult
{
    public const SUCCESS = 'success';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    private string $status = '';
    private array $messages = [];
    private array $steps = [];
    private ?MigrationValidation $validation = null;
    private ?string $backupFile = null;

    public function setStatus(string $status): void { $this->status = $status; }
    public function getStatus(): string { return $this->status; }

    public function getStatusText(): string
    {
        return match ($this->status) {
            self::SUCCESS => '成功',
            self::FAILED  => '失败',
            self::SKIPPED => '跳过',
            default       => '未知',
        };
    }

    public function isFailed(): bool { return $this->status === self::FAILED; }

    public function addMessage(string $msg, string $level = 'info'): void
    {
        $this->messages[] = ['level' => $level, 'message' => $msg];
    }

    public function getMessages(): array { return $this->messages; }
    public function addStep(MigrationStepResult $step): void { $this->steps[] = $step; }
    public function getSteps(): array { return $this->steps; }
    public function setValidation(MigrationValidation $v): void { $this->validation = $v; }
    public function getValidation(): ?MigrationValidation { return $this->validation; }
    public function setBackupFile(string $f): void { $this->backupFile = $f; }
    public function getBackupFile(): ?string { return $this->backupFile; }
}

/** 单个版本的迁移步骤结果 */
final class MigrationStepResult
{
    private string $from;
    private string $to;
    private string $status = '';
    private array $messages = [];
    private int $recordsMigrated = 0;

    public function __construct(string $from, string $to)
    {
        $this->from = $from;
        $this->to   = $to;
    }

    public function getFrom(): string { return $this->from; }
    public function getTo(): string { return $this->to; }
    public function setStatus(string $s): void { $this->status = $s; }
    public function getStatus(): string { return $this->status; }
    public function isFailed(): bool { return $this->status === MigrationResult::FAILED; }
    public function addMessage(string $msg): void { $this->messages[] = $msg; }
    public function getMessages(): array { return $this->messages; }
    public function setRecordsMigrated(int $n): void { $this->recordsMigrated = $n; }
    public function getRecordsMigrated(): int { return $this->recordsMigrated; }
}

/** 数据校验结果 */
final class MigrationValidation
{
    private array $checks = [];

    public function addCheck(string $name, $actual, $expected, string $status): void
    {
        $this->checks[] = [
            'name'     => $name,
            'actual'   => $actual,
            'expected' => $expected,
            'status'   => $status,
        ];
    }

    public function getChecks(): array { return $this->checks; }

    public function hasErrors(): bool
    {
        foreach ($this->checks as $c) {
            if ($c['status'] === 'ERROR') return true;
        }
        return false;
    }
}
