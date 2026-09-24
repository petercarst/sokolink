<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SellerRepository;
use App\Repositories\StoreRepository;

/**
 * A seller creating and editing their own products.
 *
 * Phase 3 built the read side (`CatalogService`) and the stock side
 * (`InventoryService`) but left the write side to the repository, which
 * validates nothing. This is that missing layer, and it is the only thing in
 * the application permitted to write to `products`.
 *
 * Three rules it exists to enforce:
 *
 *   1. **Ownership.** Every write takes the seller id from the session-derived
 *      actor and puts it in the WHERE clause. A seller editing an id that is
 *      not theirs gets "not found", not "forbidden" - the two must be
 *      indistinguishable or the difference is an oracle for enumerating other
 *      sellers' catalogues.
 *   2. **The right to trade.** Holding the seller role is not the same as being
 *      approved. An applicant awaiting a decision can see their dashboard;
 *      only `sellers.status = 'active'` can publish anything.
 *   3. **Publishing needs stock somewhere.** A published product with no
 *      inventory row is a listing nobody can buy, and the first a customer
 *      hears of it is an empty basket at checkout.
 */
final class ProductService
{
    /** Sellers pick from these; anything else is refused rather than stored. */
    private const UNITS = [
        'each', 'pack', 'bottle', 'jerrycan', 'sachet', 'box', 'crate',
        'bag', 'kg', 'g', 'litre', 'ml', 'bundle', 'tray',
    ];

    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
    ) {
    }

    /**
     * Creates a product. It starts as a draft whatever was posted.
     *
     * A new product has no stock yet, so publishing it immediately would list
     * something nobody can buy. The seller adds stock and then publishes, which
     * is also the order the dashboard walks them through.
     *
     * @param  array<string,mixed> $input
     * @return int the new product id
     */
    public function create(int $sellerId, array $input): int
    {
        $this->assertCanTrade($sellerId);

        $data = $this->validate($input, $sellerId, null);

        $data['seller_id'] = $sellerId;
        $data['slug']      = $this->uniqueSlug($data['name']);
        $data['status']    = 'draft';

        $productId = $this->products->create($data);

        Audit::record('product.created', 'product', $productId, $data['name']);

        return $productId;
    }

    /**
     * Updates a product this seller owns.
     *
     * The status is NOT taken from here - publishing and archiving go through
     * publish() and archive(), which have conditions of their own. A form that
     * could set the status directly would bypass them.
     *
     * @param array<string,mixed> $input
     */
    public function update(int $productId, int $sellerId, array $input): void
    {
        $this->assertCanTrade($sellerId);

        $existing = $this->products->findOwnedBy($productId, $sellerId);

        if ($existing === null) {
            throw new DomainRuleException('That product is not one of yours.', 'not_found');
        }

        $data = $this->validate($input, $sellerId, $productId);

        // The slug follows the name only while nobody can have linked to it.
        // Once a product has been published, its URL is somebody's bookmark and
        // a search engine's index, and renaming it would break both.
        if ((string) $existing['status'] === 'draft' && $data['name'] !== (string) $existing['name']) {
            $data['slug'] = $this->uniqueSlug($data['name'], $productId);
        }

        $this->products->updateOwned($productId, $sellerId, $data);

        Audit::record('product.updated', 'product', $productId, $data['name']);
    }

    /**
     * Publishes a product, which is what makes it buyable.
     *
     * Refuses when nothing is in stock anywhere. That is not a formality: the
     * catalogue would list it, a customer would add it to a basket, and
     * checkout would refuse the line - three steps later, for a reason that
     * looks like a fault.
     */
    public function publish(int $productId, int $sellerId): void
    {
        $this->assertCanTrade($sellerId);

        $product = $this->products->findOwnedBy($productId, $sellerId);

        if ($product === null) {
            throw new DomainRuleException('That product is not one of yours.', 'not_found');
        }

        if ((string) $product['status'] === 'suspended') {
            throw new DomainRuleException(
                'An administrator suspended this product. Resolve that before publishing it again.',
                'suspended'
            );
        }

        $stocked = (int) Database::scalar(
            'SELECT COUNT(*) FROM inventory WHERE product_id = :p AND qty_on_hand > 0',
            ['p' => $productId]
        );

        if ($stocked === 0) {
            throw new DomainRuleException(
                'Add stock at one of your stores before publishing this product.',
                'no_stock'
            );
        }

        $this->products->updateOwned($productId, $sellerId, ['status' => 'published']);

        Audit::record('product.published', 'product', $productId, (string) $product['name']);
    }

    /**
     * Archives a product: gone from the catalogue, kept in the database.
     *
     * Never a delete. An archived product still appears on the orders that
     * bought it, and `order_items` keeps its own name and price snapshot so
     * those receipts stay readable either way.
     */
    public function archive(int $productId, int $sellerId): void
    {
        $product = $this->products->findOwnedBy($productId, $sellerId);

        if ($product === null) {
            throw new DomainRuleException('That product is not one of yours.', 'not_found');
        }

        $promised = (int) Database::scalar(
            'SELECT COALESCE(SUM(qty_reserved), 0) FROM inventory WHERE product_id = :p',
            ['p' => $productId]
        );

        if ($promised > 0) {
            throw new DomainRuleException(
                sprintf(
                    'There are %d of these already promised to orders. Fulfil or cancel those first.',
                    $promised
                ),
                'reserved'
            );
        }

        $this->products->updateOwned($productId, $sellerId, ['status' => 'archived']);

        Audit::record('product.archived', 'product', $productId, (string) $product['name']);
    }

    /**
     * The stores this seller can stock a product at.
     *
     * @return list<array<string,mixed>>
     */
    public function storesFor(int $sellerId): array
    {
        return $this->stores->forSeller($sellerId);
    }

    // ---- internals -------------------------------------------------------

    /**
     * @param  array<string,mixed> $input
     * @return array<string,mixed> columns ready for the repository
     */
    private function validate(array $input, int $sellerId, ?int $exceptId): array
    {
        $validator = Validator::make($input, [
            'name'        => 'required|max:190',
            'sku'         => 'required|max:80',
            'description' => 'required|max:5000',
            'price'       => 'required|numeric',
            'unit'        => 'required|max:40',
            'pack_size'   => 'required|max:60',
            'brand'       => 'nullable|max:120',
            'category'    => 'required',
        ], [
            'name'        => 'Product name',
            'sku'         => 'Your reference (SKU)',
            'pack_size'   => 'Pack size',
            'category'    => 'Category',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $clean = $validator->validated();

        $price = $this->money($clean['price'], 'price', 'Price');

        if ($price <= 0) {
            throw ValidationException::forField('price', 'A price must be more than zero.');
        }

        // A "was" price that is not higher than the price is not a saving, and
        // the schema refuses it anyway - better to say so beside the field than
        // to surface a constraint violation as a 500.
        $compare = null;
        if (($input['compare_at'] ?? '') !== '') {
            $compare = $this->money($input['compare_at'], 'compare_at', 'Was price');

            if ($compare < $price) {
                throw ValidationException::forField(
                    'compare_at',
                    'The "was" price has to be higher than the price you are charging.'
                );
            }
        }

        if (!in_array($clean['unit'], self::UNITS, true)) {
            throw ValidationException::forField('unit', 'Choose one of the listed units.');
        }

        $category = $this->categories->findBySlug((string) $clean['category']);

        if ($category === null) {
            throw ValidationException::forField('category', 'Choose a category from the list.');
        }

        // SKU is unique per seller, not globally - two sellers can both call
        // their own product "OIL-5L" and neither is wrong.
        if ($this->products->skuExistsForSeller($clean['sku'], $sellerId, $exceptId)) {
            throw ValidationException::forField('sku', 'You already have a product with that reference.');
        }

        $fulfilment = is_array($input['fulfilment'] ?? null) ? $input['fulfilment'] : [];
        $pickup     = in_array('pickup', $fulfilment, true);
        $delivery   = in_array('delivery', $fulfilment, true);

        if (!$pickup && !$delivery) {
            throw ValidationException::forField(
                'fulfilment',
                'Choose at least one way customers can receive this.'
            );
        }

        $consumable = !empty($input['is_consumable']);
        $days       = $this->consumptionDays($input, $consumable);

        return [
            'category_id'  => (int) $category['id'],
            'name'         => $clean['name'],
            'brand'        => ($clean['brand'] ?? '') !== '' ? $clean['brand'] : null,
            'sku'          => $clean['sku'],
            'description'  => $clean['description'],
            'price'        => number_format($price / 100, 2, '.', ''),
            'compare_at_price' => $compare === null ? null : number_format($compare / 100, 2, '.', ''),
            'unit'         => $clean['unit'],
            'pack_size'    => $clean['pack_size'],
            'allows_pickup'   => $pickup ? 1 : 0,
            'allows_delivery' => $delivery ? 1 : 0,
            'is_consumable'   => $consumable ? 1 : 0,
            'typical_consumption_days' => $days,
        ];
    }

    /**
     * The seller's own estimate of how long one unit lasts.
     *
     * Null when they do not know, and null is the honest answer: the reminder
     * engine schedules nothing rather than guessing, and a guess here would
     * become a nudge somebody did not need.
     *
     * @param array<string,mixed> $input
     */
    private function consumptionDays(array $input, bool $consumable): ?int
    {
        if (!$consumable || ($input['consumption_days'] ?? '') === '') {
            return null;
        }

        $days = (int) $input['consumption_days'];

        if ($days < 1 || $days > 365) {
            throw ValidationException::forField(
                'consumption_days',
                'Give a number of days between 1 and 365, or leave it blank if you do not know.'
            );
        }

        return $days;
    }

    /** Money in, minor units out, so nothing is compared as a float. */
    private function money(mixed $value, string $field, string $label): int
    {
        $raw = str_replace([',', ' '], '', (string) $value);

        if (!is_numeric($raw)) {
            throw ValidationException::forField($field, $label . ' has to be a number.');
        }

        return (int) round((float) $raw * 100);
    }

    /**
     * A URL-safe slug that nothing else is using.
     *
     * The suffix counts up rather than using a random string: two products
     * called "Sunflower Oil" become `sunflower-oil` and `sunflower-oil-2`,
     * which is what somebody reading a URL would expect.
     */
    private function uniqueSlug(string $name, ?int $exceptId = null): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '', '-');
        $base = $base === '' ? 'product' : mb_substr($base, 0, 180);

        $slug    = $base;
        $attempt = 1;

        while ($this->products->slugExists($slug, $exceptId)) {
            $attempt++;
            $slug = $base . '-' . $attempt;

            if ($attempt > 50) {
                // Somebody is doing something strange, or the base is
                // pathological. A random tail ends the loop without failing
                // the seller's save.
                $slug = $base . '-' . bin2hex(random_bytes(3));
                break;
            }
        }

        return $slug;
    }

    private function assertCanTrade(int $sellerId): void
    {
        if (!$this->sellers->canTrade($sellerId)) {
            throw new DomainRuleException(
                'Your seller account is not approved for trading yet. '
                . 'You can prepare your catalogue, but not publish it.',
                'not_active'
            );
        }
    }
}
