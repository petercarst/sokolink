<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Product reviews.
 *
 * Only reviews tied to a fulfilled seller_order can be written, and the unique
 * key (user_id, product_id, seller_order_id) means one review per purchase -
 * not one per product, because a customer who buys the same oil three times has
 * three legitimate things to say.
 */
final class ReviewRepository extends Repository
{
    protected string $table = 'reviews';

    /** @return list<array<string,mixed>> */
    public function publishedFor(int $productId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        return $this->select(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at, r.seller_reply, r.seller_replied_at,
                    CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.') AS author,
                    1 AS is_verified_purchase
               FROM reviews r
               JOIN users u ON u.id = r.user_id
              WHERE r.product_id = :id AND r.status = 'published'
              ORDER BY r.created_at DESC
              LIMIT {$limit}",
            ['id' => $productId]
        );
    }

    /**
     * Star distribution, always five keys.
     *
     * Missing ratings are filled with zero so a template can draw five bars
     * without checking - a chart that silently omits "no 2-star reviews" reads
     * as though the data is missing rather than the reviews.
     *
     * @return array<int,int>
     */
    public function distributionFor(int $productId): array
    {
        $rows = $this->select(
            "SELECT rating, COUNT(*) AS total
               FROM reviews
              WHERE product_id = :id AND status = 'published'
              GROUP BY rating",
            ['id' => $productId]
        );

        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['total'];
        }

        return $distribution;
    }

    /**
     * Whether this customer may review this purchase.
     *
     * Three conditions, all in SQL: the sub-order is theirs, it reached a state
     * where they actually received the goods, and it contained the product.
     * A review for something never bought is not a moderation problem to catch
     * later; it is one that cannot be created.
     */
    public function canReview(int $userId, int $productId, int $sellerOrderId): bool
    {
        return (int) $this->scalar(
            "SELECT COUNT(*)
               FROM seller_orders so
               JOIN orders o      ON o.id = so.order_id
               JOIN order_items i ON i.seller_order_id = so.id
              WHERE so.id = :sub
                AND o.user_id = :user
                AND i.product_id = :product
                AND so.status IN ('collected', 'delivered', 'completed')",
            ['sub' => $sellerOrderId, 'user' => $userId, 'product' => $productId]
        ) > 0;
    }

    public function alreadyReviewed(int $userId, int $productId, int $sellerOrderId): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM reviews
              WHERE user_id = :user AND product_id = :product AND seller_order_id = :sub',
            ['user' => $userId, 'product' => $productId, 'sub' => $sellerOrderId]
        ) > 0;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertInto('reviews', $data);
    }

    /**
     * A seller's public reply, scoped so a seller can only answer reviews of
     * their own products.
     */
    public function addSellerReply(int $reviewId, int $sellerId, string $reply): int
    {
        return $this->statement(
            'UPDATE reviews r
               JOIN products p ON p.id = r.product_id
                SET r.seller_reply = :reply, r.seller_replied_at = UTC_TIMESTAMP()
              WHERE r.id = :id AND p.seller_id = :seller',
            ['reply' => mb_substr($reply, 0, 800), 'id' => $reviewId, 'seller' => $sellerId]
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function paginateForModeration(?string $status = 'pending', int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = '1 = 1';
        $bindings = [];

        if ($status !== null && $status !== '') {
            $where              = 'r.status = :status';
            $bindings['status'] = $status;
        }

        $total = (int) $this->scalar("SELECT COUNT(*) FROM reviews r WHERE {$where}", $bindings);

        $rows = $this->select(
            "SELECT r.id, r.rating, r.title, r.body, r.status, r.created_at, r.moderation_reason,
                    p.name AS product_name, p.slug AS product_slug,
                    CONCAT(u.first_name, ' ', u.last_name) AS author, u.email AS author_email
               FROM reviews r
               JOIN products p ON p.id = r.product_id
               JOIN users u    ON u.id = r.user_id
              WHERE {$where}
              ORDER BY r.created_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    public function moderate(int $reviewId, string $status, ?string $reason): int
    {
        return $this->updateWhere(
            'reviews',
            ['status' => $status, 'moderation_reason' => $reason],
            'id = :id',
            ['id' => $reviewId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forSeller(int $sellerId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return $this->select(
            "SELECT r.id, r.rating, r.title, r.body, r.created_at,
                    r.seller_reply, r.seller_replied_at,
                    p.name AS product_name, p.slug AS product_slug,
                    CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.') AS author
               FROM reviews r
               JOIN products p ON p.id = r.product_id
               JOIN users u    ON u.id = r.user_id
              WHERE p.seller_id = :seller AND r.status = 'published'
              ORDER BY r.created_at DESC
              LIMIT {$limit}",
            ['seller' => $sellerId]
        );
    }

    public function pendingCount(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
    }
}
