<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use PDO;

/** DashboardAlertAggregator — 工作台「待处理业务」的数据质量类预警聚合 */
final class DashboardAlertAggregator
{
    /** 需要排除的检测键 —— 这些项工作台已有同口径条目，不得重复计入 */
    private const EXCLUDE_KEYS = ['negative_stock'];

    /** 数据质量页深链前缀（拼检测键） */
    private const HEALTH_PAGE = 'index.php?v=data_health&check=';

    private PDO $db;
    private int $uid;
    private int $dataUid;

    public function __construct(PDO $db, int $uid, int $dataUid)
    {
        $this->db      = $db;
        $this->uid     = $uid;
        $this->dataUid = $dataUid;
    }

    /** 取「严重级且确有数量」的检测项，按数量降序 */
    public function getCriticalAlerts(): array
    {
        $snapshot = $this->readSnapshot();
        if ($snapshot === null) return [];

        $out = [];
        foreach (($snapshot['checks'] ?? []) as $c) {
            $key = (string)($c['key'] ?? '');
            if ($key === '' || in_array($key, self::EXCLUDE_KEYS, true)) continue;
            if (($c['severity'] ?? '') !== 'critical') continue;
            $cnt = (int)($c['count'] ?? 0);
            if ($cnt <= 0) continue;
            $out[] = [
                'key'     => $key,
                'label'   => (string)($c['label'] ?? $key),
                'icon'    => (string)($c['icon'] ?? ''),
                'count'   => $cnt,
                'hint'    => (string)($c['hint'] ?? ''),
                'fixable' => !empty($c['fixable']),
                'url'     => self::HEALTH_PAGE . rawurlencode($key),
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        return $out;
    }

    /** 只读快照缓存，**绝不触发重算** */
    private function readSnapshot(): ?array
    {
        try {
            $raw = \getSetting(\Modules\System\Service\DataHealthService::CACHE_KEY, '');
        } catch (\Throwable $e) {
            error_log('DashboardAlertAggregator readSnapshot: ' . $e->getMessage());
            return null;
        }
        if ($raw === '' || $raw === null) return null;

        $data = json_decode((string)$raw, true);
        if (!is_array($data) || !isset($data['generated_at'], $data['checks'])) return null;
        if ((time() - (int)$data['generated_at']) > \Modules\System\Service\DataHealthService::CACHE_TTL) return null;

        if ((int)($data['ws'] ?? 0) !== (int)\getWorkspaceId()) return null;
        if (($data['can_cost'] ?? false) !== \can_view_cost()) return null;

        return $data;
    }
}
