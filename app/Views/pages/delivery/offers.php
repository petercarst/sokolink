<?php
declare(strict_types=1);
/**
 * Available delivery jobs.
 *
 * WHAT IS DELIBERATELY MISSING: the customer's name, address and phone number.
 * An offer shows the zone, a distance band, the parcel size and the fee - enough
 * to decide whether to take it. The address appears only once the job is
 * accepted and the task becomes yours (FR-DEL-03, FR-DEL-04).
 *
 * @var list<array<string,mixed>> $offers
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Available jobs',
        'subtitle' => 'Work you can take on. Accept to see the delivery address.',
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'lock', 'size' => 18]) ?></span>
        <span>
            Addresses and recipient details are not shown until you accept a job. You get the zone,
            the distance band, the parcel size and the fee &mdash; enough to decide, without exposing
            a customer's address to every agent on the platform.
        </span>
    </div>

    <?php if ($offers === []) : ?>
        <?= component('empty-state', [
            'icon' => 'bell', 'title' => 'No jobs available right now',
            'text' => 'New work appears here when a seller packs a delivery order in a zone you cover.',
            'actionUrl' => route('delivery.tasks'), 'actionLabel' => 'See my deliveries',
        ]) ?>
    <?php else : ?>
        <div class="sl-grid sl-grid-2">
            <?php foreach ($offers as $offer) : ?>
                <article class="sl-card sl-card-raised">
                    <div class="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-mb-4">
                        <div>
                            <p class="t-micro t-muted tw-mb-1"><span class="t-code"><?= e($offer['ref']) ?></span></p>
                            <h2 class="t-heading-md tw-mb-0"><?= e($offer['zone']) ?></h2>
                        </div>
                        <p class="t-heading-xl tabular tw-mb-0"><?= e(money($offer['fee'])) ?></p>
                    </div>

                    <?= component('detail-list', ['items' => array_filter([
                        ['label' => 'Collect from', 'value' => $offer['pickup_store']],
                        ['label' => 'Distance', 'value' => $offer['distance_band']],
                        ['label' => 'Parcels', 'value' => (string) $offer['parcels']],
                        ['label' => 'Weight', 'value' => $offer['weight_band']],
                        ['label' => 'Ready at', 'value' => $offer['ready_at_utc'], 'type' => 'datetime'],
                        $offer['cod_amount'] !== null
                            ? ['label' => 'Cash to collect', 'value' => $offer['cod_amount'], 'type' => 'money']
                            : null,
                    ])]) ?>

                    <?php if ($offer['cod_amount'] !== null) : ?>
                        <div class="sl-alert sl-alert-warn tw-mt-4 tw-mb-0">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span class="t-caption">
                                Cash on delivery. You collect the money before handing the parcel over.
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="tw-flex tw-gap-2 tw-flex-wrap tw-mt-5">
                        <form method="post" action="<?= e(route('delivery.offers.claim')) ?>" class="tw-m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="ref" value="<?= e($offer['ref']) ?>">
                            <button class="sl-btn sl-btn-aloe" type="submit">Accept this job</button>
                        </form>

                        <details>
                            <summary class="sl-btn sl-btn-ghost" style="cursor:pointer">Decline</summary>
                            <form method="post" action="<?= e(route('delivery.offers.decline')) ?>" class="tw-mt-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="ref" value="<?= e($offer['ref']) ?>">
                                <?= component('field', [
                                    'name' => 'reason', 'label' => 'Why?', 'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ''            => 'Choose a reason',
                                        'too_far'     => 'Outside the area I cover',
                                        'too_heavy'   => 'Too heavy for my vehicle',
                                        'timing'      => 'Cannot make the ready time',
                                        'busy'        => 'Already fully loaded',
                                        'other'       => 'Something else',
                                    ],
                                ]) ?>
                                <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Decline job</button>
                            </form>
                        </details>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
