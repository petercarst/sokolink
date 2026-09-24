<?php
declare(strict_types=1);
/**
 * Delivery zones and fee rules.
 *
 * These rules are what the SERVER uses to compute a delivery charge. The
 * browser never sends a fee and any fee it did send would be discarded
 * (FR-CART-08). An address that matches no active zone is collection-only, and
 * the customer is told that at checkout rather than after paying.
 *
 * @var list<array<string,mixed>> $zones
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Delivery zones',
        'subtitle' => 'Where delivery is offered and what it costs. These rules are applied server-side.',
        'actions'  => [['label' => 'Add a zone', 'feature' => 'zone_add', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 18]) ?></span>
        <span>
            The fee a customer pays is calculated here, on the server, from the zone their address
            resolves to. Nothing the browser sends about a price is trusted. An address outside every
            active zone is offered collection only, stated before payment rather than after.
        </span>
    </div>

    <div class="sl-grid sl-grid-2">
        <?php foreach ($zones as $zone) : ?>
            <article class="sl-card <?= $zone['active'] ? '' : 'tw-opacity-60' ?>">
                <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                    <div>
                        <h2 class="t-heading-md tw-mb-1"><?= e($zone['name']) ?></h2>
                        <p class="t-micro t-muted tw-mb-0"><?= e($zone['region']) ?></p>
                    </div>
                    <?= component('badge', [
                        'status' => $zone['active'] ? 'active' : 'archived',
                        'label'  => $zone['active'] ? 'Active' : 'Not serving',
                    ]) ?>
                </div>

                <?= component('detail-list', ['items' => [
                    ['label' => 'Districts', 'value' => implode(', ', $zone['districts'])],
                    ['label' => 'Base fee', 'value' => $zone['base_fee'], 'type' => 'money'],
                    ['label' => 'Heavy item surcharge', 'value' => $zone['heavy_surcharge'], 'type' => 'money'],
                    ['label' => 'Free delivery over', 'value' => $zone['free_threshold'], 'type' => 'money'],
                    ['label' => 'Agents covering', 'value' => (string) $zone['agents']],
                ]]) ?>

                <?php if ($zone['active'] && (int) $zone['agents'] === 0) : ?>
                    <div class="sl-alert sl-alert-danger tw-mt-4 tw-mb-0">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 16]) ?></span>
                        <span class="t-caption">
                            Active with no agents. Customers here can order delivery that nobody can
                            carry out. Assign an agent or deactivate the zone.
                        </span>
                    </div>
                <?php endif; ?>

                <div class="tw-flex tw-gap-2 tw-flex-wrap tw-mt-5">
                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="zone_edit">
                        <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Edit fees</button>
                    </form>
                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="zone_toggle">
                        <button class="sl-btn sl-btn-ghost sl-btn-sm" type="submit">
                            <?= e($zone['active'] ? 'Stop serving this zone' : 'Start serving this zone') ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>
