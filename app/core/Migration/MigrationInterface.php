<?php
declare(strict_types=1);

namespace App\Core\Migration;

/** 迁移适配器接口 */
interface MigrationInterface
{
    /** 迁移来源版本号，如 '1.2.0' */
    public function getSourceVersion(): string;

    /** 迁移目标版本号，如 '2.0.0' */
    public function getTargetVersion(): string;

    /** 迁移描述（页面展示用） */
    public function getDescription(): string;

    /** 前置检查：当前数据库状态是否允许执行本步骤 */
    public function preCheck(): bool;

    /** 升级迁移 */
    public function up(): MigrationStepResult;

    /** 降级回滚（可选，暂不实现） */
    public function down(): MigrationStepResult;
}
