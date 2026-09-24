<?php
declare(strict_types=1);
/**
 * Notification inbox.
 *
 * Transactional and marketing messages are visually distinguished, because the
 * customer can only opt out of one of them and that difference should be
 * obvious here rather than buried in the preferences page.
 *
 * Each row is rendered from the template key and payload by the SAME code that
 * renders the email, so the inbox cannot drift out of step with what was sent.
 * Payloads that carried a collection code had it scrubbed on delivery, so a
 * code cannot reappear on this page.
 *
 * @var list<array<string,mixed>> $inbox
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Notifications',
        'subtitle' => 'Updates about your orders, and any reminders you have asked for.',
        'actions'  => [
            ['label' => 'Mark all read', 'post' => route('customer.notifications.read'), 'icon' => 'check'],
            ['label' => 'Email preferences', 'url' => route('customer.preferences'), 'style' => 'sl-btn-primary', 'icon' => 'mail'],
        ],
    ]) ?>

    <?php if ($inbox === []) : ?>
        <?= component('empty-state', [
            'icon' => 'bell', 'title' => 'Nothing here yet',
            'text' => 'Order updates will appear here as soon as something happens.',
        ]) ?>
    <?php else : ?>
        <div class="sl-card sl-card-flush">
            <ul class="tw-list-none tw-p-0 tw-m-0">
                <?php foreach ($inbox as $note) : ?>
                    <li class="tw-flex tw-gap-4 tw-p-5" style="border-bottom:1px solid var(--c-hairline-light)">
                        <span class="tw-shrink-0 tw-mt-1" style="color:var(--c-shade-60)">
                            <?= component('icon', ['name' => $note['marketing'] ? 'repeat' : 'package', 'size' => 20]) ?>
                        </span>

                        <span class="tw-flex-1" style="min-width:0">
                            <span class="tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-mb-1">
                                <span class="t-body-strong"><?= e($note['title']) ?></span>
                                <?php if (!$note['read']) : ?>
                                    <span class="sl-chip sl-chip-mint">New</span>
                                <?php endif; ?>
                                <?php if ($note['marketing']) : ?>
                                    <span class="sl-chip">Marketing &mdash; you can turn these off</span>
                                <?php endif; ?>
                            </span>
                            <span class="t-caption t-muted tw-block tw-mb-2"><?= e($note['body']) ?></span>
                            <span class="t-micro t-muted"><?= time_tag($note['at_utc'], true) ?></span>
                        </span>

                        <?php if ($note['order_ref'] !== null) : ?>
                            <a class="sl-btn sl-btn-outline-light sl-btn-sm tw-shrink-0"
                               href="<?= e(route('customer.orders.show', ['ref' => $note['order_ref']])) ?>">Open</a>
                        <?php elseif ($note['ticket_ref'] !== null) : ?>
                            <a class="sl-btn sl-btn-outline-light sl-btn-sm tw-shrink-0"
                               href="<?= e(route('customer.tickets.show', ['ref' => $note['ticket_ref']])) ?>">Open</a>
                        <?php elseif ($note['marketing']) : ?>
                            <a class="sl-btn sl-btn-aloe sl-btn-sm tw-shrink-0" href="<?= e(route('customer.reorder')) ?>">Reorder</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
