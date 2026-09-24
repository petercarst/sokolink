<?php
declare(strict_types=1);
/**
 * Edit a store: details, hours, collection instructions.
 *
 * Pickup instructions matter more than they look. They are what a customer
 * reads standing outside your building, and they go into the ready-to-collect
 * email - so "ring the bell at the side gate" prevents a support ticket.
 *
 * @var array<string,mixed> $store
 */
$days = [
    'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
    'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
];
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Stores', 'url' => route('seller.stores')],
            ['label' => $store['name'], 'url' => null],
        ],
        'title'    => $store['name'],
        'subtitle' => 'Where customers find you, when you are open, and how collection works here.',
        'actions'  => [['label' => 'View public page', 'url' => route('store.show', ['slug' => $store['slug']]), 'icon' => 'external']],
    ]) ?>

    <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="feature" value="store_save">

        <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
            <div>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-5">Store details</h2>

                    <?= component('field', ['name' => 'store_name', 'label' => 'Store name', 'required' => true, 'value' => $store['name'],
                        'help' => 'Customers choose between your stores by this name, so make the location obvious.']) ?>

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', ['name' => 'region', 'label' => 'Region', 'required' => true, 'value' => $store['region']]) ?>
                        <?= component('field', ['name' => 'district', 'label' => 'District', 'required' => true, 'value' => $store['district']]) ?>
                    </div>

                    <?= component('field', ['name' => 'street', 'label' => 'Street and building', 'required' => true, 'value' => $store['street']]) ?>

                    <?= component('field', ['name' => 'landmark', 'label' => 'Nearest landmark', 'value' => $store['landmark'] ?? '',
                        'help' => 'What someone would look for from the street.']) ?>

                    <?= component('field', ['name' => 'phone', 'label' => 'Store phone', 'type' => 'tel', 'required' => true,
                        'value' => '+255 712 345 412',
                        'help' => 'Customers see a masked version until they have an order with this store.']) ?>
                </section>

                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Opening hours</h2>
                    <p class="t-caption t-muted tw-mb-5">
                        Shown in <?= e((string) config('app.display_timezone')) ?>. Leave a day blank to
                        mark it closed.
                    </p>

                    <?php foreach ($days as $key => $label) : ?>
                        <?php
                        $value  = $store['hours'][$key] ?? 'closed';
                        $closed = $value === 'closed';
                        [$from, $to] = $closed ? ['', ''] : explode('-', $value);
                        ?>
                        <div class="tw-flex tw-flex-wrap tw-items-end tw-gap-3 tw-py-3"
                             style="border-bottom:1px solid var(--c-hairline-light)">
                            <span class="t-body-strong" style="width:6rem"><?= e($label) ?></span>

                            <span>
                                <label class="visually-hidden" for="from-<?= e($key) ?>">Opens on <?= e($label) ?></label>
                                <input class="sl-input tabular" style="width:7rem" type="time"
                                       id="from-<?= e($key) ?>" name="hours[<?= e($key) ?>][from]" value="<?= e($from) ?>">
                            </span>

                            <span class="t-muted" aria-hidden="true">&ndash;</span>

                            <span>
                                <label class="visually-hidden" for="to-<?= e($key) ?>">Closes on <?= e($label) ?></label>
                                <input class="sl-input tabular" style="width:7rem" type="time"
                                       id="to-<?= e($key) ?>" name="hours[<?= e($key) ?>][to]" value="<?= e($to) ?>">
                            </span>

                            <label class="sl-check tw-mb-0">
                                <input type="checkbox" name="hours[<?= e($key) ?>][closed]" value="1" <?= $closed ? 'checked' : '' ?>>
                                <span class="sl-check-label t-caption">Closed</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="sl-card">
                    <h2 class="t-heading-xl tw-mb-2">Collection instructions</h2>
                    <p class="t-caption t-muted tw-mb-5">
                        This goes into the ready-to-collect email and onto the customer's order page.
                        It is what they read standing outside your building.
                    </p>

                    <?= component('field', [
                        'name' => 'pickup_instructions', 'label' => 'What should the customer do when they arrive?',
                        'type' => 'textarea', 'required' => true, 'value' => $store['pickup_instructions'],
                        'help' => 'Where the counter is, which door, whether there is parking, anything unexpected.',
                    ]) ?>

                    <?= component('field', [
                        'name' => 'collection_window_hours', 'label' => 'Hold orders for (hours)', 'type' => 'number',
                        'value' => '72', 'attrs' => ['min' => '4', 'max' => '336'],
                        'help' => 'After this an order is flagged overdue and both sides are told. Shorten it for chilled goods.',
                    ]) ?>
                </section>
            </div>

            <aside class="tw-mt-8 xl:tw-mt-0 xl:tw-sticky" style="top:96px">
                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-4">This store</h2>
                    <?= component('detail-list', ['items' => [
                        ['label' => 'Status', 'value' => 'published', 'type' => 'badge'],
                        ['label' => 'Products', 'value' => (string) $store['product_count']],
                        ['label' => 'Rating', 'value' => number_format((float) $store['rating'], 1)],
                        ['label' => 'Reviews', 'value' => (string) $store['review_count']],
                    ]]) ?>

                    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0 tw-mt-4">
                        <legend class="sl-label">This store offers</legend>
                        <label class="sl-check">
                            <input type="checkbox" name="accepts[]" value="pickup"
                                   <?= in_array('pickup', $store['accepts'], true) ? 'checked' : '' ?>>
                            <span class="sl-check-label">Click and collect</span>
                        </label>
                        <label class="sl-check">
                            <input type="checkbox" name="accepts[]" value="delivery"
                                   <?= in_array('delivery', $store['accepts'], true) ? 'checked' : '' ?>>
                            <span class="sl-check-label">
                                Home delivery
                                <span class="t-micro t-muted tw-block">Platform agents collect from here.</span>
                            </span>
                        </label>
                    </fieldset>

                    <button class="sl-btn sl-btn-primary sl-btn-block tw-mt-4" type="submit">Save store</button>
                </div>

                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-3">Temporarily closing</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Pausing hides this store from collection options. Orders already placed still
                        need fulfilling &mdash; pausing does not cancel them.
                    </p>
                    <button class="sl-btn sl-btn-danger sl-btn-sm sl-btn-block" type="submit"
                            name="feature" value="store_pause">
                        Pause this store
                    </button>
                </div>
            </aside>
        </div>
    </form>
</div>
