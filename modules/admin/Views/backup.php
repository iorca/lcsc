<?php
declare(strict_types=1);
/** 数据备份 Tab */

$webdavUrlVal  = trim((string)(getSetting('webdav_url') ?? ''));
$webdavUserVal = trim((string)(getSetting('webdav_user') ?? ''));
$webdavPathVal = trim((string)(getSetting('webdav_path') ?? ''));
if ($webdavPathVal === '') $webdavPathVal = 'lcsc_backup';
$maskVal = static function (string $v, int $head, int $tail): string {
    $len = mb_strlen($v, 'UTF-8');
    if ($len === 0) return '';
    if ($len <= $head + $tail) return mb_substr($v, 0, 1, 'UTF-8') . str_repeat('*', max(4, $len - 1));
    return mb_substr($v, 0, $head, 'UTF-8') . str_repeat('*', 8) . mb_substr($v, -$tail, null, 'UTF-8');
};

$maskUrl = static function (string $u): string {
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return $u;
    return $p['scheme'] . '://' . str_repeat('*', 8)
        . (isset($p['port']) ? ':' . (int)$p['port'] : '')
        . (string)($p['path'] ?? '');
};
?>
<div class="card card-pad">
<div class="sec-title">数据备份</div>

<!-- 第一行：下载备份 / 恢复备份 / 一键备份（三卡并排，紧凑布局） -->
<div class="bk-grid compact">
    <div class="bk-card">
        <h4>📥 下载备份 <span class="help-tip" data-tip="将数据库导出为 SQL 文件并下载到本地电脑（浏览器），方便你自行留存、拷贝到其他设备或离线归档。文件不会保留在服务器上。">?</span></h4>
        <div class="bk-desc">导出全部表结构及数据为 SQL 文件，用于本地留存或跨环境迁移。</div>
        <form method="post" id="backupForm">
            <input type="hidden" name="action" value="backup">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <button type="submit" class="btn btn-primary" data-busy-label="正在生成备份...">📥 生成并下载备份</button>
        </form>
    </div>

    <div class="bk-card">
        <h4>📤 恢复备份</h4>
        <div class="bk-danger-tip">
            <span>⚠️</span>
            <span><strong>高危操作：</strong>恢复会覆盖全部现有业务数据，不可撤销。</span>
        </div>
        <form method="post" enctype="multipart/form-data" id="restoreForm">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="file" name="backup_file" accept=".sql" class="bk-file-input" required>
            <button type="submit" class="btn btn-danger" data-busy-label="正在恢复数据，请勿关闭页面...">📤 恢复备份</button>
        </form>
    </div>

    <div class="bk-card">
        <h4>💾 一键备份 <span class="help-tip" data-tip="将备份文件保存在服务器 backups/ 目录中（不会下载到本地）。可随时在下方「服务器备份列表」中一键恢复，适合做定期快照。">?</span></h4>
        <div class="bk-desc">在服务器 <code>backups/</code> 目录保存备份，可随时从下方列表恢复。</div>
        <?php if(isPrimaryAdmin()): ?>
        <div class="bk-ops">
            <button type="button" class="btn btn-primary" id="oneClickBackupBtn" data-busy-label="正在备份到服务器...">💾 备份到服务器</button>
        </div>
        <?php else: ?>
        <div class="bk-desc bk-desc-flush">仅主管理员可操作</div>
        <?php endif; ?>
    </div>
</div>

<!-- 服务器备份文件列表（全宽） -->
<?php if(isPrimaryAdmin()): ?>
<div class="bk-card">
    <div class="bk-card-head">
        <h4>📦 服务器备份列表</h4>
        <?php // 清理入口：按保留份数删掉最旧的多余备份?>
        <button type="button" class="btn btn-ghost btn-sm" id="backupCleanupBtn" data-busy-label="清理中...">🧹 清理过期备份</button>
    </div>
    <div id="backupFilesWrap" class="bk-table-wrap bk-table-wrap-tight"></div>
</div>

