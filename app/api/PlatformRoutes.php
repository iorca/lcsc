<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;

require_once __DIR__ . '/../../modules/system/Service/PlatformService.php';

require_once __DIR__ . '/../../modules/scan/Service/ScanQrDictService.php';

/** 平台管理 API 路由 */
final class PlatformRoutes
{
    /** 取数：单个平台的类型属性 */
    public static function queryPlatformType(\PDO $db, int $uid, int $dataUid, int $platformId): array
    {
        return (new \PlatformManager($db, $uid, $dataUid))->getPlatformType($platformId);
    }

    /** 取数：平台列表 */
    public static function queryPlatforms(\PDO $db, int $uid, int $dataUid): array
    {
        return (new \PlatformManager($db, $uid, $dataUid))->listPlatforms();
    }

    /** 取数：单个平台完整详情 */
    public static function queryPlatformDetail(\PDO $db, int $uid, int $dataUid, int $platformId): array
    {
        return (new \PlatformManager($db, $uid, $dataUid))->getPlatform($platformId);
    }

    public static function handle(string $method, array $user): void
    {
        $db      = \getDB();
        $uid     = (int)$user['id'];
        $dataUid = (int)\getDataUserId();
        $wsId    = (int)\getWorkspaceId();

        // ── 读操作：平台查询（登录即可）──
        if ($method === 'type' || $method === 'list' || $method === 'detail') {
            try {
                if ($method === 'type') {
                    Response::ok(self::queryPlatformType($db, $uid, $dataUid, intval($_GET['platform_id'] ?? 0)));
                } elseif ($method === 'list') {
                    Response::ok(self::queryPlatforms($db, $uid, $dataUid));
                } else {
                    Response::ok(self::queryPlatformDetail($db, $uid, $dataUid, intval($_GET['platform_id'] ?? 0)));
                }
            } catch (\PlatformException $e) {
                Response::error($e->getMessage(), $e->errCode);
            }
            return;
        }

        // 平台管理（写操作）仅管理员可操作
        if (($user['role'] ?? '') !== 'admin') {
            Response::error('无权限', 403);
        }
        // 扫码参数配置包属系统级配置：仅主管理员（role=admin 且 id=1）
        $isPrimary = (int)$user['id'] === 1;

        $plm = new \PlatformManager($db, $uid, $dataUid);
        $svc = new \Modules\Scan\Service\ScanQrDictService($db, $dataUid, $wsId);

        try {
            switch ($method) {
                case 'add':
                    $result = $plm->addPlatform($_POST);
                    Response::ok($result, '平台添加成功');
                    break;

                case 'edit':
                    $result = $plm->editPlatform($_POST);
                    Response::ok($result, '平台修改成功');
                    break;

                case 'delete':
                    $platId = (int)($_POST['plat_id'] ?? 0);
                    $result = $plm->deletePlatform($platId);
                    Response::ok($result, '平台删除成功');
                    break;

                case 'set_default':
                    $platId = (int)($_POST['plat_id'] ?? 0);
                    $result = $plm->setDefault($platId);
                    Response::ok($result, '默认平台设置成功');
                    break;

                case 'qr_config_get':
                    if (!$isPrimary) { Response::error('仅主管理员可查看扫码参数配置', 403); }
                    Response::ok($svc->getConfig((int)($_GET['platform_id'] ?? 0)));
                    break;

                case 'qr_config_import':
                    if (!$isPrimary) { Response::error('仅主管理员可导入扫码参数配置', 403); }
                    Response::ok(self::handleQrConfigImport($uid, $svc));
                    break;

                case 'qr_config_create':
                    if (!$isPrimary) { Response::error('仅主管理员可创建平台', 403); }
                    Response::ok(self::handleQrConfigCreate($db, $uid, $dataUid, $svc), '平台已创建并应用扫码参数');
                    break;

                case 'qr_config_save':
                    if (!$isPrimary) { Response::error('仅主管理员可保存扫码参数配置', 403); }
                    $formats = json_decode((string)($_POST['formats'] ?? '[]'), true);
                    $pid     = (int)($_POST['platform_id'] ?? 0);
                    $svc->saveUserFormats($pid, is_array($formats) ? $formats : []);
                    \traceLog($uid, 'qr_config_save', 'platform', $pid, "保存扫码参数配置 platform_id:{$pid}");
                    Response::ok(['saved' => true, 'platform_id' => $pid], '扫码参数已保存');
                    break;

                case 'qr_config_reset':
                    if (!$isPrimary) { Response::error('仅主管理员可重置扫码参数配置', 403); }
                    $pid = (int)($_POST['platform_id'] ?? 0);
                    if (!$svc->resetToBuiltin($pid)) { Response::error('该平台没有可重置的扫码参数', 4); }
                    \traceLog($uid, 'qr_config_reset', 'platform', $pid, "重置扫码参数为内置 platform_id:{$pid}");
                    Response::ok(['reset' => true, 'platform_id' => $pid], '已重置为内置扫码参数');
                    break;

                default:
                    Response::error('未知 platform 方法：' . $method, 404);
            }
        } catch (\PlatformException $e) {
            Response::error($e->getMessage(), $e->errCode);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 4);
        }
    }

    /** 导入扫码参数配置包（本地 .json 文件，不出站） */
    private static function handleQrConfigImport(int $uid, \Modules\Scan\Service\ScanQrDictService $svc): array
    {
        if (empty($_FILES['file']) || !\is_array($_FILES['file'])) {
            throw new \InvalidArgumentException('请选择要导入的配置文件（.json）');
        }
        $file = $_FILES['file'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('文件上传失败，请重试');
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 262144) {
            throw new \InvalidArgumentException('配置文件过大（上限 256KB）');
        }
        $ext = \strtolower((string)\pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'json') throw new \InvalidArgumentException('仅支持 .json 配置文件');
        if (!\is_uploaded_file((string)$file['tmp_name'])) throw new \InvalidArgumentException('非法上传');

        $raw = (string)\file_get_contents((string)$file['tmp_name']);
        $cfg = \Modules\Scan\Service\ScanQrDictService::parseConfigFile($raw);   // 非法整体拒绝

        $code = (string)$cfg['platform']['code'];
        $pid  = $svc->findPlatformIdByCode($code);

        // 命中 → 直接覆盖（等同套用模板）
        if ($pid > 0) {
            $svc->saveUserFormats($pid, $cfg['formats'], $code);
            \traceLog($uid, 'qr_config_import', 'platform', $pid, "导入扫码参数配置（覆盖）code:{$code}");
            return ['applied' => true, 'found' => true, 'platform_id' => $pid, 'platform_code' => $code];
        }

        return [
            'applied'     => false,
            'found'       => false,
            'need_create' => true,
            'platform'    => $cfg['platform'],
            'formats'     => $cfg['formats'],
        ];
    }

    /** 未命中时新建平台并应用扫码参数（复用 PlatformManager::addPlatform 的校验与默认平台逻辑） */
    private static function handleQrConfigCreate(\PDO $db, int $uid, int $dataUid, \Modules\Scan\Service\ScanQrDictService $svc): array
    {

        $rawFormats = json_decode((string)($_POST['formats'] ?? '[]'), true);
        $formats    = \Modules\Scan\Service\ScanQrDictService::validateFormats(is_array($rawFormats) ? $rawFormats : []);

        $created = (new \PlatformManager($db, $uid, $dataUid))->addPlatform([
            'plat_code'     => trim((string)($_POST['code'] ?? '')),
            'plat_name'     => trim((string)($_POST['name'] ?? '')),
            'plat_url'      => trim((string)($_POST['url_template'] ?? '')),
            'platform_type' => strtolower(trim((string)($_POST['type'] ?? 'standard'))),
        ]);
        $pid = (int)$created['platform_id'];
        $svc->saveUserFormats($pid, $formats, (string)$created['code']);

        \traceLog($uid, 'qr_config_create', 'platform', $pid, "导入创建平台并应用扫码参数 code:{$created['code']}");

        return ['created' => true, 'platform_id' => $pid, 'platform_code' => (string)$created['code']];
    }
}
