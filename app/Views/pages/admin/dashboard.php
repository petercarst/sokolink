<?php
declare(strict_types=1);
/**
 * Administrator overview.
 *
 * @var list<array<string,mixed>> $orders
 * @var list<array<string,mixed>> $applications
 * @var list<array<string,mixed>> $tickets
 * @var list<array<string,mixed>> $escalated
 * @var list<array<string,mixed>> $payments
 * @var list<array<string,mixed>> $series
 * @var list<array<string,mixed>> $failed
 * @var list<array<string,mixed>> $audit
 */
$gmv        = \App\Support\MockDashboard::sum($orders, 'total');
$commission = \App\Support\MockDashboard::sum($orders, 'commission');
$weekTotal  = \App\Support\MockDashboard::sum($series, 'revenue');
$maxRevenue = max(array_map(static fn (array $d): float => (float) $d['revenue'], $series));
$pickupCount = count(array_filter($orders, static fn (array $o): bool => $o['fulfilment'] === 'pickup'));
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Platform overview',
        'subtitle' => 'What needs a decision, and how the marketplace is running.',
        'actions'  => [
            ['label' => 'Reports', 'url' => route('admin.reports'), 'icon' => 'leaf'],
            ['label' => 'Audit log', 'url' => route('admin.audit'), 'style' => 'sl-btn-primary', 'icon' => 'lock'],
        ],
    ]) ?>

    <!-- Things only an admin can decide -->
    <?php if ($applications !== [] || $escalated !== [] || $failed !== []) : ?>
        <section class="sl-card sl-card-raised tw-mb-8">
            <h2 class="t-heading-xl tw-mb-4">Waiting on you</h2>
            <div class="sl-grid sl-grid-3">
                <?php if ($applications !== []) : ?>
                    <a class="sl-option tw-no-underline" href="<?= e(route('admin.approvals')) ?>">
                        <span class="tw-mt-1" style="color:var(--c-shade-60)"><?= component('icon', ['name' => 'check-circle', 'size' => 20]) ?></span>
                        <span class="tw-flex-1">
                            <span class="t-body-strong tw-block"><?= e((string) count($applications)) ?> seller applications</span>
                            <span class="t-micro t-muted">Nobody can trade until you decide</span>
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($escalated !== []) : ?>
                    <a class="sl-option tw-no-underline" href="<?= e(route('admin.disputes')) ?>">
                        <span class="tw-mt-1" style="color:var(--c-shade-60)"><?= component('icon', ['name' => 'alert', 'size' => 20]) ?></span>
                        <span class="tw-flex-1">
                            <span class="t-body-strong tw-block"><?= e((string) count($escalated)) ?> escalated disputes</span>
                            <span class="t-micro t-muted">Support cannot resolve these</span>
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($failed !== []) : ?>
                    <a class="sl-option tw-no-underline" href="<?= e(route('admin.deliveries')) ?>">
                        <span class="tw-mt-1" style="color:var(--c-shade-60)"><?= component('icon', ['name' => 'truck', 'size' => 20]) ?></span>
                        <span class="tw-flex-1">
                            <span class="t-body-strong tw-block"><?= e((string) count($failed)) ?> failed deliveries</span>
                            <span class="t-micro t-muted">Need a retry or a return decision</span>
                        </span>
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Revenue this week', 'value' => money_compact($weekTotal), 'delta' => '+8% on last week', 'dir' => 'up'],
        ['label' => 'Orders in view', 'value' => (string) count($orders), 'hint' => 'Across all sellers', 'href' => route('admin.orders')],
        ['label' => 'Commission', 'value' => money_compact($commission), 'hint' => 'Recorded, not yet paid out'],
        ['label' => 'Open tickets', 'value' => (string) count($tickets), 'hint' => 'With support'],
    ]]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-2">Revenue by day</h2>
                <p class="t-caption t-muted tw-mb-6">Whole marketplace, last seven days.</p>

                <div class="tw-flex tw-items-end tw-gap-3" style="height:200px">
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

            <section>
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h2 class="t-heading-xl tw-mb-0">Latest orders</h2>
                    <a class="t-micro sl-link-more" href="<?= e(route('admin.orders')) ?>">Order monitor</a>
                </div>

                <?= component('data-table', [
                    'caption' => 'Most recent orders across the marketplace',
                    'columns' => [
                        ['key' => 'ref',      'label' => 'Order',  'type' => 'code', 'href' => 'url'],
                        ['key' => 'seller',   'label' => 'Seller'],
                        ['key' => 'customer', 'label' => 'Customer'],
                        ['key' => 'status',   'label' => 'Status', 'type' => 'badge'],
                        ['key' => 'total',    'label' => 'Total',  'type' => 'money', 'align' => 'right'],
                    ],
                    'rows' => array_map(static fn (array $o): array => [
                        'ref'      => $o['ref'],
                        'seller'   => $o['seller_name'],
                        'customer' => $o['customer_name'],
                        'status'   => $o['status'],
                        'total'    => $o['total'],
                        'url'      => route('admin.orders.show', ['ref' => $o['ref']]),
                    ], array_slice($orders, 0, 6)),
                ]) ?>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Fulfilment mix</h2>
                <?php
                $total = max(1, count($orders));
                foreach ([['Click and collect', $pickupCount], ['Home delivery', count($orders) - $pickupCount]] as [$label, $count]) :
                    $pct = (int) round(($count / $total) * 100);
                    ?>
                    <div class="tw-mb-3">
                        <div class="tw-flex tw-justify-between tw-gap-2 tw-mb-1">
                            <span class="t-caption"><?= e($label) ?></span>
                            <span class="t-caption tabular"><?= e((string) $pct) ?>%</span>
                        </div>
                        <div style="height:8px;background:var(--c-hairline-light);border-radius:var(--r-pill)">
                            <div style="height:8px;width:<?= e((string) $pct) ?>%;background:var(--c-ink);border-radius:var(--r-pill)"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Payments</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Recorded', 'value' => (string) count($payments)],
                    ['label' => 'Paid', 'value' => (string) count(array_filter($payments, static fn (array $p): bool => $p['status'] === 'paid'))],
                    ['label' => 'Pending', 'value' => (string) count(array_filter($payments, static fn (array $p): bool => $p['status'] === 'pending'))],
                    ['label' => 'Refunded', 'value' => (string) count(array_filter($payments, static fn (array $p): bool => $p['status'] === 'refunded'))],
                ]]) ?>
                <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                   href="<?= e(route('admin.payments')) ?>">Payment monitor</a>
            </div>

            <div class="sl-card">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h2 class="t-heading-md tw-mb-0">Recent audit entries</h2>
                    <a class="t-micro sl-link-more" href="<?= e(route('admin.audit')) ?>">View all</a>
                </div>
                <ul class="tw-list-none tw-p-0 tw-m-0">
                    <?php foreach ($audit as $entry) : ?>
                        <li class="tw-py-3" style="border-bottom:1px solid var(--c-hairline-light)">
                            <p class="t-micro t-code tw-mb-1"><?= e($entry['action']) ?></p>
                            <p class="t-micro t-muted tw-mb-0">
                                <?= e($entry['actor']) ?> &middot; <?= time_tag($entry['at_utc'], true) ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
    </div>
</div>
