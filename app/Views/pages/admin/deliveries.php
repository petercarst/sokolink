<?php
declare(strict_types=1);
/**
 * Delivery monitor and agent assignment.
 *
 * Assignment is an admin action in v1 (OQ-03), with an open-pool toggle per
 * zone available as a setting. Assigning is audited, because it is the moment a
 * customer's address becomes visible to a particular agent.
 *
 * @var list<array<string,mixed>> $tasks
 * @var list<array<string,mixed>> $agents
 */
$unassigned = array_filter($tasks, static fn (array $t): bool => empty($t['delivery']['agent_id']));
$failed     = array_filter($tasks, static fn (array $t): bool => $t['status'] === 'delivery_failed');

$rows = array_map(static fn (array $t): array => [
    'task'      => $t['delivery']['task_ref'] ?? 'Not created',
    'order'     => $t['ref'],
    'recipient' => $t['delivery']['recipient'],
    'zone'      => $t['delivery']['zone'],
    'agent'     => $t['delivery']['agent_name'] ?? 'Unassigned',
    'status'    => $t['status'],
    'attempts'  => $t['delivery']['attempts'],
    'fee'       => $t['delivery_fee'],
    'url'       => route('admin.orders.show', ['ref' => $t['ref']]),
], $tasks);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Delivery monitor',
        'subtitle' => 'Every delivery task, who has it, and which ones are in trouble.',
        'actions'  => [['label' => 'Delivery zones', 'url' => route('admin.zones'), 'icon' => 'map-pin']],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Tasks in view', 'value' => (string) count($tasks), 'hint' => 'Delivery sub-orders'],
        ['label' => 'Unassigned', 'value' => (string) count($unassigned), 'hint' => 'Nobody is carrying these'],
        ['label' => 'Failed attempts', 'value' => (string) count($failed), 'hint' => 'Need a retry or a return'],
        ['label' => 'Active agents', 'value' => (string) count(array_filter($agents, static fn (array $a): bool => $a['status'] === 'active')), 'hint' => 'Available to assign'],
    ]]) ?>

    <?php if ($failed !== []) : ?>
        <section class="tw-mb-8">
            <h2 class="t-heading-xl tw-mb-4">Needs a decision</h2>
            <?php foreach ($failed as $task) : ?>
                <article class="sl-card sl-card-raised tw-mb-4">
                    <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                        <div>
                            <p class="t-micro t-muted tw-mb-1">
                                <span class="t-code"><?= e($task['delivery']['task_ref']) ?></span>
                                &middot; <?= e($task['delivery']['zone']) ?>
                                &middot; attempt <?= e((string) $task['delivery']['attempts']) ?>
                            </p>
                            <h3 class="t-heading-md tw-mb-0"><?= e($task['delivery']['recipient']) ?></h3>
                        </div>
                        <?= component('badge', ['status' => 'delivery_failed']) ?>
                    </div>

                    <?php if (!empty($task['delivery']['failures'])) : ?>
                        <?php $last = end($task['delivery']['failures']); ?>
                        <div class="sl-alert sl-alert-warn tw-mb-4">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong><?= e(ucwords(str_replace('_', ' ', $last['reason_code']))) ?></strong>
                                &middot; <?= e($task['delivery']['agent_name']) ?>
                                &middot; <?= time_tag($last['at_utc'], true) ?><br>
                                <span class="t-caption"><?= e($last['note']) ?></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="tw-flex tw-gap-2 tw-flex-wrap">
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="delivery_requeue">
                            <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">Requeue for another attempt</button>
                        </form>
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="delivery_return">
                            <button class="sl-btn sl-btn-danger sl-btn-sm" type="submit">Return to seller and refund</button>
                        </form>
                        <a class="sl-btn sl-btn-ghost sl-btn-sm"
                           href="<?= e(route('admin.orders.show', ['ref' => $task['ref']])) ?>">Open the order</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <h2 class="t-heading-xl tw-mb-4">All delivery tasks</h2>

    <?= component('data-table', [
        'caption' => 'Delivery tasks across the marketplace',
        'columns' => [
            ['key' => 'task',      'label' => 'Task',      'type' => 'code', 'href' => 'url', 'sub' => 'order'],
            ['key' => 'recipient', 'label' => 'Recipient'],
            ['key' => 'zone',      'label' => 'Zone'],
            ['key' => 'agent',     'label' => 'Agent'],
            ['key' => 'status',    'label' => 'Status',    'type' => 'badge'],
            ['key' => 'attempts',  'label' => 'Attempts',  'type' => 'number', 'align' => 'right'],
            ['key' => 'fee',       'label' => 'Fee',       'type' => 'money',  'align' => 'right'],
            ['type' => 'actions',  'label' => '', 'actions' => [
                ['label' => 'Assign', 'feature' => 'delivery_assign'],
            ]],
        ],
        'rows'  => $rows,
        'empty' => ['icon' => 'truck', 'title' => 'No delivery tasks', 'text' => 'Tasks appear when a seller packs a delivery order.'],
    ]) ?>

    <section class="tw-mt-8">
        <h2 class="t-heading-xl tw-mb-4">Agents</h2>
        <?= component('data-table', [
            'caption' => 'Delivery agents',
            'columns' => [
                ['key' => 'name',   'label' => 'Agent',  'type' => 'avatar', 'href' => 'url', 'sub' => 'email'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'seen',   'label' => 'Last seen', 'type' => 'relative'],
            ],
            'rows' => array_map(static fn (array $a): array => [
                'name'   => $a['name'],
                'email'  => $a['email'],
                'status' => $a['status'],
                'seen'   => $a['last_seen_utc'],
                'url'    => route('admin.users.show', ['id' => $a['id']]),
            ], $agents),
        ]) ?>
    </section>
</div>
