<?php
declare(strict_types=1);
/** 系统监控 Tab（仅主管理员 / 团队版） */

if (!\isPrimaryAdmin() || !hasFeature('multi_user') || !$monitorData) return;

// ── 视图辅助函数（仅本视图使用） ──
if (!function_exists('monFmtSize')) {
    function monFmtSize($bytes): string {
        $b = (int)$bytes;
        if ($b >= 1099511627776) return round($b / 1099511627776, 2) . ' TB';
        if ($b >= 1073741824)    return round($b / 1073741824, 2) . ' GB';
        if ($b >= 1048576)       return round($b / 1048576, 1) . ' MB';
        if ($b >= 1024)          return round($b / 1024, 1) . ' KB';
        return $b . ' B';
    }
    /** 资源占用百分比（封顶 100） */
    function monPct($used, $total): float {
        return $total > 0 ? min(100, round($used / $total * 100, 1)) : 0.0;
    }
    /** 占用率对应语义色：<70 绿 / 70~90 黄 / >=90 红 */
    function monMeterVar($pct): string {
        return $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--yellow)' : 'var(--green)');
    }
    /** 趋势柱状图最大值（避免除 0） */
    function monTrendMax(array $rows): int {
        $max = 1;
        foreach ($rows as $r) { $max = max($max, (int)$r['total_visits']); }
        return $max;
    }
    /** 近 24h 登录时段扇形几何（4 小时一段，共 6 段） */
    function monLoginSlots(array $slots): array {
        $R_MIN = 34.0;
        $R_MAX = 88.0;
        $HUB   = 27.0;           // 中心留白圆半径：盖住扇形根部，供中心文字排版
        $GAP   = 1.6;             // 相邻扇形之间的角度间隙（度）
        $STEP  = 360 / 6;         // 每段 60°
        $max = 1;
        foreach ($slots as $c) { $max = max($max, (int)$c); }
        $out = [];
        foreach ($slots as $i => $cnt) {
            $cnt = (int)$cnt;
            $ratio = $cnt / $max;                        // 0~1
            $from = -90 + $i * $STEP + $GAP / 2;         // 12 点方向顺时针
            $to   = -90 + ($i + 1) * $STEP - $GAP / 2;
            $out[] = [
                'label' => sprintf('%02d:00–%02d:00', $i * 4, $i * 4 + 4),
                'from'  => $from,
                'to'    => $to,
                'r'     => $R_MIN + ($R_MAX - $R_MIN) * $ratio,
                'hub'   => $HUB,
                'cnt'   => $cnt,
                'pct'   => $ratio * 100,
            ];
        }
        return $out;
    }
}

$si         = $monitorData['serverInfo'];
$diskTotal  = (int)($si['disk_total'] ?? 0);
$diskFree   = (int)($si['disk_free'] ?? 0);
$diskUsed   = $diskTotal > 0 ? $diskTotal - $diskFree : 0;
$diskPct    = monPct($diskUsed, $diskTotal);
$memTotal   = (int)($si['mem_total'] ?? 0);
$memUsed    = (int)($si['mem_used'] ?? 0);
$memPct     = monPct($memUsed, $memTotal);

$trendMax    = monTrendMax($monitorData['visitTrend']);
$today       = date('Y-m-d');
$peakVisit   = 0;
foreach ($monitorData['visitTrend'] as $vt) { $peakVisit = max($peakVisit, (int)$vt['total_visits']); }

$loginOk  = (int)($monitorData['loginStats']['success_count'] ?? 0);
$loginBad = (int)($monitorData['loginStats']['fail_count'] ?? 0);
$loginAll = $loginOk + $loginBad;
$okDeg    = $loginAll > 0 ? round($loginOk / $loginAll * 360) : 0;
$okRate   = $loginAll > 0 ? round($loginOk / $loginAll * 100, 1) : 0;

$loginSlots = monLoginSlots($monitorData['loginSlots'] ?? array_fill(0, 6, 0));
$slotTotal  = 0;
foreach ($loginSlots as $ls) { $slotTotal += $ls['cnt']; }

