<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

/** 物料图片缩略图服务（GD 生成 + 磁盘缓存 + 不可变缓存头） */
final class ThumbnailService
{
    /** 列表缩略图边长（正方形，CSS 侧 object-fit:cover 裁切） */
    public const SIZE_LIST = 160;

    /** 详情页主图边长（留足 2x 屏的放大余量） */
    public const SIZE_DETAIL = 800;

    /** 允许生成缩略图的源扩展名（与上传白名单同源，防 SVG / PDF 被当图片解码） */
    private const SOURCE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private string $uploadDir;
    private string $thumbDir;

    public function __construct()
    {
        $this->uploadDir = \dirname(__DIR__, 3) . '/uploads';
        $this->thumbDir  = $this->uploadDir . '/thumbs';
    }

    /** GD 是否可用（healthcheck 与降级说明用） */
    public static function gdAvailable(): bool
    {
        return \function_exists('imagecreatetruecolor');
    }

    /** 取缩略图：命中缓存直接返回路径，未命中则生成后返回 */
    public function resolve(string $sourceRelPath, int $size): ?string
    {

        $abs = $this->safeLocalPath($sourceRelPath);
        if ($abs === null) return null;

        $thumb = $this->thumbPathFor($abs, $size);
        if (\is_file($thumb)) return $thumb;

        if (!self::gdAvailable()) return null;

        $this->ensureThumbDir();
        if ($this->generate($abs, $thumb, $size)) {
            return \is_file($thumb) ? $thumb : null;
        }
        return null; // 生成失败同样降级，不抛给用户
    }

    /** 删除某源文件对应的所有尺寸缩略图 */
    public function purge(string $sourceRelPath): void
    {
        $base = self::thumbBaseName($sourceRelPath);
        if ($base === null) return;

        foreach ([\glob($this->thumbDir . '/' . $base . '_*.webp') ?: [],
                  \glob($this->thumbDir . '/' . $base . '_*.jpg') ?: []] as $group) {
            foreach ($group as $f) {
                @\unlink($f);
            }
        }
    }

    /** 取可安全用于 glob 的源文件基本名（不含分隔符与 ..） */
    private static function thumbBaseName(string $sourceRelPath): ?string
    {
        $rel = \str_replace('\\', '/', \trim($sourceRelPath));
        if ($rel === '' || \str_contains($rel, "\0")) return null;

        $base = \basename($rel);
        if (!\preg_match('~^[A-Za-z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$~i', $base)) return null;

        return \pathinfo($base, PATHINFO_FILENAME);
    }

    /** 校验并把相对路径解析为 uploads/ 内的绝对路径 */
    private function safeLocalPath(string $rel): ?string
    {
        $rel = \str_replace('\\', '/', \trim($rel));
        if ($rel === '' || \str_contains($rel, "\0")) return null;
        // 只接受 uploads/ 下的相对路径；外链由前端直连，不进本服务
        if (!\str_starts_with($rel, 'uploads/')) return null;
        if (\str_contains($rel, '..')) return null;

        $ext = \strtolower(\pathinfo($rel, PATHINFO_EXTENSION));
        if (!\in_array($ext, self::SOURCE_EXT, true)) return null;

        $abs  = \realpath($this->uploadDir . '/' . \substr($rel, \strlen('uploads/')));
        $root = \realpath($this->uploadDir);
        if ($abs === false || $root === false) return null;
        if (!\str_starts_with($abs, $root . \DIRECTORY_SEPARATOR)) return null;
        if (!\is_file($abs)) return null;

        return $abs;
    }

    /** 缩略图目标路径（文件名规则即缓存键，见类注释「为什么 immutable 安全」） */
    private function thumbPathFor(string $sourceAbs, int $size): string
    {
        return $this->thumbDir . '/' . \pathinfo($sourceAbs, PATHINFO_FILENAME) . '_' . $size . '.webp';
    }

    /** 缓存目录可能被手工清掉过，用时再建（0775 允许同组用户写，容器内 group 常非 root） */
    private function ensureThumbDir(): void
    {
        if (\is_dir($this->thumbDir)) return;
        @\mkdir($this->thumbDir, 0775, true);
    }

    /** 生成缩略图 */
    private function generate(string $srcAbs, string $dstAbs, int $size): bool
    {
        $info = @\getimagesize($srcAbs);
        if ($info === false) return false;

        [$w, $h] = $info;
        if ($w < 1 || $h < 1) return false;

        $src = $this->loadImage($srcAbs, $info[2]);
        if ($src === null) return false;

        // 不放大：小于目标尺寸的图保持原样，插值放大只会让模糊的图更模糊
        $scale = \min($size / $w, $size / $h, 1.0);
        $nw    = \max(1, (int)\round($w * $scale));
        $nh    = \max(1, (int)\round($h * $scale));

        $canvas = \imagecreatetruecolor($size, $size);
        if ($canvas === false) {
            \imagedestroy($src);
            return false;
        }

        \imagealphablending($canvas, false);
        \imagesavealpha($canvas, true);
        \imagefill($canvas, 0, 0, \imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $dstX = (int)\round(($size - $nw) / 2);
        $dstY = (int)\round(($size - $nh) / 2);
        \imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $nw, $nh, $w, $h);

        $ok = \function_exists('imagewebp')
            ? @\imagewebp($canvas, $dstAbs, 82)
            : @\imagejpeg($canvas, $dstAbs, 82);

        \imagedestroy($canvas);
        \imagedestroy($src);
        return (bool)$ok;
    }

    /** 按图片类型解码（类型由 getimagesize 判定，不靠扩展名猜） */
    private function loadImage(string $abs, int $type)
    {
        return match ($type) {
            \IMAGETYPE_JPEG => @\imagecreatefromjpeg($abs),
            \IMAGETYPE_PNG  => @\imagecreatefrompng($abs),
            \IMAGETYPE_GIF  => @\imagecreatefromgif($abs),
            \IMAGETYPE_WEBP => \function_exists('imagecreatefromwebp') ? @\imagecreatefromwebp($abs) : null,
            default         => null,
        };
    }
}
