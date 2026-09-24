<?php
declare(strict_types=1);
/**
 * Checkout step 1 - fulfilment, per seller.
 *
 * ONE delivery address for the whole order, not one per seller. The schema is
 * explicit about this: delivery_tasks snapshots a single recipient and address
 * per sub-order, and every sub-order of one order goes to the same door. The
 * Phase 1 draft offered a picker per seller; that was a guess made before the
 * data model existed, and this is the correction.
 *
 * @var array<string,mixed>       $cart
 * @var list<array<string,mixed>> $addresses
 * @var array<string,mixed>       $choices
 * @var int                       $step
 */
$anyDelivery = false;
foreach ($cart['groups'] as $g) {
    if ($g['selected_fulfilment'] === 'delivery') {
        $anyDelivery = true;
        break;
    }
}
$selectedAddressId = $choices['address_id'] ?? null;
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Basket', 'url' => route('cart')],
                ['label' => 'Checkout', 'url' => null],
            ],
        ]) ?>

        <h1 class="t-display-lg tw-mb-8">Checkout</h1>

        <?= partial('checkout-steps', ['step' => $step]) ?>

        <form method="post" action="<?= e(route('checkout.fulfilment.save')) ?>">
            <?= csrf_field() ?>

            <div class="lg:tw-grid lg:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
                <div>
                    <?php foreach ($cart['groups'] as $gi => $group) : ?>
                        <?php $isPickup = $group['selected_fulfilment'] === 'pickup'; ?>

                        <section class="sl-card sl-card-raised tw-mb-6" aria-labelledby="co-g<?= e((string) $gi) ?>">
                            <header class="tw-flex tw-items-center tw-gap-3 tw-pb-4 tw-mb-5"
                                    style="border-bottom:1px solid var(--c-hairline-light)">
                                <span class="sl-avatar"><?= e(initials($group['seller_name'])) ?></span>
                                <div>
                                    <h2 class="t-heading-md tw-mb-0" id="co-g<?= e((string) $gi) ?>">
                                        <?= e($group['seller_name']) ?>
                                    </h2>
                                    <p class="t-micro t-muted tw-mb-0">
                                        <?= e((string) count($group['items'])) ?> item<?= count($group['items']) === 1 ? '' : 's' ?>
                                        &middot; becomes order part <?= e((string) ($gi + 1)) ?> of <?= e((string) count($cart['groups'])) ?>
                                    </p>
                                </div>
                            </header>

                            <fieldset class="tw-border-0 tw-p-0 tw-m-0 tw-mb-6">
                                <legend class="sl-label">Delivery or collection</legend>
                                <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
                                    <?php foreach (['pickup' => 'Click and collect', 'delivery' => 'Home delivery'] as $method => $label) : ?>
                                        <?php $allowed = in_array($method, $group['fulfilment_options'], true); ?>
                                        <label class="sl-option<?= $group['selected_fulfilment'] === $method ? ' is-selected' : '' ?><?= $allowed ? '' : ' is-disabled' ?>">
                                            <input type="radio" class="tw-mt-1"
                                                   name="fulfilment[<?= e((string) $group['seller_id']) ?>]"
                                                   value="<?= e($method) ?>"
                                                   <?= $group['selected_fulfilment'] === $method ? 'checked' : '' ?>
                                                   <?= $allowed ? '' : 'disabled' ?>>
                                            <span>
                                                <span class="t-body-strong tw-block"><?= e($label) ?></span>
                                                <span class="t-micro t-muted">
                                                    <?php if (!$allowed) : ?>
                                                        Not offered by this seller
                                                    <?php elseif ($method === 'pickup') : ?>
                                                        Free. Collect with a code.
                                                    <?php else : ?>
                                                        <?= e(money($group['delivery_fee'])) ?>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <?php if ($isPickup) : ?>
                                <fieldset class="tw-border-0 tw-p-0 tw-m-0">
                                    <legend class="sl-label">Choose a collection point</legend>
                                    <div class="tw-flex tw-flex-col tw-gap-3">
                                        <?php foreach ($group['available_stores'] as $st) : ?>
                                            <label class="sl-option<?= $st['id'] === $group['selected_store_id'] ? ' is-selected' : '' ?><?= $st['stocks_all_lines'] ? '' : ' is-disabled' ?>">
                                                <input type="radio" class="tw-mt-1"
                                                       name="store[<?= e((string) $group['seller_id']) ?>]"
                                                       value="<?= e((string) $st['id']) ?>"
                                                       <?= $st['id'] === $group['selected_store_id'] ? 'checked' : '' ?>
                                                       <?= $st['stocks_all_lines'] ? '' : 'disabled' ?>>
                                                <span class="tw-flex-1">
                                                    <span class="t-body-strong tw-block"><?= e($st['name']) ?></span>
                                                    <span class="t-micro t-muted tw-block"><?= e($st['district']) ?></span>
                                                    <?php if (!$st['stocks_all_lines']) : ?>
                                                        <span class="t-micro tw-block tw-mt-1" style="color:var(--c-status-danger-fg)">
                                                            This store does not stock every item in this part of the order.
                                                        </span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>

                            <?php else : ?>
                                <p class="t-caption t-muted tw-mb-0">
                                    Delivered to the address chosen below, once for the whole order.
                                </p>
                            <?php endif; ?>

                            <!-- Line summary -->
                            <ul class="tw-list-none tw-p-0 tw-mt-6 tw-mb-0">
                                <?php foreach ($group['items'] as $item) : ?>
                                    <li class="tw-flex tw-justify-between tw-gap-3 tw-py-2"
                                        style="border-top:1px solid var(--c-hairline-light)">
                                        <span class="t-caption">
                                            <?= e((string) $item['qty']) ?> &times; <?= e($item['name']) ?>
                                            <span class="t-muted">(<?= e($item['pack_size']) ?>)</span>
                                        </span>
                                        <span class="t-caption tabular"><?= e(money($item['line_total'])) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endforeach; ?>

                    <!-- Delivery address: one for the order, shown only when something is being delivered -->
                    <section class="sl-card sl-card-raised tw-mb-6" id="delivery-address"
                             aria-labelledby="co-address-title"<?= $anyDelivery ? '' : ' hidden' ?>>
                        <h2 class="t-heading-md tw-mb-1" id="co-address-title">Delivery address</h2>
                        <p class="t-micro t-muted tw-mb-5">
                            Used for every part of this order that is being delivered.
                        </p>

                        <?php if (error_for('address_id') !== null) : ?>
                            <p class="sl-error tw-mb-4">
                                <?= component('icon', ['name' => 'alert', 'size' => 14]) ?><?= e((string) error_for('address_id')) ?>
                            </p>
                        <?php endif; ?>

                        <div class="tw-flex tw-flex-col tw-gap-3 tw-mb-4">
                            <?php foreach ($addresses as $address) : ?>
                                <?php $deliverable = $address['zone'] !== null; ?>
                                <label class="sl-option<?= $address['id'] === $selectedAddressId ? ' is-selected' : '' ?><?= $deliverable ? '' : ' is-disabled' ?>">
                                    <input type="radio" class="tw-mt-1" name="address_id"
                                           value="<?= e((string) $address['id']) ?>"
                                           <?= $address['id'] === $selectedAddressId ? 'checked' : '' ?>
                                           <?= $deliverable ? '' : 'disabled' ?>>
                                    <span class="tw-flex-1">
                                        <span class="t-body-strong tw-block">
                                            <?= e($address['label']) ?>
                                            <?php if ($address['is_default']) : ?>
                                                <span class="sl-chip sl-chip-mint tw-ml-2">Default</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="t-micro t-muted tw-block">
                                            <?= e($address['recipient']) ?> &middot; <?= e($address['phone_masked']) ?>
                                        </span>
                                        <span class="t-micro t-muted tw-block">
                                            <?= e(implode(', ', array_filter([
                                                $address['street'], $address['ward'],
                                                $address['district'], $address['region'],
                                            ]))) ?>
                                        </span>
                                        <span class="t-micro tw-block tw-mt-1">
                                            <?php if ($deliverable) : ?>
                                                Zone <strong><?= e((string) $address['zone']) ?></strong>
                                                &middot; from <?= e(money((string) $address['zone_fee'])) ?>
                                            <?php else : ?>
                                                <span style="color:var(--c-status-danger-fg)">
                                                    We do not deliver to <?= e($address['district']) ?> yet.
                                                    Collection only for this address.
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>

                            <label class="sl-option<?= $addresses === [] ? ' is-selected' : '' ?>">
                                <input type="radio" class="tw-mt-1" name="address_id" value="new"
                                       <?= $addresses === [] ? 'checked' : '' ?>>
                                <span class="t-body-strong">Use a new address</span>
                            </label>
                        </div>

                        <details class="sl-card tw-mb-0" style="padding:var(--s-lg)"<?= $addresses === [] || error_for('addr_street') !== null ? ' open' : '' ?>>
                            <summary class="t-body-strong" style="cursor:pointer">New address details</summary>
                            <div class="tw-mt-4">
                                <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                                    <?= component('field', ['name' => 'addr_recipient', 'label' => 'Recipient name', 'autocomplete' => 'name']) ?>
                                    <?= component('field', ['name' => 'addr_phone', 'label' => 'Phone number', 'type' => 'tel', 'placeholder' => '+255 7XX XXX XXX', 'autocomplete' => 'tel']) ?>
                                    <?= component('field', ['name' => 'addr_region', 'label' => 'Region']) ?>
                                    <?= component('field', ['name' => 'addr_district', 'label' => 'District']) ?>
                                    <?= component('field', ['name' => 'addr_ward', 'label' => 'Ward']) ?>
                                    <?= component('field', ['name' => 'addr_street', 'label' => 'Street and house number']) ?>
                                </div>
                                <?= component('field', [
                                    'name' => 'addr_landmark', 'label' => 'Nearest landmark',
                                    'help' => 'Helps the delivery agent find you. For example: blue gate opposite the pharmacy.',
                                ]) ?>
                                <?= component('field', [
                                    'name' => 'addr_instructions', 'label' => 'Delivery instructions', 'type' => 'textarea',
                                    'help' => 'Anything the agent should know, such as a bell that does not work.',
                                ]) ?>
                                <p class="sl-help tw-mb-0">
                                    The delivery charge is worked out from the zone your address falls into, on the
                                    server, after you submit. It is never taken from the browser.
                                </p>
                            </div>
                        </details>
                    </section>
                </div>

                <!-- Summary -->
                <aside class="tw-mt-8 lg:tw-mt-0 lg:tw-sticky" style="top:96px">
                    <div class="sl-card sl-card-raised">
                        <h2 class="t-heading-xl tw-mb-6">Summary</h2>

                        <dl class="tw-m-0 tw-mb-6">
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-2">
                                <dt class="t-body-md t-muted tw-m-0">Items</dt>
                                <dd class="t-body-md tabular tw-m-0"><?= e(money($cart['totals']['items_subtotal'])) ?></dd>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-2">
                                <dt class="t-body-md t-muted tw-m-0">Delivery</dt>
                                <dd class="t-body-md tabular tw-m-0"><?= e(money($cart['totals']['delivery_total'])) ?></dd>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-3 tw-mt-2"
                                 style="border-top:1px solid var(--c-hairline-light)">
                                <dt class="t-body-strong tw-m-0">Total</dt>
                                <dd class="t-heading-xl tabular tw-m-0"><?= e(money($cart['totals']['grand_total'])) ?></dd>
                            </div>
                        </dl>

                        <button class="sl-btn sl-btn-primary sl-btn-lg sl-btn-block tw-mb-3" type="submit">
                            Continue to payment
                        </button>

                        <a class="sl-btn sl-btn-ghost sl-btn-block" href="<?= e(route('cart')) ?>">
                            Back to basket
                        </a>

                        <p class="t-micro t-muted tw-mt-4 tw-mb-0">
                            Nothing is reserved yet. Stock is held inside the transaction that creates
                            the order, on the next step.
                        </p>
                    </div>


                </aside>
            </div>
        </form>
    </div>
</section>
