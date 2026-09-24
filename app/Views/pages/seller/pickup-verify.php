<?php
declare(strict_types=1);
/**
 * Collection verification - the counter screen.
 *
 * This is the one screen that turns a packed order into a collected one, so it
 * is the one that has to be hard to fool (FR-PICK-03 to FR-PICK-05):
 *
 *  - The code is compared against a stored HASH, server-side. It is never sent
 *    to this page, so it cannot be read off the screen or out of the HTML.
 *  - Only staff of the store that holds the order can verify it.
 *  - Attempts are rate-limited; repeated failures alert the seller and are
 *    audited.
 *  - The customer cannot mark their own order collected, and the shop cannot
 *    mark it collected without the code. Both halves are needed.
 *
 * @var list<array<string,mixed>> $orders
 * @var array<string,string>      $orderOptions  seller_order_id => label
 * @var string                    $selected      pre-picked from ?ref=
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Ready to collect', 'url' => route('seller.pickup')],
            ['label' => 'Verify', 'url' => null],
        ],
        'title'    => 'Verify a collection',
        'subtitle' => 'Enter or scan the code the customer shows you.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <section class="sl-card sl-card-raised">
            <?php if ($orders === []) : ?>
                <p class="t-body-md t-muted tw-mb-0">
                    Nothing is waiting to be collected, so there is no code to verify.
                    Orders appear here once you mark them ready.
                </p>
            <?php else : ?>
            <form method="post" action="<?= e(route('seller.pickup.confirm')) ?>" data-validate>
                <?= csrf_field() ?>

                <!-- Which order, then the code. A code on its own identifies
                     nothing: it is checked against the hash stored on ONE
                     sub-order, and the attempt counter belongs to that order too. -->
                <?= component('field', [
                    'name' => 'seller_order_id', 'label' => 'Which order', 'type' => 'select', 'required' => true,
                    'value' => $selected,
                    'options' => $orderOptions,
                    'help' => 'The customer can read their order reference from the email or their order page.',
                ]) ?>

                <div class="sl-field">
                    <label class="sl-label" for="collection-code">
                        Collection code<span class="sl-required" aria-hidden="true">*</span>
                        <span class="visually-hidden"> (required)</span>
                    </label>
                    <input class="sl-input t-code" id="collection-code" name="code" required
                           autocomplete="off" autocapitalize="characters" spellcheck="false"
                           inputmode="text" maxlength="8" placeholder="XXXXXX"
                           aria-describedby="code-help"
                           style="font-size:28px;letter-spacing:6px;text-align:center;height:64px">
                    <span class="sl-help" id="code-help">
                        Six characters, from the customer's order page or their ready-to-collect email.
                    </span>
                </div>

                <button class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block tw-mb-4" type="submit">
                    <?= component('icon', ['name' => 'check', 'size' => 20]) ?>
                    Verify and mark collected
                </button>

                <p class="t-micro t-muted tw-mb-0">
                    Five wrong codes lock an order and it has to be released by support. A wrong code
                    costs an attempt whether or not anything else goes wrong, so it cannot be
                    guessed at.
                </p>
            </form>
            <?php endif; ?>

            <hr class="sl-divider">

            <h2 class="t-heading-md tw-mb-3">Or scan the QR code</h2>
            <p class="t-caption t-muted tw-mb-4">
                The QR carries the order reference and the code. Scanning it fills the field above
                &mdash; the check still happens on the server, so a screenshot of somebody else's QR
                is useless unless that order is genuinely ready at this store.
            </p>
            <button class="sl-btn sl-btn-outline-light" type="button" data-scanner-open>
                <?= component('icon', ['name' => 'qr', 'size' => 18]) ?> Open the scanner
            </button>
            <p class="sl-help tw-mt-2" data-scanner-note>
                Camera scanning is not connected yet. Type the code for now - the verification is
                identical either way, because the scanner only ever fills in the same field.
            </p>
        </section>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Waiting at your counters</h2>

                <?php if ($orders === []) : ?>
                    <p class="t-caption t-muted tw-mb-0">Nothing is waiting to be collected.</p>
                <?php else : ?>
                    <ul class="tw-list-none tw-p-0 tw-m-0">
                        <?php foreach ($orders as $order) : ?>
                            <li class="tw-py-3" style="border-bottom:1px solid var(--c-hairline-light)">
                                <p class="t-caption t-body-strong tw-mb-1">
                                    <span class="t-code"><?= e($order['ref']) ?></span>
                                </p>
                                <p class="t-micro t-muted tw-mb-0">
                                    <?= e($order['customer_name']) ?> &middot; <?= e($order['store_name']) ?>
                                </p>
                                <p class="t-micro t-muted tw-mb-0">
                                    Ready <?= time_tag($order['updated_at_utc'], true) ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">If the code does not work</h2>
                <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
                    <li class="t-caption">Check the customer is at the right store for that order.</li>
                    <li class="t-caption">Check the order is actually marked ready, not still being prepared.</li>
                    <li class="t-caption">Codes are case-insensitive but exact - no spaces.</li>
                    <li class="t-caption">
                        After repeated failures the attempt is blocked for a short period and logged.
                        That is deliberate: it stops someone trying codes until one works.
                    </li>
                </ul>
            </div>
        </aside>
    </div>
</div>
