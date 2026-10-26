<?php
declare(strict_types=1);
/** 平台管理模块（v1.1.0 正式版） */

class PlatformException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message);
        $this->errCode = $errCode;
    }
}

/** 平台管理器 */
final class PlatformManager
{
    private PDO $db;
    private int $uid;       // 当前操作用户ID（用于日志记录）
    private int $dataUid;   // 数据所属用户ID（子用户继承父用户数据）
    private int $workspaceId; // 工作空间ID

    /** 允许的平台类型白名单 */
    private const ALLOWED_TYPES = ['standard', 'loose'];

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid         = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = getWorkspaceId();
    }

    /** 平台列表（含 code 字段，供前端下拉和二维码 pid 标识使用） */
    public function listPlatforms(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, code, name, url_template, is_default, platform_type
             FROM platforms WHERE user_id=? AND workspace_id=? ORDER BY id"
        );
        $stmt->execute([$this->dataUid, $this->workspaceId]);
        $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($platforms as &$p) {
            $p['id']             = (int)$p['id'];
            $p['is_default']     = (int)$p['is_default'];
            $p['platform_type']  = in_array($p['platform_type'] ?? '', self::ALLOWED_TYPES, true)
                ? $p['platform_type'] : 'standard';
        }
        unset($p);

        return ['platforms' => $platforms];
    }

    /** 单个平台完整详情 */
    public function getPlatform(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);

        $stmt = $this->db->prepare(
            "SELECT id, code, name, url_template, is_default, platform_type
             FROM platforms WHERE id=? AND user_id=? AND workspace_id=?"
        );
        $stmt->execute([$id, $this->dataUid, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformException('平台不存在或无权访问', 404);

        $row['id']             = (int)$row['id'];
        $row['is_default']     = (int)$row['is_default'];
        $row['platform_type']  = in_array($row['platform_type'] ?? '', self::ALLOWED_TYPES, true)
            ? $row['platform_type'] : 'standard';

        // 统计该平台下元件数量
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE platform_id=? AND user_id=? AND workspace_id=?");
        $cntStmt->execute([$id, $this->dataUid, $this->workspaceId]);
        $row['parts_count'] = (int)$cntStmt->fetchColumn();

        return ['platform' => $row];
    }

    /** 单个平台的类型属性（供前端弹窗异步查询，区分 standard/loose） */
    public function getPlatformType(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：platform_id 必填', 4);

        $stmt = $this->db->prepare(
            "SELECT id, code, name, platform_type, url_template
             FROM platforms WHERE id=? AND user_id=? AND workspace_id=?"
        );
        $stmt->execute([$id, $this->dataUid, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new PlatformException('平台不存在或无权访问', 404);

        $ptype = in_array($row['platform_type'] ?? '', self::ALLOWED_TYPES, true)
            ? $row['platform_type'] : 'standard';

        return [
            'platform_id'   => (int)$row['id'],
            'code'          => $row['code'] ?? '',
            'name'          => $row['name'] ?? '',
            'platform_type' => $ptype,
            'url_template'  => $row['url_template'] ?? '',
        ];
    }

    /** 添加平台 */
    public function addPlatform(array $p): array
    {
        $code  = $this->safeStr($p['plat_code'] ?? '');
        $name  = $this->safeStr($p['plat_name'] ?? '');
        $url   = $this->safeStr($p['plat_url'] ?? '');
        $ptype = $this->normalizeType($p['platform_type'] ?? 'standard');

        // 参数校验
        if ($code === '' || $name === '') {
            throw new PlatformException('平台代码和名称不能为空', 4);
        }
        $this->assertUrlValid($url);

        // 代码唯一性校验
        if ($this->codeExists($code)) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        // 插入平台
        try {
            $this->db->prepare(
                "INSERT INTO platforms (user_id, code, name, url_template, platform_type, workspace_id) VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$this->dataUid, $code, $name, $url, $ptype, $this->workspaceId]);
        } catch (\Throwable $e) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        $newId = (int)$this->db->lastInsertId();

        // 默认平台自动设置：首个平台自动设为默认；若无默认平台则补设
        $this->ensureDefaultExists($newId);

        traceLog($this->uid, 'add_platform', 'platform', $newId, "添加平台 code:{$code} name:{$name}");

        return [
            'platform_id' => $newId,
            'code'        => $code,
            'name'        => $name,
        ];
    }

    /** 编辑平台 */
    public function editPlatform(array $p): array
    {
        $id    = (int)($p['plat_id'] ?? 0);
        $code  = $this->safeStr($p['plat_code'] ?? '');
        $name  = $this->safeStr($p['plat_name'] ?? '');
        $url   = $this->safeStr($p['plat_url'] ?? '');
        $ptype = $this->normalizeType($p['platform_type'] ?? 'standard');

        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);
        if ($code === '' || $name === '') {
            throw new PlatformException('平台代码和名称不能为空', 4);
        }
        $this->assertUrlValid($url);

        // 验证平台归属
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        // 代码唯一性校验（排除自身）
        if ($this->codeExists($code, $id)) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        try {
            $this->db->prepare(
                "UPDATE platforms SET code=?, name=?, url_template=?, platform_type=? WHERE id=? AND user_id=? AND workspace_id=?"
            )->execute([$code, $name, $url, $ptype, $id, $this->dataUid, $this->workspaceId]);
        } catch (\Throwable $e) {
            throw new PlatformException('平台代码重复，请更换', 4);
        }

        traceLog($this->uid, 'edit_platform', 'platform', $id, "编辑平台 id:{$id} code:{$code}");

        return [
            'platform_id' => $id,
            'code'        => $code,
            'name'        => $name,
        ];
    }

    /** 删除平台（含级联清理元件相关数据） */
    public function deletePlatform(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);

        // 至少保留 1 个平台
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND workspace_id=?");
        $totalStmt->execute([$this->dataUid, $this->workspaceId]);
        $totalPlatforms = (int)$totalStmt->fetchColumn();
        if ($totalPlatforms <= 1) {
            throw new PlatformException('至少保留一个平台，无法删除最后一个平台', 4);
        }

        // 验证平台归属
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        // 统计删除前该平台下的元件数
        $cntStmt = $this->db->prepare("SELECT COUNT(*) FROM parts WHERE platform_id=? AND user_id=? AND workspace_id=?");
        $cntStmt->execute([$id, $this->dataUid, $this->workspaceId]);
        $delCount = (int)$cntStmt->fetchColumn();

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare("SELECT id FROM platforms WHERE user_id=? AND workspace_id=? FOR UPDATE");
            $lock->execute([$this->dataUid, $this->workspaceId]);

            $totalStmt2 = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND workspace_id=?");
            $totalStmt2->execute([$this->dataUid, $this->workspaceId]);
            if ((int)$totalStmt2->fetchColumn() <= 1) {
                throw new PlatformException('至少保留一个平台，无法删除最后一个平台', 4);
            }

            // 收集该平台下所有元件ID
            $partsStmt = $this->db->prepare("SELECT id FROM parts WHERE platform_id=? AND user_id=? AND workspace_id=?");
            $partsStmt->execute([$id, $this->dataUid, $this->workspaceId]);
            $partIds = array_column($partsStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

            if (!empty($partIds)) {
                $in = implode(',', array_fill(0, count($partIds), '?'));

                $this->db->prepare("DELETE FROM stock_log WHERE part_id IN ($in) AND user_id=?")
                    ->execute([...$partIds, $this->dataUid]);
                $this->db->prepare("DELETE FROM price_history WHERE part_id IN ($in)")
                    ->execute($partIds);
                $this->db->prepare("DELETE FROM scan_log WHERE part_id IN ($in) AND user_id=?")
                    ->execute([...$partIds, $this->uid]);
                $this->db->prepare("DELETE FROM part_categories WHERE part_id IN ($in)")
                    ->execute($partIds);
            }

            // 软删除该平台下所有元件（设置status='deleted'）
            $this->db->prepare("UPDATE parts SET status='deleted', update_time=NOW() WHERE platform_id=? AND user_id=? AND workspace_id=?")
                ->execute([$id, $this->dataUid, $this->workspaceId]);

            // 与平台一体：扫码参数配置 / 导入模板属该平台，随平台一并删除

            $this->db->prepare("DELETE FROM import_template WHERE workspace_id=? AND platform_id=?")
                ->execute([$this->workspaceId, $id]);

            // 删除平台
            $delPlat = $this->db->prepare("DELETE FROM platforms WHERE id=? AND user_id=? AND workspace_id=?");
            $delPlat->execute([$id, $this->dataUid, $this->workspaceId]);
            if ($delPlat->rowCount() === 0) {
                $this->db->rollBack();
                throw new PlatformException('平台删除失败，请检查日志后重试', 4);
            }

            // 确保至少有一个默认平台
            $this->ensureDefaultExists();

            $this->db->commit();
        } catch (PlatformException $e) {
            try { $this->db->rollBack(); } catch (\Throwable $re) {}
            throw $e;
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $re) {}
            error_log('PlatformManager::deletePlatform error: ' . $e->getMessage());
            throw new PlatformException('平台删除失败，请检查日志后重试', 4);
        }

        traceLog($this->uid, 'delete_platform', 'platform', $id, "删除平台 id:{$id} 删除元件数:{$delCount}");

        return [
            'platform_id'    => $id,
            'deleted_parts'  => $delCount,
        ];
    }

    /** 设为默认平台 */
    public function setDefault(int $id): array
    {
        if ($id <= 0) throw new PlatformException('参数错误：平台ID必填', 4);
        if (!$this->platformBelongsToUser($id)) {
            throw new PlatformException('平台不存在或无权操作', 404);
        }

        $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT id FROM platforms WHERE user_id=? AND workspace_id=? FOR UPDATE");
            $lock->execute([$this->dataUid, $this->workspaceId]);

            // 清除所有默认标记，再设置当前为默认
            $this->db->prepare("UPDATE platforms SET is_default=0 WHERE user_id=? AND workspace_id=?")
                ->execute([$this->dataUid, $this->workspaceId]);
            $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=? AND workspace_id=?")
                ->execute([$id, $this->dataUid, $this->workspaceId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            try { $this->db->rollBack(); } catch (\Throwable $re) {}
            throw $e;
        }

        traceLog($this->uid, 'set_default_platform', 'platform', $id, "设置默认平台 id:{$id}");

        return ['platform_id' => $id];
    }

    /** 安全字符串过滤 */
    private function safeStr(mixed $v): string
    {
        return trim((string)$v);
    }

    /** 平台类型归一化 */
    private function normalizeType(string $type): string
    {
        return in_array($type, self::ALLOWED_TYPES, true) ? $type : 'standard';
    }

    /** URL 模板校验：非空时必须 http(s):// 开头 */
    private function assertUrlValid(string $url): void
    {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new PlatformException('URL 模板必须以 http:// 或 https:// 开头', 4);
        }
    }

    /** 检查平台代码是否已存在（编辑时排除自身） */
    private function codeExists(string $code, int $excludeId = 0): bool
    {
        if ($excludeId > 0) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND code=? AND id<>? AND workspace_id=?");
            $stmt->execute([$this->dataUid, $code, $excludeId, $this->workspaceId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND code=? AND workspace_id=?");
            $stmt->execute([$this->dataUid, $code, $this->workspaceId]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    /** 验证平台归属当前用户 */
    private function platformBelongsToUser(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM platforms WHERE id=? AND user_id=? AND workspace_id=?");
        $stmt->execute([$id, $this->dataUid, $this->workspaceId]);
        return (bool)$stmt->fetch();
    }

    /** 确保至少有一个默认平台 */
    private function ensureDefaultExists(int $newId = 0): void
    {

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare("SELECT id FROM platforms WHERE user_id=? AND workspace_id=? FOR UPDATE");
            $lock->execute([$this->dataUid, $this->workspaceId]);

            $defStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND is_default=1 AND workspace_id=?");
            $defStmt->execute([$this->dataUid, $this->workspaceId]);
            $hasDefault = (int)$defStmt->fetchColumn();

            if ($hasDefault === 0) {
                if ($newId > 0) {
                    $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=? AND workspace_id=?")
                        ->execute([$newId, $this->dataUid, $this->workspaceId]);
                } else {
                    $this->db->prepare("UPDATE platforms SET is_default=1 WHERE user_id=? AND workspace_id=? ORDER BY id LIMIT 1")
                        ->execute([$this->dataUid, $this->workspaceId]);
                }
            } elseif ($newId > 0) {
                // 首个平台自动设为默认：若这是第一个平台（total=1），强制设为默认
                $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND workspace_id=?");
                $totalStmt->execute([$this->dataUid, $this->workspaceId]);
                $total = (int)$totalStmt->fetchColumn();
                if ($total === 1) {
                    $this->db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=? AND workspace_id=?")
                        ->execute([$newId, $this->dataUid, $this->workspaceId]);
                }
            }

            if ($ownTx) $this->db->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (\Throwable $re) {}
            }
            throw $e;
        }
    }
}
