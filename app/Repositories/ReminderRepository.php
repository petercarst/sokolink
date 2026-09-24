<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Enums\ReminderBasis;
use App\Domain\Enums\ReminderState;
use App\Domain\Enums\SkipReason;
use PDOException;

/**
 * Reorder reminders.
 *
 * The UNIQUE key (user_id, product_id, cycle_key) is the whole duplicate story.
 * The scheduler can run twice, or twenty times; the second insert for a cycle
 * is refused by the database, not by a flag somebody has to remember to check.
 *
 * `basis` and `skip_reason` are what make the engine auditable. Every row says
 * either how the date was estimated or why nothing was sent - so
 * "why did this customer get a message?" and "why did this one not?" both have
 * answers in one query.
 */
final class ReminderRepository extends Repository
{
    protected string $table = 'reorder_reminders';

    /**
     * Schedules a reminder, or does nothing if this cycle already has one.
     *
     * Returns the new id, or null when the unique key refused it. Null is a
     * normal outcome - it means the scheduler has already been here.
     */
    public function schedule(
        int $userId,
        int $productId,
        string $cycleKey,
        ReminderBasis $basis,
        string $basisDetail,
        string $lastPurchasedAtUtc,
        ?string $nextDueAtUtc,
        ?int $sourceSellerOrderId = null
    ): ?int {
        try {
            return $this->insertInto('reorder_reminders', [
                'user_id'                => $userId,
                'product_id'             => $productId,
                'source_seller_order_id' => $sourceSellerOrderId,
                'cycle_key'              => $cycleKey,
                'basis'                  => $basis->value,
                'basis_detail'           => mb_substr($basisDetail, 0, 255),
                'last_purchased_at'      => $lastPurchasedAtUtc,
                'next_due_at'            => $nextDueAtUtc,
                'state'                  => $nextDueAtUtc === null
                    ? ReminderState::NotScheduled->value
                    : ReminderState::Scheduled->value,
                'skip_reason'            => $nextDueAtUtc === null ? SkipReason::InsufficientData->value : null,
            ]);
        } catch (PDOException $e) {
            // 1062 - the cycle already has a reminder. Exactly what the unique
            // key is for; running the scheduler again is a no-op.
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Records that a reminder will not be sent, and why.
     *
     * A row, not an absence of a row. Without it, "we did not message them
     * because they withdrew consent" is an assertion nobody can check.
     */
    public function recordSkip(
        int $userId,
        int $productId,
        string $cycleKey,
        SkipReason $reason,
        string $lastPurchasedAtUtc,
        ReminderBasis $basis = ReminderBasis::None
    ): ?int {
        try {
            return $this->insertInto('reorder_reminders', [
                'user_id'           => $userId,
                'product_id'        => $productId,
                'cycle_key'         => $cycleKey,
                'basis'             => $basis->value,
                'basis_detail'      => 'Not scheduled: ' . $reason->label(),
                'last_purchased_at' => $lastPurchasedAtUtc,
                'next_due_at'       => null,
                'state'             => ReminderState::Skipped->value,
                'skip_reason'       => $reason->value,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Reminders whose date has arrived.
     *
     * @return list<array<string,mixed>>
     */
    public function dueNow(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT r.*, p.name AS product_name, p.slug AS product_slug, p.status AS product_status,
                    u.first_name, u.email
               FROM reorder_reminders r
               JOIN products p ON p.id = r.product_id
               JOIN users u    ON u.id = r.user_id
              WHERE r.state = 'scheduled'
                AND r.next_due_at IS NOT NULL
                AND r.next_due_at <= UTC_TIMESTAMP()
                AND u.status = 'active'
              ORDER BY r.next_due_at
              LIMIT {$limit}"
        );
    }

    public function markSent(int $reminderId, int $notificationId): int
    {
        return $this->statement(
            "UPDATE reorder_reminders
                SET state = 'sent', notification_id = :notification
              WHERE id = :id AND state = 'scheduled'",
            ['notification' => $notificationId, 'id' => $reminderId]
        );
    }

    public function markSkipped(int $reminderId, SkipReason $reason): int
    {
        return $this->statement(
            "UPDATE reorder_reminders
                SET state = 'skipped', skip_reason = :reason, next_due_at = NULL
              WHERE id = :id AND state IN ('scheduled', 'queued')",
            ['reason' => $reason->value, 'id' => $reminderId]
        );
    }

    /**
     * Records that a reminder led to a purchase - the only number that says
     * whether any of this is worth doing.
     */
    public function markConverted(int $reminderId, int $orderId): int
    {
        return $this->statement(
            "UPDATE reorder_reminders
                SET state = 'converted', converted_order_id = :order
              WHERE id = :id AND state IN ('sent', 'scheduled')",
            ['order' => $orderId, 'id' => $reminderId]
        );
    }

    public function snooze(int $reminderId, int $days): int
    {
        return $this->statement(
            "UPDATE reorder_reminders
                SET state = 'scheduled',
                    next_due_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :days DAY)
              WHERE id = :id",
            ['days' => max(1, $days), 'id' => $reminderId]
        );
    }

    /**
     * Closes a reminder because the customer said no more of these.
     */
    public function close(int $reminderId, int $userId): int
    {
        return $this->statement(
            "UPDATE reorder_reminders SET state = 'closed', next_due_at = NULL
              WHERE id = :id AND user_id = :user",
            ['id' => $reminderId, 'user' => $userId]
        );
    }

    /**
     * Has this customer already bought the product again since the reminder was
     * scheduled? If so there is nothing to remind them about.
     */
    public function repurchasedSince(int $userId, int $productId, string $sinceUtc): bool
    {
        return (int) $this->scalar(
            "SELECT COUNT(*)
               FROM orders o
               JOIN seller_orders so ON so.order_id = o.id
               JOIN order_items i    ON i.seller_order_id = so.id
              WHERE o.user_id = :user
                AND i.product_id = :product
                AND o.placed_at > :since
                AND so.status NOT IN ('cancelled_customer', 'rejected_seller', 'expired_unpaid')",
            ['user' => $userId, 'product' => $productId, 'since' => $sinceUtc]
        ) > 0;
    }

    /** When this customer last had ANY reminder - the cooldown reads it. */
    public function lastSentAt(int $userId): ?string
    {
        $when = $this->scalar(
            "SELECT MAX(n.sent_at)
               FROM reorder_reminders r
               JOIN notifications n ON n.id = r.notification_id
              WHERE r.user_id = :user AND r.state IN ('sent', 'converted')",
            ['user' => $userId]
        );

        return is_string($when) ? $when : null;
    }

    public function sentInLastDays(int $userId, int $days): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*)
               FROM reorder_reminders
              WHERE user_id = :user
                AND state IN ('sent', 'converted')
                AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)",
            ['user' => $userId, 'days' => $days]
        );
    }

