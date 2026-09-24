<?php
declare(strict_types=1);
/**
 * Dispute queue - escalated tickets needing an administrator decision.
 *
 * Support escalates; only an admin resolves. The actions available here are the
 * ones support deliberately cannot take: approving a refund, acting against a
 * seller, suspending an account.
 *
 * @var list<array<string,mixed>> $disputes
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Disputes',
        'subtitle' => 'Escalated by support because they need a decision only you can make.',
    ]) ?>

    <?php if ($disputes === []) : ?>
        <?= component('empty-state', [
            'icon' => 'check-circle', 'title' => 'No open disputes',
            'text' => 'Support escalates a ticket here when it needs a refund approval or action against an account.',
            'actionUrl' => route('admin.dashboard'), 'actionLabel' => 'Back to the overview',
        ]) ?>
    <?php else : ?>
        <div class="tw-flex tw-flex-col tw-gap-6">
            <?php foreach ($disputes as $dispute) : ?>
                <?php
                $internal = null;
                foreach (array_reverse($dispute['messages']) as $message) {
                    if (!empty($message['internal'])) {
                        $internal = $message;
                        break;
                    }
                }
                ?>
                <article class="sl-card sl-card-raised">
                    <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                        <div style="min-width:0">
                            <p class="t-micro t-muted tw-mb-1">
                                <span class="t-code"><?= e($dispute['ref']) ?></span>
                                &middot; <?= e($dispute['customer_name']) ?>
                                <?php if ($dispute['order_ref'] !== null) : ?>
                                    &middot; order <span class="t-code"><?= e($dispute['order_ref']) ?></span>
                                <?php endif; ?>
                            </p>
                            <h2 class="t-heading-md tw-mb-0"><?= e($dispute['subject']) ?></h2>
                        </div>
                        <?= component('badge', ['status' => 'escalated']) ?>
                    </div>

                    <?php if ($internal !== null) : ?>
                        <div class="sl-alert sl-alert-warn tw-mb-4">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong>Escalated by <?= e($internal['author']) ?></strong>
                                &middot; <?= time_tag($internal['at_utc'], true) ?><br>
                                <span class="t-caption"><?= e($internal['body']) ?></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <h3 class="t-heading-md tw-mb-3">Your options</h3>

                    <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                        <?= csrf_field() ?>

                        <?= component('field', [
                            'name' => 'resolution_' . $dispute['ref'], 'label' => 'Decision', 'type' => 'select',
                            'required' => true,
                            'options' => [
                                ''                 => 'Choose an outcome',
                                'refund_full'      => 'Approve a full refund',
                                'refund_partial'   => 'Approve a partial refund',
                                'redeliver'        => 'Arrange another delivery at no cost',
                                'seller_warning'   => 'Warn the seller',
                                'seller_suspend'   => 'Suspend the seller pending review',
                                'no_action'        => 'No action needed - explain to the customer',
                            ],
                        ]) ?>

                        <?= component('field', [
                            'name' => 'resolution_note_' . $dispute['ref'], 'label' => 'Reason for the decision',
                            'type' => 'textarea', 'required' => true,
                            'help' => 'Required. Recorded in the audit log and shared with support so they can explain it.',
                        ]) ?>

                        <div class="tw-flex tw-gap-2 tw-flex-wrap">
                            <button class="sl-btn sl-btn-primary" type="submit" name="feature" value="dispute_resolve">
                                Resolve the dispute
                            </button>
                            <a class="sl-btn sl-btn-outline-light"
                               href="<?= e(route('support.tickets.show', ['ref' => $dispute['ref']])) ?>">
                                Read the full thread
                            </a>
                        </div>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
