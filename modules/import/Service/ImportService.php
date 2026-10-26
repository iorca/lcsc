<?php
declare(strict_types=1);

namespace Modules\Import\Service;

use App\Core\Enum\ImportStatus;
use App\Helper\Parser\FieldRegistry;

/** ImportService — 导入主流程（分批处理 + 进度 + 批次回退） */
final class ImportService
{
    /** 每批处理行数上限 */
    public const CHUNK_SIZE = 150;

    /** 期望的进度采样点数（实际批数） */
    public const CHUNK_STEPS = 40;

    /** 单批最少行数（小文件摊薄时的下限，防止请求数被行数除法放大） */
    public const MIN_CHUNK_SIZE = 10;

    /** 行级认领超时秒数（超过后视为请求中断，行自动复位重跑） */
    public const CLAIM_TIMEOUT_SECONDS = 300;

    /** 自定义字段在列映射值里的前缀（形如 `ext:my_field`） */
    public const EXT_PREFIX = 'ext:';

    /** 自定义字段 key 归一（与 OrderMetaService::normalizeKey 同口径：仅 ASCII 字母数字下划线连字符） */
    public static function normalizeExtKey(string $key): string
    {
        return (string)preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($key)));
    }

    private \PDO $db;
    private int $uid;
    private int $dataUid;  // 数据归属（parts.user_id）
    private int $wsId;

    public function __construct(\PDO $db, int $uid, int $dataUid, int $wsId = 1)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
        $this->wsId    = $wsId;
    }

    /**
     * 批次收尾自动归类
     *
     * 给本批导入的物料按「品牌 / 商品类型 → 封装格式」建两级分类树并挂上，
     * 让导入完成后物料列表的「分类」列不再是空的。
     *
     * 失败一律不抛出：分类是附加价值，不能因为它让整次导入失败。
     */
    private function autoClassifyBatch(int $batchId): void
    {
        try {
            $st = $this->db->prepare(
                "SELECT DISTINCT part_id FROM import_batch_rows
                 WHERE batch_id=? AND part_id>0 AND action IN ('insert','update')
                 LIMIT 5000"
            );
            $st->execute([$batchId]);
            $ids = array_map(static fn(array $r): int => (int)$r['part_id'],
                             $st->fetchAll(\PDO::FETCH_ASSOC));
            if (empty($ids)) return;

            $svc = new \Modules\Category\Service\CategoryAutoClassifier($this->db, $this->dataUid);
            $res = $svc->classify($ids);
            \traceLog($this->uid, 'auto_classify', 'imported_file', $batchId,
                "自动归类 关联:{$res['linked']} 新建一级:{$res['new_top']} 新建二级:{$res['new_leaf']} 缺字段:{$res['no_value']}");
        } catch (\Throwable $e) {
            error_log('[ImportService] 自动归类失败: ' . $e->getMessage());
        }
    }

    // ══ ① 暂存 ═══════════════════════════════════════

    /** 建批次并入队 */
    public function stage(string $filePath, array $headers, array $columns, array $ignored, int $platformId, string $platformCode, string $fileName, bool $isSample, string $importMode = 'stock', int $documentId = 0, string $defaultCategory = ''): array
    {
        $importMode = ($importMode === 'profile') ? 'profile' : 'stock';
        $defaultCategory = trim($defaultCategory);
        $svc    = new ImportTemplateService($this->db, $this->dataUid, $this->wsId);
        $parser = new SheetTableParser();
        $parsed = $parser->parse($filePath, $svc->expectedHeaders($platformId));

        $rows = $parsed['rows'];
        $importId = bin2hex(random_bytes(16));

        // 批次主记录（status=processing，分批处理中）
        $this->db->prepare("INSERT INTO imported_files
            (user_id, operator, import_id, file_name, file_path, platform, template_id, is_sample, import_mode, total_rows, inserted, updated, skipped, errors, status, workspace_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,0,0,0,0,?,?)")
            ->execute([$this->dataUid, $this->uid, $importId, $fileName, $filePath, $platformCode, $platformId, $isSample ? 1 : 0, $importMode, count($rows), ImportStatus::PROCESSING, $this->wsId]);
        $batchId = (int)$this->db->lastInsertId();

        // 逐行归一化入队
        $insRow = $this->db->prepare("INSERT INTO import_batch_rows (batch_id, row_num, action, qty_change, payload_json, processed, raw_data) VALUES (?,?,?,?,?,?,?)");
        $insErr = $this->db->prepare("INSERT INTO import_errors (user_id, import_id, row_num, raw_data, reason, workspace_id) VALUES (?,?,?,?,?,?)");

        $queued = 0; $errs = 0; $skipped = 0;

        $insRowReason = $this->db->prepare("INSERT INTO import_batch_rows (batch_id, row_num, action, qty_change, payload_json, processed, raw_data, reason) VALUES (?,?,?,?,?,?,?,?)");

        $identityMapped = false;
        foreach ($columns as $f) {
            $f = FieldRegistry::canonicalField((string)$f);
            if ($f === 'platform_part_no' || $f === 'model') { $identityMapped = true; break; }
        }

        $keepRaw = (string)\getSetting('import_keep_raw', '0') === '1';
        if ($documentId > 0) {

            $this->db->prepare("DELETE FROM normalized_import_items WHERE document_id=?")->execute([$documentId]);
        }
        $insNorm = $documentId > 0
            ? $this->db->prepare("INSERT INTO normalized_import_items
                (workspace_id, document_id, `row_number`, part_number, name, brand, package, quantity, unit_price, parameters_text, category_name, location_name, description, order_no, supplier, match_status, user_confirmed, raw_data_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'', 'pending', 1, ?)")
            : null;
        $insRaw = ($keepRaw && $documentId > 0)
            ? $this->db->prepare("INSERT INTO import_raw_data (document_id, `row_number`, column_number, `value`) VALUES (?,?,0,?)")
            : null;
        $normCount = 0; $rawCount = 0;

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // 表头占第 1 行
            $payload = $this->normalizeRow((array)$row, $columns, $headers);
            $rawData = implode('|', array_slice(array_map('strval', (array)$row), 0, 8));

            // 默认分类：仅当行内没有分类值时套用（不猜，留空则不动）
            if ($defaultCategory !== '' && (string)$payload['product_type'] === '') {
                $payload['product_type'] = $defaultCategory;
            }

            $v = ImportValidator::validate($payload, isEnforceUnitPrice(), $importMode, $identityMapped);
            if (!empty($v['skip'])) {

                $insRowReason->execute([$batchId, $rowNum, 'skip', 0, '', 1, $rawData, '非物料行（无商品编号且无型号）']);
                $skipped++;
                continue;
            }
            if (!$v['ok']) {
                $insErr->execute([$this->uid, $importId, $rowNum, $rawData, $v['reason'], $this->wsId]);
                $insRowReason->execute([$batchId, $rowNum, 'error', 0, '', 1, $rawData, (string)$v['reason']]);
                $errs++;
                continue;
            }
            $payload = $v['payload'];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

            if ($insNorm) {
                $insNorm->execute([
                    $this->wsId, $documentId, $rowNum,
                    (string)$payload['platform_part_no'], (string)$payload['product_name'], (string)$payload['brand'],
                    (string)$payload['package'], (int)$payload['qty'], (float)$payload['unit_price'],
                    (string)$payload['parameters'], (string)$payload['product_type'], (string)$payload['location'],
                    (string)$payload['remark'], (string)$payload['order_no'], $payloadJson,
                ]);
                $normCount++;
            }

            if ($insRaw) {
                $insRaw->execute([$documentId, $rowNum, json_encode(array_values((array)$row), JSON_UNESCAPED_UNICODE)]);
                $rawCount++;
            }

            $insRow->execute([$batchId, $rowNum, '', 0, $payloadJson, 0, $rawData]);
            $queued++;
        }

        $this->db->prepare("UPDATE imported_files SET errors=?, skipped=? WHERE id=?")->execute([$errs, $skipped, $batchId]);

        return [
            'batch_id'     => $batchId,
            'import_id'    => $importId,
            'total'        => count($rows),
            'queued'       => $queued,
            'errors'       => $errs,
            'skipped'      => $skipped,
            'ignored_cols' => count($ignored),
            'normalized'   => $normCount,
            'raw_rows'     => $rawCount,
            'headers'      => $parsed['headers'],
            'sheet'        => $parsed['sheet_name'],
        ];
    }

    // ══ ② 分批处理 ══════════════════════════════════

    /** 处理一批待处理行 */
    public function chunk(int $batchId, int $limit = self::CHUNK_SIZE): array
    {
        $batch = $this->loadBatch($batchId);
        if (!$batch) throw new \RuntimeException('批次不存在');

        $this->recoverStaleClaims($batchId);

        // 批大小自适应：按剩余待处理行数摊成约 CHUNK_STEPS 批，

        $remaining = $this->pendingCount($batchId);
        if ($limit === self::CHUNK_SIZE && $remaining > 0) {
            $adaptive = (int)ceil($remaining / self::CHUNK_STEPS);
            $limit = max(self::MIN_CHUNK_SIZE, min(self::CHUNK_SIZE, $adaptive));
        }

        $limit = max(1, min(500, $limit));
        $stmt  = $this->db->prepare("SELECT * FROM import_batch_rows WHERE batch_id=? AND processed=0 ORDER BY id ASC LIMIT $limit");
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $importId = (string)$batch['import_id'];
        $importMode = ((string)($batch['import_mode'] ?? 'stock')) === 'profile' ? 'profile' : 'stock';

        $matchKeys = (new ImportTemplateService($this->db, $this->dataUid, $this->wsId))->matchKeys((int)($batch['template_id'] ?? 0));
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $claim = $this->db->prepare("UPDATE import_batch_rows SET processed=2, claimed_at=NOW() WHERE id=? AND processed=0");

        foreach ($rows as $r) {
            $rowId = (int)$r['id'];
            $claim->execute([$rowId]);
            if ($claim->rowCount() !== 1) continue;   // 已被其它请求认领

            $payload = json_decode((string)$r['payload_json'], true);
            if (!is_array($payload)) {
                $this->markRow($rowId, 'error', 0, 0, 0, 0, 0, '行数据解析失败');
                $this->logError($importId, (int)$r['row_num'], (string)$r['raw_data'], '行数据解析失败');
                $stats['errors']++;
                continue;
            }
            try {
                $res = $this->writeRow($payload, (string)$batch['platform'], (int)$batch['is_sample'] === 1, $importId, (int)$r['row_num'], (string)$r['raw_data'], $importMode, $matchKeys);
                if ($res['action'] === 'skip') {
                    $this->markRow($rowId, 'skip', 0, 0, 0, 0, 0, (string)($res['reason'] ?? ''));
                    $stats['skipped']++;
                } else {
                    $this->markRow($rowId, $res['action'], (int)$res['part_id'], (int)$res['qty_change'], (int)$res['qty_before'], (int)$res['qty_after'], (int)$res['stock_log_id'], (string)($res['reason'] ?? ''));
                    if ($res['action'] === 'insert') $stats['inserted']++; else $stats['updated']++;
                }
            } catch (\Throwable $e) {
                $this->markRow($rowId, 'error', 0, 0, 0, 0, 0, $e->getMessage());
                $this->logError($importId, (int)$r['row_num'], (string)$r['raw_data'], $e->getMessage());
                $stats['errors']++;
            }
        }

        // 累加计数
        $this->db->prepare("UPDATE imported_files
            SET inserted=inserted+?, updated=updated+?, skipped=skipped+?, errors=errors+?
            WHERE id=?")
            ->execute([$stats['inserted'], $stats['updated'], $stats['skipped'], $stats['errors'], $batchId]);

        $prog = $this->progress($batchId);

        $fresh = $this->loadBatch($batchId);
        if ($prog['done']) {

            $this->db->prepare("UPDATE imported_files SET status=? WHERE id=? AND status=?")
                ->execute([ImportStatus::DONE, $batchId, ImportStatus::PROCESSING]);

            // 批次收尾时**批量重算成本缓存**。

            \recalcDirtyCostBatch($this->db, 1000);

            // 批次收尾时**自动归类**：按「品牌 / 商品类型 → 封装格式」建两级分类树
            // 并挂到本批物料上（导入后分类列不再是空的）。
            $this->autoClassifyBatch($batchId);

            \traceLog($this->uid, 'import_order', 'imported_file', $batchId,
                "导入订单 文件:{$batch['file_name']} 平台:{$batch['platform']} 新增:{$fresh['inserted']} 更新:{$fresh['updated']} 跳过:{$fresh['skipped']} 错误:{$fresh['errors']}");
        }

        return array_merge($prog, [
            'inserted' => (int)$fresh['inserted'],
            'updated'  => (int)$fresh['updated'],
            'skipped'  => (int)$fresh['skipped'],
            'errors'   => (int)$fresh['errors'],
        ]);
    }

    /** 崩溃恢复：把「已认领但超时未完成」的行复位为待处理 */
    private function recoverStaleClaims(int $batchId): void
    {
        try {
            $sec = self::CLAIM_TIMEOUT_SECONDS;
            $this->db->prepare("UPDATE import_batch_rows SET processed=0, claimed_at=NULL
                                WHERE batch_id=? AND processed=2 AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL $sec SECOND))")
                ->execute([$batchId]);
        } catch (\Throwable $e) { /* 崩溃恢复失败不应阻断正常处理 */ }
    }

    /** 剩余待处理行数（processed=0；认领中的processed=2 不算，它已被别的请求占用） */
    private function pendingCount(int $batchId): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) AS c FROM import_batch_rows WHERE batch_id=? AND processed=0");
        $st->execute([$batchId]);
        return (int)(($st->fetch(\PDO::FETCH_ASSOC) ?: [])['c'] ?? 0);
    }

    /** 进度（processed/total，含已完成的 skip/error 行） */
    public function progress(int $batchId): array
    {
        $st = $this->db->prepare("SELECT COUNT(*) AS total, SUM(processed=1) AS done FROM import_batch_rows WHERE batch_id=?");
        $st->execute([$batchId]);
        $s = $st->fetch(\PDO::FETCH_ASSOC) ?: ['total' => 0, 'done' => 0];
        $total = (int)$s['total'];
        $done  = (int)$s['done'];
        return ['processed' => $done, 'total' => $total, 'done' => $total === 0 || $done >= $total, 'percent' => $total > 0 ? (int)round($done * 100 / $total) : 100];
    }

    /** 处理详情（汇总 + 失败明细） */
    public function batchDetail(int $batchId): array
    {
        $batch = $this->loadBatch($batchId);
        if (!$batch) throw new \RuntimeException('批次不存在');

        $errs = $this->db->prepare("SELECT row_num, raw_data, reason FROM import_errors WHERE import_id=? ORDER BY row_num ASC LIMIT 500");
        $errs->execute([(string)$batch['import_id']]);

        $act = $this->db->prepare("SELECT action, COUNT(*) AS c FROM import_batch_rows WHERE batch_id=? GROUP BY action");
        $act->execute([$batchId]);
        $byAction = [];
        foreach ($act->fetchAll(\PDO::FETCH_ASSOC) as $a) $byAction[(string)$a['action']] = (int)$a['c'];

        return [
            'batch'      => $batch,
            'progress'   => $this->progress($batchId),
            'by_action'  => $byAction,
            'errors'     => $errs->fetchAll(\PDO::FETCH_ASSOC),
            'can_rollback' => ImportStatus::isBatchRollbackable((string)$batch['status']) && empty($byAction['']),
        ];
    }

    /** 按处理结论分页查逐行明细（处理详情的四张卡片点击后调用） */
    public function batchRows(int $batchId, string $action, int $page = 1, int $perPage = 20): array
    {
        if (!$this->loadBatch($batchId)) throw new \RuntimeException('批次不存在');

        $allowed = ['insert', 'update', 'skip', 'error'];
        if (!in_array($action, $allowed, true)) {
            throw new \RuntimeException('未知的处理结论：' . $action);
        }
        $perPage = max(5, min(100, $perPage));

        $cnt = $this->db->prepare("SELECT COUNT(*) FROM import_batch_rows WHERE batch_id=? AND action=?");
        $cnt->execute([$batchId, $action]);
        $total = (int)$cnt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        $st = $this->db->prepare("SELECT id, row_num, part_id, qty_change, qty_before, qty_after, raw_data, reason
                                  FROM import_batch_rows WHERE batch_id=? AND action=? ORDER BY id ASC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
        $st->execute([$batchId, $action]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $ids = [];
        foreach ($rows as $r) { if ((int)$r['part_id'] > 0) $ids[(int)$r['part_id']] = true; }
        $names = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ps = $this->db->prepare("SELECT id, platform_part_no, model, product_name FROM parts WHERE id IN ({$in})");
            $ps->execute(array_keys($ids));
            foreach ($ps->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $names[(int)$p['id']] = trim(((string)($p['platform_part_no'] ?? '')) . ' ' . ((string)($p['model'] ?? '')));
                $names[(int)$p['id']] = trim(($names[(int)$p['id']] ?: '') . ' ' . mb_substr((string)($p['product_name'] ?? ''), 0, 40));
            }
        }
        foreach ($rows as &$r) {
            $r['part_label'] = (string)($names[(int)$r['part_id']] ?? '');
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages];
    }

    /** 批次列表（最近 N 条） */
    public function batchList(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $st = $this->db->prepare("SELECT id, import_id, file_name, platform, total_rows, inserted, updated, skipped, errors, status, is_sample, created_at, rolled_back_at
                                  FROM imported_files WHERE user_id=? AND workspace_id=? ORDER BY id DESC LIMIT $limit");
        $st->execute([$this->dataUid, $this->wsId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** 失败明细 CSV（BOM + UTF-8） */
    public function errorsCsv(int $batchId): string
    {
        $batch = $this->loadBatch($batchId);
        if (!$batch) throw new \RuntimeException('批次不存在');

        $st = $this->db->prepare("SELECT row_num, raw_data, reason FROM import_errors WHERE import_id=? ORDER BY row_num ASC");
        $st->execute([(string)$batch['import_id']]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $csvSafe = static fn($v) => (strpbrk((string)$v, ",\"\n\r") !== false) ? '"' . str_replace('"', '""', (string)$v) . '"' : (string)$v;
        $csv = "\xEF\xBB\xBF";
        $csv .= implode(',', ['行号', '原始数据', '失败原因']) . "\r\n";
        if (!$rows) {

            $csv .= implode(',', ['-', '-', '该批次没有失败明细（批次计数：' . (int)$batch['errors'] . ' 条）']) . "\r\n";
            return $csv;
        }
        foreach ($rows as $r) {
            $csv .= implode(',', array_map($csvSafe, [$r['row_num'], $r['raw_data'], $r['reason']])) . "\r\n";
        }
        return $csv;
    }

    // ══ ④ 批次回退 ═══════════════════════════════════

    /** 一键撤销批次：新增物料软删除 + 叠加库存补偿式扣回 + 反向流水留痕 */
    public function rollback(int $batchId): array
    {
        $batch = $this->loadBatch($batchId);
        if (!$batch) throw new \RuntimeException('批次不存在');

        $guard = $this->db->prepare("UPDATE imported_files SET status=? WHERE id=? AND status IN (?,?)");
        $guard->execute([ImportStatus::ROLLING_BACK, $batchId, ImportStatus::DONE, ImportStatus::PARTIAL_ROLLED_BACK]);
        if ($guard->rowCount() !== 1) {
            throw new \RuntimeException('该批次当前状态不允许撤销（可能已撤销或仍在处理中）');
        }

        $importId  = (string)$batch['import_id'];
        $deleted = 0; $restored = 0; $skipped = 0; $partial = 0;

        $st = $this->db->prepare("SELECT * FROM import_batch_rows WHERE batch_id=? AND action IN ('insert','update') AND undone=0 ORDER BY id ASC");
        $st->execute([$batchId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        $markUndone = $this->db->prepare("UPDATE import_batch_rows SET undone=1 WHERE id=? AND undone=0");

        foreach ($rows as $r) {
            $rowId  = (int)$r['id'];
            $partId = (int)$r['part_id'];
            $qtyChg = (int)$r['qty_change'];

            $markUndone->execute([$rowId]);
            if ($markUndone->rowCount() !== 1) { $skipped++; continue; } // 已被其它撤销流程处理

            try {
                if ((string)$r['action'] === 'insert') {
                    // 本次新建的物料 → 软删除
                    $del = $this->db->prepare("UPDATE parts SET status='deleted', update_time=NOW() WHERE id=? AND user_id=? AND workspace_id=? AND status!='deleted'");
                    $del->execute([$partId, $this->dataUid, $this->wsId]);
                    if ($del->rowCount() === 1) $deleted++; else $skipped++;
                    continue;
                }

                // 叠加行 → 补偿式扣回（保护导入后的合法出库，绝不为负）
                $cur = $this->db->prepare("SELECT stock FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
                $cur->execute([$partId, $this->dataUid, $this->wsId]);
                $stockNow = $cur->fetchColumn();
                if ($stockNow === false) { $skipped++; continue; }
                $stockNow = (int)$stockNow;
                if ($qtyChg <= 0) { $skipped++; continue; }

                $delta    = min($stockNow, $qtyChg);           // 实际可扣回数量
                $newStock = $stockNow - $delta;
                if ($delta < $qtyChg) $partial++;

                \applyStockDelta($partId, -$delta);

                \insertStockLog($this->db, [
                    'user_id'      => $this->uid,
                    'part_id'      => $partId,
                    'change_type'  => 'import_undo_out',
                    'qty_change'   => -$delta,
                    'qty_before'   => $stockNow,
                    'qty_after'    => $newStock,
                    'remark'       => '撤销导入批次:' . $importId,
                    'workspace_id' => $this->wsId,
                    'source_type'  => 'import_undo',
                    'import_batch' => $importId,
                ]);

                \markCostDirty($this->db, $partId);
                $restored++;
            } catch (\Throwable $e) {
                $skipped++;
                \error_log('import rollback row failed: ' . $e->getMessage());
            }
        }

        $status = $partial > 0 ? ImportStatus::PARTIAL_ROLLED_BACK : ImportStatus::ROLLED_BACK;
        $this->db->prepare("UPDATE imported_files SET status=?, rolled_back_at=NOW(), rolled_back_by=? WHERE id=?")
            ->execute([$status, $this->uid, $batchId]);

        $clean = $this->cleanBatchTraces($batchId);

        \traceLog($this->uid, 'import_rollback', 'imported_file', $batchId,
            "撤销导入批次:{$importId} 删除物料:{$deleted} 恢复库存:{$restored} 跳过:{$skipped}"
            . ($partial > 0 ? " 部分扣回:{$partial}" : '')
            . " 清理防重:{$clean['history']} 清理价格:{$clean['price']}");

        return [
            'status'          => $status,
            'deleted_parts'   => $deleted,
            'restored_parts'  => $restored,
            'skipped'         => $skipped,
            'partial'         => $partial,
            'cleaned_history' => $clean['history'],
            'cleaned_price'   => $clean['price'],
        ];
    }

    // ══ 内部：行归一化与写库 ═════════════════════════

    /** 撤销后的痕迹清理（D6） */
    private function cleanBatchTraces(int $batchId): array
    {
        $out = ['history' => 0, 'price' => 0];
        try {

            $st = $this->db->prepare("SELECT part_id, payload_json FROM import_batch_rows WHERE batch_id=? AND action IN ('insert','update')");
            $st->execute([$batchId]);
            $pairs = []; $pricePairs = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['payload_json'], true);
                if (!is_array($p)) continue;
                $orderNo = (string)($p['order_no'] ?? '');
                $partNo  = (string)($p['platform_part_no'] ?? '');
                $partId  = (int)$r['part_id'];
                if ($orderNo !== '' && $partNo !== '') $pairs[$orderNo . "\x00" . $partNo] = [$orderNo, $partNo];
                if ($partId > 0) $pricePairs[$partId . "\x00" . $orderNo] = [$partId, $orderNo];
            }
            $delHist = $this->db->prepare("DELETE FROM import_history WHERE user_id=? AND workspace_id=? AND order_no=? AND platform_part_no=?");
            foreach ($pairs as [$orderNo, $partNo]) {
                $delHist->execute([$this->dataUid, $this->wsId, $orderNo, $partNo]);
                $out['history'] += $delHist->rowCount();
            }

            $delPh = $this->db->prepare("DELETE FROM price_history WHERE user_id=? AND workspace_id=? AND part_id=? AND order_no=?");
            $touched = [];
            foreach ($pricePairs as [$partId, $orderNo]) {
                $delPh->execute([$this->dataUid, $this->wsId, $partId, $orderNo]);
                $out['price'] += $delPh->rowCount();
                $touched[$partId] = true;
            }
            foreach (array_keys($touched) as $pid) \markCostDirty($this->db, (int)$pid);
        } catch (\Throwable $e) {
            \error_log('import rollback cleanup failed: ' . $e->getMessage());
        }
        return $out;
    }

    /** 按列映射把原始行归一化为系统字段（未映射字段为空） */
    private function normalizeRow(array $row, array $columns, array $headers = []): array
    {
        $out = [
            'platform_part_no' => '', 'model' => '', 'product_name' => '', 'product_type' => '',
            'package' => '', 'brand' => '', 'customer_part_no' => '', 'order_no' => '',
            'order_time' => '', 'remark' => '', 'location' => '', 'parameters' => '',
            'qty' => 0, 'qty_raw' => '', 'unit_price' => 0.0,

            'unit' => '',

            'datasheet' => '', 'image' => '',
            'order_status' => '', 'receiver' => '', 'customer_rep' => '',
            'ship_date' => '', 'express_company' => '', 'express_no' => '', 'sign_date' => '',
            'invoice_no' => '', 'invoice_time' => '',
            'origin_order_no' => '', 'bom_flag' => '', 'contract_no' => '',

            'ext' => [],
        ];
        foreach ($columns as $idx => $field) {
            $field = (string)$field;
            $val = trim((string)($row[(int)$idx] ?? ''));
            if ($val === '') continue;

            if (str_starts_with($field, self::EXT_PREFIX)) {
                $key = self::normalizeExtKey(substr($field, strlen(self::EXT_PREFIX)));
                if ($key !== '') $out['ext'][$key] = mb_substr($val, 0, 2000);
                continue;
            }

            $field = FieldRegistry::canonicalField($field, (string)($headers[(int)$idx] ?? ''));
            if ($field === '' || !FieldRegistry::isWritableField($field)) continue;

            switch ($field) {
                case 'quantity':
                    $out['qty_raw'] = $val;
                    $out['qty']     = (int)preg_replace('/[^0-9\-]/', '', $val);
                    break;
                case 'unit_price':
                    $out['unit_price'] = (float)preg_replace('/[￥¥,\s]/u', '', $val);
                    break;
                default:
                    $out[$field] = $val;
            }
        }
        if ($out['qty'] === 0 && $out['qty_raw'] === '') $out['qty'] = 1; // 数量列缺失时默认 1
        return $out;
    }

    /** 写入单行 */
    private function writeRow(array $p, string $platformCode, bool $isSample, string $importId, int $rowNum, string $rawData, string $importMode = 'stock', array $matchKeys = []): array
    {
        $partNo  = (string)$p['platform_part_no'];
        $model   = (string)$p['model'];
        $qty     = (int)$p['qty'];
        $orderNo = (string)$p['order_no'];
        $ptype   = (string)$p['product_type'];

        $v = ImportValidator::validate($p, isEnforceUnitPrice(), $importMode);
        if (!empty($v['skip'])) {
            // 非物料行（stage 已过滤，此处为兜底）：按跳过处理，不计失败
            return ['action' => 'skip', 'part_id' => 0, 'qty_change' => 0, 'qty_before' => 0, 'qty_after' => 0, 'stock_log_id' => 0, 'reason' => '非物料行（无商品编号且无型号）'];
        }
        if (!$v['ok']) throw new \RuntimeException($v['reason']);
        $p = $v['payload'];
        $price = (float)$p['unit_price'];

        $isProfile = ($importMode === 'profile');
        if (empty($matchKeys)) $matchKeys = ImportTemplateService::DEFAULT_MATCH_KEYS;

        // 平台 ID：按批次里记的平台 code 反查（兼容 workspace）

        $pl = $this->db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=? AND workspace_id=? LIMIT 1");
        $pl->execute([$platformCode, $this->dataUid, $this->wsId]);
        $platId = (int)$pl->fetchColumn();
        if ($platId <= 0) {
            throw new \RuntimeException('目标平台不存在或不可用（平台代码：' . $platformCode . '），本行未写入；请到「平台管理」确认后再导入');
        }

        // 下单时间
        $otDb = null;
        if ((string)$p['order_time'] !== '') {
            $ts = strtotime((string)$p['order_time']);
            if ($ts) $otDb = date('Y-m-d H:i:s', $ts);
        }

        $this->saveOrderMeta($p, $platId, $platformCode);

        // 防重复（同订单 + 同编号）：仅采购入库适用
        if (!$isProfile && $orderNo !== '' && $partNo !== '') {
            $dup = $this->db->prepare("SELECT id FROM import_history WHERE user_id=? AND order_no=? AND platform_part_no=? AND workspace_id=?");
            $dup->execute([$this->dataUid, $orderNo, $partNo, $this->wsId]);
            if ($dup->fetch()) {
                // 重复行不重复计入库存；但补空类主档字段（如后绑定的商品单位）仍要补，

                $this->backfillPartExtras($p, $matchKeys, $platId);
                return ['action' => 'skip', 'part_id' => 0, 'qty_change' => 0, 'qty_before' => 0, 'qty_after' => 0, 'stock_log_id' => 0, 'reason' => '重复行（同订单 + 同商品编号已导入过）'];
            }
        }

        $existing = $this->locateByMatchKeys($p, $matchKeys, $platId);

        $subtotal = ($price > 0 && !$isSample) ? round($qty * $price, 4) : 0;

        $partNoDb = $partNo !== '' ? $partNo : null;

        if ($existing && $isProfile) {
            // 建档模式命中既有物料：只补空字段，不覆盖用户已维护的值
            $pid    = (int)$existing['id'];
            $filled = $this->fillEmptyFields($pid, $p);
            if ($ptype !== '') \linkCategories($pid, $this->dataUid, \parseCategories($ptype));
            return ['action' => $filled > 0 ? 'update' : 'skip', 'part_id' => $pid, 'qty_change' => 0, 'qty_before' => 0, 'qty_after' => 0, 'stock_log_id' => 0,
                    'reason' => $filled > 0 ? '' : '物料已存在，且没有可补充的空字段'];
        }

        if ($existing) {
            $before   = (int)$existing['stock'];
            $newStock = $before + $qty;
            \applyStockDelta((int)$existing['id'], $qty);
            $pid = (int)$existing['id'];
            $action = 'update';
        } else {
            $mx = $this->db->prepare("SELECT COALESCE(MAX(internal_id),0) FROM parts WHERE user_id=? AND workspace_id=?");
            $mx->execute([$this->dataUid, $this->wsId]);
            $nextInternalId = (int)$mx->fetchColumn() + 1;

            $this->db->prepare("INSERT INTO parts
                (user_id, platform_id, internal_id, platform_part_no, customer_part_no, model, product_name, product_type, package, brand, unit, stock, low_stock_threshold, location, remark, parameters, parameters_text, datasheet, image, workspace_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?)")
                ->execute([$this->dataUid, $platId, $nextInternalId, $partNoDb, (string)$p['customer_part_no'], $model,
                           (string)$p['product_name'], $ptype, (string)$p['package'], (string)$p['brand'], (string)$p['unit'], $isProfile ? 0 : $qty,
                           (string)$p['location'], (string)$p['remark'], (string)$p['parameters'],
                           $this->buildParametersText((string)$p['parameters'], (string)$p['product_name'], $ptype, (string)$p['package'], (string)$model),
                           (string)$p['datasheet'], (string)$p['image'], $this->wsId]);
            $pid    = (int)$this->db->lastInsertId();
            $before = 0;
            $newStock = $isProfile ? 0 : $qty;
            $action = 'insert';

            \ensureStockRecord($pid, 0);
        }

        if ($isProfile) {
            if ($ptype !== '') \linkCategories($pid, $this->dataUid, \parseCategories($ptype));
            return ['action' => $action, 'part_id' => $pid, 'qty_change' => 0, 'qty_before' => 0, 'qty_after' => 0, 'stock_log_id' => 0, 'reason' => ''];
        }

        // 流水（带批次号，供回退与追踪）
        $logId = \insertStockLog($this->db, [
            'user_id'          => $this->uid,
            'part_id'          => $pid,
            'platform_part_no' => $partNo,
            'change_type'      => 'import',
            'qty_change'       => $qty,
            'qty_before'       => $before,
            'qty_after'        => $newStock,
            'unit_cost'        => $price,
            'is_sample'        => $isSample ? 1 : 0,
            'subtotal'         => $subtotal,
            'order_time'       => $otDb,
            'remark'           => '订单:' . $orderNo,
            'workspace_id'     => $this->wsId,
            'source_type'      => 'import',
            'import_batch'     => $importId,
        ]);

        if ($ptype !== '') \linkCategories($pid, $this->dataUid, \parseCategories($ptype));
        if ($price > 0) {
            $this->db->prepare("INSERT INTO price_history (user_id, part_id, platform_part_no, order_no, unit_price, qty, order_time, workspace_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$this->dataUid, $pid, $partNo, $orderNo, $price, $qty, $otDb, $this->wsId]);
            $this->db->prepare("UPDATE parts SET last_purchase_price=? WHERE id=?")->execute([round($price, 4), $pid]);
        }
        \markCostDirty($this->db, $pid);
        if ($orderNo !== '' && $partNo !== '') {
            $this->db->prepare("INSERT IGNORE INTO import_history (user_id, order_no, platform_part_no, workspace_id) VALUES (?,?,?,?)")
                ->execute([$this->dataUid, $orderNo, $partNo, $this->wsId]);
        }

        return ['action' => $action, 'part_id' => $pid, 'qty_change' => $qty, 'qty_before' => $before, 'qty_after' => $newStock, 'stock_log_id' => $logId, 'reason' => ''];
    }

    /** 按模板匹配键定位既有物料（顺序即优先级，命中第一个即有结论） */
    private function locateByMatchKeys(array $p, array $matchKeys, int $platId): ?array
    {
        foreach ($matchKeys as $key) {
            $val = trim((string)($p[$key] ?? ''));
            if ($val === '') continue;

            if ($key === 'model') {
                $st = $this->db->prepare("SELECT id, stock, status FROM parts WHERE user_id=? AND workspace_id=? AND model=? AND status!='deleted' LIMIT 1");
                $st->execute([$this->dataUid, $this->wsId, $val]);
                $hit = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                if (!$hit) {

                    $norm = PartMatcher::normalizeModel($val);
                    if ($norm !== $val) {
                        $st = $this->db->prepare("SELECT id, stock, status FROM parts
                            WHERE user_id=? AND workspace_id=? AND status!='deleted'
                              AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(model,'-',''),'_',''),'.',''),' ','')) = ?
                            LIMIT 1");
                        $st->execute([$this->dataUid, $this->wsId, $norm]);
                        $hit = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                    }
                }
                if ($hit) return $hit;
                continue;
            }

            // 平台编号/客户料号等：同平台范围内精确匹配（编号跨平台同号属不同物料）
            if ($key === 'platform_part_no') {
                $st = $this->db->prepare("SELECT id, stock, status FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? AND workspace_id=? LIMIT 1");
                $st->execute([$this->dataUid, $platId, $val, $this->wsId]);
            } else {
                $st = $this->db->prepare("SELECT id, stock, status FROM parts WHERE user_id=? AND workspace_id=? AND `$key`=? AND status!='deleted' LIMIT 1");
                $st->execute([$this->dataUid, $this->wsId, $val]);
            }
            $hit = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($hit) {
                if ((string)($hit['status'] ?? '') === 'deleted') {
                    throw new \RuntimeException('该物料已存在于回收站（已删除物料），请先恢复或永久删除后再导入');
                }
                return $hit;
            }
        }
        return null;
    }

    /** 建档模式：只补空字段，不覆盖用户已维护的值 */
    private function fillEmptyFields(int $partId, array $p): int
    {
        // 可补字段（与 parts 真实列一致；编号/型号是身份键，不在此补）
        $fields = ['product_name', 'product_type', 'package', 'brand', 'unit', 'customer_part_no', 'location', 'remark', 'parameters', 'parameters_text', 'datasheet', 'image'];
        // 参数文本若为空，用规范化的参数文本补（复用 SpecNormalizer 结果）

        $p['parameters_text'] = $this->buildParametersText(
            (string)($p['parameters'] ?? ''),
            (string)($p['product_name'] ?? ''),
            (string)($p['product_type'] ?? ''),
            (string)($p['package'] ?? ''),
            (string)($p['model'] ?? '')
        );
        $sets = []; $args = [];
        foreach ($fields as $f) {
            $val = trim((string)($p[$f] ?? ''));
            if ($val === '') continue;
            $sets[] = "`$f` = CASE WHEN `$f` IS NULL OR `$f` = '' THEN ? ELSE `$f` END";
            $args[] = $val;
        }
        if (empty($sets)) return 0;

        $old = $this->db->prepare("SELECT " . implode(',', array_map(static fn($f) => "`$f`", $fields)) . " FROM parts WHERE id=? AND user_id=? AND workspace_id=?");
        $old->execute([$partId, $this->dataUid, $this->wsId]);
        $before = $old->fetch(\PDO::FETCH_ASSOC) ?: [];
        $filled = 0;
        foreach ($fields as $f) {
            $val = trim((string)($p[$f] ?? ''));
            if ($val !== '' && trim((string)($before[$f] ?? '')) === '') $filled++;
        }
        if ($filled === 0) return 0;

        $args[] = $partId; $args[] = $this->dataUid; $args[] = $this->wsId;
        $this->db->prepare("UPDATE parts SET " . implode(', ', $sets) . ", update_time=NOW() WHERE id=? AND user_id=? AND workspace_id=?")
            ->execute($args);
        return $filled;
    }

    /** 重复行（同订单+同编号已导入过）的「主档补空」：只补 parts 的空字段（当前仅商品单位）， */
    private function backfillPartExtras(array $p, array $matchKeys, int $platId): void
    {
        $unit = trim((string)($p['unit'] ?? ''));
        if ($unit === '') return;
        $existing = $this->locateByMatchKeys($p, $matchKeys, $platId);
        if (!$existing) return;
        try {
            $this->db->prepare("UPDATE parts SET unit=?, update_time=NOW() WHERE id=? AND user_id=? AND workspace_id=? AND (unit IS NULL OR unit='')")
                ->execute([mb_substr($unit, 0, 20), (int)$existing['id'], $this->dataUid, $this->wsId]);
        } catch (\Throwable $e) {
            \error_log('import backfill unit failed: ' . $e->getMessage());
        }
    }

    /** 写入/更新订单级扩展信息（order_meta） */
    private function saveOrderMeta(array $p, int $platId, string $platformCode): void
    {
        $orderNo = trim((string)($p['order_no'] ?? ''));
        if ($orderNo === '') return;

        $map = [
            'order_status' => 'order_status', 'receiver' => 'receiver', 'customer_rep' => 'customer_rep',
            'ship_date' => 'ship_date', 'express_company' => 'express_company', 'express_no' => 'express_no',
            'sign_date' => 'sign_date', 'invoice_no' => 'invoice_no', 'invoice_time' => 'invoice_time',
            'origin_order_no' => 'origin_order_no', 'bom_flag' => 'bom_flag', 'contract_no' => 'contract_no',
        ];
        $cols = []; $vals = [];
        foreach ($map as $field => $col) {
            $val = trim((string)($p[$field] ?? ''));
            if ($val === '') continue;
            $cols[] = $col;
            $vals[] = mb_substr($val, 0, 100);
        }
        // 用户自定义字段（order_meta_ext）：与固定列一并归集到同一订单
        $ext = (array)($p['ext'] ?? []);
        $ext = array_filter($ext, static fn($v) => trim((string)$v) !== '');
        if (empty($cols) && empty($ext)) return;   // 未绑定任何订单级字段：不产生空行

        try {
            $insCols = array_merge(['workspace_id', 'user_id', 'operator_id', 'platform_id', 'platform_code', 'order_no'], $cols);
            $args    = array_merge([$this->wsId, $this->dataUid, $this->uid, $platId, $platformCode, mb_substr($orderNo, 0, 100)], $vals);
            // 冲突时仅更新本次带值的列（CASE 保证不被空值覆盖）
            $updates = [];
            foreach ($cols as $c) { $updates[] = "`{$c}` = CASE WHEN VALUES(`{$c}`) <> '' THEN VALUES(`{$c}`) ELSE `{$c}` END"; }
            $sql = "INSERT INTO order_meta (`" . implode('`,`', $insCols) . "`) VALUES (" . rtrim(str_repeat('?,', count($args)), ',') . ")";

            if ($updates) {
                $updates[] = 'updated_time = NOW()';
                $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
            } else {
                $sql .= ' ON DUPLICATE KEY UPDATE updated_time = updated_time';
            }
            $this->db->prepare($sql)->execute($args);

            if (!empty($ext)) $this->writeOrderMetaExt($orderNo, $ext);
        } catch (\Throwable $e) {
            // 订单扩展信息失败不能中断整批导入
            \error_log('import saveOrderMeta failed: ' . $e->getMessage() . ' (order_no=' . $orderNo . ')');
        }
    }

    /** 写订单自定义字段值（order_meta_ext） */
    private function writeOrderMetaExt(string $orderNo, array $ext): void
    {
        $svc = new OrderMetaService($this->db, $this->uid, $this->dataUid, $this->wsId);
        $st = $this->db->prepare("SELECT id FROM order_meta WHERE user_id=? AND workspace_id=? AND order_no=? LIMIT 1");
        $st->execute([$this->dataUid, $this->wsId, mb_substr($orderNo, 0, 100)]);
        $metaId = (int)($st->fetchColumn() ?: 0);
        if ($metaId <= 0) return;

        foreach ($ext as $key => $val) {
            $key = self::normalizeExtKey((string)$key);
            if ($key === '') continue;
            $svc->setExtValue($metaId, $key, (string)$val);
        }
    }

    /** 用替代料引擎的 SpecNormalizer 生成规范化参数文本（不恢复已删的硬编码分类表） */
    private function buildParametersText(string $raw, string $productName, string $ptype, string $package, string $model = ''): string
    {
        return \App\Helper\Spec\SpecNormalizer::buildText([
            'parameters'   => $raw,
            'model'        => $model,
            'product_name' => $productName,
            'category'     => $ptype,
            'package'      => $package,
        ]);
    }

    /** 更新逐行留痕 */
    private function markRow(int $rowId, string $action, int $partId, int $qtyChange, int $qtyBefore, int $qtyAfter, int $stockLogId = 0, string $reason = ''): void
    {
        $this->db->prepare("UPDATE import_batch_rows SET action=?, part_id=?, qty_change=?, qty_before=?, qty_after=?, stock_log_id=?, processed=1, reason=? WHERE id=?")
            ->execute([$action, $partId, $qtyChange, $qtyBefore, $qtyAfter, $stockLogId, mb_substr($reason, 0, 250), $rowId]);
    }

    /** 记失败明细 */
    private function logError(string $importId, int $rowNum, string $rawData, string $reason): void
    {
        try {
            $this->db->prepare("INSERT INTO import_errors (user_id, import_id, row_num, raw_data, reason, workspace_id) VALUES (?,?,?,?,?,?)")
                ->execute([$this->uid, $importId, $rowNum, mb_substr($rawData, 0, 500), mb_substr($reason, 0, 400), $this->wsId]);
        } catch (\Throwable $e) {

            \error_log('import logError failed: ' . $e->getMessage() . ' (import_id=' . $importId . ' row=' . $rowNum . ')');
        }
    }

    /** 读取批次 */
    private function loadBatch(int $batchId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM imported_files WHERE id=? AND user_id=? LIMIT 1");
        $st->execute([$batchId, $this->dataUid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
