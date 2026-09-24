<?php
declare(strict_types=1);
/**
 * Tickets escalated to an administrator.
 *
 * Support can escalate but cannot resolve a dispute, approve a refund, or act
 * against a seller account. Those need an admin, and the split is deliberate
 * (USER_ROLES_AND_PERMISSIONS.md section 3.10).
 *
 * @var list<array<string,mixed>> $tickets
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Escalated tickets',
        'subtitle' => 'Handed to an administrator because they need a decision you cannot make.',
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            You can escalate anything, and you keep the conversation with the customer. What you
            cannot do is approve a refund, resolve a dispute against a seller, or suspend an account
            &mdash; those need an administrator, which is why they exist as a separate role.
        </span>
    </div>

    <?php if ($tickets === []) : ?>
        <?= component('empty-state', [
            'icon' => 'check-circle', 'title' => 'Nothing escalated',
            'text' => 'Escalate a ticket when it needs a refund approval, a decision about a seller, or an account action.',
            'actionUrl' => route('support.tickets'), 'actionLabel' => 'Back to the queue',
        ]) ?>
    <?php else : ?>
        <div class="tw-flex tw-flex-col tw-gap-4">
            <?php foreach ($tickets as $ticket) : ?>
                <?php
                // The reason lives on the ticket, not buried in the thread.
                // Scanning messages for the last internal note was a guess that
                // happened to work on fixtures; escalation_reason is the answer.
                $reason = $ticket['escalation_reason'];
                ?>
                <article class="sl-card sl-card-raised">
                    <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                        <div style="min-width:0">
                            <p class="t-micro t-muted tw-mb-1">
                                <span class="t-code"><?= e($ticket['ref']) ?></span>
                                &middot; <?= e($ticket['customer_name']) ?>
                                <?php if ($ticket['order_ref'] !== null) : ?>
                                    &middot; order <span class="t-code"><?= e($ticket['order_ref']) ?></span>
                                <?php endif; ?>
                            </p>
                            <h2 class="t-heading-md tw-mb-0"><?= e($ticket['subject']) ?></h2>
                        </div>
                        <?= component('badge', ['status' => 'escalated']) ?>
                    </div>

                    <?php if ($reason !== null) : ?>
                        <div class="sl-alert sl-alert-warn tw-mb-4">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong>Why it was escalated</strong>
                                &mdash; <?= time_tag($ticket['updated_at_utc'], true) ?><br>
                                <span class="t-caption"><?= e($reason) ?></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                        <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                           href="<?= e(route('support.tickets.show', ['ref' => $ticket['ref']])) ?>">Open the thread</a>
                        <span class="t-micro t-muted">
                            With <?= e($ticket['assignee'] ?? 'an administrator') ?>
                            since <?= time_tag($ticket['updated_at_utc'], true) ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
