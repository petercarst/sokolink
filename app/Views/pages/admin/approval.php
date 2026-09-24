<?php
declare(strict_types=1);
/**
 * Review one seller application.
 *
 * A rejection REQUIRES a reason (FR-ADM-02). "Your application was
 * unsuccessful" with no explanation generates a support ticket and a reapply
 * with the same problem, so the field is mandatory and the text is sent to the
 * applicant verbatim.
 *
 * @var array<string,mixed> $application
 */
$pending = $application['status'] === 'pending_approval';
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Seller approvals', 'url' => route('admin.approvals')],
            ['label' => $application['business_name'], 'url' => null],
        ],
        'title'    => $application['business_name'],
        'subtitle' => 'Applied ' . local_datetime($application['submitted_at_utc']),
        'badge'    => $application['status'] === 'rejected' ? 'rejected_seller' : $application['status'],
    ]) ?>

    <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 360px;">
        <div>
            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">The business</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Trading name', 'value' => $application['business_name']],
                    ['label' => 'Business type', 'value' => $application['business_type']],
                    ['label' => 'Registration number', 'value' => $application['registration_number'] ?? 'Not provided'],
                    ['label' => 'Contact', 'value' => $application['contact_name']],
                    ['label' => 'Email', 'value' => $application['email']],
                    ['label' => 'Phone', 'value' => $application['phone_masked']],
                ]]) ?>
            </section>

            <section class="sl-card tw-mb-6">
                <h2 class="t-heading-xl tw-mb-5">Their first store</h2>
                <?= component('detail-list', ['items' => [
                    ['label' => 'Store name', 'value' => $application['store_name']],
                    ['label' => 'Address', 'value' => $application['street']],
                    ['label' => 'District', 'value' => $application['district']],
                    ['label' => 'Region', 'value' => $application['region']],
                    ['label' => 'Offers', 'value' => implode(', ', array_map(
                        static fn (string $m): string => $m === 'delivery' ? 'Home delivery' : 'Click and collect',
                        $application['offers']
                    ))],
                ]]) ?>

                <p class="t-caption t-muted tw-mt-5 tw-mb-0">
                    <strong>What they say they sell:</strong> <?= e($application['categories']) ?>
                </p>
            </section>

            <?php if (!$pending) : ?>
                <section class="sl-card">
                    <h2 class="t-heading-xl tw-mb-4">Decision</h2>
                    <div class="sl-alert sl-alert-<?= $application['status'] === 'rejected' ? 'danger' : 'success' ?> tw-mb-0">
                        <span class="tw-shrink-0 tw-mt-px">
                            <?= component('icon', ['name' => $application['status'] === 'rejected' ? 'close' : 'check', 'size' => 18]) ?>
                        </span>
                        <span>
                            <strong><?= e($application['status'] === 'rejected' ? 'Rejected' : 'Approved') ?></strong>
                            by <?= e($application['decided_by'] ?? 'an administrator') ?>,
                            <?= time_tag($application['decided_at_utc'] ?? $application['submitted_at_utc'], true) ?>.
                            <?php if (!empty($application['decision_reason'])) : ?>
                                <br><span class="t-caption"><?= e($application['decision_reason']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <aside class="tw-mt-8 xl:tw-mt-0">
            <?php if ($pending) : ?>
                <div class="sl-card sl-card-raised tw-mb-6">
                    <h2 class="t-heading-md tw-mb-2">Approve</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        The store goes live, the full seller dashboard unlocks, and the applicant is
                        emailed. They can start listing immediately.
                    </p>
                    <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="seller_approve">
                        <?= component('field', ['name' => 'approve_note', 'label' => 'Note to the seller (optional)']) ?>
                        <button class="sl-btn sl-btn-aloe sl-btn-block" type="submit">Approve this seller</button>
                    </form>
                </div>

                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-2">Reject</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        The reason is sent to the applicant exactly as you write it, so make it
                        actionable &mdash; they can fix the problem and reapply.
                    </p>
                    <form method="post" action="<?= e(route('preview.submit')) ?>" data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="feature" value="seller_reject">

                        <?= component('field', [
                            'name' => 'reject_reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
                            'help' => 'Required. Tell them what to change, not just that the answer is no.',
                        ]) ?>

                        <label class="sl-check tw-mb-4">
                            <input type="checkbox" name="allow_reapply" value="1" checked>
                            <span class="sl-check-label">They may reapply</span>
                        </label>

                        <button class="sl-btn sl-btn-danger sl-btn-block" type="submit">Reject application</button>
                    </form>
                </div>
            <?php else : ?>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-3">Already decided</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        This application has been dealt with. The decision and its reason are in the
                        audit log permanently.
                    </p>
                    <a class="sl-btn sl-btn-outline-light sl-btn-block" href="<?= e(route('admin.approvals')) ?>">
                        Back to the queue
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</div>
