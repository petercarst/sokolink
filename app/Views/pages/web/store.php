<?php
declare(strict_types=1);
/**
 * Store / seller profile - cinematic track.
 *
 * Shows only what a public visitor is entitled to see: store location, hours,
 * pickup instructions, rating and catalogue. No seller financials, no customer
 * data (USER_ROLES_AND_PERMISSIONS.md section 4).
 *
 * @var array<string,mixed>       $store
 * @var list<array<string,mixed>> $sellerStores
 * @var list<array<string,mixed>> $products
 */
$dayNames = [
    'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
    'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
];
$todayKey = strtolower(\App\Core\Clock::local(gmdate('Y-m-d H:i:s'))->format('D'));
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Stores', 'url' => route('catalog.index')],
                ['label' => $store['name'], 'url' => null],
            ],
            'onDark' => true,
        ]) ?>

        <div class="tw-flex tw-flex-wrap tw-items-start tw-gap-6 tw-mb-8">
            <span class="sl-avatar" style="width:72px;height:72px;font-size:24px">
                <?= e(initials($store['name'])) ?>
            </span>

            <div class="tw-flex-1" style="min-width:16rem">
                <p class="t-eyebrow t-muted-dark tw-mb-2"><?= e($store['seller_name']) ?></p>
                <h1 class="t-display-md tw-mb-4"><?= e($store['name']) ?></h1>

                <div class="tw-flex tw-items-center tw-gap-4 tw-flex-wrap">
                    <?= component('rating', ['rating' => $store['rating'], 'count' => $store['review_count']]) ?>
                    <?= component('badge', [
                        'status' => $store['is_open_now'] ? 'active' : 'closed',
                        'label'  => $store['is_open_now'] ? 'Open now' : 'Closed now',
                        'onDark' => true,
                    ]) ?>
                    <span class="t-caption t-muted-dark"><?= e((string) $store['product_count']) ?> products</span>
                </div>
            </div>

            <a class="sl-btn sl-btn-outline-dark" href="#products">Browse the catalogue</a>
        </div>
    </div>
</section>

<section class="sl-section-tight">
    <div class="sl-container">
        <div class="sl-grid sl-grid-3">

            <!-- Location -->
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'map-pin', 'size' => 22]) ?></span>
                <h2 class="t-heading-md tw-mt-4 tw-mb-3">Where to find it</h2>
                <address class="t-body-md t-muted-dark tw-not-italic tw-mb-4">
                    <?= e($store['street']) ?><br>
                    <?= e($store['district']) ?><br>
                    <?= e($store['region']) ?>
                </address>
                <?php if (!empty($store['landmark'])) : ?>
                    <p class="t-caption t-muted-dark tw-mb-3"><?= e($store['landmark']) ?></p>
                <?php endif; ?>
                <p class="t-caption t-muted-dark tw-mb-0 tw-flex tw-items-center tw-gap-2">
                    <?= component('icon', ['name' => 'phone', 'size' => 15]) ?>
                    <?= e($store['phone_masked']) ?>
                </p>
                <p class="t-micro t-muted-dark tw-mt-2 tw-mb-0">
                    The full number is shown to you only once you have an order with this store.
                </p>
            </div>

            <!-- Hours -->
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'clock', 'size' => 22]) ?></span>
                <h2 class="t-heading-md tw-mt-4 tw-mb-3">Opening hours</h2>
                <dl class="tw-m-0">
                    <?php foreach ($dayNames as $key => $label) : ?>
                        <div class="tw-flex tw-justify-between tw-gap-3 tw-py-1">
                            <dt class="t-caption tw-m-0 <?= $key === $todayKey ? 't-on-dark' : 't-muted-dark' ?>">
                                <?= e($label) ?><?= $key === $todayKey ? ' (today)' : '' ?>
                            </dt>
                            <dd class="t-caption tw-m-0 tabular <?= $key === $todayKey ? 't-on-dark' : 't-muted-dark' ?>">
                                <?= e($store['hours'][$key] === 'closed' ? 'Closed' : str_replace('-', ' - ', $store['hours'][$key])) ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="t-micro t-muted-dark tw-mt-3 tw-mb-0">
                    Times shown in <?= e((string) config('app.display_timezone')) ?>.
                </p>
            </div>

            <!-- Collection -->
            <div class="sl-card-cinematic">
                <span style="color:var(--c-shade-40)"><?= component('icon', ['name' => 'package', 'size' => 22]) ?></span>
                <h2 class="t-heading-md tw-mt-4 tw-mb-3">Collecting your order</h2>
                <p class="t-body-md t-muted-dark tw-mb-4"><?= e($store['pickup_instructions']) ?></p>
                <div class="tw-flex tw-gap-2 tw-flex-wrap">
                    <?php foreach ($store['accepts'] as $method) : ?>
                        <span class="sl-chip sl-chip-dark">
                            <?= component('icon', ['name' => $method === 'delivery' ? 'truck' : 'package', 'size' => 13]) ?>
                            <?= e($method === 'delivery' ? 'Home delivery' : 'Click and collect') ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (count($sellerStores) > 1) : ?>
    <section class="sl-section-tight">
        <div class="sl-container">
            <h2 class="t-heading-xl tw-mb-6">Other stores from <?= e($store['seller_name']) ?></h2>
            <div class="sl-grid sl-grid-4">
                <?php foreach ($sellerStores as $other) : ?>
                    <?php if ($other['id'] !== $store['id']) : ?>
                        <?= component('store-card', ['store' => $other, 'onDark' => true]) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="sl-section" id="products">
    <div class="sl-container">
        <h2 class="t-display-md tw-mb-8">Products from this seller</h2>

        <?php if ($products === []) : ?>
            <?= component('empty-state', [
                'icon'        => 'store',
                'title'       => 'No products listed yet',
                'text'        => 'This store has not published any products. Check back soon.',
                'actionUrl'   => route('catalog.index'),
                'actionLabel' => 'Browse other sellers',
            ]) ?>
        <?php else : ?>
            <div class="sl-grid sl-grid-4">
                <?php foreach ($products as $product) : ?>
                    <?= component('product-card', ['product' => $product, 'onDark' => true]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
