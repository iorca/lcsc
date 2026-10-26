<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use App\Core\Http\HttpClient;
use PDO;
use Throwable;

/**
 * 规格书（数据手册）本地化服务
 *
 * 背景：
 *   1. 立创商品详情接口 result.pdfUrl 直接给出规格书 PDF 直链，
 *      可自动下载，不必手工从浏览器另存。
 *   2. 主人要求「规格书保存路径可在设置里配置」——因为规格书体积大
 *      （单份常 1~20MB），放应用目录里既撑大部署包、又不方便备份。
 *   3. 主人要求「按立创编号自动绑定」——立创下载下来的文件名里含 C 编号
 *      （如 C9864），用它做键就能把 PDF 挂到对应物料上。
 *
 * 设计：
 *   - 保存目录取设置项 `datasheet_dir`（默认 uploads/datasheets），
 *     可填相对路径（相对应用根）或绝对路径（如 D:\datasheets）。
 *   - 落盘文件名固定为 `<立创编号>_<型号>.pdf`，保证「一个编号一个文件」，
 *     重复下载覆盖同名文件即可，不会堆垃圾。
 *   - 绑定关系写 attachments 表（filetype='pdf'）+ parts.datasheet 列，
 *     与手工上传的附件走同一套展示逻辑。
 */
final class PartDatasheetLocalizer
{
    /** 设置项键：规格书保存目录 */
    public const SETTING_DIR = 'datasheet_dir';

    /** 默认保存目录（相对应用根） */
    public const DEFAULT_DIR = 'uploads/datasheets';

    /** 立创规格书 CDN 主机白名单：只允许从这两个域名下载 */
    private const ALLOWED_HOSTS = ['datasheet.lcsc.com', 'wmsc.lcsc.com'];

    /** 单份规格书最大字节数（20MB：手册常带大量矢量图，与上传上限一致） */
    private const MAX_BYTES = 20 * 1024 * 1024;

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
     * 取规格书保存目录的绝对路径（不存在则尝试创建）
     */
    public static function resolveDir(): string
    {
        $raw = trim((string)\getSetting(self::SETTING_DIR, ''));
        if ($raw === '') $raw = self::DEFAULT_DIR;

        // 绝对路径（Windows 盘符 / UNC / POSIX 根）→ 直接用
        $isAbs = (bool)preg_match('~^(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)~', $raw);
        $dir   = $isAbs ? $raw : self::appRoot() . '/' . ltrim(str_replace('\\', '/', $raw), '/');

        return rtrim($dir, '/\\');
    }

    /** 当前配置的保存目录（用于界面回显） */
    public static function configuredDir(): string
    {
        $raw = trim((string)\getSetting(self::SETTING_DIR, ''));
        return $raw === '' ? self::DEFAULT_DIR : $raw;
    }

    /**
     * 批量下载规格书到本地并绑定
     *
     * @param  int[] $partIds 为空则取「立创平台 + 还没本地规格书」的物料
     * @param  array $opts ['overwrite'=>bool,'limit'=>int]
     * @return array{checked:int,downloaded:int,skipped:int,failed:int,details:array}
     */
    public function download(array $partIds = [], array $opts = []): array
    {
        $overwrite = !empty($opts['overwrite']);
        $limit     = (int)($opts['limit'] ?? 0);

        $stats = ['checked' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        $rows = $this->pickTargets($partIds, $overwrite, $limit);
        if (empty($rows)) return $stats;

        $dir = self::resolveDir();
        if (!$this->ensureDir($dir)) {
            $stats['failed'] = count($rows);
            $stats['details'][] = ['msg' => '规格书目录不可写：' . $dir];
            return $stats;
        }

        foreach ($rows as $r) {
            $stats['checked']++;
            $pid  = (int)$r['id'];
            $code = LcscEnrichService::normalizeCode((string)($r['platform_part_no'] ?? ''));
            $model = (string)($r['model'] ?? '');

            if ($code === '') { $stats['skipped']++; continue; }

            // 已有本地规格书且不覆盖 → 跳过
            if (!$overwrite && trim((string)($r['datasheet'] ?? '')) !== ''
                && !$this->isRemote((string)$r['datasheet'])) {
                $stats['skipped']++;
                continue;
            }

            $detail = (new LcscEnrichService($this->db, $this->dataUid, $this->wsId))->fetchDetail($code);
            $pdfUrl = (string)($detail['pdf_url'] ?? '');
            if ($pdfUrl === '') {
                $stats['failed']++;
                $stats['details'][] = ['id' => $pid, 'code' => $code, 'msg' => '立创未提供规格书'];
                continue;
            }

            $ok = $this->downloadOne($pdfUrl, $dir, $code, $model, $pid);
            if (!$ok) {
                $stats['failed']++;
                $stats['details'][] = ['id' => $pid, 'code' => $code, 'msg' => '规格书下载失败'];
                continue;
            }

            $stats['downloaded']++;
            $stats['details'][] = ['id' => $pid, 'code' => $code, 'file' => $ok['filename']];
        }

        return $stats;
    }

    /**
     * 下载单个规格书到本地并绑定到物料
     *
     * @return array|null ['filename'=>string,'path'=>string,'size'=>int] 失败返回 null
     */
    public function downloadOne(string $url, string $dir, string $code, string $model, int $partId): ?array
    {
        if (!$this->isRemote($url)) return null;

        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || !in_array($host, self::ALLOWED_HOSTS, true)) return null;

        if (!$this->ensureDir($dir)) return null;

        $safeCode  = preg_replace('/[^A-Za-z0-9_]/', '', $code) ?: ('C' . $partId);
        $safeModel = preg_replace('/[^A-Za-z0-9._-]/', '_', $model);
        $safeModel = trim((string)$safeModel, '_');
        if ($safeModel === '') $safeModel = 'datasheet';

        // 文件名：<编号>_<型号>.pdf —— 编号在前，保证按编号排序/检索时连续
        $filename = $safeCode . '_' . substr($safeModel, 0, 60) . '.pdf';
        $dest     = $dir . DIRECTORY_SEPARATOR . $filename;

        $ok = HttpClient::downloadToFile($url, $dest, [
            'timeout'       => 120,
            'max_bytes'     => self::MAX_BYTES,
            'allowed_hosts' => self::ALLOWED_HOSTS,
            'max_redirects' => 5,
        ]);
        if (!$ok || !is_file($dest) || filesize($dest) <= 0) {
            @unlink($dest);
            return null;
        }

        $size = (int)filesize($dest);
        $this->bindToPart($partId, $filename, $dest, $size);

        return ['filename' => $filename, 'path' => $dest, 'size' => $size];
    }

