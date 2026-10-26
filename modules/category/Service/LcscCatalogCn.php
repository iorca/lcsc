<?php
declare(strict_types=1);

namespace Modules\Category\Service;

use App\Core\Http\HttpClient;
use PDO;
use Throwable;

/**
 * 立创商城**中文类目**服务
 *
 * 立创有两套类目：
 *   - wmsc 英文树：https://wmsc.lcsc.com/ftps/wm/home/category（细，2000+ 节点，只有英文）
 *   - szlcsc 中文树：https://www-api.szlcsc.com/resource/catalog/list（粗，657 节点，**中英双份**）
 *
 * 两套的 catalogId 是**同一套编号**（已验证：商品详情 parentCatalogId=938
 * 在中文树里就是「物联网/通信模块」），所以可以用商品详情的 id 直接查中文名。
 *
 * 取名优先级（二级类目 → 自身类目 → 英文名精确匹配 → 关键词兜底 → 英文原文）：
 *   1. parentCatalogId 命中中文树 → 形如「物联网/通信模块」（立创官方中文类目）
 *   2. catalogId 命中中文树
 *   3. 英文名在中文树里精确命中
 *   4. 内置关键词表（ProductTypeMap）
 *   5. 保留英文原文
 *
 * 中文树会缓存到 external_cache（30 天），断网时自动退回关键词表。
 */
class LcscCatalogCn
{
    private const API_URL     = 'https://www-api.szlcsc.com/resource/catalog/list';
    private const CACHE_KEY   = 'lcsc:catalog:cn';
    private const CACHE_TTL   = 2592000;   // 30 天
    private const TIMEOUT     = 20;

    /** id → 中文类目 */
    private static ?array $byId = null;
    /** 英文名（小写）→ 中文类目 */
    private static ?array $byEn = null;
    /** 已尝试拉取过（失败则不再重复拉，直接走兜底） */
    private static bool $tried = false;

    /**
     * 取中文类目名
     *
     * @param int    $catalogId     商品自身类目 id（细）
     * @param int    $parentCatalogId 上用类目 id（中文树二级，粗，与中文站侧栏一致）
     * @param string $enName        英文类目名（兜底用）
     */
    public static function name(int $catalogId = 0, int $parentCatalogId = 0, string $enName = ''): string
    {
        self::load();

        if (self::$byId !== null) {
            if ($parentCatalogId > 0 && isset(self::$byId[$parentCatalogId])) {
                return (string)self::$byId[$parentCatalogId];
            }
            if ($catalogId > 0 && isset(self::$byId[$catalogId])) {
                return (string)self::$byId[$catalogId];
            }
            $en = strtolower(trim($enName));
            if ($en !== '' && isset(self::$byEn[$en])) {
                return (string)self::$byEn[$en];
            }
        }

        // 兜底：内置关键词表
        $zh = ProductTypeMap::translate($enName);
        return $zh !== '' ? $zh : $enName;
    }

    /** 中文树是否可用（用于界面提示） */
    public static function available(): bool
    {
        self::load();
        return self::$byId !== null && !empty(self::$byId);
    }

    // ── 拉取与索引 ───────────────────────────────────────────────

    private static function load(): void
    {
        if (self::$tried) return;
        self::$tried = true;

        $raw = self::fetch();
        if ($raw === null) return;

        $byId = [];
        $byEn = [];
        $walk = static function (array $list) use (&$walk, &$byId, &$byEn): void {
            foreach ($list as $n) {
                if (!is_array($n)) continue;
                $id  = (int)($n['catalogId'] ?? 0);
                $cn  = trim((string)($n['catalogName'] ?? ''));
                $en  = trim((string)($n['catalogNameEn'] ?? ''));
                if ($id > 0 && $cn !== '') $byId[$id] = $cn;
                if ($en !== '' && $cn !== '') {
                    $byEn[strtolower($en)] = $cn;
                }
                if (!empty($n['sonCatalogList']) && is_array($n['sonCatalogList'])) {
                    $walk($n['sonCatalogList']);
                }
            }
        };
        $walk(array_values($raw));

        if (!empty($byId)) {
            self::$byId = $byId;
            self::$byEn = $byEn;
        }
    }

    /** 拉取中文类目树（带 external_cache 缓存） */
    private static function fetch(): ?array
    {
        $db = null;
        try {
            $db = \getDB();
        } catch (Throwable $e) {
            $db = null;
        }

        // 1) 读缓存
        if ($db !== null) {
            try {
                $st = $db->prepare("SELECT payload, expires_at FROM external_cache WHERE cache_key=?");
                $st->execute([self::CACHE_KEY]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row && (empty($row['expires_at']) || strtotime((string)$row['expires_at']) > time())) {
                    $cached = json_decode((string)$row['payload'], true);
                    if (is_array($cached)) return $cached;
                }
            } catch (Throwable $e) {
                // 缓存表不可用时直接走接口
            }
        }

        // 2) 调接口
        $json = HttpClient::getJson(self::API_URL, [
            'Accept'     => 'application/json, text/plain, */*',
            'Referer'    => 'https://www.szlcsc.com/catalog.html',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                          . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ], self::TIMEOUT);
        if ($json === null) return null;

        $result = $json['result'] ?? null;
        if (!is_array($result) || empty($result)) return null;

        // 3) 写缓存
        if ($db !== null) {
            try {
                $db->prepare(
                    "INSERT INTO external_cache (cache_key, payload, fetched_at, expires_at)
                     VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                     ON DUPLICATE KEY UPDATE payload=VALUES(payload), fetched_at=NOW(), expires_at=VALUES(expires_at)"
                )->execute([self::CACHE_KEY, json_encode($result, JSON_UNESCAPED_UNICODE), self::CACHE_TTL]);
            } catch (Throwable $e) {
                // 缓存写失败不影响主流程
            }
        }
        return $result;
    }
}
