<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use App\Helper\RollbackService;

// AdminManager 提供设置 / 用户管理 / 子用户管理全部业务逻辑
require_once __DIR__ . '/../../modules/admin/Service/AdminService.php';

/** 管理后台 API 路由 */
final class AdminRoutes
{
    public static function handle(string $method, array $user): void
    {
        // 管理后台全部动作仅主管理员：页面视图已有同款守卫，此处补齐 API 侧

        if (!\isPrimaryAdmin()) {
            Response::error('无权限', 403);
        }

        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();

        switch ($method) {
            case 'save_settings':
                self::handleSaveSettings($db, $uid, $dataUid);
                break;

            case 'save_edition':
                self::handleSaveEdition($db, $uid, $dataUid);
                break;

            case 'user_reset_pw':
                self::handleUserResetPw($db, $uid, $dataUid);
                break;

            case 'user_delete':
                self::handleUserDelete($db, $uid, $dataUid);
                break;

            case 'create_sub_user':
                self::handleCreateSubUser($db, $uid, $dataUid);
                break;

            case 'update_sub_user':
                self::handleUpdateSubUser($db, $uid, $dataUid);
                break;

            case 'delete_sub_user':
                self::handleDeleteSubUser($db, $uid, $dataUid);
                break;

            case 'rollback':
                self::handleRollback($db, $uid, $dataUid);
                break;

            default:
                Response::error('未知 admin 方法：' . $method, 404);
        }
    }

    /** 子用户操作跳转锚点（统一为用户管理面板） */
    private static function subUserAnchor(): string
    {
        return '#tab-users';
    }

    /** POST admin.save_settings — 保存网站设置（仅主管理员，含 Logo 上传） */
    private static function handleSaveSettings(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->saveSettings($_POST);

            // Logo 上传处理（文件操作，仅主管理员）
            if (!empty($_FILES['logo']['name']) && \isPrimaryAdmin()) {
                $f    = $_FILES['logo'];
                $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                $allowed      = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if ($f['error'] === UPLOAD_ERR_OK
                    && in_array($ext, $allowed, true)
                    && $f['size'] < 2 * 1024 * 1024
                    && \isValidMime($f['tmp_name'], $allowedMimes)
                    && \isValidFileName($f['name'])) {
                    $dir = dirname(__DIR__, 2) . '/uploads/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $name = 'logo_' . time() . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
                        \setSetting('site_logo', 'uploads/' . $name);
                    }
                } else {
                    $result['logo_err'] = true;
                }
            }

            if ($isAjax) Response::ok($result, '设置已保存');
            $flashKey = !empty($result['logo_err']) ? 'logo_err' : 'ok_save';
            Response::redirect('index.php?v=admin&flash=' . $flashKey . (!empty($result['logo_err']) ? '&ft=err' : '') . '#tab-settings');
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin&flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err#tab-settings');
        }
    }

    /** POST admin.save_edition — 保存版本选择（一次性锁定，不可修改） */
    private static function handleSaveEdition(\PDO $db, int $uid, int $dataUid): void
    {
        if (\isEditionLocked()) Response::error('版本已锁定，不可修改');
        $edition = trim((string)($_POST['edition'] ?? ''));
        if (!in_array($edition, ['personal', 'studio'], true)) Response::error('无效版本');

        \setSetting('edition', $edition);

        foreach (\featureDefaults() as $fk => $fv) {
            \setSetting($fk, $fv);
        }
        \traceLog($uid, 'save_edition', 'setting', 0, "设置版本:{$edition}（永久锁定）");
        Response::ok(['edition' => $edition]);
    }

    /** POST admin.user_reset_pw — 重置用户密码 */
    private static function handleUserResetPw(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->resetUserPassword((int)($_POST['target_id'] ?? 0));
            if ($isAjax) Response::ok($result, '密码已重置');
            $_SESSION['reset_pw_flash'] = $result['new_password'];
            Response::redirect('index.php?v=admin?flash=pw_reset&ft=warn' . self::subUserAnchor());
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err' . self::subUserAnchor());
        }
    }

    /** POST admin.user_delete — 删除用户（仅主管理员） */
    private static function handleUserDelete(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->deleteUser((int)($_POST['target_id'] ?? 0));
            if ($isAjax) Response::ok($result, '用户已删除');
            Response::redirect('index.php?v=admin?flash=ok_save#tab-users');
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err#tab-users');
        }
    }

    /** POST admin.create_sub_user — 创建子用户 */
    private static function handleCreateSubUser(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->createSubUser($_POST);
            if ($isAjax) Response::ok($result, '子用户已创建');
            Response::redirect('index.php?v=admin?flash=ok_save' . self::subUserAnchor());
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin?flash=sub_user_err&ft=err' . self::subUserAnchor());
        }
    }

    /** POST admin.update_sub_user — 更新子用户权限 */
    private static function handleUpdateSubUser(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->updateSubUser((int)($_POST['target_id'] ?? 0), $_POST['sub_perms'] ?? []);
            if ($isAjax) Response::ok($result, '权限已更新');
            Response::redirect('index.php?v=admin?flash=ok_save' . self::subUserAnchor());
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin?flash=err&ft=err' . self::subUserAnchor());
        }
    }

    /** POST admin.delete_sub_user — 删除子用户 */
    private static function handleDeleteSubUser(\PDO $db, int $uid, int $dataUid): void
    {
        $isAjax = \isAjaxRequest();
        $adm = new \AdminManager($db, $uid, $dataUid);
        try {
            $result = $adm->deleteSubUser((int)($_POST['target_id'] ?? 0));
            if ($isAjax) Response::ok($result, '子用户已删除');
            Response::redirect('index.php?v=admin?flash=ok_save' . self::subUserAnchor());
        } catch (\AdminException $e) {
            if ($isAjax) Response::error($e->getMessage(), $e->errCode);
            $_SESSION['action_error'] = $e->getMessage();
            Response::redirect('index.php?v=admin?flash=err&ft=err' . self::subUserAnchor());
        }
    }

    /** POST admin.rollback — 回滚溯源日志记录（仅主管理员） */
    private static function handleRollback(\PDO $db, int $uid, int $dataUid): void
    {
        if (!\isPrimaryAdmin()) {
            Response::error('仅主管理员可执行回滚操作', 403);
        }

        $traceId = (int)($_POST['trace_id'] ?? 0);
        if ($traceId <= 0) {
            Response::error('参数无效：缺少 trace_id', 422);
        }

        // 校验目标类型是否可回滚
        $stmt = $db->prepare("SELECT target_type, snapshot_before FROM trace_log WHERE id = ?");
        $stmt->execute([$traceId]);
        $trace = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$trace) {
            Response::error('日志记录不存在', 404);
        }
        $rollableTypes = ['part', 'category', 'platform', 'user', 'bom_project'];
        if (!in_array($trace['target_type'], $rollableTypes, true)) {
            Response::error('不支持回滚类型：' . $trace['target_type'], 422);
        }
        if (empty($trace['snapshot_before'])) {
            Response::error('该记录无操作前快照，无法回滚', 422);
        }

        $service = new RollbackService($db, $uid);
        $result = $service->rollback($traceId);

        if ($result['success']) {
            Response::ok(['success' => true], $result['message']);
        } else {
            Response::error($result['message']);
        }
    }
}
