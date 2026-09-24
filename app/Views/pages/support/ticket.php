<?php
declare(strict_types=1);
/**
 * Support view of a ticket.
 *
 * INTERNAL NOTES ARE VISIBLE HERE and visually distinct. The customer's view of
 * this same thread never fetches them - the filter is in the query, not in the
 * template, so a template refactor cannot leak them (FR-SUP-02).
 *
 * @var array<string,mixed>      $ticket
 * @var array<string,mixed>|null $order
 */
$closed = in_array($ticket['status'], ['resolved', 'closed'], true);
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Ticket queue', 'url' => route('support.tickets')],
            ['label' => $ticket['ref'], 'url' => null],
        ],
        'title'    => $ticket['subject'],
        'subtitle' => $ticket['customer_name'] . ' - ' . $ticket['ref'],
        'badge'    => $ticket['status'],
        'actions'  => $closed ? [] : [
            ['label' => 'Resolve', 'feature' => 'ticket_resolve', 'style' => 'sl-btn-aloe', 'icon' => 'check'],
            ['label' => 'Escalate to admin', 'feature' => 'ticket_escalate', 'style' => 'sl-btn-danger', 'icon' => 'alert'],
        ],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card sl-card-flush tw-mb-6">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-p-6 tw-pb-2">
                    <h2 class="t-heading-xl tw-mb-0">Conversation</h2>
                    <span class="sl-chip"><?= e((string) count($ticket['messages'])) ?> messages</span>
                </div>

                <div class="tw-px-6">
                    <?php foreach ($ticket['messages'] as $message) : ?>
                        <?php
                        $internal = !empty($message['internal']);
                        $mine     = $message['role'] === 'support';
                        ?>
                        <div class="sl-msg <?= $internal ? 'sl-msg-internal' : ($mine ? 'sl-msg-mine' : '') ?>">
                            <span class="sl-avatar tw-shrink-0"><?= e(initials($message['author'])) ?></span>
                            <div class="sl-msg-body">
                                <p class="t-caption t-body-strong tw-mb-1">
                                    <?= e($message['author']) ?>
                                    <span class="sl-chip tw-ml-2"><?= e($message['role']) ?></span>
                                    <?php if ($internal) : ?>
                                        <span class="sl-badge sl-badge-warn tw-ml-1">Internal - not visible to the customer</span>
                                    <?php endif; ?>
                                    <span class="t-micro t-muted tw-ml-2"><?= time_tag($message['at_utc'], true) ?></span>
                                </p>
                                <p class="t-body-md tw-mb-0"><?= e($message['body']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$closed) : ?>
                    <div class="tw-p-6 tw-pt-2">
                        <form method="post" action="<?= e(route('support.tickets.reply')) ?>" data-validate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">

                            <div class="sl-field">
                                <label class="sl-label" for="support-reply">
                                    Message<span class="sl-required" aria-hidden="true">*</span>
                                    <span class="visually-hidden"> (required)</span>
                                </label>
                                <textarea class="sl-textarea" id="support-reply" name="body" required
                                          maxlength="3000" data-counter="support-count"
                                          aria-describedby="support-count"></textarea>
                                <span class="sl-help" id="support-count">3000 characters remaining</span>
                            </div>

                            <label class="sl-check tw-mb-4">
                                <input type="checkbox" name="internal" value="1">
                                <span class="sl-check-label">
                                    Internal note &mdash; the customer never sees this
                                    <span class="t-micro t-muted tw-block">
                                        Internal rows are excluded from the customer's query entirely, not
                                        hidden in their template.
                                    </span>
                                </span>
                            </label>

                            <div class="tw-flex tw-gap-2 tw-flex-wrap">
                                <button class="sl-btn sl-btn-primary" type="submit">Send</button>
                            </div>
                            <p class="sl-help tw-mt-2 tw-mb-0">
                                A reply from support moves the ticket to "waiting on customer" by
                                itself - an internal note does not, because the customer never sees it
                                and so is not waiting on anything.
                            </p>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Ticket</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Reference', 'value' => $ticket['ref'], 'type' => 'code'],
                    ['label' => 'Status', 'value' => $ticket['status'], 'type' => 'badge'],
                    ['label' => 'Priority', 'value' => ucfirst($ticket['priority'])],
                    ['label' => 'Category', 'value' => ucfirst($ticket['category'])],
                    ['label' => 'Assigned to', 'value' => $ticket['assignee'] ?? 'Nobody yet'],
                    ['label' => 'Opened', 'value' => $ticket['created_at_utc'], 'type' => 'datetime'],
                    ['label' => 'Last update', 'value' => $ticket['updated_at_utc'], 'type' => 'datetime'],
                ]]) ?>

                <?php if (!$closed) : ?>
                    <form method="post" action="<?= e(route('support.tickets.assign')) ?>" class="tw-mt-4">
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">
                        <button class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block" type="submit">
                            <?= e($ticket['assignee'] === null ? 'Assign to me' : 'Reassign to me') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$closed) : ?>
                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-2">Close it out</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        The summary is sent to the customer and is what the next person reads if
                        they come back. "Resolved" on its own tells nobody anything.
                    </p>

                    <form method="post" action="<?= e(route('support.tickets.resolve')) ?>" data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">

                        <?= component('field', [
                            'name' => 'summary', 'label' => 'What was the resolution?', 'type' => 'textarea',
                            'required' => true,
                        ]) ?>

                        <button class="sl-btn sl-btn-aloe sl-btn-block" type="submit">Resolve</button>
                    </form>
                </div>

                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-2">Escalate</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        For anything needing an administrator: a refund outside policy, a seller
                        dispute, an account decision. It goes to whoever is on that day, not to a
                        named person who might be on leave.
                    </p>

                    <details>
                        <summary class="t-body-strong" style="cursor:pointer">Escalate this ticket</summary>

                        <form method="post" action="<?= e(route('support.tickets.escalate')) ?>"
                              class="tw-mt-4" data-validate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($ticket['ref']) ?>">

                            <?= component('field', [
                                'name' => 'reason', 'label' => 'Why does this need an administrator?',
                                'type' => 'textarea', 'required' => true,
                                'help' => 'Recorded on the ticket and shown on the escalations queue.',
                            ]) ?>

                            <button class="sl-btn sl-btn-danger sl-btn-block" type="submit">Escalate</button>
                        </form>
                    </details>
                </div>
            <?php endif; ?>

            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Customer</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Name', 'value' => $ticket['customer_name']],
                    ['label' => 'Email', 'value' => $ticket['customer_email']],
                ]]) ?>
                <div class="sl-alert sl-alert-warn tw-mt-4 tw-mb-0">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 16]) ?></span>
                    <span class="t-micro">
                        Opening this customer's record is written to the audit log with
                        <?= e($ticket['ref']) ?> as the justification.
                    </span>
                </div>
            </div>

            <?php if ($order !== null) : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-4">Linked order</h2>
                    <?= component('detail-list', ['items' => [
                        ['label' => 'Order', 'value' => $order['parent_ref'], 'type' => 'code'],
                        ['label' => 'Customer', 'value' => $order['customer_name']],
                        ['label' => 'Payment', 'value' => $order['payment_status'], 'type' => 'badge'],
                        ['label' => 'Method', 'value' => $order['payment_method'] === 'cash' ? 'Cash on fulfilment' : 'Sandbox gateway'],
                        ['label' => 'Total', 'value' => $order['total'], 'type' => 'money'],
                        ['label' => 'Placed', 'value' => $order['placed_at_utc'], 'type' => 'datetime'],
                    ]]) ?>

                    <?php if (count($order['parts']) > 1) : ?>
                        <p class="t-caption t-muted tw-mt-4 tw-mb-2">
                            This order was split between <?= e((string) count($order['parts'])) ?> sellers.
                            They move independently, so check the part the customer is asking about.
                        </p>
                    <?php else : ?>
                        <p class="t-caption t-muted tw-mt-4 tw-mb-2">The order part:</p>
                    <?php endif; ?>

                    <?php foreach ($order['parts'] as $part) : ?>
                        <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mb-2 tw-justify-between"
                           href="<?= e($part['url']) ?>">
                            <span>
                                <span class="t-code"><?= e($part['ref']) ?></span>
                                <span class="t-micro t-muted tw-block"><?= e($part['seller']) ?></span>
                            </span>
                            <?= component('badge', ['status' => $part['status']]) ?>
                        </a>
                    <?php endforeach; ?>

                    <p class="t-micro t-muted tw-mt-2 tw-mb-0">
                        These links carry <?= e($ticket['ref']) ?> as the justification, so the audit
                        entry says which ticket you opened the order for.
                    </p>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
