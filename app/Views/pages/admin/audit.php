<?php
declare(strict_types=1);
/**
 * Audit log.
 *
 * APPEND-ONLY. There is no edit and no delete path for these rows anywhere in
 * the application, for anyone, including administrators (FR-ADM-09). An audit
 * log an admin can edit is not an audit log.
 *
 * @var list<array<string,mixed>> $rows
 * @var string $actor
 */
$actorRoles = [
    ''               => 'All actors',
    'admin'          => 'Administrators',
    'support'        => 'Support staff',
    'seller'         => 'Sellers',
    'delivery_agent' => 'Delivery agents',
    'customer'       => 'Customers',
    'system'         => 'The system itself',
];
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Audit log',
        'subtitle' => 'Every privileged action and every access to customer data. Read-only, permanently.',
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span>
            <strong>This log is append-only.</strong>
            There is no button here to edit or delete an entry, and no code path anywhere in the
            application that does either &mdash; including for administrators. A log that privileged
            users can rewrite would tell you nothing.
        </span>
    </div>

    <form class="sl-toolbar" method="get" action="<?= e(route('admin.audit')) ?>">
        <div class="sl-field">
            <label class="sl-label" for="actor-filter">Actor role</label>
            <select class="sl-select" id="actor-filter" name="actor" data-auto-submit>
                <?php foreach ($actorRoles as $value => $label) : ?>
                    <option value="<?= e((string) $value) ?>"<?= $actor === (string) $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="sl-btn sl-btn-outline-light" type="submit">Filter</button></noscript>
        <?php if ($actor !== '') : ?>
            <a class="sl-btn sl-btn-ghost" href="<?= e(route('admin.audit')) ?>">Clear</a>
        <?php endif; ?>
        <p class="t-caption t-muted tw-mb-0 tw-ml-auto" role="status">
            <?= e((string) count($rows)) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?>
        </p>
    </form>

    <?= component('data-table', [
        'caption' => 'Audit log entries',
        'columns' => [
            ['key' => 'at_utc',     'label' => 'When',   'type' => 'datetime'],
            ['key' => 'actor',      'label' => 'Actor',  'sub' => 'actor_role'],
            ['key' => 'action',     'label' => 'Action', 'type' => 'code'],
            ['key' => 'entity',     'label' => 'Entity', 'type' => 'code'],
            ['key' => 'detail',     'label' => 'Detail', 'type' => 'muted'],
            ['key' => 'ip',         'label' => 'From',   'type' => 'muted'],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'lock', 'title' => 'No entries for that actor',
            'text' => 'Try another role, or clear the filter.',
            'actionUrl' => route('admin.audit'), 'actionLabel' => 'Show everything',
        ],
    ]) ?>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">What gets recorded</h2>
        <ul class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
            <li class="t-caption">Every account suspension or reactivation, with its reason.</li>
            <li class="t-caption">Every seller approval or rejection, with its reason.</li>
            <li class="t-caption">Every administrator override of an order status.</li>
            <li class="t-caption">Every regeneration of a collection or delivery code.</li>
            <li class="t-caption">Every refund approval.</li>
            <li class="t-caption">Every change to a platform setting, with the before and after values.</li>
            <li class="t-caption">
                Every time support opens a customer's record, with the ticket that justified it.
            </li>
        </ul>
    </div>
</div>
