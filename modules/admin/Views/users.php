<?php
declare(strict_types=1);
/** 用户管理 Tab（主管理员，子用户 —— 真正的版本分叉） */
if (!\isPrimaryAdmin()) return;
if (!hasFeature('multi_user')) return;

$totalAdmins = 0;
foreach ($users as $u) {
    if (($u['role'] ?? '') === 'admin') $totalAdmins++;
}
$totalSubs = count($subUsers);
?>

<div class="card card-pad um-wrap">

<div class="um-head">
    <div>
        <div class="sec-title um-title">用户管理</div>
        <div class="page-desc"><strong><?=$totalAdmins?></strong> 位主管理员 · <strong><?=$totalSubs?></strong> 位子用户</div>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="btnChangeMyPw">修改我的密码</button>
</div>

<!-- 主管理员 -->
<div class="table-wrap um-table-gap">
<table>
    <thead><tr>
        <th>用户名</th>
        <th>角色</th>
        <th>创建时间</th>
        <th>最近登录</th>
        <th class="col-act">操作</th>
    </tr></thead>
    <tbody>
<?php foreach ($users as $u):

    $uId     = (int)$u['id'];
    $isSelf  = ($uId === (int)$uid);
    $role    = (string)($u['role'] ?? '');
?>
<tr>
    <td class="cell-user">
        <?=h($u['username'])?>
        <?php if ($isSelf): ?><span class="badge badge-blue um-self">我</span><?php endif; ?>
        <?php if (!empty($u['must_change_pw'])): ?><span class="badge badge-yellow">需改密</span><?php endif; ?>
    </td>
    <td>
        <?php if ($role === 'admin'): ?>
        <span class="badge badge-yellow">主管理员</span>
        <?php else: ?>
        <span class="badge badge-green">子用户</span>
        <?php endif; ?>
    </td>
    <td class="cell-date"><?=h(substr((string)$u['created_at'], 0, 10))?></td>
    <td class="cell-date"><?=h(substr((string)($u['last_login'] ?? '—'), 0, 10))?></td>
    <td class="td-actions col-act">
        <?php if ($isSelf): ?>
        <span class="um-hint">当前登录账户</span>
        <?php else: ?>
        <div class="actions">
            <form method="post" action="api.php" class="user-reset-pw-form" data-username="<?=h($u['username'])?>">
                <input type="hidden" name="action" value="admin.user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$uId?>">
                <button type="submit" class="btn btn-ghost btn-xs">重置密码</button>
            </form>
            <form method="post" action="api.php" class="user-delete-form" data-username="<?=h($u['username'])?>">
                <input type="hidden" name="action" value="admin.user_delete">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$uId?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- 子用户管理 -->
<div class="um-sub">
    <div class="sec-title">子用户管理</div>
    <p class="um-note">
        子用户共享你的全部物料数据，新建时默认勾选「编辑 / 扫码 / 打印 / 导出」，可随时点「权限」调整。
        多人共用一套物料时创建子用户即可，无需每人注册独立账号。
    </p>

<?php if ($subUsers): ?>
    <div class="table-wrap um-table-gap">
    <table>
        <thead><tr>
            <th>用户名</th>
            <th>权限</th>
            <th>创建时间</th>
            <th>最近登录</th>
            <th class="col-act">操作</th>
        </tr></thead>
        <tbody>
<?php foreach ($subUsers as $su):
    $suId    = (int)$su['id'];
    $suPerms = parsePerms($su['permissions'] ?? null);
    $permCnt = count($suPerms);
