<?php
declare(strict_types=1);
/**
 * Inventory - stock per product per store.
 *
 * THE MODEL THIS SCREEN MAKES VISIBLE (FR-INV-01 to FR-INV-03):
 *  - Stock is held per product PER STORE, never as one number for the seller.
 *  - Two quantities are tracked: on hand, and reserved for orders already
 *    placed. Available is on-hand minus reserved, and is derived, never stored.
 *  - Every adjustment needs a reason and writes an immutable movement row.
 *    That is what makes "where did 12 units go?" an answerable question.
 *
 * @var list<array<string,mixed>> $stores  each carrying its own `rows`
 */
?>
<div class="sl-container-wide sl-container">
    <?= partial('dash-page-header', [
        'title'    => 'Inventory',
        'subtitle' => 'Stock is held per store. Adjustments need a reason and are recorded permanently.',
        'actions'  => [['label' => 'Products', 'url' => route('seller.products'), 'icon' => 'package']],
    ]) ?>

    <div class="sl-alert sl-alert-info tw-mb-6">
        <span class="tw-shrink-0 tw-mt-px"><?= component('icon', ['name' => 'info', 'size' => 18]) ?></span>
        <span>
            <strong>Available = on hand &minus; reserved.</strong>
            Reserved means already promised to an order that has been placed. You cannot set stock
            below what is reserved, because that would be un-selling something a customer has
            already paid for.
        </span>
    </div>

    <?php foreach ($stores as $store) : ?>
        <section class="tw-mb-10">
            <div class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-mb-4">
                <h2 class="t-heading-xl tw-mb-0"><?= e($store['name']) ?></h2>
                <span class="sl-chip"><?= e($store['district']) ?>, <?= e($store['region']) ?></span>
            </div>

            <div class="sl-card sl-card-flush">
                <div class="sl-table-scroll">
                <table class="sl-table sl-table-reflow">
                    <caption class="visually-hidden">Stock at <?= e($store['name']) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">SKU</th>
                            <th scope="col" class="sl-num">On hand</th>
                            <th scope="col" class="sl-num">Reserved</th>
                            <th scope="col" class="sl-num">Available</th>
                            <th scope="col">State</th>
                            <th scope="col">Adjust</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($store['rows'] as $row) : ?>
                            <tr>
                                <td data-label="Product">
                                    <a href="<?= e(route('seller.products.form')) ?>?id=<?= e((string) $row['product_id']) ?>">
                                        <?= e($row['name']) ?>
                                    </a>
                                    <span class="t-micro t-muted tw-block">
                                        <?= e($row['pack_size']) ?>
                                        <?php if ($row['status'] !== 'published') : ?>
                                            &middot; <?= e($row['status']) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="SKU"><span class="t-code"><?= e($row['sku']) ?></span></td>
                                <td data-label="On hand" class="sl-num tabular"><?= e((string) $row['on_hand']) ?></td>
                                <td data-label="Reserved" class="sl-num tabular"><?= e((string) $row['reserved']) ?></td>
                                <td data-label="Available" class="sl-num tabular t-body-strong"><?= e((string) $row['available']) ?></td>
                                <td data-label="State"><?= component('badge', ['status' => $row['stock_state']]) ?></td>
                                <td data-label="Adjust">
                                    <?php $fid = $row['product_id'] . '-' . $store['id']; ?>

                                    <!-- A correction: the figure asked for is what was COUNTED on the
                                         shelf, because that is what somebody with a clipboard has in
                                         front of them. The server works out the difference. -->
                                    <form method="post" action="<?= e(route('seller.inventory.adjust')) ?>"
                                          class="tw-flex tw-gap-2 tw-items-end tw-flex-wrap tw-m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= e((string) $row['product_id']) ?>">
                                        <input type="hidden" name="store_id" value="<?= e((string) $store['id']) ?>">

                                        <span>
                                            <label class="visually-hidden" for="qty-<?= e($fid) ?>">
                                                Counted on-hand quantity for <?= e($row['name']) ?> at <?= e($store['name']) ?>
                                            </label>
                                            <input class="sl-input tabular" style="width:5.5rem" type="number"
                                                   id="qty-<?= e($fid) ?>" name="qty"
                                                   value="<?= e((string) $row['on_hand']) ?>"
                                                   min="<?= e((string) $row['reserved']) ?>" step="1">
                                        </span>

                                        <span>
                                            <label class="visually-hidden" for="reason-<?= e($fid) ?>">
                                                Reason for adjusting <?= e($row['name']) ?>
                                            </label>
                                            <select class="sl-select" style="width:auto"
                                                    id="reason-<?= e($fid) ?>" name="reason" required>
                                                <option value="">Reason...</option>
                                                <option value="recount">Stock count correction</option>
                                                <option value="damage">Damaged or expired</option>
                                                <option value="loss">Loss</option>
                                                <option value="transfer">Moved to another store</option>
                                            </select>
                                        </span>

                                        <button class="sl-btn sl-btn-outline-light sl-btn-sm" type="submit">Correct</button>
                                    </form>

                                    <form method="post" action="<?= e(route('seller.inventory.receive')) ?>"
                                          class="tw-flex tw-gap-2 tw-items-end tw-flex-wrap tw-m-0 tw-mt-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="product_id" value="<?= e((string) $row['product_id']) ?>">
                                        <input type="hidden" name="store_id" value="<?= e((string) $store['id']) ?>">

                                        <span>
                                            <label class="visually-hidden" for="recv-<?= e($fid) ?>">
                                                Units of <?= e($row['name']) ?> received at <?= e($store['name']) ?>
                                            </label>
                                            <input class="sl-input tabular" style="width:5.5rem" type="number"
                                                   id="recv-<?= e($fid) ?>" name="qty" value="" min="1" step="1"
                                                   placeholder="+ qty">
                                        </span>

                                        <button class="sl-btn sl-btn-aloe sl-btn-sm" type="submit">Received</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if ($store['rows'] === []) : ?>
                            <tr>
                                <td colspan="7" class="t-caption t-muted">
                                    Nothing stocked at this store yet. Add a product, then record what arrived.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

</div>
