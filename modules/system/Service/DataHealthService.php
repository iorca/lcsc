<?php
declare(strict_types=1);

namespace Modules\System\Service;

/** 数据健康检测服务 */
final class DataHealthService
{
    /** 缓存键（存 settings.k，值为 JSON 快照） */
    public const CACHE_KEY = 'data_health_snapshot';

    /** 快照结构版本：口径或文案变更时递增 */
    public const CACHE_VERSION = 2;

    /** 缓存有效期（秒）：默认 6 小时，过期后下次访问触发重算 */
    public const CACHE_TTL = 21600;

    /** 全量比对类检测的物料数阈值：超过则提示"数据量较大" */
    public const SCAN_THRESHOLD = 5000;

    private \PDO $db;
    private int $uid;
    private int $dataUid;
    private int $workspaceId;
    private bool $canViewCost;

    public function __construct(\PDO $db, int $uid, int $dataUid)
    {
        $this->db         = $db;
        $this->uid        = $uid;
        $this->dataUid    = $dataUid;
        $this->workspaceId = \getWorkspaceId();
        $this->canViewCost = \can_view_cost();
    }

    /** 检测项定义表 */
    private function checkItems(): array
    {
        $ws = $this->workspaceId;
        $du = $this->dataUid;

        // ── A 组：物料主档（只查 parts 单表，最便宜）──
        $partItems = [
            'missing_model' => [
                'group' => 'part', 'label' => '缺型号', 'icon' => '🔤', 'severity' => 'critical',
                'hint' => '型号是物料的身份证：搜索、匹配替代料、导入去重都靠它。请在物料列表点「编辑」补上型号。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (model IS NULL OR model='')",
            ],
            'missing_partno' => [
                'group' => 'part', 'label' => '缺平台编号', 'icon' => '🔢', 'severity' => 'warn',
                'hint' => '平台编号是导入时对号入座、以及查价的依据；缺了同款料容易被重复建档。建议照平台订单补齐。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (platform_part_no IS NULL OR platform_part_no='')",
            ],
            'missing_name' => [
                // 名称是给人看的说明，缺失不影响任何业务流程 → 仅供参考、不扣健康分
                'group' => 'part', 'label' => '缺商品名称', 'icon' => '🏷️', 'severity' => 'info',
                'hint' => '名称只是给人看的说明，缺了不影响收发与盘点；列表里会显示为空，方便时补上即可。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (product_name IS NULL OR product_name='')",
            ],
            'missing_brand' => [
                'group' => 'part', 'label' => '缺品牌', 'icon' => '®️', 'severity' => 'warn',
                'hint' => '推荐替代料时会用品牌判断兼容性；缺品牌，系统只能少考虑一个维度，建议补齐。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (brand IS NULL OR brand='')",
            ],
            'missing_package' => [
                'group' => 'part', 'label' => '缺封装', 'icon' => '📐', 'severity' => 'warn',
                'hint' => '封装（如 0603、SOT-23）是替代料能不能直接换上的硬条件，缺了系统不会给出推荐。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (package IS NULL OR package='')",
            ],
            'missing_unit' => [
                'group' => 'part', 'label' => '缺商品单位', 'icon' => '📐', 'severity' => 'info',
                'hint' => '单位影响标签打印与「个 / 卷 / 包」的语义，不影响收发数量本身，可按需补齐。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (unit IS NULL OR unit='')",
            ],
            'missing_datasheet' => [
                'group' => 'part', 'label' => '缺数据手册', 'icon' => '📄', 'severity' => 'info',
                'hint' => '查规格时最常用；缺了不影响任何业务流程。可在物料编辑弹窗补链接，或直接上传 PDF。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (datasheet IS NULL OR datasheet='')
                               AND NOT EXISTS (SELECT 1 FROM attachments a
                                               WHERE a.target_type='part' AND a.target_id=parts.id
                                                 AND a.filetype='pdf')",
            ],
            'missing_image' => [
                'group' => 'part', 'label' => '缺物料图片', 'icon' => '🖼️', 'severity' => 'info',
                'hint' => '有图更认得出料，但缺图不影响出入库与盘点；有空时批量补图会更好用。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (image IS NULL OR image='')
                               AND NOT EXISTS (SELECT 1 FROM attachments a
                                               WHERE a.target_type='part' AND a.target_id=parts.id
                                                 AND a.filetype='image')",
            ],
        ];

        // 成本类检测（受版本门控）
        if ($this->canViewCost) {
            $partItems['missing_cost'] = [
                'group' => 'part', 'label' => '缺成本', 'icon' => '💰', 'severity' => 'warn',
                'hint' => '没有采购单价，就无法统计库存金额与 BOM 成本。入库时填单价即可逐步补齐。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND (avg_cost IS NULL OR avg_cost=0)",
            ];
        }

        // ── B 组：库存一致性 ──
        $stockItems = [
            'stock_cache_drift' => [
                'group' => 'stock', 'label' => '库存数字对不上', 'icon' => '⚠️', 'severity' => 'critical',
                'hint' => '列表上的库存数与出入库流水算出来的不一致（多为中途异常导致）。点「一键修复」按流水回算即可。',
                'fix' => 'sync_stock',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts p
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                               AND p.stock <> COALESCE(
                                   (SELECT SUM(s.quantity) FROM stock s
                                    WHERE s.part_id=p.id AND s.workspace_id={$ws}), 0)",
            ],
            'cost_dirty_pending' => [
                'group' => 'stock', 'label' => '成本待重算', 'icon' => '🔄', 'severity' => 'warn',

                'hint' => '刚入过库、加权平均价还没算完，属正常的中间状态。打开「资产总览」会自动重算，也可点「一键修复」。',
                'fix' => 'recalc_cost',
                'needCost' => true,
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND cost_dirty=1",
            ],
            'negative_stock' => [
                'group' => 'stock', 'label' => '负库存', 'icon' => '⬇️', 'severity' => 'critical',
                'hint' => '库存出现负数，说明有过量出库或历史数据问题，需要点开明细逐条核对后修正。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND stock < 0",
            ],
            'no_stock_flow' => [
                'group' => 'stock', 'label' => '从未有过流水', 'icon' => '🧊', 'severity' => 'info',
                'hint' => '有库存却没有任何出入库记录，通常是手工录入或导入建的档，属正常现象，无需处理。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts p
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                               AND p.stock > 0
                               AND NOT EXISTS (SELECT 1 FROM stock_log sl
                                               WHERE sl.part_id=p.id AND sl.workspace_id={$ws})",
            ],
        ];

        // ── C 组：分类与库位 ──
        $catItems = [
            'uncategorized' => [
                'group' => 'category', 'label' => '未分类', 'icon' => '🏷️', 'severity' => 'warn',
                'hint' => '没归入任何分类，按分类筛选、分类阈值与分类报表都不会包含它。在物料编辑里选一个分类即可。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts p
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                               AND NOT EXISTS (SELECT 1 FROM part_categories pc WHERE pc.part_id=p.id)",
            ],
            'dangling_location' => [
                'group' => 'category', 'label' => '库位已失效', 'icon' => '🔗', 'severity' => 'critical',
                'hint' => '物料指向的库位已经从库位树里删掉了，按库位筛选时这批料会凭空消失。重新指定一个库位即可。',
                'needLocation' => true,
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts p
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                               AND p.location_id IS NOT NULL AND p.location_id > 0
                               AND NOT EXISTS (SELECT 1 FROM locations l
                                               WHERE l.id=p.location_id AND l.workspace_id={$ws})",
            ],
            'location_text_mismatch' => [
                'group' => 'category', 'label' => '库位名称对不上', 'icon' => '✂️', 'severity' => 'warn',
                'hint' => '物料上写的库位与库位树里的完整路径不一致，统计时会被算成两个库位。改成库位树里的完整路径（形如「仓库 / 库区 / 库位」）即可。',
                'needLocation' => true,

                'countSql' => "SELECT COUNT(*) AS cnt FROM parts p
                               JOIN locations l ON l.id=p.location_id AND l.workspace_id={$ws}
                               LEFT JOIN locations l2 ON l2.id=l.parent_id AND l2.workspace_id={$ws}
                               LEFT JOIN locations l1 ON l1.id=l2.parent_id AND l1.workspace_id={$ws}
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                               AND p.location <> '' AND p.location <> CONCAT_WS(' / ', l1.name, l2.name, l.name)",
            ],
            'stocked_no_location' => [
                'group' => 'category', 'label' => '有库存无库位', 'icon' => '📍', 'severity' => 'info',
                'hint' => '有库存却没写库位，盘点时不容易找到实物。补一个库位（或文本备注）会更顺手。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM parts
                               WHERE user_id={$du} AND workspace_id={$ws} AND status!='deleted'
                               AND stock > 0 AND (location IS NULL OR location='')",
            ],
            'orphan_category' => [
                'group' => 'category', 'label' => '空分类', 'icon' => '🗑️', 'severity' => 'info',
                'hint' => '这个分类下没有任何物料，也不是其他分类的上级；分类太多会不好找，可考虑删掉。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM categories c
                               WHERE c.user_id={$du} AND c.workspace_id={$ws}
                               AND NOT EXISTS (SELECT 1 FROM part_categories pc
                                               JOIN parts p ON p.id=pc.part_id AND p.status!='deleted'
                                               WHERE pc.category_id=c.id)
                               AND NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id=c.id)",
            ],
        ];

