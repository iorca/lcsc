<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Location\Service\LocationService;

/** 库位管理 API 路由（P6 三级结构：仓库→库区→库位） */
final class LocationRoutes
{
    /** 写操作方法表：本类所有写操作均需自行校验 CSRF */
    private const WRITE_METHODS = [
        'create', 'rename', 'move', 'delete',
        'bind_part', 'batch_bind', 'backfill', 'import_csv',
    ];

    public static function handle(string $method, array $user): void
    {

        if (!\hasFeature('location_manage')) {
            Response::error('库位管理未启用，请先在「网站设置 → 功能开关」中开启', 403);
        }

        // 写操作统一 CSRF 校验（安全底线：所有 POST 状态变更必须校验）
        if (in_array($method, self::WRITE_METHODS, true)) {
            \verifyCsrfSafe();
        }

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'tree':
                self::handleTree($db, $dataUid);
                break;

            case 'create':
                self::requirePerm('can_manage_categories');
                self::handleCreate($db, $uid, $dataUid);
                break;

            case 'rename':
                self::requirePerm('can_manage_categories');
                self::handleRename($db, $uid, $dataUid);
                break;

            case 'move':
                self::requirePerm('can_manage_categories');
                self::handleMove($db, $uid, $dataUid);
                break;

            case 'delete':
                self::requirePerm('can_manage_categories');
                self::handleDelete($db, $uid, $dataUid);
                break;

            case 'bind_part':
                self::requirePerm('can_edit');
                self::handleBindPart($db, $uid, $dataUid);
                break;

            case 'batch_bind':
                if (!\hasPermission('can_edit') || !\hasPermission('can_batch')) {
                    Response::error('权限不足', 403);
                }
                self::handleBatchBind($db, $uid, $dataUid);
                break;

            case 'backfill':
                // 存量回填会改写 parts.location 文本，与库位结构维护同权限
                self::requirePerm('can_manage_categories');
                self::handleBackfill($db, $uid, $dataUid);
                break;

            case 'backfill_preview':
                self::handleBackfillPreview($db, $dataUid);
                break;

            case 'parts':
                self::handleParts($db, $dataUid);
                break;

            case 'import_csv':
                self::requirePerm('can_manage_categories');
                self::handleImportCsv($db, $uid, $dataUid);
                break;

            case 'find':
                // 声光寻物：纯查询，登录即可（不需 can_edit —— 取件人未必有编辑权）
                self::handleFind($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 location 方法：' . $method, 404);
        }
    }

    /** GET location.tree — 库位树（物料编辑/批量操作/库位管理页共用） */
    private static function handleTree(\PDO $db, int $dataUid): void
    {
        $svc  = new LocationService($db, 0, $dataUid);
        $tree = $svc->getTree();
        Response::ok([
            'tree'      => $tree,
            'max_depth' => LocationService::MAX_DEPTH,
        ]);
    }

    /** POST location.create — 新增库位 */
    private static function handleCreate(\PDO $db, int $uid, int $dataUid): void
    {
        $name     = trim((string)($_POST['name'] ?? ''));
        $type     = (string)($_POST['type'] ?? 'shelf');
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $desc     = (string)($_POST['description'] ?? '');
        if ($name === '') Response::error('库位名称不能为空');

        $svc   = new LocationService($db, $uid, $dataUid);
        $result = $svc->create($name, $type, $parentId, $desc);
        if (!$result['ok']) Response::error($result['msg']);
        Response::ok(['id' => $result['id']], '库位已创建');
    }

    /** POST location.rename — 编辑库位（name 必填；type / description 传了才更新） */
    private static function handleRename(\PDO $db, int $uid, int $dataUid): void
    {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($id <= 0 || $name === '') Response::error('参数无效');

        $svc    = new LocationService($db, $uid, $dataUid);

        $type = \array_key_exists('type', $_POST) ? (string)$_POST['type'] : null;
        $desc = \array_key_exists('description', $_POST) ? (string)$_POST['description'] : null;
        $result = $svc->rename($id, $name, $type, $desc);
        if (!$result['ok']) Response::error($result['msg']);
        Response::ok(['path' => $result['path']], '库位已保存');
    }

    /** POST location.move — 移动库位（防环 + 深度限制） */
    private static function handleMove(\PDO $db, int $uid, int $dataUid): void
    {
        $id       = (int)($_POST['id'] ?? 0);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if ($id <= 0) Response::error('参数无效');

        $svc    = new LocationService($db, $uid, $dataUid);
        $result = $svc->move($id, $parentId);
        if (!$result['ok']) Response::error($result['msg']);
        Response::ok(['id' => $id, 'parent_id' => $parentId], '库位已移动');
    }

