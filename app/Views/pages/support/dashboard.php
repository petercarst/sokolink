<?php
declare(strict_types=1);
/**
 * Support desk overview.
 *
 * @var list<array<string,mixed>> $open
 * @var list<array<string,mixed>> $escalated
 * @var list<array<string,mixed>> $unassigned
 * @var list<array<string,mixed>> $notifications
 * @var array<string,int>         $counts
 */
$highPriority = array_filter($open, static fn (array $t): bool => $t['priority'] === 'high');
$failedNotifications = array_filter($notifications, static fn (array $n): bool => $n['status'] === 'failed');
?>
<div class="sl-container-wide sl-container">

    <?= partial('dash-page-header', [
        'title'    => 'Support overview',
        'subtitle' => 'What is waiting, what is urgent, and whether messages are getting through.',
        'actions'  => [['label' => 'Ticket queue', 'url' => route('support.tickets'), 'style' => 'sl-btn-primary', 'icon' => 'mail']],
    ]) ?>

    <?= component('stat-row', ['stats' => [
        ['label' => 'Open tickets', 'value' => (string) ($counts['open'] ?? 0), 'hint' => 'Not yet picked up or in progress', 'href' => route('support.tickets')],
        ['label' => 'Unassigned', 'value' => (string) ($counts['unassigned'] ?? 0), 'hint' => 'Nobody has picked these up', 'href' => route('support.tickets')],
        ['label' => 'High priority', 'value' => (string) count($highPriority), 'hint' => 'Order or delivery problems'],
        ['label' => 'Escalated', 'value' => (string) ($counts['escalated'] ?? 0), 'hint' => 'With an administrator', 'href' => route('support.escalations')],
    ]]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="tw-mb-10">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h2 class="t-heading-xl tw-mb-0">Needs attention</h2>
                    <a class="t-micro sl-link-more" href="<?= e(route('support.tickets')) ?>">Full queue</a>
                </div>

                <?php if ($open === []) : ?>
                    <?= component('empty-state', [
                        'icon' => 'check-circle', 'title' => 'The queue is clear',
                        'text' => 'Nothing is waiting. New requests appear here as they arrive.',
                    ]) ?>
                <?php else : ?>
                    <div class="tw-flex tw-flex-col tw-gap-4">
                        <?php foreach ($open as $ticket) : ?>
                            <article class="sl-card <?= $ticket['priority'] === 'high' ? 'sl-card-raised' : '' ?>">
                                <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-3">
                                    <div style="min-width:0">
                                        <p class="t-micro t-muted tw-mb-1">
                                            <span class="t-code"><?= e($ticket['ref']) ?></span>
                                            &middot; <?= e($ticket['customer_name']) ?>
                                            <?php if ($ticket['order_ref'] !== null) : ?>
                                                &middot; order <span class="t-code"><?= e($ticket['order_ref']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <h3 class="t-heading-md tw-mb-0"><?= e($ticket['subject']) ?></h3>
                                    </div>
                                    <div class="tw-flex tw-gap-2 tw-flex-wrap">
                                        <?php if ($ticket['priority'] === 'high') : ?>
                                            <span class="sl-badge sl-badge-danger">High priority</span>
                                        <?php endif; ?>
                                        <?= component('badge', ['status' => $ticket['status']]) ?>
                                    </div>
                                </div>

                                <p class="t-caption t-muted tw-mb-4">
                                    <?= e(str_limit($ticket['preview'], 130)) ?>
                                </p>

                                <div class="tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                                    <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                                       href="<?= e(route('support.tickets.show', ['ref' => $ticket['ref']])) ?>">Open</a>

                                    <?php if ($ticket['assignee'] === null) : ?>
                                        <form method="post" action="<?= e(route('support.tickets.assign')) ?>" class="tw-m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">
                                            <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">Assign to me</button>
                                        </form>
                                    <?php else : ?>
                                        <span class="t-micro t-muted">Assigned to <?= e($ticket['assignee']) ?></span>
                                    <?php endif; ?>

                                    <span class="t-micro t-muted tw-ml-auto">
                                        Opened <?= time_tag($ticket['created_at_utc'], true) ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <?php if ($failedNotifications !== []) : ?>
                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-2">Messages not getting through</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        These customers may not know what is happening with their order, which is
                        usually the cause of the next ticket.
                    </p>
                    <?php foreach ($failedNotifications as $note) : ?>
                        <div class="sl-alert sl-alert-danger tw-mb-2">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 16]) ?></span>
                            <span class="t-caption">
                                <strong><?= e($note['customer']) ?></strong><br>
                                <?= e($note['template']) ?> &middot; <?= e((string) $note['attempts']) ?> attempts
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-3"
                       href="<?= e(route('support.notifications')) ?>">Notification monitor</a>
                </div>
            <?php endif; ?>

            <div class="sl-card sl-card-band tw-mb-6">
                <h2 class="t-heading-md tw-mb-3">What you can see</h2>
                <p class="t-caption tw-mb-0">
                    You can open the record of a customer who has a ticket with you. You cannot see
                    password hashes, reset tokens, collection codes in plain text, or any payment
                    credential. Every time you open a customer record it is written to the audit log
                    with the ticket as the reason.
                </p>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-4">Quick lookups</h2>
                <a class="sl-btn sl-btn-outline-light sl-btn-block tw-mb-2" href="<?= e(route('support.orders')) ?>">
                    <?= component('icon', ['name' => 'package', 'size' => 18]) ?> Find an order
                </a>
                <a class="sl-btn sl-btn-outline-light sl-btn-block tw-mb-2" href="<?= e(route('support.notifications')) ?>">
                    <?= component('icon', ['name' => 'bell', 'size' => 18]) ?> Notification monitor
                </a>
                <a class="sl-btn sl-btn-outline-light sl-btn-block" href="<?= e(route('support.escalations')) ?>">
                    <?= component('icon', ['name' => 'alert', 'size' => 18]) ?> Escalated tickets
                </a>
            </div>
        </aside>
    </div>
</div>
