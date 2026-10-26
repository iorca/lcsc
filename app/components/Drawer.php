<?php
declare(strict_types=1);

namespace App\Components;

/** 侧滑抽屉组件 */
final class Drawer
{
    /** 开始渲染抽屉（输出开始标签） */
    public static function begin(array $cfg): void
    {
        $id         = $cfg['id'] ?? '';
        $overlayId  = $cfg['overlay_id'] ?? $id . '_mask';
        $title      = $cfg['title'] ?? '';
        $titleId    = $cfg['title_id'] ?? '';
        $bodyId     = $cfg['body_id'] ?? '';
        $onClose    = $cfg['on_close'] ?? "closeDrawer('{$id}')";
        $closeIcon  = $cfg['close_icon'] ?? '✕';
        $width      = $cfg['width'] ?? '';
        $open       = $cfg['open'] ?? false;
        $openClass  = $open ? ' open' : '';
        $widthStyle = $width !== '' ? "width:{$width};max-width:90vw" : '';
        ?>
<!-- 侧滑抽屉（来自 App\Components\Drawer::begin） -->
<div class="drawer-overlay<?= $openClass ?>" id="<?= \h($overlayId) ?>" onclick="<?= htmlspecialchars($onClose) ?>"></div>
<div class="drawer<?= $openClass ?>" id="<?= \h($id) ?>" style="<?= $widthStyle ?>">
    <div class="drawer-header">
        <span class="drawer-title"<?= $titleId !== '' ? ' id="' . \h($titleId) . '"' : '' ?>><?= \h($title) ?></span>
        <button class="drawer-close" onclick="<?= htmlspecialchars($onClose) ?>" title="关闭"><?= $closeIcon ?></button>
    </div>
    <div class="drawer-body"<?= $bodyId !== '' ? ' id="' . \h($bodyId) . '"' : '' ?>>
        <?php
    }

    /** 结束渲染抽屉（输出结束标签） */
    public static function end(): void
    {
        ?>
    </div>
</div>
        <?php
    }

    /** 完整渲染抽屉（一次性输出） */
    public static function render(array $cfg): void
    {
        $content = $cfg['content'] ?? $cfg['body'] ?? '';
        unset($cfg['content'], $cfg['body']);
        self::begin($cfg);
        echo $content;
        self::end();
    }
}
