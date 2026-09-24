<?php
declare(strict_types=1);
/**
 * Platform reports.
 *
 * @var list<array<string,mixed>> $series
 * @var list<array<string,mixed>> $orders
 * @var list<array<string,mixed>> $sellers
 * @var list<array<string,mixed>> $reminders
 */
$weekTotal  = \App\Support\MockDashboard::sum($series, 'revenue');
$weekOrders = array_sum(array_column($series, 'orders'));
$maxRevenue = max(array_map(static fn (array $d): float => (float) $d['revenue'], $series));

$sent      = count(array_filter($reminders, static fn (array $r): bool => $r['state'] === 'sent'));
$skipped   = count(array_filter($reminders, static fn (array $r): bool => $r['state'] === 'skipped'));
$notSched  = count(array_filter($reminders, static fn (array $r): bool => $r['state'] === 'not_scheduled'));

$failureReasons = [];
foreach ($orders as $order) {
    foreach ($order['delivery']['failures'] ?? [] as $failure) {
        $key = $failure['reason_code'];
        $failureReasons[$key] = ($failureReasons[$key] ?? 0) + 1;
    }
}
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Reports',
        'subtitle' => 'How the marketplace is performing, and whether the retention engine is behaving.',
        'actions'  => [['label' => 'Export CSV', 'feature' => 'report_export', 'icon' => 'external']],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Revenue this week', 'value' => money_compact($weekTotal), 'delta' => '+8% on last week', 'dir' => 'up'],
        ['label' => 'Orders this week', 'value' => (string) $weekOrders, 'delta' => '+5% on last week', 'dir' => 'up'],
        ['label' => 'Average order', 'value' => money_compact((string) ((float) $weekTotal / max(1, $weekOrders))), 'hint' => 'Marketplace-wide'],
        ['label' => 'Active sellers', 'value' => (string) count($sellers), 'hint' => 'Trading this period'],
    ]]) ?>

    <section class="sl-card tw-mb-8">
        <h2 class="t-heading-xl tw-mb-2">Revenue by day</h2>
        <p class="t-caption t-muted tw-mb-6">Whole marketplace, last seven days.</p>

        <div class="tw-flex tw-items-end tw-gap-3" style="height:220px">
            <?php foreach ($series as $day) : ?>
                <?php $pct = (int) round(((float) $day['revenue'] / $maxRevenue) * 100); ?>
                <div class="tw-flex-1 tw-flex tw-flex-col tw-items-center tw-gap-2 tw-h-full tw-justify-end">
                    <span class="t-micro tabular"><?= e(money_compact($day['revenue'], false)) ?></span>
                    <span style="width:100%;height:<?= e((string) $pct) ?>%;background:var(--c-aloe-10);border-radius:var(--r-sm) var(--r-sm) 0 0"
                          role="img"
                          aria-label="<?= e($day['label']) ?>: <?= e(money($day['revenue'])) ?> from <?= e((string) $day['orders']) ?> orders"></span>
                    <span class="t-micro t-muted"><?= e($day['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tw-mb-8">
        <h2 class="t-heading-xl tw-mb-4">By seller</h2>
        <?= component('data-table', [
            'caption' => 'Performance by seller',
            'columns' => [
                ['key' => 'seller',           'label' => 'Seller'],
                ['key' => 'orders',           'label' => 'Orders',  'type' => 'number', 'align' => 'right'],
                ['key' => 'revenue',          'label' => 'Revenue', 'type' => 'money',  'align' => 'right'],
                ['key' => 'rejection_rate',   'label' => 'Rejection rate', 'align' => 'right'],
                ['key' => 'avg_ready_hours',  'label' => 'Avg hours to ready', 'align' => 'right'],
            ],
            'rows' => $sellers,
        ]) ?>
        <p class="t-micro t-muted tw-mt-3 tw-mb-0">
            A rising rejection rate is usually a stock-accuracy problem rather than a bad seller.
            Worth a conversation before an account action.
        </p>
    </section>

    <div class="sl-grid sl-grid-2">
        <section class="sl-card">
            <h2 class="t-heading-xl tw-mb-2">Retention engine</h2>
            <p class="t-caption t-muted tw-mb-5">
                Whether reminders are being sent, and how often the system correctly declines.
            </p>

            <?= component('detail-list', ['items' => [
                ['label' => 'Reminders sent', 'value' => (string) $sent],
                ['label' => 'Correctly skipped', 'value' => (string) $skipped],
                ['label' => 'Not enough data to schedule', 'value' => (string) $notSched],
                ['label' => 'Duplicates sent', 'value' => '0'],
            ]]) ?>

            <div class="sl-alert sl-alert-success tw-mt-5 tw-mb-0">
                <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'check', 'size' => 18]) ?></span>
                <span class="t-caption">
                    A high skip count is healthy. It means consent, cooldowns and repurchase checks
                    are doing their job. Duplicates are structurally impossible &mdash; a unique key
                    on customer, product and cycle prevents them at the database level.
                </span>
            </div>
        </section>

        <section class="sl-card">
            <h2 class="t-heading-xl tw-mb-2">Delivery failures</h2>
            <p class="t-caption t-muted tw-mb-5">
                Reasons agents recorded. These point at operational fixes, not at individual agents.
            </p>

            <?php if ($failureReasons === []) : ?>
                <p class="t-caption t-muted tw-mb-0">No failed attempts recorded in this period.</p>
            <?php else : ?>
                <?php $maxFail = max($failureReasons); ?>
                <?php foreach ($failureReasons as $reason => $count) : ?>
                    <div class="tw-mb-3">
                        <div class="tw-flex tw-justify-between tw-gap-2 tw-mb-1">
                            <span class="t-caption"><?= e(ucwords(str_replace('_', ' ', $reason))) ?></span>
                            <span class="t-caption tabular"><?= e((string) $count) ?></span>
                        </div>
                        <div style="height:8px;background:var(--c-hairline-light);border-radius:var(--r-pill)">
                            <div style="height:8px;width:<?= e((string) (int) round($count / $maxFail * 100)) ?>%;background:var(--c-status-danger-fg);border-radius:var(--r-pill)"></div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="t-micro t-muted tw-mt-4 tw-mb-0">
                    A lot of "nobody there" usually means delivery windows, not agents. That is the
                    kind of thing a reason code makes visible and a bare failure count does not.
                </p>
            <?php endif; ?>
        </section>
    </div>
</div>
