<?php
declare(strict_types=1);
/**
 * Flash messages.
 *
 * role="status" with aria-live="polite" so a screen reader announces the result
 * of an action without the user having to go looking for it (NFR-USA-02).
 *
 * @var list<array{type:string,message:string}> $flashes
 */
$flashes = $flashes ?? [];

$toneFor = [
    'success' => 'success',
    'info'    => 'info',
    'warning' => 'warn',
    'error'   => 'danger',
    'danger'  => 'danger',
];

$iconFor = [
    'success' => 'check-circle',
    'info'    => 'info',
    'warn'    => 'alert',
    'danger'  => 'alert',
];
?>
<?php if ($flashes !== []) : ?>
    <div class="sl-container tw-pt-6" role="status" aria-live="polite">
        <?php foreach ($flashes as $flash) : ?>
            <?php $tone = $toneFor[$flash['type']] ?? 'info'; ?>
            <div class="sl-alert sl-alert-<?= e($tone) ?> tw-mb-3">
                <span class="tw-shrink-0 tw-mt-px">
                    <?= component('icon', ['name' => $iconFor[$tone] ?? 'info', 'size' => 18]) ?>
                </span>
                <span><?= e($flash['message']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
