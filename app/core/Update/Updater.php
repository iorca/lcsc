<?php
declare(strict_types=1);

namespace App\Core\Update;

use App\Api\BackupRoutes;
use App\Core\Http\HttpClient;
use App\Core\Migration\MigrationEngine;
use PDO;
use Throwable;

/** 在线升级编排（P2） */
final class Updater
{
    public function __construct(
        private PDO $db,
        private string $rootDir
    ) {
    }

    /** 工作区目录（运行时创建；已 gitignore 且 web 拒绝） */
    public function workDir(): string
    {
        return rtrim(str_replace('\\', '/', $this->rootDir), '/') . '/updates';
    }

    /** 取远端发布信息（**按仓库顺序**用 HttpClient 取 version.json） */
    public function remoteRelease(): array
    {
        $errors = [];
        $candidates = [];
        foreach (UpdateSource::repos() as $key => $repo) {

            if (UpdateSource::isLocalDev()) {
                $data = $this->fetchLocalJson($repo['version'] . '?t=' . time());
            } else {
                $data = HttpClient::getJson($repo['version'] . '?t=' . time(), ['Cache-Control: no-cache']);
            }
            if (!is_array($data) || empty($data['version'])) {
                $errors[] = $key . '(无响应或格式无效)';
                continue;
            }
            $pkg  = is_array($data['package'] ?? null) ? $data['package'] : [];
            $file = (string)($pkg['file'] ?? '');
            $sha  = (string)($pkg['sha256'] ?? '');
            $candidates[] = [
                'repo'             => $key,
                'version'          => (string)$data['version'],
                'file'             => $file,
                'sha256'           => $sha,
                'package_ready'    => ($file !== '' && $sha !== ''),
                'database_version' => (string)($data['database_version'] ?? ''),
                'min_php'          => (string)($data['min_php'] ?? ''),
                'release_url'      => (string)($repo['release'] ?? ''),
            ];
        }
        if ($candidates === []) {
            throw new UpdateException('无法从远端获取发布信息：' . implode('；', $errors));
        }

        $best = null;
        foreach ($candidates as $c) {
            if ($best === null) {
                $best = $c;
                continue;
            }
            $cmp = version_compare($c['version'], $best['version']);
            if ($cmp > 0 || ($cmp === 0 && $c['package_ready'] && !$best['package_ready'])) {
                $best = $c;
            }
        }
        if (!$best['package_ready']) {
            error_log('[Updater] 远端 ' . $best['version'] . ' 的 version.json 缺 package.file / package.sha256（仅可做版本检测）');
        }
        return $best;
    }

    /** 预检：只读，不下载 —— 给「人工确认」提供判断依据 */
    public function preflight(): array
    {
        $remote = $this->remoteRelease();
        $cur    = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
        $hasUpdate = version_compare($remote['version'], $cur, '>');

        $workDir  = $this->workDir();
        $writable = is_dir($workDir) ? is_writable($workDir) : is_writable($this->rootDir);

        return [
            'current'          => $cur,
            'remote'           => $remote,
            'update_available' => $hasUpdate,
            'package_ready'    => (bool)$remote['package_ready'],
            'can_apply'        => $hasUpdate && (bool)$remote['package_ready'],
            'writable'         => $writable,
            'env'              => [
                'php_version'     => PHP_VERSION,
                'min_php_ok'      => $remote['min_php'] === '' || version_compare(PHP_VERSION, $remote['min_php'], '>='),
                'zip_available'   => class_exists(\ZipArchive::class),
                'curl_available'  => function_exists('curl_init'),
                'outbound_ready'  => UpdateSource::isLocalDev() ? true : HttpClient::isEnvironmentReady(),
                'local_test_source' => UpdateSource::isLocalDev(),
            ],
        ];
    }

