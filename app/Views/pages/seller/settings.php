<?php
declare(strict_types=1);
/**
 * Seller account and store settings.
 *
 * Only "Order handling" is connected to the database. The other panels still
 * show sample data and post to the Phase 1 sink, and the page says so.
 *
 * @var array<string,mixed> $seller     the sellers row
 * @var array<string,mixed> $codLimits  the platform's cash limits, for the explanation
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Store settings',
        'subtitle' => 'Your business details, how you handle orders, and what you are notified about.',
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            <strong>Only &ldquo;Order handling&rdquo; is connected so far.</strong>
            What you save there is stored and takes effect immediately. The other panels on this
            page still show sample data and are wired in a later Phase 4 stage.
        </span>
    </div>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Business details</h2>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="seller_settings">

                    <?= component('field', ['name' => 'business_name', 'label' => 'Trading name', 'required' => true,
                        'value' => 'Mama Lishe Provisions',
                        'help' => 'This is the seller name customers see on every product and order.']) ?>

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', ['name' => 'contact_name', 'label' => 'Main contact', 'required' => true, 'value' => 'Rehema Mushi']) ?>
                        <?= component('field', ['name' => 'contact_phone', 'label' => 'Contact number', 'type' => 'tel', 'required' => true, 'value' => '+255 712 345 412']) ?>
                    </div>

                    <?= component('field', ['name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email', 'required' => true,
                        'value' => 'seller.mama.lishe@sokolink.test',
                        'help' => 'Order alerts and platform notices go here. Customers never see it.']) ?>

                    <?= component('field', ['name' => 'registration_number', 'label' => 'Business registration number', 'value' => 'BRELA-448201']) ?>

                    <button class="sl-btn sl-btn-primary" type="submit">Save details</button>
                </form>
            </section>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">Order handling</h2>
                <p class="t-caption t-muted tw-mb-5">
                    These set what customers are promised. Being honest here causes fewer support
                    tickets than being optimistic.
                </p>

                <form method="post" action="<?= e(route('seller.settings.orders')) ?>">
                    <?= csrf_field() ?>

                    <?= component('field', [
                        'name' => 'prep_hours', 'label' => 'Typical preparation time (hours)', 'type' => 'number',
                        'value' => (string) $seller['prep_hours'], 'attrs' => ['min' => '1', 'max' => '168'],
                        'help' => 'How long from accepting an order to it being ready. Shown to customers at checkout.',
                    ]) ?>

                    <?= component('field', [
                        'name' => 'auto_accept', 'label' => 'Accepting orders', 'type' => 'select',
                        'value' => $seller['auto_accept'] ? 'auto' : 'manual',
                        'options' => [
                            'manual' => 'I accept each order myself',
                            'auto'   => 'Accept automatically when stock is available',
                        ],
                        'help' => 'Automatic acceptance is faster for customers but means you cannot decline before preparation starts.',
                    ]) ?>

                    <?= component('field', [
                        'name' => 'low_stock_threshold', 'label' => 'Warn me when stock falls below', 'type' => 'number',
                        'value' => (string) $seller['low_stock_threshold'], 'attrs' => ['min' => '0', 'max' => '1000'],
                        'help' => 'Per product per store.',
                    ]) ?>

                    <!-- The one payment decision that is genuinely the seller's.
                         Everything else is settled before the goods move; cash is
                         not, and it is their stock sitting on a shelf. -->
                    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
                        <legend class="sl-label">Cash on collection or delivery</legend>

                        <label class="sl-check">
                            <input type="checkbox" name="accepts_cod" value="1"
                                   <?= $seller['accepts_cod'] ? 'checked' : '' ?>>
                            <span class="sl-check-label">
                                Let customers pay in cash when they receive their order
                                <span class="t-micro t-muted tw-block tw-mt-1">
                                    You pick and hold the goods before any money changes hands, so a customer
                                    who does not turn up costs you the picking and the shelf space. The platform
                                    limits the exposure &mdash; cash is capped at
                                    <?= e(money($codLimits['max_order_value'])) ?> per order,
                                    <?= e((string) $codLimits['max_open_orders']) ?> open cash orders per customer,
                                    and it is withdrawn from anyone with
                                    <?= e((string) $codLimits['max_strikes']) ?> no-shows in
                                    <?= e((string) $codLimits['strike_window_days']) ?> days. Switching this off
                                    removes the risk entirely, and some customers with it.
                                </span>
                            </span>
                        </label>
                    </fieldset>

                    <button class="sl-btn sl-btn-primary" type="submit">Save order handling</button>
                </form>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-5">Notify me about</h2>

                <form method="post" action="<?= e(route('preview.submit')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="seller_settings">

                    <?php
                    $alerts = [
                        ['new_order', 'A new order arrives', 'So you can accept it before the customer wonders.', true],
                        ['low_stock', 'Stock falls below the threshold', 'Per product per store.', true],
                        ['overdue_pickup', 'An order is not collected in time', 'Before you have to decide whether to return it to stock.', true],
                        ['failed_delivery', 'A delivery attempt fails', 'With the agent\'s reason.', true],
                        ['new_review', 'A customer leaves a review', 'Including ones you may want to reply to.', false],
                        ['payout_summary', 'Weekly sales summary', 'A digest rather than individual messages.', true],
                    ];
                    foreach ($alerts as [$key, $label, $desc, $on]) : ?>
                        <label class="sl-check">
                            <input type="checkbox" name="alerts[]" value="<?= e($key) ?>" <?= $on ? 'checked' : '' ?>>
                            <span class="sl-check-label">
                                <?= e($label) ?>
                                <span class="t-micro t-muted tw-block"><?= e($desc) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>

                    <button class="sl-btn sl-btn-primary tw-mt-4" type="submit">Save notifications</button>
                </form>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Account</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Status', 'value' => 'active', 'type' => 'badge', 'badgeLabel' => 'Approved'],
                    ['label' => 'Approved on', 'value' => '2026-02-11 09:20:00', 'type' => 'datetime'],
                    ['label' => 'Stores', 'value' => '2'],
                    ['label' => 'Orders fulfilled', 'value' => '412'],
                    ['label' => 'Commission', 'value' => '5.0% per sale'],
                ]]) ?>
            </div>

            <div class="sl-card sl-card-band tw-mb-6">
                <h2 class="t-heading-md tw-mb-3">Payouts</h2>
                <p class="t-caption tw-mb-0">
                    Commission is recorded against every order so the figures are there from day one,
                    but payouts and settlement are not built yet. No money is moving through the
                    platform in this phase, and nothing here implies otherwise.
                </p>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Taking a break</h2>
                <p class="t-caption t-muted tw-mb-4">
                    Pausing hides all your products from the catalogue. Orders already placed still
                    need fulfilling.
                </p>
                <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="seller_pause">
                    <button class="sl-btn sl-btn-danger sl-btn-sm sl-btn-block" type="submit">
                        Pause my store
                    </button>
                </form>
            </div>
        </aside>
    </div>
</div>
