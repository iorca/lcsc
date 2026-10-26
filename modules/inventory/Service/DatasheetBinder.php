<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use PDO;

/**
 * 规格书自动绑定服务（按文件名里的立创编号）
 *
 * 主人诉求：「从立创下载下来的规格书文件名都包含立创编号（C 数字），
 * 最好做成自动绑定。」
 *
 * 做法：
 *   扫描规格书目录（或主人指定的任意目录）里的 PDF，
 *   从文件名中提取 C 编号（大小写不敏感，支持 C9864 / c9864 / 纯数字），
 *   再用这个编号去 parts.platform_part_no 里找对应物料，
 *   找到就把 PDF 绑定到该物料（写 attachments + parts.datasheet）。
 *
 * 边界：
 *   - 只认 PDF（.pdf），其它扩展名跳过。
 *   - 文件名里没有 C 编号的跳过，记入「未识别」清单让主人自己看。
 *   - 编号在库里找不到的跳过，记入「未匹配」清单。
 *   - 已绑定过同一文件的跳过（幂等，重复扫描不会产生重复行）。
 */
final class DatasheetBinder
{
    /** 文件名里提取立创编号：优先 C+数字，其次独立数字串（避免把型号里的数字误判） */
    private const CODE_PATTERNS = [
        '/(?<![A-Za-z0-9])C(\d{3,})(?![0-9])/i',   // C9864 / c9864
        '/(?<![A-Za-z0-9])(\d{5,})(?![0-9])/',       // 纯数字（立创编号至少 5 位）
    ];

    private PDO $db;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $dataUid, int $wsId = 0)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId    = $wsId;
    }

    /**
     * 扫描目录并自动绑定
     *
     * @param  string $dir 扫描目录（空 = 用设置里的规格书目录）
     * @param  array  $opts ['overwrite'=>bool,'limit'=>int]
     * @return array{scanned:int,bound:int,skipped:int,unmatched:int,unrecognized:int,details:array}
     */
    public function scan(string $dir = '', array $opts = []): array
    {
        $overwrite = !empty($opts['overwrite']);
        $limit     = (int)($opts['limit'] ?? 0);

        $stats = [
            'scanned' => 0, 'bound' => 0, 'skipped' => 0,
            'unmatched' => 0, 'unrecognized' => 0, 'details' => [],
        ];

        $dir = trim($dir) !== '' ? $dir : PartDatasheetLocalizer::resolveDir();
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        if (!is_dir($dir)) {
            $stats['details'][] = ['msg' => '目录不存在：' . $dir];
            return $stats;
        }

        $files = glob($dir . '/*.pdf') ?: [];
        // Windows 下 glob 不区分大小写，但 Linux 部署要补一轮
        foreach (glob($dir . '/*.PDF') ?: [] as $f) {
            if (!in_array($f, $files, true)) $files[] = $f;
        }
        sort($files);

        if (empty($files)) {
            $stats['details'][] = ['msg' => '目录里没有 PDF：' . $dir];
            return $stats;
        }

        foreach ($files as $file) {
            if ($limit > 0 && $stats['scanned'] >= $limit) break;
            $stats['scanned']++;

            $name = basename($file);
            $code = self::extractCode($name);
            if ($code === '') {
                $stats['unrecognized']++;
                $stats['details'][] = ['file' => $name, 'msg' => '文件名里没有立创编号'];
                continue;
            }

            $part = $this->findPartByCode($code);
            if ($part === null) {
                $stats['unmatched']++;
                $stats['details'][] = ['file' => $name, 'code' => $code, 'msg' => '库中没有该编号的物料'];
                continue;
            }

            // 已绑定过同一文件 → 幂等跳过
            if (!$overwrite && $this->isBound((int)$part['id'], $file)) {
                $stats['skipped']++;
                continue;
            }

            $localizer = new PartDatasheetLocalizer($this->db, $this->dataUid, $this->wsId);
            $localizer->bindToPart((int)$part['id'], $name, $file, (int)filesize($file));

            $stats['bound']++;
            $stats['details'][] = [
                'file' => $name, 'code' => $code,
                'id'   => (int)$part['id'],
                'msg'  => '已绑定到 #' . (int)$part['id'] . ' ' . (string)($part['model'] ?? ''),
            ];
        }

        return $stats;
    }

    /**
     * 从文件名提取立创编号（返回 C+数字 形式）
     */
    public static function extractCode(string $filename): string
    {
        $base = basename($filename);
        foreach (self::CODE_PATTERNS as $re) {
            if (preg_match($re, $base, $m)) {
                return 'C' . $m[1];
            }
        }
        return '';
    }

    /**
     * 按立创编号找物料
     * platform_part_no 可能是 C9864 / 9864 / c9864，大小写与前缀都统一后再比
     */
    private function findPartByCode(string $code): ?array
    {
        $want = strtoupper($code);
        $bare = substr($want, 1);   // 去掉 C 前缀，兼容库里存纯数字的旧数据

        $sql    = "SELECT id, model, platform_part_no FROM parts
                   WHERE user_id=? AND status<>'deleted'
                     AND UPPER(platform_part_no) IN (?, ?) ";
        $params = [$this->dataUid, $want, $bare];

        if ($this->wsId > 0) {
            $sql .= " AND workspace_id=? ";
            $params[] = $this->wsId;
        }
        $sql .= " ORDER BY id ASC LIMIT 1";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 该物料是否已经绑定过这个文件 */
    private function isBound(int $partId, string $absFile): bool
    {
        $rel = PartDatasheetLocalizer::relPathOf($absFile);
        $st  = $this->db->prepare(
            "SELECT COUNT(*) FROM attachments WHERE user_id=? AND part_id=? AND filepath=?"
        );
        $st->execute([$this->dataUid, $partId, $rel]);
        return ((int)$st->fetchColumn()) > 0;
    }
}
