<?php
declare(strict_types=1);

namespace Modules\Admin\Service;

use App\Core\Http\SecretStore;
use PDO;

/** WebdavCredentialService — WebDAV 认证密码的加密存取 */
final class WebdavCredentialService
{
    /** SecretStore 槽位名 */
    private const SLOT = 'webdav';

    /** 迁移前的明文 settings 键 */
    private const LEGACY_KEY = 'webdav_pass';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** 取 WebDAV 密码（必要时先把旧明文搬进加密槽位） */
    public function getPass(): string
    {
        $secret = SecretStore::get(self::SLOT);
        if ($secret !== '') {
            return $secret;
        }
        return $this->migrateLegacyPlaintext();
    }

    /** 存 WebDAV 密码（加密落库） */
    public function setPass(string $plain): bool
    {
        return SecretStore::put(self::SLOT, $plain);
    }

    /** 清空 WebDAV 密码（连密文与槽位索引一起删） */
    public function clearPass(): bool
    {
        return SecretStore::delete(self::SLOT);
    }

    /** 是否已配置密码（供界面显示「已保存，留空不修改」） */
    public function hasPass(): bool
    {
        if (SecretStore::has(self::SLOT)) {
            return true;
        }
        return $this->legacyPlaintext() !== '';
    }

    /** 惰性迁移：把旧明文写进加密槽位后删除明文键 */
    private function migrateLegacyPlaintext(): string
    {
        $plain = $this->legacyPlaintext();
        if ($plain === '') {
            return '';
        }
        if (!SecretStore::put(self::SLOT, $plain)) {
            // 加密失败则**保留明文**并如实返回：宁可继续明文存，也不能让用户
            // 的 WebDAV 备份突然用不了（下次调用还会重试迁移）。
            error_log('[WebdavCredential] 旧明文迁移失败，保留 settings.webdav_pass 不动');
            return $plain;
        }
        $st = $this->db->prepare('DELETE FROM settings WHERE k=?');
        $st->execute([self::LEGACY_KEY]);
        \clearSettingCache(self::LEGACY_KEY);
        error_log('[WebdavCredential] 旧明文已迁入加密槽位并从settings 删除');
        return $plain;
    }

    /** 读旧明文键（不存在返回空串） */
    private function legacyPlaintext(): string
    {
        try {
            return (string)\getSetting(self::LEGACY_KEY, '');
        } catch (\Throwable $e) {
            error_log('[WebdavCredential] 读旧明文失败：' . $e->getMessage());
            return '';
        }
    }
}
