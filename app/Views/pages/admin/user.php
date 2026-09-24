<?php
declare(strict_types=1);
/**
 * User detail.
 *
 * Suspension requires a MANDATORY reason, notifies the affected user, and is
 * audited (USER_ROLES_AND_PERMISSIONS.md section 5). Suspension does not delete
 * anything: orders already placed still have to be fulfilled, and a customer's
 * receipt is a financial record, not a profile field.
 *
 * @var array<string,mixed> $user
 * @var list<array<string,mixed>> $audit
 */
$suspended = $user['status'] === 'suspended';
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Users', 'url' => route('admin.users')],
            ['label' => $user['name'], 'url' => null],
        ],
        'title'    => $user['name'],
        'subtitle' => ucwords(str_replace('_', ' ', $user['role'])) . ' - ' . $user['email'],
        'badge'    => $user['status'],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Account</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Name', 'value' => $user['name']],
                    ['label' => 'Email', 'value' => $user['email']],
                    ['label' => 'Role', 'value' => ucwords(str_replace('_', ' ', $user['role']))],
                    ['label' => 'Status', 'value' => $user['status'], 'type' => 'badge'],
                    ['label' => 'Joined', 'value' => $user['joined_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Last seen', 'value' => $user['last_seen_utc'], 'type' => 'datetime'],
                    ['label' => 'Orders', 'value' => (string) $user['orders']],
                ]]) ?>

                <div class="sl-alert sl-alert-info tw-mt-5 tw-mb-0">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
                    <span class="t-caption">
                        There is no password field here, and there never will be. Passwords are stored
                        as one-way hashes, so nobody &mdash; including you &mdash; can read one back.
                        You can trigger a reset; you cannot see or set a password.
                    </span>
                </div>
            </section>

            <section class="sl-card">
                <h2 class="t-heading-xl tw-mb-2">Audit entries for this account</h2>
                <p class="t-caption t-muted tw-mb-5">
                    Append-only. There is no path in the application to edit or delete these, for
                    anyone, including administrators.
                </p>

                <?php if ($audit === []) : ?>
                    <p class="t-caption t-muted tw-mb-0">No recorded actions against this account.</p>
                <?php else : ?>
                    <ol class="sl-timeline">
                        <?php foreach ($audit as $entry) : ?>
                            <li class="sl-timeline-item is-done">
                                <p class="t-body-strong t-code tw-mb-1"><?= e($entry['action']) ?></p>
                                <p class="t-caption t-muted tw-mb-1">
                                    <?= time_tag($entry['at_utc']) ?> &middot; <?= e($entry['actor']) ?>
                                    <span class="sl-chip tw-ml-1"><?= e($entry['actor_role']) ?></span>
                                </p>
                                <p class="t-caption tw-mb-0"><?= e($entry['detail']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-2"><?= e($suspended ? 'Reactivate this account' : 'Suspend this account') ?></h2>
                <p class="t-caption t-muted tw-mb-4">
                    <?php if ($suspended) : ?>
                        Reactivating restores access immediately. The reason is recorded and the user
                        is told.
                    <?php else : ?>
                        Takes effect on their very next request, not at their next login. Orders
                        already placed still have to be fulfilled &mdash; suspension stops the account
                        acting, it does not cancel business that already happened.
                    <?php endif; ?>
                </p>

                <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="<?= e($suspended ? 'user_reactivate' : 'user_suspend') ?>">

                    <?= component('field', [
                        'name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
                        'help' => 'Required. Recorded in the audit log and included in the message to the user.',
                    ]) ?>

                    <button class="sl-btn <?= $suspended ? 'sl-btn-aloe' : 'sl-btn-danger' ?> sl-btn-block" type="submit">
                        <?= e($suspended ? 'Reactivate account' : 'Suspend account') ?>
                    </button>
                </form>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Other actions</h2>
                <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="password_forgot">
                    <button class="sl-btn sl-btn-outline-light sl-btn-block tw-mb-2" type="submit">
                        Send a password reset link
                    </button>
                </form>
                <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feature" value="user_role">
                    <button class="sl-btn sl-btn-outline-light sl-btn-block" type="submit">
                        Change roles
                    </button>
                </form>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    Signing in as another user is not built, deliberately. It makes the audit log
                    ambiguous about who actually did something.
                </p>
            </div>
        </aside>
    </div>
</div>
