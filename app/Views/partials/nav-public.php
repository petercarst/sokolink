<?php
declare(strict_types=1);
/**
 * Public site navigation.
 *
 * Inherits canvas polarity from the track, as the reference requires: the
 * mobile menu opens in the same polarity as the page it came from.
 *
 * Bootstrap owns the offcanvas behaviour; the styling is ours.
 *
 * @var string $track
 */
$track = $track ?? 'cinematic';
$dark  = $track === 'cinematic';

$navCategories = \App\Support\View\Chrome::categories(5);
$cartCount     = \App\Support\View\Chrome::cartCount();
$signedIn      = \App\Core\Auth::check();
?>
<header class="sl-nav <?= $dark ? 'sl-nav-dark' : 'sl-nav-light' ?> sl-nav-sticky">
    <div class="sl-container">
        <div class="tw-flex tw-items-center tw-gap-4">

            <a class="sl-brand" href="<?= e(route('home')) ?>">
                <span class="sl-brand-mark" aria-hidden="true">S</span>
                <span>SokoLink</span>
            </a>

            <!-- Desktop navigation -->
            <nav class="tw-hidden lg:tw-flex tw-items-center tw-gap-1 tw-ml-4" aria-label="Main">
                <a class="sl-navlink <?= nav_active(['catalog.index']) ?>" href="<?= e(route('catalog.index')) ?>">All products</a>

                <div class="dropdown">
                    <button class="sl-navlink dropdown-toggle <?= nav_active(['catalog.category']) ?>"
                            type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Categories
                    </button>
                    <ul class="dropdown-menu tw-mt-2" style="border-radius:var(--r-lg)">
                        <?php foreach ($navCategories as $category) : ?>
                            <li>
                                <a class="dropdown-item" href="<?= e(route('catalog.category', ['slug' => $category['slug']])) ?>">
                                    <?= e($category['name']) ?>
                                    <span class="t-micro t-muted tw-ml-2"><?= e((string) $category['product_count']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(route('catalog.index')) ?>">Browse everything</a></li>
                    </ul>
                </div>

                <a class="sl-navlink <?= nav_active(['page.sell']) ?>" href="<?= e(route('page.sell')) ?>">Sell with us</a>
                <a class="sl-navlink <?= nav_active(['page.about']) ?>" href="<?= e(route('page.about')) ?>">About</a>
            </nav>

            <!-- Search: a real GET form to a real results page -->
            <form class="tw-hidden md:tw-flex tw-flex-1 tw-max-w-md tw-ml-auto" method="get"
                  action="<?= e(route('search')) ?>" role="search">
                <label class="visually-hidden" for="nav-search">Search products</label>
                <div class="tw-relative tw-w-full">
                    <span class="tw-absolute tw-left-3 tw-top-1/2 tw--translate-y-1/2 tw-pointer-events-none"
                          style="color:var(--c-shade-50)">
                        <?= component('icon', ['name' => 'search', 'size' => 18]) ?>
                    </span>
                    <input class="sl-input <?= $dark ? 'sl-input-dark' : '' ?> tw-pl-10"
                           style="border-radius:var(--r-pill)"
                           type="search" id="nav-search" name="q" placeholder="Search cooking oil, rice, soap..."
                           value="<?= e((string) \App\Core\Request::current()->query('q', '')) ?>">
                </div>
            </form>

            <div class="tw-flex tw-items-center tw-gap-2 tw-ml-auto md:tw-ml-0">
                <a class="sl-navlink tw-relative" href="<?= e(route('cart')) ?>" aria-label="Basket, <?= e((string) $cartCount) ?> items">
                    <?= component('icon', ['name' => 'cart', 'size' => 20]) ?>
                    <span class="tw-hidden xl:tw-inline">Basket</span>
                    <?php if ($cartCount > 0) : ?>
                        <span class="sl-cart-count"><?= e((string) $cartCount) ?></span>
                    <?php endif; ?>
                </a>

                <?php if ($signedIn) : ?>
                    <div class="dropdown tw-hidden sm:tw-block">
                        <button class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-sm dropdown-toggle"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?= component('icon', ['name' => 'user', 'size' => 18]) ?>
                            <span class="tw-hidden xl:tw-inline"><?= e(\App\Core\Auth::name()) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end tw-mt-2" style="border-radius:var(--r-lg)">
                            <li><a class="dropdown-item" href="<?= e(route('customer.dashboard')) ?>">My dashboard</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('customer.orders')) ?>">My orders</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(route('auth.logout')) ?>" class="tw-m-0">
                                    <?= csrf_field() ?>
                                    <button class="dropdown-item" type="submit">Log out</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else : ?>
                    <a class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-sm tw-hidden sm:tw-inline-flex"
                       href="<?= e(route('auth.login')) ?>">Log in</a>

                    <a class="sl-btn <?= $dark ? 'sl-btn-outline-dark' : 'sl-btn-primary' ?> sl-btn-sm tw-hidden sm:tw-inline-flex"
                       href="<?= e(route('auth.register')) ?>">Create account</a>
                <?php endif; ?>

                <button class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-icon lg:tw-hidden"
                        type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav"
                        aria-controls="mobileNav" aria-label="Open menu">
                    <?= component('icon', ['name' => 'menu', 'size' => 22]) ?>
                </button>
            </div>
        </div>

        <!-- Mobile search, below the bar so the tap targets stay 44px -->
        <form class="md:tw-hidden tw-mt-3" method="get" action="<?= e(route('search')) ?>" role="search">
            <label class="visually-hidden" for="nav-search-m">Search products</label>
            <input class="sl-input <?= $dark ? 'sl-input-dark' : '' ?>" style="border-radius:var(--r-pill)"
                   type="search" id="nav-search-m" name="q" placeholder="Search products..."
                   value="<?= e((string) \App\Core\Request::current()->query('q', '')) ?>">
        </form>
    </div>