$activeUserMax = 1;
foreach ($monitorData['activeUsers'] as $au) { $activeUserMax = max($activeUserMax, (int)$au['login_count']); }
?>

<div class="page-header">
    <h2 class="mon-page-title">系统监控面板</h2>
    <div class="mon-toolbar">
        <span class="mon-updated">更新于 <?= date('H:i:s') ?></span>
        <button type="button" class="btn btn-ghost btn-sm" id="monRefreshBtn">刷新</button>
        <div class="mon-view-switch" role="tablist">
            <button type="button" class="mon-view-btn active" data-view="list" role="tab">列表视图</button>
            <button type="button" class="mon-view-btn" data-view="dash" role="tab">大屏视图</button>
        </div>
    </div>
</div>

<!-- ═══════════ 视图一：列表式 ═══════════ -->
<div id="monitorViewList">

<!-- 统计卡：按「今日 / 登录 / 累计」三组语义分栏 -->
<div class="mon-group">
    <div class="mon-group-head">
        <h3 class="mon-group-title">今日实时</h3>
        <span class="mon-group-rule"></span>
    </div>
    <div class="mon-stat-grid">
        <div class="stat-card c-blue">
            <div class="stat-label">访问次数</div>
            <div class="stat-value"><?=number_format((int)$monitorData['todayStats']['total_visits'])?></div>
        </div>
        <div class="stat-card c-green">
            <div class="stat-label">独立 IP</div>
            <div class="stat-value"><?=number_format((int)$monitorData['todayStats']['unique_ips'])?></div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-label">在线用户</div>
            <div class="stat-value"><?=count($monitorData['onlineUsers'])?></div>
        </div>
        <div class="stat-card c-yellow">
            <div class="stat-label">活跃用户</div>
            <div class="stat-value"><?=number_format((int)$monitorData['loginStats']['unique_users'])?></div>
        </div>
    </div>
</div>

<div class="mon-group">
    <div class="mon-group-head">
        <h3 class="mon-group-title">今日登录</h3>
        <span class="mon-group-rule"></span>
        <span class="mon-group-count">成功率 <?= $loginAll > 0 ? $okRate . '%' : '—' ?></span>
    </div>
    <div class="mon-stat-grid">
        <div class="stat-card c-green">
            <div class="stat-label">成功次数</div>
            <div class="stat-value"><?=number_format($loginOk)?></div>
        </div>
        <div class="stat-card c-red">
            <div class="stat-label">失败次数</div>
            <div class="stat-value"><?=number_format($loginBad)?></div>
        </div>
        <div class="stat-card c-blue">
            <div class="stat-label">近 30 天访问</div>
            <div class="stat-value"><?=number_format((int)$monitorData['monthStats']['month_visits'])?></div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-label">注册用户</div>
            <div class="stat-value"><?=count($users)?></div>
        </div>
    </div>
</div>

<!-- 在线用户 -->
<div class="card card-pad mb-3">
    <div class="sec-title">在线用户 <small class="sec-sub">5 分钟内有活动</small></div>
    <?php if ($monitorData['onlineUsers']): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>用户名</th><th>角色</th><th>最后活动时间</th></tr></thead>
            <tbody>
            <?php foreach ($monitorData['onlineUsers'] as $ou): ?>
            <tr>
                <td class="cell-user"><?=h($ou['username'])?></td>
                <td><span class="badge <?= $ou['role']==='admin'?'badge-blue':'badge-green' ?>"><?= $ou['role']==='admin'?'管理员':'子用户' ?></span></td>
                <td class="cell-time"><?=h(substr((string)$ou['last_activity'],0,19))?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="icon">💤</div>当前无在线用户</div>
    <?php endif; ?>
</div>

