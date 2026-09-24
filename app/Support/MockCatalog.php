<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\View;

/* =============================================================================
 *  PHASE 1 ONLY - DELETED IN PHASE 4
 * -----------------------------------------------------------------------------
 *  This class fakes the queries the catalogue pages need so the frontend can be
 *  built and reviewed before the database exists. Each method names the
 *  repository method that replaces it.
 *
 *  Phase 4 is not complete until this file and app/Views/_mock/ are both gone
 *  (docs/DEVELOPMENT_ROADMAP.md, Phase 4 exit criteria).
 * ===========================================================================*/
final class MockCatalog
{
    /** @return list<array<string,mixed>> Replaced by CategoryRepository::tree() */
    public static function categories(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = View::mock('categories');

        return $rows;
    }

    /** @return list<array<string,mixed>> Replaced by StoreRepository::all() */
    public static function stores(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = View::mock('stores');

        return $rows;
    }

    /** @return array<string,mixed>|null Replaced by StoreRepository::findBySlug() */
    public static function storeBySlug(string $slug): ?array
    {
        foreach (self::stores() as $store) {
            if ($store['slug'] === $slug) {
                return $store;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    public static function storeById(int $id): ?array
    {
        foreach (self::stores() as $store) {
            if ($store['id'] === $id) {
                return $store;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> Replaced by ProductRepository::all() */
    public static function products(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = View::mock('products');

        return $rows;
    }

    /** @return array<string,mixed>|null Replaced by ProductRepository::findBySlug() */
    public static function productBySlug(string $slug): ?array
    {
        foreach (self::products() as $product) {
            if ($product['slug'] === $slug) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Stands in for ProductRepository::listForCatalog(CatalogFilter).
     *
     * The real version does this in SQL with indexes and a FULLTEXT match; the
     * filter keys and the shape of the result are the contract.
     *
     * @param array<string,mixed> $filters q, category, seller, min, max, stock, fulfilment, rating, sort
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = 12): array
    {
        $items = self::products();

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items  = array_values(array_filter($items, static function (array $p) use ($needle): bool {
                $haystack = mb_strtolower(
                    $p['name'] . ' ' . $p['brand'] . ' ' . $p['description'] . ' ' . $p['category_name']
                );

                return str_contains($haystack, $needle);
            }));
        }

        if (($category = (string) ($filters['category'] ?? '')) !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool =>
                $p['category_slug'] === $category || $p['parent_category_slug'] === $category));
        }

        if (($seller = (string) ($filters['seller'] ?? '')) !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool => $p['seller_slug'] === $seller));
        }

        if (($min = (string) ($filters['min'] ?? '')) !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool => (float) $p['price'] >= (float) $min));
        }

        if (($max = (string) ($filters['max'] ?? '')) !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool => (float) $p['price'] <= (float) $max));
        }

        if (($filters['stock'] ?? '') === 'in') {
            $items = array_values(array_filter($items, static fn (array $p): bool => $p['stock_state'] !== 'out'));
        }

        if (($fulfilment = (string) ($filters['fulfilment'] ?? '')) !== '') {
            $items = array_values(array_filter($items, static fn (array $p): bool =>
                in_array($fulfilment, $p['fulfilment'], true)));
        }

        if (($rating = (float) ($filters['rating'] ?? 0)) > 0) {
            $items = array_values(array_filter($items, static fn (array $p): bool => (float) $p['rating'] >= $rating));
        }

        $items = self::sort($items, (string) ($filters['sort'] ?? 'relevance'));

        $total   = count($items);
        $perPage = max(1, min($perPage, 48));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));

        return [
            'items'    => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private static function sort(array $items, string $sort): array
    {
        $comparators = [
            'price_asc'  => static fn (array $a, array $b): int => (float) $a['price'] <=> (float) $b['price'],
            'price_desc' => static fn (array $a, array $b): int => (float) $b['price'] <=> (float) $a['price'],
            'rating'     => static fn (array $a, array $b): int => (float) $b['rating'] <=> (float) $a['rating'],
            'newest'     => static fn (array $a, array $b): int => $b['id'] <=> $a['id'],
        ];

        if (isset($comparators[$sort])) {
            usort($items, $comparators[$sort]);
        }

        return $items;
    }

    /**
     * @return list<array<string,mixed>> Replaced by ReviewRepository::publishedForProduct()
     */
    public static function reviewsFor(int $productId): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = View::mock('reviews');

        return array_values(array_filter($rows, static fn (array $r): bool => $r['product_id'] === $productId));
    }

    /**
     * @return list<array<string,mixed>> Replaced by ProductRepository::relatedTo()
     */
    public static function related(array $product, int $limit = 4): array
    {
        $items = array_values(array_filter(
            self::products(),
            static fn (array $p): bool =>
                $p['id'] !== $product['id']
                && ($p['category_slug'] === $product['category_slug']
                    || $p['parent_category_slug'] === $product['parent_category_slug'])
        ));

        return array_slice($items, 0, $limit);
    }

    /** @return list<array<string,mixed>> */
    public static function productsForSeller(string $sellerSlug): array
    {
        return array_values(array_filter(
            self::products(),
            static fn (array $p): bool => $p['seller_slug'] === $sellerSlug
        ));
    }

    /** @return list<array<string,mixed>> Replaced by ProductRepository::featured() */
    public static function featured(int $limit = 8): array
    {
        $items = array_values(array_filter(
            self::products(),
            static fn (array $p): bool => $p['badges'] !== [] || (float) $p['rating'] >= 4.5
        ));

        return array_slice($items, 0, $limit);
    }

    /** @return array<string,mixed> Replaced by CartRepository::currentFor() + CartPricingService */
    public static function cart(): array
    {
        /** @var array<string,mixed> $cart */
        $cart = View::mock('cart');

        return $cart;
    }

    /** Flattened category list for filter dropdowns. @return list<array{slug:string,name:string,depth:int}> */
    public static function categoryOptions(): array
    {
        $options = [];

        foreach (self::categories() as $parent) {
            $options[] = ['slug' => $parent['slug'], 'name' => $parent['name'], 'depth' => 0];

            foreach ($parent['children'] as $child) {
                $options[] = ['slug' => $child['slug'], 'name' => $child['name'], 'depth' => 1];
            }
        }

        return $options;
    }

    /** @return array{slug:string,name:string,parent:?array<string,mixed>}|null */
    public static function findCategory(string $slug): ?array
    {
        foreach (self::categories() as $parent) {
            if ($parent['slug'] === $slug) {
                return ['slug' => $parent['slug'], 'name' => $parent['name'], 'parent' => null, 'node' => $parent];
            }

            foreach ($parent['children'] as $child) {
                if ($child['slug'] === $slug) {
                    return ['slug' => $child['slug'], 'name' => $child['name'], 'parent' => $parent, 'node' => $child];
                }
            }
        }

        return null;
    }
}