</header>

<!-- Mobile menu: inherits the canvas polarity of the page it opened from -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel"
     style="background-color: <?= $dark ? 'var(--c-canvas-night)' : 'var(--c-canvas-light)' ?>;
            color: <?= $dark ? 'var(--c-on-dark)' : 'var(--c-ink)' ?>;">
    <div class="offcanvas-header">
        <h2 class="t-heading-md" id="mobileNavLabel">Menu</h2>
        <button type="button" class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-ghost' ?> sl-btn-icon"
                data-bs-dismiss="offcanvas" aria-label="Close menu">
            <?= component('icon', ['name' => 'close', 'size' => 20]) ?>
        </button>
    </div>
    <div class="offcanvas-body">
        <nav class="tw-flex tw-flex-col tw-gap-1" aria-label="Mobile">
            <a class="sl-navlink" href="<?= e(route('catalog.index')) ?>">All products</a>
            <?php foreach ($navCategories as $category) : ?>
                <a class="sl-navlink" href="<?= e(route('catalog.category', ['slug' => $category['slug']])) ?>">
                    <?= e($category['name']) ?>
                </a>
            <?php endforeach; ?>
            <hr class="sl-divider">
            <a class="sl-navlink" href="<?= e(route('page.sell')) ?>">Sell with us</a>
            <a class="sl-navlink" href="<?= e(route('page.about')) ?>">About</a>
            <a class="sl-navlink" href="<?= e(route('page.contact')) ?>">Contact and support</a>
            <a class="sl-navlink" href="<?= e(route('styleguide')) ?>">Design system</a>
            <hr class="sl-divider">
            <?php if ($signedIn) : ?>
                <a class="sl-btn <?= $dark ? 'sl-btn-outline-dark' : 'sl-btn-primary' ?> tw-mb-2"
                   href="<?= e(route('customer.dashboard')) ?>">My dashboard</a>
                <form method="post" action="<?= e(route('auth.logout')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <button class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-outline-light' ?> sl-btn-block"
                            type="submit">Log out</button>
                </form>
            <?php else : ?>
                <a class="sl-btn <?= $dark ? 'sl-btn-outline-dark' : 'sl-btn-primary' ?> tw-mb-2"
                   href="<?= e(route('auth.register')) ?>">Create account</a>
                <a class="sl-btn <?= $dark ? 'sl-btn-ghost-dark' : 'sl-btn-outline-light' ?>"
                   href="<?= e(route('auth.login')) ?>">Log in</a>
            <?php endif; ?>
        </nav>
    </div>
</div>
