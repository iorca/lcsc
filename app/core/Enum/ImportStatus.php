<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** 导入状态枚举 */
final class ImportStatus
{

    const UPLOADED  = 'uploaded';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';

    // normalized_import_items.match_status
    const PENDING  = 'pending';
    const MATCHED  = 'matched';
    const CREATED  = 'created';
    const CONFLICT = 'conflict';

    // imported_files.status（导入批次）
    const PROCESSING         = 'processing';          // 分批处理中
    const DONE               = 'done';                // 已完成
    const ROLLING_BACK       = 'rolling_back';        // 撤销中（守卫中间态）
    const ROLLED_BACK        = 'rolled_back';         // 已全部撤销
    const PARTIAL_ROLLED_BACK = 'partial_rolled_back'; // 部分撤销（部分行不满足扣回条件）

    public static function documentStatuses(): array
    {
        return [self::UPLOADED, self::COMPLETED, self::CANCELLED];
    }

    public static function matchStatuses(): array
    {
        return [self::PENDING, self::MATCHED, self::CREATED, self::CONFLICT];
    }

    public static function batchStatuses(): array
    {
        return [self::PROCESSING, self::DONE, self::ROLLING_BACK, self::ROLLED_BACK, self::PARTIAL_ROLLED_BACK];
    }

    public static function getLabel(string $status): string
    {
        return match ($status) {
            self::UPLOADED  => '已上传',
            self::COMPLETED => '已完成',
            self::CANCELLED => '已取消',
            self::PENDING   => '待匹配',
            self::MATCHED  => '已匹配',
            self::CREATED  => '已创建',
            self::CONFLICT => '冲突',
            default        => $status,
        };
    }

    /** 批次状态 → [显示文案, 徽标样式类] */
    public static function batchLabel(string $status): array
    {
        return match ($status) {
            self::PROCESSING          => ['处理中', 'badge-blue'],
            self::DONE                => ['已完成', 'badge-green'],
            self::ROLLING_BACK        => ['撤销中', 'badge-blue'],
            self::ROLLED_BACK         => ['已撤销', 'badge-gray'],
            self::PARTIAL_ROLLED_BACK => ['部分撤销', 'badge-yellow'],
            default                   => [],
        };
    }

    /** 批次是否处于「可撤销」终态（已撤销的不能再撤） */
    public static function isBatchRollbackable(string $status): bool
    {
        return $status === self::DONE || $status === self::PARTIAL_ROLLED_BACK;
    }

    /** 全部批次状态的「状态 → [文案, 徽标类]」映射 */
    public static function batchLabels(): array
    {
        $out = [];
        foreach (self::batchStatuses() as $s) {
            $label = self::batchLabel($s);
            if ($label) $out[$s] = $label;
        }
        return $out;
    }

    public static function isValidDocumentStatus(string $status): bool
    {
        return in_array($status, self::documentStatuses(), true);
    }

    public static function isValidMatchStatus(string $status): bool
    {
        return in_array($status, self::matchStatuses(), true);
    }
}
