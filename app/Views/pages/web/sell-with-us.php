<?php
declare(strict_types=1);
/**
 * Seller landing page - cinematic track.
 *
 * Sets expectations honestly: approval is required, and what a seller can and
 * cannot see about customers is stated up front rather than discovered later.
 */
?>

<section class="sl-section-air">
    <div class="sl-container">
        <p class="t-eyebrow t-muted-dark tw-mb-6">For sellers</p>
        <h1 class="t-display-xl tw-mb-8" style="max-width:17ch">
            Take online orders without building a shop.
        </h1>
        <p class="t-body-lg t-muted-dark tw-mb-10" style="max-width:54ch">
            List what you already stock, set quantities per store, and take orders for collection or
            delivery. You keep control of your prices, your stock and which orders you accept.
        </p>
        <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('auth.register.seller')) ?>">
            Apply to sell
        </a>
    </div>
</section>

<section class="sl-section">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">What you get</h2>
        <div class="sl-grid sl-grid-3">
            <?php
            $features = [
                ['store',   'Multiple stores',       'Hold different stock at each branch. Customers pick the collection point that suits them, and only stores that hold every item can be chosen.'],
                ['package', 'Order preparation',     'A clear queue of what to pick and pack, with a pick list per order and a single button to mark it ready.'],
                ['qr',      'Collection codes',      'Each ready order gets a code. Scan or type it at the counter to confirm the handover. Nobody can collect without it.'],
                ['truck',   'Delivery without a van','If you offer delivery, platform agents collect from your store and deliver. You do not have to run a fleet.'],
                ['grain',   'Stock that cannot oversell', 'Quantities are reserved the moment an order is placed, inside a database transaction, so two customers cannot be sold the same last unit.'],
                ['repeat',  'Repeat customers',      'Set how long a pack typically lasts and the platform times reorder reminders for customers who asked for them.'],
            ];
            foreach ($features as [$icon, $title, $text]) : ?>
                <div class="sl-card-cinematic">
                    <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => $icon, 'size' => 26]) ?></span>
                    <h3 class="t-heading-xl tw-mt-5 tw-mb-3"><?= e($title) ?></h3>
                    <p class="t-body-md t-muted-dark tw-mb-0"><?= e($text) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sl-section-air">
    <div class="sl-container-read sl-container">
        <h2 class="t-display-md tw-mb-8">How applying works</h2>

        <ol class="sl-timeline tw-mb-0" style="--c-shade-30: var(--c-hairline-dark)">
            <li class="sl-timeline-item">
                <p class="t-heading-md tw-mb-2">1. You apply</p>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Business details, a contact, and your first store. It takes a few minutes.
                </p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-heading-md tw-mb-2">2. You can log in straight away</p>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Your account works immediately, but in a restricted state: you can complete your
                    store profile and nothing else. You cannot list products or receive orders yet.
                </p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-heading-md tw-mb-2">3. An administrator reviews it</p>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    If we approve it, your store goes live and the full dashboard unlocks. If we turn
                    it down, you get a reason and can reapply.
                </p>
            </li>
            <li class="sl-timeline-item">
                <p class="t-heading-md tw-mb-2">4. You list and set stock</p>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Add products with photos, prices and pack sizes, then set the quantity held at
                    each store.
                </p>
            </li>
        </ol>
    </div>
</section>

<section class="sl-section">
    <div class="sl-container-read sl-container">
        <h2 class="t-display-md tw-mb-6">What you can and cannot see</h2>
        <p class="t-body-lg t-muted-dark tw-mb-8">
            Worth knowing before you apply, because it is not negotiable.
        </p>

        <div class="sl-grid sl-grid-2">
            <div class="sl-card-cinematic">
                <h3 class="t-heading-md tw-mb-4">You can see</h3>
                <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
                    <li class="t-body-md t-muted-dark">Your own stores, products and stock</li>
                    <li class="t-body-md t-muted-dark">The part of each order you are fulfilling</li>
                    <li class="t-body-md t-muted-dark">The customer's first name and a masked phone number</li>
                    <li class="t-body-md t-muted-dark">The delivery address for your own delivery orders</li>
                    <li class="t-body-md t-muted-dark">Your own sales figures and reviews</li>
                </ul>
            </div>

            <div class="sl-card-cinematic">
                <h3 class="t-heading-md tw-mb-4">You cannot see</h3>
                <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
                    <li class="t-body-md t-muted-dark">Another seller's orders, products or figures</li>
                    <li class="t-body-md t-muted-dark">A customer's email address</li>
                    <li class="t-body-md t-muted-dark">The rest of a customer's basket from other sellers</li>
                    <li class="t-body-md t-muted-dark">A customer's other orders, even their own with you historically beyond your own sales</li>
                    <li class="t-body-md t-muted-dark">Any payment credential, ever</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="sl-section-air">
    <div class="sl-container">
        <div class="sl-card-cinematic sl-card-cinematic-el tw-text-center" style="padding:clamp(32px,6vw,72px)">
            <h2 class="t-display-md tw-mb-6">Ready to apply?</h2>
            <p class="t-body-lg t-muted-dark tw-mb-8" style="max-width:48ch;margin-inline:auto">
                Applications are reviewed by an administrator. You will hear either way, with a
                reason if the answer is no.
            </p>
            <div class="tw-flex tw-flex-wrap tw-gap-4 tw-justify-center">
                <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('auth.register.seller')) ?>">
                    Apply to sell
                </a>
                <a class="sl-btn sl-btn-ghost-dark sl-btn-lg" href="<?= e(route('page.contact')) ?>">
                    Ask a question first
                </a>
            </div>
        </div>
    </div>
</section>