<!-- 第二行：WebDAV / 定时备份（两卡并排） -->
<div class="bk-grid compact">
    <div class="bk-card">
        <h4>☁️ WebDAV 远程备份</h4>
        <div class="bk-desc">配置后可将备份文件自动上传至 WebDAV 网盘，实现异地容灾。</div>
        <div class="bk-field-row">
            <div class="form-group bk-grow"><label>WebDAV 地址</label>
                <input type="text" id="webdavUrl" value="" title="已保存的地址以掩码显示；留空表示不修改"
                    placeholder="<?= $webdavUrlVal !== '' ? h($maskUrl($webdavUrlVal)) : 'https://dav.jianguoyun.com/dav/' ?>" class="bk-input-mono">
            </div>
            <div class="form-group bk-grow"><label>备份路径（相对目录）</label>
                <input type="text" id="webdavPath" value="<?=h($webdavPathVal)?>" placeholder="lcsc_backup" class="bk-input-mono">
            </div>
        </div>
        <div class="bk-field-row">
            <div class="form-group bk-grow"><label>认证用户名</label>
                <input type="text" id="webdavUser" value="" title="已保存的用户名以掩码显示；留空表示不修改"
                    placeholder="<?= $webdavUserVal !== '' ? h($maskVal($webdavUserVal, 5, 6)) : '选填' ?>" autocomplete="off">
            </div>
            <div class="form-group bk-grow"><label>认证密码</label>
                <input type="password" id="webdavPass" placeholder="<?=!empty($webdavPassSet)?'●●●●●●●●（已保存，留空不修改）':'选填'?>" autocomplete="new-password">
            </div>
        </div>
        <div class="bk-desc">地址只填到服务根（如坚果云 <code>https://dav.jianguoyun.com/dav/</code>）；备份存放在「备份路径」指定目录下，目录不存在时上传会自动逐级创建。已保存的地址 / 用户名以掩码显示，<b>留空表示不修改</b>。</div>
        <div class="bk-ops">
            <button type="button" class="btn btn-primary btn-sm" id="webdavSaveBtn" data-busy-label="保存中...">保存配置</button>
            <button type="button" class="btn btn-primary" id="webdavUploadBtn" data-busy-label="上传中...">☁️ 上传到WebDAV</button>
        </div>
    </div>

    <div class="bk-card">
        <h4>⏰ 定时备份配置</h4>
        <div class="bk-desc">按设定频率与时刻自动创建备份并保留指定份数。两种执行方式：<b>定时备份</b>由服务器到点自动执行，无需任何人访问；<b>周期内首次请求执行</b>则在到点后的第一个访问请求上补跑。</div>
        <div class="bk-field-row">
            <div class="form-group bk-grow"><label>执行方式</label>
                <select id="scheduleMode" title="定时备份：到点自动执行（服务器内常驻守候，单进程开发服务器下自动退回补跑）；周期内首次请求执行：到点后由第一个访问请求补跑">
                    <option value="schedule" <?=(backupMode() === 'schedule') ? 'selected' : ''?>>定时备份（到点自动执行）</option>
                    <option value="on_request" <?=(backupMode() === 'on_request') ? 'selected' : ''?>>周期内首次请求执行</option>
                </select>
            </div>
            <div class="form-group bk-grow"><label>备份频率</label>
                <select id="scheduleFrequency">
                    <option value="disabled" <?=((getSetting('backup_frequency') ?? 'disabled') === 'disabled') ? 'selected' : ''?>>关闭</option>
                    <option value="daily" <?=((getSetting('backup_frequency') ?? 'disabled') === 'daily') ? 'selected' : ''?>>每日</option>
                    <option value="weekly" <?=((getSetting('backup_frequency') ?? 'disabled') === 'weekly') ? 'selected' : ''?>>每周</option>
                </select>
            </div>
            <div class="form-group bk-field-fixed"><label>备份时刻</label>
                <input type="time" id="scheduleTime" value="<?=h((string)(getSetting('backup_time') ?? '03:00'))?>" title="定时备份方式下到点即自动执行；首次请求方式下由该时刻之后的第一个访问请求执行">
            </div>
            <div class="form-group bk-grow"><label>保留份数</label>
                <input type="number" id="scheduleKeepCount" value="<?=h(getSetting('backup_keep_count') ?? '7')?>" min="1" max="30" placeholder="1-30">
            </div>
        </div>
        <div class="bk-ops">
            <button type="button" class="btn btn-primary btn-sm" id="scheduleSaveBtn" data-busy-label="保存中...">保存配置</button>
        </div>
        <div class="bk-schedule-status" id="scheduleLastRun">
            <?php
            $lastAuto = (int)getSetting('backup_last_at', '0');
            $autoFiles = glob(__DIR__ . '/../../../backups/auto_backup_*.sql') ?: [];
            $lastRunAt = $autoFiles ? date('Y-m-d H:i', max(array_map('filemtime', $autoFiles))) : '';
            ?>
            上次自动备份：<b><?=$lastRunAt !== '' ? h($lastRunAt) : '尚未执行'?></b>
            · 时间窗起点：<b><?=$lastAuto > 0 ? h(date('Y-m-d H:i', $lastAuto)) : '—'?></b>
            · 自动备份文件：<b><?=count($autoFiles)?></b> 份
        </div>
    </div>
</div>

