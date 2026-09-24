<?php
declare(strict_types=1);
/**
 * Notification preferences and marketing consent.
 *
 * THE RULES THIS PAGE MAKES VISIBLE (FR-CRM-05 to FR-CRM-07):
 *  - Service messages about an order you placed are not optional, and the page
 *    says why rather than showing a toggle that does nothing.
 *  - Marketing is off unless switched on, and can be switched off in one click.
 *  - SMS and WhatsApp are shown as NOT CONNECTED, not as toggles that fail.
 *  - Consent changes are timestamped and versioned; the current record is shown.
 *
 * The lists below come from NotificationService::preferencePanel(). Which
 * categories are optional is a RULE, decided there, not a flag hard-coded in
 * this file - a toggle the customer cannot actually use should not exist, and
 * whether they can use it is not a presentational question.
 *
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $channels
 * @var array<string,mixed>       $consent
 * @var string                    $email
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Email preferences',
        'subtitle' => 'What we send you, and how. You can change any of this at any time.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <form method="post" action="<?= e(route('customer.preferences.save')) ?>">
            <?= csrf_field() ?>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">Channels</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Email is the only channel connected. The others are built as interfaces so a
                    provider can be added without touching anything else &mdash; but nothing is
                    connected, so they are not offered as choices that would silently do nothing.
                </p>

                <div class="tw-flex tw-flex-col tw-gap-3">
                    <?php foreach ($channels as $channel) : ?>
                        <?php /* A <label>, not a <div>: the input needs an accessible name whether
                                 or not it is disabled. Caught by the HTML audit. */ ?>
                        <label class="sl-option <?= $channel['connected'] ? 'is-selected' : 'is-disabled' ?>">
                            <input type="checkbox" class="tw-mt-1" name="channel[]"
                                   value="<?= e($channel['key']) ?>"
                                   <?= $channel['connected'] ? 'checked' : 'disabled' ?>>
                            <span class="tw-flex-1">
                                <span class="t-body-strong tw-block"><?= e($channel['label']) ?></span>
                                <span class="t-micro t-muted">
                                    <?= e($channel['connected']
                                        ? 'Connected. Sent to ' . $email . '.' . ($channel['reason'] !== '' ? ' ' . $channel['reason'] : '')
                                        : $channel['reason']) ?>
                                </span>
                            </span>
                            <?= component('badge', [
                                'status' => $channel['connected'] ? 'active' : 'draft',
                                'label'  => $channel['connected'] ? 'Connected' : 'Not connected',
                            ]) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">What we send</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Messages about an order you placed are part of the service you bought, so they
                    are always sent while that order is open. Everything marked
                    <em>optional</em> is yours to switch off.
                </p>

                <?php foreach ($categories as $category) : ?>
                    <div class="tw-py-4" style="border-top:1px solid var(--c-hairline-light)">
                        <label class="sl-check tw-mb-0">
                            <input type="checkbox" name="category[]" value="<?= e($category['key']) ?>"
                                   <?= $category['on'] ? 'checked' : '' ?>
                                   <?= $category['marketing'] ? '' : 'disabled checked' ?>>
                            <span class="sl-check-label">
                                <span class="t-body-strong">
                                    <?= e($category['label']) ?>
                                    <?php if ($category['marketing']) : ?>
                                        <span class="sl-chip sl-chip-mint tw-ml-2">Optional</span>
                                    <?php else : ?>
                                        <span class="sl-chip tw-ml-2">Always sent</span>
                                    <?php endif; ?>
                                </span>
                                <span class="t-micro t-muted tw-block tw-mt-1"><?= e($category['desc']) ?></span>
                                <?php if (!$category['marketing']) : ?>
                                    <span class="t-micro t-muted tw-block tw-mt-1">
                                        Switching this off would mean not being told your order is waiting
                                        for you, so it is not offered.
                                    </span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">Quiet hours</h2>
                <p class="t-caption t-muted tw-mb-3">
                    Optional messages are held from
                    <strong><?= e(sprintf('%02d:00', (int) config('notify.quiet_start'))) ?></strong>
                    until
                    <strong><?= e(sprintf('%02d:00', (int) config('notify.quiet_end'))) ?></strong>
                    and go out the following morning instead of at 2am. Order updates ignore this,
                    because a failed delivery cannot wait until breakfast.
                </p>
                <p class="t-micro t-muted tw-mb-0">
                    This window applies to everyone and is not yours to set &mdash; so it is shown
                    here as what it is, rather than as two dropdowns that would save nothing.
                </p>
            </section>

            <button class="sl-btn sl-btn-primary sl-btn-lg" type="submit">Save preferences</button>
        </form>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card sl-card-band tw-mb-6">
                <h2 class="t-heading-md tw-mb-3">Your consent record</h2>
                <?= component('detail-list', ['items' => array_values(array_filter([
                    ['label' => 'Marketing consent',
                     'value' => $consent['granted'] ? 'active' : 'draft', 'type' => 'badge',
                     'badgeLabel' => $consent['granted'] ? 'Given' : 'Not given'],
                    $consent['at'] !== null
                        ? ['label' => $consent['granted'] ? 'Given on' : 'Withdrawn on',
                           'value' => $consent['at'], 'type' => 'datetime']
                        : null,
                    $consent['source'] !== null
                        ? ['label' => 'Recorded at',
                           'value' => ucwords(str_replace('_', ' ', $consent['source']))]
                        : null,
                    $consent['version'] !== null
                        ? ['label' => 'Version', 'value' => $consent['version']]
                        : null,
                ]))]) ?>
                <?php if ($consent['at'] === null) : ?>
                    <p class="t-caption tw-mt-3 tw-mb-0">
                        There is no record either way, which counts as no. Consent is opt-in here,
                        so silence is a refusal rather than a default yes.
                    </p>
                <?php endif; ?>
                <p class="t-micro tw-mt-3 tw-mb-0">
                    Every change is recorded with who, when and from where, so we can show what you
                    agreed to and when.
                </p>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Stop everything optional</h2>
                <p class="t-caption t-muted tw-mb-4">
                    One click, takes effect immediately, and cancels anything already queued but not
                    yet sent. The same link is at the bottom of every marketing email and works
                    without logging in.
                </p>
                <form method="post" action="<?= e(route('customer.unsubscribe')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <button class="sl-btn sl-btn-danger sl-btn-block" type="submit">
                        Unsubscribe from marketing
                    </button>
                </form>
            </div>
        </aside>
    </div>
</div>
