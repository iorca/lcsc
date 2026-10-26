<?php
declare(strict_types=1);

namespace Modules\Import\Service;

use App\Helper\Parser\FieldRegistry;

/** SheetTableParser — 通用表格解析器（Excel 多 Sheet / CSV） */
final class SheetTableParser
{
    /** 汇总行关键词（跳过） */
    private const SUMMARY_KEYWORDS = ['合计', '小计', '总计', 'Total', 'Summary', 'Subtotal', '汇总'];

    /** 解析表格文件 */
    public function parse(string $filePath, array $expectedHeaders = []): array
    {
        $ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'tsv' || $ext === 'txt') {
            return $this->parseDelimited($filePath, $ext === 'tsv' ? "\t" : ',', $expectedHeaders);
        }

        return $this->parseExcel($filePath, $expectedHeaders);
    }

    /** 字段识别（无模板时的兜底：按注册表关键词把表头映射到系统字段） */
    public function recognizeFields(array $headers): array
    {
        $mapping = [];
        $used    = [];
        foreach ($headers as $i => $header) {
            $header = trim((string)$header);
            if ($header === '') continue;
            foreach (FieldRegistry::fieldAliases() as $field => $keywords) {
                if (isset($used[$field])) continue;   // 同一字段只取第一列
                foreach ($keywords as $kw) {
                    if (FieldRegistry::matchText($header, $kw)) {
                        $mapping[(int)$i] = $field;
                        $used[$field] = true;
                        break 2;
                    }
                }
            }
        }
        return $mapping;
    }

    /** 供模板 UI 展示的「系统字段 => 中文标签」（注册表派生，仅可写字段） */
    public static function fieldLabels(): array
    {
        return FieldRegistry::fieldLabels();
    }

    // ── 解析实现 ───────────────────────────────────

    /** Excel：多 Sheet 打分择优 */
    private function parseExcel(string $filePath, array $expectedHeaders): array
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new \RuntimeException('PhpSpreadsheet 未安装，请运行 composer require phpoffice/phpspreadsheet');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $sheetNames  = $spreadsheet->getSheetNames();

        $best = null;
        foreach ($sheetNames as $idx => $name) {
            $data = $spreadsheet->getSheet($idx)->toArray(null, true, true, false);
            $res  = $this->extractTable((array)$data, (string)$name, $expectedHeaders);
            if ($best === null || $res['score'] > $best['score']) $best = $res;
        }
        if ($best === null) {
            return ['headers' => [], 'rows' => [], 'sheet_name' => '', 'total' => 0, 'header_row' => -1, 'sheets' => count($sheetNames)];
        }

        return [
            'headers'    => $best['headers'],
            'rows'       => $best['rows'],
            'sheet_name' => $best['sheet_name'],
            'total'      => count($best['rows']),
            'header_row' => $best['header_row'],
            'sheets'     => count($sheetNames),
        ];
    }

    /** CSV / TSV：直读（修复 BOM 偏移） */
    private function parseDelimited(string $filePath, string $delimiter, array $expectedHeaders): array
    {
        $raw = @file_get_contents($filePath);
        if ($raw === false) throw new \RuntimeException('无法读取文件');

        // 编码归一（沿用项目既有做法）
        $enc = mb_detect_encoding($raw, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        if ($enc && $enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);

        $data = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if (trim($line) === '') continue;
            $data[] = str_getcsv($line, $delimiter);
        }

        $res = $this->extractTable($data, 'CSV', $expectedHeaders);
        return [
            'headers'    => $res['headers'],
            'rows'       => $res['rows'],
            'sheet_name' => 'CSV',
            'total'      => count($res['rows']),
            'header_row' => $res['header_row'],
            'sheets'     => 1,
        ];
    }

    /** 从二维数组提取表（表头行探测 + 数据行清洗） */
    private function extractTable(array $data, string $sheetName, array $expectedHeaders): array
    {
        $empty = ['headers' => [], 'rows' => [], 'sheet_name' => $sheetName, 'header_row' => -1, 'score' => 0];
        if (empty($data)) return $empty;

        $bestRow      = -1;
        $bestHeaders  = [];
        $bestScore    = 0;
        $bestExpected = 0;

        foreach ($data as $i => $row) {
            $row = array_map(static fn($v) => trim((string)$v), (array)$row);
            $expectedHits = $this->countExpectedHits($row, $expectedHeaders);
            $keywordHits  = $this->countKeywordHits($row);
            $score        = $expectedHits * 2 + $keywordHits;
            if ($score > $bestScore) {
                $bestScore    = $score;
                $bestRow      = (int)$i;
                $bestHeaders  = $row;
                $bestExpected = $expectedHits;
            }
        }

        if ($bestRow < 0 || ($bestExpected === 0 && $bestScore < 2)) {
            foreach ($data as $i => $row) {
                $cells = array_filter(array_map(static fn($v) => trim((string)$v), (array)$row), static fn($v) => $v !== '');
                if (count($cells) >= 2) { $bestRow = (int)$i; $bestHeaders = (array)$row; break; }
            }
        }
        if ($bestRow < 0) return $empty;

        $headers = $this->cleanHeaders($bestHeaders);
        if (count(array_filter($headers, static fn($v) => $v !== '')) < 2) return $empty;

        $colCount = count($headers);
        $rows     = [];
        for ($i = $bestRow + 1, $n = count($data); $i < $n; $i++) {
            $row = (array)$data[$i];
            if ($this->isEmptyRow($row)) continue;
            if ($this->isSummaryRow($row)) continue;
            $clean = $this->cleanRow($row, $colCount);
            if ($clean !== null) $rows[] = $clean;
        }

        return ['headers' => $headers, 'rows' => $rows, 'sheet_name' => $sheetName, 'header_row' => $bestRow, 'score' => max($bestScore, 1)];
    }

    /** 模板期望表头命中数 */
    private function countExpectedHits(array $row, array $expectedHeaders): int
    {
        if (empty($expectedHeaders)) return 0;
        $hits = 0;
        foreach ($row as $cell) {
            if ($cell === '') continue;
            foreach ($expectedHeaders as $eh) {
                if (self::matchText($cell, (string)$eh)) { $hits++; break; }
            }
        }
        return $hits;
    }

    /** 通用关键词命中数（每单元格最多计 1；含仅识别字段如位号，仅用于表头行打分） */
    private function countKeywordHits(array $row): int
    {
        $hits = 0;
        foreach ($row as $cell) {
            if ($cell === '') continue;
            foreach (FieldRegistry::fieldKeywords() as $keywords) {
                $matched = false;
                foreach ($keywords as $kw) {
                    if (self::matchText($cell, $kw)) { $matched = true; break; }
                }
                if ($matched) { $hits++; break; }
            }
        }
        return $hits;
    }

    // ── 清洗工具 ───────────────────────────────────

    private function cleanHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            $h = preg_replace('/[\r\n]+/', ' ', trim((string)$h)) ?? '';
            $out[] = $h;
        }
        return $out;
    }

    private function cleanRow(array $row, int $expectedCols): ?array
    {
        $row = array_slice(array_values($row), 0, $expectedCols);
        while (count($row) < $expectedCols) $row[] = '';
        $row = array_map(static fn($v) => trim((string)$v), $row);
        return $this->isEmptyRow($row) ? null : $row;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            $cell = trim((string)$cell);
            if ($cell !== '' && $cell !== '0') return false;
        }
        return true;
    }

    private function isSummaryRow(array $row): bool
    {
        foreach ($row as $cell) {
            $cell = trim((string)$cell);
            if ($cell === '') continue;
            foreach (self::SUMMARY_KEYWORDS as $kw) {
                if (self::matchText($cell, $kw)) return true;
            }
        }
        return false;
    }

    /** 宽松文本匹配（实现统一在 FieldRegistry，保留本方法为既有调用方入口） */
    public static function matchText(string $a, string $b): bool
    {
        return FieldRegistry::matchText($a, $b);
    }
}
