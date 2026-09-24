<?php
declare(strict_types=1);
/**
 * Quantity stepper.
 *
 * The minus/plus buttons are real buttons with accessible names, and the field
 * itself stays editable so a keyboard user can type a number rather than
 * pressing plus fourteen times.
 *
 * The JS in public/assets/js/cart.js updates the displayed line and basket
 * totals immediately. That is a genuine frontend interaction, but it is a
 * PREVIEW only - in Phase 3 the server recalculates every amount and the client
 * figure is discarded (FR-CART-03).
 *
 * @var int    $value
 * @var int    $max
 * @var string $name
 * @var string $label      accessible name, e.g. the product name
 * @var string $unitPrice  decimal string, used by the JS preview
 * @var bool   $onDark     render for the cinematic track
 */
$value     = (int) ($value ?? 1);
$max       = (int) ($max ?? 99);
$name      = $name ?? 'qty';
$id        = 'q-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
$unitPrice = $unitPrice ?? '0.00';
$onDark    = $onDark ?? false;
$btnCls    = $onDark ? 'sl-btn-outline-dark' : 'sl-btn-outline-light';
$inputCls  = $onDark ? 'sl-input sl-input-dark' : 'sl-input';
$noteCls   = $onDark ? 'sl-help t-muted-dark' : 'sl-help';
?>
<div class="tw-inline-flex tw-items-center tw-gap-1" data-qty-stepper data-unit-price="<?= e($unitPrice) ?>">
    <button type="button" class="sl-btn <?= e($btnCls) ?> sl-btn-icon" data-qty-dec
            aria-label="Decrease quantity of <?= e($label ?? 'item') ?>">
        <?= component('icon', ['name' => 'minus', 'size' => 16]) ?>
    </button>

    <label class="visually-hidden" for="<?= e($id) ?>">Quantity of <?= e($label ?? 'item') ?></label>
    <input class="<?= e($inputCls) ?> tabular tw-text-center" style="width:4.25rem" type="number"
           id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e((string) $value) ?>"
           min="1" max="<?= e((string) $max) ?>" step="1" inputmode="numeric" data-qty-input>

    <button type="button" class="sl-btn <?= e($btnCls) ?> sl-btn-icon" data-qty-inc
            aria-label="Increase quantity of <?= e($label ?? 'item') ?>">
        <?= component('icon', ['name' => 'plus', 'size' => 16]) ?>
    </button>
</div>
<p class="<?= e($noteCls) ?> tw-mt-1" data-qty-max-note><?= e((string) $max) ?> available</p>
