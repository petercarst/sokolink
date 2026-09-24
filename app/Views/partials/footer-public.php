<?php
declare(strict_types=1);
/**
 * Site footer.
 *
 * @var string $track
 * @var int    $currentYear
 */
$track = $track ?? 'cinematic';
$dark  = $track === 'cinematic';
?>
<footer class="<?= $dark ? 'sl-footer-dark' : 'sl-footer-light' ?>">
    <div class="sl-container">

        <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 lg:tw-grid-cols-5 tw-gap-8 tw-mb-10">

            <div class="tw-col-span-2 lg:tw-col-span-1">
                <a class="sl-brand tw-mb-4" href="<?= e(route('home')) ?>">
                    <span class="sl-brand-mark" aria-hidden="true">S</span>
                    <span>SokoLink</span>
                </a>
                <p class="t-caption <?= $dark ? 't-muted-dark' : 't-muted' ?>" style="max-width:32ch">
                    Order from local sellers online. Collect in store when it suits you, or have it
                    delivered to your door.
                </p>
            </div>

            <div>
                <h2 class="sl-footer-heading">Shop</h2>
                <a class="sl-footer-link" href="<?= e(route('catalog.index')) ?>">All products</a>
                <a class="sl-footer-link" href="<?= e(route('catalog.category', ['slug' => 'food-cupboard'])) ?>">Food cupboard</a>
                <a class="sl-footer-link" href="<?= e(route('catalog.category', ['slug' => 'household'])) ?>">Household</a>
                <a class="sl-footer-link" href="<?= e(route('catalog.category', ['slug' => 'personal-care'])) ?>">Personal care</a>
                <a class="sl-footer-link" href="<?= e(route('catalog.category', ['slug' => 'fresh'])) ?>">Fresh produce</a>
            </div>

            <div>
                <h2 class="sl-footer-heading">Selling</h2>
                <a class="sl-footer-link" href="<?= e(route('page.sell')) ?>">Sell on SokoLink</a>
                <a class="sl-footer-link" href="<?= e(route('auth.register.seller')) ?>">Apply to sell</a>
                <a class="sl-footer-link" href="<?= e(route('auth.login')) ?>">Seller log in</a>
            </div>

            <div>
                <h2 class="sl-footer-heading">Help</h2>
                <a class="sl-footer-link" href="<?= e(route('page.contact')) ?>">Contact and support</a>
                <a class="sl-footer-link" href="<?= e(route('page.about')) ?>">About us</a>
                <a class="sl-footer-link" href="<?= e(route('page.unsubscribe')) ?>">Manage email preferences</a>
            </div>

            <div>
                <h2 class="sl-footer-heading">Legal</h2>
                <a class="sl-footer-link" href="<?= e(route('page.terms')) ?>">Terms of service</a>
                <a class="sl-footer-link" href="<?= e(route('page.privacy')) ?>">Privacy notice</a>
                <a class="sl-footer-link" href="<?= e(route('styleguide')) ?>">Design system</a>
            </div>
        </div>

        <hr class="sl-divider">

        <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-4">
            <p class="t-micro <?= $dark ? 't-muted-dark' : 't-muted' ?> tw-mb-0">
                &copy; <?= e((string) ($currentYear ?? gmdate('Y'))) ?> SokoLink. Prices shown in Tanzanian Shillings.
            </p>

            <p class="t-micro <?= $dark ? 't-muted-dark' : 't-muted' ?> tw-mb-0 tw-flex tw-items-center tw-gap-2">
                <?= component('icon', ['name' => 'clock', 'size' => 14]) ?>
                Times shown in <?= e((string) config('app.display_timezone')) ?>
            </p>
        </div>

        <?php /* The build note that used to sit here has been removed: it repeated
                 the same paragraph on every page of the site, which is where a
                 standing notice stops being read.

                 It is NOT gone from the places it matters. "No payment provider
                 is connected and nothing is charged" is stated on the payment
                 step and again on the order confirmation, at the moment somebody
                 is actually deciding to pay; and every screen still on sample
                 data carries its own banner saying so. Those are the disclosures
                 that have to stay, and they are nearer the decision than a
                 footer ever was. */ ?>
    </div>
</footer>
