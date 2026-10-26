<?php
declare(strict_types=1);

namespace Modules\Bom\Service;

use PDO;

require_once __DIR__ . '/BomService.php';

/** BomVersionService — BOM 版本管理 */
final class BomVersionService
{
    private PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId;
    private \BomManager $bom;

    public function __construct(PDO $db, int $uid, int $dataUid, \BomManager $bom)
    {
        $this->db          = $db;
        $this->uid         = $uid;
        $this->dataUid     = $dataUid;
        $this->workspaceId = getWorkspaceId();
        $this->bom         = $bom;
    }

    // ════════ 按版本取明细 ════════

    /** 按版本取明细 + 与主库匹配后的状态判定（导出 CSV / 采购清单共用同一口径） */
    public function listVersionItems(int $projectId, $versionId = null): array
    {
        $this->bom->loadOwnedProject($projectId);
        $vid = $this->bom->resolveVersionId($projectId, $versionId);
        $st = $this->db->prepare("SELECT id,part_id,platform_part_no,model,qty,sort_order FROM bom_items WHERE project_id=? AND workspace_id=? AND version_id=? ORDER BY sort_order, id");
        $st->execute([$projectId, $this->workspaceId, $vid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $partStmt = $this->db->prepare("SELECT id,stock,model,platform_part_no,platform_id,product_name,brand,package,parameters,product_type FROM parts WHERE id=? AND user_id=?");
        $out = [];
        foreach ($rows as $it) {
            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([(int)$it['part_id'], $this->dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$part) $part = $this->bom->matchPart((string)$it['platform_part_no'], (string)$it['model']);
            $qty = (int)$it['qty'];
            $it['part'] = $part;
            if (!$part) {
                $it['status'] = 'not_found';
                $it['stock']  = 0;
                $it['gap']    = $qty;
            } else {
                $stock = (int)$part['stock'];
                $it['stock'] = $stock;
                if ($stock >= $qty) { $it['status'] = 'ok';           $it['gap'] = 0; }
                else                { $it['status'] = 'insufficient'; $it['gap'] = $qty - $stock; }
            }
            $out[] = $it;
        }
        return $out;
    }

    // ════════ 版本 CRUD ════════

    /** 创建 BOM 版本 */
    public function createVersion(int $projectId, string $version, string $description = ''): int
    {
        $this->bom->loadOwnedProject($projectId);
        $this->db->prepare("INSERT INTO bom_versions (workspace_id, project_id, version, status, description) VALUES (?,?,?,?,?)")
                 ->execute([$this->workspaceId, $projectId, $version, 'draft', $description]);
        $versionId = (int)$this->db->lastInsertId();
        traceLog($this->uid, 'bom_version_create', 'bom_version', $versionId, "创建BOM版本:{$version} 项目ID:{$projectId}");
        return $versionId;
    }

    /** 列出指定 BOM 项目的所有版本（标注当前编辑草稿与发布信息） */
    public function listVersions(int $projectId): array
    {
        $project = $this->bom->loadOwnedProject($projectId);
        $currentId = (int)($project['current_version_id'] ?? 0);
        $stmt = $this->db->prepare("SELECT v.*, u.username AS released_by_name FROM bom_versions v LEFT JOIN users u ON u.id=v.released_by WHERE v.project_id=? AND v.workspace_id=? ORDER BY v.created_time DESC");
        $stmt->execute([$projectId, $this->workspaceId]);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as &$r) {
            $r['is_current'] = ((int)$r['id'] === $currentId);
            $r['editable'] = ($r['status'] === 'draft' && !(int)$r['is_current']);
        }
        return $list;
    }

    /** 克隆版本 → 生成新草稿作为新的当前编辑目标（版本迭代核心）。 */
    public function cloneVersion(int $projectId, int $baseVersionId, string $version, string $description = ''): array
    {
        $this->bom->loadOwnedProject($projectId);
        $base = $baseVersionId > 0 ? $this->bom->getVersion($baseVersionId) : $this->bom->ensureCurrentDraft($projectId);
        if (!$base || (int)$base['project_id'] !== $projectId) throw new \BomException('来源版本不存在或无权访问');
        $baseId = (int)$base['id'];

        $version = $this->bom->sanitizeVersion($version, $version);
        $baseVersionStr = (string)($base['version'] ?? '');
        if ($this->isNumericVersion($baseVersionStr) && $this->isNumericVersion($version)) {
            if (!$this->versionLessThan($baseVersionStr, $version)) {
                throw new \BomException("新版本号「{$version}」必须大于来源版本「{$baseVersionStr}」");
            }
        }

        $this->db->prepare("INSERT INTO bom_versions (workspace_id, project_id, version, status, description, base_version_id, created_by) VALUES (?,?,?,?,?,?,?)")
                 ->execute([$this->workspaceId, $projectId, $version, 'draft', $description, $baseId, $this->uid]);
        $newId = (int)$this->db->lastInsertId();

        // 复制 base 版本明细
        $sel = $this->db->prepare("SELECT part_id, platform_part_no, customer_part_no, model, qty, matched, sort_order, reference, remark, is_incomplete_part FROM bom_items WHERE project_id=? AND workspace_id=? AND version_id=?");
        $sel->execute([$projectId, $this->workspaceId, $baseId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        $ins = $this->db->prepare("INSERT INTO bom_items (project_id,version_id,part_id,platform_part_no,customer_part_no,model,qty,matched,sort_order,reference,remark,is_incomplete_part,workspace_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($rows as $r) {
            $ins->execute([$projectId, $newId, $r['part_id'], $r['platform_part_no'], $r['customer_part_no'],
                $r['model'], $r['qty'], $r['matched'], $r['sort_order'], $r['reference'], $r['remark'], $r['is_incomplete_part'], $this->workspaceId]);
        }
        // 设为当前编辑草稿
        $this->db->prepare("UPDATE bom_projects SET current_version_id=? WHERE id=?")->execute([$newId, $projectId]);
        traceLog($this->uid, 'bom_version_clone', 'bom_version', $newId, "克隆BOM版本:{$version} 来源V{$base['version']}(id:{$baseId}) 项目ID:{$projectId}");
        return ['version_id' => $newId, 'version' => $version, 'copied' => count($rows)];
    }

    /** 切换当前编辑草稿版本（仅草稿可被设为编辑目标） */
    public function setCurrentVersion(int $projectId, int $versionId): array
    {
        $this->bom->loadOwnedProject($projectId);
        $v = $this->bom->getVersion($versionId);
        if (!$v || (int)$v['project_id'] !== $projectId) throw new \BomException('版本不存在或无权访问');
        if ($v['status'] !== 'draft') throw new \BomException('只有草稿版本可作为当前编辑目标');
        $this->db->prepare("UPDATE bom_projects SET current_version_id=? WHERE id=?")->execute([$versionId, $projectId]);
        return ['version_id' => $versionId];
    }

    /** 发布 BOM 版本（draft→released），自动基于当前内容新建下一草稿承接后续编辑。 */
    public function releaseVersion(int $versionId, string $changeNote = ''): array
    {
        $v = $this->bom->getVersion($versionId);
        if (!$v) throw new \BomException('BOM版本不存在或无权访问');
        if ($v['status'] !== 'draft') throw new \BomException('仅草稿版本可发布');

        $projectId = (int)$v['project_id'];
        $this->bom->loadOwnedProject($projectId);
        $changeNote = trim($changeNote);

        // 版本号唯一性校验（released/archived 不允许重复版本号）
        $dup = $this->db->prepare("SELECT COUNT(*) FROM bom_versions WHERE project_id=? AND workspace_id=? AND version=? AND status IN ('released','archived') AND id<>?");
        $dup->execute([$projectId, $this->workspaceId, $v['version'], $versionId]);
        if ((int)$dup->fetchColumn() > 0) throw new \BomException("已存在同号版本「{$v['version']}」，请先修改版本号再发布");

        $this->db->prepare("UPDATE bom_versions SET status='released', released_at=NOW(), released_by=?, change_note=? WHERE id=? AND workspace_id=?")
                 ->execute([$this->uid, $changeNote, $versionId, $this->workspaceId]);
        $snapshot = [];
        try {
            $si = $this->db->prepare("SELECT part_id,platform_part_no,model,qty,reference,remark FROM bom_items WHERE version_id=? AND workspace_id=?");
            $si->execute([$versionId, $this->workspaceId]);
            $snapshot = $si->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
        traceLog($this->uid, 'bom_version_release', 'bom_version', $versionId, "发布BOM版本:{$v['version']} 项目ID:{$projectId} 明细项:"
            . count($snapshot) . ($changeNote !== '' ? " 说明:{$changeNote}" : ''));

        // 自动创建下一草稿承接后续编辑（版本号自动 +1.x）
        $nextVersion = $this->suggestNextVersion($v['version']);
        $next = $this->cloneVersion($projectId, $versionId, $nextVersion, '承接上一版本「' . $v['version'] . '」的续作草稿');
        return [
            'released_id'      => $versionId,
            'released_version' => $v['version'],
            'next_id'          => (int)$next['version_id'],
            'next_version'     => $nextVersion,
        ];
    }

    /** 编辑版本描述 */
    public function updateVersion(int $versionId, string $description = ''): array
    {
        $this->bom->getVersion($versionId) ?: throw new \BomException('版本不存在或无权访问');
        $this->db->prepare("UPDATE bom_versions SET description=? WHERE id=? AND workspace_id=?")
                 ->execute([$description, $versionId, $this->workspaceId]);
        traceLog($this->uid, 'bom_version_update', 'bom_version', $versionId, '编辑BOM版本描述');
        return ['version_id' => $versionId];
    }

    /** 归档版本（released/其他 → archived，只读保留） */
    public function archiveVersion(int $versionId): array
    {
        $v = $this->bom->getVersion($versionId);
        if (!$v) throw new \BomException('版本不存在或无权访问');
        if ($v['status'] === 'draft') throw new \BomException('草稿版本请直接删除或发布，无法归档');
        $this->db->prepare("UPDATE bom_versions SET status='archived' WHERE id=? AND workspace_id=?")
                 ->execute([$versionId, $this->workspaceId]);
        traceLog($this->uid, 'bom_version_archive', 'bom_version', $versionId, "归档BOM版本:{$v['version']}");
        return ['version_id' => $versionId];
    }

    /** 删除版本（仅草稿，且非当前编辑目标；有明细则级联删除） */
    public function deleteVersion(int $versionId): array
    {
        $v = $this->bom->getVersion($versionId);
        if (!$v) throw new \BomException('版本不存在或无权访问');
        if ($v['status'] !== 'draft') throw new \BomException('仅草稿版本可删除（已发布/归档为只读留存）');
        $projectId = (int)$v['project_id'];
        $project = $this->bom->loadOwnedProject($projectId);
        if ((int)$project['current_version_id'] === $versionId) throw new \BomException('当前编辑草稿不可删除');
        $this->db->prepare("DELETE FROM bom_items WHERE version_id=? AND workspace_id=?")->execute([$versionId, $this->workspaceId]);
        $this->db->prepare("DELETE FROM bom_versions WHERE id=? AND workspace_id=?")->execute([$versionId, $this->workspaceId]);
        traceLog($this->uid, 'bom_version_delete', 'bom_version', $versionId, "删除BOM版本:{$v['version']}");
        return ['version_id' => $versionId];
    }

    /** 回滚到某版本：基于历史版本克隆出恢复草稿作为新编辑目标（保留历史不变） */
    public function rollbackToVersion(int $projectId, int $versionId, string $description = ''): array
    {
        $this->bom->loadOwnedProject($projectId);
        $version = $this->bom->getVersion($versionId);
        if (!$version || (int)$version['project_id'] !== $projectId) throw new \BomException('版本不存在或无权访问');
        $next = $this->cloneVersion($projectId, $versionId, '回滚-' . $version['version'], '回滚到版本「' . $version['version'] . '」的恢复草稿' . ($description !== '' ? "（{$description}）" : ''));
        traceLog($this->uid, 'bom_version_rollback', 'bom_version', (int)$next['version_id'], "回滚BOM到版本:{$version['version']} 项目ID:{$projectId}");
        return ['version_id' => (int)$next['version_id'], 'version' => $next['version']];
    }

    // ════════ 版本号工具 ════════

    /** 基于版本号建议下一版本号（V1.0 → V1.1，V1.9 → V2.0；非标准则追加 .x） */
    public function suggestNextVersion(string $version): string
    {

        if (preg_match('/^(?<p>[Vv]?)(?<M>\d+)\.(?<m>\d+)$/', $version, $m)) {
            $prefix = $m['p'];   // '' 或 'V' 或 'v'
            $major  = (int)$m['M'];
            $minor  = (int)$m['m'] + 1;
            if ($minor >= 10) { $major++; $minor = 0; }
            return $prefix . $major . '.' . $minor;
        }
        return $version . '.x';
    }

    /** 是否为 x.y 数字版本（可选 V/v 前缀） */
    public function isNumericVersion(string $version): bool
    {
        return (bool)preg_match('/^[Vv]?\d+\.\d+$/', trim((string)$version));
    }

    /** 比较两个 x.y 数字版本：$a 是否严格小于 $b（任一非数字返回 false） */
    public function versionLessThan(string $a, string $b): bool
    {
        if (!preg_match('/^[Vv]?(\d+)\.(\d+)$/', $a, $ma) || !preg_match('/^[Vv]?(\d+)\.(\d+)$/', $b, $mb)) {
            return false;
        }
        $av = (int)$ma[1] * 100000 + (int)$ma[2];
        $bv = (int)$mb[1] * 100000 + (int)$mb[2];
        return $av < $bv;
    }

    // ════════ 版本对比 / 变更记录 ════════

    /** 对比两个版本的明细差异。 */
    public function diffVersions(int $versionA, int $versionB): array
    {
        $a = $this->versionItemsIndex($versionA);
        $b = $this->versionItemsIndex($versionB);
        $added = []; $removed = []; $qtyChanged = []; $nochange = [];
        foreach ($a as $key => $ai) {
            if (!isset($b[$key])) { $removed[] = ['key' => $key] + $ai; continue; }
            $bi = $b[$key];
            $fieldsChanged = [];
            foreach (['qty', 'reference', 'remark'] as $f) {
                if ((string)($ai[$f] ?? '') !== (string)($bi[$f] ?? '')) $fieldsChanged[] = $f;
            }
            if (!empty($fieldsChanged)) $qtyChanged[] = ['key' => $key, 'a' => $ai, 'b' => $bi, 'changed' => $fieldsChanged];
            else $nochange[] = $key;
        }
        foreach ($b as $key => $bi) {
            if (!isset($a[$key])) $added[] = ['key' => $key] + $bi;
        }
        return ['added' => $added, 'removed' => $removed, 'qty_changed' => $qtyChanged, 'nochange' => $nochange,
                'a' => ['id' => $versionA], 'b' => ['id' => $versionB]];
    }

    /** 读取某版本明细并构建索引（diff 内部用） */
    public function versionItemsIndex(int $versionId): array
    {
        $index = [];
        $stmt = $this->db->prepare("SELECT id, part_id, platform_part_no, model, qty, reference, remark FROM bom_items WHERE version_id=? AND workspace_id=? ORDER BY id");
        $stmt->execute([$versionId, $this->workspaceId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int)($r['part_id'] ?? 0);
            $key = $this->bom->diffKey($r['platform_part_no'], $r['model'], $pid);
            if ($key === '') $key = '#' . $r['id'];
            if (isset($index[$key])) {

                $index[$key]['qty'] += (int)$r['qty'];
                $index[$key]['reference'] = $this->bom->mergeReference((string)$index[$key]['reference'], (string)$r['reference']);
                $index[$key]['remark']    = $this->bom->mergeReference((string)$index[$key]['remark'], (string)$r['remark']);
                continue;
            }
            $index[$key] = [
                'platform_part_no' => (string)$r['platform_part_no'],
                'model' => (string)$r['model'],
                'qty' => (int)$r['qty'],
                'reference' => (string)$r['reference'],
                'remark' => (string)$r['remark'],
                'part_id' => $pid,
            ];
        }
        return $index;
    }

    /** 当前版本相对其迭代来源（base_version_id）的变更清单（发布记录用） */
    public function getVersionChange(int $versionId): array
    {
        $v = $this->bom->getVersion($versionId);
        if (!$v) return ['has_base' => false, 'diff' => []];
        $base = (int)($v['base_version_id'] ?? 0);
        if ($base <= 0) return ['has_base' => false, 'diff' => []];
        return ['has_base' => true, 'diff' => $this->diffVersions($base, $versionId),
                'base_version' => ($this->bom->getVersion($base)['version'] ?? '')];
    }
}
