<?php
declare(strict_types=1);

namespace App\Core\Http;

/** 快递公司编码映射 —— 把订单里存的中文名翻译成快递 API 要的编码 */
final class ExpressCarrier
{
    /** 别名（归一化后）→ 接口编码 —— **唯一事实来源** */
    private const CODE = [
        // ── 国内主流 ──
        '顺丰' => 'shunfeng', '顺丰速运' => 'shunfeng', '顺丰快递' => 'shunfeng',
        '顺丰速递' => 'shunfeng', 'sf' => 'shunfeng', 'shunfeng' => 'shunfeng',
        '中通' => 'zhongtong', '中通快递' => 'zhongtong', '中通速递' => 'zhongtong',
        'zto' => 'zhongtong', 'zhongtong' => 'zhongtong',
        '圆通' => 'yuantong', '圆通速递' => 'yuantong', '圆通速递' => 'yuantong',
        'yto' => 'yuantong', 'yuantong' => 'yuantong',
        '韵达' => 'yunda', '韵达快递' => 'yunda', '韵达速递' => 'yunda', 'yunda' => 'yunda',
        '申通' => 'shentong', '申通快递' => 'shentong', '申通速递' => 'shentong',
        'sto' => 'shentong', 'shentong' => 'shentong',
        '极兔' => 'jtexpress', '极兔速递' => 'jtexpress', '极兔快递' => 'jtexpress',
        'jt' => 'jtexpress', 'jitu' => 'jtexpress', 'jtexpress' => 'jtexpress',
        '京东' => 'jd', '京东快递' => 'jd', '京东物流' => 'jd',
        'jd' => 'jd', 'jingdong' => 'jd',
        '邮政' => 'ems', '中国邮政' => 'ems', '中国邮政ems' => 'ems', 'ems' => 'ems',
        '德邦' => 'debangkuaidi', '德邦快递' => 'debangkuaidi', '德邦物流' => 'debangkuaidi',
        'debang' => 'debangkuaidi', 'debangkuaidi' => 'debangkuaidi',
        // ── 国际 ──
        'ups' => 'ups', '联合包裹' => 'ups',
        'fedex' => 'fedex', '联邦快递' => 'fedex', '联邦' => 'fedex',
        'dhl' => 'dhl', '敦豪' => 'dhl',
        'usps' => 'usps', '美国邮政' => 'usps',
    ];

    /** 编码 → 中文名（用于展示，如「顺丰速运」） */
    private const NAME = [
        'zhongtong'    => '中通快递',
        'yuantong'     => '圆通速递',
        'yunda'        => '韵达快递',
        'shentong'     => '申通快递',
        'jtexpress'    => '极兔速递',
        'shunfeng'     => '顺丰速运',
        'jd'           => '京东快递',
        'ems'          => 'EMS',
        'debangkuaidi' => '德邦快递',
        'ups'          => 'UPS',
        'fedex'        => 'FedEx',
        'dhl'          => 'DHL Express',
        'usps'         => 'USPS',
    ];

    /** 中文名（或别名）→ 接口编码 */
    public static function toCode(string $name): string
    {
        return self::lookup($name);
    }

    /** 编码 → 中文名（用于展示，如「顺丰速运」） */
    public static function toName(string $code): string
    {
        $c = strtolower(trim($code));
        return self::NAME[$c] ?? $code;
    }

    /** 归一化公司名：去括号内容、去空格、转小写 */
    private static function normalize(string $s): string
    {
        $s = trim($s);
        // 去掉括号及其内容（顺丰（SF） → 顺丰）
        $s = (string)preg_replace('/[（(\[【][^）)\]】]*[）)\]】]/u', '', $s);
        // 去掉所有空白（含全角空格）
        $s = (string)preg_replace('/[\s\x{3000}]+/u', '', $s);
        return mb_strtolower($s, 'UTF-8');
    }

    /** 查编码：整串 →拆「中文+尾部代码」两段各试 */
    private static function lookup(string $name): string
    {
        $k = self::normalize($name);
        if ($k === '') {
            return '';
        }
        if (isset(self::CODE[$k])) {
            return self::CODE[$k];
        }
        // 拆尾部连续的英文字母：圆通速递yto → 中文「圆通速递」+ 尾部「yto」
        if (preg_match('/^(.+?)([a-z]{2,4})$/u', $k, $m)) {
            if (isset(self::CODE[$m[1]])) {
                return self::CODE[$m[1]];
            }
        }
        return '';
    }

    /** 全部已收录的编码（供设置页下拉，避免用户手打错） */
    public static function all(): array
    {
        $out = [];
        foreach (self::NAME as $code => $name) {
            $out[] = ['code' => $code, 'name' => $name];
        }
        return $out;
    }

    /** 常见快递单号前缀特征（用于**猜测**公司，仅在没有中文名时用） */
    public static function guessByNumber(string $trackingNo): string
    {
        $n = strtoupper(preg_replace('/\s+/', '', $trackingNo) ?? '');
        if ($n === '') {
            return '';
        }
        // 顺丰单号形如 SF/SF0/SF1... 开头（12~15 位数字）
        if (preg_match('/^SF\d{10,}$/', $n)) return 'shunfeng';
        // 京东 JDVA/JDXG/JDCN 开头
        if (preg_match('/^JD[A-Z]{2}\d+$/', $n)) return 'jd';
        // 中通 ZTO 开头
        if (preg_match('/^ZT\d{10,}$/', $n)) return 'zhongtong';
        return '';
    }
}
