<?php
declare(strict_types=1);

namespace Modules\Admin\Service;

use PDO;

/** 备份与恢复服务 */
final class AdminBackupService
{
    private PDO $db;
    private int $uid;

    public function __construct(PDO $db, int $uid)
    {
        $this->db  = $db;
        $this->uid = $uid;
    }

    /** 生成整库备份 SQL */
    public function generateSql(): string
    {
        $sql  = "-- LCSC Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- User ID: {$this->uid}\n\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $allTables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($allTables as $table) {
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table: `{$table}`\n";
            $sql .= "-- --------------------------------------------------------\n\n";

            $create = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            $rows = $this->dumpRows($table);
            if (empty($rows)) {
                continue;
            }

            $colList = '`' . implode('`, `', array_keys($rows[0])) . '`';
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $this->db->quote((string)$v);
                }
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        return $sql . "SET FOREIGN_KEY_CHECKS=1;\n";
    }

    /** 导出一张表的数据行 */
    private function dumpRows(string $table): array
    {
        $cols = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if ($col['Field'] === 'user_id') {
                $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE user_id=?");
                $stmt->execute([$this->uid]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        return $this->db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** 写备份 / 恢复日志 */
    public function recordLog(string $fileName, int $fileSize, string $action): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO backup_log (user_id, file_name, file_size, action) VALUES (?, ?, ?, ?)"
            )->execute([$this->uid, $fileName, $fileSize, $action]);
        } catch (\Throwable $e) {
            // 日志失败不阻断备份 / 恢复主流程
            error_log('AdminBackupService::recordLog: ' . $e->getMessage());
        }
    }

    /** 执行上传的 SQL 文件 */
    public function restore(string $content): array
    {
        $statements = self::splitSqlStatements($content);
        $executed = 0;
        $lastErr  = '';

        foreach ($statements as $st) {
            try {
                $this->db->exec($st);
                $executed++;
            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                error_log('Restore statement failed: ' . $lastErr . ' | SQL: ' . substr($st, 0, 200));
                break;
            }
        }

        return ['executed' => $executed, 'total' => count($statements), 'error' => $lastErr];
    }

    /** SQL 文件按语句分割（静态，纯字符串处理） */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $delimiter  = ';';
        $len        = strlen($sql);
        $i          = 0;

        $inSingleQuote  = false;
        $inDoubleQuote  = false;
        $inBacktick     = false;
        $inLineComment  = false;
        $inBlockComment = false;

        while ($i < $len) {
            $char    = $sql[$i];
            $next    = ($i + 1 < $len) ? $sql[$i + 1] : '';
            $twoChar = $char . $next;

            // 行注释结束（换行）
            if ($inLineComment) {
                if ($char === "\n" || $char === "\r") {
                    $inLineComment = false;
                }
                $current .= $char;
                $i++;
                continue;
            }

            // 块注释结束
            if ($inBlockComment) {
                if ($twoChar === '*/') {
                    $inBlockComment = false;
                    $current .= '*/';
                    $i += 2;
                } else {
                    $current .= $char;
                    $i++;
                }
                continue;
            }

            // 字符串内（单引号）
            if ($inSingleQuote) {
                if ($char === '\\' && $next !== '') {
                    $current .= $char . $next;
                    $i += 2;
                    continue;
                }
                if ($char === "'") {
                    $inSingleQuote = false;
                }
                $current .= $char;
                $i++;
                continue;
            }

            // 字符串内（双引号）
            if ($inDoubleQuote) {
                if ($char === '\\' && $next !== '') {
                    $current .= $char . $next;
                    $i += 2;
                    continue;
                }
                if ($char === '"') {
                    $inDoubleQuote = false;
                }
                $current .= $char;
                $i++;
                continue;
            }

            // 反引号标识符内（列名 / 表名可含 `;` 等分隔符）
            if ($inBacktick) {
                if ($char === '`') {
                    $inBacktick = false;
                }
                $current .= $char;
                $i++;
                continue;
            }

            // 行注释开头
            if ($twoChar === '--' || $char === '#') {
                $inLineComment = true;
                $current .= $char;
                $i++;
                continue;
            }

            // 块注释开头
            if ($twoChar === '/*') {
                $inBlockComment = true;
                $current .= '/*';
                $i += 2;
                continue;
            }

            // 字符串开始
            if ($char === "'") {
                $inSingleQuote = true;
                $current .= $char;
                $i++;
                continue;
            }
            if ($char === '"') {
                $inDoubleQuote = true;
                $current .= $char;
                $i++;
                continue;
            }
            if ($char === '`') {
                $inBacktick = true;
                $current .= $char;
                $i++;
                continue;
            }

            // DELIMITER 命令
            if (stripos(substr($sql, $i, 10), 'DELIMITER') === 0) {
                $rest = substr($sql, $i);
                if (preg_match('/^DELIMITER\s+(\S+)/i', $rest, $m)) {
                    $delimiter = $m[1];
                    $i += strlen($m[0]);
                    continue;
                }
            }

            // 语句分隔符
            if ($char === $delimiter[0]) {
                $delimLen = strlen($delimiter);
                if (substr($sql, $i, $delimLen) === $delimiter) {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    $i += $delimLen;
                    continue;
                }
            }

            $current .= $char;
            $i++;
        }

        // 最后一条语句
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