        // ── D 组：业务关联 ──
        $relItems = [
            'bom_pending' => [
                'group' => 'relation', 'label' => 'BOM 未绑定行', 'icon' => '📋', 'severity' => 'warn',

                'hint' => 'BOM 里还有没关联到主库物料的行，缺料分析会按可用量 0 计算。到 BOM 项目里点该行的「替代料」，从物料库选择并绑定/替换。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM bom_items bi
                               JOIN bom_projects bp ON bp.id=bi.project_id
                               WHERE bp.user_id={$du} AND bp.workspace_id={$ws}
                               AND bi.is_incomplete_part=1",
            ],
            'alt_unconfirmed' => [
                'group' => 'relation', 'label' => '替代料待确认', 'icon' => '🔗', 'severity' => 'info',
                'hint' => '推荐出的替代料还没确认通过，出库时不会把它算进可用量；确认后即可参与配单。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM part_alternatives pa
                               JOIN parts p ON p.id=pa.source_part_id
                               WHERE p.user_id={$du} AND p.workspace_id={$ws} AND pa.status='pending'",
            ],
            'dangling_attachment' => [
                'group' => 'relation', 'label' => '附件挂在已删物料上', 'icon' => '📎', 'severity' => 'warn',
                'hint' => '附件指向的物料已经被删除，属于残留数据，会让图片列表出现异常。',
                'countSql' => "SELECT COUNT(*) AS cnt FROM attachments a
                               WHERE a.target_type='part' AND a.target_id IS NOT NULL
                               AND NOT EXISTS (SELECT 1 FROM parts p
                                               WHERE p.id=a.target_id AND p.user_id={$du} AND p.workspace_id={$ws})",
            ],
        ];

