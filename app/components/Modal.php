<?php
declare(strict_types=1);

namespace App\Components;

/** 弹窗组件 */
final class Modal
{
    /** 开始渲染弹窗（输出开始标签） */
    public static function begin(array $cfg): void
    {
        $id        = $cfg['id'] ?? '';
        $title     = $cfg['title'] ?? '';
        $size      = $cfg['size'] ?? '';
        $style     = $cfg['style'] ?? '';
        $open      = $cfg['open'] ?? false;

        $sizeClass = $size !== '' ? 'modal-' . $size : '';
        $openClass = $open ? ' open' : '';
        $styleAttr = $style !== '' ? " style=\"{$style}\"" : '';
        ?>
<!-- 弹窗（来自 App\Components\Modal::begin） -->
<div class="overlay<?= $openClass ?>" id="<?= \h($id) ?>"<?= $styleAttr ?>>
    <div class="modal <?= $sizeClass ?>">
        <h3><?= \h($title) ?></h3>
        <?php
    }

    /** 结束渲染弹窗（输出结束标签，含底部按钮区） */
    public static function end($footer = null): void
    {
        $footerHtml = '';
        if (is_string($footer) && $footer !== '') {
            $footerHtml = $footer;
        } elseif (is_array($footer)) {
            $footerHtml = $footer['html'] ?? $footer['content'] ?? '';
        }

        if ($footerHtml === '' && $footer !== false) {
            $footerHtml = '<button type="button" class="btn btn-ghost" onclick="this.closest(\'.overlay\').classList.remove(\'open\')">取消</button>'
                . '<button type="button" class="btn btn-primary modal-confirm-btn" onclick="var o=this.closest(\'.overlay\');if(typeof handleModalConfirm===\'function\'){handleModalConfirm();}else{o.classList.remove(\'open\');}">确定</button>';
        }
        ?>
    </div>
    <?php if ($footerHtml !== ''): ?>
    <div class="modal-footer"><?= $footerHtml ?></div>
    <?php endif; ?>
</div>
</div>
        <?php
    }

    /** 完整渲染弹窗（一次性输出） */
    public static function render(array $cfg): void
    {
        $body   = $cfg['body'] ?? $cfg['content'] ?? '';
        $footer = $cfg['footer'] ?? null;
        unset($cfg['body'], $cfg['content'], $cfg['footer']);

        self::begin($cfg);
        echo $body;
        self::end($footer);
    }
}
