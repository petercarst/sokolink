<?php
declare(strict_types=1);
/**
 * Homepage - cinematic track.
 *
 * One action per band, monumental thin display type, photography doing the
 * visual work. No aloe or pistachio anywhere on this page: the greens belong
 * to the light track (the brand mark in the nav is the single identity
 * exception, documented on /styleguide).
 *
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $featured
 * @var list<array<string,mixed>> $stores
 */
?>

<!-- ============================ HERO ============================ -->
<section class="sl-section-air">
    <div class="sl-container-wide sl-container">
        <p class="t-eyebrow t-muted-dark tw-mb-6">Marketplace &middot; Tanzania</p>

        <h1 class="t-display-xxl tw-mb-8" style="max-width:16ch">
            Buy it online. Collect it, or have it delivered.
        </h1>

        <p class="t-body-lg t-muted-dark tw-mb-10" style="max-width:52ch">
            Order from sellers near you before you leave the house. Pick it up ready-packed when you
            are passing, or have a delivery agent bring it to your door.
        </p>

        <div class="tw-flex tw-flex-wrap tw-gap-4">
            <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('catalog.index')) ?>">
                Start shopping
            </a>
        </div>
    </div>

    <!-- Full-bleed photography: it escapes the container, as the reference requires -->
    <div class="tw-mt-16" style="height:clamp(280px, 42vw, 560px)">
        <div class="sl-photo-frame tw-h-full" style="border-radius:0">
            <?= component('product-image', [
                'tone'  => 'forest',
                'label' => 'Merchant storefront',
                'mark'  => true,
            ]) ?>
        </div>
    </div>
</section>

<!-- ======================== CATEGORY STRIP ======================== -->
<section class="sl-section">
    <div class="sl-container">
        <div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-4 tw-mb-8">
            <div>
                <p class="t-eyebrow t-muted-dark tw-mb-3">Browse</p>
                <h2 class="t-display-md">Shop by category</h2>
            </div>
            <a class="sl-btn sl-btn-ghost-dark" href="<?= e(route('catalog.index')) ?>">
                See everything <?= component('icon', ['name' => 'arrow-right', 'size' => 18]) ?>
            </a>
        </div>

        <div class="sl-grid sl-grid-3">
            <?php foreach ($categories as $category) : ?>
                <a class="sl-card-cinematic tw-no-underline tw-flex tw-items-start tw-gap-4"
                   href="<?= e(route('catalog.category', ['slug' => $category['slug']])) ?>">
                    <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-40)">
                        <?= component('icon', ['name' => $category['icon'], 'size' => 24]) ?>
                    </span>
                    <span>
                        <span class="t-heading-md tw-block tw-mb-1"><?= e($category['name']) ?></span>
                        <span class="t-caption t-muted-dark"><?= e((string) $category['product_count']) ?> products</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========================= HOW IT WORKS ========================= -->
<section class="sl-section-air">
    <div class="sl-container">
        <p class="t-eyebrow t-muted-dark tw-mb-3">Two ways to receive it</p>
        <h2 class="t-display-lg tw-mb-12" style="max-width:18ch">You choose how it reaches you.</h2>

        <div class="sl-grid sl-grid-2">
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'package', 'size' => 28]) ?></span>
                <h3 class="t-heading-xl tw-mt-5 tw-mb-4">Click and collect</h3>
                <ol class="tw-list-none tw-p-0 tw-m-0 tw-flex tw-flex-col tw-gap-4">
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">1.</strong> Order online and pick the store that suits you.</li>
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">2.</strong> The seller packs it and marks it ready.</li>
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">3.</strong> You get a collection code and pick it up.</li>
                </ol>
                <p class="t-caption t-muted-dark tw-mt-6 tw-mb-0">
                    Your code is checked at the counter, so nobody else can collect your order.
                </p>
            </div>

            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'truck', 'size' => 28]) ?></span>
                <h3 class="t-heading-xl tw-mt-5 tw-mb-4">Home delivery</h3>
                <ol class="tw-list-none tw-p-0 tw-m-0 tw-flex tw-flex-col tw-gap-4">
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">1.</strong> Order online and give your delivery address.</li>
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">2.</strong> The seller packs it and hands it to an agent.</li>
                    <li class="t-body-md t-muted-dark"><strong class="t-on-dark">3.</strong> The agent brings it and confirms with your code.</li>
                </ol>
                <p class="t-caption t-muted-dark tw-mt-6 tw-mb-0">
                    Delivery charges are calculated from your zone before you pay, never after.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ========================== FEATURED =========================== -->
<section class="sl-section">
    <div class="sl-container">
        <div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-4 tw-mb-8">
            <div>
                <p class="t-eyebrow t-muted-dark tw-mb-3">Popular right now</p>
                <h2 class="t-display-md">What people are buying</h2>
            </div>
            <a class="sl-btn sl-btn-ghost-dark" href="<?= e(route('catalog.index')) ?>">
                All products <?= component('icon', ['name' => 'arrow-right', 'size' => 18]) ?>
            </a>
        </div>

        <div class="sl-grid sl-grid-4">
            <?php foreach ($featured as $product) : ?>
                <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ======================= REORDER / RETENTION ==================== -->
<section class="sl-section-air">
    <div class="sl-container-read sl-container tw-text-center">
        <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'repeat', 'size' => 32]) ?></span>
        <h2 class="t-display-lg tw-mt-6 tw-mb-6">The things you buy every month, without the thinking.</h2>
        <p class="t-body-lg t-muted-dark tw-mb-8">
            When you buy something regularly, we work out roughly when you will run low from how much
            you bought and how often you have reordered before. If you have asked us to, we send one
            reminder around then. One tap puts it back in your basket.
        </p>
        <p class="t-caption t-muted-dark tw-mb-8">
            Reminders are off unless you turn them on, capped so they never become nagging, and every
            message has a one-click unsubscribe that works without logging in.
        </p>
        <a class="sl-btn sl-btn-outline-dark" href="<?= e(route('auth.register')) ?>">Create an account</a>
    </div>
</section>

<!-- =========================== STORES ============================ -->
<section class="sl-section">
    <div class="sl-container">
        <p class="t-eyebrow t-muted-dark tw-mb-3">Collection points</p>
        <h2 class="t-display-md tw-mb-8">Stores you can collect from</h2>

        <div class="sl-grid sl-grid-4">
            <?php foreach ($stores as $store) : ?>
                <?= component('store-card', ['store' => $store, 'onDark' => true]) ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ========================= SELLER BAND ========================= -->
<section class="sl-section-air">
    <div class="sl-container">
        <div class="sl-card-cinematic sl-card-cinematic-el tw-text-center" style="padding:clamp(32px,6vw,80px)">
            <p class="t-eyebrow t-muted-dark tw-mb-4">For sellers</p>
            <h2 class="t-display-md tw-mb-6" style="max-width:20ch;margin-inline:auto">
                Sell online without building a shop.
            </h2>
            <p class="t-body-lg t-muted-dark tw-mb-8" style="max-width:52ch;margin-inline:auto">
                List your products, set your stock per store, and take orders for collection or
                delivery. Applications are reviewed before a store goes live.
            </p>
            <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('page.sell')) ?>">
                Find out about selling
            </a>
        </div>
    </div>
</section>
