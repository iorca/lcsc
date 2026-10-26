<?php
declare(strict_types=1);
/** 操作溯源日志模块（v1.1.0 正式版） */

class TraceException extends RuntimeException
{
    public int $errCode;

    public function __construct(string $message, int $errCode = 1)
    {
        parent::__construct($message);
        $this->errCode = $errCode;
    }
}

/** 溯源日志管理器 */
final class TraceManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private bool $isPrimary;

    /** 默认每页条数（满足"列表默认仅展示最新100条"需求） */
    public const DEFAULT_PER_PAGE = 100;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
        $this->isPrimary = isPrimaryAdmin();
    }

    /** 动作码 → 中文标签（页面 / 移动端 / 导出共用的唯一来源） */
    public static function actionLabels(): array
    {
        return [
            'login' => '登录', 'logout' => '登出', 'change_password' => '修改密码',
            'user_reset_pw' => '重置密码', 'user_delete' => '删除用户',
            'create_sub_user' => '创建子用户', 'update_sub_user' => '更新子用户', 'delete_sub_user' => '删除子用户',
            'save_settings' => '保存设置', 'save_user_config' => '保存全局配置', 'save_edition' => '锁定版本',
            'save_home_cards' => '保存工作台卡片',
            'add_part' => '添加物料', 'edit_part' => '编辑物料', 'delete_part' => '删除物料',
            'duplicate_part' => '复制物料', 'part_merge' => '合并物料',
            'batch_delete_parts' => '批量删除物料', 'batch_set_category' => '批量设置分类',
            'batch_set_location' => '批量修改库位', 'batch_set_remark' => '批量设置备注',
            'batch_set_threshold' => '批量设置阈值', 'update_status' => '状态变更',
            'upload_attachment' => '上传附件', 'delete_attachment' => '删除附件',
            'stock_change' => '手动出入库', 'scan_in' => '扫码入库', 'scan_out' => '扫码出库',
            'scan_undo' => '撤销扫码', 'manual_out' => '手动出库', 'damaged' => '报损', 'repair' => '修复',
            'bom_out' => 'BOM 出库', 'batch_stock_in' => '批量入库', 'check_import' => '盘点导入',
            'delete_log' => '删除流水', 'batch_delete_logs' => '批量删除流水',
            'export_stock_full' => '导出出入库记录', 'review_undo' => '复核撤销流水',
            'add_platform' => '添加平台', 'edit_platform' => '编辑平台', 'delete_platform' => '删除平台',
            'set_default_platform' => '设为默认平台',
            'cat_create' => '新增分类', 'cat_rename' => '分类重命名', 'cat_delete' => '分类删除',
            'cat_merge' => '分类合并', 'cat_bind_parent' => '绑定上级分类',
            'topcat_add' => '新增一级大类', 'topcat_rename' => '重命名一级大类', 'topcat_delete' => '删除一级大类',
            'batch_set_category_location' => '按分类设置库位',
            'add_purchase_record' => '添加采购记录', 'purchase_inbound' => '采购入库',
            'bom_project_create' => '创建 BOM 项目', 'bom_project_update' => '编辑 BOM 项目',
            'bom_project_delete' => '删除 BOM 项目', 'bom_batch_add' => 'BOM 添加物料',
            'bom_batch_delete' => 'BOM 删除物料', 'bom_checkout' => 'BOM 出库',
            'bom_use_alt' => 'BOM 替代料出库', 'bom_replace_alt' => 'BOM 替换标准料',
            'bom_update_margin' => 'BOM 设置利润率', 'bom_update_item' => '编辑 BOM 物料',
            'bom_pending_complete' => '补全待补物料', 'bom_pending_batch_complete' => '批量补全待补物料',
            'bom_version_create' => '创建 BOM 版本', 'bom_version_clone' => '克隆 BOM 版本',
            'bom_version_release' => '发布 BOM 版本', 'bom_version_update' => '编辑 BOM 版本',
            'bom_version_archive' => '归档 BOM 版本', 'bom_version_delete' => '删除 BOM 版本',
            'bom_version_rollback' => '回滚 BOM 版本', 'bom_import' => '导入 BOM',
            'bom_export_items' => '导出 BOM', 'update_bom_status' => 'BOM 状态变更',
            'alt_bind' => '绑定替代料', 'alt_unbind' => '解绑替代料',
            'alt_confirm' => '确认替代料', 'alt_reorder' => '替代料排序',
            'import_order' => '订单导入', 'import_rollback' => '撤销导入',
            'order_columns_save' => '保存订单列设置', 'order_field_add' => '新增订单字段',
            'order_field_toggle' => '启停订单字段', 'order_field_delete' => '删除订单字段',
            'backup' => '数据备份', 'backup_database' => '备份到服务器', 'backup_webdav' => 'WebDAV 备份',
            'backup_schedule' => '定时备份配置', 'backup_cleanup' => '清理过期备份',
            'backup_auto' => '自动备份', 'backup_delete' => '删除备份',
            'restore' => '恢复备份', 'restore_database' => '恢复服务器备份',
            'system_upgrade' => '一键升级', 'online_upgrade' => '在线升级', 'system_install' => '系统安装',
            'rebuild_all_cost' => '重建成本', 'delete_login_log' => '删除登录记录',
            'batch_delete_login_logs' => '批量删除登录记录',
            'pending_scan' => '待处理扫描', 'pending_fix' => '待处理修正', 'pending_ignore' => '待处理忽略',
            'rollback' => '数据回滚', 'rollback_restore' => '回滚恢复',
            'health_refresh' => '数据质量重检', 'health_fix_sync_stock' => '修复库存缓存',
            'health_fix_recalc_cost' => '重算成本', 'health_fix_cleanup_deprecated' => '清理废弃字段',
            'health_fix_cleanup_import_residue' => '清理导入中间数据',
            'health_ignore_on' => '忽略检测项', 'health_ignore_off' => '恢复检测项',
            'db_migration' => '数据库迁移', 'cache_purge' => '清理外部缓存',
            'qr_config_save' => '保存扫码参数', 'qr_config_reset' => '重置扫码参数',
            'qr_config_import' => '导入扫码参数', 'qr_config_create' => '创建平台并应用扫码参数',
            'apikey_create' => '创建 API Key', 'apikey_delete' => '删除 API Key',
            'apikey_update_expiry' => '调整 API Key 有效期', 'webdav_delete_failed' => 'WebDAV 删除失败',
            'loc_create' => '新增库位', 'loc_rename' => '重命名库位', 'loc_move' => '移动库位',
            'loc_delete' => '删除库位', 'loc_import' => '导入库位', 'loc_bind' => '绑定物料库位',
            'loc_backfill' => '存量库位回填',
            'check_create' => '发起盘点', 'check_count' => '盘点录数', 'check_skip' => '跳过盘点',
            'check_confirm' => '确认盘点', 'check_cancel' => '取消盘点',
            'checklist_export' => '导出盘点清单',
            'stale_mark' => '标记呆滞已处理', 'stale_unmark' => '取消呆滞标记', 'stale_export' => '导出呆滞清单',
        ];
    }

    /** 动作码 → 中文标签；未登记时返回原码 */
    public static function labelAction(string $code): string
    {
        $map = self::actionLabels();
        return $map[$code] ?? $code;
    }

    /** 目标类型码 → 中文标签 */
    public static function targetTypeLabels(): array
    {
        return [
            'part' => '物料', 'category' => '分类', 'platform' => '平台', 'system' => '系统',
            'stock_log' => '出入库流水', 'scan_log' => '扫码记录', 'backup' => '备份',
            'setting' => '设置', 'login_attempt' => '登录记录', 'user' => '用户',
            'bom_project' => 'BOM 项目', 'bom_item' => 'BOM 物料', 'bom_version' => 'BOM 版本',
            'part_alternatives' => '替代料', 'imported_file' => '导入批次',
            'pending_items' => '待处理记录', 'stock' => '库存', 'location' => '库位',
            'check_session' => '盘点单', 'user_setting' => '用户设置', 'export' => '导出记录',
            'import_document' => '导入文档', 'api_key' => 'API Key',
        ];
    }

    /** 目标类型码 → 中文标签；未登记时返回原码 */
    public static function labelTargetType(string $code): string
    {
        $map = self::targetTypeLabels();
        return $map[$code] ?? $code;
    }

    /** 构建权限范围 WHERE 子句 */
    private function buildScopeWhere(): array
    {
        if ($this->isPrimary) {
            return ['', []];
        }
        // 普通管理员：自身 + 数据归属用户（含子用户）
        $subStmt = $this->db->prepare("SELECT id FROM users WHERE parent_id=?");
        $subStmt->execute([$this->uid]);
        $subIds = array_map('intval', array_column($subStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $userIds = array_values(array_unique(array_merge([$this->uid, $this->dataUid], $subIds)));
        if (count($userIds) === 1) {
            return ['user_id = ?', $userIds];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        return ["user_id IN ($in)", $userIds];
    }

    /** 溯源日志列表查询（支持动作/目标类型/操作人筛选） */
    public function listLogs(array $f): array
    {
        $dateFrom   = trim((string)($f['date_from'] ?? ''));
        $dateTo     = trim((string)($f['date_to'] ?? ''));
        $action     = trim((string)($f['action'] ?? ''));
        $targetType = trim((string)($f['target_type'] ?? ''));
        $operator   = trim((string)($f['operator'] ?? ''));
        $mine       = !empty($f['mine']);
        $perPage    = (int)($f['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage    = max(10, min(500, $perPage));
        $page       = max(1, (int)($f['page'] ?? 1));

        // 构建权限范围 + 日期筛选
        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereParts = [];
        $params = $scopeParams;
        if ($scopeWhere !== '') $whereParts[] = $scopeWhere;
        // 只看本人：在权限范围内进一步收窄（移动端「我的操作记录」）。

        if ($mine) {
            $whereParts[] = 't.user_id = ?';
            $params[] = $this->uid;
        }
        if ($dateFrom !== '') {
            $whereParts[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereParts[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        // 动作筛选
        if ($action !== '') {
            $whereParts[] = 't.action = ?';
            $params[] = $action;
        }
        // 目标类型筛选
        if ($targetType !== '') {
            $whereParts[] = 't.target_type = ?';
            $params[] = $targetType;
        }
        // 操作人筛选（按用户名模糊匹配）
        if ($operator !== '') {
            $whereParts[] = 'u.username LIKE ?';
            $params[] = '%' . $operator . '%';
        }
        $whereSql = '';
        if (!empty($whereParts)) $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        // 总数查询
        $cntStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM trace_log t LEFT JOIN users u ON u.id = t.user_id $whereSql"
        );
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // 列表查询（LEFT JOIN users 获取操作用户名，含快照字段）
        $sql = "SELECT t.id, t.user_id, t.action, t.target_type, t.target_id, t.detail, t.ip, t.created_at,
                       t.snapshot_before, t.snapshot_after,
                       u.username
                FROM trace_log t LEFT JOIN users u ON u.id = t.user_id
                $whereSql
                ORDER BY t.created_at DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 规范化输出
        foreach ($logs as &$l) {
            $l['id']         = (int)$l['id'];
            $l['user_id']    = (int)$l['user_id'];
            $l['target_id']  = (int)$l['target_id'];
            $l['username']   = $l['username'] ?? '';
            $l['detail']     = $l['detail'] ?? '';
            $l['ip']         = $l['ip'] ?? '';
            $l['action']     = $l['action'] ?? '';
            $l['target_type']= $l['target_type'] ?? '';

            $l['action_label']      = self::labelAction((string)$l['action']);
            $l['target_type_label'] = self::labelTargetType((string)$l['target_type']);
            $l['snapshot_before'] = $l['snapshot_before'] ?? null;
            $l['snapshot_after']  = $l['snapshot_after'] ?? null;
            $l['has_snapshot']    = !empty($l['snapshot_before']);
        }
        unset($l);

        return [
            'logs'        => $logs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $totalPages,
            'per_page'    => $perPage,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'action'      => $action,
            'target_type' => $targetType,
            'operator'    => $operator,
            'mine'        => $mine,
        ];
    }

    /** 获取筛选下拉选项：当前权限范围内实际存在的动作与目标类型（DISTINCT） */
    public function getFilterOptions(): array
    {
        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereSql = $scopeWhere !== '' ? 'WHERE ' . $scopeWhere : '';

        $aStmt = $this->db->prepare(
            "SELECT DISTINCT action FROM trace_log t $whereSql ORDER BY action ASC"
        );
        $aStmt->execute($scopeParams);
        $actions = array_values(array_filter(array_column($aStmt->fetchAll(PDO::FETCH_ASSOC), 'action'), fn($v) => $v !== null && $v !== ''));

        $tStmt = $this->db->prepare(
            "SELECT DISTINCT target_type FROM trace_log t $whereSql ORDER BY target_type ASC"
        );
        $tStmt->execute($scopeParams);
        $types = array_values(array_filter(array_column($tStmt->fetchAll(PDO::FETCH_ASSOC), 'target_type'), fn($v) => $v !== null && $v !== ''));

        return ['actions' => $actions, 'target_types' => $types];
    }

    /** 查询指定日期范围的溯源日志（用于 CSV 导出） */
    public function getLogsForExport(string $dateFrom, string $dateTo): array
    {
        $dateFrom = trim($dateFrom);
        $dateTo   = trim($dateTo);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereParts = [];
        $params = $scopeParams;
        if ($scopeWhere !== '') $whereParts[] = $scopeWhere;
        if ($dateFrom !== '') {
            $whereParts[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $whereParts[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereSql = '';
        if (!empty($whereParts)) $whereSql = 'WHERE ' . implode(' AND ', $whereParts);

        $sql = "SELECT t.id, t.user_id, t.action, t.target_type, t.target_id, t.detail, t.ip, t.created_at,
                       u.username
                FROM trace_log t LEFT JOIN users u ON u.id = t.user_id
                $whereSql
                ORDER BY t.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($logs as &$l) {
            $l['id']         = (int)$l['id'];
            $l['user_id']    = (int)$l['user_id'];
            $l['target_id']  = (int)$l['target_id'];
            $l['username']   = $l['username'] ?? '';
            // 导出同样输出中文标签（与页面同一来源）
            $l['action_label']      = self::labelAction((string)($l['action'] ?? ''));
            $l['target_type_label'] = self::labelTargetType((string)($l['target_type'] ?? ''));
        }
        unset($l);

        return $logs;
    }

    /** 删除单条溯源日志（受留存时效限制，未满留存期禁止删除） */
    public function deleteLog(int $logId): array
    {
        if ($logId <= 0) throw new TraceException('参数无效', 4);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $whereSql = 'WHERE t.id=?';
        $params = [$logId];
        if ($scopeWhere !== '') {
            $whereSql .= " AND $scopeWhere";
            $params = array_merge([$logId], $scopeParams);
        }

        $stmt = $this->db->prepare("SELECT t.id, t.created_at FROM trace_log t $whereSql");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new TraceException('记录不存在或无权操作', 3);

        if (!isRetentionExpired((string)$row['created_at'])) {
            $days = getRetentionDays();
            throw new TraceException("溯源日志未满 {$days} 天留存期，禁止删除", 5);
        }

        $this->db->prepare("DELETE FROM trace_log WHERE id=?")->execute([$logId]);
        return ['deleted' => true, 'log_id' => $logId];
    }

    /** 批量删除溯源日志（受留存时效限制，未满留存期的自动跳过） */
    public function batchDeleteLogs(array $logIds): array
    {
        $logIds = array_values(array_filter(array_map('intval', $logIds), fn($v) => $v > 0));
        if (empty($logIds)) throw new TraceException('未选择有效记录', 4);

        [$scopeWhere, $scopeParams] = $this->buildScopeWhere();
        $in = implode(',', array_fill(0, count($logIds), '?'));
        $whereSql = "t.id IN ($in)";
        $params = $logIds;
        if ($scopeWhere !== '') {
            $whereSql .= " AND $scopeWhere";
            $params = array_merge($logIds, $scopeParams);
        }

        $stmt = $this->db->prepare("SELECT t.id, t.created_at FROM trace_log t WHERE $whereSql");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) throw new TraceException('无权删除这些记录', 3);

        $deletable = [];
        $skipped = 0;
        foreach ($rows as $r) {
            if (isRetentionExpired((string)$r['created_at'])) {
                $deletable[] = (int)$r['id'];
            } else {
                $skipped++;
            }
        }

        if (empty($deletable)) {
            $days = getRetentionDays();
            throw new TraceException("所选记录均未满 {$days} 天留存期，禁止删除", 5);
        }

        $inV = implode(',', array_fill(0, count($deletable), '?'));
        $this->db->prepare("DELETE FROM trace_log WHERE id IN ($inV)")->execute($deletable);
        return ['deleted' => count($deletable), 'skipped' => $skipped];
    }
}
