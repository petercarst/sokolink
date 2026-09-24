<?php
declare(strict_types=1);
/**
 * Per-product reorder reminder settings.
 *
 * WHAT A SELLER CONTROLS HERE, AND WHAT THEY DO NOT (FR-CRM-08, FR-CRM-09):
 *
 *  Controls   whether a product is treated as repeat-purchase, and roughly how
 *             long a pack lasts.
 *  Does NOT   who gets messaged, or how often. Consent, the cooldown, the
 *             monthly cap and quiet hours are the customer's and the platform's
 *             - a seller cannot opt anybody in or turn the frequency up.
 *
 * @var list<array<string,mixed>> $products
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Reorder reminders',
        'subtitle' => 'Tell us roughly how long a pack lasts. We work out the timing per customer from that and their own reorder history.',
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            <strong>You set the guide, not the schedule.</strong>
            A customer is only ever messaged if they asked to be, at most a few times a month across
            the whole platform, never during their quiet hours, and never if they have already
            bought the product again. If a customer has their own reorder pattern for a product, that
            wins over your guide.
        </span>
    </div>

    <form method="post" action="<?= e(route('preview.submit')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="feature" value="reminder_settings">

        <div class="sl-card sl-card-flush tw-mb-6">
            <div class="sl-table-scroll">
            <table class="sl-table sl-table-reflow">
                <caption class="visually-hidden">Reorder reminder settings per product</caption>
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col">Pack size</th>
                        <th scope="col">Repeat purchase</th>
                        <th scope="col">A pack lasts (days)</th>
                        <th scope="col">Effect</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product) : ?>
                        <?php $id = (string) $product['id']; ?>
                        <tr>
                            <td data-label="Product">
                                <a href="<?= e(route('seller.products.form')) ?>?slug=<?= e(rawurlencode($product['slug'])) ?>">
                                    <?= e($product['name']) ?>
                                </a>
                                <span class="t-micro t-muted tw-block"><?= e($product['brand']) ?></span>
                            </td>
                            <td data-label="Pack size"><?= e($product['pack_size']) ?></td>
                            <td data-label="Repeat purchase">
                                <label class="sl-check tw-mb-0">
                                    <input type="checkbox" name="consumable[<?= e($id) ?>]" value="1"
                                           <?= !empty($product['is_consumable']) ? 'checked' : '' ?>>
                                    <span class="sl-check-label t-caption visually-hidden">
                                        <?= e($product['name']) ?> is a repeat purchase
                                    </span>
                                </label>
                            </td>
                            <td data-label="A pack lasts (days)">
                                <label class="visually-hidden" for="days-<?= e($id) ?>">
                                    Days a pack of <?= e($product['name']) ?> typically lasts
                                </label>
                                <input class="sl-input tabular" style="width:6rem" type="number"
                                       id="days-<?= e($id) ?>" name="days[<?= e($id) ?>]"
                                       value="<?= e((string) ($product['typical_consumption_days'] ?? '')) ?>"
                                       min="1" max="365">
                            </td>
                            <td data-label="Effect">
                                <?php if (empty($product['typical_consumption_days'])) : ?>
                                    <span class="t-micro t-muted">
                                        No guide set &mdash; nothing is scheduled until a customer has
                                        bought it twice and we can see their own interval.
                                    </span>
                                <?php else : ?>
                                    <span class="t-micro t-muted">
                                        A customer buying one pack would first be considered at about
                                        <strong><?= e((string) $product['typical_consumption_days']) ?> days</strong>,
                                        scaled by how many they bought.
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <button class="sl-btn sl-btn-primary" type="submit">Save reminder settings</button>
    </form>

    <div class="sl-card sl-card-band tw-mt-8">
        <h2 class="t-heading-md tw-mb-3">How the timing is actually decided</h2>
        <ol class="tw-m-0 tw-pl-5 tw-flex tw-flex-col tw-gap-2">
            <li class="t-caption">
                <strong>The customer's own pattern first.</strong> If they have bought this product
                two or more times, the median gap between their purchases is used. It beats any guide.
            </li>
            <li class="t-caption">
                <strong>Then your guide,</strong> scaled by how much they bought - two packs means
                roughly twice as long.
            </li>
            <li class="t-caption">
                <strong>Then a category default,</strong> if an administrator has set one.
            </li>
            <li class="t-caption">
                <strong>Otherwise nothing is sent.</strong> Silence beats guessing, and a badly timed
                reminder is worse than none.
            </li>
        </ol>
    </div>
</div>