    /** 执行升级：下载 → 校验/解压 → 库备份 → 加锁 → 替换 → 落点 */
    public function apply(): array
    {
        $ts     = date('Ymd_His');
        $remote = $this->remoteRelease();
        $cur    = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';

        // ④ 版本一致性 + 禁降级
        if (!version_compare($remote['version'], $cur, '>')) {
            throw new UpdateException("远端版本 {$remote['version']} 不高于当前 {$cur}，已中止（禁降级）");
        }
        // 远端描述可以不带包信息（同版本检测用）；真要升级时必须完整
        if (empty($remote['package_ready']) || $remote['file'] === '' || $remote['sha256'] === '') {
            throw new UpdateException('远端 version.json 缺 package.file / package.sha256，无法下载新版发布包');
        }
        if ($remote['min_php'] !== '' && version_compare(PHP_VERSION, $remote['min_php'], '<')) {
            throw new UpdateException("本机 PHP " . PHP_VERSION . " 低于新版要求的 {$remote['min_php']}，已中止");
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateException('服务器未启用 ZipArchive 扩展，无法解压发布包');
        }

        $workDir = $this->workDir();
        if (!is_dir($workDir) && !@mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new UpdateException('无法创建升级工作目录 updates/（请检查目录权限）');
        }

        $zipPath  = $workDir . '/dl_' . $ts . '.zip';
        $staging  = $workDir . '/staging_' . $ts;

        $assetUrl = UpdateSource::assetUrl($remote['repo'], $remote['version'], $remote['file']);
        if (UpdateSource::isLocalDev()) {
            if (!$this->downloadLocal($assetUrl, $zipPath)) {
                throw new UpdateException('本地测试源发布包下载失败：' . $assetUrl);
            }
        } else {
            $ok = HttpClient::downloadToFile($assetUrl, $zipPath, [
                'timeout'       => 300,
                'max_bytes'     => UpdateSource::MAX_PACKAGE_BYTES,
                'allowed_hosts' => UpdateSource::allowedHosts(),
                'max_redirects' => UpdateSource::MAX_REDIRECTS,
            ]);
            if (!$ok) {
                throw new UpdateException('发布包下载失败（网络不可达 / 证书缺失 / 主机不在白名单，细节见 error_log）');
            }
        }

        try {

            $info = (new PackageVerifier($staging))->verifyAndExtract($zipPath, $remote['sha256'], $remote['version']);

            // ⑤ 升级前先做一次全量库备份（失败即中止 —— 无兜底不动代码）
            $backup = BackupRoutes::createBackupFile($this->db, 0, 'upgrade_backup');
            if (($backup['size'] ?? 0) <= 0) {
                throw new UpdateException('升级前库备份失败，已中止');
            }

            // 维护锁：抢不到说明已有升级在跑
            if (!UpdateLock::acquire($this->rootDir, 'online-upgrade ' . $remote['version'])) {
                throw new UpdateException('已有另一个升级正在进行，请稍后重试');
            }

            try {
                $result = (new FileReplacer($this->rootDir, $staging, $workDir . '/rollback_' . $ts))->apply();
            } finally {
                UpdateLock::release($this->rootDir);   // 无论成败都解锁
            }
        } finally {
            @unlink($zipPath);                                    // 包已解压，删掉
            if (is_dir($staging)) $this->removeDir($staging);      // staging 用完即删
        }

        $migrationSteps = $info['migration_steps'] ?? [];
        $redirect = $migrationSteps !== [] ? 'index.php?v=migration' : 'index.php';

        $this->cleanupWorkDir((string)($result['rollback_dir'] ?? ''));

        return [
            'version'         => $remote['version'],
            'backup'          => (string)($backup['filename'] ?? ''),
            'replaced'        => count($result['replaced']),
            'added'           => count($result['added']),
            'deleted'         => count($result['deleted'] ?? []),
            'delete_failed'   => count($result['delete_failed'] ?? []),
            'skipped'         => count($result['skipped']),
            'migration_steps' => $migrationSteps,
            'redirect'        => $redirect,
            'rollback_dir'    => basename((string)$result['rollback_dir']),
        ];
    }

    /** 本地测试源取 JSON（仅 `UpdateSource::isLocalDev()` 时调用） */
    private function fetchLocalJson(string $url): ?array
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 1],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            error_log('[Updater] local test source unreachable: ' . $url);
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** 本地测试源下载发布包（仅 `UpdateSource::isLocalDev()` 时调用） */
    private function downloadLocal(string $url, string $dest): bool
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 120, 'ignore_errors' => true, 'follow_location' => 1],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            error_log('[Updater] local test package unreachable: ' . $url);
            return false;
        }
        if (strlen($body) > UpdateSource::MAX_PACKAGE_BYTES) {
            error_log('[Updater] local test package exceeds size limit: ' . $url);
            return false;
        }
        if (@file_put_contents($dest, $body) === false) {
            error_log('[Updater] local test package write failed: ' . $dest);
            return false;
        }
        return true;
    }

    /** 清理升级工作区 */
    private function cleanupWorkDir(string $keepRollback): void
    {
        $work = $this->workDir();
        $keep = '';
        if ($keepRollback !== '') {
            $keep = realpath($keepRollback) ?: $keepRollback;
        }
        foreach (glob($work . '/rollback_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $real = realpath($dir) ?: $dir;
            if ($keep !== '' && $real === $keep) continue;
            $this->removeDir($dir);
        }
        foreach (glob($work . '/staging_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->removeDir($dir);
        }
        foreach (glob($work . '/dl_*.zip') ?: [] as $zip) {
            @unlink($zip);
        }
    }

    /** 递归删目录（仅用于清理工作区；不用于别处） */
    private function removeDir(string $dir): void
    {
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        } catch (Throwable $e) {
            error_log('[Updater] 清理工作区目录失败：' . $e->getMessage());
        }
    }
}
