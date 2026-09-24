<?php
declare(strict_types=1);
/**
 * Support order lookup.
 *
 * ACCESS (FR-SUP-04, FR-SUP-06): support is NOT scoped to a subset of orders -
 * a desk that can only see some orders cannot answer the phone. The control is
 * therefore not a WHERE clause but a trail: every order opened is written to the
 * audit log with the agent, the order, the time, and the reason.
 *
 * The reason is collected HERE, beside the search, rather than being asked for
 * after the agent has already seen the result. Searching is not looking: nothing
 * below is an order's contents, only enough to pick the right one.
 *
 * @var string $query
 * @var string $ticket
 * @var list<array<string,mixed>> $orders
 */
$rows = array_map(static fn (array $o): array => [
    'ref'      => $o['ref'],
    'parent'   => $o['parent_ref'],
    'customer' => $o['customer_name'],
    'seller'   => $o['seller_name'],
    'how'      => $o['fulfilment'] === 'pickup' ? 'Collect' : 'Delivery',
    'status'   => $o['status'],
    'payment'  => $o['payment_status'],
    'total'    => $o['total'],
    'url'      => $ticket === ''
        ? route('support.orders.show', ['ref' => $o['ref']])
        : route('support.orders.show', ['ref' => $o['ref'], 'ticket' => $ticket]),
], $orders);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Order lookup',
        'subtitle' => 'Find an order by reference or customer name to answer a question about it.',
    ]) ?>

    <form class="sl-toolbar" method="get" action="<?= e(route('support.orders')) ?>" role="search">
        <div class="sl-field tw-flex-1" style="min-width:16rem">
            <label class="sl-label" for="order-q">Order reference or customer</label>
            <input class="sl-input" type="search" id="order-q" name="q" value="<?= e($query) ?>"
                   placeholder="SL-2026-... or a name">
        </div>
        <div class="sl-field tw-flex-1" style="min-width:14rem">
            <label class="sl-label" for="order-ticket">Which ticket is this for?</label>
            <input class="sl-input" type="text" id="order-ticket" name="ticket" value="<?= e($ticket) ?>"
                   placeholder="TKT-2026-... or a short reason"
                   aria-describedby="order-ticket-help">
            <span class="sl-help" id="order-ticket-help">Recorded against every order you open.</span>
        </div>
        <button class="sl-btn sl-btn-primary" type="submit">
            <?= component('icon', ['name' => 'search', 'size' => 18]) ?> Search
        </button>
        <?php if ($query !== '') : ?>
            <a class="sl-btn sl-btn-ghost" href="<?= e(route('support.orders')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($ticket === '') : ?>
        <div class="sl-alert sl-alert-warn tw-mb-6">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
            <span>
                <strong>Every order you open is logged.</strong>
                The audit entry records who you are, which order, when, and why. Fill in the ticket
                above and the results below will carry it for you. Without one you will be asked
                again before anything opens.
            </span>
        </div>
    <?php else : ?>
        <div class="sl-alert sl-alert-info tw-mb-6">
            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
            <span>
                Opening any result below will be recorded against
                <strong><?= e($ticket) ?></strong>. Payment credentials are not shown because none
                are stored anywhere.
            </span>
        </div>
    <?php endif; ?>

    <?php if ($query !== '') : ?>
        <p class="t-caption t-muted tw-mb-4" role="status">
            <?= e((string) count($rows)) ?> result<?= count($rows) === 1 ? '' : 's' ?>
            for &ldquo;<?= e($query) ?>&rdquo;
        </p>
    <?php endif; ?>

    <?= component('data-table', [
        'caption' => 'Order search results',
        'columns' => [
            ['key' => 'ref',      'label' => 'Order part', 'type' => 'code', 'href' => 'url', 'sub' => 'parent'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'seller',   'label' => 'Seller'],
            ['key' => 'how',      'label' => 'How'],
            ['key' => 'status',   'label' => 'Status',  'type' => 'badge'],
            ['key' => 'payment',  'label' => 'Payment', 'type' => 'badge'],
            ['key' => 'total',    'label' => 'Total',   'type' => 'money', 'align' => 'right'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'search', 'title' => 'Nothing matched',
            'text' => 'Check the reference, or search by the customer name instead.',
            'actionUrl' => route('support.orders'), 'actionLabel' => 'Clear the search',
        ],
    ]) ?>
</div>
