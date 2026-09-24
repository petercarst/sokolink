<?php
declare(strict_types=1);
/**
 * Agent delivery history.
 *
 * @var list<array<string,mixed>> $tasks
 */
$rows = array_map(static fn (array $t): array => [
    'task'      => $t['delivery']['task_ref'],
    'recipient' => $t['delivery']['recipient'],
    'zone'      => $t['delivery']['zone'],
    'status'    => $t['status'],
    'when'      => $t['delivery']['delivered_at_utc'] ?? $t['updated_at_utc'],
    'attempts'  => $t['delivery']['attempts'],
    'fee'       => $t['delivery_fee'],
], $tasks);

$fees = array_sum(array_map(static fn (array $t): float => (float) $t['delivery_fee'], $tasks));
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Delivery history',
        'subtitle' => 'Jobs you have completed, and how they went.',
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Completed', 'value' => (string) count($tasks), 'hint' => 'All time'],
        ['label' => 'First-attempt success', 'value' => count($tasks) > 0
            ? (string) (int) round(count(array_filter($tasks, static fn (array $t): bool => (int) $t['delivery']['attempts'] <= 1)) / count($tasks) * 100) . '%'
            : '-', 'hint' => 'Delivered without a repeat visit'],
        ['label' => 'Fees earned', 'value' => money_compact((string) $fees), 'hint' => 'On completed jobs'],
    ]]) ?>

    <?= component('data-table', [
        'caption' => 'Your completed deliveries',
        'columns' => [
            ['key' => 'task',      'label' => 'Task',      'type' => 'code'],
            ['key' => 'recipient', 'label' => 'Recipient'],
            ['key' => 'zone',      'label' => 'Zone'],
            ['key' => 'status',    'label' => 'Outcome',   'type' => 'badge'],
            ['key' => 'when',      'label' => 'Completed', 'type' => 'datetime'],
            ['key' => 'attempts',  'label' => 'Attempts',  'type' => 'number', 'align' => 'right'],
            ['key' => 'fee',       'label' => 'Fee',       'type' => 'money',  'align' => 'right'],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'clock', 'title' => 'No completed deliveries yet',
            'text' => 'Finished jobs move here with their outcome and fee.',
            'actionUrl' => route('delivery.tasks'), 'actionLabel' => 'See my deliveries',
        ],
    ]) ?>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">About these figures</h2>
        <p class="t-caption tw-mb-0">
            These are your own jobs only. Agents are not ranked against each other here, and a failed
            attempt with a recorded reason is not counted against you &mdash; the reason matters more
            than the count, which is why one is always required.
        </p>
    </div>
</div>
