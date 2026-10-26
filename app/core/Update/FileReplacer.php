<?php
declare(strict_types=1);

namespace App\Core\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** 逐文件原子替换 + 失败回滚（product_scope §5.2.4 第 5 条） */
final class FileReplacer
{
    /** 回滚备份目录内的元数据文件名（新增文件清单，供还原时删除） */
    private const META_ADDED = '.updater_added.list';

    /** 发布包清单（包内根目录；列出新版应存在的全部文件） */
    private const MANIFEST = 'release-manifest.json';

    /** 旧文件清理只扫这些可替换目录，用户数据目录（uploads / updates / backups 等）永不触碰 */
    private const MANAGED_DIRS = ['app', 'modules', 'ui', 'mobile', 'third_party', 'deploy'];

    /** 根目录可清理的代码文件（其余根文件不动） */
    private const ROOT_CODE_FILES = [
        '.htaccess', 'api.php', 'config.php', 'detail_ajax.php', 'external_api.php', 'index.php',
        'install.php', 'login.php', 'logout.php', 'print.php', 'composer.json', 'version.json',
        'scan_decoder.js', self::MANIFEST,
    ];

    /** 清单必须包含的核心文件；缺一即视为清单不可信，跳过清理（fail-safe） */
    private const MANIFEST_REQUIRED = ['index.php', 'api.php', 'config.php', 'version.json', self::MANIFEST];

    public function __construct(
        private string $rootDir,
        private string $stagingDir,
        private string $rollbackDir
    ) {
    }

    /** 执行替换；任何一步失败都先回滚再抛异常 */
    public function apply(): array
    {
        $replaced = [];
        $added    = [];
        $skipped  = [];

        if (!is_dir($this->rollbackDir)
            && !@mkdir($this->rollbackDir, 0755, true)
            && !is_dir($this->rollbackDir)) {
            throw new UpdateException('无法创建回滚备份目录');
        }

        try {
            foreach ($this->listFiles($this->stagingDir) as $rel) {

                if (ReleaseExclusions::isExcluded($rel)) {
                    $skipped[] = $rel;
                    continue;
                }
                $src = $this->stagingDir . '/' . $rel;
                $dst = $this->rootDir . '/' . $rel;

                if (is_file($dst)) {
                    $bak = $this->rollbackDir . '/' . $rel;
                    $bakDir = dirname($bak);
                    if (!is_dir($bakDir) && !@mkdir($bakDir, 0755, true) && !is_dir($bakDir)) {
                        throw new UpdateException('无法创建回滚备份目录：' . $rel);
                    }

                    if (!@rename($dst, $bak)) {
                        throw new UpdateException('备份旧文件失败（文件可能被占用）：' . $rel);
                    }
                } else {
                    $added[] = $rel;
                }

                $dir = dirname($dst);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new UpdateException('无法创建目标目录：' . $dir);
                }

                $tmp = $dst . '.upload.tmp';
                if (!@copy($src, $tmp)) {
                    @unlink($tmp);
                    throw new UpdateException('写入临时文件失败：' . $rel);
                }
                if (!@rename($tmp, $dst)) {
                    @unlink($tmp);
                    throw new UpdateException('替换文件失败（文件可能被占用）：' . $rel);
                }
                $replaced[] = $rel;
            }
        } catch (Throwable $e) {
            @file_put_contents($this->rollbackDir . '/' . self::META_ADDED, implode("\n", $added));
            $msg = ($e instanceof UpdateException) ? $e->getMessage() : ('替换失败：' . $e->getMessage());
            try {
                $this->rollback($added);
            } catch (Throwable $re) {
                $msg .= '；回滚异常：' . $re->getMessage();
            }
            throw new UpdateException($msg);
        }

        @file_put_contents($this->rollbackDir . '/' . self::META_ADDED, implode("\n", $added));

        $deleted = [];
        $deleteFailed = [];
        try {
            $deleted = $this->cleanupObsolete($deleteFailed);
        } catch (Throwable $e) {
            $deleteFailed[] = '清理异常：' . $e->getMessage();
            error_log('[FileReplacer] 旧文件清理异常：' . $e->getMessage());
        }

