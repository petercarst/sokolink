<?php
declare(strict_types=1);
/**
 * Basket - TRANSACTIONAL track.
 *
 * The basket is grouped by seller because each seller becomes its own
 * sub-order with its own fulfilment method and its own status (A-03). Showing
 * one flat list here would misrepresent what is about to happen.
 *
 * The fulfilment radios post as a GET to this same page, so switching between
 * collection and delivery re-prices on the SERVER and the new total is a real
 * one. They sit in a form of their own, referenced by the `form` attribute,
 * because the quantity and remove controls are POSTs and forms cannot nest.
 *
 * @var array<string,mixed>       $cart
 * @var list<array<string,mixed>> $suggested
 * @var array<string,mixed>|null  $deliveryAddress
 */
$empty           = (int) $cart['item_count'] === 0;
$deliveryAddress = $deliveryAddress ?? null;
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Basket', 'url' => null],
            ],
        ]) ?>

        <h1 class="t-display-lg tw-mb-2">Your basket</h1>
        <p class="t-body-md t-muted tw-mb-8">
            <?php if ($empty) : ?>
                Nothing in here yet.
            <?php else : ?>
                <?= e((string) $cart['item_count']) ?> item<?= (int) $cart['item_count'] === 1 ? '' : 's' ?>
                from <?= e((string) count($cart['groups'])) ?> seller<?= count($cart['groups']) === 1 ? '' : 's' ?>.
            <?php endif; ?>
        </p>

        <?php if ($empty) : ?>
            <?= component('empty-state', [
                'icon'           => 'cart',
                'title'          => 'Your basket is empty',
                'text'           => 'Once you add something it will appear here, grouped by seller so you can choose collection or delivery for each.',
                'actionUrl'      => route('catalog.index'),
                'actionLabel'    => 'Start shopping',
                'secondaryUrl'   => route('home'),
                'secondaryLabel' => 'Back to homepage',
            ]) ?>

            <section class="tw-mt-16">
                <h2 class="t-heading-xl tw-mb-6">Popular right now</h2>
                <div class="sl-grid sl-grid-4">
                    <?php foreach ($suggested as $product) : ?>
                        <?= component('product-card', ['product' => $product]) ?>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php else : ?>
            <div class="lg:tw-grid lg:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">

                <!-- Seller groups -->
                <div>
                    <!-- The radios above live in this form via form="cart-fulfilment".
                         A GET to this same page, so a choice can be compared, refreshed
                         and linked to - and every amount it produces is priced server-side. -->
                    <form method="get" action="<?= e(route('cart')) ?>" id="cart-fulfilment" class="tw-m-0">
                        <noscript>
                            <p class="t-caption t-muted tw-mb-4">
                                Pick collection or delivery below, then press Update to see the totals.
                            </p>
                        </noscript>
                    </form>

                    <?php if ($cart['problems'] !== []) : ?>
                        <div class="sl-alert sl-alert-warn tw-mb-6">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong>Sort these out before checkout</strong>
                                <ul class="tw-mt-2 tw-mb-0 tw-pl-5">
                                    <?php foreach ($cart['problems'] as $problem) : ?>
                                        <li class="t-caption"><?= e((string) $problem['message']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($cart['groups'] as $gi => $group) : ?>
                        <section class="sl-card sl-card-raised tw-mb-6" aria-labelledby="g<?= e((string) $gi) ?>-title">

                            <header class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-pb-4 tw-mb-4"
                                    style="border-bottom:1px solid var(--c-hairline-light)">
                                <div class="tw-flex tw-items-center tw-gap-3">
                                    <span class="sl-avatar"><?= e(initials($group['seller_name'])) ?></span>
                                    <div>
                                        <h2 class="t-heading-md tw-mb-0" id="g<?= e((string) $gi) ?>-title">
                                            <?= e($group['seller_name']) ?>
                                        </h2>
                                        <p class="t-micro t-muted tw-mb-0">
                                            Becomes its own order, tracked separately
                                        </p>
                                    </div>
                                </div>
                                <span class="sl-chip"><?= e((string) count($group['items'])) ?> line<?= count($group['items']) === 1 ? '' : 's' ?></span>
                            </header>

                            <!-- Fulfilment choice, per seller -->
                            <fieldset class="tw-border-0 tw-p-0 tw-m-0 tw-mb-6">
                                <legend class="sl-label">How would you like this part of the order?</legend>
                                <div class="tw-grid sm:tw-grid-cols-2 tw-gap-3">
                                    <?php foreach (['pickup' => 'Click and collect', 'delivery' => 'Home delivery'] as $method => $label) : ?>
                                        <?php $allowed = in_array($method, $group['fulfilment_options'], true); ?>
                                        <label class="sl-option<?= $group['selected_fulfilment'] === $method ? ' is-selected' : '' ?><?= $allowed ? '' : ' is-disabled' ?>">
                                            <input type="radio" class="tw-mt-1" form="cart-fulfilment"
                                                   name="fulfilment[<?= e((string) $group['seller_id']) ?>]"
                                                   value="<?= e($method) ?>"
                                                   <?= $group['selected_fulfilment'] === $method ? 'checked' : '' ?>
                                                   <?= $allowed ? '' : 'disabled' ?>
                                                   data-cart-autosubmit>
                                            <span>
                                                <span class="t-body-strong tw-block"><?= e($label) ?></span>
                                                <span class="t-micro t-muted">
                                                    <?php if (!$allowed) : ?>
                                                        Not offered by this seller
                                                    <?php elseif ($method === 'pickup') : ?>
                                                        No charge. Collect with a code.
                                                    <?php elseif ($deliveryAddress === null) : ?>
                                                        Worked out at checkout, from your address
                                                    <?php elseif ($group['selected_fulfilment'] === 'delivery') : ?>
                                                        <?= e(money($group['delivery_fee'])) ?> to <?= e((string) $deliveryAddress['district']) ?>
                                                    <?php else : ?>
                                                        Charged by zone. Choose this to see the amount
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button class="sl-btn sl-btn-outline-light sl-btn-sm tw-mt-3"
                                        type="submit" form="cart-fulfilment" data-cart-fallback>
                                    Update
                                </button>
                            </fieldset>

                            <?php if ($group['selected_fulfilment'] === 'pickup') : ?>
                                <form class="sl-field" method="post" action="<?= e(route('cart.store')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="seller_id" value="<?= e((string) $group['seller_id']) ?>">

                                    <label class="sl-label" for="store-<?= e((string) $group['seller_id']) ?>">Collect from</label>

                                    <?php if ($group['available_stores'] === []) : ?>
                                        <p class="sl-alert sl-alert-warn t-caption tw-mb-0">
                                            <span>No store of this seller stocks everything in this part of your basket.
                                            Choose home delivery, or remove a line.</span>
                                        </p>
                                    <?php else : ?>
                                        <div class="tw-flex tw-gap-2">
                                            <select class="sl-select" id="store-<?= e((string) $group['seller_id']) ?>"
                                                    name="store_id" data-cart-autosubmit>
                                                <?php foreach ($group['available_stores'] as $st) : ?>
                                                    <option value="<?= e((string) $st['id']) ?>"
                                                            <?= $st['id'] === $group['selected_store_id'] ? 'selected' : '' ?>
                                                            <?= $st['stocks_all_lines'] ? '' : 'disabled' ?>>
                                                        <?= e($st['name']) ?> - <?= e($st['district']) ?><?= $st['stocks_all_lines'] ? '' : ' (does not stock every item)' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="sl-btn sl-btn-outline-light" type="submit" data-cart-fallback>
                                                Use this store
                                            </button>
                                        </div>
                                        <span class="sl-help">
                                            Only stores that stock every item in this part of the order can be chosen (FR-CART-06).
                                        </span>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>

                            <!-- Lines -->
                            <ul class="tw-list-none tw-p-0 tw-m-0">
                                <?php foreach ($group['items'] as $item) : ?>
                                    <li class="tw-flex tw-flex-wrap tw-gap-4 tw-py-4"
                                        style="border-top:1px solid var(--c-hairline-light)"
                                        data-cart-line data-line-total="<?= e($item['line_total']) ?>">

                                        <a class="tw-shrink-0" style="width:88px"
                                           href="<?= e(route('product.show', ['slug' => $item['slug']])) ?>">
                                            <span class="sl-photo-frame tw-block" style="aspect-ratio:1/1;border-radius:var(--r-md)">
                                                <?= component('product-image', [
                                                    'path'  => $item['image_path'],
                                                    'tone'  => $item['tone'],
                                                    'label' => $item['name'],
                                                    'mark'  => false,
                                                ]) ?>
                                            </span>
                                        </a>

                                        <div class="tw-flex-1" style="min-width:12rem">
                                            <p class="t-micro t-muted tw-mb-1"><?= e($item['brand']) ?> &middot; <?= e($item['pack_size']) ?></p>
                                            <a class="t-body-strong tw-no-underline tw-block tw-mb-2"
                                               href="<?= e(route('product.show', ['slug' => $item['slug']])) ?>"><?= e($item['name']) ?></a>

                                            <?php if (!empty($item['price_changed_from'])) : ?>
                                                <p class="sl-alert sl-alert-warn t-micro tw-mb-2" style="padding:6px 10px">
                                                    <span>
                                                        The price changed from <?= e(money($item['price_changed_from'])) ?>
                                                        to <?= e(money($item['unit_price'])) ?> since you added this.
                                                        You will be asked to confirm before the order is placed.
                                                    </span>
                                                </p>
                                            <?php endif; ?>

                                            <div class="tw-flex tw-items-end tw-gap-4 tw-flex-wrap">
                                                <form method="post" action="<?= e(route('cart.update')) ?>"
                                                      class="tw-m-0 tw-flex tw-items-start tw-gap-2">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="cart_item_id" value="<?= e((string) $item['cart_item_id']) ?>">
                                                    <?= component('quantity', [
                                                        'value'     => (int) $item['qty'],
                                                        'max'       => (int) $item['qty_available'],
                                                        'name'      => 'qty',
                                                        'label'     => $item['name'],
                                                        'unitPrice' => $item['unit_price'],
                                                    ]) ?>
                                                    <button type="submit" class="sl-btn sl-btn-outline-light sl-btn-sm"
                                                            aria-label="Update quantity of <?= e($item['name']) ?>">
                                                        Update
                                                    </button>
                                                </form>

                                                <form method="post" action="<?= e(route('cart.remove')) ?>" class="tw-m-0">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="cart_item_id" value="<?= e((string) $item['cart_item_id']) ?>">
                                                    <button type="submit" class="sl-btn sl-btn-ghost sl-btn-sm"
                                                            aria-label="Remove <?= e($item['name']) ?> from basket">
                                                        <?= component('icon', ['name' => 'trash', 'size' => 16]) ?> Remove
                                                    </button>
                                                </form>
                                            </div>
                                        </div>

                                        <div class="tw-text-right" style="min-width:7rem">
                                            <p class="t-body-strong tabular tw-mb-1" data-line-display>
                                                <?= e(money($item['line_total'])) ?>
                                            </p>
                                            <p class="t-micro t-muted tw-mb-0"><?= e(money($item['unit_price'])) ?> each</p>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <footer class="tw-flex tw-justify-between tw-gap-4 tw-pt-4 tw-mt-2"
                                    style="border-top:1px solid var(--c-hairline-light)">
                                <span class="t-caption t-muted">
                                    Subtotal
                                    <?php if ($group['selected_fulfilment'] === 'delivery') : ?>
                                        + <?= e(money($group['delivery_fee'])) ?> delivery
                                    <?php endif; ?>
                                </span>
                                <span class="t-body-strong tabular">
                                    <?= e(money((string) ((float) $group['subtotal'] + (float) ($group['selected_fulfilment'] === 'delivery' ? $group['delivery_fee'] : 0)))) ?>
                                </span>
                            </footer>
                        </section>
                    <?php endforeach; ?>

                    <a class="sl-btn sl-btn-outline-light" href="<?= e(route('catalog.index')) ?>">
                        <?= component('icon', ['name' => 'arrow-left', 'size' => 16]) ?> Continue shopping
                    </a>
                </div>

                <!-- Totals -->
                <aside class="tw-mt-8 lg:tw-mt-0 lg:tw-sticky" style="top:96px">
                    <div class="sl-card sl-card-raised">
                        <h2 class="t-heading-xl tw-mb-6">Order summary</h2>

                        <dl class="tw-m-0 tw-mb-6">
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-2">
                                <dt class="t-body-md t-muted tw-m-0">Items</dt>
                                <dd class="t-body-md tabular tw-m-0" data-summary-items>
                                    <?= e(money($cart['totals']['items_subtotal'])) ?>
                                </dd>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-2">
                                <dt class="t-body-md t-muted tw-m-0">Delivery</dt>
                                <dd class="t-body-md tabular tw-m-0"><?= e(money($cart['totals']['delivery_total'])) ?></dd>
                            </div>
                            <?php if ((float) $cart['totals']['discount_total'] > 0) : ?>
                                <div class="tw-flex tw-justify-between tw-gap-4 tw-py-2">
                                    <dt class="t-body-md t-muted tw-m-0">Discount</dt>
                                    <dd class="t-body-md tabular tw-m-0">-<?= e(money($cart['totals']['discount_total'])) ?></dd>
                                </div>
                            <?php endif; ?>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-3 tw-mt-2"
                                 style="border-top:1px solid var(--c-hairline-light)">
                                <dt class="t-body-strong tw-m-0">Total</dt>
                                <dd class="t-heading-xl tabular tw-m-0" data-summary-total
                                    data-delivery="<?= e($cart['totals']['delivery_total']) ?>">
                                    <?= e(money($cart['totals']['grand_total'])) ?>
                                </dd>
                            </div>
                        </dl>

                        <?php if ($cart['can_checkout']) : ?>
                            <a class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block tw-mb-3"
                               href="<?= e(route('checkout.fulfilment')) ?><?= e(query_with([])) ?>">
                                Checkout
                            </a>
                        <?php else : ?>
                            <button class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block tw-mb-3" type="button" disabled>
                                Checkout
                            </button>
                            <p class="t-micro t-muted tw-mb-3">
                                Resolve the items flagged above and this will unlock.
                            </p>
                        <?php endif; ?>

                        <p class="t-micro t-muted tw-mb-0 tw-flex tw-items-start tw-gap-2">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 14]) ?></span>
                            <span>
                                Every amount is recalculated on the server from current prices before your order is
                                created. Nothing your browser sends is trusted.
                            </span>
                        </p>
                    </div>


                </aside>
            </div>
        <?php endif; ?>
    </div>
</section>
