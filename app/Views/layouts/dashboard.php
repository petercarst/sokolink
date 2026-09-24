<?php
declare(strict_types=1);
/**
 * Dashboard layout - TRANSACTIONAL track, all five roles.
 *
 * Sidebar pinned from 1024px, Bootstrap offcanvas below that. The sidebar is
 * built from DashboardNav so five dashboards cannot drift apart.
 *
 * PHASE 1 NOTE: these routes are NOT protected. There is no authentication yet,
 * so anyone can open /admin. The banner below says so on every screen, and it
 * stays until Phase 3.2 puts RequireAuth + RequireRole in front of these routes
 * and the ownership checks in the services behind them.
 *
 * @var string $content
 * @var string $role      customer|seller|delivery|support|admin
 * @var string $title
 */
$role = $role ?? 'customer';
$meta = \App\Support\DashboardNav::roleMeta($role);
$nav  = \App\Support\DashboardNav::for($role);
?>
<!doctype html>
<html lang="<?= e((string) config('app.locale', 'en')) ?>"
      data-currency="<?= e((string) config('app.currency')) ?>"
      data-currency-symbol="<?= e((string) config('app.currency_symbol')) ?>">
<head>
    <?= partial('head', ['title' => $title ?? '', 'metaDesc' => $metaDesc ?? '', 'track' => 'transactional']) ?>
</head>
<body class="track-transactional">

<a class="skip-link visually-hidden-focusable" href="#main">Skip to main content</a>

<?php
/**
 * The preview bar, shown only on the areas Phase 4 has not reached yet.
 *
 * The customer, seller and delivery areas are signed in, role-gated and
 * reading real rows, so they must NOT carry a banner saying none of that is
 * true. Individual
 * screens within them that are still on sample data say so for themselves,
 * through `sampleData` below.
 *
 * Admin still renders sample data behind an open route, and must keep saying so
 * until it is wired - including the "view as" switcher, which only works because
 * that route is still open. Support left this list in stage 4d: it is signed in,
 * behind `role:support`, and running on the database.
 */
$previewRoles = ['admin'];
?>
<?php if (in_array($role, $previewRoles, true)) : ?>
    <div class="sl-preview-bar">
        <div class="sl-container-wide sl-container tw-flex tw-flex-wrap tw-items-center tw-gap-3">
            <span class="tw-shrink-0"><?= component('icon', ['name' => 'alert', 'size' => 16]) ?></span>
            <span class="t-micro tw-flex-1" style="min-width:14rem">
                <strong>Not wired up yet &mdash; and not protected.</strong>
                This area still shows sample data and its routes are open to anyone. The customer,
                seller, delivery and support areas are signed in and running on the database; this
                one is connected in stage 4e.
            </span>
            <?php if (count($previewRoles) > 1) : ?>
                <span class="tw-flex tw-items-center tw-gap-1 tw-flex-wrap">
                    <span class="t-micro tw-mr-1">View as:</span>
                    <?php foreach ($previewRoles as $r) : ?>
                        <a class="sl-chip <?= $r === $role ? 'is-active' : '' ?>"
                           href="<?= e(route($r . '.dashboard')) ?>"><?= e(ucfirst($r)) ?></a>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!empty($sampleData)) : ?>
    <div class="sl-preview-bar">
        <div class="sl-container-wide sl-container tw-flex tw-flex-wrap tw-items-center tw-gap-3">
            <span class="tw-shrink-0"><?= component('icon', ['name' => 'alert', 'size' => 16]) ?></span>
            <span class="t-micro tw-flex-1" style="min-width:14rem">
                <strong>This screen still shows sample data.</strong>
                You are signed in and the rest of this area is real, but this particular page has
                not been connected to the database yet - nothing you see or submit here is yours.
            </span>
        </div>
    </div>
<?php endif; ?>

<div class="sl-dash">
    <!-- Sidebar: pinned on desktop -->
    <aside class="sl-dash-side tw-hidden lg:tw-block" aria-label="<?= e($meta['label']) ?> navigation">
        <?= partial('dash-sidebar', ['role' => $role, 'nav' => $nav, 'meta' => $meta, 'idPrefix' => 'd']) ?>
    </aside>

    <!-- Sidebar: offcanvas on mobile -->
    <div class="offcanvas offcanvas-start lg:tw-hidden" tabindex="-1" id="dashNav"
         aria-labelledby="dashNavLabel" style="background:var(--c-canvas-light);width:280px">
        <div class="offcanvas-header">
            <h2 class="t-heading-md" id="dashNavLabel"><?= e($meta['label']) ?></h2>
            <button type="button" class="sl-btn sl-btn-ghost sl-btn-icon" data-bs-dismiss="offcanvas"
                    aria-label="Close navigation">
                <?= component('icon', ['name' => 'close', 'size' => 20]) ?>
            </button>
        </div>
        <div class="offcanvas-body tw-pt-0">
            <?= partial('dash-sidebar', ['role' => $role, 'nav' => $nav, 'meta' => $meta, 'idPrefix' => 'm', 'compact' => true]) ?>
        </div>
    </div>

    <div class="sl-dash-main">
        <?= partial('dash-topbar', ['role' => $role, 'meta' => $meta]) ?>

        <?= partial('flash', ['flashes' => $flashes ?? []]) ?>

        <main id="main" tabindex="-1" class="sl-dash-body">
            <?= $content ?>
        </main>

        <footer class="sl-container-wide sl-container tw-py-8">
            <hr class="sl-divider">
            <p class="t-micro t-muted tw-mb-0">
                SokoLink &middot; <?= e($meta['badge']) ?> view &middot;
                times in <?= e((string) config('app.display_timezone')) ?> &middot;
                <a class="sl-link-quiet" href="<?= e(route('home')) ?>">Back to the marketplace</a>
            </p>
        </footer>
    </div>
</div>

<?= partial('scripts') ?>
</body>
</html>