<!-- 近7天访问趋势（表格视图） -->
<?php if ($monitorData['visitTrend']): ?>
<div class="card card-pad mb-3">
    <div class="mon-card-head">
        <div class="sec-title">近 7 天访问趋势</div>
        <span class="mon-group-count">峰值 <?=number_format($peakVisit)?> 次/日</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>日期</th><th>总访问</th><th>独立 IP</th></tr></thead>
            <tbody>
            <?php foreach ($monitorData['visitTrend'] as $vt):
                $isToday = ($vt['stat_date'] === $today);
            ?>
            <tr class="<?= $isToday ? 'is-today' : '' ?>">
                <td class="cell-date"><?=h($vt['stat_date'])?><?= $isToday ? ' <span class="badge badge-blue">今天</span>' : '' ?></td>
                <td class="cell-num"><?=number_format((int)$vt['total_visits'])?></td>
                <td class="cell-num"><?=number_format((int)$vt['unique_ips'])?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- 登录记录 -->
<div class="card card-pad mb-3">
    <div class="mon-card-head">
        <div class="sec-title">登录记录 <small class="sec-sub">共 <?=number_format($monitorData['logTotal'])?> 条</small></div>
        <?php if ($monitorData['loginLogs']): ?>
        <div class="mon-card-actions">
            <button type="button" class="btn btn-ghost btn-sm" id="logBatchBtn">批量选择</button>
            <button type="button" class="btn btn-danger btn-sm is-hidden" id="logBatchDeleteBtn">删除选中</button>
        </div>
        <?php endif; ?>
    </div>
<?php if ($monitorData['loginLogs']): ?>
    <form method="post" action="api.php" id="logBatchForm">
        <input type="hidden" name="action" value="log.batch_delete_login">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th id="thLogCheckbox" class="is-hidden col-check"><input type="checkbox" id="selectAllLogs" aria-label="全选"></th>
                    <th>时间</th><th>用户名</th><th>IP</th><th>结果</th><th id="thLogAction" class="is-hidden col-action">操作</th>
                </tr></thead>
                <tbody>
                <?php foreach ($monitorData['loginLogs'] as $ll): ?>
                <tr>
                    <td class="tdLogCheckbox is-hidden col-check"><input type="checkbox" name="log_ids[]" value="<?=(int)$ll['id']?>" class="logCheckbox" aria-label="选择该行"></td>
                    <td class="cell-time"><?=h(substr((string)$ll['created_at'],0,16))?></td>
                    <td class="cell-user"><?=h($ll['username'])?></td>
                    <td class="cell-ip"><?=h($ll['ip'])?></td>
                    <td><span class="badge <?= $ll['success'] ? 'badge-green' : 'badge-red' ?>"><?= $ll['success'] ? '成功' : '失败' ?></span></td>
                    <td class="tdLogAction is-hidden col-action">
                        <button type="button" class="btn btn-danger btn-xs js-del-log" data-id="<?=(int)$ll['id']?>">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php if ($monitorData['logTotalPage'] > 1 || $monitorData['logTotal'] > 0): ?>
    <div class="pagination">
        <span class="page-info">共 <?=number_format($monitorData['logTotal'])?> 条 / <?=$monitorData['logTotalPage']?> 页</span>
        <?php
        $cur = (int)$monitorData['logPage'];
        $tp  = (int)$monitorData['logTotalPage'];
        $qs  = 'v=admin&tab=monitor&log_per_page=' . (int)$logPerPage;
        $ls  = max(1, $cur - 2);
        $le  = min($tp, $cur + 2);
        if ($ls > 1) echo '<a href="?' . $qs . '&log_page=1#tab-monitor" class="page-btn">1</a>';
        if ($ls > 2) echo '<span class="page-info">…</span>';
        for ($i = $ls; $i <= $le; $i++) {
            echo '<a href="?' . $qs . '&log_page=' . $i . '#tab-monitor" class="page-btn' . ($i === $cur ? ' active' : '') . '">' . $i . '</a>';
        }
        if ($le < $tp - 1) echo '<span class="page-info">…</span>';
        if ($le < $tp) echo '<a href="?' . $qs . '&log_page=' . $tp . '#tab-monitor" class="page-btn">' . $tp . '</a>';
        ?>
        <select class="per-page-select" id="logPerPageSel" aria-label="每页条数">
            <?php foreach ([10, 15, 20, 25, 30, 40, 50] as $pp): ?>
            <option value="<?=$pp?>" <?=$logPerPage === $pp ? 'selected' : ''?>><?=$pp?> 条/页</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="empty-state"><div class="icon">📋</div>暂无登录记录</div>
