<?php
declare(strict_types=1);

namespace Modules\Import\Service;

/** ImportValidator — 导入行校验（stage 暂存与 chunk 写库共用同一口径） */
final class ImportValidator
{
    /** 校验并归一化一行（返回的 payload 为最终写库口径） */
    public static function validate(array $payload, bool $enforcePrice = false, string $importMode = 'stock', bool $identityMapped = true): array
    {
        if ((string)$payload['platform_part_no'] === '' && (string)$payload['model'] === '') {

            if ($identityMapped) {
                return ['ok' => false, 'skip' => true, 'reason' => '非物料行（无商品编号与型号），已跳过', 'payload' => $payload];
            }
            return ['ok' => false, 'skip' => false, 'reason' => '商品编号与型号均为空，无法定位物料', 'payload' => $payload];
        }
        if ($importMode === 'profile') {
            // 物料建档：只落主档字段，不涉及库存与成本
            $payload['unit_price'] = 0.0;
            return ['ok' => true, 'skip' => false, 'reason' => '', 'payload' => $payload];
        }
        if ((int)$payload['qty'] <= 0) {
            return ['ok' => false, 'skip' => false, 'reason' => '数量为 0 或无效：' . (string)$payload['qty_raw'], 'payload' => $payload];
        }

        if ($enforcePrice && (float)$payload['unit_price'] <= 0) {
            return ['ok' => false, 'skip' => false, 'reason' => \App\Core\Edition::displayName() . '要求单价 > 0，该行缺少有效单价', 'payload' => $payload];
        }
        return ['ok' => true, 'skip' => false, 'reason' => '', 'payload' => $payload];
    }
}
