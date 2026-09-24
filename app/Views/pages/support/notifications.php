<?php
declare(strict_types=1);
/**
 * Notification delivery monitor.
 *
 * Support sees STATUS, never message content or secrets - a ready-to-collect
 * email contains a collection code, so the body is not exposed here
 * (USER_ROLES_AND_PERMISSIONS.md section 4.3).
 *
 * The skip_reason column is the useful part: it turns "why didn't this customer
 * get a reminder?" from a mystery into a fact.
 *
 * @var list<array<string,mixed>> $log
 */
$skipLabels = [
    'no_consent'         => 'Never opted in to marketing',
    'consent_withdrawn'  => 'Unsubscribed from marketing',
    'already_repurchased'=> 'Already bought it again',
    'cooldown'           => 'Reminded too recently',
    'frequency_cap'      => 'Over the monthly message cap',
    'unavailable'        => 'Product no longer purchasable',
    'insufficient_data'  => 'Not enough history to estimate timing',
    'skipped_no_provider'=> 'Channel has no provider connected',
    'quiet_hours'        => 'Held until the next allowed window',
];

$rows = array_map(static fn (array $n): array => [
    'customer' => $n['customer'],
    'template' => $n['template'],
    'channel'  => ucfirst($n['channel']),
    'category' => $n['category'] === 'marketing' ? 'Marketing' : 'Service',
    'status'   => $n['status'],
    'attempts' => $n['attempts'],
    'queued'   => $n['queued_at_utc'],
    'why'      => $n['skip_reason'] !== null
        ? ($skipLabels[$n['skip_reason']] ?? $n['skip_reason'])
        : ($n['provider_response'] ?? ''),
], $log);

$failed  = count(array_filter($log, static fn (array $n): bool => $n['status'] === 'failed'));
$skipped = count(array_filter($log, static fn (array $n): bool => $n['status'] === 'skipped'));
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Notification monitor',
        'subtitle' => 'Whether messages reached customers, and exactly why any did not.',
    ]) ?>

    <?= component('stat-row', ['cols' => '3', 'stats' => [
        ['label' => 'Delivered', 'value' => (string) count(array_filter($log, static fn (array $n): bool => $n['status'] === 'delivered')), 'hint' => 'Accepted by the provider'],
        ['label' => 'Failed', 'value' => (string) $failed, 'hint' => 'Retried and still not delivered'],
        ['label' => 'Deliberately skipped', 'value' => (string) $skipped, 'hint' => 'Consent, cooldown or no provider'],
    ]]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            <strong>Skipped is not the same as failed.</strong>
            A skip is the system correctly declining to send: no consent, too soon after the last
            message, the customer already bought the thing again, or the channel has no provider
            connected. Only <em>failed</em> means something went wrong.
        </span>
    </div>

    <?= component('data-table', [
        'caption' => 'Notification delivery log',
        'columns' => [
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'template', 'label' => 'Message', 'type' => 'code'],
            ['key' => 'channel',  'label' => 'Channel'],
            ['key' => 'category', 'label' => 'Type'],
            ['key' => 'status',   'label' => 'Result', 'type' => 'badge'],
            ['key' => 'attempts', 'label' => 'Tries',  'type' => 'number', 'align' => 'right'],
            ['key' => 'queued',   'label' => 'Queued', 'type' => 'relative'],
            ['key' => 'why',      'label' => 'Detail', 'type' => 'muted'],
        ],
        'rows'  => $rows,
        'empty' => ['icon' => 'bell', 'title' => 'No messages logged', 'text' => 'Delivery attempts appear here as they happen.'],
    ]) ?>

    <div class="tw-mt-6">
        <?= component('devnote', [
            'text' => 'Message bodies are deliberately not shown: a ready-to-collect email contains a '
                    . 'collection code. Support sees delivery status, not content. Phase 3.9 builds the queue, '
                    . 'the retry backoff and this log for real.',
        ]) ?>
    </div>
</div>
