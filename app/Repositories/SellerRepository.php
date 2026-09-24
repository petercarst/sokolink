<?php

declare(strict_types=1);

namespace App\Repositories;

final class SellerRepository extends Repository
{
    protected string $table = 'sellers';

    /** @return array<string,mixed>|null */
    public function findByUserId(int $userId): ?array
    {
        return $this->selectOne('SELECT * FROM sellers WHERE user_id = :id', ['id' => $userId]);
    }

    /** @return array<string,mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        return $this->selectOne(
            "SELECT * FROM sellers WHERE slug = :slug AND status = 'active'",
            ['slug' => $slug]
        );
    }

    /**
     * Whether this seller may take orders right now.
     *
     * Holding the seller ROLE is not the same as being allowed to trade. An
     * applicant awaiting approval holds the role so they can see their
     * dashboard; only an `active` row here means goods can be sold. Every
     * seller action checks this rather than the role.
     */
    public function canTrade(int $sellerId): bool
    {
        return (string) $this->scalar(
            'SELECT status FROM sellers WHERE id = :id',
            ['id' => $sellerId]
        ) === 'active';
    }

    public function commissionPercent(int $sellerId): string
    {
        $percent = $this->scalar('SELECT commission_percent FROM sellers WHERE id = :id', ['id' => $sellerId]);

        // Returned as a string: it is a DECIMAL, and casting it to float here
        // would start the rounding drift the schema goes to some trouble to
        // avoid.
        return $percent === null ? '0.00' : (string) $percent;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertInto('sellers', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $sellerId, array $data): int
    {
        return $this->updateWhere('sellers', $data, 'id = :id', ['id' => $sellerId]);
    }

    public function slugExists(string $slug): bool
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM sellers WHERE slug = :slug', ['slug' => $slug]) > 0;
    }

    /**
     * Recalculates a seller's rating from their published product reviews.
     * Derived rather than incremented, for the same reason as the product
     * rating: a hidden review must not leave the average permanently wrong.
     */
    public function refreshRating(int $sellerId): void
    {
        $this->statement(
            "UPDATE sellers s
                SET s.rating_avg = COALESCE((
                        SELECT ROUND(AVG(r.rating), 2)
                          FROM reviews r JOIN products p ON p.id = r.product_id
                         WHERE p.seller_id = s.id AND r.status = 'published'
                    ), 0),
                    s.rating_count = (
                        SELECT COUNT(*)
                          FROM reviews r JOIN products p ON p.id = r.product_id
                         WHERE p.seller_id = s.id AND r.status = 'published'
                    )
              WHERE s.id = :id",
            ['id' => $sellerId]
        );
    }

    // ---- Applications ------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findApplication(int $applicationId): ?array
    {
        return $this->selectOne(
            'SELECT a.*, u.email, u.first_name, u.last_name, u.status AS user_status
               FROM seller_applications a
               JOIN users u ON u.id = a.user_id
              WHERE a.id = :id',
            ['id' => $applicationId]
        );
    }

    /** @return array<string,mixed>|null */
    public function latestApplicationFor(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM seller_applications
              WHERE user_id = :id
              ORDER BY submitted_at DESC, id DESC
              LIMIT 1',
            ['id' => $userId]
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function paginateApplications(?string $status = null, int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = '1 = 1';
        $bindings = [];

        if ($status !== null && $status !== '') {
            $where             = 'a.status = :status';
            $bindings['status'] = $status;
        }

        $total = (int) $this->scalar("SELECT COUNT(*) FROM seller_applications a WHERE {$where}", $bindings);

        $rows = $this->select(
            "SELECT a.id, a.business_name, a.business_type, a.region, a.district, a.store_name,
                    a.status, a.submitted_at, a.decided_at, a.decision_reason,
                    u.email, u.first_name, u.last_name,
                    decider.email AS decided_by_email
               FROM seller_applications a
               JOIN users u ON u.id = a.user_id
               LEFT JOIN users decider ON decider.id = a.decided_by
              WHERE {$where}
              ORDER BY a.submitted_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * Records a decision, guarded on the application still being pending.
     *
     * The guard matters: two administrators opening the same application should
     * not both be able to decide it. The second gets 0 affected rows and is
     * told it has already been handled.
     */
    public function decideApplication(int $applicationId, string $status, int $decidedBy, ?string $reason): int
    {
        return $this->statement(
            "UPDATE seller_applications
                SET status = :status,
                    decision_reason = :reason,
                    decided_by = :decided_by,
                    decided_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = 'pending_approval'",
            [
                'status'     => $status,
                'reason'     => $reason,
                'decided_by' => $decidedBy,
                'id'         => $applicationId,
            ]
        );
    }

    public function pendingApplicationCount(): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM seller_applications WHERE status = 'pending_approval'"
        );
    }
}
