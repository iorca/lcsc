<?php
declare(strict_types=1);

/** BOM 业务模块 */

final class BomManager
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid        = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = getWorkspaceId();
    }

    /** 匹配库存元件（全平台匹配，不按平台过滤；导入与列表读取共用，保证口径一致） */
    public function matchPart(string $partNo, string $model, string $package = ''): ?array
    {
        $cols    = "id,stock,model,platform_part_no,platform_id,product_name,brand,package,parameters,product_type";
        $partNo  = trim($partNo);
        $model   = trim($model);
        $package = trim($package);

        if ($partNo !== '') {
            $stmt = $this->db->prepare("SELECT {$cols} FROM parts WHERE user_id=? AND platform_part_no=? LIMIT 1");
            $stmt->execute([$this->dataUid, $partNo]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($r) return $r;
        }
        if ($model !== '' && $package !== '') {
            $stmt = $this->db->prepare("SELECT {$cols} FROM parts WHERE user_id=? AND model=? AND package=? LIMIT 2");
            $stmt->execute([$this->dataUid, $model, $package]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) return $rows[0];
        }
        if ($model !== '') {
            $stmt = $this->db->prepare("SELECT {$cols} FROM parts WHERE user_id=? AND model=? LIMIT 2");
            $stmt->execute([$this->dataUid, $model]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 1) return $rows[0];
        }
        return null;
    }

    /** 解析待补行 pending_json（容错：非法 JSON 返回空数组） */
    private function decodePendingJson($raw): array
    {
        if (!is_string($raw) || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** 校验 BOM 项目归属并返回项目行 */
    public function loadOwnedProject(int $pid): array
    {
        $stmt = $this->db->prepare("SELECT id,user_id,name,description,plat_id,profit_margin,current_version_id,version_managed,start_version,created_at,updated_at FROM bom_projects WHERE id=? AND user_id=? AND workspace_id=?");
        $stmt->execute([$pid, $this->dataUid, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BomException('BOM 项目不存在或无权访问');
        return $row;
    }

    /** 确保项目存在一个"当前编辑草稿版本"。 */
    public function ensureCurrentDraft(int $pid): array
    {
        $project = $this->loadOwnedProject($pid);
        if (!empty($project['current_version_id'])) {
            $v = $this->getVersion((int)$project['current_version_id']);
            if ($v) return $v;
        }
        // 无草稿：创建初始草稿
        $managed = (int)($project['version_managed'] ?? 1);
        $initialVersion = $managed ? ($project['start_version'] ?: 'V1.0') : '工作副本';
        $this->db->prepare("INSERT INTO bom_versions (workspace_id, project_id, version, status, description, created_by) VALUES (?,?,?,?,?,?)")
                 ->execute([$this->workspaceId, $pid, $initialVersion, 'draft', '初始编辑草稿（自动创建）', $this->uid]);
        $draftId = (int)$this->db->lastInsertId();
        $this->db->prepare("UPDATE bom_projects SET current_version_id=? WHERE id=?")
                 ->execute([$draftId, $pid]);

        try {
            $this->db->prepare("UPDATE bom_items SET version_id=? WHERE project_id=? AND workspace_id=? AND version_id IS NULL")
                     ->execute([$draftId, $pid, $this->workspaceId]);
        } catch (Throwable $e) {}
        return ['id' => $draftId, 'version' => $initialVersion, 'status' => 'draft', 'project_id' => $pid];
    }

    /** 解析"当前应展示/编辑的版本 ID"： */
    public function resolveVersionId(int $pid, $requestedVersionId = null): int
    {
        $rv = (int)($requestedVersionId ?? 0);
        if ($rv > 0) {
            $v = $this->getVersion($rv);
            if ($v && (int)$v['project_id'] === $pid) return $rv;
        }
        $draft = $this->ensureCurrentDraft($pid);
        return (int)$draft['id'];
    }

    /** 版本是否只读（released/archived） */
    public function isVersionReadonly(int $versionId): bool
    {
        $v = $this->getVersion($versionId);
        return $v && in_array($v['status'], ['released', 'archived'], true);
    }

    /** 列出当前用户所有 BOM 项目（首页批量加入 BOM 选择器用） */
    public function listProjects(): array
    {
        $stmt = $this->db->prepare("SELECT bp.id,bp.name,bp.updated_at,bp.version_managed,bp.start_version,
            bp.current_version_id,
            (SELECT v.version FROM bom_versions v WHERE v.id=bp.current_version_id) AS current_version,
            (SELECT COUNT(*) FROM bom_versions v WHERE v.project_id=bp.id AND v.workspace_id=bp.workspace_id AND v.status='released') AS released_count,
            (SELECT COUNT(*) FROM bom_items bi WHERE bi.project_id=bp.id) AS item_count
            FROM bom_projects bp
            WHERE bp.user_id=? AND bp.workspace_id=?
            ORDER BY bp.updated_at DESC");
        $stmt->execute([$this->dataUid, $this->workspaceId]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'name'        => (string)$r['name'],
            'item_count'  => (int)$r['item_count'],
            'updated_at'  => (string)$r['updated_at'],
            'version_managed' => (int)($r['version_managed'] ?? 1),
            'start_version'   => (string)($r['start_version'] ?? 'V1.0'),
            'current_version' => (string)($r['current_version'] ?? ''),
            'released_count'  => (int)$r['released_count'],
        ], $list);
    }

    /** 查询指定 BOM 项目的物料明细列表（支持状态筛选 + 分页 + 库存预校验） */
    public function listItems(int $projectId, array $params): array
    {
        $project = $this->loadOwnedProject($projectId);

        $filter = (string)($params['filter'] ?? '');
        if (!in_array($filter, ['ok', 'insufficient', 'not_found', 'unbound'], true)) $filter = '';

        $perPage = intval($params['per_page'] ?? ($_COOKIE['per_page_bom'] ?? 25));
        $perPage = max(10, min(50, $perPage));
        $page    = max(1, intval($params['page'] ?? 1));

        $versionId = $this->resolveVersionId($projectId, $params['version_id'] ?? null);
        $curVersion = $this->getVersion($versionId);

        $is = $this->db->prepare("SELECT id,part_id,platform_part_no,customer_part_no,model,qty,sort_order,reference,remark,is_incomplete_part,pending_json FROM bom_items WHERE project_id=? AND workspace_id=? AND version_id=? ORDER BY sort_order, id");
        $is->execute([$projectId, $this->workspaceId, $versionId]);
        $rawItems = $is->fetchAll(PDO::FETCH_ASSOC);

        // 预编译 parts 表查询
        $partStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,platform_id,location,customer_part_no,low_stock_threshold,remark FROM parts WHERE id=? AND user_id=?");

        $summary = ['ok' => 0, 'insufficient' => 0, 'not_found' => 0, 'unbound' => 0];
        $items = [];
        foreach ($rawItems as $it) {
            $partNo = (string)$it['platform_part_no'];
            $model  = (string)$it['model'];
            $qty    = (int)$it['qty'];

            $isPendingRow = ((int)($it['is_incomplete_part'] ?? 0) === 1);
            $pending = $this->decodePendingJson($it['pending_json'] ?? null);

            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([$it['part_id'], $this->dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$part && !$isPendingRow) $part = $this->matchPart($partNo, $model);

            if ($isPendingRow) {
                $row = $it;
                $row['part'] = [
                    'id'               => 0,
                    'stock'            => 0,
                    'model'            => (string)($pending['model'] ?? $model),
                    'platform_part_no' => $partNo,
                    'product_name'     => (string)($pending['product_name'] ?? ''),
                    'brand'            => (string)($pending['brand'] ?? ''),
                    'package'          => (string)($pending['package'] ?? ''),
                    'parameters'       => (string)($pending['parameters'] ?? ''),
                    'product_type'     => (string)($pending['product_type'] ?? ''),
                    'location'         => (string)($pending['location'] ?? ''),
                    'customer_part_no' => (string)($pending['customer_part_no'] ?? ''),
                ];
                $row['pending'] = $pending ?: null;
                $row['pending_missing'] = $this->pendingMissingFields($pending, $model);
                $row['status'] = 'unbound';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $row['alt_parts'] = [];
                $summary['unbound']++;
                $items[] = $row;
                continue;
            }

            // BOM 行自有参数优先：pending_json 里有值就用它覆盖主库读数。

            if ($part && $pending) {
                foreach (['model','brand','package','product_type','product_name','customer_part_no','location','parameters'] as $k) {
                    if (isset($pending[$k]) && trim((string)$pending[$k]) !== '') {
                        $part[$k] = (string)$pending[$k];
                    }
                }
            }

            if (!$part) {
                $part = [
                    'id'               => 0,
                    'stock'            => 0,
                    'model'            => (string)($pending['model'] ?? $model),
                    'platform_part_no' => $partNo,
                    'product_name'     => (string)($pending['product_name'] ?? ''),
                    'brand'            => (string)($pending['brand'] ?? ''),
                    'package'          => (string)($pending['package'] ?? ''),
                    'parameters'       => (string)($pending['parameters'] ?? ''),
                    'product_type'     => (string)($pending['product_type'] ?? ''),
                    'location'         => (string)($pending['location'] ?? ''),
                    'customer_part_no' => (string)($pending['customer_part_no'] ?? ''),
                ];
            }

            $row = $it;
            $row['part'] = $part;
            $row['pending'] = $pending ?: null;

            $row['pending_missing'] = $isPendingRow ? $this->pendingMissingFields($pending, $model) : [];

            if (!$part) {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                $row['alt_parts'] = [];
            } elseif ($part) {
                $stock = (int)$part['stock'];
                $row['stock'] = $stock;
                if ($stock >= $qty) {
                    $row['status'] = 'ok';
                    $row['gap'] = 0;
                    $summary['ok']++;
                } else {
                    $row['status'] = 'insufficient';
                    $row['gap'] = $qty - $stock;
                    $summary['insufficient']++;
                }

                $row['alternatives'] = [];
                $row['alt_parts'] = [];
                try {
                    $altPartStmt = $this->db->prepare("
                        SELECT p.id, p.stock, p.model, p.platform_part_no, p.product_name
                        FROM parts p
                        INNER JOIN part_alternatives pa ON pa.target_part_id = p.id
                        WHERE pa.source_part_id = ? AND p.user_id=? AND p.workspace_id=? AND p.status!='deleted'
                        UNION
                        SELECT p.id, p.stock, p.model, p.platform_part_no, p.product_name
                        FROM parts p
                        INNER JOIN part_alternatives pa ON pa.source_part_id = p.id
                        WHERE pa.target_part_id = ? AND p.user_id=? AND p.workspace_id=? AND p.status!='deleted'
                    ");
                    $altPartStmt->execute([$part['id'], $this->dataUid, $this->workspaceId, $part['id'], $this->dataUid, $this->workspaceId]);
                    $row['alt_parts'] = $altPartStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable) {}
            } else {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                $row['alt_parts'] = [];
            }
            $items[] = $row;
        }

        // 后端先按 status 过滤数据集，再对过滤后的数据做分页与 total 统计
        if ($filter !== '') {
            $items = array_values(array_filter($items, fn($row) => $row['status'] === $filter));
        }
        // 待补行置顶（单表，不做双区域）
        $items = $this->sortPendingFirst($items);
        $total = count($items);
        $totalPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPage);
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);

        return [
            'items'       => $pageItems,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_page'  => $totalPage,
            'summary'     => $summary,
            'project'     => $project,
            'version_id'  => $versionId,
            'version'     => $curVersion['version'] ?? '',
            'readonly'    => $curVersion ? in_array($curVersion['status'], ['released', 'archived'], true) : false,
        ];
    }

    /** 创建 BOM 项目 */
    public function createProject(array $post): array
    {
        $name   = trim((string)($post['name'] ?? ''));
        $desc   = trim((string)($post['description'] ?? ''));

        $versionManaged = \hasFeature('feature_bom_version')
            ? (isset($post['version_managed']) ? ((int)$post['version_managed'] === 1 ? 1 : 0) : 1)
            : 0;
        $projType = self::normalizeProjectType((string)($post['project_type'] ?? ''));
        $startVersion = trim((string)($post['start_version'] ?? ''));
        if ($versionManaged === 1) {
            $startVersion = $this->sanitizeVersion($startVersion, 'V1.0');
        } else {
            $startVersion = '工作副本';
        }
        if ($name === '') {
            throw new BomException('项目名称不能为空');
        }

        // 自动填入全局默认利润率
        $defaultMargin = round(max(0, min(99.99, (float)(getSetting('default_profit_margin') ?: 0))), 2);
        $this->db->prepare("INSERT INTO bom_projects (user_id,name,description,project_type,profit_margin,version_managed,start_version,workspace_id) VALUES (?,?,?,?,?,?,?,?)")
                 ->execute([$this->dataUid, $name, $desc, $projType, $defaultMargin, $versionManaged, $startVersion, $this->workspaceId]);
        $newId = (int)$this->db->lastInsertId();
        // 自动创建初始编辑草稿版本（启动版本号即 start_version）
        try {
            $this->db->prepare("INSERT INTO bom_versions (workspace_id, project_id, version, status, description, created_by) VALUES (?,?,?,?,?,?)")
                     ->execute([$this->workspaceId, $newId, $startVersion, 'draft', '初始编辑草稿', $this->uid]);
            $draftId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE bom_projects SET current_version_id=? WHERE id=?")
                     ->execute([$draftId, $newId]);
        } catch (Throwable $e) {}
        traceLog($this->uid, 'bom_project_create', 'bom_project', $newId, "创建BOM项目:{$name} 利润率:{$defaultMargin}% 版本管理:" . ($versionManaged ? '开' : '关'));
        return ['project_id' => $newId, 'name' => $name, 'profit_margin' => $defaultMargin,
                'version_managed' => $versionManaged, 'start_version' => $startVersion];
    }

    /** 规范化版本号：允许 V 前缀 + 数字.数字，限制数字位数（各段 ≤3），非法则回退默认。 */
    public function sanitizeVersion(string $version, string $default = 'V1.0'): string
    {
        $v = trim((string)$version);
        if (preg_match('/^(?<p>V?)(?<M>\d{1,3})\.(?<m>\d{1,3})$/', $v, $m)) {
            $prefix = $m['p'] === 'V' ? 'V' : '';
            return $prefix . (int)$m['M'] . '.' . (int)$m['m'];
        }
        return $default;
    }

    /** 更新 BOM 项目（名称/描述/版本配置） */
    public function updateProject(array $post): array
    {
        $pid  = (int)($post['project_id'] ?? 0);
        $pr   = $this->loadOwnedProject($pid);
        $name = trim((string)($post['name'] ?? ''));
        $desc = trim((string)($post['description'] ?? ''));
        if ($name === '') {
            throw new BomException('项目名称不能为空');
        }

        $versionManaged = \hasFeature('feature_bom_version')
            ? (isset($post['version_managed']) ? ((int)$post['version_managed'] === 1 ? 1 : 0) : (int)($pr['version_managed'] ?? 1))
            : (int)($pr['version_managed'] ?? 1);
        $startVersion = trim((string)($post['start_version'] ?? $pr['start_version'] ?? ''));
        if ($versionManaged === 1) {
            $startVersion = $this->sanitizeVersion($startVersion, (string)($pr['start_version'] ?: 'V1.0'));
        } else {
            $startVersion = '工作副本';
        }
        // 类型自由文本；
        // 状态仍走白名单（状态驱动筛选与统计，必须是既定枚举）
        $projType = self::normalizeProjectType((string)($post['project_type'] ?? ''));
        $statusAllowed = ['draft', 'testing', 'production', 'finished', 'archived'];
        $projStatus = trim((string)($post['status'] ?? ''));
        if (!in_array($projStatus, $statusAllowed, true)) $projStatus = '';
        $priority = isset($post['priority']) ? max(0, min(2, (int)$post['priority'])) : -1;   // -1=不修改

        $sets = "name=?,description=?,version_managed=?,start_version=?,project_type=?";
        $args = [$name, $desc, $versionManaged, $startVersion, $projType];
        if ($projStatus !== ''){ $sets .= ",status=?";       $args[] = $projStatus; }
        if ($priority >= 0)    { $sets .= ",priority=?";     $args[] = $priority; }
        $args[] = $pid; $args[] = $this->dataUid; $args[] = $this->workspaceId;
        $this->db->prepare("UPDATE bom_projects SET {$sets} WHERE id=? AND user_id=? AND workspace_id=?")->execute($args);
        traceLog($this->uid, 'bom_project_update', 'bom_project', $pid, '编辑BOM项目:' . $name . ($versionManaged ? ' 版本:开' : ' 版本:关'));
        return ['project_id' => $pid, 'name' => $name, 'version_managed' => $versionManaged, 'start_version' => $startVersion];
    }

    /** 项目类型归一化：自由文本（建表即 VARCHAR(20)），只做安全清洗与长度截断 */
    private static function normalizeProjectType(string $type): string
    {
        $type = preg_replace('/[\x00-\x1F<>"]/u', '', trim($type)) ?? '';
        $type = mb_substr($type, 0, 20);
        return $type === '' ? 'bom' : $type;
    }

    /** 删除 BOM 项目（级联删除物料项） */
    public function deleteProject(int $pid): array
    {
        $this->loadOwnedProject($pid);
        $this->db->prepare("DELETE FROM bom_items WHERE project_id=? AND workspace_id=?")->execute([$pid, $this->workspaceId]);
        $this->db->prepare("DELETE FROM bom_projects WHERE id=? AND user_id=? AND workspace_id=?")->execute([$pid, $this->dataUid, $this->workspaceId]);
        traceLog($this->uid, 'bom_project_delete', 'bom_project', $pid, '删除BOM项目ID:' . $pid);
        return ['project_id' => $pid];
    }

    /** 添加单个物料到 BOM 项目 */
    public function addItem(array $post): array
    {
        $pid     = (int)($post['project_id'] ?? 0);
        $pr      = $this->loadOwnedProject($pid);
        $partNo  = trim((string)($post['platform_part_no'] ?? ''));
        $model   = trim((string)($post['model'] ?? ''));
        $qty     = max(1, (int)($post['qty'] ?? 1));
        $versionId = $this->resolveVersionId($pid, $post['version_id'] ?? null);
        if ($this->isVersionReadonly($versionId)) throw new BomException('该版本已发布为只读，请先新建草稿版本再修改');
        $reference = trim((string)($post['reference'] ?? ''));
        $remark    = trim((string)($post['remark'] ?? ''));
        if ($partNo === '' && $model === '') {
            throw new BomException('编号和型号不能同时为空');
        }
        $matchedPart  = $this->matchPart($partNo, $model);
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();
        $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order,version_id,workspace_id,reference,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                 ->execute([$pid, $matchedPart['id'] ?? null, $partNo, $model, $qty, $matchedPart ? 1 : 0, $sortOrder, $versionId, $this->workspaceId, $reference, $remark]);
        return ['project_id' => $pid, 'matched' => $matchedPart ? 1 : 0];
    }

    /** 通过 part_id 直接添加库内已有物料到 BOM（不允许新建元件）。 */
    public function addItemByPartId(array $post): array
    {
        $pid    = (int)($post['project_id'] ?? 0);
        $pr     = $this->loadOwnedProject($pid);
        $partId = (int)($post['part_id'] ?? 0);
        $qty    = max(1, (int)($post['qty'] ?? 1));
        $versionId = $this->resolveVersionId($pid, $post['version_id'] ?? null);
        if ($this->isVersionReadonly($versionId)) throw new BomException('该版本已发布为只读，请先新建草稿版本再修改');
        $reference = trim((string)($post['reference'] ?? ''));
        $remark    = trim((string)($post['remark'] ?? ''));
        if ($partId <= 0) {
            throw new BomException('请从库内选择一个物料');
        }
        // 验证 part 归属当前用户
        $pStmt = $this->db->prepare("SELECT id, platform_part_no, model FROM parts WHERE id=? AND user_id=? LIMIT 1");
        $pStmt->execute([$partId, $this->dataUid]);
        $part = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            throw new BomException('所选物料不存在或无权访问');
        }
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();
        $partNo = (string)$part['platform_part_no'];
        $model  = (string)$part['model'];
        $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order,version_id,workspace_id,reference,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                 ->execute([$pid, $partId, $partNo, $model, $qty, 1, $sortOrder, $versionId, $this->workspaceId, $reference, $remark]);
        return ['project_id' => $pid, 'matched' => 1, 'part_id' => $partId];
    }

    /** 批量添加库内已有物料到 BOM 项目（首页批量加入 BOM 用） */
    public function batchAddByPartIds(array $post): array
    {
        $pid    = (int)($post['project_id'] ?? 0);
        $pr     = $this->loadOwnedProject($pid);
        $ids    = $post['part_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids    = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        $qty    = max(1, (int)($post['qty'] ?? 1));

        $versionId = $this->resolveVersionId($pid, $post['version_id'] ?? null);
        if ($this->isVersionReadonly($versionId)) throw new BomException('该版本已发布为只读，请先新建草稿版本再修改');
        $reference = trim((string)($post['reference'] ?? ''));
        $remark    = trim((string)($post['remark'] ?? ''));
        if (empty($ids)) {
            throw new BomException('未选择任何物料');
        }

        $exStmt = $this->db->prepare("SELECT part_id FROM bom_items WHERE project_id=? AND part_id IS NOT NULL AND workspace_id=?");
        $exStmt->execute([$pid, $this->workspaceId]);
        $exSet = array_flip(array_map('intval', array_column($exStmt->fetchAll(PDO::FETCH_ASSOC), 'part_id')));

        // 读取当前 sort_order 起点
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
        $soStmt->execute([$pid]);
        $sortOrder = (int)$soStmt->fetchColumn();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $qStmt = $this->db->prepare("SELECT id,platform_part_no,model FROM parts WHERE id IN ($placeholders) AND user_id=?");
        $qStmt->execute([...$ids, $this->dataUid]);
        $parts = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $ins = $this->db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order,version_id,workspace_id,reference,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $added = 0; $skipped = 0;
        foreach ($parts as $p) {
            $partId = (int)$p['id'];
            if (isset($exSet[$partId])) { $skipped++; continue; }
            $ins->execute([$pid, $partId, (string)$p['platform_part_no'], (string)$p['model'], $qty, 1, $sortOrder++, $versionId, $this->workspaceId, $reference, $remark]);
            $added++;
        }
        // 未查询到的物料（已被删除或无权限）也算跳过
        $notFound = count($ids) - count($parts);
        $skipped += $notFound;
        traceLog($this->uid, 'bom_batch_add', 'bom_item', $pid, "批量添加BOM物料: added={$added} skipped={$skipped} pid={$pid}");
        return ['project_id' => $pid, 'added' => $added, 'skipped' => $skipped];
    }

    /** 删除单个 BOM 物料项 */
    public function deleteItem(int $itemId): array
    {
        $vidStmt = $this->db->prepare("SELECT bi.version_id FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id=? AND bp.user_id=? AND bp.workspace_id=?");
        $vidStmt->execute([$itemId, $this->dataUid, $this->workspaceId]);
        $versionId = (int)$vidStmt->fetchColumn();
        if ($versionId > 0 && $this->isVersionReadonly($versionId)) {
            throw new BomException('该物料属于已发布为只读的版本，请先新建草稿版本再修改');
        }
        $this->db->prepare("DELETE bi FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id=? AND bp.user_id=? AND bp.workspace_id=?")
                 ->execute([$itemId, $this->dataUid, $this->workspaceId]);
        return ['item_id' => $itemId];
    }

    /** 批量删除 BOM 物料项 */
    public function batchDeleteItems(array $post): array
    {
        $pid = (int)($post['project_id'] ?? 0);
        $ids = $post['item_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
        if (empty($ids)) {
            throw new BomException('未选择任何物料');
        }
        $this->loadOwnedProject($pid);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // 只读版本守卫：已发布 / 已归档版本的明细不可批量删除
        $vidStmt = $this->db->prepare("SELECT DISTINCT version_id FROM bom_items WHERE id IN ($placeholders) AND project_id=? AND workspace_id=?");
        $vidStmt->execute(array_merge($ids, [$pid, $this->workspaceId]));
        foreach ($vidStmt->fetchAll(PDO::FETCH_COLUMN) as $vid) {
            if ((int)$vid > 0 && $this->isVersionReadonly((int)$vid)) {
                throw new BomException('所选物料包含已发布为只读的版本，请先新建草稿版本再修改');
            }
        }
        $params = array_merge($ids, [$this->dataUid, $this->workspaceId]);
        $this->db->prepare("DELETE bi FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id IN ($placeholders) AND bp.user_id=? AND bp.workspace_id=?")
                 ->execute($params);
        traceLog($this->uid, 'bom_batch_delete', 'bom_item', 0, '批量删除BOM物料:' . count($ids) . '条');
        return ['deleted_count' => count($ids)];
    }

    /** BOM 一键出库（事务性扣减） */
    public function bomCheckout(int $pid, string $mode = 'all_or_nothing', $versionId = null, int $copies = 1): array
    {
        $pr     = $this->loadOwnedProject($pid);
        if (!in_array($mode, ['all_or_nothing', 'partial'], true)) $mode = 'all_or_nothing';
        $copies = max(1, min(999, $copies));

        $vid = $this->resolveVersionId($pid, $versionId);
        if ($this->isVersionReadonly($vid)) {
            throw new BomException('该版本已发布为只读，请先新建草稿版本再出库');
        }

        $itemStmt = $this->db->prepare("SELECT id,part_id,platform_part_no,model,qty,is_incomplete_part FROM bom_items WHERE project_id=? AND workspace_id=? AND version_id=? ORDER BY sort_order, id");
        $itemStmt->execute([$pid, $this->workspaceId, $vid]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $unmatched = [];
        $partStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,status FROM parts WHERE id=? AND user_id=?");
        $altStmt  = $this->db->prepare("
            SELECT p.id, p.stock, p.model, p.platform_part_no, p.status FROM parts p INNER JOIN part_alternatives pa ON pa.target_part_id = p.id
             WHERE pa.source_part_id = ? AND p.user_id=? AND p.workspace_id=? AND (p.status IS NULL OR p.status<>'deleted')
            UNION
            SELECT p.id, p.stock, p.model, p.platform_part_no, p.status FROM parts p INNER JOIN part_alternatives pa ON pa.source_part_id = p.id
             WHERE pa.target_part_id = ? AND p.user_id=? AND p.workspace_id=? AND (p.status IS NULL OR p.status<>'deleted')
        ");
        $plans = [];   // 每行：need / 本料扣减 / 替代料扣减清单
        foreach ($items as $it) {
            $model = (string)$it['model'];
            $partNo = (string)$it['platform_part_no'];
            $part = null;
            if ((int)($it['is_incomplete_part'] ?? 0) !== 1 && (int)$it['part_id'] > 0) {
                $partStmt->execute([(int)$it['part_id'], $this->dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$part || !\App\Core\Enum\PartStatus::canCheckout((string)($part['status'] ?? 'active'))) {
                $unmatched[] = ['model' => $model, 'part_no' => $partNo, 'reason' => '未匹配主库物料'];
                continue;
            }
            $need = (int)$it['qty'] * $copies;
            $own  = (int)$part['stock'];
            // 替代料可用量（双向去重）
            $altStmt->execute([(int)$part['id'], $this->dataUid, $this->workspaceId, (int)$part['id'], $this->dataUid, $this->workspaceId]);
            $alts = $altStmt->fetchAll(PDO::FETCH_ASSOC);
            $plans[] = ['item' => $it, 'part' => $part, 'need' => $need, 'own_avail' => $own, 'alts' => $alts, 'model' => $model, 'part_no' => $partNo];
        }
        if ($unmatched) {
            throw new BomException(
                '出库已取消：BOM 内存在 ' . count($unmatched) . ' 行未匹配主库物料，请先「匹配库料」绑定或删除这些行',
                4,
                ['kind' => 'unmatched', 'reasons' => $unmatched]
            );
        }

        $shortages = [];
        $pool = [];   // part_id => 剩余可用（本料 + 替代料统一进池，按行序分配）
        foreach ($plans as &$pl) {
            $ownId = (int)$pl['part']['id'];
            if (!isset($pool[$ownId])) $pool[$ownId] = max(0, (int)$pl['part']['stock']);
            $takeFromOwn = min($pl['need'], $pool[$ownId]);
            $pool[$ownId] -= $takeFromOwn;
            $remain = $pl['need'] - $takeFromOwn;
            $altTakes = [];
            foreach ($pl['alts'] as $a) {
                if ($remain <= 0) break;
                $aid = (int)$a['id'];
                if (!isset($pool[$aid])) $pool[$aid] = max(0, (int)$a['stock']);
                $take = min($remain, $pool[$aid]);
                if ($take <= 0) continue;
                $pool[$aid] -= $take;
                $altTakes[] = ['id' => $aid, 'take' => $take, 'part_no' => (string)$a['platform_part_no']];
                $remain -= $take;
            }
            $pl['take_own'] = $takeFromOwn;
            $pl['take_alts'] = $altTakes;
            $pl['remain']    = $remain;
            if ($remain > 0) {
                $shortages[] = [
                    'model'   => $pl['model'],
                    'part_no' => $pl['part_no'],
                    'need'    => $pl['need'],
                    'stock'   => max(0, (int)$pl['part']['stock']),
                    'alt'     => array_sum(array_column($pl['alts'], 'stock')),
                    'gap'     => $remain,
                    'reason'  => '库存不足（需 ' . $pl['need'] . '，本料 ' . max(0, (int)$pl['part']['stock'])
                               . ' + 替代料 ' . array_sum(array_column($pl['alts'], 'stock')) . '，缺 ' . $remain . '）',
                ];
            }
        }
        unset($pl);
        if ($shortages && $mode === 'all_or_nothing') {
            throw new BomException(
                '出库失败：' . count($shortages) . ' 行库存不足（本次按 ' . $copies . ' 份核算），未做任何扣减',
                4,
                ['kind' => 'shortage', 'reasons' => $shortages, 'copies' => $copies]
            );
        }

        // ③ 执行扣减（事务）：按②的分配结果扣减，替代料写独立流水
        $syncPartIds = [];
        $stats = ['matched' => 0, 'not_found' => 0, 'insufficient' => 0, 'partial_qty' => 0, 'total_qty' => 0, 'alt_qty' => 0, 'total_rows' => count($items), 'copies' => $copies];
        $this->db->beginTransaction();
        try {
            foreach ($plans as $pl) {
                if (!empty($pl['take_own'])) {
                    $before = (int)$pl['part']['stock'];
                    applyStockDelta((int)$pl['part']['id'], -$pl['take_own']);
                    \insertStockLog($this->db, [
                        'user_id' => $this->uid, 'part_id' => (int)$pl['part']['id'],
                        'platform_part_no' => (string)$pl['part']['platform_part_no'],
                        'change_type' => 'bom_out', 'qty_change' => $pl['take_own'],
                        'qty_before' => $before, 'qty_after' => $before - $pl['take_own'],
                        'remark' => 'BOM出库:' . $pr['name'] . ' ×' . $copies . '份',
                        'source_type' => 'bom', 'source_id' => $pid,
                        'workspace_id' => $this->workspaceId,
                    ]);
                    $syncPartIds[] = (int)$pl['part']['id'];
                    $stats['total_qty'] += $pl['take_own'];
                }
                foreach ($pl['take_alts'] as $at) {
                    $aStmt = $this->db->prepare("SELECT stock FROM parts WHERE id=? AND user_id=?");
                    $aStmt->execute([$at['id'], $this->dataUid]);
                    $before = (int)$aStmt->fetchColumn();
                    applyStockDelta($at['id'], -$at['take']);
                    \insertStockLog($this->db, [
                        'user_id' => $this->uid, 'part_id' => $at['id'],
                        'platform_part_no' => $at['part_no'],
                        'change_type' => 'bom_out', 'qty_change' => $at['take'],
                        'qty_before' => $before, 'qty_after' => $before - $at['take'],
                        'remark' => 'BOM出库(替代料):' . $pr['name'] . ' ×' . $copies . '份 替 ' . $pl['part_no'],
                        'source_type' => 'bom', 'source_id' => $pid,
                        'workspace_id' => $this->workspaceId,
                    ]);
                    $syncPartIds[] = $at['id'];
                    $stats['total_qty'] += $at['take'];
                    $stats['alt_qty'] += $at['take'];
                }
                if (!empty($pl['remain'])) {
                    // partial 模式允许不足（已扣可用量），严格模式不会走到这里
                    $stats['insufficient']++;
                    $stats['partial_qty'] += ($pl['need'] - $pl['remain']);
                }
                $stats['matched']++;
            }
            $this->db->commit();
            foreach (array_unique($syncPartIds) as $partId) {
                syncPartStockCache($partId);
            }
            $this->db->prepare("INSERT INTO bom_exports (user_id,file_name,total_rows,matched,not_found,insufficient,total_qty) VALUES (?,?,?,?,?,?,?)")
                     ->execute([$this->uid, 'BOM:' . $pr['name'] . '[' . $mode . '×' . $copies . ']', $stats['total_rows'], $stats['matched'], $stats['not_found'], $stats['insufficient'], $stats['total_qty']]);
            traceLog($this->uid, 'bom_checkout', 'bom_project', $pid, 'BOM出库[' . $mode . '×' . $copies . ']:' . $pr['name'] . ' 行数' . $stats['matched'] . ' 总量:' . $stats['total_qty'] . ' 替代料:' . $stats['alt_qty']);
            $message = 'BOM「' . $pr['name'] . '」成功出库 ' . $copies . ' 份：共扣减 ' . $stats['total_qty'] . ' 件 / ' . $stats['matched'] . ' 种物料'
                     . ($stats['alt_qty'] > 0 ? '（其中替代料 ' . $stats['alt_qty'] . ' 件）' : '')
                     . ($mode === 'partial' && $stats['insufficient'] > 0 ? '；' . $stats['insufficient'] . ' 行库存不足仅按可用量扣减' : '');
            return ['ok' => true, 'stats' => $stats, 'message' => $message, 'project_name' => $pr['name'], 'copies' => $copies];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** 使用替代料出库（单条 BOM 项切换为替代料扣减） */
    public function useAlternative(array $post): array
    {
        $pid        = (int)($post['project_id'] ?? 0);
        $itemId     = (int)($post['item_id'] ?? 0);
        $altPartId  = (int)($post['alt_part_id'] ?? 0);
        $pr         = $this->loadOwnedProject($pid);

        $itStmt = $this->db->prepare("SELECT id,part_id,qty,version_id FROM bom_items WHERE id=? AND project_id=? AND workspace_id=?");
        $itStmt->execute([$itemId, $pid, $this->workspaceId]);
        $item = $itStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new BomException('BOM物料项不存在');
        if ((int)($item['version_id'] ?? 0) > 0 && $this->isVersionReadonly((int)$item['version_id'])) {
            throw new BomException('该物料属于已发布为只读的版本，请先新建草稿版本再修改');
        }

        $apStmt = $this->db->prepare("SELECT id,stock,platform_part_no,model,status FROM parts WHERE id=? AND user_id=?");
        $apStmt->execute([$altPartId, $this->dataUid]);
        $altPart = $apStmt->fetch(PDO::FETCH_ASSOC);
        if (!$altPart) throw new BomException('替代料不存在');

        $qty = (int)$item['qty'];
        if ((int)$altPart['stock'] < $qty) {
            throw new BomException('替代料库存不足（' . $altPart['stock'] . ' < ' . $qty . '）');
        }
        // 出库前检查物料状态
        $partStatus = $altPart['status'] ?? 'active';
        if (!\App\Core\Enum\PartStatus::canCheckout($partStatus)) {
            throw new BomException('替代料状态为' . $partStatus . '，不可出库');
        }
        $newStock = (int)$altPart['stock'] - $qty;
        $this->db->beginTransaction();
        try {
            applyStockDelta((int)$altPart['id'], -$qty);
            \insertStockLog($this->db, [
                'user_id'          => $this->uid,
                'part_id'          => (int)$altPart['id'],
                'platform_part_no' => (string)$altPart['platform_part_no'],
                'change_type'      => 'bom_out',
                'qty_change'       => $qty,
                'qty_before'       => (int)$altPart['stock'],
                'qty_after'        => $newStock,
                'remark'           => 'BOM替代料出库:' . $pr['name'],
                'source_type'      => 'bom',
                'source_id'        => $pid,
                'workspace_id'     => $this->workspaceId,
            ]);
            $this->db->prepare("UPDATE bom_items SET part_id=?, matched=1 WHERE id=?")
                     ->execute([$altPart['id'], $itemId]);
            syncPartStockCache((int)$altPart['id']);
            $this->db->commit();
            traceLog($this->uid, 'bom_use_alt', 'part', (int)$altPart['id'], 'BOM替代料出库:' . $pr['name'] . ' part_id:' . $altPart['id'] . ' qty:' . $qty);
            return [
                'message'  => '替代料出库成功：' . $altPart['platform_part_no'] . ' 扣减 ' . $qty . ' 件',
                'part_id'  => (int)$altPart['id'],
                'new_stock' => $newStock,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** 替换 BOM 物料行（与 useAlternative 双功能分离）。 */
    public function replaceWithAlternative(array $post): array
    {
        $pid       = (int)($post['project_id'] ?? 0);
        $itemId    = (int)($post['item_id'] ?? 0);
        $altPartId = (int)($post['alt_part_id'] ?? 0);
        $pr        = $this->loadOwnedProject($pid);

        $itStmt = $this->db->prepare("SELECT id,part_id,qty,version_id FROM bom_items WHERE id=? AND project_id=? AND workspace_id=?");
        $itStmt->execute([$itemId, $pid, $this->workspaceId]);
        $item = $itStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new BomException('BOM物料项不存在');
        if ((int)($item['version_id'] ?? 0) > 0 && $this->isVersionReadonly((int)$item['version_id'])) {
            throw new BomException('该物料属于已发布为只读的版本，请先新建草稿版本再修改');
        }

        $apStmt = $this->db->prepare("SELECT id,platform_part_no,model,brand,package,product_type,product_name,customer_part_no,location,parameters FROM parts WHERE id=? AND user_id=?");
        $apStmt->execute([$altPartId, $this->dataUid]);
        $altPart = $apStmt->fetch(PDO::FETCH_ASSOC);
        if (!$altPart) throw new BomException('替换目标物料不存在');

        // 单向拉取：把主库物料参数复制进 BOM 行自有的 pending_json。
        // BOM 参数独立于主库——此处只读主库、只写 BOM 行，绝不回写主库。
        $pulled = [
            'model'            => (string)($altPart['model'] ?? ''),
            'brand'            => (string)($altPart['brand'] ?? ''),
            'package'          => (string)($altPart['package'] ?? ''),
            'product_type'     => (string)($altPart['product_type'] ?? ''),
            'product_name'     => (string)($altPart['product_name'] ?? ''),
            'customer_part_no' => (string)($altPart['customer_part_no'] ?? ''),
            'location'         => (string)($altPart['location'] ?? ''),
            'parameters'       => (string)($altPart['parameters'] ?? ''),
        ];

        $this->db->prepare("UPDATE bom_items SET part_id=?, platform_part_no=?, model=?, pending_json=?, matched=1, is_incomplete_part=0 WHERE id=? AND workspace_id=?")
                 ->execute([
                     (int)$altPart['id'],
                     (string)$altPart['platform_part_no'],
                     (string)$altPart['model'],
                     json_encode($pulled, JSON_UNESCAPED_UNICODE),
                     $itemId,
                     $this->workspaceId,
                 ]);

        traceLog($this->uid, 'bom_replace_alt', 'part', (int)$altPart['id'], 'BOM替换为库存标准物料:' . $pr['name'] . ' item_id:' . $itemId . ' part_id:' . $altPart['id']);
        return [
            'message'      => '已替换为库存标准物料：' . $altPart['platform_part_no'],
            'part_id'      => (int)$altPart['id'],
            'item_id'      => $itemId,
        ];
    }

    /** 更新BOM项目利润率 */
    public function updateProfitMargin(int $projectId, float $margin): array
    {
        $this->loadOwnedProject($projectId);
        $margin = round(max(0, min(99.99, $margin)), 2);
        $this->db->prepare("UPDATE bom_projects SET profit_margin=? WHERE id=? AND user_id=? AND workspace_id=?")
                 ->execute([$margin, $projectId, $this->dataUid, $this->workspaceId]);
        traceLog($this->uid, 'bom_update_margin', 'bom_project', $projectId, "设置利润率:{$margin}%");
        return ['project_id' => $projectId, 'profit_margin' => $margin];
    }

    /** BOM成本汇总：Σ(子物料数量 × 加权平均单价) */
    public function getCostSummary(int $projectId, $versionId = null): array
    {
        $versionId = $this->resolveVersionId($projectId, $versionId);
        $stmt = $this->db->prepare(
            "SELECT bi.id, bi.qty, bi.model, bi.platform_part_no,
                    COALESCE(bi.part_id, 0) AS part_id,
                    COALESCE(p.avg_cost, 0) AS avg_cost
             FROM bom_items bi
             LEFT JOIN parts p ON p.id = bi.part_id
             WHERE bi.project_id = ? AND bi.matched = 1 AND bi.workspace_id = ? AND bi.version_id = ?
             ORDER BY bi.id ASC"
        );
        $stmt->execute([$projectId, $this->workspaceId, $versionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalCost = 0.0;
        $items = [];
        $noCostCount = 0;
        foreach ($rows as $r) {
            $qty = (int)$r['qty'];
            $avgCost = round((float)$r['avg_cost'], 4);
            if ($avgCost <= 0) {
                $noCostCount++;
                continue;
            }
            $lineCost = round($qty * $avgCost, 2);
            $totalCost += $lineCost;
            $items[] = [
                'id'       => (int)$r['id'],
                'model'    => $r['model'] ?: $r['platform_part_no'],
                'qty'      => $qty,
                'avg_cost' => $avgCost,
                'line_cost' => $lineCost,
            ];
        }

        $project = $this->loadOwnedProject($projectId);
        $profitMargin = round((float)($project['profit_margin'] ?? 0), 2);
        $quotePrice = ($profitMargin > 0 && $totalCost > 0) ? round($totalCost / (1 - $profitMargin / 100), 2) : 0;

        return [
            'total_cost'    => round($totalCost, 2),
            'item_count'    => count($items),
            'items'         => $items,
            'no_cost_count' => $noCostCount,
            'profit_margin' => $profitMargin,
            'quote_price'   => $quotePrice,
        ];
    }

    /** 获取BOM项目缺料汇总（含替代料可用量、两类分组、最大可生产份数） */
    public function getShortageSummary(int $projectId, int $quantity = 1, $versionId = null): array
    {
        $versionId = $this->resolveVersionId($projectId, $versionId);
        $stmt = $this->db->prepare("
            SELECT bi.id, bi.part_id, bi.platform_part_no, bi.model, bi.qty,
                bi.matched, bi.reference, bi.is_incomplete_part, bi.pending_json
            FROM bom_items bi
            WHERE bi.project_id = ? AND bi.workspace_id = ? AND bi.version_id = ?
            ORDER BY bi.model
        ");
        $stmt->execute([$projectId, $this->workspaceId, $versionId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $altStmt = $this->db->prepare("
            SELECT COALESCE(SUM(sub.stock),0) FROM (
                SELECT p.id, p.stock FROM parts p INNER JOIN part_alternatives pa ON pa.target_part_id = p.id
                 WHERE pa.source_part_id = ? AND p.user_id=? AND p.workspace_id=? AND (p.status IS NULL OR p.status<>'deleted')
                UNION
                SELECT p.id, p.stock FROM parts p INNER JOIN part_alternatives pa ON pa.source_part_id = p.id
                 WHERE pa.target_part_id = ? AND p.user_id=? AND p.workspace_id=? AND (p.status IS NULL OR p.status<>'deleted')
            ) sub
        ");

        $shortageList = [];
        $totalItems = count($items);
        $cntIncomplete = 0; $cntStock = 0;
        $altUsedTotal = 0; $altCoveredCount = 0;
        $pendingCount = 0; $blockedByPending = 0;
        $maxProducible = null;

        foreach ($items as &$item) {
            $partNo = (string)$item['platform_part_no'];
            $model  = (string)$item['model'];
            $qty    = (int)$item['qty'];
            $isPendingRow = ((int)($item['is_incomplete_part'] ?? 0) === 1 && (int)$item['part_id'] === 0);
            if ($isPendingRow) $pendingCount++;
            $pending = $this->decodePendingJson($item['pending_json'] ?? null);

            $part = null;
            if ((int)$item['part_id'] > 0) {
                $pStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,location FROM parts WHERE id=? AND user_id=?");
                $pStmt->execute([(int)$item['part_id'], $this->dataUid]);
                $part = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$part && !$isPendingRow) {
                $part = $this->matchPart($partNo, $model, (string)($pending['package'] ?? ''));
            }

            $need = $qty * $quantity;

            $usable = $part && !$isPendingRow;

            $have = $usable ? (int)$part['stock'] : 0;
            $altAvail = 0;
            if ($usable) {
                $altStmt->execute([(int)$part['id'], $this->dataUid, $this->workspaceId, (int)$part['id'], $this->dataUid, $this->workspaceId]);
                $altAvail = (int)$altStmt->fetchColumn();
            }
            $gapBefore = max(0, $need - $have);
            $altUsed = min($gapBefore, $altAvail);
            $shortageQty = $gapBefore - $altUsed;

            $item['need_qty'] = $need;
            $item['have_qty'] = $have;
            $item['alt_qty'] = $altUsed;
            $item['shortage_qty'] = $shortageQty;
            $item['category'] = $usable ? 'stock' : 'incomplete';

            // 最大可生产份数：可用量（本料+替代）÷ 单机用量；出现信息待完善行 → 置 0
            if ($usable) {
                $capacity = intdiv($have + $altAvail, max(1, $qty));
                $maxProducible = ($maxProducible === null) ? $capacity : min($maxProducible, $capacity);
            } else {
                $blockedByPending++;
                $maxProducible = 0;
            }

            if ($altUsed > 0) { $altUsedTotal += $altUsed; $altCoveredCount++; }

            if (!$usable || $shortageQty > 0) {
                if ($usable) $cntStock++; else $cntIncomplete++;

                $pendingBrand  = (string)($pending['brand'] ?? '');
                $shortageList[] = [
                    'model' => $part['model'] ?? $item['model'],
                    'part_no' => $part['platform_part_no'] ?? $item['platform_part_no'],
                    'brand' => $part['brand'] ?? $pendingBrand,
                    'package' => $part['package'] ?? (string)($pending['package'] ?? ''),
                    'location' => $part['location'] ?? '',
                    'need_qty' => $need,
                    'have_qty' => $have,
                    'alt_qty' => $altUsed,
                    'shortage_qty' => $shortageQty,
                    'matched' => $usable ? 1 : 0,
                    'reference' => $item['reference'] ?? '',
                    'category' => $usable ? 'stock' : 'incomplete',
                ];
            }
        }
        unset($item);

        return [
            'shortage_count' => count($shortageList),
            'total_items' => $totalItems,
            'items' => $items,
            'shortage_list' => $shortageList,
            'group_incomplete_count' => $cntIncomplete,
            'group_stock_count' => $cntStock,
            'alt_used_total' => $altUsedTotal,
            'alt_covered_count' => $altCoveredCount,
            'max_producible' => $maxProducible ?? 0,
            'blocked_by_pending' => $blockedByPending,
            'pending_count' => $pendingCount,
        ];
    }

    /** 导出缺料采购清单CSV（复用 getShortageSummary 同一口径） */
    public function exportShortageCsv(int $projectId, int $quantity = 1, $versionId = null, string $category = ''): string
    {
        $summary = $this->getShortageSummary($projectId, $quantity, $versionId);
        $catMap = ['incomplete' => '信息待完善', 'stock' => '库存不足'];
        $lines = ["类型,型号,平台编号,品牌,封装,需求数,库存数,替代料可用,缺口数,库位"];
        foreach ($summary['shortage_list'] as $item) {
            $cat = (string)($item['category'] ?? 'stock');
            if ($category !== '' && $cat !== $category) continue;
            $lines[] = implode(',', [
                $catMap[$cat] ?? $cat,
                $item['model'],
                $item['part_no'],
                $item['brand'],
                $item['package'],
                $item['need_qty'],
                $item['have_qty'],
                $item['alt_qty'] ?? 0,
                $item['shortage_qty'],
                $item['location'],
            ]);
        }
        return implode("\n", $lines);
    }

    /** 获取指定版本详情 */
    public function getVersion(int $versionId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, workspace_id, project_id, version, status, description, base_version_id, created_by, released_at, released_by, change_note, created_time FROM bom_versions WHERE id=? AND workspace_id=?");
        $stmt->execute([$versionId, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 明细归并主键：优先编号，其次型号（大写去空格），**并纳入绑定的主库物料 id**。 */
    public function diffKey(?string $partNo, ?string $model, int $partId = 0): string
    {
        $pn = trim((string)$partNo);
        $md = trim((string)$model);
        $base = '';
        if ($pn !== '')      $base = 'p:' . mb_strtoupper($pn);
        elseif ($md !== '')  $base = 'm:' . mb_strtoupper($md);
        if ($base === '') return '';
        return $base . '|n:' . $partId;
    }

    /** BOM 文件导入（统一写路径） */
    public function importBom(array $file, int $projectId): array
    {
        if (empty($file['name'])) throw new BomException('请选择要上传的文件');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new BomException('文件上传失败');

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            throw new BomException('仅支持 CSV / XLSX / XLS 格式');
        }

        $this->loadOwnedProject($projectId);   // 项目归属校验

        $fmap = [
            'platform_part_no' => ['商品编号','产品编号','料号','物料编码','物料号','元件编号','编号','物料编号','Supplier Part','Part Number','Part No','LCSC Item'],
            'model'            => ['商品型号','产品型号','型号','物料名称','元件型号','规格型号','器件型号','描述','规格','名称','Manufacturer Part','Comment','MPN'],
            'qty'              => ['数量','需求数量','用量','总用量','发货数量','贴装数量','使用数量','Quantity','Qty'],
            'customer_part_no' => ['客户料号','客户编号','内部料号','ID'],
            'product_name'     => ['商品名称','产品名称','品名','Description','Name'],
            'product_type'     => ['商品类型','产品类型','类型','Category','目录','分类'],
            'package'          => ['封装格式','封装','Footprint'],

            'brand'            => ['品牌/封装','厂商/封装','品牌','Manufacturer'],
            'parameters'       => ['参数','Value','Parameter','Spec','规格参数'],
            'reference'        => ['位号','参考位号','元件位号','Reference','RefDes','Designator'],
            'remark'           => ['备注','注释','说明','Remark','Note'],
        ];

        // ── 文件解析 ──
        $rows = null;
        if ($ext === 'csv') {
            $raw = file_get_contents($file['tmp_name']);
            $enc = mb_detect_encoding($raw, ['UTF-8','GBK','GB2312','BIG5'], true);
            if ($enc && $enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
            $rows = array_map('str_getcsv', explode("\n", trim($raw)));
        } elseif (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            $spreadsheet = null;
            $tryOrder = ($ext === 'xlsx') ? ['Xlsx','Xls'] : ['Xls','Xlsx'];
            foreach ($tryOrder as $rtype) {
                try {
                    $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($rtype);
                    $rdr->setReadDataOnly(true);
                    if ($rdr->canRead($file['tmp_name'])) { $spreadsheet = $rdr->load($file['tmp_name']); break; }
                } catch (\Throwable $e) { continue; }
            }
            if (!$spreadsheet) {
                $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                $rdr->setReadDataOnly(true);
                $spreadsheet = $rdr->load($file['tmp_name']);
            }
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        } else {
            throw new BomException('未安装 PhpSpreadsheet 或无读取权限，请运行 composer require phpoffice/phpspreadsheet 并确保 vendor/ 目录权限为 755');
        }
        if (empty($rows)) throw new BomException('文件为空或格式不支持');

        // ── 自动识别表头行（别名最长匹配，至少命中编号或型号）──
        $allAliases = [];
        foreach ($fmap as $key => $aliases) {
            foreach ($aliases as $alias) $allAliases[$alias] = $key;
        }
        uksort($allAliases, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        $headerIdx = null; $colMap = []; $bestMatch = 0;
        foreach ($rows as $i => $row) {
            $matched = 0; $tmp = [];
            foreach ($row as $ci => $cell) {
                $cell = trim((string)$cell);
                if ($cell === '') continue;
                $bestAlias = ''; $bestKey = null;
                foreach ($allAliases as $alias => $key) {
                    if (mb_strpos($cell, $alias) !== false) {
                        if (mb_strlen($alias) > mb_strlen($bestAlias)) {
                            $bestAlias = $alias; $bestKey = $key;
                        }
                    }
                }
                if ($bestKey !== null && !isset($tmp[$bestKey])) {
                    $tmp[$bestKey] = $ci; $matched++;
                }
            }
            if ($matched >= 1 && (isset($tmp['platform_part_no']) || isset($tmp['model']))) {
                if ($matched > $bestMatch) { $bestMatch = $matched; $headerIdx = $i; $colMap = $tmp; }
            }
        }
        if ($headerIdx === null) throw new BomException('未找到有效表头行（需至少匹配"商品编号"或"型号"列）');

        $get = fn($row, $key) => isset($colMap[$key]) ? trim((string)($row[$colMap[$key]] ?? '')) : '';

        // ── 预处理 SQL ──
        $soStmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=? AND workspace_id=?");
        $soStmt->execute([$projectId, $this->workspaceId]);
        $sortOrder = (int)$soStmt->fetchColumn();
        // 导入写入当前编辑草稿版本（版本隔离）
        $importVersionId = $this->resolveVersionId($projectId, null);
        if ($this->isVersionReadonly($importVersionId)) {
            throw new BomException('当前版本为只读快照，禁止导入');
        }
        $insStmt = $this->db->prepare("INSERT INTO bom_items (project_id,version_id,part_id,platform_part_no,customer_part_no,model,qty,matched,sort_order,reference,remark,is_incomplete_part,pending_json,workspace_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        // ── 解析 + 文件内合并（同编号优先、次同型号：数量相加、位号合并去重）──
        $merged = [];
        $order  = [];
        $skipped = 0;
        $prevPartNo = '';
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row    = $rows[$i];
            $partNo = $get($row, 'platform_part_no');
            $model  = $get($row, 'model');

            if ($partNo === '' && $model === '' && $prevPartNo !== '') { $skipped++; continue; }
            if ($partNo === '' && $prevPartNo !== '' && $model !== '') $partNo = $prevPartNo;
            if ($partNo !== '') $prevPartNo = $partNo;
            if ($partNo === '' && $model === '') { $skipped++; continue; }

            $qtyStr = $get($row, 'qty');
            $qty = 0;
            if (preg_match('/(\d+(?:\.\d+)?)/', $qtyStr, $qm)) $qty = (int)ceil((float)$qm[1]);
            if ($qty <= 0) $qty = 1;

            $key = $this->diffKey($partNo, $model);
            if (isset($merged[$key])) {
                $merged[$key]['qty'] += $qty;
                $merged[$key]['reference'] = $this->mergeReference((string)$merged[$key]['reference'], $get($row, 'reference'));
                continue;
            }
            // 品牌/封装合并列拆分：列头「品牌/封装」整体归 brand 后，若封装列为空，

            $brandVal = $get($row, 'brand');
            $pkgVal   = $get($row, 'package');
            if ($pkgVal === '' && $brandVal !== '') {
                $tokens = '0201|0402|0603|0805|1206|1210|1812|2010|2512|'
                        . 'SOT-?\d+|SOD-?\d+|SOIC-?\d+|SOP-?\d+|SSOP-?\d+|TSSOP-?\d+|MSOP-?\d+|'
                        . 'LQFP-?\d+|QFN-?\d+|TQFP-?\d+|DFN-?\d+|QFP-?\d+|BGA-?\d*|TO-?\d+|DIP-?\d+|'
                        . '直插|贴片|插件';
                if (preg_match('/(?:^|[\s,，、|\/])(?:' . $tokens . ')(?:$|[\s,，、|\/])/iu', $brandVal, $m, PREG_OFFSET_CAPTURE)) {
                    $pkgVal   = trim($m[0][0], " \t,，、|/／");
                    $brandVal = trim(str_replace($m[0][0], ' ', $brandVal), " \t,，、|/／");
                }
            }
            $merged[$key] = [
                'part_no'          => $partNo,
                'model'            => $model,
                'qty'              => $qty,
                'customer_part_no' => $get($row, 'customer_part_no'),
                'product_name'     => $get($row, 'product_name'),
                'product_type'     => $get($row, 'product_type'),
                'package'          => $pkgVal,
                'brand'            => $brandVal,
                'parameters'       => $get($row, 'parameters'),
                'reference'        => $get($row, 'reference'),
                'remark'           => $get($row, 'remark'),
            ];
            $order[] = $key;
        }

        // ── 逐行匹配写库（命中绑 part_id；未命中零建主库，落待补行 + pending_json）──
        $imported = 0;
        $matchedCount = 0;
        $pendingCount = 0;
        foreach ($order as $key) {
            $r = $merged[$key];
            $matchedPart = $this->matchPart($r['part_no'], $r['model'], $r['package']);
            $partId = $matchedPart ? (int)$matchedPart['id'] : null;
            $matched = $matchedPart ? 1 : 0;
            $isPending = $matchedPart ? 0 : 1;
            $pendingJson = null;
            if (!$matchedPart) {
                $pendingFields = array_filter([
                    'product_name' => $r['product_name'],
                    'product_type' => $r['product_type'],
                    'package'      => $r['package'],
                    'brand'        => $r['brand'],
                    'parameters'   => $r['parameters'],
                ], fn($v) => $v !== '');
                $pendingJson = $pendingFields ? json_encode($pendingFields, JSON_UNESCAPED_UNICODE) : null;
            }

            $insStmt->execute([
                $projectId, $importVersionId, $partId, $r['part_no'], $r['customer_part_no'], $r['model'],
                $r['qty'], $matched, $sortOrder, $r['reference'], $r['remark'], $isPending, $pendingJson, $this->workspaceId,
            ]);
            $sortOrder++;
            $imported++;
            $matchedPart ? $matchedCount++ : $pendingCount++;
        }

        traceLog($this->uid, 'bom_import', 'bom_project', $projectId, '导入BOM物料:' . $file['name'] . ' 共' . $imported . '行 匹配' . $matchedCount . ' 待补' . $pendingCount);
        $message = '成功导入 ' . $imported . ' 条物料：已匹配 ' . $matchedCount . '，待补 ' . $pendingCount
                 . ($pendingCount > 0 ? '（待补物料仅存于本 BOM，不进入主库）' : '')
                 . ($skipped > 0 ? "，跳过 {$skipped} 行" : '');
        return ['imported' => $imported, 'matched' => $matchedCount, 'pending' => $pendingCount, 'skipped' => $skipped, 'message' => $message];
    }

    /** 位号合并：逗号拼接去重（大小写不敏感）；供明细读写与 BomVersionService::versionItemsIndex() 共用 */
    public function mergeReference(string $exist, string $add): string
    {
        if ($add === '') return $exist;
        if ($exist === '') return $add;
        $seen = [];
        $out  = [];
        foreach (explode(',', $exist . ',' . $add) as $ref) {
            $ref = trim($ref);
            if ($ref === '') continue;
            $k = mb_strtoupper($ref);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $ref;
        }
        return implode(',', $out);
    }

    // ════════ 待补物料补全（导入零建主库后的唯一入库路径）════════

    /** 读取清单行并校验工作区归属 + 版本可写 */
    private function loadOwnedItem(int $itemId): array
    {
        $stmt = $this->db->prepare("SELECT id,project_id,version_id,part_id,platform_part_no,customer_part_no,model,qty,reference,remark,is_incomplete_part,pending_json FROM bom_items WHERE id=? AND workspace_id=?");
        $stmt->execute([$itemId, $this->workspaceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new BomException('清单行不存在或无权访问');
        $this->loadOwnedProject((int)$row['project_id']);
        if ((int)($row['version_id'] ?? 0) > 0 && $this->isVersionReadonly((int)$row['version_id'])) {
            throw new BomException('当前版本为只读快照，禁止修改待补物料');
        }
        return $row;
    }

    /** 编辑清单行（数量 / 位号 / 备注） */
    public function updateItem(array $p): array
    {
        $itemId = (int)($p['item_id'] ?? 0);
        if ($itemId <= 0) throw new BomException('参数错误：缺少清单行ID', 2);
        $it = $this->loadOwnedItem($itemId);

        $qty = (int)($p['qty'] ?? 0);
        if ($qty <= 0) throw new BomException('数量必须大于 0', 2);
        $ref    = mb_substr(trim((string)($p['reference'] ?? '')), 0, 500);
        $remark = mb_substr(trim((string)($p['remark'] ?? '')), 0, 500);

        $partCols = [
            'model' => 'p_model', 'brand' => 'p_brand', 'package' => 'p_package',
            'product_type' => 'p_product_type', 'product_name' => 'p_product_name',
            'customer_part_no' => 'p_customer_part_no', 'location' => 'p_location',
            'parameters' => 'p_parameters',
        ];
        $params = [];
        foreach ($partCols as $col => $key) {
            if (array_key_exists($key, $p)) {
                $params[$col] = mb_substr(trim((string)$p[$key]), 0, 255);
            }
        }

        $oldQty  = (int)$it['qty'];
        $oldJson = (string)($it['pending_json'] ?? '');
        $pending = $this->decodePendingJson($oldJson);
        $hasPartFields = $params !== [];

        $this->db->beginTransaction();
        try {
            if ($hasPartFields) {

                $merged = array_merge($pending, $params);
                $this->db->prepare("UPDATE bom_items SET pending_json=? WHERE id=? AND workspace_id=?")
                         ->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $itemId, $this->workspaceId]);
            }
            $this->db->prepare("UPDATE bom_items SET qty=?, reference=?, remark=? WHERE id=? AND workspace_id=?")
                     ->execute([$qty, $ref, $remark, $itemId, $this->workspaceId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $partTouched = $hasPartFields ? implode(',', array_keys($params)) : '';
        traceLog($this->uid, 'bom_update_item', 'bom_item', $itemId,
            "编辑清单行: 数量 {$oldQty}→{$qty} 位号:{$ref}" . ($partTouched ? " BOM参数:{$partTouched}" : ''));
        return [
            'item_id' => $itemId, 'qty' => $qty, 'reference' => $ref, 'remark' => $remark,
            'part_fields' => $partTouched ?: null,
        ];
    }

    /** 补全待补行（单个）：pending_json 服务端预填 → 建/绑主库物料 → 回写 part_id、清待补标记 */
    public function completePendingItem(array $p): array
    {
        $itemId = (int)($p['item_id'] ?? 0);
        if ($itemId <= 0) throw new BomException('参数错误：缺少清单行ID', 2);

        $it = $this->loadOwnedItem($itemId);
        if ((int)$it['part_id'] > 0 && (int)($it['is_incomplete_part'] ?? 0) === 0) {
            throw new BomException('该行已匹配物料，无需补全', 2);
        }
        $pending = $this->decodePendingJson($it['pending_json'] ?? null);

        $val = function (string $k) use ($p, $pending, $it): string {
            if (array_key_exists($k, $p)) return trim((string)$p[$k]);
            if (isset($pending[$k])) return trim((string)$pending[$k]);
            if (array_key_exists($k, $it)) return trim((string)$it[$k]);
            return '';
        };

        $r = $this->resolvePendingPart([
            'platform_part_no'    => $val('platform_part_no'),
            'customer_part_no'    => $val('customer_part_no'),
            'model'               => $val('model'),
            'product_name'        => $val('product_name'),
            'product_type'        => $val('product_type'),
            'package'             => $val('package'),
            'brand'               => $val('brand'),
            'parameters'          => $val('parameters'),
            'location'            => $val('location'),
            'remark'              => $val('remark'),
            'low_stock_threshold' => $val('low_stock_threshold'),
        ], (int)($p['platform_id'] ?? 0));

        $this->db->prepare("UPDATE bom_items SET part_id=?, matched=1, is_incomplete_part=0, pending_json=NULL WHERE id=? AND workspace_id=?")
                 ->execute([$r['part_id'], $itemId, $this->workspaceId]);

        traceLog($this->uid, 'bom_pending_complete', 'bom_item', $itemId, ($r['created'] ? '待补物料新建入库' : '待补物料绑定库内物料') . " part_id:{$r['part_id']}");
        return ['item_id' => $itemId, 'part_id' => $r['part_id'], 'created' => $r['created'],
                'message' => $r['created'] ? '待补物料已入库并关联到清单行' : '已绑定库内物料并关联到清单行'];
    }

    /** 批量补全待补行：字段完整（型号/封装/分类/商品名称）的行批量建/绑主库 */
    public function batchCompletePending(array $p): array
    {
        $ids = array_values(array_filter(array_map('intval', $p['item_ids'] ?? []), fn($v) => $v > 0));
        $platformId = (int)($p['platform_id'] ?? 0);
        if (empty($ids)) throw new BomException('未选择物料', 2);
        if ($platformId <= 0) throw new BomException('必须手动选择物料归属平台', 2);

        $completed = 0; $skipped = 0; $created = 0; $bound = 0;
        foreach ($ids as $itemId) {
            try {
                $it = $this->loadOwnedItem($itemId);
                if ((int)$it['part_id'] > 0 && (int)($it['is_incomplete_part'] ?? 0) === 0) { $skipped++; continue; }
                $pending = $this->decodePendingJson($it['pending_json'] ?? null);
                $fields = [
                    'platform_part_no'    => (string)$it['platform_part_no'],
                    'customer_part_no'    => (string)$it['customer_part_no'],
                    'model'               => (string)$it['model'],
                    'product_name'        => (string)($pending['product_name'] ?? ''),
                    'product_type'        => (string)($pending['product_type'] ?? ''),
                    'package'             => (string)($pending['package'] ?? ''),
                    'brand'               => (string)($pending['brand'] ?? ''),
                    'parameters'          => (string)($pending['parameters'] ?? ''),
                    'location'            => '',
                    'remark'              => '',
                    'low_stock_threshold' => '',
                ];
                if ($fields['model'] === '' || $fields['package'] === '' || $fields['product_type'] === '' || $fields['product_name'] === '') {
                    $skipped++;
                    continue;
                }
                $r = $this->resolvePendingPart($fields, $platformId);
                $this->db->prepare("UPDATE bom_items SET part_id=?, matched=1, is_incomplete_part=0, pending_json=NULL WHERE id=? AND workspace_id=?")
                         ->execute([$r['part_id'], $itemId, $this->workspaceId]);
                $completed++;
                $r['created'] ? $created++ : $bound++;
            } catch (BomException $e) {
                $skipped++;
            }
        }
        if ($completed === 0) {
            throw new BomException('选中的清单行均不满足补全条件（型号/封装/分类/商品名称 必填，或编号冲突）', 2);
        }
        traceLog($this->uid, 'bom_pending_batch_complete', 'bom_project', 0, "批量补全待补物料 {$completed} 条（新建{$created}/绑定{$bound}），skipped:{$skipped}");
        return ['completed' => $completed, 'skipped' => $skipped, 'created' => $created, 'bound' => $bound];
    }

    /** 待补行 → 主库：同平台同编号已存在则直接绑定，否则创建新物料（初始库存 0） */
    private function resolvePendingPart(array $fields, int $platformId): array
    {
        $platStmt = $this->db->prepare("SELECT id, platform_type FROM platforms WHERE id=? AND (user_id=? OR user_id=0) LIMIT 1");
        $platStmt->execute([$platformId, $this->dataUid]);
        $plat = $platStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plat) throw new BomException('必须手动选择物料归属平台（或所选平台不存在）', 2);
        $platType = (string)($plat['platform_type'] ?? 'standard');

        $ppn   = trim((string)($fields['platform_part_no'] ?? ''));
        $ptype = trim((string)($fields['product_type'] ?? ''));
        if ($ptype === '') throw new BomException('商品类型（分类）必填', 2);

        if ($ppn !== '') {
            $dup = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? LIMIT 1");
            $dup->execute([$this->dataUid, $platformId, $ppn]);
            $exist = $dup->fetch(PDO::FETCH_ASSOC);
            if ($exist) {
                return ['part_id' => (int)$exist['id'], 'created' => false];
            }
        }

        $thr = trim((string)($fields['low_stock_threshold'] ?? ''));
        $thrVal = $thr === '' ? null : max(0, (int)$thr);
        $maxIdStmt = $this->db->prepare("SELECT COALESCE(MAX(internal_id),0) FROM parts WHERE user_id=?");
        $newPartId = 0;
        for ($retry = 0; $retry < 3; $retry++) {
            $maxIdStmt->execute([$this->dataUid]);
            $nextInternalId = (int)$maxIdStmt->fetchColumn() + 1;
            $insertPpn = $ppn;
            if ($insertPpn === '' && $platType === 'standard') $insertPpn = '#' . $nextInternalId . '内部';
            $insertPpn = $insertPpn !== '' ? $insertPpn : null;
            try {

                $bomParamsText = \App\Helper\Spec\SpecNormalizer::buildText([
                    'parameters'   => trim((string)($fields['parameters'] ?? '')),
                    'model'        => trim((string)($fields['model'] ?? '')),
                    'product_name' => trim((string)($fields['product_name'] ?? '')),
                    'category'     => $ptype,
                    'package'      => trim((string)($fields['package'] ?? '')),
                ]);
                $this->db->prepare("INSERT INTO parts (user_id,workspace_id,platform_id,internal_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,parameters,parameters_text,stock,low_stock_threshold,location,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)")
                         ->execute([
                             $this->dataUid, $this->workspaceId, $platformId, $nextInternalId, $insertPpn,
                             trim((string)($fields['customer_part_no'] ?? '')), trim((string)($fields['model'] ?? '')),
                             trim((string)($fields['product_name'] ?? '')), $ptype, trim((string)($fields['package'] ?? '')),
                             trim((string)($fields['brand'] ?? '')), trim((string)($fields['parameters'] ?? '')),
                             $bomParamsText,
                             $thrVal, trim((string)($fields['location'] ?? '')), trim((string)($fields['remark'] ?? '')),
                         ]);
                $newPartId = (int)$this->db->lastInsertId();
                break;
            } catch (\Throwable $ie) {
                $msg = $ie->getMessage();
                if (strpos($msg, 'Duplicate entry') !== false
                    && (strpos($msg, 'internal_id') !== false || strpos($msg, 'uq_user_internal') !== false)) {
                    continue;   // 内部ID并发冲突：重取最大值重试
                }
                if (strpos($msg, 'Duplicate entry') !== false && strpos($msg, 'platform_part_no') !== false) {
                    // 编号唯一冲突（并发）：回查绑定
                    $dup2 = $this->db->prepare("SELECT id FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? LIMIT 1");
                    $dup2->execute([$this->dataUid, $platformId, $insertPpn]);
                    $row2 = $dup2->fetch(PDO::FETCH_ASSOC);
                    if ($row2) return ['part_id' => (int)$row2['id'], 'created' => false];
                }
                throw new BomException('待补物料入库失败：' . $msg, 2);
            }
        }
        if ($newPartId === 0) throw new BomException('内部ID生成失败，请重试', 2);
        \linkCategories($newPartId, $this->dataUid, \parseCategories($ptype));
        return ['part_id' => $newPartId, 'created' => true];
    }

    // ════════ 替代料弹窗：相似候选推荐（仅参考，不代替用户决策）════════

    /** 清单行 → 主库候选物料（≤3，带匹配总分与理由标签） */
    public function pendingSuggest(array $p): array
    {
        $itemId = (int)($p['item_id'] ?? 0);
        if ($itemId <= 0) throw new BomException('参数错误：缺少清单行ID', 2);

        $st = $this->db->prepare("SELECT id,project_id,part_id,platform_part_no,model,qty,version_id,pending_json FROM bom_items WHERE id=? AND workspace_id=?");
        $st->execute([$itemId, $this->workspaceId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new BomException('BOM物料项不存在');
        $this->loadOwnedProject((int)$item['project_id']);

        $pending = $this->decodePendingJson($item['pending_json'] ?? null);
        $map = ['model' => 'model', 'package' => 'package', 'brand' => 'brand',
                'params' => 'parameters', 'type' => 'product_type', 'name' => 'product_name'];
        $cur = [];
        foreach ($map as $k => $col) {
            $cur[$k] = (string)($pending[$col] ?? '');
        }
        if (!$pending && (int)($item['part_id'] ?? 0) > 0) {
            $ps = $this->db->prepare("SELECT model,brand,package,parameters,product_type,product_name FROM parts WHERE id=? AND user_id=?");
            $ps->execute([(int)$item['part_id'], $this->dataUid]);
            $main = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach ($map as $k => $col) {
                $cur[$k] = (string)($main[$col] ?? '');
            }
        }
        if ($cur['model'] === '') $cur['model'] = (string)($item['model'] ?? '');
        $cur['partNo'] = (string)($item['platform_part_no'] ?? '');

        $source = $cur + ['qty' => (int)$item['qty'], 'item_id' => $itemId,
                          'current_part_id' => (int)($item['part_id'] ?? 0)];

        $candidates = $this->recallCandidates($cur, (int)($item['part_id'] ?? 0), 3);
        return ['item_id' => $itemId, 'source' => $source, 'candidates' => $candidates];
    }

    /** 候选召回 + 硬约束淘汰 + 加权评分（≤$limit） */
    private function recallCandidates(array $cur, int $excludePartId, int $limit): array
    {
        $limit = max(1, min(3, $limit));
        $model = trim((string)($cur['model'] ?? ''));
        $pkg   = trim((string)($cur['package'] ?? ''));
        $excludePartId = max(0, $excludePartId);

        $cols = 'id,internal_id,platform_part_no,model,product_name,brand,package,stock,status,location,product_type,parameters,parameters_text';
        $base = "FROM parts WHERE user_id=? AND workspace_id=? AND status!='deleted'";
        $args = [$this->dataUid, $this->workspaceId];

        // 召回分支，按 identity 优先级排列（先命中的先占位，后续分支只补空缺）
        $branches = [];

        // ① 型号精确
        if ($model !== '') {
            $branches['exact'] = [' AND LOWER(model)=LOWER(?) ', [$model]];
        }
        // ② 型号 + 封装精确
        if ($model !== '' && $pkg !== '') {
            $branches['model_pkg'] = [' AND LOWER(model)=LOWER(?) AND LOWER(package)=LOWER(?) ', [$model, $pkg]];
        }
        // ③ 型号归一化模糊（去连字符/空格/下划线后 LIKE）
        if ($model !== '') {
            $norm = self::normalizeModel($model);
            if ($norm !== '') {
                $branches['fuzzy'] = [
                    " AND REPLACE(REPLACE(REPLACE(LOWER(model),'-',''),' ',''),'_','') LIKE ? ",
                    ['%' . $norm . '%'],
                ];
            }
        }
        // ④ 封装等价词（型号缺失时的兜底召回）
        $terms = \App\Helper\Spec\SpecNormalizer::packageSearchTerms($pkg);
        if ($terms) {
            $or = implode(' OR ', array_fill(0, count($terms), 'LOWER(package) LIKE ?'));
            $bind = [];
            foreach ($terms as $t) $bind[] = '%' . mb_strtolower($t) . '%';
            $branches['pkg_like'] = [' AND (' . $or . ') ', $bind];
        }
        if (!$branches) return [];

        // 逐分支取数 + PHP 侧去重（identity 取优先级最高者）
        $merged = [];
        foreach ($branches as $identity => [$frag, $bind]) {
            $sql  = "SELECT $cols $base $frag";
            $bind2 = $bind;
            if ($excludePartId > 0) { $sql .= ' AND id<>?'; $bind2[] = $excludePartId; }
            $sql .= ' ORDER BY stock DESC, id ASC LIMIT 40';
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge($args, $bind2));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pid = (int)$r['id'];
                if (isset($merged[$pid])) continue;
                $r['identity'] = $identity;
                $merged[$pid] = $r;
            }
        }
        if (!$merged) return [];

        // 硬约束淘汰 + 加权评分（与物料页替代料同一套引擎）
        $q = [
            'model'  => $cur['model'],
            'brand'  => $cur['brand'],
            'stock'  => 0,
            'status' => 'active',
            'specs'  => \App\Helper\Spec\SpecNormalizer::extract([
                'parameters_text' => (string)($cur['params'] ?? ''),
                'product_name'    => (string)($cur['name'] ?? ''),
                'category'        => (string)($cur['type'] ?? ''),
                'package'         => (string)($cur['package'] ?? ''),
            ]),
        ];
        $scored = [];
        foreach ($merged as $r) {
            $eval = \App\Service\AlternativeScorer::evaluate($q, [
                'model'  => (string)($r['model'] ?? ''),
                'brand'  => (string)($r['brand'] ?? ''),
                'stock'  => (int)($r['stock'] ?? 0),
                'status' => (string)($r['status'] ?? 'active'),
                'specs'  => \App\Helper\Spec\SpecNormalizer::extract([
                    'parameters_text' => (string)($r['parameters_text'] ?? ''),
                    'product_name'    => (string)($r['product_name'] ?? ''),
                    'category'        => (string)($r['product_type'] ?? ''),
                    'package'         => (string)($r['package'] ?? ''),
                ]),
            ]);
            if ($eval === null) continue;   // 命中硬约束（封装族/主参数冲突）直接淘汰
            $r['score']     = $eval['score'];
            $r['score_pct'] = $eval['score_pct'];
            $r['level']     = $eval['level'];
            $r['reasons']   = $eval['reasons'];
            $scored[] = $r;
        }
        $rank = ['exact' => 0, 'model_pkg' => 1, 'fuzzy' => 2, 'pkg_like' => 3];
        usort($scored, function ($a, $b) use ($rank) {
            return [($rank[$a['identity']] ?? 9), -$a['score']] <=> [($rank[$b['identity']] ?? 9), -$b['score']];
        });
        return array_slice($scored, 0, $limit);
    }

    /** 型号归一化（与 AlternativeScorer 同口径） */
    private static function normalizeModel(string $model): string
    {
        $model = mb_substr(trim($model), 0, 64);
        return preg_replace('/[\s\-_\.\/]/', '', mb_strtoupper($model)) ?? '';
    }

    // ════════ 待补物料：补料清单导出 ════════

    /** 导出补料清单 CSV（当前版本待补行：解析字段 + 需求信息，供采购/建料） */
    public function exportPendingCsv(int $projectId, $versionId = null): string
    {
        $this->loadOwnedProject($projectId);
        $vid = $this->resolveVersionId($projectId, $versionId);
        $st = $this->db->prepare("SELECT id,platform_part_no,customer_part_no,model,qty,reference,remark,pending_json FROM bom_items WHERE project_id=? AND workspace_id=? AND version_id=? AND is_incomplete_part=1 AND part_id IS NULL ORDER BY sort_order, id");
        $st->execute([$projectId, $this->workspaceId, $vid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $lines = ["商品编号,型号,封装,商品名称,品牌,分类,参数,数量,位号,备注"];
        foreach ($rows as $r) {
            $pd = $this->decodePendingJson($r['pending_json'] ?? null);
            $lines[] = implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string)$v) . '"',
                [
                    $r['platform_part_no'], $r['model'], $pd['package'] ?? '', $pd['product_name'] ?? '',
                    $pd['brand'] ?? '', $pd['product_type'] ?? '', $pd['parameters'] ?? '',
                    $r['qty'], $r['reference'], $r['remark'],
                ]
            ));
        }
        return implode("\n", $lines) . "\n";
    }

    /** 待补行判定（part_id 空 + is_incomplete_part 标记） */
    private function isPendingItem(array $row): bool
    {
        return ((int)($row['is_incomplete_part'] ?? 0) === 1 && (int)($row['part_id'] ?? 0) === 0);
    }

    /** 待补行置顶（稳定分区：先待补行，再其余，各自保持原相对顺序） */
    private function sortPendingFirst(array $items): array
    {
        $pending = [];
        $rest    = [];
        foreach ($items as $row) {
            if ($this->isPendingItem($row)) $pending[] = $row;
            else                           $rest[]    = $row;
        }
        return array_merge($pending, $rest);
    }

    /** 待补行缺失字段（必填口径：型号/封装/分类/商品名称；用于列表 chip 与补全提示） */
    private function pendingMissingFields(array $pending, string $model): array
    {
        $miss = [];
        if (trim($model) === '')                            $miss[] = '型号';
        if (trim((string)($pending['package'] ?? '')) === '')      $miss[] = '封装';
        if (trim((string)($pending['product_type'] ?? '')) === '') $miss[] = '分类';
        if (trim((string)($pending['product_name'] ?? '')) === '') $miss[] = '名称';
        return $miss;
    }
}

/** BOM 业务异常 */
final class BomException extends \Exception
{
    public int $errCode;
    /** 结构化附加信息（失败原因明细等，供前端渲染结果卡） */
    public array $payload;

    public function __construct(string $msg, int $code = 1, array $payload = [])
    {
        parent::__construct($msg);
        $this->errCode = $code;
        $this->payload = $payload;
    }
}