    /**
     * 把一个已存在的 PDF 绑定到物料（写 attachments + parts.datasheet）
     *
     * @param string $filename 落盘文件名（显示用）
     * @param string $absPath  文件绝对路径
     */
    public function bindToPart(int $partId, string $filename, string $absPath, int $size): void
    {
        if ($partId <= 0 || !is_file($absPath)) return;

        $relPath = self::relPathOf($absPath);

        // 同物料同文件名的旧附件行先清掉（避免重复堆行）
        $old = $this->db->prepare(
            "SELECT id, filepath FROM attachments WHERE user_id=? AND part_id=? AND filetype='pdf'"
        );
        $old->execute([$this->dataUid, $partId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['filepath'] === $relPath) {
                $this->db->prepare("DELETE FROM attachments WHERE id=?")->execute([(int)$row['id']]);
            }
        }

        $this->db->prepare(
            "INSERT INTO attachments (user_id, part_id, target_type, target_id, filename, filepath, filetype, filesize)
             VALUES (?,?,'part',?,?,?,'pdf',?)"
        )->execute([$this->dataUid, $partId, $partId, $filename, $relPath, $size]);

        // parts.datasheet 只在为空时写（不覆盖主人手工填的站外链接）
        $this->db->prepare(
            "UPDATE parts SET datasheet=? WHERE id=? AND user_id=? AND (datasheet IS NULL OR datasheet='')"
        )->execute([$relPath, $partId, $this->dataUid]);
    }

    /**
     * 把绝对路径转成「相对应用根 / 绝对路径」的展示用路径
     */
    public static function relPathOf(string $absPath): string
    {
        $abs = str_replace('\\', '/', $absPath);
        $root = str_replace('\\', '/', self::appRoot());
        if (str_starts_with($abs, $root . '/')) {
            return substr($abs, strlen($root) + 1);
        }
        return $abs;
    }

    public static function appRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) return is_writable($dir);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
        return is_writable($dir);
    }

    private function isRemote(string $url): bool
    {
        return (bool)preg_match('~^https?://~i', trim($url));
    }

    /**
     * 选目标行：立创平台 + 有 C 编号的物料
     */
    private function pickTargets(array $partIds, bool $overwrite, int $limit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));

        $sql    = "SELECT p.id, p.platform_part_no, p.model, p.datasheet
                   FROM parts p
                   LEFT JOIN platforms pl ON pl.id = p.platform_id
                   WHERE p.user_id=? AND p.status<>'deleted' AND pl.code='lcsc'";
        $params = [$this->dataUid];
        if ($this->wsId > 0) {
            $sql .= " AND p.workspace_id=?";
            $params[] = $this->wsId;
        }

        if (!empty($ids)) {
            $sql .= " AND p.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            $params = array_merge($params, $ids);
        }

        $sql .= " ORDER BY p.id ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
