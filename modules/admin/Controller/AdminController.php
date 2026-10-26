<?php
declare(strict_types=1);

namespace Modules\Admin\Controller;

use Modules\Admin\Service\AdminBackupService;
use Modules\Admin\Service\AdminReadService;
use Modules\Admin\Service\WebdavCredentialService;
use PDO;

/** 管理后台页面控制器（薄壳） */
final class AdminController
{
    /** 处理 POST 请求（backup / restore） */
    public static function handlePost(PDO $db, int $uid, array $user): void
    {
        \verifyCsrf();
        // 备份 / 恢复仅主管理员（页面视图已隐藏表单，这里是直接 POST 的兜底）
        if (!\isPrimaryAdmin()) {
            http_response_code(403);
            echo '无权限';
            exit;
        }
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'backup':
                self::handleBackup($db, $uid);
                break;

            case 'restore':
                self::handleRestore($db, $uid);
                break;

            default:
                // 未知 action，跳转回管理页
                \App\Core\Response::redirect('index.php?v=admin&flash=err&ft=err');
        }
    }

    /** 组装管理页面视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid, array $user): array
    {
        $isPrimaryAdmin = \isPrimaryAdmin();
        $wsId = \getWorkspaceId();
        $read = new AdminReadService($db, $uid, $wsId);

        // Flash 消息
        $flash     = $_GET['flash'] ?? '';
        $flashType = $_GET['ft'] ?? 'ok';

        // 平台导入模板状态（平台列表徽标 + 模板配置弹窗）
        $tplStatus = [];
        $tplFields = [];
        try {
            $tplSvc    = new \Modules\Import\Service\ImportTemplateService($db, $uid, $wsId);
            $tplStatus = $tplSvc->listPlatformStatus();
            $tplFields = \Modules\Import\Service\ImportTemplateService::fieldLabels();
        } catch (\Throwable $e) {
            error_log('Admin templates: ' . $e->getMessage());
        }

        // 溯源日志（走 TraceManager，与「系统监控 → 溯源」面板同源）
        $traceData = ['logs' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        $traceFilterOptions = [];
        $traceDateFrom   = trim($_GET['trace_date_from'] ?? '');
        $traceDateTo     = trim($_GET['trace_date_to'] ?? '');
        $traceAction     = trim($_GET['trace_action'] ?? '');
        $traceTargetType = trim($_GET['trace_target_type'] ?? '');
        $traceOperator   = trim($_GET['trace_operator'] ?? '');
        try {
            $traceMgr = new \TraceManager($db, $uid, $dataUid);
            $traceData = $traceMgr->listLogs([
                'page'        => 1,
                'per_page'    => 100,
                'date_from'   => $traceDateFrom,
                'date_to'     => $traceDateTo,
                'action'      => $traceAction,
                'target_type' => $traceTargetType,
                'operator'    => $traceOperator,
            ]);

            $traceFilterOptions = $traceMgr->getFilterOptions();
        } catch (\Throwable $e) {
            error_log('Admin traceData: ' . $e->getMessage());
        }

        // 系统监控数据（仅主管理员 + 团队版）

        $monitorData = null;
        $logPerPage  = 20;
        if ($isPrimaryAdmin && hasFeature('multi_user')) {
            $logPage = max(1, (int)($_GET['log_page'] ?? 1));
            $logPerPage = (int)($_GET['log_per_page'] ?? $_COOKIE['per_page_admin'] ?? 10);
            try {
                $monitorData = $read->getMonitorData($logPage, $logPerPage);
                $logPerPage  = $monitorData['logPerPage'];
            } catch (\Throwable $e) {
                error_log('Admin monitorData: ' . $e->getMessage());
                $monitorData = null;
            }
        }

        $settings = $read->getSettings();

        return [
            'flash'            => $flash,
            'flashType'        => $flashType,
            'is_primary_admin' => $isPrimaryAdmin,
            'settings'         => $settings,
            'platforms'        => $read->getPlatforms(),
            'tplStatus'        => $tplStatus,
            'tplFields'        => $tplFields,
            'users'            => $isPrimaryAdmin ? $read->getUsers() : [],
            'subUsers'         => $read->getSubUsers(),
            'dbAlignment'      => $read->getDbAlignment(),
            'webdavPassSet'   => (new WebdavCredentialService($db))->hasPass(),
            'traceData'        => $traceData,
            'traceDateFrom'    => $traceDateFrom,
            'traceDateTo'      => $traceDateTo,
            'traceAction'      => $traceAction,
            'traceTargetType'  => $traceTargetType,
            'traceOperator'    => $traceOperator,
            'traceActionOptions' => $traceFilterOptions['actions'] ?? [],
            'traceTypeOptions'   => $traceFilterOptions['target_types'] ?? [],
            'monitorData'      => $monitorData,
            'outboundEnv'      => $read->getOutboundEnv(),
            'permLabels'       => self::permLabels(),
            'logPerPage'       => $logPerPage,
            'edition'          => $settings['edition'] ?? 'personal',
            'edition_locked'   => \isEditionLocked(),
        ];
    }

    /** 权限键的中文标签（子用户管理勾选项） */
    private static function permLabels(): array
    {
        return [
            'can_edit'              => '编辑物料',
            'can_delete'            => '删除物料',
            'can_import'            => '导入订单',
            'can_manage_categories' => '管理分类',
            'can_batch'             => '批量操作',
            'can_export'            => '导出数据',
            'can_scan'              => '扫码出入库',
            'can_print'             => '打印标签',
        ];
    }

    /** 数据库备份：生成 SQL → 记日志 → 流式下载 */
    private static function handleBackup(PDO $db, int $uid): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        try {
            $svc = new AdminBackupService($db, $uid);
            $sql = $svc->generateSql();
            $fileName = 'backup_' . date('Ymd_His') . '.sql';

            $svc->recordLog($fileName, strlen($sql), 'backup');
            \traceLog($uid, 'backup', 'backup', 0, '数据备份 size:' . strlen($sql));

            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="lcsc_backup_' . date('Ymd_His') . '.sql"');
            header('Content-Length: ' . strlen($sql));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo $sql;
            exit;
        } catch (\Throwable $e) {
            error_log('Backup failed: ' . $e->getMessage());
            \App\Core\Response::redirect('index.php?v=admin&flash=backup_err&ft=err#tab-backup');
        }
    }

    /** 数据库恢复：校验上传 → 逐条执行 → 记日志 */
    private static function handleRestore(PDO $db, int $uid): void
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            \App\Core\Response::redirect('index.php?v=admin&flash=restore_no_file&ft=err#tab-backup');
        }

        $tmp = $_FILES['backup_file']['tmp_name'];
        $ext = strtolower(pathinfo((string)$_FILES['backup_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql' || !\isValidFileName($_FILES['backup_file']['name'])) {
            \App\Core\Response::redirect('index.php?v=admin&flash=restore_bad_ext&ft=err#tab-backup');
        }

        $content = file_get_contents($tmp);
        if ($content === false || trim($content) === '') {
            \App\Core\Response::redirect('index.php?v=admin&flash=restore_empty&ft=err#tab-backup');
        }

        $svc  = new AdminBackupService($db, $uid);
        $name = $_FILES['backup_file']['name'];
        $res  = $svc->restore($content);

        if ($res['error'] === '' && $res['executed'] === $res['total']) {
            $svc->recordLog($name, strlen($content), 'restore');
            \traceLog($uid, 'restore', 'backup', 0, "恢复备份 文件:{$name} 语句:{$res['executed']}");
            \App\Core\Response::redirect('index.php?v=admin&flash=restore_ok#tab-backup');
        } else {
            error_log('Restore failed at statement #' . ($res['executed'] + 1) . ': ' . $res['error']);
            \App\Core\Response::redirect('index.php?v=admin&flash=restore_err_generic&ft=err#tab-backup');
        }
    }
}
