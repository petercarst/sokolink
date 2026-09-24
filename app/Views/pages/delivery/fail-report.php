<?php
declare(strict_types=1);
/**
 * Report a failed delivery attempt.
 *
 * A reason code is mandatory (FR-DEL-07). "Failed" on its own tells the
 * customer, the seller and support nothing, and it is the first thing every one
 * of them asks. After the attempt limit the task returns to the seller rather
 * than being retried forever.
 *
 * @var array<string,mixed> $order
 */
$d        = $order['delivery'];
$attempt  = (int) $d['attempts'] + 1;
$maxTries = 3;
$isLast   = $attempt >= $maxTries;
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'My deliveries', 'url' => route('delivery.tasks')],
            ['label' => $d['task_ref'], 'url' => route('delivery.tasks.show', ['ref' => $order['ref']])],
            ['label' => 'Report a problem', 'url' => null],
        ],
        'title'    => 'Could not deliver',
        'subtitle' => 'Attempt ' . $attempt . ' of ' . $maxTries . ' for ' . $d['recipient'] . '.',
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
        <section class="sl-card sl-card-raised">
            <?php if ($isLast) : ?>
                <div class="sl-alert sl-alert-warn tw-mb-5">
                    <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                    <span>
                        <strong>This is the final attempt.</strong>
                        Reporting it as failed returns the parcel to
                        <?= e($order['store_name']) ?> and starts the refund process for the customer.
                    </span>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= e(route('delivery.tasks.failed')) ?>" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="ref" value="<?= e($order['delivery']['task_ref']) ?>">

                <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
                    <legend class="sl-label">
                        What happened?<span class="sl-required" aria-hidden="true">*</span>
                        <span class="visually-hidden"> (required)</span>
                    </legend>

                    <div class="tw-flex tw-flex-col tw-gap-2">
                        <?php
                        $reasons = [
                            'recipient_absent'  => ['Nobody there', 'I arrived and could not find anyone to receive it.'],
                            'wrong_address'     => ['Address is wrong', 'The address does not exist or is not where the customer described.'],
                            'refused'           => ['Recipient refused it', 'They were there but would not accept the parcel.'],
                            'unreachable_phone' => ['Could not reach them', 'Phone unanswered or switched off, and I could not find the place.'],
                            'access_denied'     => ['Could not get in', 'Gate, security or building access stopped me.'],
                            'unsafe_conditions' => ['Unsafe to deliver', 'Weather, road or safety conditions made it impossible.'],
                            'damaged_in_transit'=> ['Parcel damaged', 'The parcel was damaged and I did not hand it over.'],
                            'other'             => ['Something else', 'None of the above - explain below.'],
                        ];
                        foreach ($reasons as $value => [$label, $desc]) : ?>
                            <label class="sl-option">
                                <input type="radio" class="tw-mt-1" name="reason_code" value="<?= e($value) ?>" required>
                                <span class="tw-flex-1">
                                    <span class="t-body-strong tw-block"><?= e($label) ?></span>
                                    <span class="t-micro t-muted"><?= e($desc) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <div class="sl-field">
                    <label class="sl-label" for="fail-note">
                        What exactly happened?<span class="sl-required" aria-hidden="true">*</span>
                        <span class="visually-hidden"> (required)</span>
                    </label>
                    <textarea class="sl-textarea" id="fail-note" name="note" required maxlength="500"
                              aria-describedby="fail-note-help"
                              placeholder="For example: arrived 11:20, called twice, no answer, no one at the gate."></textarea>
                    <span class="sl-help" id="fail-note-help">
                        The customer and the seller both see this. Times and what you tried are what
                        stop an argument later.
                    </span>
                </div>

                <label class="sl-check tw-mb-5">
                    <input type="checkbox" name="contacted" value="1">
                    <span class="sl-check-label">
                        I tried to call the recipient
                        <span class="t-micro t-muted tw-block">
                            Worth ticking honestly - it is the first thing support checks.
                        </span>
                    </span>
                </label>

                <button class="sl-btn sl-btn-danger sl-btn-lg sl-btn-block tw-mb-3" type="submit">
                    Report this attempt as failed
                </button>

                <a class="sl-btn sl-btn-ghost sl-btn-block"
                   href="<?= e(route('delivery.tasks.show', ['ref' => $order['ref']])) ?>">
                    Cancel - I will try again now
                </a>
            </form>
        </section>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <div class="sl-card tw-mb-6">
                <h2 class="t-heading-md tw-mb-4">This delivery</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Task', 'value' => $d['task_ref'], 'type' => 'code'],
                    ['label' => 'Recipient', 'value' => $d['recipient']],
                    ['label' => 'Zone', 'value' => $d['zone']],
                    ['label' => 'Attempt', 'value' => $attempt . ' of ' . $maxTries],
                    ['label' => 'Collect from', 'value' => $order['store_name']],
                ]]) ?>
                <address class="t-caption t-muted tw-not-italic tw-mt-4 tw-mb-0"><?= e($d['address']) ?></address>
            </div>

            <div class="sl-card">
                <h2 class="t-heading-md tw-mb-3">What happens next</h2>
                <p class="t-caption t-muted tw-mb-0">
                    <?php if ($isLast) : ?>
                        The parcel goes back to the seller and the customer is refunded. Both are told
                        the reason you give.
                    <?php else : ?>
                        The job returns to the queue for another attempt, and the customer is told
                        what happened and that a retry is coming. You are not penalised for a failed
                        attempt with a recorded reason.
                    <?php endif; ?>
                </p>
            </div>
        </aside>
    </div>
</div>