?>
<tr>
    <td class="cell-user">
        <?=h($su['username'])?>
        <?php if (!empty($su['must_change_pw'])): ?><span class="badge badge-yellow">需改密</span><?php endif; ?>
    </td>
    <td>
        <?php if ($permCnt === 0): ?>
        <span class="um-hint">仅查看</span>
        <?php else: ?>
        <button type="button" class="um-perm-chip"
                data-perm-list="<?=h(implode(',', $suPerms))?>"
                title="<?=h(implode('、', array_map(fn($k) => $permLabels[$k] ?? $k, $suPerms)))?>">
            <?=$permCnt?> 项权限
        </button>
        <?php endif; ?>
    </td>
    <td class="cell-date"><?=h(substr((string)$su['created_at'], 0, 10))?></td>
    <td class="cell-date"><?=h(substr((string)($su['last_login'] ?? '—'), 0, 10))?></td>
    <td class="td-actions col-act">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs sub-perm-btn"
                    data-id="<?=$suId?>"
                    data-username="<?=h($su['username'])?>"
                    data-perms="<?=h(json_encode($suPerms, JSON_UNESCAPED_UNICODE))?>">权限</button>
            <form method="post" action="api.php" class="sub-reset-pw-form" data-username="<?=h($su['username'])?>">
                <input type="hidden" name="action" value="admin.user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$suId?>">
                <button type="submit" class="btn btn-ghost btn-xs">重置密码</button>
            </form>
            <form method="post" action="api.php" class="sub-delete-form" data-username="<?=h($su['username'])?>">
                <input type="hidden" name="action" value="admin.delete_sub_user">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$suId?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php else: ?>
    <div class="empty-state um-empty">
        <div class="icon">👤</div>
        <div class="es-desc">还没有子用户。用下方表单创建，共享同一套物料数据</div>
    </div>
<?php endif; ?>

<!-- 创建子用户 -->
<div class="um-create">
    <h4 class="um-create-title">创建子用户</h4>
    <form method="post" action="api.php" id="createSubForm">
        <input type="hidden" name="action" value="admin.create_sub_user">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-row">
            <div class="form-group">
                <label>用户名 <em class="um-req">*</em>（至少 3 位）</label>
                <input name="sub_username" placeholder="worker1" required>
            </div>
            <div class="form-group">
                <label>密码 <em class="um-req">*</em>（至少 8 位，含 3 类字符）</label>
                <input name="sub_password" type="password" placeholder="••••••" required>
            </div>
        </div>
        <div class="um-perm-picker">
            <div class="um-perm-picker-head">
                <span>权限设置（已按常用功能预选，可调整）</span>
                <span class="um-perm-bulk">
                    <button type="button" class="um-link-btn" id="btnPermAll">全选</button>
                    <button type="button" class="um-link-btn" id="btnPermNone">清空</button>
                </span>
            </div>
            <div class="um-perm-grid">
<?php
$defaultPerms = ['can_edit', 'can_scan', 'can_print', 'can_export'];
foreach ($permLabels as $pk => $pl): ?>
                <label class="um-perm-item">
                    <input type="checkbox" name="sub_perms[]" value="<?=h($pk)?>"
                           <?=in_array($pk, $defaultPerms, true) ? 'checked' : ''?>>
                    <span><?=h($pl)?></span>
                </label>
<?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">创建子用户</button>
    </form>
</div>
</div><!-- /um-sub -->

</div><!-- /um-wrap -->

<!-- ── 修改我的密码弹窗（复用全站 .overlay + .modal 体系） ── -->
<div class="overlay" id="pwModal">
<div class="modal um-pw-modal">
    <h3 class="um-pw-title">修改我的密码</h3>
    <button type="button" class="modal-close" data-close-pw aria-label="关闭">×</button>
    <form id="pwModalForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-group"><label>当前密码</label><input name="old_password" type="password" required autocomplete="current-password"></div>
        <div class="form-group"><label>新密码（至少 8 位，含 3 类字符）</label><input name="new_password" type="password" required autocomplete="new-password"></div>
        <div class="form-group"><label>确认新密码</label><input name="confirm" type="password" required autocomplete="new-password"></div>
        <div class="um-pw-msg is-hidden" id="pwModalMsg"></div>
        <div class="um-pw-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-close-pw>取消</button>
            <button type="submit" class="btn btn-primary btn-sm" id="pwModalSubmit">更新密码</button>
        </div>
    </form>
</div>
</div>

<script src="modules/admin/Assets/js/users.js?v=<?=APP_VERSION.'.'.filemtime(__DIR__.'/../Assets/js/users.js')?>" defer></script>
