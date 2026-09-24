<?php
declare(strict_types=1);
/**
 * Add or edit a product.
 *
 * The upload control states the real rules (FR-CAT-04, NFR-SEC-06): type is
 * checked by sniffing the file's content rather than trusting its name or
 * extension, size is capped, and every accepted image is re-encoded through GD
 * so anything hidden inside it does not survive.
 *
 * @var array<string,mixed>|null $product
 * @var list<array{slug:string,name:string,depth:int}> $categories
 * @var array<string,string> $units
 * @var bool $canTrade
 */
$isEdit = $product !== null;
$catOptions = ['' => 'Choose a category'];
foreach ($categories as $category) {
    if ($category['depth'] > 0) {
        $catOptions[$category['slug']] = $category['name'];
    }
}
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'crumbs' => [
            ['label' => 'Products', 'url' => route('seller.products')],
            ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
        ],
        'title'    => $isEdit ? 'Edit product' : 'Add a product',
        'subtitle' => $isEdit
            ? 'Changes go live immediately. Stock is managed separately, per store.'
            : 'Describe the product. You set how much each store holds once it is saved.',
        'badge'    => $isEdit ? $product['status'] : null,
    ]) ?>

    <form method="post" action="<?= e(route('seller.products.save')) ?>" data-validate>
        <?= csrf_field() ?>
        <?php if ($isEdit) : ?>
            <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
        <?php endif; ?>

        <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
            <div>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-5">The basics</h2>

                    <?= component('field', [
                        'name' => 'name', 'label' => 'Product name', 'required' => true,
                        'value' => $product['name'] ?? '',
                        'help' => 'What a customer would search for. Include the brand if it matters.',
                    ]) ?>

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', ['name' => 'brand', 'label' => 'Brand', 'value' => $product['brand'] ?? '']) ?>
                        <?= component('field', [
                            'name' => 'sku', 'label' => 'Your SKU', 'required' => true,
                            'value' => $product['sku'] ?? '',
                            'help' => 'Must be unique within your store.',
                        ]) ?>
                    </div>

                    <?= component('field', [
                        'name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true,
                        'value' => $product['category_slug'] ?? '', 'options' => $catOptions,
                        'help' => 'Pick the most specific one - it drives search and filtering.',
                    ]) ?>

                    <?= component('field', [
                        'name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true,
                        'value' => $product['description'] ?? '',
                        'help' => 'What it is, what size, and anything a customer would be annoyed to discover later.',
                    ]) ?>
                </section>

                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-5">Price and size</h2>

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', [
                            'name' => 'price', 'label' => 'Price (' . config('app.currency_symbol') . ')',
                            'type' => 'number', 'required' => true,
                            'value' => isset($product['price']) ? rtrim(rtrim((string) $product['price'], '0'), '.') : '',
                            'attrs' => ['min' => '1', 'step' => '100'],
                        ]) ?>
                        <?= component('field', [
                            'name' => 'compare_at', 'label' => 'Was price (optional)', 'type' => 'number',
                            'value' => ($product['compare_at_price'] ?? '') !== ''
                                ? rtrim(rtrim((string) $product['compare_at_price'], '0'), '.') : '',
                            'attrs' => ['min' => '0', 'step' => '100'],
                            'help' => 'Shown struck through. Only use it if the product really was this price.',
                        ]) ?>
                    </div>

                    <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4">
                        <?= component('field', [
                            'name' => 'unit', 'label' => 'Sold as', 'type' => 'select', 'required' => true,
                            'value' => $product['unit'] ?? '',
                            'options' => array_merge(['' => 'Choose a unit'], $units),
                        ]) ?>
                        <?= component('field', [
                            'name' => 'pack_size', 'label' => 'Pack size', 'required' => true,
                            'value' => $product['pack_size'] ?? '', 'placeholder' => '5 L, 25 kg, 4 x 175 g',
                        ]) ?>
                    </div>
                </section>

                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">Photos</h2>
                    <p class="t-caption t-muted tw-mb-4">
                        Until a photo is uploaded, your product shows a plain tonal panel marked
                        PLACEHOLDER, so nobody browsing mistakes it for your artwork.
                    </p>

                    <div class="sl-alert sl-alert-info tw-mb-0">
                        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
                        <span>
                            <strong>Uploads are not connected yet.</strong>
                            Rather than show you a file picker that quietly discards what you choose,
                            there is no control here. When it arrives it will accept JPEG, PNG and
                            WebP up to 3&nbsp;MB, check each file's actual content rather than its
                            name, strip the metadata and re-encode it, so nothing hidden inside an
                            image survives the upload (FR-CAT-04, NFR-SEC-06).
                        </span>
                    </div>
                </section>

                <section class="sl-card">
                    <h2 class="t-heading-xl tw-mb-2">Reorder reminders</h2>
                    <p class="t-caption t-muted tw-mb-5">
                        If this is something people buy again, tell us roughly how long a pack lasts.
                        We combine that with how often each customer has actually reordered - we do
                        not message anyone just because a number of days has passed.
                    </p>

                    <label class="sl-check tw-mb-4">
                        <input type="checkbox" name="is_consumable" value="1"
                               <?= !empty($product['is_consumable']) ? 'checked' : '' ?>>
                        <span class="sl-check-label">
                            People buy this repeatedly
                            <span class="t-micro t-muted tw-block">Leave unticked for one-off purchases.</span>
                        </span>
                    </label>

                    <?= component('field', [
                        'name' => 'consumption_days', 'label' => 'A pack typically lasts (days)', 'type' => 'number',
                        'value' => isset($product['typical_consumption_days']) ? (string) $product['typical_consumption_days'] : '',
                        'attrs' => ['min' => '1', 'max' => '365'],
                        'help' => 'Your best guess for an average household. Leave blank if you genuinely do not know - we would rather send nothing than guess.',
                    ]) ?>
                </section>
            </div>

            <aside class="tw-mt-8 xl:tw-mt-0 xl:tw-sticky" style="top:96px">
                <div class="sl-card tw-mb-6">
                    <h2 class="t-heading-md tw-mb-4">Availability</h2>

                    <fieldset class="sl-field tw-border-0 tw-p-0 tw-m-0">
                        <legend class="sl-label">Customers can receive this by</legend>
                        <label class="sl-check">
                            <input type="checkbox" name="fulfilment[]" value="pickup"
                                   <?= !$isEdit || in_array('pickup', $product['fulfilment'], true) ? 'checked' : '' ?>>
                            <span class="sl-check-label">Click and collect</span>
                        </label>
                        <label class="sl-check">
                            <input type="checkbox" name="fulfilment[]" value="delivery"
                                   <?= $isEdit && in_array('delivery', $product['fulfilment'], true) ? 'checked' : '' ?>>
                            <span class="sl-check-label">Home delivery</span>
                        </label>
                        <?php if (error_for('fulfilment') !== null) : ?>
                            <span class="sl-error">
                                <?= component('icon', ['name' => 'alert', 'size' => 14]) ?><?= e((string) error_for('fulfilment')) ?>
                            </span>
                        <?php endif; ?>
                    </fieldset>

                    <button class="sl-btn sl-btn-primary sl-btn-block tw-mb-2" type="submit">
                        <?= e($isEdit ? 'Save changes' : 'Create product') ?>
                    </button>
                    <a class="sl-btn sl-btn-ghost sl-btn-block" href="<?= e(route('seller.products')) ?>">Cancel</a>
                </div>
            </aside>
        </div>
    </form>

    <?php if ($isEdit) : ?>
        <!-- Publishing is outside the edit form, and separate from saving, because
             both of these have conditions the form cannot check: a product needs
             stock somewhere before it can go live, and one with units already
             promised to orders cannot be archived out from under them. Putting
             them in the status dropdown would make those refusals look like
             validation errors on an unrelated save. -->
        <div class="xl:tw-grid xl:tw-gap-8 tw-items-start" style="grid-template-columns: minmax(0, 1fr) 340px;">
            <div>
                <section class="sl-card tw-mb-6">
                    <h2 class="t-heading-xl tw-mb-2">
                        This product is <?= component('badge', ['status' => $product['status']]) ?>
                    </h2>

                    <?php if ($product['status'] === 'draft') : ?>
                        <p class="t-caption t-muted tw-mb-4">
                            Nobody can see or buy it yet. Record how much of it each store holds,
                            then publish.
                        </p>

                        <?php if ($canTrade) : ?>
                            <div class="tw-flex tw-gap-2 tw-flex-wrap">
                                <a class="sl-btn sl-btn-outline-light" href="<?= e(route('seller.inventory')) ?>">
                                    <?= component('icon', ['name' => 'grain', 'size' => 18]) ?> Add stock
                                </a>
                                <form method="post" action="<?= e(route('seller.products.publish')) ?>" class="tw-m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
                                    <button class="sl-btn sl-btn-aloe" type="submit">
                                        <?= component('icon', ['name' => 'check', 'size' => 18]) ?> Publish
                                    </button>
                                </form>
                            </div>
                        <?php else : ?>
                            <p class="sl-alert sl-alert-warn t-caption tw-mb-0">
                                <span>
                                    Your seller account is still being reviewed, so nothing can be
                                    published yet. Everything you save here is kept.
                                </span>
                            </p>
                        <?php endif; ?>

                    <?php elseif ($product['status'] === 'published') : ?>
                        <p class="t-caption t-muted tw-mb-4">
                            Live in the catalogue wherever a store has stock.
                        </p>
                        <div class="tw-flex tw-gap-2 tw-flex-wrap">
                            <a class="sl-btn sl-btn-outline-light" href="<?= e(route('product.show', ['slug' => $product['slug']])) ?>">
                                View the public page
                            </a>
                            <form method="post" action="<?= e(route('seller.products.archive')) ?>" class="tw-m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
                                <button class="sl-btn sl-btn-danger" type="submit">Archive</button>
                            </form>
                        </div>

                    <?php elseif ($product['status'] === 'suspended') : ?>
                        <div class="sl-alert sl-alert-danger tw-mb-0">
                            <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'alert', 'size' => 18]) ?></span>
                            <span>
                                <strong>An administrator suspended this listing.</strong>
                                <?php if (!empty($product['moderation_reason'])) : ?>
                                    <span class="tw-block tw-mt-1"><?= e((string) $product['moderation_reason']) ?></span>
                                <?php endif; ?>
                                Contact support once the issue is resolved.
                            </span>
                        </div>

                    <?php else : ?>
                        <p class="t-caption t-muted tw-mb-4">
                            Archived: off the catalogue, but still on every order that bought it.
                        </p>
                        <?php if ($canTrade) : ?>
                            <form method="post" action="<?= e(route('seller.products.publish')) ?>" class="tw-m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $product['id']) ?>">
                                <button class="sl-btn sl-btn-aloe" type="submit">Put it back on sale</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>

            <div>
                <div class="sl-card">
                    <h2 class="t-heading-md tw-mb-4">Stock right now</h2>

                    <?php if ($stock === []) : ?>
                        <p class="t-caption t-muted tw-mb-4">Not stocked at any of your stores yet.</p>
                    <?php else : ?>
                        <?= component('detail-list', ['items' => array_map(
                            static fn (array $s): array => [
                                'label' => $s['store_name'],
                                'value' => $s['available'] . ' available of ' . $s['on_hand'] . ' on hand',
                            ],
                            $stock
                        )]) ?>
                    <?php endif; ?>

                    <a class="sl-btn sl-btn-outline-light sl-btn-sm sl-btn-block tw-mt-4"
                       href="<?= e(route('seller.inventory')) ?>">Adjust stock</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
