<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use App\Core\Enum\ImportStatus;
use App\Helper\Parser\FieldIdentifier;
use App\Helper\Parser\FieldRegistry;
use Modules\Import\Service\ImportService;
use Modules\Import\Service\OrderMetaService;
use Modules\Import\Service\ImportTemplateService;
use Modules\Import\Service\PartMatcher;
use Modules\Import\Service\SheetTableParser;

/** 导入 API 路由 */
final class ImportRoutes
{
    /** 允许的导入文件扩展名 */
    private const ALLOWED_EXTS = ['csv', 'tsv', 'xlsx', 'xls'];

    public static function handle(string $method, array $user): void
    {
        $db          = \getDB();
        $uid         = (int)($user['id'] ?? 0);
        $dataUid     = \getDataUserId();
        $workspaceId = \getWorkspaceId();

        if (!\hasPermission('can_import')) {
            Response::error('当前账号无导入权限', 403);
        }

        try {
            switch ($method) {

                // ─ 模板化导入流程（新版向导）──
                case 'stage':
                    self::handleStage($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'chunk':
                    self::handleChunk($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'batch_list':
                    self::handleBatchList($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'batch_detail':
                    self::handleBatchDetail($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'batch_rows':
                    self::handleBatchRows($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'custom_fields':
                    self::handleCustomFields($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'errors_export':
                    self::handleErrorsExport($db, $uid, $dataUid, $workspaceId);
                    break;

                case 'rollback':
                    self::handleRollback($db, $uid, $dataUid, $workspaceId);
                    break;

                // ── 旧 action 兼容别名 ──
                case 'upload':
                    self::handleUpload($db, $workspaceId);
                    break;

                case 'parse':
                    self::handleParse($db, $workspaceId);
                    break;

                case 'recognize':
                    self::handleRecognize($db, $workspaceId);
                    break;

                case 'match':
                    self::handleMatch($db, $dataUid, $workspaceId);
                    break;

                case 'documents':
                    self::handleDocuments($db, $workspaceId);
                    break;

                case 'document_detail':
                    self::handleDocumentDetail($db, $workspaceId);
                    break;

                case 'learn_mapping':
                    self::handleLearnMapping($db, $workspaceId);
                    break;

                // ─ 平台导入模板 ──
                case 'template_get':
                    self::handleTemplateGet($db, $workspaceId);
                    break;

                case 'template_status':
                    self::handleTemplateStatus($db, $workspaceId);
                    break;

                case 'template_parse':
                    self::handleTemplateParse($db, $workspaceId);
                    break;

                case 'template_save':
                    self::handleTemplateSave($db, $workspaceId);
                    break;

                case 'template_reset':
                    self::handleTemplateReset($db, $workspaceId);
                    break;

                case 'template_delete':
                    self::handleTemplateDelete($db, $workspaceId);
                    break;

                case 'normalize':
                    self::mergedNotice('数据标准化已合并至「解析」与「确认导入」步骤，无需单独调用');
                    break;

                case 'confirm':
                    self::mergedNotice('确认导入已迁移到新版流程（上传 → 映射确认 → 确认导入 → 分批处理）');
                    break;

                case 'confirm_row':
                    self::mergedNotice('单行确认已合并至「映射确认」步骤');
                    break;

                default:
                    Response::error('未知 import 方法：' . $method, 404);
            }
        } catch (\Throwable $e) {
            \error_log('ImportRoutes error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            Response::error($e->getMessage(), 1);
        }
    }

    /** upload — 保存上传文件并登记文档 */
    private static function handleUpload(\PDO $db, int $workspaceId): void
    {
        if (empty($_FILES['file'])) {
            Response::error('未上传文件', 4);
        }
        $file     = $_FILES['file'];
        $filename = \basename((string)$file['name']);
        $ext      = \strtolower((string)\pathinfo($filename, PATHINFO_EXTENSION));

        if (!\in_array($ext, self::ALLOWED_EXTS, true)) {
            Response::error('不支持的文件格式，仅允许 ' . \implode(' / ', self::ALLOWED_EXTS), 4);
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            Response::error('文件上传失败（错误码：' . (int)$file['error'] . '）', 4);
        }

        $path = self::storeUploadedFile($file, $ext);
        $hash = (string)@\md5_file($path);
        $docId = self::registerDocument($db, $workspaceId, $filename, \safeStr($_POST['source_type'] ?? 'custom'), $hash, $path);

        Response::ok([
            'document_id' => $docId,
            'doc_token'   => $docId,
            'filename'    => $filename,
            'source_type' => \safeStr($_POST['source_type'] ?? 'custom'),
        ]);
    }

    /** parse — 解析文件（表头 + 预览行），不落业务数据 */
    private static function handleParse(\PDO $db, int $workspaceId): void
    {
        $documentId = \safePosInt($_POST['document_id'] ?? 0, 0);

        if ($documentId <= 0) {
            $file = $_FILES['file'] ?? $_FILES['excel'] ?? null;
            if (empty($file)) {
                Response::error('缺少 document_id 或上传文件', 4);
            }
            $filename = \basename((string)$file['name']);
            $ext      = \strtolower((string)\pathinfo($filename, PATHINFO_EXTENSION));
            if (!\in_array($ext, self::ALLOWED_EXTS, true)) {
                Response::error('不支持的文件格式，仅允许 ' . \implode(' / ', self::ALLOWED_EXTS), 4);
            }
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                Response::error('文件上传失败（错误码：' . (int)$file['error'] . '）', 4);
            }
            $path       = self::storeUploadedFile($file, $ext);
            $hash       = (string)@\md5_file($path);
            $documentId = self::registerDocument($db, $workspaceId, $filename, \safeStr($_POST['source_type'] ?? 'custom'), $hash, $path);
        }

        $doc = self::loadDocument($db, $documentId, $workspaceId);
        if (!$doc) {
            Response::error('文档不存在', 404);
        }

        $filePath = (string)($doc['file_path'] ?? '');
        if ($filePath === '' || !\is_file($filePath)) {
            $filePath = self::findUploadedFile((string)$doc['filename']);
        }
        if ($filePath === '' || !\is_file($filePath)) {
            Response::error('上传文件已丢失，请重新上传', 4);
        }

        $parser = new SheetTableParser();
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        $expected   = [];
        if ($platformId > 0) {
            try {
                $expected = (new ImportTemplateService($db, \getDataUserId(), $workspaceId))->expectedHeaders($platformId);
            } catch (\Throwable $e) { $expected = []; }
        }
        $parsed = $parser->parse($filePath, $expected);

        $resp = [
            'document_id' => $documentId,
            'doc_token'   => $documentId,
            'headers'     => $parsed['headers'],
            'headers_sig' => ImportTemplateService::sig($parsed['headers']),
            'preview'     => \array_slice($parsed['rows'], 0, 20),
            'total_rows'  => (int)$parsed['total'],
            'sheet_name'  => $parsed['sheet_name'],
        ];

        // 附带模板识别建议（向导的映射确认步骤直接用）
        if ($platformId > 0) {
            try {
                $tplSvc = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
                $resp['detected']   = self::blankEmptyColumns($tplSvc->detect($platformId, $parsed['headers'], \safeStr($_POST['platform_code'] ?? 'custom')), $parsed['headers'], $parsed['rows']);
                $resp['fields']     = ImportTemplateService::fieldLabels();
                $resp['has_template'] = $tplSvc->hasTemplate($platformId);
            } catch (\Throwable $e) { /* 识别失败不影响解析结果 */ }
        }

        Response::ok($resp);
    }

    /** recognize — 字段识别（用户规则 → 系统关键词 → 格式推断） */
    private static function handleRecognize(\PDO $db, int $workspaceId): void
    {
        $headers    = \json_decode((string)($_POST['headers'] ?? '[]'), true);
        $sourceType = \safeStr($_POST['source_type'] ?? 'custom');
        if (!\is_array($headers) || empty($headers)) {
            Response::error('缺少 headers', 4);
        }

        $identifier   = new FieldIdentifier($db, $workspaceId);
        $recognitions = $identifier->recognizeHeaders($headers, $sourceType);

        // 兼容附带 document_id：落库映射明细（表存在时才写）
        $documentId = \safePosInt($_POST['document_id'] ?? 0, 0);
        if ($documentId > 0) {
            $identifier->saveMapping($documentId, $recognitions);
        }

        Response::ok([
            'document_id'  => $documentId,
            'recognitions' => $recognitions,
        ]);
    }

    /** match — 物料匹配（供外部对接与测试使用） */
    private static function handleMatch(\PDO $db, int $dataUid, int $workspaceId): void
    {
        $items = \json_decode((string)($_POST['items'] ?? $_POST['rows'] ?? '[]'), true);
        if (!\is_array($items) || empty($items)) {
            Response::error('缺少 items（待匹配的标准化物料数组）', 4);
        }

        $matcher = new PartMatcher($db, $dataUid, $workspaceId);
        $results = [];
        $summary = ['matched' => 0, 'conflict' => 0, 'pending' => 0];

        foreach ($items as $item) {
            $r = $matcher->match((array)$item);
            $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1;
            $results[] = $r;
        }

        Response::ok(['results' => $results, 'summary' => $summary]);
    }

    /** documents — 文档列表 */
    private static function handleDocuments(\PDO $db, int $workspaceId): void
    {
        try {
            $stmt = $db->prepare("SELECT id, filename, source_type, upload_time, status
                                  FROM import_document WHERE workspace_id = ? ORDER BY id DESC LIMIT 50");
            $stmt->execute([$workspaceId]);
            Response::ok(['documents' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            Response::ok(['documents' => []]);
        }
    }

    /** document_detail — 文档详情 */
    private static function handleDocumentDetail(\PDO $db, int $workspaceId): void
    {
        $documentId = \safePosInt($_GET['document_id'] ?? $_POST['document_id'] ?? 0, 0);
        if ($documentId <= 0) {
            Response::error('缺少 document_id', 4);
        }

        $doc = self::loadDocument($db, $documentId, $workspaceId);
        if (!$doc) {
            Response::error('文档不存在', 404);
        }

        $mapping = [];
        try {
            $stmt = $db->prepare("SELECT source_column, source_header, target_field, confidence, match_rule
                                  FROM import_mapping WHERE document_id = ? ORDER BY source_column ASC");
            $stmt->execute([$documentId]);
            $mapping = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { /* 表不存在时忽略 */ }

        Response::ok(['document' => $doc, 'mapping' => $mapping]);
    }

    /** learn_mapping — 记录用户字段映射修正（供后续自动识别复用） */
    private static function handleLearnMapping(\PDO $db, int $workspaceId): void
    {
        $sourceType   = \safeStr($_POST['source_type'] ?? '');
        $sourceColumn = \safeStr($_POST['source_column'] ?? '');
        $targetField  = \safeStr($_POST['target_field'] ?? '');
        if ($sourceType === '' || $sourceColumn === '' || $targetField === '') {
            Response::error('缺少 source_type / source_column / target_field', 4);
        }

        (new FieldIdentifier($db, $workspaceId))->learnUserRule($sourceType, $sourceColumn, $targetField);
        Response::ok(['learned' => true]);
    }

    /** 已合并步骤的提示响应（返回成功，避免外部调用方拿到 5xx） */
    private static function mergedNotice(string $msg): void
    {
        Response::ok(['merged' => true, 'message' => $msg]);
    }

    // ── 平台导入模板 ────────────────────────────────

    /** template_get — 读取某平台的模板与可映射字段 */
    private static function handleTemplateGet(\PDO $db, int $workspaceId): void
    {
        $platformId = \safePosInt($_GET['platform_id'] ?? $_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);

        $svc = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        $tpl = $svc->get($platformId);

        Response::ok([

            'has_template' => $tpl !== null && !empty($tpl['profiles']),
            'template_id'  => $tpl ? (int)$tpl['id'] : 0,
            'is_builtin'   => $tpl ? (int)$tpl['is_builtin'] === 1 : false,
            'revision'     => $tpl ? (int)$tpl['revision'] : 0,
            'profiles'     => $tpl['profiles'] ?? [],
            'match_keys'   => $svc->matchKeys($platformId),
            'fields'       => ImportTemplateService::fieldLabels(),
            'match_key_options' => self::matchKeyOptions(),
        ]);
    }

    /** 匹配键可选项（供模板弹窗多选/排序；含推荐说明） */
    private static function matchKeyOptions(): array
    {
        return [
            ['field' => 'platform_part_no', 'label' => '商品编号（平台编号）', 'hint' => '同平台同编号 = 同一物料（推荐首选）'],
            ['field' => 'model',            'label' => '型号',                 'hint' => '型号等价比较（去分隔符/大写），适合无编号的文件'],
            ['field' => 'customer_part_no', 'label' => '客户料号',             'hint' => '以自有料号为准的内部编号体系'],
        ];
    }

    /** template_status — 各平台模板状态（平台列表徽标） */
    private static function handleTemplateStatus(\PDO $db, int $workspaceId): void
    {
        $svc = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        Response::ok(['status' => $svc->listPlatformStatus(), 'fields' => ImportTemplateService::fieldLabels()]);
    }

    /** template_parse — 上传样本 → 解析表头 → 自动识别映射（不落库） */
    private static function handleTemplateParse(\PDO $db, int $workspaceId): void
    {
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);
        if (empty($_FILES['file'])) Response::error('请上传该平台的样本文件', 4);

        $file = $_FILES['file'];
        $ext  = \strtolower((string)\pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!\in_array($ext, self::ALLOWED_EXTS, true)) {
            Response::error('不支持的文件格式，仅允许 ' . \implode(' / ', self::ALLOWED_EXTS), 4);
        }

        $path   = self::storeUploadedFile($file, $ext);
        $svc    = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        $parser = new SheetTableParser();
        $parsed = $parser->parse($path, $svc->expectedHeaders($platformId));

        $sourceType = \safeStr($_POST['platform_code'] ?? 'custom');
        $detected   = self::blankEmptyColumns($svc->detect($platformId, $parsed['headers'], $sourceType), $parsed['headers'], $parsed['rows']);

        Response::ok([
            'headers'    => $parsed['headers'],
            'preview'    => \array_slice($parsed['rows'], 0, 10),
            'total_rows' => (int)$parsed['total'],
            'sheet_name' => $parsed['sheet_name'],
            'detected'   => $detected,
            'fields'     => ImportTemplateService::fieldLabels(),
        ]);
    }

    /** template_save — 保存模板（表头 + 列映射 + 忽略列） */
    private static function handleTemplateSave(\PDO $db, int $workspaceId): void
    {
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);

        $headers = \json_decode((string)($_POST['headers'] ?? '[]'), true);
        $columns = \json_decode((string)($_POST['columns'] ?? '{}'), true);
        $ignored = \json_decode((string)($_POST['ignored'] ?? '[]'), true);
        if (!\is_array($headers) || empty($headers)) Response::error('缺少 headers（样本表头）', 4);
        if (!\is_array($columns)) Response::error('缺少 columns（列映射）', 4);

        $canon = [];
        foreach ($columns as $idx => $field) {
            $canon[(string)$idx] = FieldRegistry::canonicalField((string)$field, (string)($headers[(int)$idx] ?? ''));
        }
        $columns = $canon;

        $fields = array_values(array_filter(array_map('strval', $columns), static fn($v) => $v !== ''));
        if (!\in_array('platform_part_no', $fields, true) && !\in_array('model', $fields, true)) {
            Response::error('映射不完整：至少需要映射「商品编号」或「型号」其中一列', 4);
        }

        $matchKeys = \json_decode((string)($_POST['match_keys'] ?? '[]'), true);
        $svc      = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        $revision = $svc->save($platformId, \safeStr($_POST['platform_code'] ?? ''), $headers, $columns, \is_array($ignored) ? $ignored : [], [], \is_array($matchKeys) ? $matchKeys : []);

        Response::ok(['revision' => $revision, 'saved' => true, 'match_keys' => $svc->matchKeys($platformId)]);
    }

    /** template_reset — 重置为内置模板 */
    private static function handleTemplateReset(\PDO $db, int $workspaceId): void
    {
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);

        $svc = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        if (!$svc->resetToBuiltin($platformId)) Response::error('该平台没有可重置的模板', 4);

        Response::ok(['reset' => true]);
    }

    /** template_delete — 删除平台模板（删除后该平台不可导入） */
    private static function handleTemplateDelete(\PDO $db, int $workspaceId): void
    {
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);

        $svc = new ImportTemplateService($db, \getDataUserId(), $workspaceId);
        $svc->remove($platformId);
        Response::ok(['deleted' => true]);
    }

    // ── 模板化导入流程（新版）────────────────────────

    /** stage — 用已确认的列映射建批次并入队（上传的文件由 document_id 定位） */
    private static function handleStage(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $platformId = \safePosInt($_POST['platform_id'] ?? 0, 0);
        if ($platformId <= 0) Response::error('缺少 platform_id', 4);

        $columns = \json_decode((string)($_POST['columns'] ?? '{}'), true);
        $ignored = \json_decode((string)($_POST['ignored'] ?? '[]'), true);
        if (!\is_array($columns) || empty($columns)) Response::error('缺少 columns（列映射）', 4);

        $tplSvc = new ImportTemplateService($db, $dataUid, $workspaceId);

        $platformCode = \safeStr($_POST['platform_code'] ?? '');
        if ($platformCode === '') {
            $st = $db->prepare("SELECT code FROM platforms WHERE id=? LIMIT 1");
            $st->execute([$platformId]);
            $platformCode = (string)($st->fetchColumn() ?: 'custom');
        }

        $documentId = \safePosInt($_POST['document_id'] ?? 0, 0);
        if ($documentId <= 0) {
            Response::error('缺少 document_id，请返回上一步重新解析文件', 4);
        }
        $doc = self::loadDocument($db, $documentId, $workspaceId);
        if (!$doc) {
            Response::error('解析记录不存在，请重新上传文件', 404);
        }
        $fileName = (string)($doc['filename'] ?: 'import.xlsx');
        $filePath = (string)($doc['file_path'] ?? '');
        if ($filePath === '' || !\is_file($filePath)) {
            Response::error('上传文件已失效，请重新选择文件', 4);
        }

        // 表头签名校验：防止 parse 之后文件被调换（两次解析的表头必须一致）
        $headersSig = \safeStr($_POST['headers_sig'] ?? '');
        if ($headersSig !== '') {
            $verify = (new SheetTableParser())->parse($filePath, $tplSvc->expectedHeaders($platformId));
            if (ImportTemplateService::sig($verify['headers']) !== $headersSig) {
                Response::error('文件内容与解析确认时不一致，请返回上一步重新解析', 4);
            }
        }

        $tpl = $tplSvc->get($platformId);
        $headers  = \json_decode((string)($_POST['headers'] ?? '[]'), true) ?: [];
        $detected = \json_decode((string)($_POST['detected'] ?? '[]'), true) ?: [];
        $importMode = \safeStr($_POST['import_mode'] ?? 'stock');
        $defaultCategory = \safeStr($_POST['default_category'] ?? '');
        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        $res = $svc->stage(
            $filePath,
            $headers,
            $columns,
            \is_array($ignored) ? $ignored : [],
            $platformId,
            $platformCode,
            $fileName,
            !empty($_POST['is_sample']),
            $importMode,
            $documentId,
            $defaultCategory
        );
        $res['import_mode'] = $importMode === 'profile' ? 'profile' : 'stock';
        $res['template_id'] = $tpl ? (int)$tpl['id'] : 0;

        self::recordMapping($db, $workspaceId, $documentId, $headers, $columns, \is_array($detected) ? $detected : [], $platformCode);

        try {
            $db->prepare("UPDATE import_document SET status='" . ImportStatus::COMPLETED . "' WHERE id=? AND workspace_id=?")
               ->execute([$documentId, $workspaceId]);
        } catch (\Throwable $e) { /* 状态更新失败不影响导入结果 */ }

        Response::ok($res);
    }

    /** chunk — 处理一批（前端轮询直至 done） */
    private static function handleChunk(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $batchId = \safePosInt($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0, 0);
        if ($batchId <= 0) Response::error('缺少 batch_id', 4);

        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        Response::ok($svc->chunk($batchId));
    }

    /** batch_list — 批次列表（导入页"最近导入"） */
    private static function handleBatchList(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        Response::ok(['batches' => $svc->batchList(12)]);
    }

    /** batch_detail — 处理详情（汇总 + 失败明细） */
    private static function handleBatchDetail(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $batchId = \safePosInt($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0, 0);
        if ($batchId <= 0) Response::error('缺少 batch_id', 4);

        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        Response::ok($svc->batchDetail($batchId));
    }

    /** batch_rows — 按处理结论分页查逐行明细（详情四张卡片点击后调用） */
    private static function handleBatchRows(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $batchId = \safePosInt($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0, 0);
        if ($batchId <= 0) Response::error('缺少 batch_id', 4);

        $action = \safeStr($_GET['action_filter'] ?? $_POST['action_filter'] ?? '');
        $page   = \safePosInt($_GET['page'] ?? $_POST['page'] ?? 1, 1);
        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        Response::ok($svc->batchRows($batchId, $action, $page, 20));
    }

    /** custom_fields — 订单自定义字段（映射页下拉 + 管理界面共用） */
    private static function handleCustomFields(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $op = \safeStr($_GET['op'] ?? $_POST['op'] ?? 'list');
        $svc = new OrderMetaService($db, $uid, $dataUid, $workspaceId);

        if ($op === 'list') {
            Response::ok(['fields' => $svc->customFields()]);
        }

        if (!\hasPermission('can_edit')) Response::error('权限不足', 403);

        if ($op === 'add') {
            $r = $svc->addCustomField(
                (string)($_POST['key'] ?? ''),
                (string)($_POST['label'] ?? '')
            );
            if (!$r['ok']) Response::error($r['msg']);
            Response::ok(['msg' => $r['msg'], 'fields' => $svc->customFields()]);
        }

        if ($op === 'toggle') {
            $r = $svc->toggleCustomField((string)($_POST['key'] ?? ''), ($_POST['enabled'] ?? '') === '1');
            if (!$r['ok']) Response::error($r['msg']);
            Response::ok(['msg' => $r['msg'], 'fields' => $svc->customFields()]);
        }

        if ($op === 'delete') {
            $r = $svc->deleteCustomField((string)($_POST['key'] ?? ''), ($_POST['purge'] ?? '') === '1');
            if (!$r['ok']) Response::error($r['msg']);
            Response::ok(['msg' => $r['msg'], 'purged' => $r['purged'], 'fields' => $svc->customFields()]);
        }

        Response::error('未知的操作：' . $op, 400);
    }

    /** errors_export — 失败明细 CSV 下载 */
    private static function handleErrorsExport(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        $batchId = \safePosInt($_GET['batch_id'] ?? $_POST['batch_id'] ?? 0, 0);
        if ($batchId <= 0) Response::error('缺少 batch_id', 4);

        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        $csv = $svc->errorsCsv($batchId);

        \header('Content-Type: text/csv; charset=utf-8');
        \header('Content-Disposition: attachment; filename="import_errors_' . $batchId . '.csv"');
        echo $csv;
        exit;
    }

    /** 数据全空的列自动忽略（不参与列映射） */
    private static function blankEmptyColumns(array $detected, array $headers, array $rows): array
    {
        $nonEmpty = [];
        foreach ($rows as $row) {
            foreach ((array)$row as $i => $v) {
                if (trim((string)$v) !== '') $nonEmpty[(int)$i] = ($nonEmpty[(int)$i] ?? 0) + 1;
            }
        }
        foreach ($headers as $i => $h) {
            $i = (int)$i;
            if (trim((string)$h) === '') continue;
            if (($nonEmpty[$i] ?? 0) > 0) continue;
            $detected[$i] = [
                'field'      => '',
                'confidence' => 0.0,
                'rule'       => '数据为空·自动忽略',
                'header'     => (string)$h,
                'empty'      => true,
            ];
        }
        ksort($detected);
        return $detected;
    }

    /** rollback — 批次级一键撤销（需删除权限） */
    private static function handleRollback(\PDO $db, int $uid, int $dataUid, int $workspaceId): void
    {
        if (!\hasPermission('can_delete')) {
            Response::error('当前账号无撤销权限（需删除权限）', 403);
        }
        $batchId = \safePosInt($_POST['batch_id'] ?? 0, 0);
        if ($batchId <= 0) Response::error('缺少 batch_id', 4);

        $svc = new ImportService($db, $uid, $dataUid, $workspaceId);
        $res = $svc->rollback($batchId);

        $msg = '已撤销：删除新建物料 ' . $res['deleted_parts'] . ' 项，恢复库存 ' . $res['restored_parts'] . ' 项';
        if (!empty($res['cleaned_history'])) $msg .= '，清理防重记录 ' . (int)$res['cleaned_history'] . ' 条（同一文件可重新导入）';
        if (!empty($res['cleaned_price'])) $msg .= '，清理价格历史 ' . (int)$res['cleaned_price'] . ' 条';
        if ($res['partial'] > 0) $msg .= '（' . $res['partial'] . ' 项库存已被后续操作消耗，按可用量扣回）';
        if ($res['skipped'] > 0) $msg .= '，跳过 ' . $res['skipped'] . ' 项';

        Response::ok($res, $msg);
    }

    // ── 文件与文档工具 ──────────────────────────────

    /** 确认即学习：落 import_mapping 明细；用户纠正过的列写入 user_mapping_rules */
    private static function recordMapping(\PDO $db, int $workspaceId, int $documentId, array $headers, array $columns, array $detected, string $sourceType): void
    {
        if ($documentId <= 0) return;
        try {
            $sourceType = $sourceType !== '' ? $sourceType : 'custom';
            $db->prepare("DELETE FROM import_mapping WHERE document_id=?")->execute([$documentId]);

            $ins = $db->prepare("INSERT INTO import_mapping
                (workspace_id, document_id, source_type, source_column, source_header, target_field, confidence, match_rule, is_manual)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $identifier = null;

            foreach ($headers as $i => $h) {
                $i = (int)$i;
                $h = (string)$h;
                $field = FieldRegistry::canonicalField((string)($columns[(string)$i] ?? $columns[$i] ?? ''), $h);
                if ($field !== '' && !FieldRegistry::isWritableField($field)) $field = '';

                $auto = (string)($detected[$i]['field'] ?? $detected[(string)$i]['field'] ?? '');
                $rule = (string)($detected[$i]['rule'] ?? $detected[(string)$i]['rule'] ?? '');
                $conf = (float)($detected[$i]['confidence'] ?? $detected[(string)$i]['confidence'] ?? 0.0);
                $manual = 0;

                if ($field === '') {
                    $conf = 0.0;
                    if ($rule === '') $rule = '未映射';
                } elseif ($field !== $auto) {
                    // 用户纠正过的列 → 记为人工确认并学习（下次自动识别优先命中）
                    $manual = 1;
                    $conf = 1.0;
                    $rule = '人工确认';
                    if ($h !== '') {
                        if ($identifier === null) $identifier = new FieldIdentifier($db, $workspaceId);
                        $identifier->learnUserRule($sourceType, $h, $field);
                    }
                }

                $ins->execute([$workspaceId, $documentId, $sourceType, (string)$i, $h, $field, $conf, $rule, $manual]);
            }
        } catch (\Throwable $e) { /* 映射留痕失败不影响导入结果 */ }
    }

    /** 保存上传文件到 uploads/import/ */
    private static function storeUploadedFile(array $file, string $ext): string
    {
        $dir = \dirname(__DIR__, 2) . '/uploads/import';
        if (!\is_dir($dir)) @\mkdir($dir, 0755, true);
        $path = $dir . '/imp_' . \bin2hex(\random_bytes(8)) . '.' . $ext;
        if (!\move_uploaded_file((string)$file['tmp_name'], $path)) {
            Response::error('文件保存失败', 1);
        }
        return $path;
    }

    /** 登记文档记录（import_document） */
    private static function registerDocument(\PDO $db, int $workspaceId, string $filename, string $sourceType, string $hash, string $path): int
    {
        try {
            $stmt = $db->prepare("INSERT INTO import_document (workspace_id, filename, source_type, hash, upload_time, status, file_path)
                                  VALUES (?, ?, ?, ?, NOW(), '" . ImportStatus::UPLOADED . "', ?)");
            $stmt->execute([$workspaceId, $filename, $sourceType, $hash, $path]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            // file_path 列可能未建：退化为原始列集
            $stmt = $db->prepare("INSERT INTO import_document (workspace_id, filename, source_type, hash, upload_time, status)
                                  VALUES (?, ?, ?, ?, NOW(), '" . ImportStatus::UPLOADED . "')");
            $stmt->execute([$workspaceId, $filename, $sourceType, $hash]);
            return (int)$db->lastInsertId();
        }
    }

    /** 读取文档记录 */
    private static function loadDocument(\PDO $db, int $documentId, int $workspaceId): ?array
    {
        $stmt = $db->prepare("SELECT * FROM import_document WHERE id = ? AND workspace_id = ?");
        $stmt->execute([$documentId, $workspaceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 兜底：按文件名在 uploads/import 下查找最近上传文件 */
    private static function findUploadedFile(string $filename): string
    {
        $ext = \strtolower((string)\pathinfo($filename, PATHINFO_EXTENSION));
        $dir = \dirname(__DIR__, 2) . '/uploads/import';
        $files = \glob($dir . '/imp_*.' . $ext) ?: [];
        if (!$files) return '';
        \usort($files, static fn($a, $b) => \filemtime($b) <=> \filemtime($a));
        return (string)$files[0];
    }
}
