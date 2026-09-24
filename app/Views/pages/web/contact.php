<?php
declare(strict_types=1);
/**
 * Contact and support - cinematic track.
 *
 * This form creates a REAL support ticket (FR-SUP-01). It posts to the same
 * handler as the one inside the dashboard rather than having a second path
 * into the same table.
 *
 * It needs an account, and that is a decision rather than an oversight: a
 * ticket is a thread with a status the person comes back and reads, and
 * `support_tickets.user_id` is NOT NULL because a reply has to have somewhere
 * to go. A signed-out visitor is shown the way in, and comes back here.
 *
 * @var bool $signedIn
 * @var bool $canOpen
 */
?>

<section class="sl-section-tight">
    <div class="sl-container">
        <?= partial('breadcrumbs', [
            'crumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Contact and support', 'url' => null],
            ],
            'onDark' => true,
        ]) ?>

        <h1 class="t-display-md tw-mb-4">Contact and support</h1>
        <p class="t-body-lg t-muted-dark tw-mb-0" style="max-width:56ch">
            Tell us what is wrong and we will pick it up. If it is about an order, quote the order
            number and we can see its full history.
        </p>
    </div>
</section>

<section class="tw-pb-20">
    <div class="sl-container">
        <div class="lg:tw-grid lg:tw-gap-10 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">

            <div class="sl-card-cinematic">
                <h2 class="t-heading-xl tw-mb-6">Send us a message</h2>

                <?php if ($signedIn && !$canOpen) : ?>
                    <p class="t-body-md t-muted-dark tw-mb-4">
                        You are signed in to a seller or staff account. Support for those runs
                        through your own dashboard, where whoever picks it up can already see your
                        stores and orders.
                    </p>
                    <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(url('/')) ?>">Back to your dashboard</a>
                <?php elseif (!$signedIn) : ?>
                    <p class="t-body-md t-muted-dark tw-mb-4">
                        Sign in first and this becomes a request you can follow: it gets a reference,
                        a status, and a thread you can read and reply to. We do it this way because a
                        message with no account behind it has nowhere for our answer to go.
                    </p>
                    <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mb-6">
                        <a class="sl-btn sl-btn-outline-dark sl-btn-lg" href="<?= e(route('auth.login')) ?>">Sign in</a>
                        <a class="sl-btn sl-btn-ghost-dark sl-btn-lg" href="<?= e(route('auth.register')) ?>">Create an account</a>
                    </div>
                    <p class="t-caption t-muted-dark tw-mb-0">
                        You will come straight back here once you are in.
                    </p>
                <?php else : ?>
                    <form method="post" action="<?= e(route('customer.tickets.open')) ?>" data-validate>
                        <?= csrf_field() ?>

                        <div class="sl-field">
                            <label class="sl-label t-on-dark" for="c-subject">
                                What is it about?<span class="sl-required" aria-hidden="true">*</span>
                                <span class="visually-hidden"> (required)</span>
                            </label>
                            <input class="sl-input sl-input-dark" type="text" id="c-subject" name="subject"
                                   required maxlength="190" value="<?= e(old('subject')) ?>"
                                   placeholder="One line, such as: collection code never arrived">
                            <?php if (error_for('subject')) : ?>
                                <span class="sl-error"><?= e(error_for('subject')) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="sl-field">
                            <label class="sl-label t-on-dark" for="c-topic">
                                Topic<span class="sl-required" aria-hidden="true">*</span>
                                <span class="visually-hidden"> (required)</span>
                            </label>
                            <select class="sl-select sl-input-dark" id="c-topic" name="category" required>
                                <?php foreach ([
                                    'order'      => 'A problem with an order',
                                    'collection' => 'Collection or a collection code',
                                    'delivery'   => 'A delivery',
                                    'payment'    => 'Payment or a refund',
                                    'account'    => 'My account',
                                    'seller'     => 'Selling on SokoLink',
                                    'other'      => 'Something else',
                                ] as $value => $label) : ?>
                                    <option value="<?= e($value) ?>"
                                            <?= old('category') === $value ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (error_for('category')) : ?>
                                <span class="sl-error"><?= e(error_for('category')) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="sl-field">
                            <label class="sl-label t-on-dark" for="c-order">Order reference</label>
                            <input class="sl-input sl-input-dark t-code" type="text" id="c-order"
                                   name="order_number" maxlength="32" value="<?= e(old('order_number')) ?>"
                                   placeholder="SL-2026-..." aria-describedby="c-order-help">
                            <span class="sl-help t-muted-dark" id="c-order-help">
                                Optional, and only accepted if the order is on your account.
                            </span>
                            <?php if (error_for('order_number')) : ?>
                                <span class="sl-error"><?= e(error_for('order_number')) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="sl-field">
                            <label class="sl-label t-on-dark" for="c-message">
                                What happened?<span class="sl-required" aria-hidden="true">*</span>
                                <span class="visually-hidden"> (required)</span>
                            </label>
                            <textarea class="sl-textarea sl-input-dark" id="c-message" name="body" required
                                      maxlength="3000" data-counter="c-message-count"
                                      aria-describedby="c-message-count"><?= e(old('body')) ?></textarea>
                            <span class="sl-help t-muted-dark" id="c-message-count">3000 characters remaining</span>
                            <?php if (error_for('body')) : ?>
                                <span class="sl-error"><?= e(error_for('body')) ?></span>
                            <?php endif; ?>
                        </div>

                        <p class="t-micro t-muted-dark tw-mb-6">
                            Please do not include passwords, PINs or full card numbers. We will never ask
                            for them, and support staff cannot see them.
                        </p>

                        <button class="sl-btn sl-btn-outline-dark sl-btn-lg" type="submit">Open a request</button>
                    </form>
                <?php endif; ?>
            </div>

            <aside class="tw-mt-8 lg:tw-mt-0">
                <div class="sl-card-cinematic tw-mb-6">
                    <h2 class="t-heading-md tw-mb-4">Before you write</h2>
                    <ul class="tw-list-none tw-p-0 tw-m-0 tw-flex tw-flex-col tw-gap-4">
                        <li>
                            <p class="t-body-strong tw-mb-1">Order not collected yet?</p>
                            <p class="t-caption t-muted-dark tw-mb-0">
                                Your collection code is on the order page and in the ready-to-collect email.
                            </p>
                        </li>
                        <li>
                            <p class="t-body-strong tw-mb-1">Delivery running late?</p>
                            <p class="t-caption t-muted-dark tw-mb-0">
                                The order page shows the current delivery status and any failed attempt reason.
                            </p>
                        </li>
                        <li>
                            <p class="t-body-strong tw-mb-1">Want to stop reminder emails?</p>
                            <p class="t-caption t-muted-dark tw-mb-0">
                                Use the unsubscribe link in any of them, or
                                <a href="<?= e(route('page.unsubscribe')) ?>">manage preferences here</a>.
                                No login needed.
                            </p>
                        </li>
                    </ul>
                </div>

                <div class="sl-card-cinematic">
                    <h2 class="t-heading-md tw-mb-4">Response times</h2>
                    <p class="t-caption t-muted-dark tw-mb-3">
                        Tickets are answered in the order they arrive, with order problems prioritised.
                    </p>
                    <p class="t-caption t-muted-dark tw-mb-0">
                        Support hours are shown in
                        <?= e((string) config('app.display_timezone')) ?>.
                    </p>
                </div>

                <div class="tw-mt-6">
                    <?= component('devnote', [
                        'onDark' => true,
                        'text'   => 'This form is live. It opens a real support ticket with a '
                                  . 'reference and a status, visible to you in your dashboard and '
                                  . 'to the support desk in its queue.',
                    ]) ?>
                </div>
            </aside>
        </div>
    </div>
</section>
