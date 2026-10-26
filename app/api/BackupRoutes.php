<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use Modules\Admin\Service\WebdavCredentialService;

/** 数据备份 API 路由 */
final class BackupRoutes
{
    /** 备份根目录（项目根 /backups/） */
    private static function backupDir(): string
    {
        return dirname(__DIR__, 2) . '/backups';
    }

    /** WebDAV 密码存取器（加密落库，读时惰性迁移旧明文） */
    private static function webdavCred(): WebdavCredentialService
    {
        return new WebdavCredentialService(\getDB());
    }

    /** 规范化 WebDAV 目录地址 */
    private static function webdavBaseUrl(): string
    {
        $raw = trim((string)\getSetting('webdav_url'));
        if ($raw === '') return '';
        $p = parse_url($raw);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return rtrim($raw, '/');

        $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '');
        $path = rtrim(self::webdavEncodePath((string)($p['path'] ?? '')), '/');

        $sub = self::webdavSubPath();
        if ($sub !== '') $path .= '/' . self::webdavEncodePath($sub);

        return $base . $path;
    }

    /** 读取并规范化「备份路径」（`settings.webdav_path`） */
    private static function webdavSubPath(): string
    {
        $raw = trim((string)\getSetting('webdav_path'));
        if ($raw === '') return 'lcsc_backup';

        if (preg_match('#^https?://#i', $raw)) {
            $p   = parse_url($raw);
            $raw = (string)($p['path'] ?? '');
            $raw = preg_replace('#^/dav(?=/|$)#i', '', $raw) ?? $raw;
        }

        $segs = array_values(array_filter(
            explode('/', str_replace('\\', '/', $raw)),
            static fn(string $s): bool => $s !== '' && $s !== '.'
        ));
        if (!$segs || in_array('..', $segs, true)) return 'lcsc_backup';

        return implode('/', $segs);
    }

    /** 路径按段 percent-encode */
    private static function webdavEncodePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            $out[] = preg_replace_callback(
                '#[^A-Za-z0-9\-._~!$&\'()*+,;=:@%]#u',
                static fn(array $m): string => rawurlencode($m[0]),
                $seg
            ) ?? $seg;
        }
        return implode('/', $out);
    }

    /** 通用 WebDAV 请求（Basic 认证） */
    private static function webdavRequest(
        string $url,
        string $method,
        string $user,
        string $pass,
        ?string $body = null,
        array $headers = [],
        int $timeout = 30
    ): array {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opt);

        $res   = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$code, is_string($res) ? $res : '', $error];
    }

    /** 逐级补齐远端目录 */
    private static function webdavEnsureCollection(string $baseUrl, string $user, string $pass): bool
    {
        $p = parse_url($baseUrl);
        if (!$p || empty($p['path'])) return false;
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '');

        $path    = '';
        $created = false;
        foreach (array_filter(explode('/', (string)$p['path']), 'strlen') as $seg) {
            $path .= '/' . $seg;
            [$code] = self::webdavRequest($origin . $path, 'PROPFIND', $user, $pass, '', ['Depth: 0'], 20);

            if ($code === 207 || $code === 200 || $code === 403) continue;   // 已存在 / 存在但不可建
            if ($code !== 404) return false;

            [$mk] = self::webdavRequest($origin . $path, 'MKCOL', $user, $pass, '', [], 20);
            if (!in_array($mk, [200, 201, 204, 301, 302, 405, 409], true)) return false;
            if (in_array($mk, [200, 201, 204], true)) $created = true;
        }

        // 新建后确认可写：坚果云「建目录成功」与「PUT 可写」之间可能有短暂延迟
        if ($created) {
            for ($i = 0; $i < 8; $i++) {
                [$code] = self::webdavRequest($baseUrl . '/', 'PROPFIND', $user, $pass, '', ['Depth: 0'], 20);
                if ($code === 207 || $code === 200) break;
                usleep(500000);
            }
        }
        return true;
    }

    /** 动态枚据数据库中实际存在的全部表 */
    private static function collectTables(\PDO $db): array
    {
        $rows = $db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, 'is_string'));
    }

    /** 取数：备份记录列表 */
    public static function queryLogs(\PDO $db, int $dataUid): array
    {
        $stmt = $db->prepare("SELECT bl.id, bl.user_id, bl.file_name, bl.file_size, bl.action, bl.created_at, u.username FROM backup_log bl LEFT JOIN users u ON u.id = bl.user_id WHERE bl.user_id = ? ORDER BY bl.created_at DESC LIMIT 50");
        $stmt->execute([$dataUid]);
        $dir = self::backupDir();
        $logs = array_map(function ($r) use ($dir) {
            $fileName = (string)($r['file_name'] ?? '');
            return [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['user_id'],
                'username'   => (string)($r['username'] ?? '?'),
                'file_name'  => $fileName !== '' ? $fileName : '—',
                'file_size'  => (int)$r['file_size'] ?? 0,
                'action'     => (string)$r['action'],
                'created_at' => substr((string)$r['created_at'], 0, 16),
                'file_exists'=> $fileName !== ''
                    && basename($fileName) === $fileName
                    && is_file($dir . '/' . $fileName),
            ];
        }, $stmt->fetchAll());
        return ['logs' => $logs];
    }

    public static function handle(string $method, array $user): void
    {
        $db  = \getDB();
        $uid = (int)$user['id'];

        // 所有备份操作仅主管理员
        if (!\isPrimaryAdmin()) Response::error('仅管理员可执行', 403);

        switch ($method) {
            case 'logs':
                Response::ok(self::queryLogs($db, (int)\getDataUserId()));
                break;

            case 'database':
                self::handleBackupDatabase($db, $uid);
                break;

            case 'restore':
                self::handleRestoreDatabase($db, $uid);
                break;

            case 'list':
                self::handleListBackups();
                break;

            case 'webdav':
                self::handleBackupWebdav($db, $uid);
                break;

            case 'webdav_save':
                self::handleWebdavSave($uid);
                break;

            case 'webdav_test':
                self::handleWebdavTest();
                break;

            case 'schedule':
                self::handleScheduleConfig($db, $uid);
                break;

            case 'schedule_status':
                self::handleScheduleStatus();
                break;

            case 'cleanup':
                self::handleCleanup($uid);
                break;

            case 'delete':
                self::handleDeleteBackup($uid);
                break;

            default:
                Response::error('未知 backup 方法：' . $method, 404);
        }
    }

    /** 导出全库为 SQL 文本（DROP + CREATE + INSERT） */
    private static function dumpDatabase(\PDO $db, string $title): string
    {
        $sql = "-- LCSC {$title}\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach (self::collectTables($db) as $table) {
            try {
                $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM);
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n";
                $stmt = $db->query("SELECT * FROM `{$table}`");
                while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $values = array_map(function ($v) use ($db) {
                        return $v === null ? 'NULL' : $db->quote((string)$v);
                    }, $row);
                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n";
                }
                $sql .= "\n";
            } catch (\Throwable $e) {
                $sql .= "-- Table {$table} skipped: " . $e->getMessage() . "\n\n";
            }
        }
        return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
    }

    /** 导出整库并写文件（供 `backup.database` 与**在线升级**复用） */
    public static function createBackupFile(\PDO $db, int $uid, string $prefix = 'backup'): array
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filename = $prefix . '_' . date('Ymd_His') . '.sql';
        $dir = self::backupDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $sql = self::dumpDatabase($db, ucfirst($prefix));
        file_put_contents($dir . '/' . $filename, $sql);
        \recordBackupLog($uid, $filename, strlen($sql));
        return ['filename' => $filename, 'size' => strlen($sql)];
    }

    /** POST backup.database — 数据库备份 */
    private static function handleBackupDatabase(\PDO $db, int $uid): void
    {
        $r = self::createBackupFile($db, $uid, 'backup');
        \traceLog($uid, 'backup_database', 'system', 0, (string)$r['filename']);
        Response::ok(['filename' => $r['filename'], 'size' => $r['size']]);
    }

    /** POST backup.restore — 数据库恢复（filename） */
    private static function handleRestoreDatabase(\PDO $db, int $uid): void
    {
        $filename = trim((string)($_POST['filename'] ?? ''));
        if ($filename === '' || str_contains($filename, '..')) Response::error('文件名无效');
        $filepath = self::backupDir() . '/' . $filename;
        if (!is_file($filepath)) Response::error('备份文件不存在');

        $sql = file_get_contents($filepath);
        $db->exec($sql);
        \recordBackupLog($uid, $filename, strlen((string)$sql), 'restore');
        \traceLog($uid, 'restore_database', 'system', 0, $filename);
        Response::ok([]);
    }

    /** POST backup.list — 列出所有备份文件（含定时备份 auto_backup_*） */
    private static function handleListBackups(): void
    {
        $dir = self::backupDir();
        if (!is_dir($dir)) {
            Response::ok(['files' => []]);
        }
        $files = array_merge(
            glob($dir . '/backup_*.sql') ?: [],
            glob($dir . '/auto_backup_*.sql') ?: [],
            glob($dir . '/upgrade_backup_*.sql') ?: []
        );
        $result = array_map(function ($f) {
            return [
                'filename' => basename($f),
                'size'     => filesize($f),
                'time'     => date('Y-m-d H:i:s', filemtime($f)),
            ];
        }, $files);
        usort($result, fn($a, $b) => strcmp($b['time'], $a['time']));
        Response::ok(['files' => $result]);
    }

    /** POST backup.delete — 删除服务器上的单个备份文件 */
    private static function handleDeleteBackup(int $uid): void
    {
        $raw      = trim((string)($_POST['filename'] ?? ''));
        $filename = basename($raw);
        if ($filename === '' || $filename !== $raw || str_contains($filename, '..')) {
            Response::error('文件名无效');
        }
        if (!preg_match('/^(backup|auto_backup|upgrade_backup)_\d{8}_\d{6}\.sql$/', $filename)) {
            Response::error('只能删除系统生成的备份文件');
        }

        $path = self::backupDir() . '/' . $filename;
        if (!is_file($path)) Response::error('备份文件不存在', 404);
        if (!@unlink($path)) Response::error('删除失败，请检查 backups/ 目录权限');

        \traceLog($uid, 'backup_delete', 'backup', 0, $filename);
        Response::ok(['filename' => $filename], '备份已删除');
    }

    /** POST backup.webdav — 生成备份并 PUT 上传到 WebDAV */
    private static function handleBackupWebdav(\PDO $db, int $uid): void
    {
        $baseUrl = self::webdavBaseUrl();
        if ($baseUrl === '') Response::error('未配置WebDAV，请在设置中填写WebDAV地址');
        if (!function_exists('curl_init')) Response::error('服务器未启用 cURL 扩展，无法上传');

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $filename = 'backup_' . date('Ymd_His') . '.sql';
        $sql = self::dumpDatabase($db, 'Backup');
        if ($sql === '') Response::error('备份内容为空，已中止上传');

        // 本地同样留一份，WebDAV 不可用时仍有本地兜底
        $dir = self::backupDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $filename, $sql);

        $user = trim(\getSetting('webdav_user'));
        $pass = self::webdavCred()->getPass();      // 加密存取（读时惰性迁移旧明文）

        $fileUrl  = $baseUrl . '/' . rawurlencode($filename);

        self::webdavEnsureCollection($baseUrl, $user, $pass);

        $httpCode = 0;
        $error = self::webdavPut($fileUrl, $sql, $user, $pass, $httpCode);

        for ($i = 0; $error !== null && in_array($httpCode, [404, 409], true) && $i < 3; $i++) {
            usleep(1000000);
            self::webdavEnsureCollection($baseUrl, $user, $pass);
            $httpCode = 0;
            $error = self::webdavPut($fileUrl, $sql, $user, $pass, $httpCode);
        }
        if ($error !== null) {
            if (in_array($httpCode, [404, 409], true)) {
                $error .= '（已尝试自动创建远端目录仍失败；请确认「WebDAV 地址」只填服务地址、'
                    . '「备份路径」填相对目录，且账号对该目录有写权限）';
            }
            Response::error('WebDAV 上传失败：' . $error);
        }

        $keep = max(1, min(30, (int)\getSetting('backup_keep_count', '7')));
        $cleaned = self::webdavCleanup($baseUrl, $user, $pass, $keep, $uid);

        \recordBackupLog($uid, $filename, strlen($sql));
        \traceLog($uid, 'backup_webdav', 'system', 0, "{$filename} → {$baseUrl}（远端清理 {$cleaned} 份）");
        Response::ok(
            ['filename' => $filename, 'size' => strlen($sql), 'cleaned' => $cleaned],
            "上传成功（远端保留最近 {$keep} 份）"
        );
    }

    /** WebDAV PUT 单文件上传（Basic 认证） */
    private static function webdavPut(string $url, string $content, string $user, string $pass, ?int &$codeOut = null): ?string
    {
        $fp = fopen('php://temp', 'r+b');
        if ($fp === false) return '无法创建临时流';
        fwrite($fp, $content);
        rewind($fp);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => strlen($content),
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Expect:', 'Content-Type: application/octet-stream'],
        ]);
        if ($user !== '') curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        if ($user !== '' || $pass !== '') curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        $codeOut = $code;

        if ($errno !== 0) return $err !== '' ? $err : "cURL 错误 {$errno}";
        // 2xx / 207 多状态均为成功
        if ($code >= 200 && $code < 300) return null;
        if ($code === 0) return '无响应（请检查地址与网络）';

        $snippet = trim((string)$body);
        if ($snippet !== '') {
            $snippet = preg_replace('/\s+/', ' ', $snippet) ?? $snippet;
            $snippet = mb_substr($snippet, 0, 200);
        }
        return "HTTP {$code}" . ($snippet !== '' ? " — {$snippet}" : '');
    }

    /** WebDAV 远端保留份数清理（PROPFIND 列目录 → DELETE 超额旧文件） */
    private static function webdavCleanup(string $baseUrl, string $user, string $pass, int $keep, int $uid): int
    {
        $listBody = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:propfind xmlns:D="DAV:"><D:prop><D:displayname/><D:getcontentlength/>'
            . '<D:getlastmodified/><D:resourcetype/></D:prop></D:propfind>';

        $ch = curl_init($baseUrl . '/');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
            CURLOPT_POSTFIELDS     => $listBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_HTTPHEADER     => ['Depth: 1', 'Content-Type: application/xml'],

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $xml = curl_exec($ch);
        curl_close($ch);
        if (!is_string($xml) || $xml === '') return 0;

        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false) return 0;

        $doc->registerXPathNamespace('D', 'DAV:');
        $entries = [];
        foreach ($doc->xpath('//D:response') ?: [] as $resp) {

            $resp->registerXPathNamespace('D', 'DAV:');
            $hrefNodes = $resp->xpath('D:href');
            $href = isset($hrefNodes[0]) ? (string)$hrefNodes[0] : '';
            if ($href === '' || rtrim($href, '/') === rtrim($baseUrl, '/')) continue;

            $name = basename(rawurldecode((string)(parse_url($href, PHP_URL_PATH) ?: $href)));
            if (!preg_match('/^backup_\d{8}_\d{6}\.sql$/', $name)) continue;

            $lenNodes = $resp->xpath('D:propstat/D:prop/D:getcontentlength');
            $modNodes = $resp->xpath('D:propstat/D:prop/D:getlastmodified');
            $len  = isset($lenNodes[0]) ? (int)$lenNodes[0] : 0;
            $time = isset($modNodes[0]) ? (string)$modNodes[0] : '';
            $ts   = strtotime($time) ?: 0;
            $entries[] = ['href' => $href, 'name' => $name, 'len' => $len, 'ts' => $ts];
        }
        if (count($entries) <= $keep) return 0;

        usort($entries, fn($a, $b) => $b['ts'] <=> $a['ts']);

        $deleted = 0;
        foreach (array_slice($entries, $keep) as $old) {
            if (self::webdavDelete($baseUrl . '/' . $old['name'], $user, $pass, $uid)) $deleted++;
        }
        return $deleted;
    }

    /** WebDAV DELETE 单文件（失败仅记日志） */
    private static function webdavDelete(string $url, string $user, string $pass, int $uid): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            // 同 `webdavCleanup()`：缺省不校验证书 = 把中间人当正常路径
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) return true;
        \error_log('webdav cleanup failed: HTTP ' . $code . ' ' . $url);

        \traceLog($uid, 'webdav_delete_failed', 'backup', 0,
            'WebDAV 清理删除失败 HTTP ' . $code . '：' . basename($url));
        return false;
    }

    /** POST backup.webdav_save — 仅保存 WebDAV 连接配置（不触碰其他系统设置） */
    private static function handleWebdavSave(int $uid): void
    {
        $url  = trim((string)($_POST['webdav_url'] ?? ''));
        $user = trim((string)($_POST['webdav_user'] ?? ''));
        $path = trim((string)($_POST['webdav_path'] ?? ''));

        if ($url !== '' || $user !== '' || $path !== '') {
            if (str_contains($url . $user . $path, '*')) {
                Response::error('检测到掩码字符 *：请输入真实值，留空表示沿用已保存配置');
            }
        }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            Response::error('WebDAV 地址必须以 http:// 或 https:// 开头');
        }
        if ($url !== '')  \setSetting('webdav_url', $url);
        if ($user !== '') \setSetting('webdav_user', $user);

        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') $path = 'lcsc_backup';
        if (str_contains($path, '://')) Response::error('备份路径请填相对目录（如 lcsc_backup），不要填完整网址');
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) Response::error('备份路径不能包含 ..');
        \setSetting('webdav_path', $path);

        if (array_key_exists('webdav_pass', $_POST)) {
            $pass = (string)$_POST['webdav_pass'];
            if ($pass === '__CLEAR__')      self::webdavCred()->clearPass();
            elseif ($pass !== '')          self::webdavCred()->setPass($pass);
        }
        \traceLog($uid, 'save_settings', 'setting', 0, '保存WebDAV远程备份配置（路径：' . $path . '）');
        Response::ok(null, 'WebDAV 配置已保存');
    }

    /** POST backup.webdav_test — 连通性测试 + 远端目录列表 */
    private static function handleWebdavTest(): void
    {
        $baseUrl = self::webdavBaseUrl();
        if ($baseUrl === '') Response::error('未配置WebDAV，请先填写并保存 WebDAV 地址');
        if (!function_exists('curl_init')) Response::error('服务器未启用 cURL 扩展，无法测试连接');

        $user = trim(\getSetting('webdav_user'));
        $pass = self::webdavCred()->getPass();

        if (!self::webdavEnsureCollection($baseUrl, $user, $pass)) {
            Response::error('无法访问或创建远端目录，请检查地址、用户名与应用密码');
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:propfind xmlns:D="DAV:"><D:prop><D:displayname/><D:getcontentlength/>'
            . '<D:getlastmodified/><D:resourcetype/></D:prop></D:propfind>';

        [$code, $xml, $err] = self::webdavRequest(
            $baseUrl . '/', 'PROPFIND', $user, $pass, $body,
            ['Depth: 1', 'Content-Type: application/xml'], 30
        );

        if ($err !== '') Response::error('连接失败：' . $err);
        if ($code === 401) Response::error('认证失败（401）：请检查用户名与第三方应用密码');
        if ($code === 403) Response::error('无访问权限（403）：请检查账号对该目录的权限');
        if ($code === 404) Response::error('远端目录不存在（404）：请检查 WebDAV 地址与备份路径');
        if ($code !== 207 && $code !== 200) Response::error('连接失败：HTTP ' . $code);

        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false) Response::error('远端返回内容无法解析（可能不是 WebDAV 服务）');

        $doc->registerXPathNamespace('D', 'DAV:');
        $selfPath = rawurldecode((string)(parse_url($baseUrl, PHP_URL_PATH) ?: ''));
        $entries  = [];
        foreach ($doc->xpath('//D:response') ?: [] as $r) {

            $r->registerXPathNamespace('D', 'DAV:');
            $hrefNodes = $r->xpath('D:href');
            $href = isset($hrefNodes[0]) ? (string)$hrefNodes[0] : '';
            if ($href === '') continue;

            $hrefPath = rawurldecode((string)(parse_url($href, PHP_URL_PATH) ?: $href));
            if (rtrim($hrefPath, '/') === rtrim($selfPath, '/')) continue;   // 目录自身

            $name = basename(rtrim($hrefPath, '/'));
            if ($name === '') continue;

            $dirNodes = $r->xpath('D:propstat/D:prop/D:resourcetype/D:collection');
            $isDir    = !empty($dirNodes);
            $sizeNode = $r->xpath('D:propstat/D:prop/D:getcontentlength');
            $modNode  = $r->xpath('D:propstat/D:prop/D:getlastmodified');

            $entries[] = [
                'name'     => $name,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? 0 : (int)($sizeNode[0] ?? 0),
                'modified' => isset($modNode[0]) ? (string)$modNode[0] : '',
            ];
        }
        // 目录优先，其次按名称自然序
        usort($entries, static function (array $a, array $b): int {
            if (($a['type'] === 'dir') !== ($b['type'] === 'dir')) return $a['type'] === 'dir' ? -1 : 1;
            return strnatcasecmp($a['name'], $b['name']);
        });

        Response::ok([
            'path'    => '/' . self::webdavSubPath(),
            'count'   => count($entries),
            'entries' => array_slice($entries, 0, 100),
        ], '连接成功');
    }

    /** POST backup.schedule — 定时备份配置（方式/频率/定时点/保留份数） */
    private static function handleScheduleConfig(\PDO $db, int $uid): void
    {
        $frequency = trim((string)($_POST['frequency'] ?? ''));
        $keepCount = (int)($_POST['keep_count'] ?? 7);
        $rawTime   = trim((string)($_POST['backup_time'] ?? ''));

        $mode      = trim((string)($_POST['mode'] ?? ''));

        if (!in_array($frequency, ['daily', 'weekly', 'disabled'], true)) {
            $frequency = 'disabled';
        }
        if (!in_array($mode, ['schedule', 'on_request'], true)) {
            $mode = \backupMode();
        }
        $keepCount = max(1, min(30, $keepCount));

        if ($rawTime !== '' && backupTimeToMinutes($rawTime) === null) {
            Response::error('定时点格式无效，请使用 HH:MM（如 03:00）');
        }

        \setSetting('backup_frequency', $frequency);
        \setSetting('backup_keep_count', (string)$keepCount);
        \setSetting('backup_time', $rawTime);
        \setSetting('backup_mode', $mode);

        // 时间窗基准：记录「配置保存时刻」为新的窗口起点，而非重置为 0。

        \setSetting('backup_last_at', (string)time());

        \traceLog($uid, 'backup_schedule', 'system', 0,
            "定时备份配置：方式={$mode} 频率={$frequency} 时刻=" . ($rawTime ?: '不限') . " 保留={$keepCount}份");
        Response::ok([
            'mode'          => $mode,
            'frequency'      => $frequency,
            'keep_count'     => $keepCount,
            'backup_time'    => $rawTime,
            'window_start'   => date('Y-m-d H:i'),
            'next_due'       => self::nextScheduledRunHint($mode),
        ]);
    }

    /** 计算下次自动备份的预计时间（仅用于给管理员一个预期，非精确调度承诺） */
    private static function nextScheduledRunHint(string $mode): string
    {
        if (!\in_array(\getSetting('backup_frequency', 'disabled'), ['daily', 'weekly'], true)) {
            return '已关闭';
        }
        $next = \backupNextDueAt(time());
        if ($next <= 0) return '已关闭';
        $suffix = ($mode === 'on_request')
            ? ' 后（需该时刻之后有访问请求）'
            : ' 后（到点自动执行）';
        return date('Y-m-d H:i', $next) . $suffix;
    }

    /** GET backup.schedule_status — 定时备份当前状态（只读，供界面展示时间窗） */
    private static function handleScheduleStatus(): void
    {
        $lastAt     = (int)\getSetting('backup_last_at', '0');
        $frequency  = \getSetting('backup_frequency', 'disabled');
        $backupTime = (string)\getSetting('backup_time', '');
        $keepCount  = (int)\getSetting('backup_keep_count', '7');
        $mode       = \backupMode();
        $autoFiles  = glob(self::backupDir() . '/auto_backup_*.sql') ?: [];

        $lastRunAt  = $autoFiles ? date('Y-m-d H:i', max(array_map('filemtime', $autoFiles))) : '';

        Response::ok([
            'mode'           => $mode,
            'frequency'      => $frequency,
            'backup_time'    => $backupTime,
            'keep_count'     => $keepCount,
            'enabled'        => in_array($frequency, ['daily', 'weekly'], true),
            'last_run_at'    => $lastRunAt,
            'window_start'   => $lastAt > 0 ? date('Y-m-d H:i', $lastAt) : '',
            'auto_file_count'=> count($autoFiles),
            'next_due'       => self::nextScheduledRunHint($mode),
        ]);
    }

    /** POST backup.cleanup — 清理过期备份文件 */
    private static function handleCleanup(int $uid): void
    {
        $keepCount = (int)\getSetting('backup_keep_count', '7');
        $dir = self::backupDir();
        if (!is_dir($dir)) Response::ok(['deleted' => 0]);

        $deleted = 0;
        $kept    = 0;
        foreach (['backup_*.sql', 'upgrade_backup_*.sql'] as $pattern) {
            $files = glob($dir . '/' . $pattern) ?: [];
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            $kept += min(count($files), $keepCount);
            foreach (array_slice($files, $keepCount) as $file) {
                if (unlink($file)) $deleted++;
            }
        }

        \traceLog($uid, 'backup_cleanup', 'system', 0, "清理过期备份{$deleted}个，每类保留{$keepCount}份");
        Response::ok(['deleted' => $deleted, 'kept' => $kept]);
    }
}
