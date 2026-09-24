<?php
declare(strict_types=1);
/**
 * Saved delivery addresses.
 *
 * Each address shows the delivery ZONE it resolves to and the fee that implies,
 * so the charge is never a surprise at checkout (FR-CART-08). An address with
 * no zone is shown as collection-only rather than silently failing later.
 *
 * @var list<array<string,mixed>> $addresses
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Delivery addresses',
        'subtitle' => 'Where we can deliver, and what each address costs to reach.',
        'actions'  => [['label' => 'Add an address', 'feature' => 'address_add', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <div class="sl-grid sl-grid-3">
        <?php foreach ($addresses as $address) : ?>
            <article class="sl-card <?= !empty($address['is_default']) ? 'sl-card-featured' : '' ?>">
                <div class="tw-flex tw-items-start tw-justify-between tw-gap-2 tw-mb-3">
                    <h2 class="t-heading-md tw-mb-0"><?= e($address['label']) ?></h2>
                    <?php if (!empty($address['is_default'])) : ?>
                        <span class="sl-chip">Default</span>
                    <?php endif; ?>
                </div>

                <address class="t-body-md tw-not-italic tw-mb-3">
                    <?= e($address['recipient']) ?><br>
                    <?= e($address['street']) ?><br>
                    <?php if (!empty($address['ward'])) : ?><?= e($address['ward']) ?>, <?php endif; ?>
                    <?= e($address['district']) ?><br>
                    <?= e($address['region']) ?>
                </address>

                <p class="t-caption t-muted tw-mb-3">
                    <?= component('icon', ['name' => 'phone', 'size' => 14]) ?>
                    <?= e($address['phone_masked']) ?>
                </p>

                <?php if (!empty($address['landmark'])) : ?>
                    <p class="t-micro t-muted tw-mb-2"><?= e($address['landmark']) ?></p>
                <?php endif; ?>

                <?php if (!empty($address['instructions'])) : ?>
                    <p class="t-micro t-muted tw-mb-3"><strong>Note:</strong> <?= e($address['instructions']) ?></p>
                <?php endif; ?>

                <?php if (!empty($address['zone'])) : ?>
                    <p class="t-caption tw-mb-4">
                        <span class="sl-badge sl-badge-info"><?= e($address['zone']) ?></span>
                        <span class="t-muted tw-ml-2">Delivery <?= e(money($address['zone_fee'])) ?></span>
                    </p>
                <?php else : ?>
                    <div class="sl-alert sl-alert-warn t-micro tw-mb-4" style="padding:8px 12px">
                        <span>
                            No delivery zone covers this address yet, so orders to it are
                            collection-only. We would rather say that here than fail at checkout.
                        </span>
                    </div>
                <?php endif; ?>

                <div class="tw-flex tw-gap-2 tw-flex-wrap">
                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="address_edit">
                        <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Edit</button>
                    </form>
                    <?php if (empty($address['is_default'])) : ?>
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="address_default">
                            <button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">Make default</button>
                        </form>
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="address_delete">
                            <button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit"
                                    aria-label="Delete the <?= e($address['label']) ?> address">
                                <?= component('icon', ['name' => 'trash', 'size' => 15]) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
