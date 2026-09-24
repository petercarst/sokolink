<?php
declare(strict_types=1);
/**
 * Terms of service - TRANSACTIONAL track.
 *
 * Long-form reading is deliberately on the light canvas. Several hundred words
 * of body text on pure black is measurably harder to read, and legal terms are
 * the last place to trade comprehension for atmosphere. Documented departure
 * from the cinematic track for public pages.
 */
?>

<section class="sl-section-tight">
    <div class="sl-container-read sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Terms of service', 'url' => null],
            ],
        ]) ?>

        <h1 class="t-display-lg tw-mb-4">Terms of service</h1>
        <p class="t-caption t-muted tw-mb-8">Version 0.1 (draft) &middot; last updated 21 September 2026</p>

        <div class="sl-alert sl-alert-warn tw-mb-10">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
            <span>
                <strong>Draft, not legally reviewed.</strong>
                This is a plain-language description of how the platform is intended to work, written
                so the page is not a placeholder. It has not been drafted or checked by a lawyer and
                it is not fit to govern a real trading relationship. Before launch it must be replaced
                with terms reviewed against Tanzanian consumer and e-commerce law.
            </span>
        </div>

        <nav aria-label="On this page" class="sl-card tw-mb-10">
            <p class="t-eyebrow t-muted tw-mb-3">On this page</p>
            <ol class="tw-m-0 tw-pl-5 t-caption">
                <li class="tw-py-1"><a href="#accounts">Accounts</a></li>
                <li class="tw-py-1"><a href="#ordering">Ordering and pricing</a></li>
                <li class="tw-py-1"><a href="#fulfilment">Collection and delivery</a></li>
                <li class="tw-py-1"><a href="#payment">Payment and refunds</a></li>
                <li class="tw-py-1"><a href="#sellers">Selling on the platform</a></li>
                <li class="tw-py-1"><a href="#conduct">Acceptable use</a></li>
                <li class="tw-py-1"><a href="#liability">Our role and limits</a></li>
            </ol>
        </nav>

        <article class="tw-flex tw-flex-col tw-gap-10">

            <section id="accounts">
                <h2 class="t-heading-xl tw-mb-4">1. Accounts</h2>
                <p class="t-body-md tw-mb-3">
                    You need an account to place an order. You must give accurate contact details,
                    because they are how a seller or a delivery agent reaches you about an order you
                    placed.
                </p>
                <p class="t-body-md tw-mb-3">
                    You are responsible for keeping your password to yourself. If you think someone
                    else has it, reset it - that ends every other session on the account immediately.
                </p>
                <p class="t-body-md tw-mb-0">
                    Seller, delivery agent, support and administrator accounts are created or approved
                    by us. Seller accounts cannot trade until an administrator approves the
                    application.
                </p>
            </section>

            <section id="ordering">
                <h2 class="t-heading-xl tw-mb-4">2. Ordering and pricing</h2>
                <p class="t-body-md tw-mb-3">
                    Prices are set by the seller and shown in Tanzanian Shillings. The price that
                    applies is the one held on our server at the moment the order is created, not the
                    one your browser last displayed. If it has changed since you added the item, we
                    tell you and ask you to confirm rather than quietly charging the new price.
                </p>
                <p class="t-body-md tw-mb-3">
                    A basket containing items from more than one seller becomes several parts, one per
                    seller. Each part is accepted, prepared and fulfilled separately, and one can be
                    cancelled or refunded without affecting the others.
                </p>
                <p class="t-body-md tw-mb-0">
                    Placing an order reserves the stock for you. A seller may still decline their part
                    of an order, and must give a reason when they do. If that happens, the reserved
                    stock is released and that part is refunded.
                </p>
            </section>

            <section id="fulfilment">
                <h2 class="t-heading-xl tw-mb-4">3. Collection and delivery</h2>
                <p class="t-body-md tw-mb-3">
                    For collection, you choose a store that holds every item in that part of the
                    order. When the seller marks it ready you receive a collection code. That code is
                    how the store confirms the order was handed to you; keep it to yourself.
                </p>
                <p class="t-body-md tw-mb-3">
                    Orders not collected within the stated window are flagged as overdue. If they stay
                    uncollected, the seller may return the items to stock and the order is refunded.
                </p>
                <p class="t-body-md tw-mb-0">
                    For delivery, the charge is calculated from the zone your address falls into and
                    is shown before you pay. A delivery agent confirms handover using a code you
                    provide. If delivery fails, the agent records why, and further attempts or a
                    return to the seller follow.
                </p>
            </section>

            <section id="payment">
                <h2 class="t-heading-xl tw-mb-4">4. Payment and refunds</h2>
                <p class="t-body-md tw-mb-3">
                    An order is only treated as paid when our server has a verified record of the
                    payment. A confirmation shown in your browser is never treated as proof on its
                    own.
                </p>
                <p class="t-body-md tw-mb-3">
                    We do not store card numbers, PINs or mobile-money credentials at any point.
                </p>
                <p class="t-body-md tw-mb-0">
                    Where a part of an order is cancelled, declined or returned, a refund is recorded
                    against that part. Refunds are issued to the original payment method.
                </p>
            </section>

            <section id="sellers">
                <h2 class="t-heading-xl tw-mb-4">5. Selling on the platform</h2>
                <p class="t-body-md tw-mb-3">
                    Sellers are responsible for the accuracy of their listings, the quality and safety
                    of what they sell, keeping stock figures current, and preparing orders in the time
                    they state.
                </p>
                <p class="t-body-md tw-mb-0">
                    Sellers can see only the part of an order they are fulfilling. They do not receive
                    a customer's email address, their other orders, or their payment details.
                </p>
            </section>

            <section id="conduct">
                <h2 class="t-heading-xl tw-mb-4">6. Acceptable use</h2>
                <p class="t-body-md tw-mb-0">
                    Do not attempt to access orders, accounts or data that are not yours, interfere
                    with the platform, submit reviews for products you did not buy, or use the
                    platform to sell anything unlawful. Accounts may be suspended for any of these,
                    with a reason given.
                </p>
            </section>

            <section id="liability">
                <h2 class="t-heading-xl tw-mb-4">7. Our role and limits</h2>
                <p class="t-body-md tw-mb-0">
                    We operate the marketplace that connects you with sellers and arranges delivery.
                    The contract for the goods themselves is between you and the seller. This section
                    in particular needs proper legal drafting before launch - see the notice at the
                    top of this page.
                </p>
            </section>
        </article>

        <hr class="sl-divider tw-my-10">

        <p class="t-caption t-muted tw-mb-0">
            Questions about these terms? <a href="<?= e(route('page.contact')) ?>">Contact support</a>.
            See also our <a href="<?= e(route('page.privacy')) ?>">privacy notice</a>.
        </p>
    </div>
</section>