<!-- 第三行：API Key（全宽卡片） -->
<?php if(isPrimaryAdmin()): ?>
<div class="bk-card">
    <h4>🔑 API Key 管理</h4>
    <div class="bk-desc">通过 HTTP Header <code>X-API-Key</code> 认证访问 API 接口。密钥仅显示一次；可设有效期，过期后自动失效，可在列表中随时调整。</div>
    <div class="bk-field-row">
        <div class="form-group bk-grow bk-grow-name"><label>Key 名称</label>
            <input type="text" id="apiKeyName" placeholder="例：数据同步服务">
        </div>
        <div class="form-group bk-grow bk-grow-scope"><label>权限范围</label>
            <button type="button" id="apiKeyScopeBtn" class="bk-scope-btn" onclick="openScopePicker()">
                <span id="apiKeyScopeText">读取物料、读取库存</span>
                <span class="arrow">▼</span>
            </button>
            <!-- 隐藏域保存当前选中值（逗号分隔），createApiKey 读取此字段 -->
            <input type="hidden" id="apiKeyScope" value="read_part,read_stock">
        </div>
        <div class="form-group bk-field-fixed"><label>有效期</label>

            <select id="apiKeyExpire">
                <option value="0">永不过期</option>
                <option value="30">30 天</option>
                <option value="90" selected>90 天</option>
                <option value="365">365 天</option>
            </select>
        </div>
        <div class="form-group bk-field-fixed">
            <button type="button" class="btn btn-primary" id="apiKeyCreateBtn" data-busy-label="生成中...">生成 Key</button>
        </div>
    </div>
    <div id="apiKeyListWrap"></div>
</div>


<div class="bk-maint-bar">
    <span class="bk-maint-ver">当前版本 <strong><?=h(defined('APP_VERSION') ? APP_VERSION : '—')?></strong> · 数据库 <strong><?=h(defined('DATABASE_VERSION') ? DATABASE_VERSION : '—')?></strong>（在线升级与恢复前都会自动备份）</span>
</div>

<!-- 权限范围选择弹窗 -->
<div class="bks-overlay is-hidden" id="scopePickerOverlay">
<div class="bks-dialog" role="dialog" aria-modal="true">
    <div class="bks-title">选择权限范围</div>
    <div class="bks-hint">勾选该 Key 可访问的接口能力，点击「确定」生效</div>
    <div class="bks-list" id="scopePickerList"></div>
    <div class="bks-btns">
        <button type="button" class="acm-btn" onclick="closeScopePicker()">取消</button>
        <button type="button" class="acm-btn ok primary" onclick="confirmScopePicker()">确定</button>
    </div>
</div>
</div>


<div class="bks-overlay is-hidden" id="apiKeyShowOverlay">
<div class="bks-dialog ak-dialog" role="dialog" aria-modal="true" aria-labelledby="akShowTitle">
    <div class="ak-head">
        <span class="ak-ic" aria-hidden="true">🔑</span>
        <div class="ak-head-txt">
            <div class="bks-title" id="akShowTitle">API Key 已生成</div>
            <div class="bks-hint">此密钥仅显示一次，关闭后无法再次查看，请立即复制保存。</div>
        </div>
        <button type="button" class="ak-close" onclick="closeApiKeyShow()" title="关闭" aria-label="关闭">✕</button>
    </div>
    <div class="ak-key-row">
        <code class="ak-key" id="apiKeyShowValue"></code>
        <button type="button" class="btn btn-primary btn-sm ak-copy" id="apiKeyCopyBtn" onclick="copyApiKey(this)">复制</button>
    </div>
    <dl class="ak-meta">
        <div class="ak-meta-item"><dt>权限范围</dt><dd id="akShowScope">—</dd></div>
        <div class="ak-meta-item"><dt>有效期</dt><dd id="akShowExpire">—</dd></div>
    </dl>
    <div class="ak-tip">调用时通过请求头 <code>X-API-Key</code> 携带本密钥。</div>
    <div class="bks-btns ak-btns">
        <button type="button" class="acm-btn ok primary" onclick="closeApiKeyShow()">我已保存，关闭</button>
    </div>
</div>
</div>
<!-- WebDAV 连通性测试弹窗：保存配置后自动弹出，显示远端备份目录内容 -->
<div class="bks-overlay is-hidden" id="webdavTestOverlay">
<div class="bks-dialog wd-dialog" role="dialog" aria-modal="true" aria-labelledby="wdTestTitle">
    <div class="bks-title" id="wdTestTitle">WebDAV 连接成功</div>
    <div class="bks-hint" id="wdTestMeta">备份目录：—</div>
    <div class="wd-list" id="wdTestList"></div>
    <div class="bks-btns">
        <button type="button" class="acm-btn ok primary" onclick="closeWebdavTest()">关闭</button>
    </div>
</div>
</div>

<?php endif; ?>
<?php endif; /* 服务器备份列表/WebDAV/定时备份/API Key：主管理员区块结束 */ ?>

<?php  ?>
</div>

<script src="modules/admin/Assets/js/backup.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/backup.js')?>" defer></script>
