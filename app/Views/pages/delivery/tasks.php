<?php
declare(strict_types=1);
/**
 * Assigned deliveries.
 *
 * @var list<array<string,mixed>> $tasks
 */
$rows = array_map(static fn (array $t): array => [
    'task'      => $t['delivery']['task_ref'],
    'recipient' => $t['delivery']['recipient'],
    'address'   => str_limit($t['delivery']['address'], 44),
    'zone'      => $t['delivery']['zone'],
    'status'    => $t['status'],
    'attempts'  => $t['delivery']['attempts'],
    'fee'       => $t['delivery_fee'],
    'url'       => route('delivery.tasks.show', ['ref' => $t['ref']]),
], $tasks);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'My deliveries',
        'subtitle' => 'Only jobs assigned to you. You cannot see anyone else\'s.',
        'actions'  => [['label' => 'Available jobs', 'url' => route('delivery.offers'), 'icon' => 'bell']],
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Deliveries assigned to you',
        'columns' => [
            ['key' => 'task',      'label' => 'Task',      'type' => 'code', 'href' => 'url'],
            ['key' => 'recipient', 'label' => 'Recipient', 'sub' => 'address'],
            ['key' => 'zone',      'label' => 'Zone'],
            ['key' => 'status',    'label' => 'Status',    'type' => 'badge'],
            ['key' => 'attempts',  'label' => 'Attempts',  'type' => 'number', 'align' => 'right'],
            ['key' => 'fee',       'label' => 'Fee',       'type' => 'money',  'align' => 'right'],
            ['type' => 'actions',  'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url'], 'style' => 'sl-btn-primary'],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'truck', 'title' => 'Nothing assigned to you',
            'text' => 'Jobs assigned to you appear here. You can also pick up available work.',
            'actionUrl' => route('delivery.offers'), 'actionLabel' => 'See available jobs',
        ],
    ]) ?>
</div>
