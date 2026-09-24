<?php
declare(strict_types=1);
/**
 * Platform notification and reminder settings.
 *
 * The reminder schedule table is the interesting part: every SKIP shows its
 * reason. Without that, "why didn't this customer get a reminder?" is
 * unanswerable, and the retention engine becomes a black box that nobody
 * trusts (USER_FLOWS.md Flow K).
 *
 * @var list<array<string,mixed>> $reminders
 * @var list<array<string,mixed>> $log
 */
$basisLabels = [
    'observed_interval' => 'Customer\'s own repeat interval',
    'seller_hint'       => 'Seller guide, scaled by quantity',
    'category_default'  => 'Category default',
    'none'              => 'Nothing scheduled',
];
$stateTone = [
    'sent'          => 'delivered',
    'scheduled'     => 'processing',
    'skipped'       => 'draft',
    'not_scheduled' => 'draft',
];
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Notification settings',
        'subtitle' => 'Platform-wide message rules, and why each reminder was or was not sent.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <div>
            <section class="tw-mb-10">
                <h2 class="t-heading-xl tw-mb-2">Reminder schedule</h2>
                <p class="t-caption t-muted tw-mb-4">
                    What the estimator decided for each customer and product, and the reason for
                    every skip.
                </p>

                <?= component('data-table', [
                    'caption' => 'Reorder reminder schedule and skip reasons',
                    'columns' => [
                        ['key' => 'customer', 'label' => 'Customer'],
                        ['key' => 'product',  'label' => 'Product'],
                        ['key' => 'basis',    'label' => 'Based on', 'sub' => 'detail'],
                        ['key' => 'due',      'label' => 'Next due', 'type' => 'date'],
                        ['key' => 'state',    'label' => 'Outcome',  'type' => 'badge', 'badgeLabel' => 'stateLabel'],
                        ['key' => 'why',      'label' => 'Why',      'type' => 'muted'],
                    ],
                    'rows' => array_map(static fn (array $r): array => [
                        'customer'   => $r['customer'],
                        'product'    => $r['product'],
                        'basis'      => $basisLabels[$r['basis']] ?? $r['basis'],
                        'detail'     => $r['basis_detail'],
                        'due'        => $r['next_due_utc'],
                        'state'      => $stateTone[$r['state']] ?? 'draft',
                        'stateLabel' => ucwords(str_replace('_', ' ', $r['state'])),
                        'why'        => $r['skip_reason'] !== null
                            ? ucwords(str_replace('_', ' ', $r['skip_reason']))
                            : '',
                    ], $reminders),
                ]) ?>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-2">Platform message rules</h2>
                <p class="t-caption t-muted tw-mb-5">
                    These are ceilings, not targets. A customer's own preferences can be stricter
                    than these but never looser.
                </p>

                <form method="post" action="<?= e(route('preview.submit')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="notification_settings">

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', [
                            'name' => 'cooldown_days', 'label' => 'Minimum days between reminders for the same product',
                            'type' => 'number', 'value' => '14', 'attrs' => ['min' => '1', 'max' => '180'],
                        ]) ?>
                        <?= component('field', [
                            'name' => 'monthly_cap', 'label' => 'Maximum marketing messages per customer per month',
                            'type' => 'number', 'value' => '4', 'attrs' => ['min' => '0', 'max' => '30'],
                            'help' => 'Set to 0 to stop all marketing platform-wide.',
                        ]) ?>
                        <?= component('field', [
                            'name' => 'quiet_start', 'label' => 'Quiet hours start', 'type' => 'number',
                            'value' => '21', 'attrs' => ['min' => '0', 'max' => '23'],
                        ]) ?>
                        <?= component('field', [
                            'name' => 'quiet_end', 'label' => 'Quiet hours end', 'type' => 'number',
                            'value' => '7', 'attrs' => ['min' => '0', 'max' => '23'],
                        ]) ?>
                        <?= component('field', [
                            'name' => 'retry_max', 'label' => 'Delivery retry attempts', 'type' => 'number',
                            'value' => '3', 'attrs' => ['min' => '0', 'max' => '10'],
                            'help' => 'Transient failures only. A permanent rejection stops immediately.',
                        ]) ?>
                        <?= component('field', [
                            'name' => 'sender', 'label' => 'Sender address', 'type' => 'email',
                            'value' => 'no-reply@sokolink.test',
                        ]) ?>
                    </div>

                    <button class="sl-btn sl-btn-primary" type="submit">Save settings</button>
                </form>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Channels</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Email', 'value' => 'active', 'type' => 'badge', 'badgeLabel' => 'Connected'],
                    ['label' => 'SMS', 'value' => 'draft', 'type' => 'badge', 'badgeLabel' => 'Not connected'],
                    ['label' => 'WhatsApp', 'value' => 'draft', 'type' => 'badge', 'badgeLabel' => 'Not connected'],
                ]]) ?>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    SMS and WhatsApp exist as channel interfaces with stub drivers. Anything queued
                    for them is recorded as skipped with the reason
                    <span class="t-code">skipped_no_provider</span> rather than reported as sent.
                </p>
            </div>

            <div class="sl-card sl-card-band tw-mb-6">
                <h2 class="t-heading-md tw-mb-3">Scheduling runs on cron</h2>
                <p class="t-caption tw-mb-0">
                    Reminders are found and queued by a CLI task, and sent by a separate dispatch
                    worker. Neither needs a browser open. If cron stops, nothing sends &mdash; which
                    is the right failure mode.
                </p>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-4">Recent delivery log</h2>
                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach (array_slice($log, 0, 5) as $entry) : ?>
                        <li class="tw-py-3" style="border-bottom:1px solid var(--c-hairline-light)">
                            <p class="t-micro t-code tw-mb-1"><?= e($entry['template']) ?></p>
                            <p class="t-micro t-muted tw-mb-0">
                                <?= e($entry['customer']) ?> &middot;
                                <?= component('badge', ['status' => $entry['status']]) ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                   href="<?= e(route('support.notifications')) ?>">Full monitor</a>
            </div>
        </aside>
    </div>
</div>