<?php endif; ?>
</div>

<!-- 最活跃用户 -->
<?php if ($monitorData['activeUsers']): ?>
<div class="card card-pad mb-3">
    <div class="sec-title">近 7 天最活跃用户</div>
    <div class="table-wrap">
        <table>
            <thead><tr><th class="col-rank">排名</th><th>用户名</th><th>登录次数</th><th>最近登录</th></tr></thead>
            <tbody>
            <?php foreach ($monitorData['activeUsers'] as $i => $au): ?>
            <tr>
                <td class="col-rank"><?=$i+1?></td>
                <td class="cell-user"><?=h($au['username'])?></td>
                <td><span class="badge badge-green"><?=number_format((int)$au['login_count'])?> 次</span></td>
                <td class="cell-time"><?=h(substr((string)$au['last_login'],0,16))?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- 服务器配置 -->
<div class="card card-pad">
    <div class="sec-title">服务器配置</div>
    <table class="mon-kv">
        <tbody>
        <tr><td>PHP 版本</td><td><?=h($si['php'])?></td></tr>
        <tr><td>Web Server</td><td><?=h($si['server'])?></td></tr>
        <tr><td>MySQL 版本</td><td><?=h($si['mysql'])?></td></tr>
        <tr><td>操作系统</td><td><?=h($si['os'])?></td></tr>
        <tr><td>最大上传</td><td><?=h($si['max_upload'])?></td></tr>
        <tr><td>最大 POST</td><td><?=h($si['max_post'])?></td></tr>
        <tr><td>内存限制</td><td><?=h($si['memory'])?></td></tr>
        </tbody>
    </table>
    <div class="srv-meters">
        <?php if ($diskTotal > 0): ?>
        <div class="srv-meter">
            <div class="srv-meter-head">
                <strong>磁盘使用（站点所在分区）</strong>
                <span class="mono"><?=monFmtSize($diskUsed)?> / <?=monFmtSize($diskTotal)?> · <?=$diskPct?>% · 可用 <?=monFmtSize($diskFree)?></span>
            </div>
            <div class="srv-meter-track">
                <div class="srv-meter-bar">
                    <div class="srv-meter-fill" data-width="<?=$diskPct?>" style="width:<?=$diskPct?>%;background:<?=monMeterVar($diskPct)?>"></div>
                </div>
                <div class="srv-meter-ticks" aria-hidden="true">
                    <i class="srv-meter-tick srv-tick-70"></i>
                    <i class="srv-meter-tick srv-tick-90"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($memTotal > 0): ?>
        <div class="srv-meter">
            <div class="srv-meter-head">
                <strong>物理内存使用</strong>
                <span class="mono"><?=monFmtSize($memUsed)?> / <?=monFmtSize($memTotal)?> · <?=$memPct?>%</span>
            </div>
            <div class="srv-meter-track">
                <div class="srv-meter-bar">
                    <div class="srv-meter-fill" data-width="<?=$memPct?>" style="width:<?=$memPct?>%;background:<?=monMeterVar($memPct)?>"></div>
                </div>
                <div class="srv-meter-ticks" aria-hidden="true">
                    <i class="srv-meter-tick srv-tick-70"></i>
                    <i class="srv-meter-tick srv-tick-90"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="srv-meter-legend">
            <span class="lg"><i class="dot dot-ok"></i>&lt;70% 正常</span>
            <span class="lg"><i class="dot dot-warn"></i>70%~90% 偏高</span>
            <span class="lg"><i class="dot dot-bad"></i>≥90% 紧张</span>
        </div>
    </div>
</div>
</div><!-- /monitorViewList -->