    /**
     * A customer's own reminder list.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->select(
            "SELECT r.id, r.cycle_key, r.basis, r.basis_detail, r.next_due_at, r.state, r.skip_reason,
                    r.last_purchased_at,
                    p.name AS product_name, p.slug AS product_slug, p.price, p.status AS product_status
               FROM reorder_reminders r
               JOIN products p ON p.id = r.product_id
              WHERE r.user_id = :user AND r.state IN ('scheduled', 'sent', 'snoozed')
              ORDER BY r.next_due_at IS NULL, r.next_due_at",
            ['user' => $userId]
        );
    }

    /**
     * The engine's own report, for the admin screen.
     *
     * The skip breakdown is the interesting half: an engine that sends nothing
     * and an engine that is working correctly look identical without it.
     *
     * @return array{by_state:array<string,int>,by_basis:array<string,int>,by_skip:array<string,int>}
     */
    public function statistics(int $days = 30): array
    {
        $shape = static function (array $rows, string $key): array {
            $out = [];
            foreach ($rows as $row) {
                $out[(string) ($row[$key] ?? 'none')] = (int) $row['total'];
            }

            return $out;
        };

        return [
            'by_state' => $shape($this->select(
                'SELECT state, COUNT(*) AS total FROM reorder_reminders
                  WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
                  GROUP BY state',
                ['days' => $days]
            ), 'state'),
            'by_basis' => $shape($this->select(
                'SELECT basis, COUNT(*) AS total FROM reorder_reminders
                  WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
                  GROUP BY basis',
                ['days' => $days]
            ), 'basis'),
            'by_skip' => $shape($this->select(
                'SELECT skip_reason, COUNT(*) AS total FROM reorder_reminders
                  WHERE skip_reason IS NOT NULL
                    AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)
                  GROUP BY skip_reason',
                ['days' => $days]
            ), 'skip_reason'),
        ];
    }

    /**
     * Candidates the scheduler should consider: fulfilled purchases of
     * consumable products that do not already have a reminder for that cycle.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));

        return $this->select(
            "SELECT o.user_id, i.product_id, i.qty, o.placed_at, so.id AS seller_order_id,
                    p.name AS product_name, p.is_consumable
               FROM orders o
               JOIN seller_orders so ON so.order_id = o.id
               JOIN order_items i    ON i.seller_order_id = so.id
               JOIN products p       ON p.id = i.product_id
               JOIN users u          ON u.id = o.user_id
              WHERE so.status IN ('collected', 'delivered', 'completed')
                AND p.is_consumable = 1
                AND u.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM reorder_reminders r
                     WHERE r.user_id = o.user_id
                       AND r.product_id = i.product_id
                       AND r.cycle_key = CONCAT(so.id, ':', i.product_id)
                )
              ORDER BY o.placed_at DESC
              LIMIT {$limit}"
        );
    }
}
