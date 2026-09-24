<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * The catalogue.
 *
 * Two audiences with different rules, and they are separate methods rather than
 * one method with a flag:
 *
 *   - The public marketplace sees `published` products from `active` sellers
 *     and `published` stores only. Those conditions are in every public query,
 *     never applied afterwards.
 *   - A seller sees their own products in any state, scoped by seller_id.
 *
 * Sorting goes through an allow-list. ORDER BY cannot be parameterised, so a
 * caller-supplied column name would be an injection; an unrecognised key falls
 * back to the default instead of reaching the query.
 */
final class ProductRepository extends Repository
{
    protected string $table = 'products';

    /** @var array<string,string> */
    private const PUBLIC_SORTS = [
        'relevance' => 'p.rating_avg DESC, p.rating_count DESC',
        'newest'    => 'p.created_at DESC',
        'price_asc' => 'p.price ASC',
        'price_desc' => 'p.price DESC',
        'rating'    => 'p.rating_avg DESC, p.rating_count DESC',
        'name'      => 'p.name ASC',
    ];

    /**
     * The visibility rule, written once. Every public read uses it, so
     * "can a suspended seller's product still be bought?" has one answer in one
     * place.
     */
    private const PUBLIC_VISIBILITY = "p.status = 'published' AND sel.status = 'active'";

    /** @return array<string,mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        return $this->selectOne(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                    c.default_consumption_days,
                    sel.id AS seller_id, sel.business_name, sel.slug AS seller_slug,
                    sel.rating_avg AS seller_rating, sel.prep_hours
               FROM products p
               JOIN categories c ON c.id = p.category_id
               JOIN sellers  sel ON sel.id = p.seller_id
              WHERE p.slug = :slug AND ' . self::PUBLIC_VISIBILITY . '
              LIMIT 1',
            ['slug' => $slug]
        );
    }

    /** @return array<string,mixed>|null */
    public function findPublished(int $id): ?array
    {
        return $this->selectOne(
            'SELECT p.*, sel.business_name, sel.prep_hours
               FROM products p
               JOIN sellers sel ON sel.id = p.seller_id
              WHERE p.id = :id AND ' . self::PUBLIC_VISIBILITY,
            ['id' => $id]
        );
    }

