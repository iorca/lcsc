<?php
declare(strict_types=1);

namespace Modules\Import\Controller;

use PDO;

/** 导入订单页控制器 */
final class ImportController
{
    /** 组装导入订单页视图数据 */
    public static function prepareViewData(PDO $db, int $uid, int $dataUid): array
    {
        $read = new \Modules\Import\Service\ImportReadService($db, $uid, $dataUid);

        [$platforms, $defaultPlat] = $read->getPlatformsWithDefault();
        $recentErrors  = $read->getRecentErrors();
        $importedFiles = $read->getImportedFiles();
        $batches       = $read->getRecentBatches();

        // ── 平台导入模板状态（未配置模板的平台不可上传）──
        $tplStatus = [];
        $tplFields = [];
        try {
            $tplSvc    = new \Modules\Import\Service\ImportTemplateService($db, $dataUid, \getWorkspaceId());
            $tplStatus = $tplSvc->listPlatformStatus();
            $tplFields = \Modules\Import\Service\ImportTemplateService::fieldLabels();
        } catch (\Throwable $e) { error_log('ImportController tplStatus: ' . $e->getMessage()); }

        return [
            'platforms'     => $platforms,
            'defaultPlat'   => $defaultPlat,
            'recentErrors'  => $recentErrors,
            'importedFiles' => $batches ?: $importedFiles,
            'tplStatus'     => $tplStatus,
            'tplFields'     => $tplFields,
        ];
    }
}
