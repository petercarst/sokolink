<?php
declare(strict_types=1);
/**
 * Seller application queue.
 *
 * @var list<array<string,mixed>> $applications
 */
$pending = array_filter($applications, static fn (array $a): bool => $a['status'] === 'pending_approval');
$decided = array_filter($applications, static fn (array $a): bool => $a['status'] !== 'pending_approval');
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Seller approvals',
        'subtitle' => 'Nobody can list a product or take an order until you decide.',
    ]) ?>

    <?php if ($pending === []) : ?>
        <?= component('empty-state', [
            'icon' => 'check-circle', 'title' => 'Nothing waiting',
            'text' => 'New applications appear here as they are submitted.',
        ]) ?>
    <?php else : ?>
        <h2 class="t-heading-xl tw-mb-4">Waiting for a decision</h2>

        <div class="tw-flex tw-flex-col tw-gap-4 tw-mb-10">
            <?php foreach ($pending as $application) : ?>
                <article class="sl-card sl-card-raised">
                    <div class="tw-flex tw-flex-wrap tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                        <div class="tw-flex tw-gap-3">
                            <span class="sl-avatar"><?= e(initials($application['business_name'])) ?></span>
                            <div>
                                <h3 class="t-heading-md tw-mb-1"><?= e($application['business_name']) ?></h3>
                                <p class="t-micro t-muted tw-mb-0">
                                    <?= e($application['contact_name']) ?> &middot; <?= e($application['email']) ?>
                                    &middot; applied <?= time_tag($application['submitted_at_utc'], true) ?>
                                </p>
                            </div>
                        </div>
                        <?= component('badge', ['status' => 'pending_approval']) ?>
                    </div>

                    <div class="sl-grid sl-grid-2 tw-mb-4">
                        <?= component('detail-list', ['items' => [
                            ['label' => 'Business type', 'value' => $application['business_type']],
                            ['label' => 'Registration', 'value' => $application['registration_number'] ?? 'Not provided'],
                            ['label' => 'Region', 'value' => $application['region']],
                        ]]) ?>
                        <?= component('detail-list', ['items' => [
                            ['label' => 'First store', 'value' => $application['store_name']],
                            ['label' => 'Address', 'value' => $application['street'] . ', ' . $application['district']],
                            ['label' => 'Offers', 'value' => implode(', ', array_map(
                                static fn (string $m): string => $m === 'delivery' ? 'Delivery' : 'Collect',
                                $application['offers']
                            ))],
                        ]]) ?>
                    </div>

                    <p class="t-caption t-muted tw-mb-4">
                        <strong>Sells:</strong> <?= e($application['categories']) ?>
                    </p>

                    <div class="tw-flex tw-gap-2 tw-flex-wrap">
                        <a class="sl-btn sl-btn-outline-light sl-btn-sm"
                           href="<?= e(route('admin.approvals.show', ['id' => $application['id']])) ?>">
                            Review in full
                        </a>
                        <form method="post" action="<?= e(route('preview.submit')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="feature" value="seller_approve">
                            <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">Approve</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($decided !== []) : ?>
        <h2 class="t-heading-xl tw-mb-4">Already decided</h2>
        <?= component('data-table', [
            'caption' => 'Previously decided applications',
            'columns' => [
                ['key' => 'business', 'label' => 'Business', 'href' => 'url', 'sub' => 'contact'],
                ['key' => 'region',   'label' => 'Region'],
                ['key' => 'status',   'label' => 'Decision', 'type' => 'badge'],
                ['key' => 'reason',   'label' => 'Reason',   'type' => 'muted'],
                ['key' => 'decided',  'label' => 'Decided',  'type' => 'date'],
                ['key' => 'by',       'label' => 'By'],
            ],
            'rows' => array_map(static fn (array $a): array => [
                'business' => $a['business_name'],
                'contact'  => $a['contact_name'],
                'region'   => $a['region'],
                'status'   => $a['status'] === 'rejected' ? 'rejected_seller' : 'approved',
                'reason'   => $a['decision_reason'] ?? '',
                'decided'  => $a['decided_at_utc'] ?? null,
                'by'       => $a['decided_by'] ?? '-',
                'url'      => route('admin.approvals.show', ['id' => $a['id']]),
            ], $decided),
        ]) ?>
    <?php endif; ?>
</div>
