<?php
declare(strict_types=1);
/**
 * A single delivery.
 *
 * THE RULE THIS SCREEN ENFORCES (FR-DEL-06): an agent cannot mark a delivery
 * complete by pressing a button. They must enter the code the recipient gives
 * them, which is verified server-side against a stored hash. Otherwise "proof
 * of delivery" would just mean "the agent said so".
 *
 * Note what is NOT shown: what is in the parcel, and what the customer paid -
 * unless this is cash on delivery, in which case the amount to collect is shown
 * because the agent has to handle it.
 *
 * @var array<string,mixed>       $order
 * @var list<array<string,mixed>> $events  the append-only task log
 */
$d   = $order['delivery'];
$cod = $d['cod_amount'] !== null;
$ref = ['ref' => $d['task_ref']];

// Driven by the TASK status, not the order's. They are close but not the same,
// and it is the task that says whether this agent has the parcel in their hand.
$next = match ($d['status']) {
    'assigned'  => ['label' => 'I have collected the parcel',
                    'post' => route('delivery.tasks.pickedup'), 'fields' => $ref, 'icon' => 'package'],
    'picked_up' => ['label' => 'Start the delivery run',
                    'post' => route('delivery.tasks.out'), 'fields' => $ref, 'icon' => 'truck'],
    'failed'    => $d['attempts_left'] > 0
                    ? ['label' => 'Try again', 'post' => route('delivery.tasks.retry'),
                       'fields' => $ref, 'icon' => 'repeat']
                    : null,
    default     => null,
};

$canDeliver = $d['status'] === 'out_for_delivery';
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'My deliveries', 'url' => route('delivery.tasks')],
            ['label' => $d['task_ref'], 'url' => null],
        ],
        'title'    => $d['recipient'],
        'subtitle' => $d['zone'] . ' - task ' . $d['task_ref'],
        'badge'    => $order['status'],
        'actions'  => $next === null ? [] : [$next + ['style' => 'sl-btn-aloe']],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card sl-card-raised tw-mb-6">
                <h2 class="t-heading-xl tw-mb-4">Where it is going</h2>

                <address class="t-body-lg tw-not-italic tw-mb-4"><?= e($d['address']) ?></address>

                <?php if (!empty($d['landmark'])) : ?>
                    <p class="t-caption t-muted tw-mb-3">
                        <?= component('icon', ['name' => 'map-pin', 'size' => 15]) ?> <?= e($d['landmark']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($d['instructions'])) : ?>
                    <div class="sl-alert sl-alert-info tw-mb-4">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                        <span><strong>From the customer:</strong> <?= e($d['instructions']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="tw-flex tw-gap-2 tw-flex-wrap">
                    <a class="sl-btn sl-btn-outline-light" href="tel:+255000000000">
                        <?= component('icon', ['name' => 'phone', 'size' => 18]) ?>
                        Call <?= e($d['phone_masked']) ?>
                    </a>
                </div>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    The number is masked on screen. Calling connects you without revealing it.
                </p>
            </section>

            <?php if ($cod) : ?>
                <section class="sl-card sl-card-featured tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Cash to collect</h2>
                    <p class="t-display-md tw-mb-3"><?= e(money((string) $d['cod_amount'])) ?></p>
                    <p class="t-caption tw-mb-0">
                        Collect this before handing the parcel over. Confirming the delivery records
                        that the cash was received, which is what marks the order paid.
                    </p>
                </section>
            <?php endif; ?>

            <!-- Completion: requires the recipient's code -->
            <?php if (in_array($order['status'], ['out_for_delivery', 'picked_up'], true)) : ?>
                <section class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Confirm the delivery</h2>
                    <p class="t-caption t-muted tw-mb-5">
                        Ask the recipient for their four-digit delivery code. You cannot complete a
                        delivery without it &mdash; that is what makes the record proof rather than a
                        claim.
                    </p>

                    <form method="post" action="<?= e(route('delivery.tasks.deliver')) ?>" data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($d['task_ref']) ?>">

                        <div class="sl-field">
                            <label class="sl-label" for="delivery-code">
                                Delivery code<span class="sl-required" aria-hidden="true">*</span>
                                <span class="visually-hidden"> (required)</span>
                            </label>
                            <input class="sl-input t-code" id="delivery-code" name="code" required
                                   inputmode="numeric" maxlength="4" autocomplete="off" placeholder="0000"
                                   style="font-size:32px;letter-spacing:10px;text-align:center;height:68px">
                        </div>

                        <?php if ($cod) : ?>
                            <label class="sl-check tw-mb-4">
                                <input type="checkbox" name="cash_received" value="1" required>
                                <span class="sl-check-label">
                                    I have collected <?= e(money($order['total'])) ?> in cash
                                    <span class="sl-required" aria-hidden="true">*</span>
                                </span>
                            </label>
                        <?php endif; ?>

                        <?= component('field', [
                            'name' => 'delivery_note', 'label' => 'Note (optional)',
                            'help' => 'Anything worth recording, for example who received it.',
                        ]) ?>

                        <button class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block" type="submit">
                            <?= component('icon', ['name' => 'check', 'size' => 20]) ?>
                            Confirm delivered
                        </button>
                    </form>

                    <hr class="sl-divider">

                    <a class="sl-btn sl-btn-danger sl-btn-block"
                       href="<?= e(route('delivery.tasks.fail', ['ref' => $order['ref']])) ?>">
                        Could not deliver - report why
                    </a>
                </section>
            <?php elseif ($next !== null) : ?>
                <section class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-4">Next step</h2>
                    <form method="post" action="<?= e($next['post']) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="ref" value="<?= e($d['task_ref']) ?>">
                        <button class="sl-btn sl-btn-aloe sl-btn-lg sl-btn-block" type="submit">
                            <?= component('icon', ['name' => $next['icon'], 'size' => 20]) ?>
                            <?= e($next['label']) ?>
                        </button>
                    </form>
                </section>
            <?php endif; ?>

            <?php
            $failures = array_values(array_filter(
                $events,
                static fn (array $e): bool => (string) $e['event_type'] === 'attempt_failed'
            ));
            ?>
            <?php if ($failures !== []) : ?>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-4">Previous attempts</h2>
                    <?php foreach ($failures as $failure) : ?>
                        <div class="sl-alert sl-alert-warn tw-mb-2">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong><?= e(ucwords(str_replace('_', ' ', $failure['reason_code']))) ?></strong>
                                &middot; <?= time_tag((string) $failure['created_at'], true) ?><br>
                                <span class="t-caption"><?= e((string) ($failure['note'] ?? '')) ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">Collect from</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Store', 'value' => $order['store_name']],
                    ['label' => 'Seller', 'value' => $order['seller_name']],
                    ['label' => 'Task', 'value' => $d['task_ref'], 'type' => 'code'],
                    ['label' => 'Zone', 'value' => $d['zone']],
                    ['label' => 'Attempts so far', 'value' => (string) $d['attempts']],
                    ['label' => 'Delivery fee', 'value' => $order['delivery_fee'], 'type' => 'money'],
                ]]) ?>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">Parcel</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Parcels', 'value' => '1'],
                    ['label' => 'Payment', 'value' => $cod ? 'Cash on delivery' : 'Already paid'],
                ]]) ?>
                <p class="t-micro t-muted tw-mt-3 tw-mb-0">
                    What is inside is not shown to agents. You do not need it to deliver, so you do
                    not get it.
                </p>
            </div>
        </aside>
    </div>
</div>
