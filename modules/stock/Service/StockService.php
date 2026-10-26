<?php
declare(strict_types=1);
/** 出入库管理模块（v1.1.0 正式版） */

class StockException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message, $errCode);
        $this->errCode = $errCode;
    }
}

/** 出入库管理器 */
final class StockManager
{
    private PDO $db;
    private int $uid;       // 当前操作用户ID（用于日志记录）
    private int $dataUid;   // 数据所属用户ID（子用户继承父用户数据）
    private int $workspaceId; // 工作空间ID

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid         = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = getWorkspaceId();
    }

    /** 手动出入库操作 */
    public function stockChange(array $p): array
    {
        $id       = intval($p['id'] ?? 0);
        $type     = (string)($p['change_type'] ?? '');
        $qty      = intval($p['qty'] ?? 0);
        $rem      = trim((string)($p['remark'] ?? ''));
        $unitCost = round((float)($p['unit_cost'] ?? 0), 4);
        // ── 价格处理 ──

        if (isEnforceUnitPrice() && $unitCost <= 0 && in_array($type, ['manual_in', 'scan_in', 'import_in'], true)) {
            // 团队版：入库类操作强制要求单价>0
            throw new StockException(\App\Core\Edition::displayName() . '入库必须填写有效单价（大于0）', 4);
        }
        $isSample = !empty($p['is_sample']) ? 1 : 0;

        if ($id <= 0) throw new StockException('元件ID无效', 4);
        if ($qty < 0) throw new StockException('数量不能为负', 4);

        // 查询元件当前库存
        $row = $this->db->prepare("SELECT stock, damaged, platform_part_no FROM parts WHERE id=? AND user_id=?");
        $row->execute([$id, $this->dataUid]);
        $row = $row->fetch();
        if (!$row) throw new StockException('元件不存在', 3);

        $before  = (int)$row['stock'];
        $dBefore = (int)$row['damaged'];
        $after   = $before;
        $dAfter  = $dBefore;
        $change  = 0;

        if ($type === 'manual_out') {
            // 手动出库：复用统一出库底层方法
            $remark = $rem !== '' ? $rem : '手动出库';
            $result = $this->executeOutbound($id, $qty, 'manual_out', $remark);
            return [
                'part_id'      => $id,
                'user_id'      => $this->uid,
                'stock_before' => $result['qty_before'],
                'stock_after'  => $result['qty_after'],
                'change'       => -$result['qty'],
                'subtotal'     => 0,
            ];
        }

        if ($type === 'damaged') {
            // 报损：从良品 → 不良品
            $actual = min($before, $qty);
            $after  = $before - $actual;
            $dAfter = $dBefore + $actual;
            $change = -$actual;
            \applyStockDelta($id, -$actual);
            \applyDamagedDelta($id, $actual);
        } elseif ($type === 'repair') {

            $actual = min($dBefore, $qty);
            $after  = $before + $actual;
            $dAfter = $dBefore - $actual;
            $change = $actual;
            \applyStockDelta($id, $actual);
            \applyDamagedDelta($id, -$actual);
        } else {
            // 入库/调整
            $after = match ($type) {
                'adjust'     => max(0, $qty),
                default      => $before + $qty, // manual_in / scan_in 等入库类型
            };
            $change = $after - $before;
            if ($type === 'adjust') {
                \applyStockSet($id, $after);   // 绝对值调整
            } else {
                \applyStockDelta($id, $qty);   // 入库增量
            }
        }

        // 写入 stock_log（入库类操作记录采购成本、样品标记和含税小计）
        $stkSubtotal = ($change > 0 && $unitCost > 0 && !$isSample) ? round($change * $unitCost, 4) : 0;

        $sourceType = match ($type) {
            'manual_in', 'manual_out' => 'manual',
            'scan_in', 'scan_out'     => 'scan',
            'adjust', 'damaged', 'repair' => 'adjust',
            default                   => 'manual',
        };
        $this->insertStockLog($id, $row['platform_part_no'], $type, $change, $before, $after, $unitCost, $isSample, $stkSubtotal, $rem, $sourceType, null);
        // 同步独立 stock 表
        try { ensureStockRecord($id); } catch (Throwable $e) {}
        try { syncPartStockCache($id); } catch (Throwable $e) {}
        traceLog($this->uid, 'stock_change', 'part', $id, "出入库操作 id:{$id} 类型:{$type} 变动:{$change}");
        markCostDirty($this->db, $id);

        return [
            'part_id'      => $id,
            'user_id'      => $this->uid,
            'stock_before' => $before,
            'stock_after'  => $after,
            'change'       => $change,
            'subtotal'     => $stkSubtotal,
        ];
    }

    /** 扫码入库 */
    public function scanIn(array $p): array
    {

        $p          = $this->withQrFields($p);
        $barcode    = $this->safeStr($p['barcode'] ?? '');
        $internalId = $this->safeInt($p['internal_id'] ?? 0);
        $platCode   = $this->safeStr($p['platform_code'] ?? '');

        $platId     = $this->safeInt($p['platform_id'] ?? 0);
        if ($platId <= 0) $platId = $this->findPlatformIdByCode($platCode);
        $qty        = max(1, $this->safeInt($p['qty'] ?? 1));
        $orderNo    = $this->safeStr($p['order_no'] ?? '');
        $unitCost   = round((float)($p['unit_cost'] ?? 0), 4);

        $skipCost   = (!empty($p['skip_cost']) && can_view_cost()) ? 1 : 0;
        // ── 扫码入库价格处理 ──

        if (isEnforceUnitPrice() && $unitCost <= 0 && !$skipCost) {
            // 团队版：扫码入库必须携带有效单价（勾选「不计成本」时不拦，以 0 价记账）
            throw new StockException(\App\Core\Edition::displayName() . '扫码入库必须填写有效单价', 4);
        }
        $isSample   = !empty($p['is_sample']) ? 1 : 0;

        if ($internalId > 0) {
            $part = $this->findPartByInternalId($internalId);
            if (!$part) throw new StockException('内部物料ID不存在: ' . $internalId, 2);
            $barcode = $part['platform_part_no'] ?: $part['customer_part_no'] ?: ('内部ID:' . $internalId);
        } else {
            if ($barcode === '') throw new StockException('条码不能为空', 2);
            $part = $this->findPartByBarcode($barcode, $platId);
            if (!$part) throw new StockException('未找到该元件: ' . $barcode, 2);
        }

        // 自动携带最近采购价（仅团队版，前端未传单价时回填）；「不计成本」时跳过回填
        if (can_view_cost() && $unitCost <= 0 && !$skipCost && (float)($part['last_purchase_price'] ?? 0) > 0) {
            $unitCost = round((float)$part['last_purchase_price'], 4);
        }

        $dupCheck = $this->db->prepare(
            "SELECT id FROM scan_log WHERE user_id=? AND part_id=? AND scan_type='in' AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1"
        );
        $dupCheck->execute([$this->uid, $part['id']]);
        if ($dupCheck->fetch()) {
            throw new StockException('5秒内请勿重复扫码，请稍后再试', 2);
        }

        $before = (int)$part['stock'];
        $after  = $before + $qty;

        \applyStockDelta((int)$part['id'], $qty);

        $remark = $orderNo !== '' ? '扫码入库 订单:' . $orderNo : '扫码入库';
        $scanSubtotal = ($unitCost > 0 && !$isSample) ? round($qty * $unitCost, 4) : 0;

        // 先写入 scan_log 以获取 scan_log_id
        $this->db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'in', $qty, $before, $after, $remark]);
        $scanLogId = (int)$this->db->lastInsertId();

        $this->insertStockLog((int)$part['id'], $barcode, 'scan_in', $qty, $before, $after, $unitCost, $isSample, $scanSubtotal, $remark, 'scan', $scanLogId);
        // 同步独立 stock 表
        try { ensureStockRecord((int)$part['id']); } catch (Throwable $e) {}
        try { syncPartStockCache((int)$part['id']); } catch (Throwable $e) {}

        $noCost = ($skipCost === 1 && $unitCost <= 0);
        traceLog($this->uid, 'scan_in', 'part', (int)$part['id'], "扫码入库 part_id:{$part['id']} qty:{$qty} barcode:{$barcode}" . ($noCost ? ' 不计成本' : ''));
        if (!$noCost) markCostDirty($this->db, (int)$part['id']);

        return [
            'ok'           => true,
            'type'         => 'scan_in',
            'part_id'      => $part['id'],
            'user_id'      => $this->uid,
            'model'        => $part['model'],
            'part_no'      => $barcode,
            'product_name' => $part['product_name'],
            'qty'          => $qty,
            'qty_before'   => $before,
            'qty_after'    => $after,
            'scan_log_id'  => $scanLogId,
            'remark'       => $remark,
            'created_at'   => date('Y-m-d H:i'),
        ];
    }

    /** 共享出库底层方法（scanOut/manualOut/bomCheckout复用） */
    private function executeOutbound(int $partId, int $qty, string $changeType, string $remark, string $sourceType = '', int $sourceId = 0): array
    {
        if ($qty <= 0) throw new StockException('出库数量必须大于0', 4);
        // 查询物料
        $stmt = $this->db->prepare("SELECT stock, platform_part_no, avg_cost, status FROM parts WHERE id=? AND user_id=?");
        $stmt->execute([$partId, $this->dataUid]);
        $part = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new StockException('物料不存在', 3);

        // 出库前检查物料状态
        $partStatus = $part['status'] ?? 'active';
        if (!\App\Core\Enum\PartStatus::canCheckout($partStatus)) {
            throw new StockException("物料状态为{$partStatus}，不可出库");
        }

        $before = (int)$part['stock'];
        // 出库校验：库存不足时扣到0
        $actual = min($before, $qty);
        $after = $before - $actual;

        \applyStockDelta($partId, -$actual);

        // 出库统一读取avg_cost作为成本
        $outCost = can_view_cost() ? round((float)$part['avg_cost'], 4) : 0;
        $subtotal = ($outCost > 0) ? round($actual * $outCost, 4) : 0;

        if ($sourceType === '') {
            $sourceType = match ($changeType) {
                'manual_out'  => 'manual',
                'scan_out'    => 'scan',
                'bom_checkout'=> 'bom',
                default       => 'manual',
            };
        }
        $this->insertStockLog($partId, $part['platform_part_no'], $changeType, -$actual, $before, $after, $outCost, 0, $subtotal, $remark, $sourceType, $sourceId > 0 ? $sourceId : null);
        // 同步独立 stock 表
        try { syncPartStockCache($partId); } catch (Throwable $e) {}

        markCostDirty($this->db, $partId);
        traceLog($this->uid, $changeType, 'part', $partId, "出库 part_id:{$partId} qty:{$actual} type:{$changeType}");

        return [
            'part_id'    => $partId,
            'qty'        => $actual,
            'qty_before' => $before,
            'qty_after'  => $after,
        ];
    }

    /** 扫码预检：解析阶段只查物料、不改库存 */
    public function scanResolve(array $p): array
    {
        $p          = $this->withQrFields($p);
        $barcode    = $this->safeStr($p['barcode'] ?? '');
        $model      = $this->safeStr($p['model'] ?? '');
        $internalId = $this->safeInt($p['internal_id'] ?? 0);
        $platCode   = $this->safeStr($p['platform_code'] ?? '');

        $platId     = $this->safeInt($p['platform_id'] ?? 0);
        if ($platId <= 0) $platId = $this->findPlatformIdByCode($platCode);
        $matchedBy  = '';
        $part       = null;

        if ($internalId > 0) {
            $part = $this->findPartByInternalId($internalId);
            $matchedBy = 'internal_id';
        } else {
            // 编号优先；编号查不到再按型号试一次 —— 手动敲入的文本无从判断
            // 是编号还是型号（解码器两种都带上），只查编号会漏掉「敲型号」这一路。
            if ($barcode !== '') {
                $part = $this->findPartByBarcode($barcode, $platId);
                if ($part) {
                    $matchedBy = ($part['customer_part_no'] === $barcode && $part['platform_part_no'] !== $barcode)
                        ? 'customer_part_no'
                        : 'part_no';
                }
            }
            if (!$part && $model !== '') {
                $part = $this->findPartByModel($model);
                $matchedBy = $part ? 'model' : '';
            }
            if (!$part && $barcode === '' && $model === '') {
                throw new StockException('条码不能为空', 2);
            }
        }

        // 型号精确匹配没中：再试模糊（用户常只敲型号前缀，如「STM32F」）
        if (!$part && $model !== '') {
            $candidates = $this->findPartsByModel($model);
            if (count($candidates) > 1) {
                return [
                    'ok'         => true,
                    'found'      => false,
                    'ambiguous'  => true,
                    'keyword'    => $model,
                    'candidates' => $candidates,
                ];
            }
            if (count($candidates) === 1) {
                $part = $candidates[0];
                $matchedBy = 'model';
            }
        }

        // 未匹配到：回传原始关键词，由前端引导新建
        if (!$part) {
            return [
                'ok'      => true,
                'found'   => false,
                'keyword' => $internalId > 0 ? ('#' . $internalId) : ($barcode !== '' ? $barcode : $model),
            ];
        }

        $base = $this->partSummary($part);
        $base['matched_by'] = $matchedBy;
        return $base;
    }

    /** 物料摘要：预检与候选列表共用的输出结构 */
    private function partSummary(array $p): array
    {
        return [
            'ok'                  => true,
            'found'               => true,
            'part_id'             => (int)$p['id'],
            'internal_id'         => (int)($p['internal_id'] ?? 0),
            'model'               => (string)($p['model'] ?? ''),
            'part_no'             => (string)($p['platform_part_no'] ?? ''),
            'customer_part_no'    => (string)($p['customer_part_no'] ?? ''),
            'product_name'        => (string)($p['product_name'] ?? ''),
            'brand'               => (string)($p['brand'] ?? ''),
            'package'             => (string)($p['package'] ?? ''),
            'stock'               => (int)$p['stock'],
            'platform_id'         => (int)($p['platform_id'] ?? 0),
            'platform_name'       => (string)($p['platform_name'] ?? ''),
            'platform_type'       => (string)($p['platform_type'] ?? ''),
            'last_purchase_price' => round((float)($p['last_purchase_price'] ?? 0), 4),
        ];
    }

    /** 物料查询统一带平台名（parts 无 platform_code / platform_name 列，需 JOIN） */
    private const PART_SELECT = "SELECT p.*, pl.name AS platform_name, pl.platform_type AS platform_type
                                FROM parts p LEFT JOIN platforms pl ON pl.id = p.platform_id";

    /** 按型号精确匹配元件 */
    private function findPartByModel(string $model): ?array
    {
        $norm = $this->normalizeModel($model);
        if ($norm === '') return null;
        $stmt = $this->db->prepare(
            self::PART_SELECT . "
             WHERE REPLACE(REPLACE(REPLACE(LOWER(p.model),'-',''),' ',''),'_','')=?
               AND p.user_id=? AND p.status!='deleted'
             ORDER BY p.stock DESC, p.id LIMIT 1"
        );
        $stmt->execute([$norm, $this->dataUid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 按型号取候选列表（模糊匹配，最多 8 条） */
    private function findPartsByModel(string $model): array
    {
        $norm = $this->normalizeModel($model);
        if ($norm === '') return [];
        $like = '%' . $norm . '%';
        $stmt = $this->db->prepare(
            self::PART_SELECT . "
             WHERE REPLACE(REPLACE(REPLACE(LOWER(p.model),'-',''),' ',''),'_','') LIKE ?
               AND p.user_id=? AND p.status!='deleted'
             ORDER BY p.stock DESC, p.id LIMIT 8"
        );
        $stmt->execute([$like, $this->dataUid]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->partSummary($row);
        }
        return $out;
    }

    /** 型号归一化：去连字符/空格/下划线并转小写 */
    private function normalizeModel(string $s): string
    {
        return strtolower(str_replace(['-', ' ', '_'], '', trim($s)));
    }

    /** 扫码出库 */
    public function scanOut(array $p): array
    {
        $barcode    = $this->safeStr($p['barcode'] ?? '');
        $internalId = $this->safeInt($p['internal_id'] ?? 0);
        $platCode   = $this->safeStr($p['platform_code'] ?? '');

        $platId     = $this->safeInt($p['platform_id'] ?? 0);
        if ($platId <= 0) $platId = $this->findPlatformIdByCode($platCode);
        $qty        = max(1, $this->safeInt($p['qty'] ?? 1));
        $orderNo    = $this->safeStr($p['order_no'] ?? '');

        if ($internalId > 0) {
            $part = $this->findPartByInternalId($internalId);
            if (!$part) throw new StockException('内部物料ID不存在: ' . $internalId, 2);
            $barcode = $part['platform_part_no'] ?: $part['customer_part_no'] ?: ('内部ID:' . $internalId);
        } else {
            if ($barcode === '') throw new StockException('条码不能为空', 2);
            $part = $this->findPartByBarcode($barcode, $platId);
            if (!$part) throw new StockException('未找到该元件: ' . $barcode, 2);
        }

        // 服务端防重复：同一用户同一元件5秒内不可重复扫码出库（适用内部二维码）
        $dupCheck = $this->db->prepare(
            "SELECT id FROM scan_log WHERE user_id=? AND part_id=? AND scan_type='out' AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND) LIMIT 1"
        );
        $dupCheck->execute([$this->uid, $part['id']]);
        if ($dupCheck->fetch()) {
            throw new StockException('5秒内请勿重复扫码，请稍后再试', 2);
        }

        $remark = $orderNo !== '' ? '扫码出库 订单:' . $orderNo : '扫码出库';

        $this->db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$this->uid, $part['id'], $barcode, 'out', $qty, (int)$part['stock'], (int)$part['stock'] - $qty, $remark]);
        $scanLogId = (int)$this->db->lastInsertId();

        $result = $this->executeOutbound((int)$part['id'], $qty, 'scan_out', $remark, 'scan', $scanLogId);

        // 同步独立 stock 表
        try { ensureStockRecord((int)$part['id']); } catch (Throwable $e) {}

        return [
            'ok'           => true,
            'type'         => 'scan_out',
            'part_id'      => $part['id'],
            'user_id'      => $this->uid,
            'model'        => $part['model'],
            'part_no'      => $barcode,
            'product_name' => $part['product_name'],
            'qty'          => $result['qty'],
            'qty_before'   => $result['qty_before'],
            'qty_after'    => $result['qty_after'],
            'scan_log_id'  => $scanLogId,
            'remark'       => $remark,
            'created_at'   => date('Y-m-d H:i'),
        ];
    }

    /** 撤销扫码操作（入库撤销→出库，出库撤销→入库） */
    public function scanUndo(int $scanLogId): array
    {
        if ($scanLogId <= 0) throw new StockException('参数无效', 2);

        // 查找扫码记录，确保属于当前用户
        $sl = $this->db->prepare("SELECT * FROM scan_log WHERE id=? AND user_id=?");
        $sl->execute([$scanLogId, $this->uid]);
        $scan = $sl->fetch();
        if (!$scan) throw new StockException('记录不存在或无权操作', 2);

        // 防止重复撤销
        if (strpos($scan['remark'] ?? '', '[已撤销]') !== false) {
            throw new StockException('该记录已撤销，不可重复操作', 2);
        }

        // 查找元件
        $partStmt = $this->db->prepare("SELECT * FROM parts WHERE id=? AND user_id=?");
        $partStmt->execute([$scan['part_id'], $this->dataUid]);
        $part = $partStmt->fetch();
        if (!$part) throw new StockException('元件不存在', 2);

        $before = (int)$part['stock'];
        // 反向操作：入库撤销→出库，出库撤销→入库
        if ($scan['scan_type'] === 'in') {
            $after      = max(0, $before - (int)$scan['qty']);
            $actualUndo = $before - $after;
            $logType    = 'scan_undo_in';
            $logQty     = -$actualUndo;
            $remark     = '撤销扫码入库';
        } else {
            $after      = $before + (int)$scan['qty'];
            $actualUndo = (int)$scan['qty'];
            $logType    = 'scan_undo_out';
            $logQty     = $actualUndo;
            $remark     = '撤销扫码出库';
        }

        \applyStockDelta((int)$part['id'], $logQty);

        $this->insertStockLog((int)$part['id'], $scan['platform_part_no'], $logType, $logQty, $before, $after, 0, 0, 0, $remark, 'scan_undo', $scanLogId);
        // 同步独立 stock 表
        try { syncPartStockCache((int)$part['id']); } catch (Throwable $e) {}
        // 更新 scan_log 标记已撤销
        $this->db->prepare("UPDATE scan_log SET remark=CONCAT(remark,' [已撤销]') WHERE id=?")
           ->execute([$scanLogId]);
        traceLog($this->uid, 'scan_undo', 'scan_log', $scanLogId, "撤销扫码 scan_log_id:{$scanLogId} part_id:{$part['id']} type:{$logType}");
        markCostDirty($this->db, (int)$part['id']);

        return [
            'ok'         => true,
            'part_id'    => $part['id'],
            'user_id'    => $this->uid,
            'model'      => $part['model'],
            'part_no'    => $scan['platform_part_no'],
            'qty_before' => $before,
            'qty_after'  => $after,
        ];
    }

    /** 删除单条出入库记录 */
    public function deleteLog(int $logId): array
    {
        if ($logId <= 0) throw new StockException('参数无效', 4);
        // 验证记录属于当前用户（通过 parts 表关联验证）并取创建时间用于留存检查
        $check = $this->db->prepare("SELECT l.id, l.create_time FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id=? AND p.user_id=?");
        $check->execute([$logId, $this->dataUid]);
        $row = $check->fetch();
        if (!$row) throw new StockException('记录不存在或无权操作', 3);
        // 留存期检查：未到期禁止删除
        if (!isRetentionExpired((string)$row['create_time'])) {
            $days = getRetentionDays();
            throw new StockException("记录未满 {$days} 天留存期，禁止删除", 5);
        }
        $this->db->prepare("DELETE FROM stock_log WHERE id=?")->execute([$logId]);
        traceLog($this->uid, 'delete_log', 'stock_log', $logId, "删除出入库记录 log_id:{$logId}");
        return ['deleted' => true, 'log_id' => $logId];
    }

    /** 批量删除出入库记录 */
    public function batchDeleteLogs(array $logIds): array
    {
        $logIds = $this->filterValidIds($logIds);
        if (empty($logIds)) throw new StockException('未选择有效记录', 4);
        $in = implode(',', array_fill(0, count($logIds), '?'));
        $valid = $this->db->prepare("SELECT l.id, l.create_time FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id IN ($in) AND p.user_id=?");
        $valid->execute([...$logIds, $this->dataUid]);
        $rows = $valid->fetchAll();
        if (empty($rows)) throw new StockException('无权删除这些记录', 3);
        // 留存期过滤：只删除已过期的记录
        $deletable = [];
        $skipped = 0;
        foreach ($rows as $r) {
            if (isRetentionExpired((string)$r['create_time'])) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }
        if (empty($deletable)) {
            $days = getRetentionDays();
            throw new StockException("所选记录均未满 {$days} 天留存期，禁止删除", 5);
        }
        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("DELETE FROM stock_log WHERE id IN ($inV)")->execute($deletable);
        traceLog($this->uid, 'batch_delete_logs', 'stock_log', 0, "批量删除出入库记录 count:" . count($deletable) . ($skipped > 0 ? " skipped:{$skipped}" : ''));
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }

    /** 多字段匹配元件：优先按平台+编号查，其次按编号查，最后按客户料号查 */
    private function findPartByBarcode(string $barcode, int $platId): ?array
    {
        $part = null;
        if ($platId > 0) {
            $stmt = $this->db->prepare(self::PART_SELECT . " WHERE p.platform_part_no=? AND p.platform_id=? AND p.user_id=?");
            $stmt->execute([$barcode, $platId, $this->dataUid]);
            $part = $stmt->fetch();
        }
        if (!$part) {
            $stmt = $this->db->prepare(self::PART_SELECT . " WHERE p.platform_part_no=? AND p.user_id=?");
            $stmt->execute([$barcode, $this->dataUid]);
            $part = $stmt->fetch();
        }
        if (!$part) {
            $stmt = $this->db->prepare(self::PART_SELECT . " WHERE p.customer_part_no=? AND p.user_id=?");
            $stmt->execute([$barcode, $this->dataUid]);
            $part = $stmt->fetch();
        }
        return $part ?: null;
    }

    /** 按 internal_id 匹配元件（全平台唯一，用于内部物料二维码扫码） */
    private function findPartByInternalId(int $internalId): ?array
    {
        $stmt = $this->db->prepare(self::PART_SELECT . " WHERE p.internal_id=? AND p.user_id=?");
        $stmt->execute([$internalId, $this->dataUid]);
        $part = $stmt->fetch();
        return $part ?: null;
    }

    /** 根据平台代码查询平台ID（用于 findPartByBarcode 场景） */
    private function findPlatformIdByCode(string $code): int
    {
        if ($code === '') return 0;
        $stmt = $this->db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=? LIMIT 1");
        $stmt->execute([$code, $this->dataUid]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : 0;
    }

    /** 把扫码载荷里的平台二维码原始字段（`fields`）映射为系统字段并回填空缺 */
    private function withQrFields(array $p): array
    {
        $raw = $p['fields'] ?? '';
        if (is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) return $p;

        $platId   = $this->safeInt($p['platform_id'] ?? 0);
        $platCode = $this->safeStr($p['platform_code'] ?? '');
        $dict     = new \Modules\Scan\Service\ScanQrDictService($this->db, $this->dataUid, $this->workspaceId);

        $mapped = ($platId > 0 || $platCode !== '')
            ? $dict->mapRawByPlatform($raw, $platId, $platCode)
            : ($dict->resolveBySignature($raw) ?? []);

        if ($mapped === [] || (int)($mapped['platform_id'] ?? 0) <= 0 || ($mapped['values'] ?? []) === []) {
            return $p;
        }

        // 平台归属回填（仅在未显式给出时）
        if ($platId <= 0) $p['platform_id'] = (int)$mapped['platform_id'];
        if ($platCode === '' && (string)($mapped['platform_code'] ?? '') !== '') {
            $p['platform_code'] = (string)$mapped['platform_code'];
        }

        $vals = $mapped['values'];
        if ($this->safeStr($p['barcode'] ?? '') === '' && isset($vals['platform_part_no'])) $p['barcode'] = $vals['platform_part_no'];
        if ($this->safeStr($p['model'] ?? '') === '' && isset($vals['model'])) $p['model'] = $vals['model'];
        if ($this->safeStr($p['order_no'] ?? '') === '' && isset($vals['order_no'])) $p['order_no'] = $vals['order_no'];
        if (empty($p['qty']) && isset($vals['quantity'])) $p['qty'] = $vals['quantity'];
        return $p;
    }

    private function safeStr(mixed $v): string
    {
        return trim((string)($v ?? ''));
    }

    private function safeInt(mixed $v): int
    {
        return (int)($v ?? 0);
    }

    /** 写入库存流水（统一入口代理） */
    private function insertStockLog(
        int $partId,
        string $platformPartNo,
        string $changeType,
        int $qtyChange,
        int $qtyBefore,
        int $qtyAfter,
        float $unitCost,
        int $isSample,
        float $subtotal,
        string $remark,
        string $sourceType,
        ?int $sourceId = null,
        ?int $locationId = null
    ): void {

        \insertStockLog($this->db, [
            'user_id'          => $this->uid,
            'part_id'          => $partId,
            'platform_part_no' => $platformPartNo,
            'change_type'      => $changeType,
            'qty_change'       => $qtyChange,
            'qty_before'       => $qtyBefore,
            'qty_after'        => $qtyAfter,
            'unit_cost'        => $unitCost,
            'is_sample'        => $isSample,
            'subtotal'         => $subtotal,
            'remark'           => $remark,
            'workspace_id'     => $this->workspaceId,
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'location_id'      => $locationId,
        ]);

        // P8 动销自动清呆滞标记：物料发生任意出入库动销后，

        $this->clearStaleProcessedMark($partId);
    }

    /** 清除物料 remark 开头的呆滞已处理前缀（P8） */
    private function clearStaleProcessedMark(int $partId): void
    {
        try {
            $stmt = $this->db->prepare("SELECT remark FROM parts WHERE id=? AND remark LIKE '[呆滞已处理%'");
            $stmt->execute([$partId]);
            $remark = $stmt->fetchColumn();
            if ($remark === false) return;
            $clean = preg_replace('/^\[呆滞已处理 \d{4}-\d{2}-\d{2}\]\s*/u', '', (string)$remark);
            $this->db->prepare("UPDATE parts SET remark=? WHERE id=?")->execute([$clean, $partId]);
        } catch (\Throwable $e) {
            error_log('clearStaleProcessedMark: ' . $e->getMessage());
        }
    }

    private function filterValidIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) $out[] = $id;
        }
        return array_values(array_unique($out));
    }

    /** 导出盘点清单（Excel下载用数据） */
    public function exportChecklist(array $params = []): array
    {
        $where = "WHERE p.user_id=? AND p.workspace_id=?";
        $binds = [$this->dataUid, $this->workspaceId];

        if (!empty($params['category_id'])) {
            $where .= " AND EXISTS(SELECT 1 FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.id=?)";
            $binds[] = (int)$params['category_id'];
        }
        if (!empty($params['location'])) {
            $where .= " AND p.location LIKE ?";
            $binds[] = '%' . $params['location'] . '%';
        }
        if (!empty($params['platform_id'])) {
            $where .= " AND p.platform_id=?";
            $binds[] = (int)$params['platform_id'];
        }

        $snapshotTime = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("SELECT p.id, p.internal_id, p.platform_part_no, p.model, p.product_name,
            p.brand, p.package, p.location, p.stock AS system_qty, p.stock AS actual_qty
            FROM parts p $where ORDER BY p.location, p.model");
        $stmt->execute($binds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 为每行附加快照时间戳，前端/导出可据此标记盘点基准时刻
        foreach ($rows as &$row) {
            $row['snapshot_time'] = $snapshotTime;
        }
        unset($row);
        return $rows;
    }

    /** 导入盘点结果并生成差异调整 */
    public function importCheckResult(array $items): array
    {
        $adjusted = 0; $skipped = 0; $errors = [];
        $this->db->beginTransaction();
        try {
            $partStmt = $this->db->prepare("SELECT id, stock, model FROM parts WHERE id=? AND user_id=? AND workspace_id=? FOR UPDATE");
            foreach ($items as $i => $item) {
                $partId = (int)($item['part_id'] ?? 0);
                $actualQty = (int)($item['actual_qty'] ?? 0);
                $remark = trim((string)($item['remark'] ?? '盘点差异'));
                if ($partId <= 0) { $errors[] = "第{$i}行：物料ID无效"; $skipped++; continue; }
                $partStmt->execute([$partId, $this->dataUid, $this->workspaceId]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
                if (!$part) { $errors[] = "第{$i}行：物料不存在"; $skipped++; continue; }
                $diff = $actualQty - (int)$part['stock'];
                if ($diff === 0) { $skipped++; continue; } // 无差异跳过

                $this->insertStockLog($partId, '', 'adjust', $diff, (int)$part['stock'], $actualQty, 0, 0, 0, $remark, 'adjust', null);
                // 更新库存（绝对值：以实盘数为准）
                \applyStockSet($partId, $actualQty);
                // 同步独立 stock 表
                try { syncPartStockCache($partId); } catch (Throwable $e) {}
                markCostDirty($this->db, $partId);
                $adjusted++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($adjusted > 0) {
            traceLog($this->uid, 'check_import', 'stock', 0, "盘点导入：调整{$adjusted}条，跳过{$skipped}条");
        }
        return ['adjusted' => $adjusted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** 统一流水查询接口（出入库流水中心） */
    public function queryStockLog(array $params): array
    {
        $filter    = trim((string)($params['filter'] ?? ''));       // all/in/out/adjust/undo
        $partId    = max(0, (int)($params['part_id'] ?? 0));        // 按物料筛选
        $dateFrom  = trim((string)($params['date_from'] ?? ''));    // 起始日期
        $dateTo    = trim((string)($params['date_to'] ?? ''));      // 结束日期
        $keyword   = trim((string)($params['keyword'] ?? ''));      // 型号/编号搜索
        $perPage   = max(10, min(10000, (int)($params['per_page'] ?? 25))); // 上限10000：CSV导出全量取数，页面默认25
        $page      = max(1, (int)($params['page'] ?? 1));

        $where = ["sl.user_id = ?", "sl.workspace_id = ?"];
        $bind  = [$this->dataUid, $this->workspaceId];

        $inboundTypes  = ['scan_in','manual_in','import','batch_stock_in'];
        $outboundTypes = ['scan_out','manual_out','bom_out'];
        $adjustTypes   = ['adjust','checklist_adjust','damaged','repair'];
        $undoTypes     = ['scan_undo_in','scan_undo_out','import_undo_out'];

        switch ($filter) {
            case 'in':
                $ph = implode(',', array_fill(0, count($inboundTypes), '?'));
                $where[] = "sl.change_type IN ($ph)";
                $bind = array_merge($bind, $inboundTypes);
                break;
            case 'out':
                $ph = implode(',', array_fill(0, count($outboundTypes), '?'));
                $where[] = "sl.change_type IN ($ph)";
                $bind = array_merge($bind, $outboundTypes);
                break;
            case 'adjust':
                $ph = implode(',', array_fill(0, count($adjustTypes), '?'));
                $where[] = "sl.change_type IN ($ph)";
                $bind = array_merge($bind, $adjustTypes);
                break;
            case 'undo':
                $ph = implode(',', array_fill(0, count($undoTypes), '?'));
                $where[] = "sl.change_type IN ($ph)";
                $bind = array_merge($bind, $undoTypes);
                break;
        }

        if ($partId > 0) {
            $where[] = "sl.part_id = ?";
            $bind[] = $partId;
        }
        if ($dateFrom !== '') {
            $where[] = "sl.create_time >= ?";
            $bind[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $where[] = "sl.create_time <= ?";
            $bind[] = $dateTo . ' 23:59:59';
        }
        if ($keyword !== '') {
            $where[] = "(p.model LIKE ? OR sl.platform_part_no LIKE ?)";
            $bind[] = "%{$keyword}%";
            $bind[] = "%{$keyword}%";
        }

        $whereSQL = implode(' AND ', $where);

        // 总数
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM stock_log sl LEFT JOIN parts p ON p.id=sl.part_id WHERE {$whereSQL}");
        $cntStmt->execute($bind);
        $total = (int)$cntStmt->fetchColumn();

        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;

        // 查询（个人版不返回unit_cost/subtotal字段）
        $costSelect = can_view_cost()
            ? 'sl.unit_cost, sl.subtotal, sl.is_sample,'
            : '0 AS unit_cost, 0 AS subtotal, sl.is_sample,';

        $stmt = $this->db->prepare(
            "SELECT sl.id, sl.part_id, sl.platform_part_no, sl.change_type,
                    sl.qty_change, sl.qty_before, sl.qty_after,
                    {$costSelect}
                    sl.remark, sl.create_time,
                    COALESCE(sl.workspace_id, 0) AS workspace_id,
                    COALESCE(sl.location_id, 0) AS location_id,
                    COALESCE(sl.source_type, '') AS source_type,
                    COALESCE(sl.source_id, 0) AS source_id,
                    COALESCE(sl.operator, '') AS operator,
                    COALESCE(sl.reviewed_at, '') AS reviewed_at,
                    COALESCE(p.model, '') AS model,
                    COALESCE(u.username, '') AS username
             FROM stock_log sl
             LEFT JOIN parts p ON p.id = sl.part_id
             LEFT JOIN users u ON u.id = sl.user_id
             WHERE {$whereSQL}
             ORDER BY sl.create_time DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$it) {
            $it['change_type_label'] = \App\Core\Enum\StockChangeType::getLabel((string)($it['change_type'] ?? ''));
        }
        unset($it);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_page' => $totalPage,
        ];
    }
}
