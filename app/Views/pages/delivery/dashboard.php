<?php
declare(strict_types=1);
/**
 * Delivery agent overview.
 *
 * Built for someone holding a phone at a gate, so the current job and its next
 * action come first and everything else is secondary.
 *
 * @var list<array<string,mixed>> $tasks
 * @var list<array<string,mixed>> $offers
 * @var list<array<string,mixed>> $done
 */
$current = $tasks[0] ?? null;
$earned  = array_sum(array_map(
    static fn (array $t): float => (float) ($t['delivery_fee'] ?? 0),
    array_merge($tasks, $done)
));
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Today',
        'subtitle' => 'Your assigned deliveries and any jobs you can pick up.',
        'actions'  => [['label' => 'Available jobs', 'url' => route('delivery.offers'), 'style' => 'sl-btn-primary', 'icon' => 'bell']],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Assigned to you', 'value' => (string) count($tasks), 'hint' => 'Live deliveries', 'href' => route('delivery.tasks')],
        ['label' => 'Jobs available', 'value' => (string) count($offers), 'hint' => 'You can accept or decline', 'href' => route('delivery.offers')],
        ['label' => 'Completed', 'value' => (string) count($done), 'hint' => 'All time', 'href' => route('delivery.history')],
        ['label' => 'Delivery fees', 'value' => money_compact((string) $earned), 'hint' => 'On the jobs in view'],
    ]]) ?>

    <?php if ($current !== null) : ?>
        <section class="sl-card sl-card-raised tw-mb-8">
            <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                <div>
                    <p class="t-eyebrow t-muted tw-mb-2">Current job</p>
                    <h2 class="t-heading-xl tw-mb-1">
                        <?= e($current['delivery']['recipient']) ?>
                    </h2>
                    <p class="t-caption t-muted tw-mb-0">
                        <span class="t-code"><?= e($current['delivery']['task_ref']) ?></span>
                        &middot; <?= e($current['delivery']['zone']) ?>
                    </p>
                </div>
                <?= component('badge', ['status' => $current['status']]) ?>
            </div>

            <address class="t-body-lg tw-not-italic tw-mb-3">
                <?= e($current['delivery']['address']) ?>
            </address>

            <?php if (!empty($current['delivery']['landmark'])) : ?>
                <p class="t-caption t-muted tw-mb-2">
                    <?= component('icon', ['name' => 'map-pin', 'size' => 15]) ?>
                    <?= e($current['delivery']['landmark']) ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($current['delivery']['instructions'])) : ?>
                <div class="sl-alert sl-alert-info tw-mb-4">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                    <span><strong>From the customer:</strong> <?= e($current['delivery']['instructions']) ?></span>
                </div>
            <?php endif; ?>

            <div class="tw-flex tw-gap-2 tw-flex-wrap">
                <a class="sl-btn sl-btn-primary" href="<?= e(route('delivery.tasks.show', ['ref' => $current['ref']])) ?>">
                    Open this delivery
                </a>
                <a class="sl-btn sl-btn-outline-light" href="tel:+255000000000">
                    <?= component('icon', ['name' => 'phone', 'size' => 18]) ?> Call recipient
                </a>
            </div>
        </section>
    <?php endif; ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <section>
            <h2 class="t-heading-xl tw-mb-4">Your deliveries</h2>

            <?php if ($tasks === []) : ?>
                <?= component('empty-state', [
                    'icon' => 'truck', 'title' => 'Nothing assigned right now',
                    'text' => 'When a job is assigned to you it appears here with the address and the recipient.',
                    'actionUrl' => route('delivery.offers'), 'actionLabel' => 'See available jobs',
                ]) ?>
            <?php else : ?>
                <div class="tw-flex tw-flex-col tw-gap-4">
                    <?php foreach ($tasks as $task) : ?>
                        <article class="sl-card">
                            <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                                <div>
                                    <p class="t-body-strong tw-mb-1"><?= e($task['delivery']['recipient']) ?></p>
                                    <p class="t-micro t-muted tw-mb-0">
                                        <span class="t-code"><?= e($task['delivery']['task_ref']) ?></span>
                                        &middot; <?= e($task['delivery']['zone']) ?>
                                        <?php if ((int) $task['delivery']['attempts'] > 0) : ?>
                                            &middot; attempt <?= e((string) ((int) $task['delivery']['attempts'] + 1)) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?= component('badge', ['status' => $task['status']]) ?>
                            </div>

                            <p class="t-caption t-muted tw-mb-4"><?= e($task['delivery']['address']) ?></p>

                            <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                               href="<?= e(route('delivery.tasks.show', ['ref' => $task['ref']])) ?>">Open</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card sl-card-band tw-mb-6">
                <h2 class="t-heading-md tw-mb-3">What you can see</h2>
                <p class="t-caption tw-mb-0">
                    Only deliveries assigned to you, and only what you need to deliver them: the
                    recipient, the address, the instructions and a masked phone number. Not what is
                    in the parcel, not what the customer paid, and nothing about their other orders.
                </p>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-4">Recently completed</h2>
                <?php if ($done === []) : ?>
                    <p class="t-caption t-muted tw-mb-0">Nothing completed yet.</p>
                <?php else : ?>
                    <ul class="tw-list-none tw-p-0 tw-m-0">
                        <?php foreach ($done as $task) : ?>
                            <li class="tw-py-3" style="border-bottom:1px solid var(--c-hairline-light)">
                                <p class="t-caption t-body-strong tw-mb-1"><?= e($task['delivery']['recipient']) ?></p>
                                <p class="t-micro t-muted tw-mb-0">
                                    <?php if ($task['delivery']['delivered_at_utc'] !== null) : ?>
                                        Delivered <?= time_tag((string) $task['delivery']['delivered_at_utc'], true) ?>
                                    <?php else : ?>
                                        <!-- A returned parcel is finished too, and has no delivered-at.
                                             Saying "Delivered" over a blank date would be two lies. -->
                                        Returned to the seller after
                                        <?= e((string) $task['delivery']['attempts']) ?> attempts
                                    <?php endif; ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                       href="<?= e(route('delivery.history')) ?>">Full history</a>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</div>
