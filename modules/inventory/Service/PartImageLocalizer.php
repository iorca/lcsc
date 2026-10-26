<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use App\Core\Http\HttpClient;

/**
 * 立创商品图本地化服务
 *
 * 背景：立创图床（assets.lcsc.com）只有 900x900 一个规格，且在部分网络环境
 * （内网 / 离线 / 反代）下不可达，页面会出现破图。这里把图下载到本地
 * uploads/parts/ 目录，parts.image 存相对路径，前端自动走 asset.thumb
 * 接口取 webp 缩略图，彻底摆脱对外部图床的依赖。
 */
final class PartImageLocalizer
{
    /** 本地图片根目录（相对应用根），与 ThumbnailService / AttachmentService 保持一致 */
    public const REL_DIR = 'uploads/parts';

    /** 立创图床主机白名单：只允许从这两个域名取图 */
    private const ALLOWED_HOSTS = ['assets.lcsc.com', 'wmsc.lcsc.com', 'item.szlcsc.com'];

    /** 单图最大字节数（立创 900x900 约 100~200KB，2MB 足够宽松） */
    private const MAX_BYTES = 2 * 1024 * 1024;

    private \PDO $db;
    private int $dataUid;
    private int $wsId;

    public function __construct(\PDO $db, int $dataUid, int $wsId = 0)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId    = $wsId;
    }

    /**
     * 下载单张外链图到本地，返回 uploads/ 下的相对路径
     * （失败返回 null；供补全流程在写库前调用）
     */
    public function downloadOne(string $url, int $partId, string $code): ?string
    {
        if (!$this->isRemote($url)) return null;
        $rel = $this->downloadToLocal($url, $partId, $code);
        if ($rel !== null) $this->ensureThumbs($rel);
        return $rel;
    }

    /**
     * 批量把外链图片下载到本地
     *
     * @param  int[] $partIds  为空则取所有「有外链图」的物料
     * @param  array $opts ['overwrite'=>bool, 'limit'=>int]
     * @return array{checked:int,localized:int,skipped:int,failed:int,details:array}
     */
    public function localize(array $partIds = [], array $opts = []): array
    {
        $overwrite = !empty($opts['overwrite']);
        $limit     = (int)($opts['limit'] ?? 0);

        $stats = ['checked' => 0, 'localized' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

        $rows = $this->pickTargets($partIds, $overwrite, $limit);
        if (empty($rows)) return $stats;

        foreach ($rows as $r) {
            $stats['checked']++;
            $pid   = (int)$r['id'];
            $code  = (string)($r['platform_part_no'] ?? '');
            $image = (string)($r['image'] ?? '');

            if ($image === '') { $stats['skipped']++; continue; }

            // 已经是本地文件 → 只需确保缩略图存在
            if (!$this->isRemote($image)) {
                $rel = $this->normalizeRel($image);
                if ($rel !== '' && is_file($this->absPath($rel))) {
                    $this->ensureThumbs($rel);
                    $stats['skipped']++;
                    continue;
                }
                // 本地路径但文件丢了 → 视作无图
                $stats['failed']++;
                $stats['details'][] = ['id' => $pid, 'code' => $code, 'msg' => '本地图片文件缺失'];
                continue;
            }

            $rel = $this->downloadToLocal($image, $pid, $code);
            if ($rel === null) {
                $stats['failed']++;
                $stats['details'][] = ['id' => $pid, 'code' => $code, 'msg' => '图片下载失败'];
                continue;
            }

            $this->db->prepare("UPDATE parts SET image=? WHERE id=? AND user_id=?")
                ->execute([$rel, $pid, $this->dataUid]);
            $this->ensureThumbs($rel);

            $stats['localized']++;
            $stats['details'][] = ['id' => $pid, 'code' => $code, 'image' => $rel];
        }

        return $stats;
    }

    /**
     * 选目标行
     * @return array<int,array>
     */
    private function pickTargets(array $partIds, bool $overwrite, int $limit): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));

        $sql    = "SELECT id, platform_part_no, image FROM parts WHERE user_id=? AND status<>'deleted'";
        $params = [$this->dataUid];
        if ($this->wsId > 0) {
            $sql .= " AND workspace_id=?";
            $params[] = $this->wsId;
        }

        if (!empty($ids)) {
            $sql .= " AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            $params = array_merge($params, $ids);
        } else {
            // 只取外链图（http 开头）；overwrite 时连已有的外链也一起重来
            $sql .= $overwrite
                ? " AND image LIKE 'http%'"
                : " AND image LIKE 'http%'";
        }

        $sql .= " ORDER BY id ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;

        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * 下载单张图到本地，返回相对路径（失败返回 null）
     */
    private function downloadToLocal(string $url, int $partId, string $code): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        $dir = $this->absDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        // 文件名：partId + 商品编号（去掉路径分隔符与危险字符）
        $safeCode = preg_replace('/[^A-Za-z0-9_]/', '', $code) ?: 'x';
        $base     = $partId . '_' . $safeCode;
        $ext      = strtolower((string)(pathinfo((string)(parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION)));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';

        $dest = $dir . DIRECTORY_SEPARATOR . $base . '.' . $ext;

        $ok = HttpClient::downloadToFile($url, $dest, [
            'timeout'        => 30,
            'max_bytes'      => self::MAX_BYTES,
            'allowed_hosts'  => self::ALLOWED_HOSTS,
            'max_redirects'  => 3,
        ]);
        if (!$ok || !is_file($dest) || filesize($dest) <= 0) {
            @unlink($dest);
            return null;
        }

        return self::REL_DIR . '/' . $base . '.' . $ext;
    }

    /** 生成两种尺寸的 webp 缩略图（失败不阻断，仅降级为原图） */
    private function ensureThumbs(string $relPath): void
    {
        try {
            $thumb = new ThumbnailService();
            $thumb->resolve($relPath, ThumbnailService::SIZE_LIST);
            $thumb->resolve($relPath, ThumbnailService::SIZE_DETAIL);
        } catch (\Throwable $e) {
            error_log('[PartImageLocalizer] 缩略图生成失败：' . $e->getMessage());
        }
    }

    private function isRemote(string $url): bool
    {
        return (bool)preg_match('~^https?://~i', trim($url));
    }

    /** 把可能带反斜杠的相对路径规范成 uploads/... 形式 */
    private function normalizeRel(string $path): string
    {
        $rel = str_replace('\\', '/', trim($path));
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, '..')) return '';
        return $rel;
    }

    private function appRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function absDir(): string
    {
        return $this->appRoot() . '/' . self::REL_DIR;
    }

    private function absPath(string $rel): string
    {
        return $this->appRoot() . '/' . $this->normalizeRel($rel);
    }
}
