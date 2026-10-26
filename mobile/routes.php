<?php
declare(strict_types=1);
/** mobile/routes.php — 移动端路由表 */
return [
    // ── 页签页 ──（底部 4 个页签：工作台 / 扫码 / 查询 / 我的）
    'home'     => ['view' => 'home.php',     'title' => '工作台',       'tab' => true],
    'scan'     => ['view' => 'scan.php',     'title' => '扫码出入库',   'tab' => true],
    'search'   => ['view' => 'search.php',   'title' => '物料查询',     'tab' => true],
    'profile'  => ['view' => 'profile.php',  'title' => '我的',         'tab' => true],

    // ── 二级页（顶栏带返回、底栏隐藏） ──
    'password' => ['view' => 'password.php', 'title' => '修改密码',     'tab' => false, 'back' => 'profile'],
    'my_logs'  => ['view' => 'my_logs.php',  'title' => '我的操作记录', 'tab' => false, 'back' => 'profile'],
    'notice'   => ['view' => 'notice.php',   'title' => '公告',         'tab' => false, 'back' => 'profile'],
    'about'    => ['view' => 'about.php',    'title' => '关于',         'tab' => false, 'back' => 'profile'],
    'stale'    => ['view' => 'stale.php',    'title' => '呆滞物料',     'tab' => false, 'back' => 'home'],
];