        return [
            'replaced'      => $replaced,
            'added'         => $added,
            'skipped'       => $skipped,
            'deleted'       => $deleted,
            'delete_failed' => $deleteFailed,
            'rollback_dir'  => $this->rollbackDir,
        ];
    }

    /** 回滚：删除本次新增的文件 + 从备份目录还原被覆盖的旧文件 */
    public function rollback(array $added): void
    {
        $errors = [];

        foreach ($added as $rel) {
            $dst = $this->rootDir . '/' . $rel;
            if (is_file($dst) && !@unlink($dst)) {
                $errors[] = '删除新增文件失败：' . $rel;
            }
        }

        // ② 还原被覆盖的旧文件
        if (is_dir($this->rollbackDir)) {
            foreach ($this->listFiles($this->rollbackDir) as $rel) {
                if ($rel === self::META_ADDED) continue;
                $bak = $this->rollbackDir . '/' . $rel;
                $dst = $this->rootDir . '/' . $rel;
                $dir = dirname($dst);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $errors[] = '创建目录失败：' . $dir;
                    continue;
                }
                // 目标可能被占用：先试 copy（保留回滚副本），失败再 rename（移回）
                if (!@copy($bak, $dst) && !@rename($bak, $dst)) {
                    $errors[] = '还原失败：' . $rel;
                    continue;
                }
                if (!is_file($dst)) {
                    $errors[] = '还原后文件不存在：' . $rel;
                }
            }
        }

        if (!empty($errors)) {
            throw new UpdateException('回滚未完成（' . count($errors) . ' 项）：' . implode('；', array_slice($errors, 0, 5)));
        }
    }

    /** 读取并校验包内发布清单 */
    private function loadManifest(): ?array
    {
        $path = $this->stagingDir . '/' . self::MANIFEST;
        if (!is_file($path)) {
            error_log('[FileReplacer] 包内缺少 ' . self::MANIFEST . '，跳过旧文件清理');
            return null;
        }
        $json = json_decode((string)@file_get_contents($path), true);
        if (!is_array($json) || !is_array($json['files'] ?? null)) {
            error_log('[FileReplacer] ' . self::MANIFEST . ' 格式无效，跳过旧文件清理');
            return null;
        }
        $manifestVersion = (string)($json['version'] ?? '');
        if ($manifestVersion === '') {
            error_log('[FileReplacer] ' . self::MANIFEST . ' 缺 version，跳过旧文件清理');
            return null;
        }
        $pkgVersion = '';
        $pkgJson = json_decode((string)@file_get_contents($this->stagingDir . '/version.json'), true);
        if (is_array($pkgJson)) $pkgVersion = (string)($pkgJson['version'] ?? '');
        if ($pkgVersion !== '' && !hash_equals($pkgVersion, $manifestVersion)) {
            error_log('[FileReplacer] 清单版本 ' . $manifestVersion . ' 与包内版本 ' . $pkgVersion . ' 不一致，跳过清理');
            return null;
        }

        $set = [];
        foreach ($json['files'] as $rel) {
            if (!is_string($rel) || $rel === '') continue;
            $rel = str_replace('\\', '/', $rel);
            while (str_starts_with($rel, './')) $rel = substr($rel, 2);
            if ($rel === '' || str_starts_with($rel, '/') || preg_match('~^[A-Za-z]:~', $rel) === 1 || str_contains($rel, '://')) {
                error_log('[FileReplacer] 清单含非法路径，跳过清理：' . $rel);
                return null;
            }
            foreach (explode('/', $rel) as $seg) {
                if ($seg === '..') {
                    error_log('[FileReplacer] 清单含目录穿越路径，跳过清理：' . $rel);
                    return null;
                }
            }
            $set[$rel] = true;
        }

        $set[self::MANIFEST] = true;
        foreach (self::MANIFEST_REQUIRED as $must) {
            if (!isset($set[$must])) {
                error_log('[FileReplacer] 清单缺核心文件 ' . $must . '，跳过旧文件清理');
                return null;
            }
        }
        return $set;
    }

    /** 删除「旧版有、新版清单没有」的代码文件（移入回滚目录 `.deleted/`） */
    private function cleanupObsolete(array &$failed): array
    {
        $manifest = $this->loadManifest();
        if ($manifest === null) return [];

        $deleted = [];

        foreach (self::MANAGED_DIRS as $dir) {
            $base = $this->rootDir . '/' . $dir;
            if (!is_dir($base)) continue;
            foreach ($this->listFiles($base) as $sub) {
                $rel = $dir . '/' . $sub;
                if (isset($manifest[$rel])) continue;
                if (ReleaseExclusions::isExcluded($rel)) continue;
                $this->removeObsolete($rel, $deleted, $failed);
            }
        }

        foreach (@scandir($this->rootDir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!is_file($this->rootDir . '/' . $name)) continue;
            if (!in_array($name, self::ROOT_CODE_FILES, true)) continue;
            if (isset($manifest[$name])) continue;
            if (ReleaseExclusions::isExcluded($name)) continue;
            $this->removeObsolete($name, $deleted, $failed);
        }

        foreach (self::MANAGED_DIRS as $dir) {
            $this->pruneEmptyDirs($this->rootDir . '/' . $dir, true);
        }
        return $deleted;
    }

    /** 把单个旧文件移入回滚目录；失败只记录（不影响已完成的升级） */
    private function removeObsolete(string $rel, array &$deleted, array &$failed): void
    {
        $src = $this->rootDir . '/' . $rel;
        if (!is_file($src)) return;
        $bak = $this->rollbackDir . '/.deleted/' . $rel;
        $dir = dirname($bak);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $failed[] = $rel . '（无法创建备份目录）';
            error_log('[FileReplacer] 旧文件清理失败（无法创建备份目录）：' . $rel);
            return;
        }
        if (!@rename($src, $bak)) {
            $failed[] = $rel . '（移动失败，可能被占用）';
            error_log('[FileReplacer] 旧文件清理失败（移动失败）：' . $rel);
            return;
        }
        $deleted[] = $rel;
    }

    /** 递归删除空目录（保留给定的根目录自身） */
    private function pruneEmptyDirs(string $dir, bool $keepRoot): void
    {
        if (!is_dir($dir)) return;
        foreach (@scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $this->pruneEmptyDirs($path, false);
        }
        if (!$keepRoot) {
            $left = @scandir($dir) ?: [];
            if (count($left) <= 2) @rmdir($dir);
        }
    }

    /** 递归列出目录下所有**普通文件**的相对路径（统一 `/`，已排序） */
    private function listFiles(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            if (str_ends_with($rel, '.upload.tmp')) continue;   // 替换残留，不参与
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }
}