    /** POST location.delete — 删除库位（有子级或绑定物料时拒绝） */
    private static function handleDelete(\PDO $db, int $uid, int $dataUid): void
    {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) Response::error('参数无效');

        $svc    = new LocationService($db, $uid, $dataUid);
        $result = $svc->delete($id);
        if (!$result['ok']) Response::error($result['msg']);
        Response::ok(['id' => $id], '库位已删除');
    }

    /** POST location.bind_part — 物料绑定/解绑库位（事务双写 location_id + location 文本） */
    private static function handleBindPart(\PDO $db, int $uid, int $dataUid): void
    {
        $partId     = (int)($_POST['part_id'] ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        if ($partId <= 0) Response::error('参数无效');

        $svc    = new LocationService($db, $uid, $dataUid);
        $result = $svc->bindPart($partId, $locationId);
        if (!$result['ok']) Response::error($result['msg']);
        Response::ok(['part_id' => $partId, 'location_id' => $locationId, 'location' => $result['path']], '库位已更新');
    }

    /** POST location.batch_bind — 批量绑定库位 */
    private static function handleBatchBind(\PDO $db, int $uid, int $dataUid): void
    {
        $partIds    = $_POST['part_ids'] ?? [];
        $locationId = (int)($_POST['location_id'] ?? 0);
        if (!is_array($partIds) || !$partIds) Response::error('请选择物料');
        if ($locationId < 0) Response::error('参数无效');

        $svc = new LocationService($db, $uid, $dataUid);
        $ok = 0; $fail = 0;
        foreach ($partIds as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;
            $r = $svc->bindPart($pid, $locationId);
            $r['ok'] ? $ok++ : $fail++;
        }
        if ($ok === 0) Response::error('批量绑定失败');
        Response::ok(['bound' => $ok, 'failed' => $fail], "已绑定 {$ok} 个物料" . ($fail ? "，{$fail} 个失败" : ''));
    }

    /** POST location.backfill — 存量文本库位回填（分批 500 条，幂等） */
    private static function handleBackfill(\PDO $db, int $uid, int $dataUid): void
    {
        $svc    = new LocationService($db, $uid, $dataUid);
        $result = $svc->backfillBatch();
        Response::ok($result, $result['remaining'] > 0
            ? "本批回填 {$result['filled']} 条，剩余 {$result['remaining']} 条待回填"
            : "回填完成（本批 {$result['filled']} 条）");
    }

    /** GET location.backfill_preview — 存量回填预览（总数 + 样例），供二次确认 */
    private static function handleBackfillPreview(\PDO $db, int $dataUid): void
    {
        $svc = new LocationService($db, 0, $dataUid);
        Response::ok($svc->previewBackfill(20));
    }

    /** GET location.parts?location_id=&descendants=1 — 库位详情（节点 + 物料列表 + 统计） */
    private static function handleParts(\PDO $db, int $dataUid): void
    {
        $locationId = (int)($_GET['location_id'] ?? 0);
        if ($locationId <= 0) Response::error('库位参数无效');
        $includeDesc = ($_GET['descendants'] ?? '1') !== '0';

        $svc = new LocationService($db, 0, $dataUid);
        Response::ok($svc->getPartsByLocation($locationId, $includeDesc));
    }

    /** POST location.import_csv — 批量导入库位（文件字段 csv，UTF-8，列：层级,库位路径,类型） */
    private static function handleImportCsv(\PDO $db, int $uid, int $dataUid): void
    {
        if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('请选择要导入的 CSV 文件');
        }
        $raw = (string)file_get_contents($_FILES['csv']['tmp_name']);
        // BOM / GBK 兼容
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
        if (mb_check_encoding($raw, 'GBK')) $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $rows  = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $rows[] = str_getcsv($line);
        }
        if (!$rows) Response::error('CSV 文件内容为空');

        $svc    = new LocationService($db, $uid, $dataUid);
        $result = $svc->importCsv($rows);
        Response::ok($result, "导入完成：创建 {$result['created']} 个库位" . ($result['failed'] ? "，{$result['failed']} 行失败" : ''));
    }

    /** GET location.find?q= — 声光寻物：按扫码原文 / 编号 / 关键词定位库位 */
    private static function handleFind(\PDO $db, int $uid, int $dataUid): void
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $svc = new LocationService($db, $uid, $dataUid);
        Response::ok($svc->findForPickup($q, 8));
    }

    private static function requirePerm(string $perm): void
    {
        if (!\hasPermission($perm)) {
            Response::error('权限不足', 403);
        }
    }
}
