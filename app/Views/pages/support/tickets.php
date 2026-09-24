<?php
declare(strict_types=1);
/**
 * Support ticket queue.
 *
 * @var string $status
 * @var list<array<string,mixed>> $tickets
 * @var array<string,int> $counts
 */
$tabs = [
    'open'             => 'Open',
    'escalated'        => 'Escalated',
    'waiting_customer' => 'Waiting on customer',
    'resolved'         => 'Resolved',
    'all'              => 'Everything',
];

$rows = array_map(static fn (array $t): array => [
    'ref'      => $t['ref'],
    'subject'  => $t['subject'],
    'customer' => $t['customer_name'],
    'order'    => $t['order_ref'] ?? '',
    'priority' => ucfirst($t['priority']),
    'status'   => $t['status'],
    'assignee' => $t['assignee'] ?? 'Unassigned',
    'updated'  => $t['updated_at_utc'],
    'url'      => route('support.tickets.show', ['ref' => $t['ref']]),
], $tickets);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Ticket queue',
        'subtitle' => 'Everything customers have raised, and who has it.',
    ]) ?>

    <?= component('tabs', [
        'label' => 'Ticket status',
        'tabs'  => array_map(
            static fn (string $key, string $label): array => [
                'label'   => $label,
                'url'     => route('support.tickets') . '?status=' . $key,
                'current' => $status === $key,
                'count'   => $counts[$key] ?? 0,
            ],
            array_keys($tabs),
            array_values($tabs)
        ),
    ]) ?>

    <?= component('data-table', [
        'caption' => 'Tickets: ' . ($tabs[$status] ?? $status),
        'columns' => [
            ['key' => 'ref',      'label' => 'Reference', 'type' => 'code', 'href' => 'url'],
            ['key' => 'subject',  'label' => 'Subject',   'sub' => 'order'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'priority', 'label' => 'Priority'],
            ['key' => 'status',   'label' => 'Status',    'type' => 'badge'],
            ['key' => 'assignee', 'label' => 'Assigned'],
            ['key' => 'updated',  'label' => 'Updated',   'type' => 'relative'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'check-circle', 'title' => 'Nothing at this status',
            'text' => 'Try another tab, or check everything.',
            'actionUrl' => route('support.tickets') . '?status=all',
            'actionLabel' => 'Show everything',
        ],
    ]) ?>
</div>
