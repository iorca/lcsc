<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Response;
use App\Core\Update\UpdateException;
use App\Core\Update\Updater;
use Throwable;

/** 在线升级 API（P2 远端拉取更新） */
final class UpdateRoutes
{
    public static function handle(string $method, array $user): void
    {
        if (!\isPrimaryAdmin()) Response::error('仅主管理员可执行在线升级', 403);

        $db   = \getDB();
        $uid  = (int)$user['id'];
        $root = dirname(__DIR__, 2);   // app/api/ → 项目根

        switch ($method) {
            case 'preflight':
                try {
                    $data = (new Updater($db, $root))->preflight();
                } catch (UpdateException $e) {
                    Response::error($e->getMessage(), 400);
                }
                Response::ok($data);
                break;

            case 'apply':
                // ⑧ 必须人工点确认，不后台静默升级
                if ((int)($_POST['confirm'] ?? 0) !== 1) {
                    Response::error('升级需显式确认（confirm=1）', 400);
                }
                @set_time_limit(600);
                @ini_set('memory_limit', '512M');

                try {
                    $data = (new Updater($db, $root))->apply();
                } catch (UpdateException $e) {
                    \traceLog($uid, 'online_upgrade', 'system', 0, '失败：' . $e->getMessage());
                    Response::error($e->getMessage(), 400);
                } catch (Throwable $e) {
                    error_log('[update.apply] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                    \traceLog($uid, 'online_upgrade', 'system', 0, '异常：' . $e->getMessage());
                    Response::error('升级过程发生异常：' . $e->getMessage(), 500);
                }

                $detail = sprintf(
                    '在线升级 %s：替换 %d / 新增 %d / 删除旧文件 %d / 跳过 %d，库备份 %s',
                    $data['version'], $data['replaced'], $data['added'], $data['deleted'] ?? 0, $data['skipped'], $data['backup']
                );
                if (($data['delete_failed'] ?? 0) > 0) {
                    $detail .= '；旧文件清理失败 ' . (int)$data['delete_failed'] . ' 个（见 error_log）';
                }
                \traceLog($uid, 'online_upgrade', 'system', 0, $detail);
                Response::ok($data, '已升级到 ' . $data['version']);
                break;

            default:
                Response::error('未知 update 方法：' . $method, 404);
        }
    }
}
