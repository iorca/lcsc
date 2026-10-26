<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

require_once __DIR__ . '/../../modules/system/Service/AccountService.php';

/** 账号自助 API 路由 */
final class AccountRoutes
{
    /** 移动端工作台概览卡片可选键（与 `mobile/Views/home.php` 的卡片目录一一对应） */
    private const HOME_CARDS = ['kinds', 'low', 'zero', 'damaged', 'negative', 'value', 'health', 'week_in', 'week_out', 'stale'];
    private const HOME_CARDS_MAX = 6;

    public static function handle(string $method, array $user): void
    {
        $db = \getDB();

        switch ($method) {
            case 'change_password':
                self::handleChangePassword($db, $user);
                break;

            case 'save_home_cards':
                self::handleSaveHomeCards($user);
                break;

            default:
                Response::error('未知 account 方法：' . $method, 404);
        }
    }

    /** POST account.change_password — 修改当前登录用户密码 */
    private static function handleChangePassword(\PDO $db, array $user): void
    {
        $old     = (string)($_POST['old_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');

        if ($old === '' || $new === '' || $confirm === '') {
            Response::error('请填写当前密码、新密码与确认密码');
        }

        try {
            // 目标用户恒取会话身份，杜绝改成别人的密码
            $result = (new \Modules\System\Service\AccountService($db))
                ->changePassword((int)$user['id'], $old, $new, $confirm);
        } catch (\Throwable $e) {
            error_log('account.change_password: ' . $e->getMessage());
            Response::error('操作失败，请稍后重试');
            return;
        }

        if ($result['error'] !== '') {
            Response::error($result['error']);
        }
        Response::ok(['changed' => true], $result['success'] !== '' ? $result['success'] : '密码已更新');
    }

    /** POST account.save_home_cards — 保存当前登录用户的移动端工作台显示卡片 */
    private static function handleSaveHomeCards(array $user): void
    {
        $raw = (string)($_POST['cards'] ?? '');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            Response::error('参数格式不正确');
        }

        $picked = [];
        foreach ($arr as $k) {
            if (is_string($k) && in_array($k, self::HOME_CARDS, true)) $picked[$k] = true;
        }
        // 归一到目录顺序（视图按目录顺序渲染，此处保持一致的返回顺序）
        $clean = [];
        foreach (self::HOME_CARDS as $k) {
            if (isset($picked[$k])) $clean[] = $k;
        }
        if (empty($clean)) {
            Response::error('请至少选择一项');
        }
        if (count($clean) > self::HOME_CARDS_MAX) {
            $clean = array_slice($clean, 0, self::HOME_CARDS_MAX);
        }

        // 目标用户恒取会话身份，杜绝改到别人的设置
        $uid = (int)$user['id'];
        setUserSetting($uid, 'mobile_home_cards', (string)json_encode($clean));
        traceLog($uid, 'save_home_cards', 'user', $uid, '工作台显示卡片：' . implode(',', $clean));

        Response::ok(['cards' => $clean], '已保存');
    }
}
