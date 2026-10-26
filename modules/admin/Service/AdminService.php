<?php
declare(strict_types=1);

/** 管理后台业务模块（v1.1.0 正式版基线） */

final class AdminManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private bool $isPrimary;
    private int $wsId;

    /** 权限白名单：子用户可被授予的权限集合 */
    private const PERM_ALLOWLIST = [
        'can_edit', 'can_delete', 'can_import', 'can_manage_categories',
        'can_batch', 'can_export', 'can_scan', 'can_print',
    ];

    /** 网站设置可写字段白名单 */
    private const SETTING_FIELDS = [
        'site_title', 'notice_content', 'notice_show_mode',
        'default_low_stock', 'theme_default', 'session_timeout',
        'version_check_url', 'version_changelog_url',
        'publish_version', 'publish_changelog_url', 'publish_download_url',
        'webdav_url', 'webdav_user', 'webdav_path',
        'label_template', 'label_qr_content',
        'auth_enabled',
    ];

    /** 网站设置字段的中文标签（键与 SETTING_FIELDS 一致） */
    private const SETTING_LABELS = [
        'site_title'            => '网站标题',
        'notice_content'        => '公告内容',
        'notice_show_mode'      => '公告弹出方式',
        'default_low_stock'     => '低库存阈值',
        'theme_default'         => '默认主题',
        'session_timeout'       => '会话超时',
        'version_check_url'     => '版本检测地址',
        'version_changelog_url' => '版本更新说明地址',
        'publish_version'       => '发布版本号',
        'publish_changelog_url' => '发布更新说明地址',
        'publish_download_url'  => '发布下载地址',
        'webdav_url'            => 'WebDAV 地址',
        'webdav_user'           => 'WebDAV 用户名',
        'webdav_path'           => 'WebDAV 备份路径',
        'label_template'        => '标签模板',
        'label_qr_content'      => '标签编号内容',
        'auth_enabled'          => '登录密码',
    ];

    /** 功能开关的中文标签（用于操作记录；键与 featureDefaults() 一致） */
    private const FEATURE_LABELS = [
        'feature_cost_view'      => '成本管理',
        'feature_asset_stats'    => '资产总览',
        'feature_bom_version'    => 'BOM 多版本',
        'feature_log_view'       => '操作日志',
        'enable_location_manage' => '库位管理',
    ];

    /** 删除用户时需级联清理的业务表（均含 workspace_id 列） */
    private const USER_DATA_TABLES = [
        'parts', 'stock_log', 'price_history', 'import_history',
        'import_errors', 'imported_files', 'categories',
        'scan_log', 'backup_log',
    ];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db        = $db;
        $this->uid       = $uid;
        $this->dataUid   = $dataUid;
        $this->isPrimary = isPrimaryAdmin();
        $this->wsId      = getWorkspaceId();
    }

    /** 保存网站设置（仅主管理员，不含 Logo 上传） */
    public function saveSettings(array $post): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }

        // 操作记录：**只记真正发生变化的项**。
        // 设置页是「分卡片保存」——每张卡片只提交自己的字段。若按固定字段列表拼字符串，
        // 未提交的字段会被记成空值、而真正的改动（如功能开关）反而丢失

        $changes = [];
        // 记录一项变更：新值 ≠ 旧值才写入；$display 为可读展示值（缺省用新值）
        $note = function (string $label, string $newRaw, string $oldRaw, ?string $display = null) use (&$changes): void {
            if ($oldRaw !== $newRaw) {
                $v = $display ?? $newRaw;
                $changes[] = $label . ':' . ($v === '' ? '（清空）' : $v);
            }
        };

        foreach (self::SETTING_FIELDS as $f) {
            if (isset($post[$f])) {
                $newVal = trim((string)$post[$f]);
                $note(self::SETTING_LABELS[$f] ?? $f, $newVal, getSetting($f, ''));
                setSetting($f, $newVal);
            }
        }

        if (isset($post['sub_notice_content'])) {
            $subMode = (string)($post['sub_notice_mode'] ?? 'off');
            if (!in_array($subMode, ['off', 'once', 'always'], true)) $subMode = 'off';
            $subContent = trim((string)$post['sub_notice_content']);

            if ($subContent === '') $subMode = 'off';
            setUserSetting($this->uid, 'sub_notice_content', $subContent);
            setUserSetting($this->uid, 'sub_notice_mode', $subMode);
        }
        // 操作记录最低留存天数（最小7天，全系统统一）
        $retentionDays = max(7, (int)($post['retention_days'] ?? 30));
        $note('记录留存', (string)$retentionDays, getSetting('retention_days', (string)$retentionDays), $retentionDays . '天');
        setSetting('retention_days', (string)$retentionDays);
        // 呆滞料判定天数（30-365）
        $staleDays = max(30, min(365, (int)($post['stale_days'] ?? 90)));
        $note('呆滞判定', (string)$staleDays, getSetting('stale_days', (string)$staleDays), $staleDays . '天');
        setSetting('stale_days', (string)$staleDays);
        // 默认利润率（0-99.99）
        $defaultMargin = round(max(0, min(99.99, (float)($post['default_profit_margin'] ?? 0))), 2);
        $note('默认利润率', (string)$defaultMargin, getSetting('default_profit_margin', (string)$defaultMargin), $defaultMargin . '%');
        setSetting('default_profit_margin', (string)$defaultMargin);

        $keepRawNew = (isset($post['import_keep_raw']) && (string)$post['import_keep_raw'] === '1') ? '1' : '0';
        $note('保留导入原始数据', $keepRawNew === '1' ? '开启' : '关闭',
              getSetting('import_keep_raw', '0') === '1' ? '开启' : '关闭');
        setSetting('import_keep_raw', $keepRawNew);
        // 物料图片显示开关（默认关闭）：关闭时列表不渲染图片列、详情页不渲染图区。

        $showImgNew = (isset($post['parts_show_images']) && (string)$post['parts_show_images'] === '1') ? '1' : '0';
        $note('物料图片显示', $showImgNew === '1' ? '开启' : '关闭',
              getSetting('parts_show_images', '0') === '1' ? '开启' : '关闭');
        setSetting('parts_show_images', $showImgNew);
        // 版本选择（仅首次可选，锁定后不可改）
        $editionVal = \App\Core\Edition::isStudio() ? 'studio' : 'personal';
        if (!isEditionLocked()) {
            $edition = trim((string)($post['edition'] ?? ''));
            if (in_array($edition, ['personal', 'studio'], true)) {
                setSetting('edition', $edition);
                $editionVal = $edition;
                $changes[] = '版本:' . $edition;

                foreach (featureDefaults() as $fk => $fv) {
                    if (getSetting($fk, '') === '') setSetting($fk, $fv);
                }
            }
        }
        // 功能开关（双版本同一白名单）

        foreach (featureDefaults() as $fk => $fallback) {
            if (!isset($post[$fk])) continue;
            $newVal = in_array($post[$fk], ['1', '0'], true) ? (string)$post[$fk] : $fallback;
            $oldVal = getSetting($fk, $fallback);
            setSetting($fk, $newVal);
            if ($oldVal !== $newVal) {
                $changes[] = ($newVal === '1' ? '开启 ' : '关闭 ') . (self::FEATURE_LABELS[$fk] ?? $fk);
            }
        }

        traceLog($this->uid, 'save_settings', 'setting', 0,
            $changes ? implode('；', $changes) : '无变更');

        return ['retention_days' => $retentionDays, 'stale_days' => $staleDays, 'default_profit_margin' => $defaultMargin, 'edition' => $editionVal];
    }

    /** 重置用户密码（主管理员可重置任何人，普通管理员仅可重置自己的子用户） */
    public function resetUserPassword(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->canManageUser($targetId)) {
            throw new AdminException('无权限操作该用户', 403);
        }
        // 生成 8 位随机密码（字母+数字）
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $newPw = '';
        for ($i = 0; $i < 8; $i++) {
            $newPw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $hash = password_hash($newPw, PASSWORD_DEFAULT);

        $this->db->prepare("UPDATE users SET password_hash=?, must_change_pw=1 WHERE id=? AND workspace_id=?")
                 ->execute([$hash, $targetId, $this->wsId]);
        traceLog($this->uid, 'user_reset_pw', 'user', $targetId, "重置用户密码 uid:{$targetId}");
        return ['new_password' => $newPw];
    }

    /** 删除用户（仅主管理员，不可删除自己） */
    public function deleteUser(int $targetId): array
    {
        if (!$this->isPrimary) {
            throw new AdminException('无权限', 403);
        }
        if ($targetId === $this->uid) {
            throw new AdminException('不能删除当前登录用户', 1);
        }
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        // 级联清理 part_categories 关联
        $parts = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND workspace_id=?");
        $parts->execute([$targetId, $this->wsId]);
        foreach ($parts->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $this->db->prepare("DELETE FROM part_categories WHERE part_id=?")
                     ->execute([(int)$p['id']]);
        }
        // 级联清理各业务表（均含 workspace_id 列）
        foreach (self::USER_DATA_TABLES as $t) {
            $this->db->prepare("DELETE FROM `{$t}` WHERE user_id=? AND workspace_id=?")
                     ->execute([$targetId, $this->wsId]);
        }

        $this->db->prepare("DELETE FROM notice_seen WHERE user_id=?")
                 ->execute([$targetId]);
        $this->db->prepare("DELETE FROM users WHERE id=? AND workspace_id=?")
                 ->execute([$targetId, $this->wsId]);
        traceLog($this->uid, 'user_delete', 'user', $targetId, "删除用户 uid:{$targetId}");
        return ['deleted' => true, 'user_id' => $targetId];
    }

    /** 创建子用户（继承主用户数据） */
    public function createSubUser(array $post): array
    {
        $subName = trim((string)($post['sub_username'] ?? ''));
        $subPw   = (string)($post['sub_password'] ?? '');
        $perms   = $this->sanitizePerms($post['sub_perms'] ?? []);
        if (strlen($subName) < 3) {
            throw new AdminException('用户名长度至少 3 个字符', 1);
        }
        if (!isStrongPassword($subPw)) {
            throw new AdminException('密码至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种', 1);
        }
        $hash = password_hash($subPw, PASSWORD_DEFAULT);
        try {

            $this->db->prepare("INSERT INTO users (workspace_id, username, password_hash, role, parent_id, permissions)
                                VALUES (?, ?, ?, 'user', ?, ?)")
                     ->execute([$this->wsId, $subName, $hash, $this->uid, $perms]);
        } catch (\PDOException $e) {
            throw new AdminException('用户名已存在', 1);
        }
        $newSubId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'create_sub_user', 'user', $newSubId, "创建子用户 username:{$subName}");
        return ['user_id' => $newSubId, 'username' => $subName];
    }

    /** 更新子用户权限 */
    public function updateSubUser(int $targetId, array $perms): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->isOwnSubUser($targetId)) {
            throw new AdminException('只能管理自己的子用户', 403);
        }
        $permJson = $this->sanitizePerms($perms);
        $this->db->prepare("UPDATE users SET permissions=? WHERE id=? AND parent_id=? AND workspace_id=?")
                 ->execute([$permJson, $targetId, $this->uid, $this->wsId]);
        traceLog($this->uid, 'update_sub_user', 'user', $targetId, "更新子用户权限 uid:{$targetId}");
        return ['user_id' => $targetId];
    }

    /** 删除子用户 */
    public function deleteSubUser(int $targetId): array
    {
        if ($targetId <= 0) {
            throw new AdminException('目标用户无效', 1);
        }
        if (!$this->isOwnSubUser($targetId)) {
            throw new AdminException('只能管理自己的子用户', 403);
        }
        $this->db->prepare("DELETE FROM users WHERE id=? AND parent_id=? AND workspace_id=?")
                 ->execute([$targetId, $this->uid, $this->wsId]);
        traceLog($this->uid, 'delete_sub_user', 'user', $targetId, "删除子用户 uid:{$targetId}");
        return ['user_id' => $targetId];
    }

    /** 权限白名单过滤，返回 JSON 字符串 */
    private function sanitizePerms(array $raw): string
    {
        $clean = array_values(array_intersect($raw, self::PERM_ALLOWLIST));
        return json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** 检查是否可管理目标用户（主管理员或目标是自己的子用户） */
    private function canManageUser(int $targetId): bool
    {
        if ($this->isPrimary) return true;
        return $this->isOwnSubUser($targetId);
    }

    /** 检查目标用户是否为当前用户的子用户（限当前工作空间） */
    private function isOwnSubUser(int $targetId): bool
    {
        $sub = $this->db->prepare("SELECT id FROM users WHERE id=? AND parent_id=? AND workspace_id=?");
        $sub->execute([$targetId, $this->uid, $this->wsId]);
        return (bool)$sub->fetch();
    }
}

/** 管理后台业务异常 */
final class AdminException extends \Exception
{
    public int $errCode;
    public function __construct(string $msg, int $code = 1)
    {
        parent::__construct($msg);
        $this->errCode = $code;
    }
}
