// scan_decoder.js — 条码/二维码解码算法

var ScanDecoder = (function() {

    // ── 已知平台的 URL 模式 ──
    var URL_PATTERNS = [
        { regex: /lcsc\.com\/product[\w-]*\/([A-Za-z0-9_-]+)\.html/i, group: 1 },
        { regex: /szlcsc\.com\/product[\w-]*\/([A-Za-z0-9_-]+)\.html/i, group: 1 },
        { regex: /huaqiu\.com\/product[\w-]*\/([A-Za-z0-9_-]+)/i, group: 1 },
        { regex: /yunhan\.com\/product[\w-]*\/([A-Za-z0-9_-]+)/i, group: 1 },
        { regex: /\/product[\w-]*\/([A-Za-z0-9][A-Za-z0-9_-]{2,})/i, group: 1 },
        { regex: /[?&](?:part|partNo|part_no|sku|code)=([A-Za-z0-9_-]+)/i, group: 1 },
    ];

    function resolveAliases(aliases) {
        if (aliases && typeof aliases === 'object') return aliases;
        if (typeof window !== 'undefined' && window.ScanPageConfig && window.ScanPageConfig.qrAliases) {
            return window.ScanPageConfig.qrAliases;
        }
        return {};
    }

    /* 大小写不敏感地取 KV 值（去空白）；不存在返回 '' */
    function kvGet(fields, key) {
        if (!fields || !key) return '';
        var target = String(key).toLowerCase();
        for (var k in fields) {
            if (fields.hasOwnProperty(k) && String(k).toLowerCase() === target) {
                var v = fields[k];
                return (v === undefined || v === null) ? '' : String(v).trim();
            }
        }
        return '';
    }

    /* 按别名序列取第一个非空值 */
    function kvPick(fields, keys) {
        keys = keys || [];
        for (var i = 0; i < keys.length; i++) {
            var v = kvGet(fields, keys[i]);
            if (v !== '') return v;
        }
        return '';
    }

    function parseKvFormat(text) {
        var match = text.match(/^\{(.+)\}$/);
        if (!match) return null;
        var inner = match[1];
        var fields = {};
        var pairs = inner.split(',');
        for (var i = 0; i < pairs.length; i++) {
            var kv = pairs[i].split(':');
            if (kv.length >= 2) {
                var key = kv[0].trim();
                var val = kv.slice(1).join(':').trim();
                fields[key] = val;
            }
        }
        return fields;
    }

    /* 解析内部物料二维码 */
    function parseInternalQrCode(text) {
        var fields = parseKvFormat(text);
        if (!fields) return null;
        // 必须同时有 id（内部物料ID）和 type（操作类型）才认定为内部二维码
        if (!fields.id || !fields.type) return null;
        // type 必须是 in 或 out
        if (fields.type !== 'in' && fields.type !== 'out') return null;
        var internalId = parseInt(fields.id, 10);
        if (isNaN(internalId) || internalId <= 0) return null;
        return {
            type: 'internal_qr',
            internalId: internalId,
            platformCode: fields.pid || '',  // 平台代码（字符串，如 lcsc/huaqiu）
            partNo: '',
            model: fields.model || '',
            qty: parseInt(fields.qty, 10) || 1,
            scanType: fields.type,  // 'in' 或 'out'
            raw: text
        };
    }

    /* 解析平台二维码（别名表驱动，不硬编码任何平台的字段名） */
    function parsePlatformQrCode(text, aliases) {
        var fields = parseKvFormat(text);
        if (!fields) return null;
        var table = resolveAliases(aliases);
        for (var code in table) {
            if (!table.hasOwnProperty(code)) continue;
            var def = table[code] || {};
            var need = (def.match || []).slice();
            var hit = true;
            for (var i = 0; i < need.length; i++) {
                if (kvGet(fields, need[i]) === '') { hit = false; break; }
            }
            if (!hit) continue;
            var pick = def.fields || {};
            return {
                type: 'platform_qr',
                platformCode: String(code),
                orderNo: kvPick(fields, pick.order_no),
                partNo: kvPick(fields, pick.platform_part_no),
                model: kvPick(fields, pick.model),
                qty: parseInt(kvPick(fields, pick.quantity), 10) || 1,
                scanType: null,
                internalId: 0,
                fields: fields,        // 原始键值：随提交带到服务端复算（平台字段因平台而异）
                raw: text
            };
        }
        return null;
    }

    /* 主解码函数 */
    function decode(rawText, aliases) {
        if (!rawText || typeof rawText !== 'string') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText || '', autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
        }

        var text = rawText.trim();
        if (text === '') {
            return { type: 'empty', partNo: '', model: '', qty: 1, orderNo: '', source: 'empty', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
        }

        // 1. 优先解析内部物料二维码
        var internalResult = parseInternalQrCode(text);
        if (internalResult) {
            return {
                type: 'internal_qr',
                partNo: '',
                internalId: internalResult.internalId,
                platformCode: internalResult.platformCode,
                model: internalResult.model,
                qty: internalResult.qty,
                scanType: internalResult.scanType,
                orderNo: '',
                source: 'internal_qr',
                raw: rawText,
                fields: null,
                autoAction: internalResult.scanType === 'out' ? 'scan_out' : 'scan_in'
            };
        }

        // 2. 解析平台二维码（别名表驱动；平台码一律 = 收货入库）
        var platformResult = parsePlatformQrCode(text, aliases);
        if (platformResult) {
            return {
                type: 'platform_qr',
                partNo: platformResult.partNo,
                model: platformResult.model,
                qty: platformResult.qty,
                orderNo: platformResult.orderNo,
                platformCode: platformResult.platformCode,
                fields: platformResult.fields,
                source: 'platform_qr',
                raw: rawText,
                autoAction: 'scan_in',
                scanType: null,
                internalId: 0
            };
        }

        // 3. 尝试从 URL 中提取
        if (text.indexOf('http') === 0 || text.indexOf('www.') === 0) {
            for (var i = 0; i < URL_PATTERNS.length; i++) {
                var m = text.match(URL_PATTERNS[i].regex);
                if (m && m[URL_PATTERNS[i].group]) {
                    return { type: 'url', partNo: m[URL_PATTERNS[i].group], model: '', qty: 1, orderNo: '', source: 'url', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
                }
            }
            var pathMatch = text.match(/\/([A-Za-z0-9][A-Za-z0-9_-]{3,})(?:\.\w+)?(?:\?|$)/);
            if (pathMatch && pathMatch[1]) {
                return { type: 'url', partNo: pathMatch[1], model: '', qty: 1, orderNo: '', source: 'url-path', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
            }
        }

        var labelMatch = text.match(/(?:商品编号|产品编号|料号|part|sku|code|编号|料号)\s*[:：]\s*([A-Za-z0-9_-]+)/i);
        if (labelMatch && labelMatch[1]) {
            return { type: 'label', partNo: labelMatch[1], model: '', qty: 1, orderNo: '', source: 'label', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
        }

        // 5. 纯编号
        if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(text)) {
            return { type: 'direct', partNo: text, model: '', qty: 1, orderNo: '', source: 'direct', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
        }

        // 6. 多行文本
        var lines = text.split(/[\r\n]+/);
        for (var k = 0; k < lines.length; k++) {
            var line = lines[k].trim();
            if (/^[A-Za-z0-9][A-Za-z0-9_-]{1,}$/.test(line)) {
                return { type: 'multiline', partNo: line, model: '', qty: 1, orderNo: '', source: 'multiline', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: null };
            }
        }

        // 7. 兜底：认不出的 KV 载荷（如未内置 / 未导入的平台码）保留原始键值，
        // 交由服务端按该平台二维码词典复算（客户端不做最终映射）
        var cleaned = text.replace(/[\r\n\t]/g, ' ').replace(/\s+/g, ' ').trim();
        var kv = parseKvFormat(text);
        return { type: 'fallback', partNo: cleaned, model: '', qty: 1, orderNo: '', source: 'fallback', raw: rawText, autoAction: null, scanType: null, internalId: 0, platformCode: '', fields: (kv && Object.keys(kv).length) ? kv : null };
    }

    /* 判断解码结果能否直接执行库存操作 */
    function isExecutable(result) {
        return !!result && (result.type === 'internal_qr' || result.type === 'platform_qr');
    }

    /* 把解码结果折叠为一次「扫描意图」 */
    function toIntent(rawText, aliases) {
        var d = decode(rawText, aliases);
        var intent = {
            kind: 'resolve',
            executable: isExecutable(d),
            internalId: d.internalId || 0,
            partNo: d.partNo || '',
            model: d.model || '',
            qty: d.qty || 1,
            orderNo: d.orderNo || '',
            platformCode: d.platformCode || '',
            fields: d.fields || null,
            scanType: d.scanType || null,
            keyword: '',
            // true 表示无法判定这串是编号还是型号，两者都要拿去查
            ambiguousKind: false,
            decoded: d
        };

        if (d.type === 'empty') {
            intent.kind = 'empty';
            return intent;
        }
        // 内部标签：关键词取型号，其次内部ID
        if (d.type === 'internal_qr') {
            intent.keyword = d.model || ('#' + d.internalId);
            return intent;
        }
        // 平台二维码：商品编号确定，型号可能为空
        if (d.type === 'platform_qr') {
            intent.keyword = d.partNo || d.model || '';
            return intent;
        }
        // 兜底 KV 载荷：客户端无从映射，整串键值交给服务端按词典复算 ——
        if (d.type === 'fallback' && d.fields) {
            intent.partNo = '';
            intent.model = '';
            intent.keyword = '';
            return intent;
        }
        // 其余格式（纯编号 / URL / 文本标签 / 兜底）：这串内容既可能是商品编号
        // 也可能是型号，单从文本无从判断 —— 两个字段都带上，让后端依次匹配，
        // 都匹配不到再交给用户确认类别。
        intent.ambiguousKind = true;
        intent.keyword = d.partNo || '';
        intent.model = intent.keyword;
        return intent;
    }

    /* 验证扫码结果是否有效（含内部二维码、平台二维码、纯编号） */
    function isValidPartNo(partNo) {
        if (!partNo || partNo.length < 2 || partNo.length > 100) return false;
        if (/[\s\u4e00-\u9fa5]/.test(partNo)) return false;
        return true;
    }

    /* 处理扫码结果：解码 + 填入输入框 */
    function process(rawText, inputEl) {
        var result = decode(rawText, null);
        var valid = isValidPartNo(result.partNo);
        if (valid && inputEl) {
            inputEl.value = result.partNo;
        }
        result.valid = valid;
        return result;
    }

    return {
        decode: decode,
        toIntent: toIntent,
        isExecutable: isExecutable,
        isValidPartNo: isValidPartNo,
        process: process,
        parseInternalQrCode: parseInternalQrCode,
        parsePlatformQrCode: parsePlatformQrCode
    };
})();
