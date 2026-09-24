<?php
declare(strict_types=1);
/**
 * Unsubscribe - TRANSACTIONAL track.
 *
 * Reachable WITHOUT logging in (FR-CRM-07). Requiring a login to stop marketing
 * email is a dark pattern and in several jurisdictions unlawful. The token in
 * the link is what identifies the recipient. The token is single-use: consuming
 * it is what proves the request came from the mailbox, and it records the
 * withdrawal with a timestamp.
 *
 * Note the split: stopping marketing does not stop order updates, and the page
 * says so rather than letting someone accidentally silence the message that
 * tells them their order is ready to collect.
 *
 * @var string $token
 * @var bool   $hasToken
 * @var bool   $confirmed
 */
?>

<section class="sl-section-tight">
    <div class="sl-container-read sl-container">

        <?php if ($confirmed) : ?>

            <div class="tw-text-center tw-mb-8">
                <span class="sl-empty-icon" style="background:var(--c-status-success-bg);color:var(--c-status-success-fg)">
                    <?= component('icon', ['name' => 'check', 'size' => 26]) ?>
                </span>
                <h1 class="t-display-md tw-mt-6 tw-mb-4">You are unsubscribed</h1>
                <p class="t-body-lg t-muted tw-mb-0">
                    We will not send you any more reorder reminders or offers. Anything already queued
                    but not yet sent has been cancelled.
                </p>
            </div>

            <div class="sl-alert sl-alert-info tw-mb-8">
                <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                <span>
                    You will still receive messages about orders you place - confirmations, ready to
                    collect, out for delivery, refunds. Those are part of the service rather than
                    marketing, so they are not something you can be opted out of while you have an
                    active order.
                </span>
            </div>

            <div class="tw-flex tw-flex-wrap tw-gap-3">
                <a class="sl-btn sl-btn-primary" href="<?= e(route('home')) ?>">Back to the marketplace</a>
                <a class="sl-btn sl-btn-outline-light" href="<?= e(route('page.unsubscribe')) ?>?token=<?= e($token) ?>">
                    I did not mean to do that
                </a>
            </div>

        <?php elseif (!$hasToken) : ?>

            <h1 class="t-display-md tw-mb-4">Manage email preferences</h1>
            <p class="t-body-lg t-muted tw-mb-8">
                To change what you receive without logging in, use the unsubscribe link at the bottom
                of any reminder or offer we sent you. That link carries a token that identifies your
                preferences securely.
            </p>

            <div class="sl-alert sl-alert-warn tw-mb-8">
                <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                <span>
                    <strong>No token in this link.</strong>
                    We deliberately do not ask for your email address on this page. If we did,
                    anybody could use it to find out whether an address has an account here.
                </span>
            </div>

            <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mb-10">
                <a class="sl-btn sl-btn-primary" href="<?= e(route('auth.login')) ?>">
                    Log in to manage preferences
                </a>
                <a class="sl-btn sl-btn-outline-light" href="<?= e(route('page.contact')) ?>">
                    Contact support
                </a>
            </div>

            <p class="t-micro t-muted tw-mb-0">
                <a class="sl-link-quiet" href="<?= e(route('page.unsubscribe')) ?>?token=demo-token-for-review">
                    Preview this page as it appears from an email link
                </a>
            </p>

        <?php else : ?>

            <h1 class="t-display-md tw-mb-4">Stop these emails?</h1>
            <p class="t-body-lg t-muted tw-mb-8">
                You can turn off marketing email entirely, or keep some of it. You do not need to log
                in to do this.
            </p>

            <form method="post" action="<?= e(route('page.unsubscribe.submit')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-5">What would you like to stop?</h2>

                    <label class="sl-check">
                        <input type="checkbox" name="stop[]" value="reorder_reminders" checked>
                        <span class="sl-check-label">
                            Reorder reminders
                            <span class="t-micro t-muted tw-block">
                                The occasional nudge when we estimate you are running low on something
                                you buy regularly.
                            </span>
                        </span>
                    </label>

                    <label class="sl-check">
                        <input type="checkbox" name="stop[]" value="offers" checked>
                        <span class="sl-check-label">
                            Offers and promotions
                            <span class="t-micro t-muted tw-block">Reduced prices and seasonal offers.</span>
                        </span>
                    </label>

                    <hr class="sl-divider">

                    <p class="t-caption t-muted tw-mb-0 tw-flex tw-items-start tw-gap-2">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 15]) ?></span>
                        <span>
                            Messages about orders you have placed are not listed here. Confirmations,
                            ready-to-collect notices, delivery updates and refund notices keep coming,
                            because switching those off would mean not being told your order is
                            waiting for you.
                        </span>
                    </p>
                </div>

                <div class="tw-flex tw-flex-wrap tw-gap-3">
                    <button class="sl-btn sl-btn-primary sl-btn-lg" type="submit">
                        Confirm and unsubscribe
                    </button>
                    <a class="sl-btn sl-btn-ghost sl-btn-lg" href="<?= e(route('home')) ?>">
                        Cancel, keep everything
                    </a>
                </div>
            </form>

            <div class="tw-mt-8">
                <?= component('devnote', [
                    'text' => 'Phase 1 preview. Phase 3.9 verifies the signed token, records the withdrawal with a '
                            . 'timestamp, and cancels queued marketing messages for this recipient at dispatch time. '
                            . 'Add &done=1 to this address to review the confirmed state.',
                ]) ?>
            </div>

        <?php endif; ?>
    </div>
</section>