    /**
     * The catalogue listing, with filters.
     *
     * @param array{category?:string,min_price?:float,max_price?:float,fulfilment?:string,
     *              in_stock?:bool,seller?:int,sort?:string,q?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function browse(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $p = $this->paginate($page, $perPage, 60);

        $where    = [self::PUBLIC_VISIBILITY];
        $bindings = [];

        if (!empty($filters['category'])) {
            // Includes descendants, so "Groceries" shows what is filed under
            // "Groceries > Cooking oil" as well.
            $where[]              = '(c.slug = :category OR parent.slug = :category2)';
            $bindings['category'] = $filters['category'];
            $bindings['category2'] = $filters['category'];
        }

        if (isset($filters['min_price'])) {
            $where[]               = 'p.price >= :min_price';
            $bindings['min_price'] = $filters['min_price'];
        }

        if (isset($filters['max_price'])) {
            $where[]               = 'p.price <= :max_price';
            $bindings['max_price'] = $filters['max_price'];
        }

        if (($filters['fulfilment'] ?? '') === 'pickup') {
            $where[] = 'p.allows_pickup = 1';
        } elseif (($filters['fulfilment'] ?? '') === 'delivery') {
            $where[] = 'p.allows_delivery = 1';
        }

        if (!empty($filters['seller'])) {
            $where[]            = 'p.seller_id = :seller';
            $bindings['seller'] = (int) $filters['seller'];
        }

        if (isset($filters['min_rating'])) {
            // Products with no reviews have rating_avg 0.00, so they fall out
            // of a "4 stars and up" filter without a special case.
            $where[]                = 'p.rating_avg >= :min_rating';
            $bindings['min_rating'] = (float) $filters['min_rating'];
        }

        if (!empty($filters['q'])) {
            $where[]       = '(p.name LIKE :q OR p.brand LIKE :q2)';
            $bindings['q']  = '%' . $filters['q'] . '%';
            $bindings['q2'] = '%' . $filters['q'] . '%';
        }

        // "In stock" is a fact about inventory, not about the product row, so
        // it is a join condition rather than a status column that would have to
        // be kept in step.
        $having = !empty($filters['in_stock']) ? 'HAVING available > 0' : '';

        $whereSql = implode(' AND ', $where);
        $orderSql = $this->orderBy($filters['sort'] ?? null, self::PUBLIC_SORTS, 'relevance');

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM (
                SELECT p.id, COALESCE(SUM(i.qty_available), 0) AS available
                  FROM products p
                  JOIN categories c   ON c.id = p.category_id
                  LEFT JOIN categories parent ON parent.id = c.parent_id
                  JOIN sellers sel    ON sel.id = p.seller_id
                  LEFT JOIN inventory i ON i.product_id = p.id
                 WHERE {$whereSql}
                 GROUP BY p.id
                 {$having}
             ) AS matched",
            $bindings
        );

        $rows = $this->select(
            "SELECT p.id, p.slug, p.name, p.brand, p.price, p.compare_at_price, p.unit, p.pack_size,
                    p.allows_pickup, p.allows_delivery, p.rating_avg, p.rating_count, p.status,
                    c.name AS category_name, c.slug AS category_slug,
                    sel.business_name, sel.slug AS seller_slug,
                    COALESCE(SUM(i.qty_available), 0) AS available,
                    MIN(img.stored_path) AS image_path
               FROM products p
               JOIN categories c   ON c.id = p.category_id
               LEFT JOIN categories parent ON parent.id = c.parent_id
               JOIN sellers sel    ON sel.id = p.seller_id
               LEFT JOIN inventory i ON i.product_id = p.id
               LEFT JOIN product_images img ON img.product_id = p.id AND img.is_primary = 1
              WHERE {$whereSql}
              GROUP BY p.id
              {$having}
              ORDER BY {$orderSql}
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * Full-text search, ranked by relevance.
     *
     * IN BOOLEAN MODE so a short query still matches - the natural-language
     * mode drops words that appear in more than half the rows, which on a small
     * catalogue means common words match nothing at all.
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $query, int $limit = 24): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 60));

        // BOOLEAN MODE has its own grammar: + - * " ( ) ~ < > @ are operators.
        // A customer searching for `" OR 1=1 --` is not attacking anything -
        // the value is bound - but an unbalanced quote is a SYNTAX ERROR from
        // the fulltext parser, which would be a 500 on a search box. Strip the
        // operators and keep the words.
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query) ?: [];

        // Each surviving word gets a trailing wildcard, so "coo" finds
        // "cooking". Single characters are dropped: they match most of the
        // catalogue and rank nothing usefully.
        $boolean = implode(' ', array_map(
            static fn (string $word): string => $word . '*',
            array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2)
        ));

        if ($boolean === '') {
            return [];
        }

        return $this->select(
            "SELECT p.id, p.slug, p.name, p.brand, p.price, p.unit, p.pack_size,
                    p.rating_avg, p.rating_count,
                    sel.business_name, sel.slug AS seller_slug,
                    COALESCE(SUM(i.qty_available), 0) AS available,
                    MATCH(p.name, p.brand, p.description) AGAINST (:q IN BOOLEAN MODE) AS relevance
               FROM products p
               JOIN sellers sel ON sel.id = p.seller_id
               LEFT JOIN inventory i ON i.product_id = p.id
              WHERE MATCH(p.name, p.brand, p.description) AGAINST (:q2 IN BOOLEAN MODE)
                AND " . self::PUBLIC_VISIBILITY . "
              GROUP BY p.id
              ORDER BY relevance DESC, p.rating_avg DESC
              LIMIT {$limit}",
            ['q' => $boolean, 'q2' => $boolean]
        );
    }

    /**
     * Other products from the same category, for the "you might also need"
     * strip. Excludes the product being viewed, which is the kind of thing that
     * looks fine until the page shows the item you are already on.
     *
     * @return list<array<string,mixed>>
     */
    public function related(int $productId, int $categoryId, int $limit = 4): array
    {
        $limit = max(1, min($limit, 12));

        return $this->select(
            "SELECT p.id, p.slug, p.name, p.price, p.unit, p.pack_size, p.rating_avg,
                    sel.business_name
               FROM products p
               JOIN sellers sel ON sel.id = p.seller_id
              WHERE p.category_id = :category
                AND p.id <> :exclude
                AND " . self::PUBLIC_VISIBILITY . "
              ORDER BY p.rating_avg DESC, p.rating_count DESC
              LIMIT {$limit}",
            ['category' => $categoryId, 'exclude' => $productId]
        );
    }