<!-- ═══════════ 视图二：数据大屏 ═══════════ -->
<div id="monitorViewDash" class="is-hidden">
<div class="mon-dash">

    <div class="mon-kpis">
        <div class="mon-kpi k-blue">
            <div class="k-label">今日访问</div>
            <div class="k-value"><?=number_format((int)$monitorData['todayStats']['total_visits'])?></div>
        </div>
        <div class="mon-kpi k-green">
            <div class="k-label">今日独立 IP</div>
            <div class="k-value"><?=number_format((int)$monitorData['todayStats']['unique_ips'])?></div>
        </div>
        <div class="mon-kpi k-purple">
            <div class="k-label">在线用户</div>
            <div class="k-value"><?=count($monitorData['onlineUsers'])?></div>
        </div>
        <div class="mon-kpi k-yellow">
            <div class="k-label">注册用户</div>
            <div class="k-value"><?=count($users)?></div>
        </div>
        <div class="mon-kpi k-green">
            <div class="k-label">登录成功</div>
            <div class="k-value"><?=number_format($loginOk)?></div>
        </div>
        <div class="mon-kpi k-red">
            <div class="k-label">登录失败</div>
            <div class="k-value"><?=number_format($loginBad)?></div>
        </div>
        <div class="mon-kpi k-blue">
            <div class="k-label">近 30 天访问</div>
            <div class="k-value"><?=number_format((int)$monitorData['monthStats']['month_visits'])?></div>
        </div>
        <div class="mon-kpi k-purple">
            <div class="k-label">今日活跃</div>
            <div class="k-value"><?=number_format((int)$monitorData['loginStats']['unique_users'])?></div>
        </div>
    </div>


    <div class="mon-panel">
        <h3 class="mon-panel-title">近 7 天访问趋势 <small>蓝=总访问 · 紫=独立 IP · 峰值 <?=number_format($peakVisit)?></small></h3>
        <?php if ($monitorData['visitTrend']): ?>
        <div class="mon-bars">
            <div class="mon-bars-grid" aria-hidden="true">
                <i class="srv-grid-0"></i>
                <i class="srv-grid-33"></i>
                <i class="srv-grid-66"></i>
                <i class="is-top srv-grid-100"><b><?=number_format($trendMax)?></b></i>
            </div>
            <?php foreach ($monitorData['visitTrend'] as $vt):
                $tv = (int)$vt['total_visits'];
                $uv = (int)$vt['unique_ips'];
                $h1 = max(3, round($tv / $trendMax * 100));
                $h2 = max(2, round($uv / $trendMax * 100));
                $isToday = ($vt['stat_date'] === $today);
                $isPeak  = ($tv > 0 && $tv === $peakVisit);
            ?>
            <div class="mon-bar-col <?= $isToday ? 'is-today' : '' ?>"
                 title="<?=h($vt['stat_date'])?> 总访问 <?=$tv?> · 独立 IP <?=$uv?>">
                <span class="mon-bar-val"><?=$tv?></span>
                <div class="mon-bar-pair">
                    <div class="mon-bar <?= $isPeak ? 'is-peak' : '' ?>" style="height:<?=$h1?>%"></div>
                    <div class="mon-bar ip" style="height:<?=$h2?>%"></div>
                </div>
                <span class="mon-bar-date"><?=h(substr($vt['stat_date'],5))?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><div class="icon">📊</div>暂无访问数据</div>
        <?php endif; ?>
    </div>

    <!-- 登录状态：左=今日成功率环，右=近 24h 登录时段玫瑰图 -->
    <div class="mon-panel">
        <h3 class="mon-panel-title">今日登录状态 <small>成功率 · 近 24h 时段分布</small></h3>
        <div class="mon-login-wrap">
            <div class="mon-donut" style="--ok-deg:<?=$okDeg?>deg">
                <div class="mon-donut-center">
                    <span class="rate"><?= $loginAll > 0 ? $okRate . '%' : '—' ?></span>
                    <span class="lbl">今日成功率</span>
                </div>
            </div>

            <!-- 时段玫瑰图：4 小时一段共 6 段，半径随登录次数伸缩（有上下限） -->
            <div class="mon-rose">
                <svg viewBox="0 0 200 200" role="img" aria-label="近 24 小时登录时段分布">
                    <circle class="mr-base" cx="100" cy="100" r="88"></circle>
                    <?php $hub = 27.0; foreach ($loginSlots as $ls):
                        $hub = $ls['hub'];
                        $a1 = deg2rad($ls['from']);
                        $a2 = deg2rad($ls['to']);
                        $r  = $ls['r'];
                        $ix1 = 100 + $hub * cos($a1); $iy1 = 100 + $hub * sin($a1);
                        $ix2 = 100 + $hub * cos($a2); $iy2 = 100 + $hub * sin($a2);
                        $x1 = 100 + $r * cos($a1); $y1 = 100 + $r * sin($a1);
                        $x2 = 100 + $r * cos($a2); $y2 = 100 + $r * sin($a2);
                        $midDeg = ($ls['from'] + $ls['to']) / 2;
                        $cosMid = cos(deg2rad($midDeg));
                        $mx = 100 + 100 * $cosMid;
                        $my = 100 + 100 * sin(deg2rad($midDeg));
                        $anchor = ($cosMid > 0.3) ? 'start' : (($cosMid < -0.3) ? 'end' : 'middle');
                        $isZero = ($ls['cnt'] === 0);
                    ?>
                    <path class="mr-seg<?= $isZero ? ' is-zero' : '' ?>"<?= $isZero ? '' : ' style="opacity:' . round(0.45 + 0.55 * ($ls['pct'] / 100), 2) . '"' ?>
                          d="M<?=round($ix1,2)?>,<?=round($iy1,2)?> L<?=round($x1,2)?>,<?=round($y1,2)?> A<?=round($r,2)?>,<?=round($r,2)?> 0 0 1 <?=round($x2,2)?>,<?=round($y2,2)?> L<?=round($ix2,2)?>,<?=round($iy2,2)?> A<?=round($hub,2)?>,<?=round($hub,2)?> 0 0 0 <?=round($ix1,2)?>,<?=round($iy1,2)?> Z">
                        <title><?=h($ls['label'])?>：<?=$ls['cnt']?> 次</title>
                    </path>
                    <text class="mr-lab" x="<?=round($mx,2)?>" y="<?=round($my,2)?>" text-anchor="<?=$anchor?>"
                          dy="0.34em" title="<?=h($ls['label'])?>：<?=$ls['cnt']?> 次"><?=h(substr($ls['label'],0,2))?></text>
                    <?php endforeach; ?>
                    <circle class="mr-hub" cx="100" cy="100" r="<?=round($hub,2)?>"></circle>
                    <text class="mr-mid-num" x="100" y="99" text-anchor="middle"><?=$slotTotal?></text>
                    <text class="mr-mid-lbl" x="100" y="114" text-anchor="middle">近24h 登录</text>
                </svg>
                <div class="mon-rose-cap">扇形半径 = 该时段登录次数（4 小时／段）</div>
            </div>
        </div>
        <div class="mon-login-legend">
            <span><i class="mon-dot ok"></i>成功 <strong><?=$loginOk?></strong> 次</span>
            <span><i class="mon-dot bad"></i>失败 <strong><?=$loginBad?></strong> 次</span>
            <span><i class="mon-dot rose"></i>近 24h <strong><?=$slotTotal?></strong> 次</span>
        </div>
    </div>

    <!-- 活跃用户条形 -->
        <div class="mon-panel">
            <h3 class="mon-panel-title">近 7 天最活跃用户 <small>按登录次数</small></h3>
            <?php if ($monitorData['activeUsers']): ?>
            <div class="mon-hbars">
                <?php foreach ($monitorData['activeUsers'] as $au):
                    $w = max(4, round((int)$au['login_count'] / $activeUserMax * 100));
                ?>
                <div class="mon-hbar-item">
                    <span class="h-name" title="<?=h($au['username'])?>"><?=h($au['username'])?></span>
                    <div class="mon-hbar-track"><div class="mon-hbar-fill" style="width:<?=$w?>%"></div></div>
                    <span class="h-val"><?=number_format((int)$au['login_count'])?> 次</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="icon">🏆</div>暂无活跃数据</div>
            <?php endif; ?>
        </div>

        <!-- 资源 + 环境 -->
        <div class="mon-panel">
            <h3 class="mon-panel-title">服务器资源</h3>
            <div class="srv-meters">
                <?php if ($diskTotal > 0): ?>
                <div class="srv-meter">
                    <div class="srv-meter-head">
                        <strong>磁盘</strong>
                        <span class="mono"><?=monFmtSize($diskUsed)?> / <?=monFmtSize($diskTotal)?> · <?=$diskPct?>%</span>
                    </div>
                    <div class="srv-meter-track">
                        <div class="srv-meter-bar">
                            <div class="srv-meter-fill" style="width:<?=$diskPct?>%;background:<?=monMeterVar($diskPct)?>"></div>
                        </div>
                        <div class="srv-meter-ticks" aria-hidden="true">
                            <i class="srv-meter-tick srv-tick-70"></i>
                            <i class="srv-meter-tick srv-tick-90"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($memTotal > 0): ?>
                <div class="srv-meter">
                    <div class="srv-meter-head">
                        <strong>内存</strong>
                        <span class="mono"><?=monFmtSize($memUsed)?> / <?=monFmtSize($memTotal)?> · <?=$memPct?>%</span>
                    </div>
                    <div class="srv-meter-track">
                        <div class="srv-meter-bar">
                            <div class="srv-meter-fill" style="width:<?=$memPct?>%;background:<?=monMeterVar($memPct)?>"></div>
                        </div>
                        <div class="srv-meter-ticks" aria-hidden="true">
                            <i class="srv-meter-tick srv-tick-70"></i>
                            <i class="srv-meter-tick srv-tick-90"></i>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="mon-info-grid">
                <div class="mon-info-item">PHP 版本<div class="i-val"><?=h($si['php'])?></div></div>
                <div class="mon-info-item">MySQL<div class="i-val"><?=h($si['mysql'])?></div></div>
                <div class="mon-info-item">Web Server<div class="i-val"><?=h($si['server'])?></div></div>
                <div class="mon-info-item">操作系统<div class="i-val"><?=h($si['os'])?></div></div>
            </div>
        </div>

    <!-- 在线用户 chips -->
        <div class="mon-panel">
            <h3 class="mon-panel-title">在线用户 <small>5 分钟内有活动，共 <?=count($monitorData['onlineUsers'])?> 人</small></h3>
            <?php if ($monitorData['onlineUsers']): ?>
            <div class="mon-chips">
                <?php foreach ($monitorData['onlineUsers'] as $ou): ?>
                <span class="mon-chip" title="最后活动：<?=h(substr((string)$ou['last_activity'],0,19))?>">
                    <i class="dot"></i><?=h($ou['username'])?>
                    <span class="role"><?= $ou['role']==='admin' ? '管理员' : '子用户' ?></span>
                </span>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><div class="icon">💤</div>当前无在线用户</div>
            <?php endif; ?>
        </div>

        <!-- 最新登录记录 -->
        <div class="mon-panel">
            <h3 class="mon-panel-title">最新登录记录 <small>前 10 条 · 完整列表见列表视图</small></h3>
            <?php if ($monitorData['loginLogs']): ?>
            <div class="mon-mini-logs">
                <?php foreach (array_slice($monitorData['loginLogs'], 0, 10) as $ll): ?>
                <div class="mon-mini-log">
                    <span class="t"><?=h(substr((string)$ll['created_at'],5,11))?></span>
                    <span class="u" title="<?=h($ll['username'])?>"><?=h($ll['username'])?></span>
                    <span class="badge <?=$ll['success']?'badge-green':'badge-red'?>"><?=$ll['success']?'成功':'失败'?></span>
                    <span class="ip"><?=h($ll['ip'])?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
        <div class="empty-state"><div class="icon">📋</div>暂无登录记录</div>
        <?php endif; ?>
    </div>
</div>
</div><!-- /monitorViewDash -->

<script src="modules/admin/Assets/js/monitor.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/monitor.js')?>" defer></script>
