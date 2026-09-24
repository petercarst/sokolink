<?php
declare(strict_types=1);
/**
 * Customer view of a support thread.
 *
 * THERE IS NO INTERNAL-NOTE FILTER IN THIS TEMPLATE, and that is the point.
 * `SupportRepository::customerMessages()` has `is_internal = 0` in its WHERE
 * clause, so those rows are never fetched for this page. A filter here would
 * be one careless refactor away from leaking; a WHERE clause is not
 * (FR-SUP-02).
 *
 * @var array<string,mixed> $ticket
 */
$visible = $ticket['messages'];
$closed  = in_array($ticket['status'], ['resolved', 'closed'], true);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Support', 'url' => route('customer.tickets')],
            ['label' => $ticket['ref'], 'url' => null],
        ],
        'title'    => $ticket['subject'],
        'subtitle' => 'Reference ' . $ticket['ref'],
        'badge'    => $ticket['status'],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 320px;">
        <div>
            <section class="sl-card sl-card-flush tw-mb-6">
                <div class="tw-p-6 tw-pb-0">
                    <h2 class="t-heading-xl tw-mb-0">Conversation</h2>
                </div>

                <div class="tw-px-6">
                    <?php foreach ($visible as $message) : ?>
                        <?php $mine = $message['role'] === 'customer'; ?>
                        <div class="sl-msg <?= $mine ? 'sl-msg-mine' : '' ?>">
                            <span class="sl-avatar tw-shrink-0"><?= e(initials($message['author'])) ?></span>
                            <div class="sl-msg-body">
                                <p class="t-caption t-body-strong tw-mb-1">
                                    <?= e($mine ? 'You' : $message['author']) ?>
                                    <span class="t-micro t-muted tw-ml-2"><?= time_tag($message['at_utc'], true) ?></span>
                                </p>
                                <p class="t-body-md tw-mb-0"><?= e($message['body']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$closed) : ?>
                    <div class="tw-p-6 tw-pt-2">
                        <form method="post" action="<?= e(route('customer.tickets.reply')) ?>" data-validate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">

                            <div class="sl-field">
                                <label class="sl-label" for="reply-body">
                                    Your reply<span class="sl-required" aria-hidden="true">*</span>
                                    <span class="visually-hidden"> (required)</span>
                                </label>
                                <textarea class="sl-textarea" id="reply-body" name="body" required
                                          maxlength="2000" data-counter="reply-count" aria-describedby="reply-count"></textarea>
                                <span class="sl-help" id="reply-count">2000 characters remaining</span>
                            </div>

                            <p class="t-micro t-muted tw-mb-4">
                                Please do not include passwords, PINs or full card numbers. Support
                                staff cannot see them and will never ask.
                            </p>

                            <button class="sl-btn sl-btn-primary" type="submit">Send reply</button>
                        </form>
                    </div>
                <?php elseif ($ticket['status'] === 'resolved') : ?>
                    <div class="tw-p-6 tw-pt-2">
                        <div class="sl-alert sl-alert-success tw-mb-4">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'check', 'size' => 18]) ?></span>
                            <span>This request is resolved. If the problem comes back, reply below and it goes straight back on the queue.</span>
                        </div>
                        <form method="post" action="<?= e(route('customer.tickets.reply')) ?>" data-validate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">

                            <div class="sl-field">
                                <label class="sl-label" for="reopen-body">
                                    What is still wrong?<span class="sl-required" aria-hidden="true">*</span>
                                    <span class="visually-hidden"> (required)</span>
                                </label>
                                <textarea class="sl-textarea" id="reopen-body" name="body" required
                                          maxlength="2000"></textarea>
                            </div>

                            <button class="sl-btn sl-btn-outline-light" type="submit">Reopen this request</button>
                        </form>
                    </div>
                <?php else : ?>
                    <div class="tw-p-6 tw-pt-2">
                        <div class="sl-alert sl-alert-info tw-mb-0">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                            <span>
                                This request is closed. Open a new one and we will pick it up &mdash;
                                quote <?= e($ticket['ref']) ?> and whoever answers will have the history.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Details</h2>
                <?= component('detail-list', ['items' => array_filter([
                    ['label' => 'Reference', 'value' => $ticket['ref'], 'type' => 'code'],
                    ['label' => 'Status', 'value' => $ticket['status'], 'type' => 'badge'],
                    ['label' => 'Category', 'value' => ucfirst($ticket['category'])],
                    ['label' => 'Opened', 'value' => $ticket['created_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Last update', 'value' => $ticket['updated_at_utc'], 'type' => 'datetime'],
                    $ticket['order_ref'] !== null
                        ? ['label' => 'Order', 'value' => $ticket['order_ref'], 'type' => 'code']
                        : null,
                ])]) ?>
            </div>

            <?php if ($ticket['order_ref'] !== null) : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-3">Related order</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Support can see this order's full history because it is attached to this
                        request. Each time they open it, that is written to the audit log.
                    </p>
                    <a class="sl-btn sl-btn-outline-light sl-btn-block"
                       href="<?= e(route('customer.orders.show', ['ref' => $ticket['order_ref'] . '-1'])) ?>">
                        View the order
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
