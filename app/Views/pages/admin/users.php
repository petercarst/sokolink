<?php
declare(strict_types=1);
/**
 * User management.
 *
 * @var list<array<string,mixed>> $users
 * @var string $role
 */
$roles = [
    ''               => 'All roles',
    'customer'       => 'Customers',
    'seller'         => 'Sellers',
    'delivery_agent' => 'Delivery agents',
    'support'        => 'Support staff',
    'admin'          => 'Administrators',
];

$rows = array_map(static fn (array $u): array => [
    'name'   => $u['name'],
    'email'  => $u['email'],
    'role'   => ucwords(str_replace('_', ' ', $u['role'])),
    'status' => $u['status'],
    'joined' => $u['joined_at_utc'],
    'seen'   => $u['last_seen_utc'],
    'orders' => $u['orders'],
    'url'    => route('admin.users.show', ['id' => $u['id']]),
], $users);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Users',
        'subtitle' => 'Everyone with an account. Suspending or reactivating one needs a reason and is audited.',
        'actions'  => [['label' => 'Invite staff', 'feature' => 'user_invite', 'style' => 'sl-btn-primary', 'icon' => 'plus']],
    ]) ?>

    <form class="sl-toolbar" method="get" action="<?= e(route('admin.users')) ?>">
        <div class="sl-field">
            <label class="sl-label" for="role-filter">Role</label>
            <select class="sl-select" id="role-filter" name="role" data-auto-submit>
                <?php foreach ($roles as $value => $label) : ?>
                    <option value="<?= e((string) $value) ?>"<?= $role === (string) $value ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button class="sl-btn sl-btn-outline-light" type="submit">Filter</button></noscript>
        <?php if ($role !== '') : ?>
            <a class="sl-btn sl-btn-ghost" href="<?= e(route('admin.users')) ?>">Clear</a>
        <?php endif; ?>
        <p class="t-caption t-muted tw-mb-0 tw-ml-auto" role="status">
            <?= e((string) count($users)) ?> user<?= count($users) === 1 ? '' : 's' ?>
        </p>
    </form>

    <?= component('data-table', [
        'caption' => 'Platform users',
        'columns' => [
            ['key' => 'name',   'label' => 'Name',   'type' => 'avatar', 'href' => 'url', 'sub' => 'email'],
            ['key' => 'role',   'label' => 'Role'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'orders', 'label' => 'Orders', 'type' => 'number', 'align' => 'right'],
            ['key' => 'joined', 'label' => 'Joined', 'type' => 'date'],
            ['key' => 'seen',   'label' => 'Last seen', 'type' => 'relative'],
            ['type' => 'actions', 'label' => '', 'actions' => [
                ['label' => 'Open', 'href' => static fn (array $r): string => $r['url']],
            ]],
        ],
        'rows'  => $rows,
        'empty' => [
            'icon' => 'user', 'title' => 'No users match that filter',
            'text' => 'Try another role, or clear the filter.',
            'actionUrl' => route('admin.users'), 'actionLabel' => 'Show everyone',
        ],
    ]) ?>
</div>
