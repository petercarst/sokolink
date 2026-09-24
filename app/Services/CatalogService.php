<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Repositories\CategoryRepository;
use App\Repositories\InventoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\SellerRepository;
use App\Repositories\StoreRepository;

/**
 * The read side of the marketplace: browsing, searching, product and store
 * pages.
 *
 * Everything here is public and unauthenticated, so the job is mostly about
 * what is NOT returned - draft products, suspended sellers, paused stores. Those
 * conditions live in the repository queries rather than here, so there is no
 * way to reach an unpublished product by calling a different service method.
 *
 * This service assembles view models. It does no HTML and no formatting beyond
 * shaping the data: a controller decides how a price is displayed, and money()
 * decides how it is written.
 */
final class CatalogService
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
        private readonly InventoryRepository $inventory = new InventoryRepository(),
        private readonly ReviewRepository $reviews = new ReviewRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{products:list<array<string,mixed>>,total:int,page:int,perPage:int,pages:int}
     */
    public function browse(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $result = $this->products->browse($this->sanitiseFilters($filters), $page, $perPage);

        return [
            'products' => array_map($this->decorate(...), $result['rows']),
            'total'    => $result['total'],
            'page'     => $result['page'],
            'perPage'  => $result['perPage'],
            'pages'    => (int) ceil($result['total'] / max(1, $result['perPage'])),
        ];
    }

    /**
     * A category page. Throws rather than returning an empty listing for an
     * unknown slug, so the controller can render a 404 instead of a page that
     * says "no products in (nothing)".
     *
     * @return array{category:array<string,mixed>,children:list<array<string,mixed>>,
     *               trail:list<array<string,mixed>>,products:array<string,mixed>}
     */
    public function category(string $slug, array $filters = [], int $page = 1): array
    {
        $category = $this->categories->findBySlug($slug);

        if ($category === null) {
            throw new DomainRuleException('That category does not exist.', 'not_found');
        }

        $filters['category'] = $slug;

        return [
            'category' => $category,
            'children' => $this->categories->childrenOf((int) $category['id']),
            'trail'    => $this->categories->trailFor((int) $category['id']),
            'products' => $this->browse($filters, $page),
        ];
    }

    /**
     * A product page, with everything it needs in one call.
     *
     * Availability is per store, not a single number, because the customer is
     * about to choose where to collect from and "3 in stock" across two shops
     * is not the same as "3 in stock here".
     *
     * @return array<string,mixed>
     */
    public function product(string $slug): array
    {
        $product = $this->products->findPublishedBySlug($slug);

        if ($product === null) {
            throw new DomainRuleException('That product is not available.', 'not_found');
        }

        $productId  = (int) $product['id'];
        $stockRows  = $this->inventory->acrossStores($productId);
        $totalStock = array_sum(array_map(static fn (array $r): int => (int) $r['qty_available'], $stockRows));

        return [
            'product'      => $this->decorate($product),
            'images'       => $this->products->imagesFor($productId),
            'availability' => $stockRows,
            'total_stock'  => $totalStock,
            'stock_state'  => $this->stockState($totalStock, $stockRows),
            'pickup_stores' => $this->stores->pickupOptionsFor($productId),
            'reviews'      => $this->reviews->publishedFor($productId, 10),
            'rating'       => [
                'average'      => (float) $product['rating_avg'],
                'count'        => (int) $product['rating_count'],
                'distribution' => $this->reviews->distributionFor($productId),
            ],
            'related'      => $this->products->related($productId, (int) $product['category_id']),
            'trail'        => $this->categories->trailFor((int) $product['category_id']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function store(string $slug, int $page = 1): array
    {
        $store = $this->stores->findPublishedBySlug($slug);

        if ($store === null) {
            throw new DomainRuleException('That store is not available.', 'not_found');
        }

        return [
            'store'    => $store,
            'hours'    => $this->stores->hoursFor((int) $store['id']),
            'products' => $this->browse(['seller' => (int) $store['seller_id']], $page),
        ];
    }

    /**
     * @return array{query:string,results:list<array<string,mixed>>,count:int}
     */
    public function search(string $query, int $limit = 24): array
    {
        $query   = mb_substr(trim($query), 0, 120);
        $results = $query === '' ? [] : $this->products->search($query, $limit);

        return [
            'query'   => $query,
            'results' => array_map($this->decorate(...), $results),
            'count'   => count($results),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function categoryNavigation(): array
    {
        return $this->categories->topLevelWithCounts();
    }

    /**
     * The home page's product strip.
     *
     * "Featured" is highest-rated and in stock, not an editorial pick: there is
     * no curation table, and inventing one on the home page would be a claim
     * the data does not support. Out-of-stock products are excluded because the
     * first thing on the home page should not be something nobody can buy.
     *
     * @return list<array<string,mixed>>
     */
    public function featured(int $limit = 8): array
    {
        return $this->browse(['in_stock' => true, 'sort' => 'rating'], 1, $limit)['products'];
    }

    /**
     * Categories as the filter panel's dropdown needs them: flat, with a depth
     * so a child can be indented under its parent.
     *
     * @return list<array{slug:string,name:string,depth:int}>
     */
    public function categoryFilterOptions(): array
    {
        return array_map(
            static fn (array $row): array => [
                'slug'  => (string) $row['slug'],
                'name'  => (string) $row['name'],
                'depth' => (int) $row['depth'],
            ],
            $this->categories->flatTree()
        );
    }

    /**
     * Published stores, used for the "seller" filter and the store strip on the
     * home page.
     *
     * @return list<array<string,mixed>>
     */
    public function storeFilterOptions(): array
    {
        return $this->stores->allPublished();
    }

    /**
     * Adds the derived fields every product card needs, in one place, so a
     * listing and a product page cannot disagree about whether something is on
     * offer.
     *
     * @param  array<string,mixed> $product
     * @return array<string,mixed>
     */
    private function decorate(array $product): array
    {
        $price   = (float) ($product['price'] ?? 0);
        $compare = isset($product['compare_at_price']) && $product['compare_at_price'] !== null
            ? (float) $product['compare_at_price']
            : null;

        $product['is_on_offer'] = $compare !== null && $compare > $price;
        $product['discount_percent'] = $product['is_on_offer'] && $compare > 0
            ? (int) round((($compare - $price) / $compare) * 100)
            : 0;

        if (array_key_exists('available', $product)) {
            $available = (int) $product['available'];
            $product['in_stock']    = $available > 0;
            $product['stock_label'] = match (true) {
                $available <= 0 => 'Out of stock',
                $available <= 5 => sprintf('Only %d left', $available),
                default         => 'In stock',
            };
        }

        return $product;
    }

    /**
     * One word for the whole product, used for the badge on a product page.
     *
     * "Low" is deliberately per store rather than in total: five units spread
     * across five shops is one unit each, and a customer collecting from one of
     * them should be told so.
     *
     * @param list<array<string,mixed>> $stockRows
     */
    private function stockState(int $total, array $stockRows): string
    {
        if ($total <= 0) {
            return 'out_of_stock';
        }

        foreach ($stockRows as $row) {
            $threshold = (int) $row['low_stock_threshold'];

            if ((int) $row['qty_available'] > 0 && (int) $row['qty_available'] > $threshold) {
                return 'in_stock';
            }
        }

        return 'low_stock';
    }

    /**
     * Filters arrive from a query string, so they are untrusted. Anything
     * unrecognised is dropped rather than passed through - a filter the
     * repository does not know about is either a typo or an attempt.
     *
     * @param  array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function sanitiseFilters(array $filters): array
    {
        $clean = [];

        if (!empty($filters['category']) && is_string($filters['category'])) {
            $clean['category'] = mb_substr($filters['category'], 0, 160);
        }

        foreach (['min_price', 'max_price'] as $key) {
            if (isset($filters[$key]) && is_numeric($filters[$key])) {
                $clean[$key] = max(0.0, (float) $filters[$key]);
            }
        }

        if (isset($clean['min_price'], $clean['max_price']) && $clean['min_price'] > $clean['max_price']) {
            // Somebody dragged the slider past itself. Swapping is friendlier
            // than an error and is what they meant.
            [$clean['min_price'], $clean['max_price']] = [$clean['max_price'], $clean['min_price']];
        }

        if (in_array($filters['fulfilment'] ?? '', ['pickup', 'delivery'], true)) {
            $clean['fulfilment'] = $filters['fulfilment'];
        }

        if (!empty($filters['in_stock'])) {
            $clean['in_stock'] = true;
        }

        if (!empty($filters['seller'])) {
            // The filter panel offers sellers by slug, because a slug survives
            // a re-seed and an id does not. Resolving it here means an unknown
            // slug narrows to nothing rather than being silently ignored.
            $clean['seller'] = is_numeric($filters['seller'])
                ? (int) $filters['seller']
                : (int) (($this->sellers->findPublishedBySlug((string) $filters['seller'])['id'] ?? 0));
        }

        if (isset($filters['min_rating']) && is_numeric($filters['min_rating'])) {
            $rating = (float) $filters['min_rating'];

            if ($rating >= 1.0 && $rating <= 5.0) {
                $clean['min_rating'] = $rating;
            }
        }

        if (!empty($filters['q']) && is_string($filters['q'])) {
            $clean['q'] = mb_substr(trim($filters['q']), 0, 120);
        }

        if (!empty($filters['sort']) && is_string($filters['sort'])) {
            $clean['sort'] = $filters['sort'];
        }

        return $clean;
    }
}
