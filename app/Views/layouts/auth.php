<?php
declare(strict_types=1);
/**
 * Authentication layout.
 *
 * A split canvas: the cinematic panel carries the brand, the light panel
 * carries the form. This is the one place the two tracks sit side by side, and
 * they do so as separate panels with a hard edge between them - not blended,
 * which is what the reference forbids.
 *
 * The cinematic panel is hidden below lg, so on a phone this is simply a clean
 * transactional form.
 *
 * @var string $content
 */
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

<div class="tw-min-h-screen lg:tw-grid" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);">

    <!-- Brand panel: cinematic track -->
    <aside class="track-cinematic tw-hidden lg:tw-flex tw-flex-col tw-justify-between tw-p-12" aria-hidden="true">
        <a class="sl-brand" href="<?= e(route('home')) ?>">
            <span class="sl-brand-mark"><span aria-hidden="true">S</span></span>
            <span>SokoLink</span>
        </a>

        <div>
            <p class="t-eyebrow t-muted-dark tw-mb-4">Order online</p>
            <p class="t-display-lg tw-mb-6" style="max-width:14ch">Collect it, or have it brought to you.</p>
            <p class="t-body-lg t-muted-dark" style="max-width:38ch">
                Buy from sellers near you, then choose whether to pick it up when you are passing or
                have an agent deliver it.
            </p>
        </div>

        <ul class="tw-list-none tw-p-0 tw-m-0 tw-flex tw-flex-col tw-gap-3">
            <li class="t-caption t-muted-dark tw-flex tw-items-center tw-gap-2">
                <?= component('icon', ['name' => 'package', 'size' => 16]) ?> Click and collect from a store that suits you
            </li>
            <li class="t-caption t-muted-dark tw-flex tw-items-center tw-gap-2">
                <?= component('icon', ['name' => 'truck', 'size' => 16]) ?> Home delivery across served zones
            </li>
            <li class="t-caption t-muted-dark tw-flex tw-items-center tw-gap-2">
                <?= component('icon', ['name' => 'repeat', 'size' => 16]) ?> Reorder what you buy regularly, in one tap
            </li>
        </ul>
    </aside>

    <!-- Form panel: transactional track -->
    <div class="tw-flex tw-flex-col tw-min-h-screen">
        <header class="tw-p-6 lg:tw-px-12">
            <a class="sl-brand lg:tw-hidden" href="<?= e(route('home')) ?>">
                <span class="sl-brand-mark" aria-hidden="true">S</span>
                <span>SokoLink</span>
            </a>
            <a class="sl-navlink tw-hidden lg:tw-inline-flex" href="<?= e(route('home')) ?>">
                <?= component('icon', ['name' => 'arrow-left', 'size' => 16]) ?> Back to the marketplace
            </a>
        </header>

        <?= partial('flash', ['flashes' => $flashes ?? []]) ?>

        <main id="main" tabindex="-1" class="tw-flex-1 tw-flex tw-items-center tw-justify-center tw-px-6 tw-py-8 lg:tw-px-12">
            <div class="tw-w-full" style="max-width:26rem">
                <?= $content ?>
            </div>
        </main>

        <footer class="tw-px-6 tw-py-6 lg:tw-px-12">
            <p class="t-micro t-muted tw-mb-3">
                <a class="sl-link-quiet" href="<?= e(route('page.terms')) ?>">Terms</a>
                &middot;
                <a class="sl-link-quiet" href="<?= e(route('page.privacy')) ?>">Privacy</a>
                &middot;
                <a class="sl-link-quiet" href="<?= e(route('page.contact')) ?>">Help</a>
            </p>
            <?= component('devnote', [
                'text' => 'Phase 1 build - these forms are not connected to authentication yet. '
                        . 'Submitting one tells you which phase will handle it. No account is created or checked.',
            ]) ?>
        </footer>
    </div>
</div>

<?= partial('scripts') ?>
</body>
</html>
