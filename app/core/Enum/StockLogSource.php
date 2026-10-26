<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** 库存流水来源枚举（stock_log.source_type） */
final class StockLogSource
{
    const SCAN        = 'scan';         // 扫码出入库
    const SCAN_UNDO   = 'scan_undo';    // 扫码撤销
    const ADJUST      = 'adjust';       // 手动调整/盘点页调整
    const CHECKLIST   = 'checklist';    // 在线盘点差异调整
    const IMPORT      = 'import';       // 订单导入
    const IMPORT_UNDO = 'import_undo';  // 导入批次回退
    const MIGRATION   = 'migration';    // 版本迁移
    const BOM         = 'bom';          // BOM 出库（source_id = bom_projects.id）

    public static function all(): array
    {
        return [
            self::SCAN, self::SCAN_UNDO, self::ADJUST, self::CHECKLIST,
            self::IMPORT, self::IMPORT_UNDO, self::MIGRATION, self::BOM,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function getLabel(string $type): string
    {
        return match ($type) {
            self::SCAN        => '扫码',
            self::SCAN_UNDO   => '扫码撤销',
            self::ADJUST      => '手动调整',
            self::CHECKLIST   => '在线盘点',
            self::IMPORT      => '订单导入',
            self::IMPORT_UNDO => '导入回退',
            self::MIGRATION   => '版本迁移',
            self::BOM         => 'BOM 出库',
            default           => $type,
        };
    }
}
