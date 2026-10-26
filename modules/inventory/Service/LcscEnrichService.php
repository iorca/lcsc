<?php
declare(strict_types=1);

namespace Modules\Inventory\Service;

use App\Core\Http\HttpClient;
use PDO;
use Throwable;

/**
 * 立创商城商品信息补全服务
 *
 * 用途：订单详情类表格里**没有「商品类型」列**，导致 parts.product_type 为空、
 * 无法按类型建分类。本服务按 platform_part_no（立创 C 编号）批量回查立创
 * 商品详情，把商品类型（catalogName）、品牌、封装等字段补齐。
 *
 * 接口（与 wmsc 商城前端一致，公开可访问）：
 *   GET https://wmsc.lcsc.com/ftps/wm/product/detail?productCode=C123456
 *   → { "result": { productCode, productModel, catalogName, brandNameEn,
 *                   encapStandard, paramVOList[], ... } }
 *
 * 结果写入 external_cache 缓存表（7 天），避免重复打接口。
 */
class LcscEnrichService
{
    private const API_URL     = 'https://wmsc.lcsc.com/ftps/wm/product/detail?productCode=';
    // 缓存 key 带版本号：normalize() 的返回结构变化时（如新增 image 字段），
    // 改这里即可让旧缓存自动失效并重拉，不必手动清库。
    private const CACHE_PREFIX = 'lcsc:detail:v2:';
    private const CACHE_TTL    = 604800;   // 7 天
    private const TIMEOUT      = 10;

    private PDO $db;
    private int $dataUid;
    private int $wsId;

    /** 单次运行内的命中缓存（避免同批次重复请求） */
    private array $memo = [];

    public function __construct(PDO $db, int $dataUid, int $wsId = 0)
    {
        $this->db      = $db;
        $this->dataUid = $dataUid;
        $this->wsId   = $wsId;
    }

