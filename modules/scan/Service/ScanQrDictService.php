<?php
declare(strict_types=1);

namespace Modules\Scan\Service;

use App\Helper\Parser\FieldRegistry;
use PDO;

/** ScanQrDictService — 平台二维码词典（平台字段 → 系统字段） */
final class ScanQrDictService
{
    /** 内置词典所属平台代码（仅 seed 期按 code 找一次；运行时不依赖） */
    public const BUILTIN_PLATFORM_CODE = 'lcsc';

    /** 配置包文件标识（`kind`）与结构版本（`schema`）—— 导入时校验，不符即整体拒绝 */
    public const CONFIG_KIND   = 'lcsc.scan.qr_config';
    public const CONFIG_SCHEMA = 1;

    /** 允许的二维码动作（入库 / 出库）；其余值整体拒绝 */
    private const ALLOWED_ACTIONS = ['in', 'out'];

    private PDO $db;
    private int $dataUid;
    private int $workspaceId;

    public function __construct(PDO $db, int $dataUid, int $workspaceId)
    {
        $this->db          = $db;
        $this->dataUid     = $dataUid;
        $this->workspaceId = $workspaceId;
    }

    /** 把内置词典写进该平台在 `import_template` 的那一行（幂等） */
    public static function seedBuiltin(PDO $db, int $uid, int $wsId, string $builtinCode = self::BUILTIN_PLATFORM_CODE): void
    {
        try {
            $formats = FieldRegistry::builtinQrFormats($builtinCode);
            if ($formats === []) return;

            $pl = $db->prepare("SELECT id FROM platforms WHERE user_id=? AND workspace_id=? AND code=? LIMIT 1");
            $pl->execute([$uid, $wsId, $builtinCode]);
            $pid = (int)$pl->fetchColumn();
            if ($pid <= 0) return;

            $row = $db->prepare("SELECT id, profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
            $row->execute([$wsId, $pid]);
            $tpl = $row->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) return;

            $payload = json_decode((string)$tpl['profiles_json'], true);
            if (!is_array($payload)) $payload = [];

            $qa = is_array($payload['qr_aliases'] ?? null) ? $payload['qr_aliases'] : [];
            $qa['builtin']  = $formats;
            $qa['user']     = is_array($qa['user'] ?? null) ? array_values($qa['user']) : [];
            $qa['override'] = !empty($qa['override']);

            if (($payload['qr_aliases'] ?? null) === $qa) return;   // 已一致：不写库
            $payload['qr_aliases'] = $qa;
            $db->prepare("UPDATE import_template SET profiles_json=? WHERE id=?")
               ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), (int)$tpl['id']]);
        } catch (\Throwable $e) {
            error_log('ScanQrDictService::seedBuiltin: ' . $e->getMessage());
        }
    }

    /** 写入某平台的用户格式（导入 = 直接覆盖：`user = formats`、`override = true`） */
    public function saveUserFormats(int $platformId, array $formats, string $platformCode = ''): void
    {
        if ($platformId <= 0) throw new \InvalidArgumentException('参数错误：平台ID必填');
        $formats = self::validateFormats($formats);

        if ($platformCode === '') $platformCode = $this->platformCodeById($platformId);
        if ($platformCode === '') throw new \InvalidArgumentException('平台不存在或无权操作');

        $row = $this->db->prepare("SELECT id, profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
        $row->execute([$this->workspaceId, $platformId]);
        $tpl = $row->fetch(PDO::FETCH_ASSOC);

        if ($tpl) {
            $payload = json_decode((string)$tpl['profiles_json'], true);
            if (!is_array($payload)) $payload = [];
            $qa = is_array($payload['qr_aliases'] ?? null) ? $payload['qr_aliases'] : [];
            $qa['builtin']  = is_array($qa['builtin'] ?? null) ? array_values($qa['builtin']) : [];
            $qa['user']     = $formats;
            $qa['override'] = true;
            $payload['qr_aliases'] = $qa;
            $this->db->prepare("UPDATE import_template SET profiles_json=?, revision=revision+1, updated_by=?, updated_time=NOW() WHERE id=?")
                ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $this->dataUid, (int)$tpl['id']]);
            return;
        }

        $payload = ['profiles' => [], 'qr_aliases' => ['builtin' => [], 'user' => $formats, 'override' => true]];
        $this->db->prepare("INSERT INTO import_template (workspace_id, platform_id, platform_code, profiles_json, is_builtin, revision, updated_by)
                            VALUES (?, ?, ?, ?, 0, 1, ?)")
            ->execute([$this->workspaceId, $platformId, $platformCode, json_encode($payload, JSON_UNESCAPED_UNICODE), $this->dataUid]);
    }

    /** 重置为内置词典（`user = []`、`override = false`，回到「内置 + 用户」口径） */
    public function resetToBuiltin(int $platformId): bool
    {
        if ($platformId <= 0) throw new \InvalidArgumentException('参数错误：平台ID必填');

        $row = $this->db->prepare("SELECT id, profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
        $row->execute([$this->workspaceId, $platformId]);
        $tpl = $row->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) return false;

        $payload = json_decode((string)$tpl['profiles_json'], true);
        if (!is_array($payload)) $payload = [];
        $qa = is_array($payload['qr_aliases'] ?? null) ? $payload['qr_aliases'] : [];
        $qa['builtin']  = is_array($qa['builtin'] ?? null) ? array_values($qa['builtin']) : [];
        $qa['user']     = [];
        $qa['override'] = false;
        $payload['qr_aliases'] = $qa;

        $this->db->prepare("UPDATE import_template SET profiles_json=?, revision=revision+1, updated_by=?, updated_time=NOW() WHERE id=?")
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $this->dataUid, (int)$tpl['id']]);
        return true;
    }

    /** 读取某平台的扫码参数状态（编辑块用：给出「生效格式」，内置平台显示内置作起点） */
    public function getConfig(int $platformId): array
    {
        if ($platformId <= 0) throw new \InvalidArgumentException('参数错误：平台ID必填');
        $code = $this->platformCodeById($platformId);
        if ($code === '') throw new \InvalidArgumentException('平台不存在或无权操作');

        $builtin = []; $user = []; $override = false;
        try {
            $stmt = $this->db->prepare("SELECT profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
            $stmt->execute([$this->workspaceId, $platformId]);
            $json = $stmt->fetchColumn();
            $p  = json_decode((string)$json, true);
            $qa = is_array($p) ? ($p['qr_aliases'] ?? null) : null;
            if (is_array($qa)) {
                $builtin  = is_array($qa['builtin'] ?? null) ? array_values($qa['builtin']) : [];
                $user     = is_array($qa['user'] ?? null) ? array_values($qa['user']) : [];
                $override = !empty($qa['override']);
            }
        } catch (\Throwable $e) { /* 表未建时按空处理 */ }

        return [
            'platform_id'   => $platformId,
            'code'          => $code,
            'formats'       => $override ? $user : array_merge($builtin, $user),
            'builtin_count' => count($builtin),
            'user_count'    => count($user),
            'override'      => $override,
        ];
    }

    /** 解析并校验「扫码参数配置包」（本地文件内容），非法整体拒绝 */
    public static function parseConfigFile(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') throw new \InvalidArgumentException('配置文件为空');
        $cfg = json_decode($raw, true);
        if (!is_array($cfg)) throw new \InvalidArgumentException('配置文件不是合法 JSON 对象');

        $kind = (string)($cfg['kind'] ?? '');
        if ($kind !== '' && $kind !== self::CONFIG_KIND) {
            throw new \InvalidArgumentException('配置文件 kind 不受支持：' . $kind);
        }
        $schema = (int)($cfg['schema'] ?? self::CONFIG_SCHEMA);
        if ($schema !== self::CONFIG_SCHEMA) {
            throw new \InvalidArgumentException('配置文件 schema 版本不受支持：' . $schema);
        }

        $pl = $cfg['platform'] ?? null;
        if (!is_array($pl)) throw new \InvalidArgumentException('配置文件缺少 platform（平台信息）');
        $code = self::normCode((string)($pl['code'] ?? ''));
        if ($code === '') throw new \InvalidArgumentException('platform.code 非法（仅允许小写字母数字与 _ -，2-32 位）');
        $name = trim((string)($pl['name'] ?? ''));
        $type = strtolower(trim((string)($pl['type'] ?? 'standard')));
        if (!in_array($type, ['standard', 'loose'], true)) $type = 'standard';

        return [
            'platform' => [
                'code'         => $code,
                'name'         => $name,
                'type'         => $type,
                'url_template' => trim((string)($pl['url_template'] ?? '')),
            ],
            'formats'  => self::validateFormats($cfg['formats'] ?? []),
        ];
    }

    /** 校验并归一化「扫码参数格式」数组（canonical 限 FieldRegistry 白名单 / match≥1 / ASCII 键名 / 非法整体拒绝） */
    public static function validateFormats(mixed $formats): array
    {
        if (!is_array($formats) || $formats === []) {
            throw new \InvalidArgumentException('配置缺少 formats（至少 1 套码制）');
        }
        $out  = [];
        $sigs = [];
        foreach (array_values($formats) as $i => $f) {
            if (!is_array($f)) throw new \InvalidArgumentException("formats[$i] 必须是对象");

            $action = strtolower(trim((string)($f['action'] ?? 'in')));
            if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
                throw new \InvalidArgumentException("formats[$i].action 只能是 in 或 out");
            }

            $match = self::normKeyList($f['match'] ?? [], "formats[$i].match");
            if ($match === []) throw new \InvalidArgumentException("formats[$i].match 至少要有 1 个键");

            $sigKeys = $match;
            sort($sigKeys);
            $sig = implode('|', array_unique($sigKeys));
            if (isset($sigs[$sig])) throw new \InvalidArgumentException("formats[$i] 的 match 签名与前面重复（签名冲突）");
            $sigs[$sig] = true;

            $fieldsRaw = $f['fields'] ?? null;
            if (!is_array($fieldsRaw) || $fieldsRaw === []) {
                throw new \InvalidArgumentException("formats[$i].fields 不能为空（至少 1 个系统字段）");
            }
            $fields = [];
            foreach ($fieldsRaw as $canon => $keys) {
                $cf = FieldRegistry::canonicalField((string)$canon);
                if ($cf === '' || !FieldRegistry::isWritableField($cf)) {
                    throw new \InvalidArgumentException("formats[$i].fields 含未知系统字段：" . (string)$canon);
                }
                $list = self::normKeyList($keys, "formats[$i].fields.$cf");
                if ($list === []) throw new \InvalidArgumentException("formats[$i].fields.$cf 至少要有 1 个原始键");
                $fields[$cf] = $list;
            }

            $out[] = [
                'label'  => trim((string)($f['label'] ?? '')),
                'action' => $action,
                'match'  => $match,
                'fields' => $fields,
            ];
        }
        return $out;
    }

    /** 键名列表归一化：仅 ASCII 字母数字与 _ -，长度 1-32，去重保留顺序；空返回 [] */
    private static function normKeyList(mixed $keys, string $where): array
    {
        if (is_string($keys)) $keys = [$keys];
        if (!is_array($keys)) throw new \InvalidArgumentException($where . ' 必须是字符串数组');
        $out = [];
        foreach ($keys as $k) {
            $k = trim((string)$k);
            if ($k === '' || !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $k)) {
                throw new \InvalidArgumentException($where . ' 含非法键名（仅允许 ASCII 字母数字与 _ -，长度 1-32）：' . $k);
            }
            if (!in_array($k, $out, true)) $out[] = $k;
        }
        return $out;
    }

    /** 平台代码归一化（小写 + 白名单）；非法返回 '' */
    private static function normCode(string $code): string
    {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z0-9][a-z0-9_\-]{1,31}$/', $code) ? $code : '';
    }

    /** 按平台 code 找本用户平台 id（找不到返回 0）—— 供导入按文件里的 `platform.code` 匹配 */
    public function findPlatformIdByCode(string $code): int
    {
        return $this->platformIdByCode($code);
    }

    /** 取平台 code（按 dataUid + workspaceId 归属校验；不存在返回 ''） */
    private function platformCodeById(int $platformId): string
    {
        if ($platformId <= 0) return '';
        try {
            $stmt = $this->db->prepare("SELECT code FROM platforms WHERE id=? AND user_id=? AND workspace_id=? LIMIT 1");
            $stmt->execute([$platformId, $this->dataUid, $this->workspaceId]);
            return (string)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 单平台的生效格式（内置 + 用户，或只认用户） */
    public function formatsForPlatform(int $platformId): array
    {
        if ($platformId <= 0) return [];
        try {
            $stmt = $this->db->prepare("SELECT profiles_json FROM import_template WHERE workspace_id=? AND platform_id=? LIMIT 1");
            $stmt->execute([$this->workspaceId, $platformId]);
            $json = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return [];
        }
        return self::effectiveFormats(is_string($json) ? $json : null);
    }

    /** 本用户全部平台的生效格式（带 platform_id / platform_code，供签名判定） */
    public function allPlatformFormats(): array
    {
        $out = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT p.id AS platform_id, p.code AS platform_code, t.profiles_json
                   FROM platforms p
                   LEFT JOIN import_template t ON t.platform_id = p.id AND t.workspace_id = p.workspace_id
                  WHERE p.user_id = ? AND p.workspace_id = ?
                  ORDER BY p.is_default DESC, p.id ASC"
            );
            $stmt->execute([$this->dataUid, $this->workspaceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fmts = self::effectiveFormats(is_string($r['profiles_json'] ?? null) ? $r['profiles_json'] : null);
                if ($fmts === []) continue;
                $out[] = [
                    'platform_id'   => (int)$r['platform_id'],
                    'platform_code' => (string)$r['platform_code'],
                    'formats'       => $fmts,
                ];
            }
        } catch (\Throwable $e) {
            /* 表未建时返回空 */
        }
        return $out;
    }

    /** 词典状态（平台列表徽标用）：platform_id => {builtin:int, user:int, override:bool} */
    public function listStatus(): array
    {
        $out = [];
        try {
            $stmt = $this->db->prepare("SELECT platform_id, profiles_json FROM import_template WHERE workspace_id=?");
            $stmt->execute([$this->workspaceId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p  = json_decode((string)$r['profiles_json'], true);
                $qa = is_array($p) ? ($p['qr_aliases'] ?? null) : null;
                $out[(int)$r['platform_id']] = [
                    'builtin'  => is_array($qa['builtin'] ?? null) ? count($qa['builtin']) : 0,
                    'user'     => is_array($qa['user'] ?? null) ? count($qa['user']) : 0,
                    'override' => !empty($qa['override']),
                ];
            }
        } catch (\Throwable $e) {
            /* 表未建时返回空 */
        }
        return $out;
    }

    /** 按签名跨平台解析原始键值（不知道平台时用） */
    public function resolveBySignature(array $raw): ?array
    {
        if ($raw === []) return null;
        foreach ($this->allPlatformFormats() as $pf) {
            $m = FieldRegistry::mapQrFields($raw, $pf['formats']);
            if ($m['format_index'] >= 0) {
                return [
                    'platform_id'   => $pf['platform_id'],
                    'platform_code' => $pf['platform_code'],
                    'action'        => $m['action'],
                    'label'         => $m['label'],
                    'values'        => $m['values'],
                    'matched_by'    => 'signature',
                ];
            }
        }
        return null;
    }

    /** 已知平台时映射（优先 platform_id；退而按 code 找） */
    public function mapRawByPlatform(array $raw, int $platformId, string $platformCode = ''): array
    {
        if ($platformId <= 0 && $platformCode !== '') {
            $platformId = $this->platformIdByCode($platformCode);
        }
        if ($platformId > 0) {
            $formats = $this->formatsForPlatform($platformId);
            $m = FieldRegistry::mapQrFields($raw, $formats, true);
            if ($m['values'] !== []) {
                return [
                    'platform_id'   => $platformId,
                    'platform_code' => $platformCode,
                    'action'        => $m['action'],
                    'label'         => $m['label'],
                    'values'        => $m['values'],
                    'matched_by'    => 'platform',
                ];
            }
        }
        // 平台未知、或该平台词典映射不出内容 → 退回按签名判定

        return $this->resolveBySignature($raw) ?? self::noMatch();
    }

    private function platformIdByCode(string $code): int
    {
        $code = trim($code);
        if ($code === '') return 0;
        try {
            $stmt = $this->db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=? AND workspace_id=? LIMIT 1");
            $stmt->execute([$code, $this->dataUid, $this->workspaceId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function noMatch(): array
    {
        return ['platform_id' => 0, 'platform_code' => '', 'action' => '', 'label' => '', 'values' => [], 'matched_by' => ''];
    }

    /** 从 `profiles_json` 求出该平台的**生效格式**（内置 + 用户，或只认用户） */
    public static function effectiveFormats(?string $profilesJson): array
    {
        if (!$profilesJson) return [];
        $p  = json_decode($profilesJson, true);
        $qa = is_array($p) ? ($p['qr_aliases'] ?? null) : null;
        if (!is_array($qa)) return [];

        $user    = is_array($qa['user'] ?? null) ? array_values($qa['user']) : [];
        $builtin = is_array($qa['builtin'] ?? null) ? array_values($qa['builtin']) : [];
        return !empty($qa['override']) ? $user : array_merge($builtin, $user);
    }
}