    // ---- Seller-scoped -----------------------------------------------------

    /**
     * A seller's own products, in any state.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function forSeller(int $sellerId, ?string $status = null, int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = ['p.seller_id = :seller'];
        $bindings = ['seller' => $sellerId];

        if ($status !== null && $status !== '') {
            $where[]            = 'p.status = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $bindings);

        $rows = $this->select(
            "SELECT p.id, p.slug, p.name, p.sku, p.price, p.status, p.updated_at,
                    p.is_consumable, p.typical_consumption_days, p.moderation_reason,
                    c.name AS category_name,
                    COALESCE(SUM(i.qty_available), 0) AS available,
                    MIN(i.low_stock_threshold) AS low_stock_threshold
               FROM products p
               JOIN categories c ON c.id = p.category_id
               LEFT JOIN inventory i ON i.product_id = p.id
              WHERE {$whereSql}
              GROUP BY p.id
              ORDER BY p.updated_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * Fetches a product ONLY if it belongs to this seller.
     *
     * The ownership test is in the WHERE clause, so a seller who guesses
     * another seller's product id gets null - the same answer as for an id that
     * does not exist. There is nothing to distinguish, and nothing leaks.
     *
     * @return array<string,mixed>|null
     */
    public function findOwnedBy(int $productId, int $sellerId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM products WHERE id = :id AND seller_id = :seller',
            ['id' => $productId, 'seller' => $sellerId]
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertInto('products', $data);
    }

    /**
     * Updates a product, scoped to its owner. Returns affected rows, so a
     * caller that gets 0 knows the product was not theirs rather than assuming
     * success.
     *
     * @param array<string,mixed> $data
     */
    public function updateOwned(int $productId, int $sellerId, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        return $this->updateWhere(
            'products',
            $data,
            'id = :id AND seller_id = :seller',
            ['id' => $productId, 'seller' => $sellerId]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM products WHERE slug = :slug';
        $bindings = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql               .= ' AND id <> :except';
            $bindings['except'] = $exceptId;
        }

        return (int) $this->scalar($sql, $bindings) > 0;
    }

    public function skuExistsForSeller(string $sku, int $sellerId, ?int $exceptId = null): bool
    {
        $sql      = 'SELECT COUNT(*) FROM products WHERE sku = :sku AND seller_id = :seller';
        $bindings = ['sku' => $sku, 'seller' => $sellerId];

        if ($exceptId !== null) {
            $sql               .= ' AND id <> :except';
            $bindings['except'] = $exceptId;
        }

        return (int) $this->scalar($sql, $bindings) > 0;
    }

    /**
     * Recalculates the cached rating from the published reviews.
     *
     * Cached because every listing shows it and recomputing an average over
     * every review on every card would be the slowest query on the busiest
     * page. Recalculated from the source rather than incremented, so a hidden
     * or deleted review cannot leave the average permanently wrong.
     */
    public function refreshRating(int $productId): void
    {
        $this->statement(
            "UPDATE products p
                SET p.rating_avg = COALESCE((
                        SELECT ROUND(AVG(r.rating), 2) FROM reviews r
                         WHERE r.product_id = p.id AND r.status = 'published'
                    ), 0),
                    p.rating_count = (
                        SELECT COUNT(*) FROM reviews r
                         WHERE r.product_id = p.id AND r.status = 'published'
                    )
              WHERE p.id = :id",
            ['id' => $productId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function imagesFor(int $productId): array
    {
        return $this->select(
            'SELECT id, stored_path, alt_text, is_primary, sort_order
               FROM product_images
              WHERE product_id = :id
              ORDER BY is_primary DESC, sort_order, id',
            ['id' => $productId]
        );
    }
}