    /**
     * 批量补全物料的缺失字段
     *
     * @param int[] $partIds
     * @param array $opts [
     *   'overwrite' => false,   // 已有的 product_type 是否也覆盖
     *   'limit'     => 0,       // 0 = 不限
     * ]
     * @return array{checked:int,updated:int,failed:int,skipped:int,details:array}
     */
    public function enrich(array $partIds, array $opts = []): array
    {
        $overwrite = !empty($opts['overwrite']);
        $limit     = (int)($opts['limit'] ?? 0);

        $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
        if (empty($partIds)) return $stats;

        $ids = array_values(array_unique(array_filter(array_map('intval', $partIds))));
        if (empty($ids)) return $stats;

        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, platform_id, platform_part_no, product_type, brand, package, image, parameters
                FROM parts WHERE id IN ($in) AND user_id=?";
        if ($this->wsId > 0) $sql .= " AND workspace_id=?";
        $st = $this->db->prepare($sql);
        $st->execute($this->wsId > 0
            ? array_merge($ids, [$this->dataUid, $this->wsId])
            : array_merge($ids, [$this->dataUid]));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($limit > 0 && $stats['checked'] >= $limit) break;
            $stats['checked']++;

            $pid  = (int)$r['id'];
            $code = self::normalizeCode((string)($r['platform_part_no'] ?? ''));
            if ($code === '') { $stats['skipped']++; continue; }

            // 只处理立创平台物料
            if (!$this->isLcscPlatform((int)($r['platform_id'] ?? 0))) { $stats['skipped']++; continue; }

            $detail = $this->fetchDetail($code);
            if ($detail === null) {
                $stats['failed']++;
                $stats['details'][] = ['id' => $pid, 'code' => $code, 'msg' => '立创接口未返回数据'];
                continue;
            }

            $set    = [];
            $params = [];
            $fields = [];

            if (!$overwrite && trim((string)($r['product_type'] ?? '')) !== '') {
                // 已有类型且不覆盖 → 仍可补其它空字段
            } elseif (!empty($detail['product_type'])) {
                $set[]    = 'product_type=?';
                $params[] = \Modules\Category\Service\LcscCatalogCn::name(
                    (int)($detail['catalog_id'] ?? 0),
                    (int)($detail['parent_catalog_id'] ?? 0),
                    (string)$detail['product_type']
                );
                $fields[] = 'product_type';
            }

            if (trim((string)($r['brand'] ?? '')) === '' && !empty($detail['brand'])) {
                $set[]    = 'brand=?';
                $params[] = $detail['brand'];
                $fields[] = 'brand';
            }
            if (trim((string)($r['package'] ?? '')) === '' && !empty($detail['package'])) {
                $set[]    = 'package=?';
                $params[] = $detail['package'];
                $fields[] = 'package';
            }
            // 立创商品图：用于卡片封面 / 列表悬停预览
            // 直接下载到本地 uploads/parts/，parts.image 存相对路径，
            // 不依赖立创图床（外链在内网/断网/限流时不可用）。
            if (trim((string)($r['image'] ?? '')) === '' && !empty($detail['image'])) {
                $localPath = $this->localizeImage((string)$detail['image'], $pid, $code);
                if ($localPath !== null) {
                    $set[]    = 'image=?';
                    $params[] = $localPath;
                    $fields[] = 'image';
                }
            }

            // 立创参数表（paramVOList，中文「名: 值」）→ parts.parameters（JSON）
            // 这是列表「描述」区的数据源：立创商品页的参数表。
            // parameters_text 保留导入原值不动（那是 Excel 里的「参数」列）。
            if (!empty($detail['params']) && trim((string)($r['parameters'] ?? '')) === '') {
                $set[]    = 'parameters=?';
                $params[] = json_encode($detail['params'], JSON_UNESCAPED_UNICODE);
                $fields[] = 'parameters';
            }

            if (empty($set)) { $stats['skipped']++; continue; }

            $params[] = $pid;
            $this->db->prepare("UPDATE parts SET " . implode(',', $set) . " WHERE id=?")->execute($params);
            $stats['updated']++;
            $stats['details'][] = [
                'id'      => $pid,
                'code'    => $code,
                'fields'  => $fields,
                'product_type' => $detail['product_type'],
            ];
        }
        return $stats;
    }

    /**
     * 查立创商品详情（带 external_cache 缓存）
     * @return array|null ['product_type','brand','package','model','name','params']
     */
    public function fetchDetail(string $code): ?array
    {
        $code = self::normalizeCode($code);
        if ($code === '') return null;
        if (isset($this->memo[$code])) return $this->memo[$code];

        // 1) 读缓存
        $ck = self::CACHE_PREFIX . $code;
        try {
            $st = $this->db->prepare("SELECT payload, expires_at FROM external_cache WHERE cache_key=?");
            $st->execute([$ck]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (empty($row['expires_at']) || strtotime((string)$row['expires_at']) > time())) {
                $cached = json_decode((string)$row['payload'], true);
                if (is_array($cached)) {
                    $this->memo[$code] = $cached;
                    return $cached;
                }
            }
        } catch (Throwable $e) {
            // 缓存表不可用时直接走接口
        }

        // 2) 调接口
        $url  = self::API_URL . rawurlencode($code);
        $json = HttpClient::getJson($url, self::headers(), self::TIMEOUT);
        if ($json === null) return null;

        $raw = $json['result'] ?? $json['data'] ?? null;
        if (!is_array($raw)) return null;
        if (empty($raw['productCode']) && empty($raw['productModel'])) return null;

        $detail = self::normalize($raw);

        // 3) 写缓存
        try {
            $this->db->prepare(
                "INSERT INTO external_cache (cache_key, payload, fetched_at, expires_at)
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                 ON DUPLICATE KEY UPDATE payload=VALUES(payload), fetched_at=NOW(), expires_at=VALUES(expires_at)"
            )->execute([$ck, json_encode($detail, JSON_UNESCAPED_UNICODE), self::CACHE_TTL]);
        } catch (Throwable $e) {
            // 缓存写失败不影响主流程
        }

        $this->memo[$code] = $detail;
        return $detail;
    }

    /** 立创接口需要的请求头（无 Cookie 时公开接口同样可用） */
    private static function headers(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                          . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept'     => 'application/json, text/plain, */*',
            'Referer'    => 'https://www.szlcsc.com/',
        ];
    }

    /** 把立创原始字段归一化成本项目字段 */
    private static function normalize(array $raw): array
    {
        $params = [];
        $list = $raw['paramVOList'] ?? $raw['productParams'] ?? $raw['params'] ?? [];
        if (is_array($list)) {
            foreach ($list as $p) {
                if (!is_array($p)) continue;
                $n = $p['paramName'] ?? $p['name'] ?? $p['param_name'] ?? '';
                $v = $p['paramValue'] ?? $p['value'] ?? $p['param_value'] ?? null;
                if ($n !== '' && $v !== null) $params[(string)$n] = (string)$v;
            }
        }
        // 立创商品图（productImages 数组首图）——用于卡片封面 / 悬停预览
        $image = '';
        if (!empty($raw['productImages']) && is_array($raw['productImages'])) {
            $image = (string)($raw['productImages'][0] ?? '');
        }
        if ($image === '') {
            $image = (string)($raw['image'] ?? '');
        }
        return [
            // 保留立创英文原值 + 类目 id：中文类目名在 enrich() 回写时按
            // 「二级类目 id → 英文名精确匹配 → 关键词表」降级取，
            // 这样中文类目树更新后无需清缓存即刻生效。
            'product_type'      => (string)($raw['catalogName'] ?? $raw['categoryName'] ?? ''),
            'catalog_id'        => (int)($raw['catalogId'] ?? 0),
            'parent_catalog_id' => (int)($raw['parentCatalogId'] ?? 0),
            'image'             => $image,
            'brand'        => (string)($raw['brandNameEn'] ?? $raw['brandName'] ?? $raw['brand'] ?? ''),
            'package'      => (string)($raw['encapStandard'] ?? $raw['encapsulation'] ?? $raw['package'] ?? ''),
            'model'        => (string)($raw['productModel'] ?? $raw['model'] ?? ''),
            'name'         => (string)($raw['productNameEn'] ?? $raw['productName'] ?? ''),
            'params'       => $params,
        ];
    }

    /** 立创编号规范化：123456 → C123456 */
    public static function normalizeCode(string $s): string
    {
        $s = strtoupper(trim($s));
        if ($s === '') return '';
        if (preg_match('/^C\d+$/', $s)) return $s;
        if (preg_match('/^\d+$/', $s)) return 'C' . $s;
        return '';
    }

    /** 该 platform_id 是否为立创商城 */
    private function isLcscPlatform(int $platformId): bool
    {
        if ($platformId <= 0) return false;
        try {
            $st = $this->db->prepare("SELECT code FROM platforms WHERE id=?");
            $st->execute([$platformId]);
            $code = (string)$st->fetchColumn();
            return $code === 'lcsc';
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 把立创图床的图片下载到本地，返回 uploads/ 下的相对路径
     * （下载失败返回 null：此时不写 image，物料保持无图，不影响其它字段补全）
     */
    private function localizeImage(string $url, int $partId, string $code): ?string
    {
        if ($url === '') return null;
        $loc = new PartImageLocalizer($this->db, $this->dataUid, $this->wsId);
        return $loc->downloadOne($url, $partId, $code);
    }
}
