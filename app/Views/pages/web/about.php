<?php
declare(strict_types=1);
/**
 * About - cinematic track.
 *
 * @var list<array<string,mixed>> $stores
 */
?>

<section class="sl-section-air">
    <div class="sl-container">
        <p class="t-eyebrow t-muted-dark tw-mb-6">About</p>
        <h1 class="t-display-xl tw-mb-8" style="max-width:18ch">
            A marketplace built around how people actually shop.
        </h1>
        <p class="t-body-lg t-muted-dark" style="max-width:58ch">
            Most people already know where they want to buy from. What they do not want is to arrive
            and find the thing gone, or to queue for twenty minutes for one item. SokoLink puts the
            ordering online and leaves the choosing of where and when to you.
        </p>
    </div>
</section>

<section class="sl-section">
    <div class="sl-container">
        <div class="sl-grid sl-grid-3">
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'store', 'size' => 26]) ?></span>
                <h2 class="t-heading-xl tw-mt-5 tw-mb-3">Many sellers, one basket</h2>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Order from several sellers at once. Each one gets their own part of the order to
                    prepare and track, so a delay at one does not hold up the other.
                </p>
            </div>
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'package', 'size' => 26]) ?></span>
                <h2 class="t-heading-xl tw-mt-5 tw-mb-3">Collect or receive</h2>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Pick a collection point that is on your way, or have a delivery agent bring it.
                    You choose per seller, not for the whole basket.
                </p>
            </div>
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'repeat', 'size' => 26]) ?></span>
                <h2 class="t-heading-xl tw-mt-5 tw-mb-3">Reordering that is not annoying</h2>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    We estimate when you will actually run low from what you bought and how often you
                    have reordered, rather than messaging you on a fixed schedule.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="sl-section-air">
    <div class="sl-container-read sl-container">
        <h2 class="t-display-md tw-mb-8">How we think about a few things</h2>

        <div class="tw-flex tw-flex-col tw-gap-8">
            <div>
                <h3 class="t-heading-xl tw-mb-3">Stock that is honest</h3>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Stock is held per store, not as one number for the whole platform. When you order,
                    the quantity is reserved for you inside a single database transaction, so two
                    people cannot be sold the last bottle of oil. If it does go while you are at the
                    checkout, we say so and name the item, instead of taking the order and apologising
                    afterwards.
                </p>
            </div>

            <div>
                <h3 class="t-heading-xl tw-mb-3">Collection that cannot be gamed</h3>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    When your order is ready you get a collection code. Only staff at the store you
                    chose can accept it, and it is checked against a stored hash rather than compared
                    on screen. You cannot mark your own order collected, and the shop cannot mark it
                    collected without the code.
                </p>
            </div>

            <div>
                <h3 class="t-heading-xl tw-mb-3">Messages you asked for</h3>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    Updates about an order you placed are part of the service, so they are always sent.
                    Everything else - reminders, offers - requires you to opt in, is capped so it never
                    becomes a stream, respects quiet hours, and carries a one-click unsubscribe that
                    works without logging in.
                </p>
            </div>

            <div>
                <h3 class="t-heading-xl tw-mb-3">Who can see what</h3>
                <p class="t-body-md t-muted-dark tw-mb-0">
                    A seller sees the part of your order they are fulfilling, not your email address
                    and not your other orders. A delivery agent sees the address for the delivery they
                    are assigned to, and nothing about anyone else's. Support staff can only open the
                    record of a customer who has a ticket with them, and every time they do it is
                    written to an audit log.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="sl-section">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">Where you can collect</h2>
        <div class="sl-grid sl-grid-4">
            <?php foreach ($stores as $store) : ?>
                <?= component('store-card', ['store' => $store, 'onDark' => true]) ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="sl-section-air">
    <div class="sl-container-read sl-container tw-text-center">
        <h2 class="t-display-md tw-mb-6">Questions?</h2>
        <p class="t-body-lg t-muted-dark tw-mb-8">
            Support can help with an order, a delivery, a refund or your account.
        </p>
        <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('page.contact')) ?>">
            Contact support
        </a>
    </div>
</section>
