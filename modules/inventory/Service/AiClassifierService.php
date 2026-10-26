<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use App\Core\Http\HttpClient;
use Modules\Category\Service\CategoryAutoClassifier;
use PDO;
use RuntimeException;
use Throwable;

/**
 * AI 自动分类服务
 *
 * 把选中的物料（型号 / 名称 / 品牌 / 封装 / 参数）交给大模型判定：
 *   product_type（商品类型）、brand（标准品牌名）、package（标准封装）
 * 回写 parts 后，再用 CategoryAutoClassifier 按「品牌/商品类型 → 封装格式」
 * 挂到两级分类树上。
 *
 * 兼容任何 OpenAI 风格接口（/chat/completions）：
 *   base_url 填到 /v1 即可，api_key / model 在系统设置里配。
 */
class AiClassifierService
{
    private const DEFAULT_BASE  = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const TIMEOUT       = 60;

    private PDO $db;
    private int $dataUid;
    private int $wsId;

    public function __construct(PDO $db, int $dataUid, int $wsId = 0)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId   = $wsId;
    }

    /**
     * @param int[] $partIds
     * @param array $opts ['overwrite'=>bool, 'batch'=>int]
     * @return array{checked:int,updated:int,failed:int,details:array}
     */
    public function classify(array $partIds, array $opts = []): array
    {
        $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0, 'details' => []];

        $base  = rtrim(trim((string)\getSetting('ai_base_url', self::DEFAULT_BASE)), '/');
        $key   = trim((string)\getSetting('ai_api_key', ''));
        $model = trim((string)\getSetting('ai_model', self::DEFAULT_MODEL));
        if ($key === '') {
            throw new RuntimeException('未配置 AI API Key：请到「系统设置 → AI 智能分类」填写');
        }
        if ($base === '') $base = self::DEFAULT_BASE;
        if ($model === '') $model = self::DEFAULT_MODEL;

        $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));
        if (empty($ids)) return $stats;

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, model, product_name, brand, package, product_type, parameters, parameters_text
                FROM parts WHERE id IN ($in) AND user_id=?";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge($ids, [$this->dataUid]));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $stats['checked'] = count($rows);

        $batchSize = max(1, min(50, (int)($opts['batch'] ?? 15)));
        $overwrite = !empty($opts['overwrite']);
        $updated   = [];

        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $items = $this->askAi($base, $key, $model, $chunk);
            foreach ($items as $it) {
                $i = (int)($it['i'] ?? 0);
                if ($i <= 0 || $i > count($chunk)) continue;
                $row = $chunk[$i - 1];
                $pid = (int)$row['id'];

                $set = [];
                $params = [];
                $fields = [];

                $ptype = trim((string)($it['product_type'] ?? ''));
                $brand = trim((string)($it['brand'] ?? ''));
                $pkg   = trim((string)($it['package'] ?? ''));

                if ($ptype !== '' && ($overwrite || trim((string)($row['product_type'] ?? '')) === '')) {
                    $set[] = 'product_type=?'; $params[] = $ptype; $fields[] = 'product_type';
                }
                if ($brand !== '' && ($overwrite || trim((string)($row['brand'] ?? '')) === '')) {
                    $set[] = 'brand=?'; $params[] = $brand; $fields[] = 'brand';
                }
                if ($pkg !== '' && ($overwrite || trim((string)($row['package'] ?? '')) === '')) {
                    $set[] = 'package=?'; $params[] = $pkg; $fields[] = 'package';
                }
                if (empty($set)) continue;

                $params[] = $pid;
                $this->db->prepare("UPDATE parts SET " . implode(',', $set) . " WHERE id=?")->execute($params);
                $updated[] = $pid;
                $stats['updated']++;
                $stats['details'][] = [
                    'id' => $pid, 'fields' => $fields, 'product_type' => $ptype,
                ];
            }
        }

        // 识别完成后按规则挂分类树
        if (!empty($updated)) {
            try {
                $cls = new CategoryAutoClassifier($this->db, $this->dataUid);
                $cls->classify($updated);
            } catch (Throwable $e) {
                error_log('[AiClassifierService] 挂分类失败: ' . $e->getMessage());
            }
        }
        return $stats;
    }

    // ── 调用大模型 ───────────────────────────────────────────────

    /**
     * @param array $chunk 物料数组
     * @return array 形如 [['i'=>1,'product_type'=>'电阻','brand'=>'Yageo','package'=>'0603'], ...]
     */
    private function askAi(string $base, string $key, string $model, array $chunk): array
    {
        $url = (str_contains($base, '/chat/completions'))
            ? $base
            : $base . '/chat/completions';

        $payload = [
            'model'       => $model,
            'temperature' => 0,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => "你是电子元器件分类专家。根据给定的元器件信息判断："
                               . "product_type（商品类型，如 电阻/电容/电感/集成电路/二极管/MOSFET/晶振/连接器/PCB/模块/传感器/LED 等，使用中文），"
                               . "brand（厂商标准英文名，如 Yageo/Murata/TI/STMicroelectronics），"
                               . "package（标准封装形式，如 0603/0805/SOIC-8/QFN-32/SOT-23/TO-220）。"
                               . "只输出 JSON 数组，长度与输入条数一致，每个元素形如 "
                               . '{"i":1,"product_type":"电阻","brand":"Yageo","package":"0603"}，不要任何其它文字。',
                ],
                [
                    'role'    => 'user',
                    'content' => json_encode($this->toPromptItems($chunk), JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];

        $json = HttpClient::postJson($url, $payload, [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ], self::TIMEOUT);

        if ($json === null) {
            throw new RuntimeException('AI 接口请求失败（检查 base_url / api_key / 网络）');
        }
        if (!empty($json['error'])) {
            $msg = $json['error']['message'] ?? '未知错误';
            throw new RuntimeException('AI 接口返回错误：' . (string)$msg);
        }
        $content = (string)($json['choices'][0]['message']['content'] ?? '');
        return $this->parseJsonArray($content);
    }

    private function toPromptItems(array $chunk): array
    {
        $items = [];
        $i = 0;
        foreach ($chunk as $row) {
            $i++;
            $params = trim((string)($row['parameters'] ?? ''));
            if ($params === '') $params = trim((string)($row['parameters_text'] ?? ''));
            $items[] = [
                'i'          => $i,
                'model'      => (string)($row['model'] ?? ''),
                'name'       => mb_substr((string)($row['product_name'] ?? ''), 0, 120),
                'brand'      => (string)($row['brand'] ?? ''),
                'package'    => (string)($row['package'] ?? ''),
                'parameters' => mb_substr($params, 0, 200),
            ];
        }
        return $items;
    }

    /** 从模型输出里稳健地抠出 JSON 数组 */
    private function parseJsonArray(string $content): array
    {
        $content = trim($content);
        if ($content === '') return [];

        // 去掉 ```json 包裹
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $m)) {
            $content = trim($m[1]);
        }
        // 截取第一个 [ 到最后一个 ]
        $start = strpos($content, '[');
        $end   = strrpos($content, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }
        $data = json_decode($content, true);
        if (!is_array($data)) return [];

        // 兼容模型返回单对象 {"result":[...]} 或直接数组
        if (isset($data['result']) && is_array($data['result'])) $data = $data['result'];
        if (isset($data[0]['i']) === false && isset($data['product_type'])) $data = [$data];

        $out = [];
        foreach ($data as $it) {
            if (!is_array($it)) continue;
            $out[] = [
                'i'            => (int)($it['i'] ?? 0),
                'product_type' => trim((string)($it['product_type'] ?? '')),
                'brand'        => trim((string)($it['brand'] ?? '')),
                'package'      => trim((string)($it['package'] ?? '')),
            ];
        }
        return $out;
    }
}
