<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Migration\MigrationEngine;
use App\Core\Migration\MigrationGate;
use App\Core\Migration\MigrationHistory;
use App\Core\Migration\MigrationResult;
use App\Core\Response;

/** 数据库迁移 API（P1 独立迁移页的后端） */
final class MigrationRoutes
{
    public static function handle(string $method, array $user): void
    {
        if (!\isPrimaryAdmin()) Response::forbidden('仅主管理员可执行数据库迁移');

        $db = \getDB();
        switch ($method) {
            case 'preview':
                self::handlePreview($db);
                break;
            case 'execute':
                self::handleExecute($db, (int)$user['id']);
                break;
            case 'history':
                self::handleHistory($db);
                break;
            default:
                Response::error('未知 migration 方法：' . $method, 404);
        }
    }

    /** GET migration.preview — 预演结果 + 版本信息 + 最近历史 */
    private static function handlePreview(\PDO $db): void
    {
        $engine = new MigrationEngine($db);
        Response::ok([
            'current_version' => $engine->getCurrentVersion(),
            'target_version'  => $engine->getTargetVersionString(),
            'pending'         => MigrationGate::pending($db),
            'dry'             => $engine->dryRun(),
            'history'         => (new MigrationHistory($db))->recent(20),
        ]);
    }

    /** POST migration.execute — 执行迁移（仅待迁移时允许） */
    private static function handleExecute(\PDO $db, int $uid): void
    {
        // 复查闸门：无待迁移时拒绝（与 UI「无执行按钮」成对）
        if (!MigrationGate::pending($db)) {
            Response::error('当前无待迁移的版本，迁移不可执行', 403);
        }

        $engine = new MigrationEngine($db);
        $source = $engine->getCurrentVersion();
        $target = $engine->getTargetVersionString();

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        try {
            $result = $engine->execute();
        } catch (\Throwable $e) {
            error_log('[migration.execute] ' . $e->getMessage());
            Response::error('迁移执行异常：' . $e->getMessage(), 500);
        }

        MigrationGate::pending($db, true);

        \traceLog($uid, 'db_migration', 'setting', 0,
            "后台执行数据库迁移：{$source} → {$target}（{$result->getStatusText()}）");

        Response::ok(self::buildResultPayload($engine, $result), $result->getStatusText());
    }

    /** GET migration.history — 迁移历史 */
    private static function handleHistory(\PDO $db): void
    {
        Response::ok(['history' => (new MigrationHistory($db))->recent(50)]);
    }

    /** 组装执行结果（供前端内联渲染） */
    private static function buildResultPayload(MigrationEngine $engine, MigrationResult $result): array
    {
        $steps = [];
        foreach ($result->getSteps() as $s) {
            $steps[] = [
                'from'     => $s->getFrom(),
                'to'       => $s->getTo(),
                'status'   => $s->getStatus(),
                'records'  => $s->getRecordsMigrated(),
                'messages' => $s->getMessages(),
            ];
        }

        $validation = $result->getValidation();
        $backup = $result->getBackupFile();

        return [
            'status'      => $result->getStatus(),
            'status_text' => $result->getStatusText(),
            'backup'      => $backup ? basename((string)$backup) : '',
            'steps'       => $steps,
            'checks'      => $validation ? $validation->getChecks() : [],
            'messages'    => $result->getMessages(),
            'report'      => $engine->generateReport($result),
        ];
    }
}
