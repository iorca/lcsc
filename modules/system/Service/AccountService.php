<?php
declare(strict_types=1);

namespace Modules\System\Service;

use PDO;

/** 账号自助服务 */
final class AccountService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 修改当前用户密码 */
    public function changePassword(int $userId, string $oldPassword, string $newPassword, string $confirm): array
    {
        try {
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['error' => '账号不存在', 'success' => ''];
            }
            $hash = (string)$row['password_hash'];

            if (!password_verify($oldPassword, $hash)) {
                return ['error' => '当前密码错误', 'success' => ''];
            }
            if (!\isStrongPassword($newPassword)) {
                return ['error' => '新密码至少8位，需包含大小写字母、数字、特殊字符中的3种', 'success' => ''];
            }
            if (password_verify($newPassword, $hash)) {
                return ['error' => '新密码不能与当前密码相同', 'success' => ''];
            }
            if ($newPassword !== $confirm) {
                return ['error' => '两次密码不一致', 'success' => ''];
            }

            $this->db->prepare(
                "UPDATE users SET password_hash=?, must_change_pw=0 WHERE id=?"
            )->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

            \traceLog($userId, 'change_password', 'user', $userId, '修改密码');

            if (($_SESSION['user']['id'] ?? null) == $userId) {
                $_SESSION['user']['must_change_pw'] = 0;
            }

            return ['error' => '', 'success' => '密码已更新'];
        } catch (\Throwable $e) {
            error_log('AccountService::changePassword: ' . $e->getMessage());
            return ['error' => '操作失败，请稍后重试', 'success' => ''];
        }
    }
}
