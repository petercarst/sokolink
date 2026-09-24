<?php
declare(strict_types=1);
/**
 * Seller sales reports.
 *
 * A seller sees their OWN figures only. There is no platform-wide comparison
 * and no other seller's data anywhere on this page
 * (USER_ROLES_AND_PERMISSIONS.md section 4.1).
 *
 * @var list<array<string,mixed>> $series
 * @var list<array<string,mixed>> $orders
 * @var list<array<string,mixed>> $byProduct
 */
$revenue    = \App\Support\MockDashboard::sum($orders, 'total');
$commission = \App\Support\MockDashboard::sum($orders, 'commission');
$weekTotal  = \App\Support\MockDashboard::sum($series, 'revenue');
$weekOrders = array_sum(array_column($series, 'orders'));
$maxRevenue = max(array_map(static fn (array $d): float => (float) $d['revenue'], $series));
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Sales reports',
        'subtitle' => 'Your figures only. Nothing here is shared with other sellers, and theirs is not shared with you.',
        'actions'  => [['label' => 'Export CSV', 'feature' => 'report_export', 'icon' => 'external']],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Revenue this week', 'value' => money_compact($weekTotal), 'delta' => '+8% on last week', 'dir' => 'up'],
        ['label' => 'Orders this week', 'value' => (string) $weekOrders, 'delta' => '+5% on last week', 'dir' => 'up'],
        ['label' => 'Average order', 'value' => money_compact((string) ((float) $weekTotal / max(1, $weekOrders))), 'hint' => 'Across all stores'],
        ['label' => 'Commission', 'value' => money_compact($commission), 'hint' => '5% of the orders in view'],
    ]]) ?>

    <section class="sl-card tw-mb-8">
        <h2 class="t-heading-xl tw-mb-2">Revenue by day</h2>
        <p class="t-caption t-muted tw-mb-6">Last seven days across both stores.</p>

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

        <div class="sl-table-scroll">

        <table class="sl-table sl-table-reflow tw-mt-6">
            <caption class="visually-hidden">Revenue and orders per day, as a table</caption>
            <thead>
                <tr><th scope="col">Day</th><th scope="col" class="sl-num">Orders</th><th scope="col" class="sl-num">Revenue</th></tr>
            </thead>
            <tbody>
                <?php foreach ($series as $day) : ?>
                    <tr>
                        <td data-label="Day"><?= e($day['label']) ?></td>
                        <td data-label="Orders" class="sl-num tabular"><?= e((string) $day['orders']) ?></td>
                        <td data-label="Revenue" class="sl-num tabular"><?= e(money($day['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="tw-mb-8">
        <h2 class="t-heading-xl tw-mb-4">By product</h2>
        <?= component('data-table', [
            'caption' => 'Sales by product',
            'columns' => [
                ['key' => 'product', 'label' => 'Product'],
                ['key' => 'units',   'label' => 'Units sold', 'type' => 'number', 'align' => 'right'],
                ['key' => 'revenue', 'label' => 'Revenue',    'type' => 'money',  'align' => 'right'],
                ['key' => 'returns', 'label' => 'Returns',    'type' => 'number', 'align' => 'right'],
            ],
            'rows' => $byProduct,
        ]) ?>
    </section>

    <section>
        <h2 class="t-heading-xl tw-mb-4">Fulfilment mix</h2>
        <div class="sl-grid sl-grid-2">
            <?php
            $pickupCount   = count(array_filter($orders, static fn (array $o): bool => $o['fulfilment'] === 'pickup'));
            $deliveryCount = count($orders) - $pickupCount;
            $total         = max(1, count($orders));
            ?>
            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">How customers receive orders</h3>
                <?php foreach ([['Click and collect', $pickupCount], ['Home delivery', $deliveryCount]] as [$label, $count]) : ?>
                    <?php $pct = (int) round(($count / $total) * 100); ?>
                    <div class="tw-mb-4">
                        <div class="tw-flex tw-justify-between tw-gap-2 tw-mb-1">
                            <span class="t-caption"><?= e($label) ?></span>
                            <span class="t-caption tabular"><?= e((string) $count) ?> (<?= e((string) $pct) ?>%)</span>
                        </div>
                        <div style="height:8px;background:var(--c-hairline-light);border-radius:var(--r-pill)">
                            <div style="height:8px;width:<?= e((string) $pct) ?>%;background:var(--c-ink);border-radius:var(--r-pill)"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sl-card">
                <h3 class="t-heading-md tw-mb-4">Order outcomes</h3>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Completed', 'value' => (string) count(array_filter($orders, static fn (array $o): bool => $o['status'] === 'completed'))],
                    ['label' => 'In progress', 'value' => (string) count(array_filter($orders, static fn (array $o): bool => in_array($o['status'], ['awaiting_seller', 'confirmed', 'preparing', 'ready_for_pickup'], true)))],
                    ['label' => 'Rejected by you', 'value' => (string) count(array_filter($orders, static fn (array $o): bool => $o['status'] === 'rejected_seller'))],
                    ['label' => 'Revenue in view', 'value' => $revenue, 'type' => 'money'],
                ]]) ?>
                <p class="t-micro t-muted tw-mt-4 tw-mb-0">
                    A high rejection rate usually means stock counts are drifting. The inventory page
                    keeps a permanent movement history to help find where.
                </p>
            </div>
        </div>
    </section>
</div>