        // ── E 组：治理健康 ──
        $govItems = [
            'deprecated_cleanable' => [
                'group' => 'governance', 'label' => '废弃字段可清理', 'icon' => '🧹', 'severity' => 'info',
                'hint' => '台账里标记为「可删除」、且列确实还在的历史字段，确认不再需要后可清理（清理前请先备份）。',

                'fix' => 'cleanup_deprecated',

                'countSql' => "SELECT COUNT(*) AS cnt FROM deprecated_fields df
                               WHERE df.can_delete=1
                                 AND EXISTS (
                                     SELECT 1 FROM information_schema.COLUMNS c
                                     WHERE c.TABLE_SCHEMA = DATABASE()
                                       AND c.TABLE_NAME = df.table_name
                                       AND c.COLUMN_NAME = df.column_name
                                 )",
            ],
            'import_residue'     => [
                'group' => 'governance', 'label' => '导入中间数据未清理', 'icon' => '🧽', 'severity' => 'info',
                'hint' => '导入的中间结果超过保留期还留着，会多占一点空间；正常情况下系统会自动清理，也可点「修复」立即清理。',

                'fix' => 'cleanup_import_residue',
                'countSql' => "SELECT (
                                  (SELECT COUNT(*) FROM normalized_import_items
                                   WHERE user_confirmed=1 AND created_time < DATE_SUB(NOW(), INTERVAL 7 DAY))
                                + (SELECT COUNT(*) FROM import_document
                                   WHERE status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','" . \App\Core\Enum\ImportStatus::CANCELLED . "')
                                     AND created_time < DATE_SUB(NOW(), INTERVAL 7 DAY))
                               ) AS cnt",
            ],
        ];

        $items = array_merge($partItems, $stockItems, $catItems, $relItems, $govItems);

        $items['db_version_behind'] = $this->dbVersionItem();

        // 门控过滤：库位类检测仅团队版（个人版无结构化库位）
        foreach ($items as $key => $def) {
            if (!empty($def['needCost']) && !$this->canViewCost) unset($items[$key]);
            if (!empty($def['needLocation']) && !\hasFeature('location_manage')) unset($items[$key]);
        }

        return $items;
    }

    /** 数据库版本检测项 */
    private function dbVersionItem(): array
    {
        $current = '';
        try {
            $cur = $this->db->query("SELECT database_version FROM system_meta ORDER BY id DESC LIMIT 1");
            $current = trim((string)$cur->fetchColumn());
        } catch (\Throwable $e) {
            error_log('DataHealthService dbVersion: ' . $e->getMessage());
        }

        $behind = $current !== '' && version_compare($current, DATABASE_VERSION, '<');
        $cnt = $behind ? 1 : 0;

        return [
            'group' => 'governance', 'label' => '数据库结构版本落后', 'icon' => '🗓️', 'severity' => 'critical',
            'hint'   => $behind
                ? "库里的结构版本是 {$current}，比代码要求的 " . DATABASE_VERSION . ' 旧，部分功能会缺字段。结构会在每次请求时由 initDB() 幂等补齐；若长期未补齐，请到「网站设置 → 版本信息」看「数据库版本」一行，那里会给出原因（如手工覆盖升级漏了 app/core/Migration/Versions/）。'
                : '库中版本与代码一致',
            'countSql' => "SELECT {$cnt} AS cnt",
        ];
    }

    /** 检测项分组定义（前端折叠用）。desc 用大白话写「这组在查什么」，不出现表名 */
    public static function groups(): array
    {
        return [
            'part'       => ['label' => '物料信息', 'icon' => '📦', 'desc' => '型号、品牌、封装等关键字段是否填全'],
            'stock'      => ['label' => '库存数字', 'icon' => '📊', 'desc' => '列表上的数量与出入库流水是否对得上'],
            'category'   => ['label' => '分类与库位', 'icon' => '🏷️', 'desc' => '归到哪一类、放在哪个货架是否有效'],
            'relation'   => ['label' => '业务关联', 'icon' => '🔗', 'desc' => 'BOM、替代料、附件的引用是否还有效'],
            'governance' => ['label' => '系统与残留', 'icon' => '🛠️', 'desc' => '版本是否最新、有没有历史残留数据'],
        ];
    }

    /** 取概览：优先读缓存，过期或缺失时重算并写回 */
    public function summary(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache();
            if ($cached !== null) return $this->applyLiveFlags($cached);
        }
        $result = $this->applyLiveFlags($this->computeSummary());
        $this->writeCache($result);
        return $result;
    }

    /** 把「当前访问者」才能决定的字段按现值覆写（快照里可能存着别人的权限） */
    private function applyLiveFlags(array $result): array
    {
        $result['can_edit']     = \hasPermission('can_edit');
        $result['can_cost']     = $this->canViewCost;
        $result['can_cost_fix'] = $this->canViewCost;
        return $result;
    }

    /** 读缓存快照 */
    private function readCache(): ?array
    {
        $raw = \getSetting(self::CACHE_KEY, '');
        if ($raw === '' || $raw === null) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['generated_at'])) return null;
        if ((int)($data['v'] ?? 1) !== self::CACHE_VERSION) return null;
        if ((time() - (int)$data['generated_at']) > self::CACHE_TTL) return null;

        if (($data['ws'] ?? 0) !== $this->workspaceId) return null;
        if (($data['can_cost'] ?? false) !== $this->canViewCost) return null;
        $data['cached'] = true;
        return $data;
    }

    /** 写缓存快照（settings 单行 JSON，超长则放弃写缓存） */
    private function writeCache(array $result): void
    {
        $result['ws']        = $this->workspaceId;
        $result['can_cost']  = $this->canViewCost;
        $result['v']         = self::CACHE_VERSION;
        $result['generated_at'] = time();

        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 60000) return;   // 超大不入缓存，每次重算
        try {
            $this->db->prepare("INSERT INTO settings (k,v) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE v=VALUES(v)")
                ->execute([self::CACHE_KEY, $json]);

            \clearSettingCache(self::CACHE_KEY);
        } catch (\Throwable $e) {
            error_log('DataHealthService writeCache: ' . $e->getMessage());
        }
    }

    /** 清除缓存（修复动作执行后调用） */
    public static function invalidateCache(\PDO $db): void
    {
        try {
            $db->prepare("DELETE FROM settings WHERE k=?")->execute([self::CACHE_KEY]);
            \clearSettingCache(self::CACHE_KEY);
        } catch (\Throwable $e) {
            error_log('DataHealthService invalidateCache: ' . $e->getMessage());
        }
    }

    /** settings 里存忽略键列表的键名 */
    private const IGNORED_KEY = 'health_ignored_keys';

    /** 当前已忽略的检测项 key 列表 */
    public function ignoredKeys(): array
    {
        $raw = (string)\getSetting(self::IGNORED_KEY, '');
        if ($raw === '') return [];
        $arr = json_decode($raw, true);
        return is_array($arr) ? array_values(array_map('strval', $arr)) : [];
    }

    /** 忽略/恢复某检测项（仅 info 级可用 —— 忽略 critical/warn 会让人误以为问题已解决） */
    public function setIgnored(string $key, bool $on): void
    {
        $items = $this->checkItems();
        if (!isset($items[$key]) || ($items[$key]['severity'] ?? '') !== 'info') {
            throw new \RuntimeException('仅「仅供参考」级别的检测项支持忽略');
        }
        $list = $this->ignoredKeys();
        $idx  = array_search($key, $list, true);
        if ($on && $idx === false) $list[] = $key;
        if (!$on && $idx !== false) unset($list[$idx]);
        \setSetting(self::IGNORED_KEY, json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
        self::invalidateCache($this->db);
    }

    /** 跑一遍全部检测项计数，组装概览 */
    public function computeSummary(): array
    {
        $items     = $this->checkItems();
        $groups    = self::groups();
        $total     = $this->countParts();
        $large     = $total > self::SCAN_THRESHOLD;
        $ignored   = $this->ignoredKeys();   // 「仅供参考」忽略清单（忽略后仍计数，只是不默认展示）
        $checks    = [];
        $byGroup   = array_fill_keys(array_keys($groups), ['total' => 0, 'critical' => 0, 'warn' => 0, 'info' => 0, 'issue_count' => 0]);

        foreach ($items as $key => $def) {
            $cnt = $this->safeCount($def['countSql'] ?? '');
            $checks[] = [
                'key'      => $key,
                'group'    => $def['group'],
                'label'    => $def['label'],
                'icon'     => $def['icon'],
                'severity' => $def['severity'],
                'hint'     => $def['hint'] ?? '',
                'fixable'  => !empty($def['fix']),
                'fix'      => $def['fix'] ?? '',
                'count'    => $cnt,
                'percent'  => $total > 0 ? round($cnt / $total * 100, 1) : 0.0,
                'ignored'  => in_array($key, $ignored, true),
            ];
            $g = $def['group'];
            $byGroup[$g]['total']++;
            if ($cnt > 0) {
                $byGroup[$g][$def['severity']]++;
                $byGroup[$g]['issue_count'] += $cnt;
            }
        }

        $penalty = 0.0;
        $maxPenalty = 0.0;
        $todoCount = 0;      // 需处理（critical + warn）条数
        $refCount  = 0;      // 仅供参考（info）条数
        $mustChecks = 0;     // 有异常的 critical 检测项数
        $warnChecks = 0;
        $refChecks  = 0;
        foreach ($checks as $c) {
            if ($c['severity'] === 'info') {
                if ($c['count'] > 0) { $refCount += $c['count']; $refChecks++; }
                continue;
            }
            $weight = ['critical' => 3.0, 'warn' => 1.5][$c['severity']] ?? 1.0;
            $maxPenalty += $weight;
            if ($c['count'] <= 0) continue;
            $todoCount += $c['count'];
            if ($c['severity'] === 'critical') $mustChecks++; else $warnChecks++;
            $penalty += $weight * ($total > 0 ? min(1.0, $c['count'] / $total) : 0.0);
        }
        $score = $maxPenalty > 0 ? max(0, (int)round(100 - ($penalty / $maxPenalty) * 100)) : 100;

        return [
            'total'        => $total,
            'score'        => $score,
            'level'        => $score >= 90 ? 'ok' : ($score >= 70 ? 'warn' : 'bad'),
            'checks'       => $checks,
            'groups'       => $groups,
            'by_group'     => $byGroup,
            'issue_total'  => $todoCount + $refCount,
            'todo_count'   => $todoCount,
            'ref_count'    => $refCount,
            'must_checks'  => $mustChecks,
            'warn_checks'  => $warnChecks,
            'ref_checks'   => $refChecks,
            'check_total'  => count($checks),
            'ignored_keys' => $this->ignoredKeys(),   // 前端工具条「显示已忽略（N）」计数用
            'large_scale'  => $large,
            'can_cost'     => $this->canViewCost,
            'can_edit'     => \hasPermission('can_edit'),
            'can_cost_fix' => $this->canViewCost,
            'cached'       => false,
            'generated_at' => time(),
        ];
    }

    /** 物料总数（有效状态） */
    private function countParts(): int
    {
        return $this->safeCount(
            "SELECT COUNT(*) AS cnt FROM parts
             WHERE user_id={$this->dataUid} AND workspace_id={$this->workspaceId} AND status!='deleted'"
        );
    }

    /** 安全执行计数：失败返回 0 并记日志，绝不抛出 */
    private function safeCount(string $sql): int
    {
        if ($sql === '') return 0;
        try {
            $stmt = $this->db->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('DataHealthService count failed: ' . $e->getMessage());
            return 0;
        }
    }

    /** 取某检测项的明细列表 */
    public function detail(string $key, int $page = 1, int $perPage = 20): array
    {
        $items = $this->checkItems();
        if (!isset($items[$key])) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage,
                    'total_page' => 1, 'check' => null];
        }
        $def   = $items[$key];
        $perPage = max(5, min(100, $perPage));
        $page  = max(1, $page);
        $ws    = $this->workspaceId;
        $du    = $this->dataUid;

        $base = $this->detailBaseSql($key, $ws, $du);
        $total = $this->safeCount("SELECT COUNT(*) AS cnt FROM ($base) AS t");
        $offset = ($page - 1) * $perPage;

        $rows = [];
        try {
            $sql = "SELECT * FROM ($base) AS t ORDER BY t.id ASC LIMIT $perPage OFFSET $offset";
            $rows = $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('DataHealthService detail failed: ' . $e->getMessage());
            $rows = [];
        }

        foreach ($rows as &$r) {
            $r['id']    = (int)$r['id'];
            $r['stock'] = (int)($r['stock'] ?? 0);
        }
        unset($r);

        return [
            'items'      => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_page' => max(1, (int)ceil($total / $perPage)),
            'check'      => [
                'key'      => $key,
                'label'    => $def['label'],
                'icon'     => $def['icon'],
                'severity' => $def['severity'],
                'hint'     => $def['hint'] ?? '',
                'fixable'  => !empty($def['fix']),
                'fix'      => $def['fix'] ?? '',
            ],
        ];
    }

    /** 构造明细基查询（返回列：id / model / platform_part_no / product_name / stock / extra） */
    private function detailBaseSql(string $key, int $ws, int $du): string
    {
        // 基表别名固定为 p，后面的 NOT EXISTS 子查询要用 p.id 引用
        $p = "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock FROM parts p
              WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'";

        return match ($key) {
            'missing_model'      => $p . " AND (model IS NULL OR model='')",
            'missing_partno'     => $p . " AND (platform_part_no IS NULL OR platform_part_no='')",
            'missing_name'       => $p . " AND (product_name IS NULL OR product_name='')",
            'missing_brand'      => $p . " AND (brand IS NULL OR brand='')",
            'missing_package'    => $p . " AND (package IS NULL OR package='')",
            'missing_unit'       => $p . " AND (unit IS NULL OR unit='')",

            'missing_datasheet'  => $p . " AND (datasheet IS NULL OR datasheet='')"
                . " AND NOT EXISTS (SELECT 1 FROM attachments a WHERE a.target_type='part' AND a.target_id=p.id AND a.filetype='pdf')",
            'missing_image'      => $p . " AND (image IS NULL OR image='')"
                . " AND NOT EXISTS (SELECT 1 FROM attachments a WHERE a.target_type='part' AND a.target_id=p.id AND a.filetype='image')",
            'missing_cost'       => $p . " AND (avg_cost IS NULL OR avg_cost=0)",

            'stock_cache_drift'  => "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock,
                                           COALESCE((SELECT SUM(s.quantity) FROM stock s
                                                     WHERE s.part_id=p.id AND s.workspace_id={$ws}), 0) AS extra
                                    FROM parts p
                                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                                    AND p.stock <> COALESCE(
                                        (SELECT SUM(s.quantity) FROM stock s
                                         WHERE s.part_id=p.id AND s.workspace_id={$ws}), 0)",
            'cost_dirty_pending' => $p . " AND cost_dirty=1",
            'negative_stock'     => $p . " AND stock < 0",
            'no_stock_flow'      => "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock,
                                           (SELECT MAX(sl.create_time) FROM stock_log sl
                                            WHERE sl.part_id=p.id AND sl.workspace_id={$ws}) AS extra
                                    FROM parts p
                                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                                    AND p.stock > 0
                                    AND NOT EXISTS (SELECT 1 FROM stock_log sl
                                                    WHERE sl.part_id=p.id AND sl.workspace_id={$ws})",

            'uncategorized'      => $p . " AND NOT EXISTS (SELECT 1 FROM part_categories pc WHERE pc.part_id=p.id)",
            'dangling_location'  => "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock,
                                           p.location_id AS extra
                                    FROM parts p
                                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                                    AND p.location_id IS NOT NULL AND p.location_id > 0
                                    AND NOT EXISTS (SELECT 1 FROM locations l
                                                    WHERE l.id=p.location_id AND l.workspace_id={$ws})",

            'location_text_mismatch' => "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock,
                                     CONCAT(p.location, ' ≠ ', CONCAT_WS(' / ', l1.name, l2.name, l.name)) AS extra
                                    FROM parts p
                                    JOIN locations l ON l.id=p.location_id AND l.workspace_id={$ws}
                                    LEFT JOIN locations l2 ON l2.id=l.parent_id AND l2.workspace_id={$ws}
                                    LEFT JOIN locations l1 ON l1.id=l2.parent_id AND l1.workspace_id={$ws}
                                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                                    AND p.location <> '' AND p.location <> CONCAT_WS(' / ', l1.name, l2.name, l.name)",
            'stocked_no_location'=> $p . " AND stock > 0 AND (location IS NULL OR location='')",
            'orphan_category'    => "SELECT c.id, NULL AS model, NULL AS platform_part_no,
                                           c.name AS product_name, 0 AS stock, c.name AS extra
                                    FROM categories c
                                    WHERE c.user_id={$du} AND c.workspace_id={$ws}
                                    AND NOT EXISTS (SELECT 1 FROM part_categories pc
                                                    JOIN parts p2 ON p2.id=pc.part_id AND p2.status!='deleted'
                                                    WHERE pc.category_id=c.id)
                                    AND NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id=c.id)",

            'bom_pending'        => "SELECT bi.id AS id,
                                           COALESCE(NULLIF(p.model,''), bi.model, '') AS model,
                                           COALESCE(NULLIF(p.platform_part_no,''), bi.platform_part_no, '') AS platform_part_no,
                                           COALESCE(NULLIF(p.product_name,''), JSON_UNQUOTE(JSON_EXTRACT(bi.pending_json,'$.product_name')), '') AS product_name,
                                           0 AS stock, bp.name AS extra, bp.id AS project_id
                                    FROM bom_items bi
                                    JOIN bom_projects bp ON bp.id=bi.project_id
                                    LEFT JOIN parts p ON p.id=bi.part_id AND p.user_id=bp.user_id
                                    WHERE bp.user_id={$du} AND bp.workspace_id={$ws}
                                    AND bi.is_incomplete_part=1",
            'alt_unconfirmed'    => "SELECT p.id, p.model, p.platform_part_no, p.product_name,
                                           COALESCE(p.stock,0) AS stock, pa.target_part_id AS extra
                                    FROM part_alternatives pa
                                    JOIN parts p ON p.id=pa.source_part_id
                                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND pa.status='pending'",
            'dangling_attachment'=> "SELECT p.id, p.model, p.platform_part_no, p.product_name,
                                           COALESCE(p.stock,0) AS stock, a.id AS extra
                                    FROM attachments a
                                    LEFT JOIN parts p ON p.id=a.target_id
                                    WHERE a.target_type='part' AND a.target_id IS NOT NULL
                                    AND (p.id IS NULL OR p.user_id<>{$du} OR p.workspace_id<>{$ws})",

            'db_version_behind'  => "SELECT 0 AS id, NULL AS model,
                                           (SELECT database_version FROM system_meta ORDER BY id DESC LIMIT 1) AS platform_part_no,
                                           '".DATABASE_VERSION."' AS product_name,
                                           0 AS stock, '' AS extra",
            'deprecated_cleanable'=> "SELECT 0 AS id, NULL AS model,
                                           df.table_name AS platform_part_no,
                                           df.column_name AS product_name, 0 AS stock,
                                           df.replacement AS extra
                                    FROM deprecated_fields df
                                    WHERE df.can_delete=1
                                      AND EXISTS (
                                          SELECT 1 FROM information_schema.COLUMNS c
                                          WHERE c.TABLE_SCHEMA = DATABASE()
                                            AND c.TABLE_NAME = df.table_name
                                            AND c.COLUMN_NAME = df.column_name
                                      )",
            'import_residue'     => "SELECT 'normalized_import_items' AS model,
                                           CAST((SELECT COUNT(*) FROM normalized_import_items
                                                 WHERE user_confirmed=1
                                                   AND created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS CHAR) AS platform_part_no,
                                           '超过 7 天未清理' AS product_name,
                                           0 AS stock, '' AS extra, 1 AS id
                                    UNION ALL
                                    SELECT 'import_document',
                                           CAST((SELECT COUNT(*) FROM import_document
                                                 WHERE status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','" . \App\Core\Enum\ImportStatus::CANCELLED . "')
                                                   AND created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS CHAR),
                                           '超过 7 天未清理', 0, '', 2",

            default => "SELECT p.id, p.model, p.platform_part_no, p.product_name, p.stock FROM parts p
                        WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted' LIMIT 0",
        };
    }

    /** 明细列的中文表头（按检测项返回列名映射） */
    public static function detailColumns(string $key): array
    {
        return match ($key) {
            'stock_cache_drift'   => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '缓存数量', 'extra' => '实际数量'],
            'no_stock_flow'       => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '库存', 'extra' => '最近出入库'],
            'bom_pending'         => ['id' => 'BOM行ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '库存', 'extra' => 'BOM 项目'],
            'alt_unconfirmed'     => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '库存', 'extra' => '替代目标ID'],
            'dangling_attachment' => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '库存', 'extra' => '附件ID'],
            'orphan_category'     => ['id' => '分类ID', 'model' => '—', 'platform_part_no' => '—',
                                      'product_name' => '分类名', 'stock' => '—', 'extra' => '分类名'],
            'deprecated_cleanable'=> ['id' => '—', 'model' => '—', 'platform_part_no' => '表名',
                                      'product_name' => '字段名', 'stock' => '—', 'extra' => '替代方案'],
            'import_residue'      => ['id' => '—', 'model' => '中间表', 'platform_part_no' => '滞留行数',
                                      'product_name' => '说明', 'stock' => '—', 'extra' => '—'],
            'db_version_behind'   => ['id' => '—', 'model' => '—', 'platform_part_no' => '当前库版本',
                                      'product_name' => '期望版本', 'stock' => '—', 'extra' => '—'],
            'dangling_location', 'location_text_mismatch'
                                 => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                     'product_name' => '名称', 'stock' => '库存', 'extra' => '库位ID'],
            default              => ['id' => '物料ID', 'model' => '型号', 'platform_part_no' => '编号',
                                      'product_name' => '名称', 'stock' => '库存', 'extra' => '备注'],
        };
    }

    /** 执行修复动作 */
    public function fix(string $action, int $limit = 200): array
    {
        $limit = max(1, min(2000, $limit));

        return match ($action) {
            'sync_stock'              => $this->fixSyncStock($limit),
            'recalc_cost'             => $this->fixRecalcCost($limit),
            'cleanup_deprecated'      => $this->fixCleanupDeprecated(),
            'cleanup_import_residue'  => $this->fixCleanupImportResidue(),
            default => ['ok' => false, 'fixed' => 0, 'msg' => '未知的修复动作：' . $action],
        };
    }

    /** 清理废弃字段：DROP 台账里标 `can_delete=1` 且**列仍存在**的历史列 */
    private function fixCleanupDeprecated(): array
    {

        try {
            $rows = $this->db->query(
                "SELECT df.table_name, df.column_name FROM deprecated_fields df
                 WHERE df.can_delete=1
                   AND EXISTS (
                       SELECT 1 FROM information_schema.COLUMNS c
                       WHERE c.TABLE_SCHEMA = DATABASE()
                         AND c.TABLE_NAME = df.table_name
                         AND c.COLUMN_NAME = df.column_name
                   )"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['ok' => false, 'fixed' => 0, 'msg' => '读取废弃字段台账失败：' . $e->getMessage()];
        }

        if (empty($rows)) {
            return ['ok' => true, 'fixed' => 0, 'msg' => '没有可清理的废弃字段'];
        }

        $dropped = 0;
        $failed  = 0;
        foreach ($rows as $r) {
            $table  = (string)$r['table_name'];
            $column = (string)$r['column_name'];
            // 表名/列名来自本地台账，但仍只接受「字母数字下划线」形态，杜绝任何注入面
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $failed++;
                continue;
            }
            try {
                $this->db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                $dropped++;
            } catch (Throwable $e) {
                $failed++;
                error_log("fixCleanupDeprecated: DROP {$table}.{$column} 失败：{$e->getMessage()}");
            }
        }

        $msg = "已清理 {$dropped} 个废弃字段" . ($failed > 0 ? "（{$failed} 个失败，详见错误日志）" : '');
        return ['ok' => $dropped > 0 || $failed === 0, 'fixed' => $dropped, 'msg' => $msg];
    }

    /** 清理导入中间数据（保留期 7 天，与 Schema/Import.php 的自动清理同口径） */
    private function fixCleanupImportResidue(): array
    {
        $deleted = 0;
        $failed  = 0;

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM normalized_import_items
                 WHERE user_confirmed=1 AND created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $stmt->execute();
            $deleted += $stmt->rowCount();
        } catch (Throwable $e) {
            $failed++;
            error_log('fixCleanupImportResidue normalized: ' . $e->getMessage());
        }

        foreach (['import_raw_data', 'import_mapping'] as $table) {
            try {
                $this->db->exec("DELETE FROM `{$table}` WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            } catch (Throwable $e) {
                $failed++;
                error_log("fixCleanupImportResidue {$table}: " . $e->getMessage());
            }
        }

        try {
            $expired = $this->db->query(
                "SELECT file_path FROM import_document
                 WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','"
                                  . \App\Core\Enum\ImportStatus::CANCELLED . "','"
                                  . \App\Core\Enum\ImportStatus::UPLOADED . "')"
            )->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($expired as $fp) {
                $fp = (string)$fp;
                if ($fp !== '' && str_starts_with(basename($fp), 'imp_') && is_file($fp)) @unlink($fp);
            }
        } catch (Throwable $e) {
            error_log('fixCleanupImportResidue files: ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "DELETE FROM import_document
                 WHERE created_time < DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND status IN ('" . \App\Core\Enum\ImportStatus::COMPLETED . "','"
                                  . \App\Core\Enum\ImportStatus::CANCELLED . "','"
                                  . \App\Core\Enum\ImportStatus::UPLOADED . "')"
            );
            $stmt->execute();
            $deleted += $stmt->rowCount();
        } catch (Throwable $e) {
            $failed++;
            error_log('fixCleanupImportResidue documents: ' . $e->getMessage());
        }

        $msg = "已清理 {$deleted} 条过期导入中间数据" . ($failed > 0 ? "（{$failed} 类清理失败，详见错误日志）" : '');
        return ['ok' => $deleted > 0 || $failed === 0, 'fixed' => $deleted, 'msg' => $msg];
    }

    /** 修复库存缓存不一致：按 stock 事实表回算 parts.stock / parts.damaged */
    private function fixSyncStock(int $limit): array
    {
        $ws = $this->workspaceId;
        $du = $this->dataUid;
        try {
            $sql = "SELECT p.id FROM parts p
                    WHERE p.user_id={$du} AND p.workspace_id={$ws} AND p.status!='deleted'
                    AND p.stock <> COALESCE(
                        (SELECT SUM(s.quantity) FROM stock s
                         WHERE s.part_id=p.id AND s.workspace_id={$ws}), 0)
                    LIMIT {$limit}";
            $ids = $this->db->query($sql)->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            error_log('DataHealthService fixSyncStock query: ' . $e->getMessage());
            return ['ok' => false, 'fixed' => 0, 'msg' => '查询失败：' . $e->getMessage()];
        }

        $fixed = 0;
        foreach ($ids as $id) {
            // 复用全局同步函数，保证与库存变更路径完全同一套回算逻辑
            \syncPartStockCache((int)$id);
            $fixed++;
        }
        return [
            'ok'    => true,
            'fixed' => $fixed,
            'msg'   => $fixed > 0
                ? "已按 stock 事实表回写 {$fixed} 个物料的库存缓存"
                : '没有需要同步的物料',
        ];
    }

    /** 修复成本待重算：批量重算并清脏标记 */
    private function fixRecalcCost(int $limit): array
    {
        $fixed = \recalcDirtyCostBatch($this->db, $limit, (int)$this->dataUid, (int)$this->workspaceId);
        return [
            'ok'    => true,
            'fixed' => $fixed,
            'msg'   => $fixed > 0
                ? "已重算 {$fixed} 个物料的加权平均成本"
                : '没有待重算的物料',
        ];
    }

}
