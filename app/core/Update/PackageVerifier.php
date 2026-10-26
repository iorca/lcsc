<?php
declare(strict_types=1);

namespace App\Core\Update;

use ZipArchive;

/** 发布包校验与解压（product_scope §5.2.4 第 3 / 4 / 6 / 7 条） */
final class PackageVerifier
{
    /** 解压后总体积上限（防 zip 炸弹：压缩比可极高，必须按**声明**体积先卡住） */
    private const MAX_UNCOMPRESSED_BYTES = 1024 * 1024 * 1024;   // 1 GB

    public function __construct(private string $stagingDir)
    {
    }

    /** 校验并解压 */
    public function verifyAndExtract(string $zipPath, string $expectedSha256, string $expectedVersion): array
    {
        // ① SHA-256：不符直接中止（第 3 条）
        $actual = @hash_file('sha256', $zipPath);
        if ($actual === false) {
            throw new UpdateException('无法读取下载的发布包');
        }
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 === '' || !hash_equals($expectedSha256, strtolower((string)$actual))) {
            throw new UpdateException('发布包 SHA-256 校验失败，已中止（包可能被篡改或下载不完整）');
        }

        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('服务器未启用 ZipArchive 扩展，无法解压发布包');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('发布包不是合法的 zip 文件');
        }

        try {
            $files   = [];
            $skipped = [];
            $total   = 0;

            // 先按**声明体积**卡总量（zip 炸弹防线）
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) continue;
                $total += (int)($stat['size'] ?? 0);
            }
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new UpdateException('发布包解压后体积异常（超过上限），已中止');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) continue;
                $rel = $this->safeRelPath((string)$stat['name']);   // 非法 → 抛
                if ($rel === null) continue;                        // 目录条目

                // ⑥ 不覆盖用户数据与密钥：命中发布排除清单的条目**直接跳过**（第 6 条）
                if (ReleaseExclusions::isExcluded($rel)) {
                    $skipped[] = $rel;
                    continue;
                }

                $dest = $this->stagingDir . '/' . $rel;
                $dir  = dirname($dest);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new UpdateException('无法创建解压目录：' . $rel);
                }

                // 逐条目用流写盘（不用 extractTo —— 它不做任何过滤）
                $in = $zip->getStream((string)$stat['name']);
                if ($in === false) {
                    throw new UpdateException('读取包内条目失败：' . $rel);
                }
                $out = @fopen($dest, 'wb');
                if ($out === false) {
                    fclose($in);
                    throw new UpdateException('写入解压文件失败：' . $rel);
                }
                stream_copy_to_stream($in, $out);
                fclose($out);
                fclose($in);
                $files[] = $rel;
            }

            // ④ 包内 version.json 必须与请求版本一致（禁降级 / 版本一致性）
            $pkgVersionJson = $this->stagingDir . '/version.json';
            if (!is_file($pkgVersionJson)) {
                throw new UpdateException('发布包内缺少 version.json，无法核对版本一致性');
            }
            $pkg = json_decode((string)file_get_contents($pkgVersionJson), true);
            if (!is_array($pkg) || empty($pkg['version'])) {
                throw new UpdateException('发布包内 version.json 格式无效');
            }
            $pkgVersion = (string)$pkg['version'];
            if (!hash_equals((string)$expectedVersion, $pkgVersion)) {
                throw new UpdateException("包内版本 {$pkgVersion} 与请求版本 {$expectedVersion} 不一致，已中止");
            }

            return [
                'package_version'          => $pkgVersion,
                'package_database_version' => (string)($pkg['database_version'] ?? ''),
                'files'                    => $files,
                'skipped'                  => $skipped,
                'migration_steps'          => $this->listMigrationSteps(),
                'total_bytes'              => $total,
            ];
        } finally {
            $zip->close();
        }
    }

    /** 归一化并校验包内条目相对路径；目录条目返回 null；含穿越 / 绝对路径 / 盘符即抛 */
    private function safeRelPath(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '') return null;
        if (str_ends_with($name, '/')) return null;              // 目录
        while (str_starts_with($name, './')) $name = substr($name, 2);
        if ($name === '') return null;

        if (str_starts_with($name, '/')) {
            throw new UpdateException('发布包含绝对路径条目，已中止：' . $name);
        }
        if (preg_match('~^[A-Za-z]:~', $name) === 1 || str_contains($name, '://')) {
            throw new UpdateException('发布包含盘符 / 协议条目，已中止：' . $name);
        }
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..') {
                throw new UpdateException('发布包含目录穿越条目（zip-slip），已中止：' . $name);
            }
        }
        return $name;
    }

    /** 列出包内 `app/core/Migration/Versions/V*.php`（用于判断升级后是否需要走数据库迁移页） */
    private function listMigrationSteps(): array
    {
        $dir = $this->stagingDir . '/app/core/Migration/Versions';
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (glob($dir . '/V*.php') ?: [] as $f) {
            $out[] = basename($f);
        }
        sort($out);
        return $out;
    }
}
