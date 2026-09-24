<?php
declare(strict_types=1);
/**
 * Empty state (NFR-USA-03).
 *
 * An empty state always offers a way forward. A dead end is worse than a
 * wrong guess.
 *
 * @var string      $icon
 * @var string      $title
 * @var string      $text
 * @var string|null $actionUrl
 * @var string|null $actionLabel
 * @var string|null $secondaryUrl
 * @var string|null $secondaryLabel
 */
$icon = $icon ?? 'package';
?>
<div class="sl-empty">
    <div class="sl-empty-icon"><?= component('icon', ['name' => $icon, 'size' => 26]) ?></div>
    <p class="sl-empty-title"><?= e($title ?? 'Nothing here yet') ?></p>
    <p class="sl-empty-text"><?= e($text ?? '') ?></p>
    <?php if (!empty($actionUrl)) : ?>
        <div class="tw-flex tw-flex-wrap tw-gap-3 tw-justify-center">
            <a class="sl-btn sl-btn-primary" href="<?= e($actionUrl) ?>"><?= e($actionLabel ?? 'Continue') ?></a>
            <?php if (!empty($secondaryUrl)) : ?>
                <a class="sl-btn sl-btn-outline-light" href="<?= e($secondaryUrl) ?>"><?= e($secondaryLabel ?? 'Back') ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
