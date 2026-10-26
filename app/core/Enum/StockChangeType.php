<?php
declare(strict_types=1);

namespace App\Core\Enum;

/** 库存变动类型枚举（stock_log.change_type） */

final class StockChangeType
{
    const IMPORT           = 'import';            // 订单导入（入库）
    const IMPORT_UNDO_OUT  = 'import_undo_out';   // 导入批次回退（出库）
    const MANUAL_IN        = 'manual_in';         // 手动入库
    const MANUAL_OUT       = 'manual_out';        // 手动出库
    const ADJUST           = 'adjust';            // 库存调整
    const SCAN_IN          = 'scan_in';           // 扫码入库
    const SCAN_OUT         = 'scan_out';          // 扫码出库
    const SCAN_UNDO_IN     = 'scan_undo_in';      // 撤销扫码（入库侧）
    const SCAN_UNDO_OUT    = 'scan_undo_out';     // 撤销扫码（出库侧）
    const DAMAGED          = 'damaged';           // 报损
    const REPAIR           = 'repair';
    const BOM_OUT          = 'bom_out';           // BOM 出库
    const CHECKLIST_ADJUST = 'checklist_adjust';  // 在线盘点差异调整
    const BATCH_IN         = 'batch_stock_in';    // 批量入库

    /** 全部合法取值 */
    public static function all(): array
    {
        return [
            self::IMPORT, self::IMPORT_UNDO_OUT, self::MANUAL_IN, self::MANUAL_OUT,
            self::ADJUST, self::SCAN_IN, self::SCAN_OUT, self::SCAN_UNDO_IN,
            self::SCAN_UNDO_OUT, self::DAMAGED, self::REPAIR, self::BOM_OUT,
            self::CHECKLIST_ADJUST, self::BATCH_IN,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function getLabel(string $type): string
    {
        return match ($type) {
            self::IMPORT           => '订单导入',
            self::IMPORT_UNDO_OUT  => '导入回退',
            self::MANUAL_IN        => '手动入库',
            self::MANUAL_OUT       => '手动出库',
            self::ADJUST           => '库存调整',
            self::SCAN_IN          => '扫码入库',
            self::SCAN_OUT         => '扫码出库',
            self::SCAN_UNDO_IN     => '撤销入库',
            self::SCAN_UNDO_OUT    => '撤销出库',
            self::DAMAGED          => '报损',
            self::REPAIR           => '修复',
            self::BOM_OUT          => 'BOM出库',
            self::CHECKLIST_ADJUST => '盘点调整',
            self::BATCH_IN         => '批量入库',
            default                => $type,
        };
    }
}
