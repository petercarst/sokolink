<?php
declare(strict_types=1);
/**
 * Checkout step 2 - payment.
 *
 * The brief is explicit that no real payment integration may be claimed. v1
 * ships a sandbox gateway and cash on fulfilment; the mobile-money providers
 * are shown as what they are - not connected - rather than as working options
 * that fail after you tap them.
 *
 * @var array<string,mixed>      $cart
 * @var list<array<string,mixed>> $paymentOptions  from PaymentService, with availability
 * @var array<string,mixed>|null $address
 * @var string                   $idempotencyKey
 * @var int                      $step
 */
$user = \App\Core\Auth::user();
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Basket', 'url' => route('cart')],
                ['label' => 'Checkout', 'url' => route('checkout.fulfilment')],
                ['label' => 'Payment', 'url' => null],
            ],
        ]) ?>

        <h1 class="t-display-lg tw-mb-8">Payment</h1>

        <?= partial('checkout-steps', ['step' => $step]) ?>

        <form method="post" action="<?= e(route('checkout.place')) ?>" data-submit-once>
            <?= csrf_field() ?>
            <!-- Minted when this page was rendered and held in the session. A
                 double click, a refreshed POST or a browser retry all carry the
                 same key, and the order is created once (FR-ORD-04). -->
            <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">

            <div class="lg:tw-grid lg:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
                <div>
                    <section class="sl-card sl-card-raised tw-mb-6">
                        <h2 class="t-heading-xl tw-mb-2">How would you like to pay?</h2>
                        <p class="t-caption t-muted tw-mb-5">
                            Every method below is listed with whether it can be used right now and,
                            if not, why. A method that would fail after you press the button is not
                            an option, it is a trap.
                        </p>

                        <fieldset class="tw-border-0 tw-p-0 tw-m-0">
                            <legend class="visually-hidden">Payment method</legend>
                            <div class="tw-flex tw-flex-col tw-gap-3">
                                <?php $firstAvailable = true; ?>
                                <?php foreach ($paymentOptions as $option) : ?>

                                    <?php if ($option['available']) : ?>
                                        <label class="sl-option<?= $firstAvailable ? ' is-selected' : '' ?>">
                                            <input type="radio" class="tw-mt-1" name="payment_method"
                                                   value="<?= e($option['key']) ?>"
                                                   <?= $firstAvailable ? 'checked' : '' ?>>
                                            <span class="tw-flex-1">
                                                <span class="t-body-strong tw-block"><?= e($option['label']) ?></span>
                                                <span class="t-micro t-muted tw-block"><?= e($option['description']) ?></span>
                                            </span>
                                            <?php if ($option['simulated']) : ?>
                                                <span class="sl-chip">Simulated</span>
                                            <?php endif; ?>
                                        </label>
                                        <?php $firstAvailable = false; ?>

                                    <?php else : ?>
                                        <div class="sl-option is-disabled">
                                            <span class="tw-mt-1" style="width:20px" aria-hidden="true">
                                                <?= component('icon', ['name' => 'lock', 'size' => 18]) ?>
                                            </span>
                                            <span class="tw-flex-1">
                                                <span class="t-body-strong tw-block"><?= e($option['label']) ?></span>
                                                <span class="t-micro t-muted tw-block tw-mb-2"><?= e($option['description']) ?></span>
                                                <span class="t-micro tw-block" style="color:var(--c-status-warn-fg)">
                                                    <?= e($option['reason']) ?>
                                                </span>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    </section>

                    <!-- What each method actually does to the order, because
                         "paid" means different things and the difference is the
                         thing customers get wrong. -->
                    <section class="sl-card tw-mb-6">
                        <h2 class="t-heading-md tw-mb-4">What happens after you place it</h2>

                        <ol class="sl-timeline tw-mb-0">
                            <li class="sl-timeline-item is-current">
                                <p class="t-body-strong tw-mb-1">Your stock is held</p>
                                <p class="t-caption t-muted tw-mb-0">
                                    The moment the order exists, the units are reserved at the store.
                                    Nobody else can be sold them.
                                </p>
                            </li>
                            <li class="sl-timeline-item">
                                <p class="t-body-strong tw-mb-1">Payment is settled, or is not needed yet</p>
                                <p class="t-caption t-muted tw-mb-0">
                                    Paying now: the order waits until the provider confirms it to our
                                    server &mdash; we never treat a message from your browser as proof.
                                    If nothing confirms it within
                                    <?= e((string) config('payment.expiry_minutes', 60)) ?> minutes, the
                                    order is released and the stock goes back on sale.
                                    <br>
                                    Paying cash: there is nothing to confirm, so it goes to the seller
                                    straight away and does not expire. You pay the person who hands it over.
                                </p>
                            </li>
                            <li class="sl-timeline-item">
                                <p class="t-body-strong tw-mb-1">The seller accepts and prepares it</p>
                                <p class="t-caption t-muted tw-mb-0">
                                    Each seller in your basket handles their own part, and you are told
                                    about each one separately.
                                </p>
                            </li>
                            <li class="sl-timeline-item">
                                <p class="t-body-strong tw-mb-1">You collect it, or it is delivered</p>
                                <p class="t-caption t-muted tw-mb-0">
                                    Either way you get a one-time code. Nobody can hand your order over
                                    without it, and nobody here can read it back to you &mdash; only its
                                    hash is stored.
                                </p>
                            </li>
                        </ol>
                    </section>

                    <!-- Read, not editable. The order copies these from the account and
                         from the delivery address, so a field here that the server
                         ignores would be a control that does nothing. -->
                    <section class="sl-card sl-card-raised tw-mb-6">
                        <h2 class="t-heading-xl tw-mb-5">Contact for this order</h2>

                        <?php
                        $contact = [
                            ['label' => 'Name',  'value' => trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''))],
                            ['label' => 'Email', 'value' => (string) ($user['email'] ?? '')],
                            ['label' => 'Phone', 'value' => (string) ($address['phone'] ?? $user['phone'] ?? '')],
                        ];

                        if ($address !== null) {
                            $contact[] = ['label' => 'Deliver to', 'value' => implode(', ', array_filter([
                                (string) $address['street'], (string) ($address['ward'] ?? ''),
                                (string) $address['district'], (string) $address['region'],
                            ]))];
                        }
                        ?>
                        <?= component('detail-list', [
                            'items' => array_values(array_filter(
                                $contact,
                                static fn (array $row): bool => $row['value'] !== ''
                            )),
                        ]) ?>

                        <p class="sl-help tw-mt-4 tw-mb-0">
                            Your receipt, collection code and order updates go to this address.
                            <a href="<?= e(route('customer.profile')) ?>">Change your details</a>
                            before placing the order if any of this is wrong.
                        </p>
                    </section>

                    <section class="sl-card sl-card-raised">
                        <h2 class="t-heading-xl tw-mb-5">Before you place the order</h2>

                        <label class="sl-check">
                            <input type="checkbox" name="accept_terms" required>
                            <span class="sl-check-label">
                                I accept the <a href="<?= e(route('page.terms')) ?>">terms of service</a>
                                and the <a href="<?= e(route('page.privacy')) ?>">privacy notice</a>.
                                <span class="sl-required" aria-hidden="true">*</span>
                            </span>
                        </label>

                        <label class="sl-check">
                            <input type="checkbox" name="marketing_consent">
                            <span class="sl-check-label">
                                Send me reorder reminders and occasional offers by email.
                                <span class="t-micro t-muted tw-block tw-mt-1">
                                    Optional and off by default. Order updates are sent either way because they are
                                    part of the service. Leaving this unticked changes nothing if you have already
                                    opted in - to stop marketing email, use your
                                    <a href="<?= e(route('customer.preferences')) ?>">notification preferences</a>
                                    or the one-click unsubscribe link in any message, which works without logging in.
                                </span>
                            </span>
                        </label>
                    </section>
                </div>

                <aside class="tw-mt-8 lg:tw-mt-0 lg:tw-sticky" style="top:96px">
                    <div class="sl-card sl-card-raised">
                        <h2 class="t-heading-xl tw-mb-5">Order summary</h2>

                        <?php foreach ($cart['groups'] as $group) : ?>
                            <div class="tw-pb-3 tw-mb-3" style="border-bottom:1px solid var(--c-hairline-light)">
                                <p class="t-caption t-body-strong tw-mb-1"><?= e($group['seller_name']) ?></p>
                                <p class="t-micro t-muted tw-mb-2">
                                    <?= component('icon', [
                                        'name' => $group['selected_fulfilment'] === 'delivery' ? 'truck' : 'package',
                                        'size' => 13,
                                    ]) ?>
                                    <?= e($group['selected_fulfilment'] === 'delivery' ? 'Home delivery' : 'Click and collect') ?>
                                </p>
                                <?php foreach ($group['items'] as $item) : ?>
                                    <div class="tw-flex tw-justify-between tw-gap-2 tw-py-1">
                                        <span class="t-micro t-muted"><?= e((string) $item['qty']) ?> &times; <?= e(str_limit($item['name'], 30)) ?></span>
                                        <span class="t-micro tabular"><?= e(money($item['line_total'])) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <dl class="tw-m-0 tw-mb-5">
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-1">
                                <dt class="t-caption t-muted tw-m-0">Items</dt>
                                <dd class="t-caption tabular tw-m-0"><?= e(money($cart['totals']['items_subtotal'])) ?></dd>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-1">
                                <dt class="t-caption t-muted tw-m-0">Delivery</dt>
                                <dd class="t-caption tabular tw-m-0"><?= e(money($cart['totals']['delivery_total'])) ?></dd>
                            </div>
                            <div class="tw-flex tw-justify-between tw-gap-4 tw-py-3 tw-mt-2"
                                 style="border-top:1px solid var(--c-hairline-light)">
                                <dt class="t-body-strong tw-m-0">Total to pay</dt>
                                <dd class="t-heading-xl tabular tw-m-0"><?= e(money($cart['totals']['grand_total'])) ?></dd>
                            </div>
                        </dl>

                        <button class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block tw-mb-3" type="submit">
                            Place order
                        </button>

                        <a class="sl-btn sl-btn-ghost sl-btn-block" href="<?= e(route('checkout.fulfilment')) ?>">
                            Back to delivery options
                        </a>

                        <p class="t-micro t-muted tw-mt-4 tw-mb-0 tw-flex tw-items-start tw-gap-2">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'shield', 'size' => 14]) ?></span>
                            <span>
                                Card and mobile-money credentials are never stored by this platform, in any phase.
                            </span>
                        </p>
                    </div>

                    <div class="tw-mt-4">
                        <?= component('devnote', [
                            'text' => 'No payment provider is connected. The sandbox driver settles locally so the '
                                    . 'order lifecycle can be exercised end to end; nothing is charged and no card or '
                                    . 'mobile-money credential is handled. Cash on collection or delivery is real.',
                        ]) ?>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</section>
