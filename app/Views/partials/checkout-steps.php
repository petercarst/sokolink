<?php
declare(strict_types=1);
/**
 * Checkout progress indicator.
 *
 * aria-current marks the active step, and completed steps are links so a user
 * can go back and change a choice without losing the rest.
 *
 * @var int $step  1..3
 */
$step  = (int) ($step ?? 1);
$steps = [
    1 => ['label' => 'Delivery or collection', 'url' => route('checkout.fulfilment')],
    2 => ['label' => 'Payment',                'url' => route('checkout.payment')],
    3 => ['label' => 'Confirmation',           'url' => null],
];
?>
<nav aria-label="Checkout progress" class="tw-mb-10">
    <ol class="tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-list-none tw-p-0 tw-m-0">
        <?php foreach ($steps as $number => $item) : ?>
            <?php
            $isDone    = $number < $step;
            $isCurrent = $number === $step;
            ?>
            <li class="tw-flex tw-items-center tw-gap-2">
                <?php if ($number > 1) : ?>
                    <span class="t-muted" aria-hidden="true"><?= component('icon', ['name' => 'chevron-right', 'size' => 14]) ?></span>
                <?php endif; ?>

                <?php
                $inner = '<span class="sl-badge ' . ($isCurrent ? 'sl-badge-info' : ($isDone ? 'sl-badge-success' : 'sl-badge-neutral')) . '">'
                       . e((string) $number) . '</span>'
                       . '<span class="t-caption ' . ($isCurrent ? 't-body-strong' : 't-muted') . '">' . e($item['label']) . '</span>';
                ?>

                <?php if ($isDone && $item['url'] !== null) : ?>
                    <a class="tw-flex tw-items-center tw-gap-2 tw-no-underline" href="<?= e($item['url']) ?>"><?= $inner ?></a>
                <?php else : ?>
                    <span class="tw-flex tw-items-center tw-gap-2" <?= $isCurrent ? 'aria-current="step"' : '' ?>><?= $inner ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
