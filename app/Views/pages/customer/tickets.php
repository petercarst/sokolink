<?php
declare(strict_types=1);
/**
 * Customer support requests.
 *
 * @var list<array<string,mixed>> $tickets
 * @var list<string>             $orders  the customer's own order references
 */
$rows = array_map(static fn (array $t): array => [
    'ref'     => $t['ref'],
    'subject' => $t['subject'],
    'order'   => $t['order_ref'] ?? '',
    'status'  => $t['status'],
    'updated' => $t['updated_at_utc'],
    'url'     => route('customer.tickets.show', ['ref' => $t['ref']]),
], $tickets);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Support',
        'subtitle' => 'Requests you have opened and where each one has got to.',
        'actions'  => [['label' => 'New request', 'url' => '#new-request', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Your support requests',
        'columns' => [
            ['key' => 'ref',     'label' => 'Reference', 'type' => 'code', 'href' => 'url'],
            ['key' => 'subject', 'label' => 'Subject',   'sub' => 'order'],
            ['key' => 'status',  'label' => 'Status',    'type' => 'badge'],
            ['key' => 'updated', 'label' => 'Last update', 'type' => 'relative'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'info', 'title' => 'No support requests',
            'text' => 'If something goes wrong with an order, open a request and we will pick it up.',
            'actionUrl' => route('page.contact'), 'actionLabel' => 'Contact support',
        ],
    ]) ?>

    <div class="sl-card tw-mt-8" id="new-request">
        <h2 class="t-heading-xl tw-mb-2">Open a request</h2>
        <p class="t-caption t-muted tw-mb-5">
            We reply by email and on this page. If it is about an order, attaching the reference
            saves us asking for it.
        </p>

        <form method="post" action="<?= e(route('customer.tickets.open')) ?>" data-validate>
            <?= csrf_field() ?>

            <?= component('field', [
                'name' => 'subject', 'label' => 'What is it about?', 'required' => true,
                'placeholder' => 'One line - "Collection code never arrived"',
                'maxlength' => 190,
            ]) ?>

            <?= component('field', [
                'name' => 'category', 'label' => 'Topic', 'type' => 'select', 'required' => true,
                'options' => [
                    'order'      => 'A problem with an order',
                    'collection' => 'Collection or a collection code',
                    'delivery'   => 'A delivery',
                    'payment'    => 'Payment or a refund',
                    'account'    => 'My account',
                    'seller'     => 'Selling on SokoLink',
                    'other'      => 'Something else',
                ],
            ]) ?>

            <?php if ($orders !== []) : ?>
                <?= component('field', [
                    'name' => 'order_number', 'label' => 'Which order?', 'type' => 'select',
                    'options' => array_merge(
                        ['' => 'Not about a specific order'],
                        array_combine($orders, $orders)
                    ),
                    'help' => 'Only your own orders are listed, and only your own are accepted.',
                ]) ?>
            <?php else : ?>
                <?= component('field', [
                    'name' => 'order_number', 'label' => 'Order reference (optional)',
                    'placeholder' => 'SL-2026-...',
                    'help' => 'Leave this blank if it is not about an order.',
                ]) ?>
            <?php endif; ?>

            <?= component('field', [
                'name' => 'body', 'label' => 'What happened?', 'type' => 'textarea',
                'required' => true, 'maxlength' => 3000,
                'help' => 'Please do not include passwords, PINs or full card numbers. '
                    . 'Support staff cannot see them and will never ask.',
            ]) ?>

            <button class="sl-btn sl-btn-primary" type="submit">Open the request</button>
        </form>
    </div>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">Before you open a request</h2>
        <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
            <li class="t-caption">
                Your collection code is on the order page and in the ready-to-collect email.
            </li>
            <li class="t-caption">
                The order page shows the live delivery status and the reason for any failed attempt.
            </li>
            <li class="t-caption">
                To stop reminder emails, use
                <a href="<?= e(route('customer.preferences')) ?>">email preferences</a> &mdash;
                no request needed.
            </li>
        </ul>
    </div>
</div>
