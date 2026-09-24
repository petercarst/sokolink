<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Token;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\OrderStatus;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SellerRepository;
use App\Services\PaymentService;

/**
 * Click and collect.
 *
 * The brief is blunt about this: "do not allow arbitrary users to mark orders
 * as collected". Four separate things enforce it, and none of them is a hidden
 * button:
 *
 *   1. The code is generated once, when the seller marks the order ready, and
 *      the PLAINTEXT is returned exactly once - to be sent to the customer. It
 *      is never stored and never shown again.
 *   2. Only a SHA-256 hash goes in the database. No seller, support agent or
 *      administrator can read a collection code back out; they can only verify
 *      one that is presented, or issue a new one, and issuing is counted.
 *   3. Verification is scoped to the seller who owns the order AND the store it
 *      is waiting at, and it is throttled.
 *   4. The state machine allows `collected` only from `ready_for_pickup` and
 *      only for the seller actor.
 */
final class PickupService
{
    /** Wrong codes allowed before the counter has to call support. */
    private const MAX_VERIFY_ATTEMPTS = 5;

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * Marks an order ready and issues the collection code.
     *
     * @return string the plaintext code - this is the ONLY time it exists.
     *                Send it to the customer and forget it.
     */
    public function markReadyAndIssueCode(int $sellerOrderId, int $sellerId): string
    {
        $sub = $this->requireOwnedBy($sellerOrderId, $sellerId);

        if ((string) $sub['fulfilment_method'] !== 'pickup') {
            throw new DomainRuleException('That order is being delivered, not collected.', 'wrong_method');
        }

        return Database::transaction(function () use ($sellerOrderId, $sub): string {
            $this->orderService->transition($sellerOrderId, OrderStatus::ReadyForPickup, ActorType::Seller);

            $code = Token::code(6);

            $windowHours = (int) Database::scalar(
                'SELECT s.collection_window_hours
                   FROM order_pickups p JOIN stores s ON s.id = p.store_id
                  WHERE p.seller_order_id = :id',
                ['id' => $sellerOrderId]
            ) ?: 72;

            Database::statement(
                'UPDATE order_pickups
                    SET code_hash = :hash,
                        code_issued_at = UTC_TIMESTAMP(),
                        window_from = UTC_TIMESTAMP(),
                        window_to = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :hours HOUR),
                        verify_attempts = 0
                  WHERE seller_order_id = :id',
                ['hash' => Token::hash($code), 'hours' => $windowHours, 'id' => $sellerOrderId]
            );

            $userId = (int) Database::scalar(
                'SELECT user_id FROM orders WHERE id = :id',
                ['id' => (int) $sub['order_id']]
            );

            // The code travels to the customer in the message. It is in the
            // queued payload only long enough to be rendered into one -
            // NotificationRepository::markDelivered scrubs it on delivery, so
            // the only lasting record is the hash in order_pickups.
            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::PickupDelivery,
                'pickup.code_issued',
                [
                    'sub_number'   => (string) $sub['sub_number'],
                    'code'         => $code,
                    'store_name'   => (string) $sub['store_name'],
                    'window_hours' => $windowHours,
                ],
                false,
                'seller_order',
                $sellerOrderId
            );

            // Note what was issued, never the code itself.
            Audit::record(
                'pickup.code.issued',
                'seller_order',
                $sellerOrderId,
                sprintf('Collection code issued, valid %d hours', $windowHours)
            );

            return $code;
        });
    }

    /**
     * Verifies a code presented at the counter and completes the collection.
     *
     * Deliberately returns nothing useful on success beyond "done". A method
     * that returned the stored code, or reported how close a wrong code was,
     * would undo the point of hashing it.
     */
    public function confirmCollection(int $sellerOrderId, int $sellerId, string $presentedCode): void
    {
        $sub = $this->requireOwnedBy($sellerOrderId, $sellerId);

        $pickup = Database::selectOne(
            'SELECT * FROM order_pickups WHERE seller_order_id = :id',
            ['id' => $sellerOrderId]
        );

        if ($pickup === null || $pickup['code_hash'] === null) {
            throw new DomainRuleException(
                'No collection code has been issued for this order yet.',
                'no_code'
            );
        }

        // The attempt is counted OUTSIDE the transaction, and on purpose.
        //
        // Counting it inside would be undone by the very rollback that a wrong
        // code causes - the throttle would reset itself on every failure and
        // guard nothing. Committing the count first means a wrong code costs an
        // attempt whatever happens next.
        //
        // The cap is in the WHERE clause rather than in a read-then-check, so
        // two counters racing on the same order cannot both get the last
        // attempt.
        $counted = Database::statement(
            'UPDATE order_pickups
                SET verify_attempts = verify_attempts + 1, last_attempt_at = UTC_TIMESTAMP()
              WHERE seller_order_id = :id AND verify_attempts < :cap',
            ['id' => $sellerOrderId, 'cap' => self::MAX_VERIFY_ATTEMPTS]
        );

        if ($counted !== 1) {
            throw new DomainRuleException(
                'Too many incorrect codes for this order. Contact support to release it.',
                'locked'
            );
        }

        if (!Token::matches($presentedCode, (string) $pickup['code_hash'])) {
            Audit::record(
                'pickup.code.rejected',
                'seller_order',
                $sellerOrderId,
                sprintf('Wrong code presented (attempt %d)', (int) $pickup['verify_attempts'] + 1)
            );

            throw new DomainRuleException(
                'That code does not match this order. Check the customer message and try again.',
                'wrong_code'
            );
        }

        Database::transaction(function () use ($sellerOrderId, $sub): void {
            // The state machine decides whether `collected` is reachable at
            // all, and whether a seller may set it. This service proves the
            // customer is present; that one decides whether the order is ready
            // to be handed over.
            $this->orderService->transition($sellerOrderId, OrderStatus::Collected, ActorType::Seller);

            Database::statement(
                'UPDATE order_pickups
                    SET collected_at = UTC_TIMESTAMP(), collected_by_user_id = :by
                  WHERE seller_order_id = :id',
                ['by' => \App\Core\Auth::id(), 'id' => $sellerOrderId]
            );

            // The handover IS the payment event for a cash order: the seller
            // has just taken the money across the counter. Recorded before the
            // order is completed, so a completed cash order is never one the
            // books say is unpaid.
            (new PaymentService())->settleCashOnHandover($sellerOrderId, ActorType::Seller);

            $this->orderService->transition($sellerOrderId, OrderStatus::Completed, ActorType::System);

            Audit::record(
                'pickup.collected',
                'seller_order',
                $sellerOrderId,
                sprintf('%s collected at the counter', (string) $sub['sub_number'])
            );
        });
    }

    public function regenerateCode(int $sellerOrderId, int $sellerId): string
    {
        $sub = $this->requireOwnedBy($sellerOrderId, $sellerId);

        return $this->reissue($sub);
    }

    /**
     * The same reissue, asked for by a support agent.
     *
     * The customer rang the desk because the message never arrived; making them
     * ring the seller instead is not support. There is no ownership check here
     * because support has no orders of its own - so the justification is the
     * control, and it is recorded as a sensitive access before anything changes.
     *
     * Support still cannot READ the code. It is hashed, and the new one goes to
     * the customer's own notification channel, never back to the agent's screen.
     * An agent who can read a collection code can collect an order.
     */
    public function regenerateCodeForSupport(int $sellerOrderId, string $justification): void
    {
        if (mb_strlen(trim($justification)) < 5) {
            throw new DomainRuleException(
                'Say why the code is being reissued. It is recorded.',
                'justification_required'
            );
        }

        $sub = Database::selectOne(
            'SELECT so.*, o.order_number FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE so.id = :id',
            ['id' => $sellerOrderId]
        );

        if ($sub === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        Audit::sensitive(
            'pickup.code.reissued_by_support',
            'seller_order',
            $sellerOrderId,
            $justification,
            'Support reissued a collection code'
        );

        $this->reissue($sub);
    }

    /**
     * Issues a replacement code - the customer lost the message.
     *
     * The old code stops working immediately, the count is recorded, and the
     * event is audited. Anyone regenerating a code six times for the same order
     * is a pattern somebody should be able to see.
     *
     * The plaintext is returned so a seller standing at the counter can read it
     * out, and is queued to the customer either way. Nothing stores it.
     *
     * @param array<string,mixed> $sub
     */
    private function reissue(array $sub): string
    {
        $sellerOrderId = (int) $sub['id'];

        if ((string) $sub['fulfilment_method'] !== 'pickup') {
            throw new DomainRuleException(
                'That order is being delivered, so there is no collection code to reissue.',
                'wrong_method'
            );
        }

        if ((string) $sub['status'] !== 'ready_for_pickup' && (string) $sub['status'] !== 'collection_overdue') {
            throw new DomainRuleException(
                'A code can only be reissued while the order is waiting to be collected.',
                'wrong_status'
            );
        }

        return Database::transaction(function () use ($sellerOrderId, $sub): string {
            $code = Token::code(6);

            Database::statement(
                'UPDATE order_pickups
                    SET code_hash = :hash, code_issued_at = UTC_TIMESTAMP(), verify_attempts = 0
                  WHERE seller_order_id = :id',
                ['hash' => Token::hash($code), 'id' => $sellerOrderId]
            );

            $userId = (int) Database::scalar(
                'SELECT user_id FROM orders WHERE id = :id',
                ['id' => (int) $sub['order_id']]
            );

            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::PickupDelivery,
                'pickup.code_reissued',
                ['sub_number' => (string) $sub['sub_number'], 'code' => $code],
                false,
                'seller_order',
                $sellerOrderId
            );

            Audit::record(
                'pickup.code.reissued',
                'seller_order',
                $sellerOrderId,
                'Replacement collection code issued - previous code invalidated'
            );

            return $code;
        });
    }

    /**
     * The collection details a customer sees.
     *
     * Note what is absent: the code. Once issued it exists only in the message
     * that was sent. The page can say a code was issued and when it expires; it
     * cannot show the code, because nothing can read it back.
     *
     * @return array<string,mixed>
     */
    public function collectionDetailsFor(int $sellerOrderId, int $userId): array
    {
        $row = Database::selectOne(
            "SELECT p.code_issued_at, p.window_from, p.window_to, p.collected_at,
                    p.instructions_snapshot, p.verify_attempts,
                    so.sub_number, so.status,
                    s.name AS store_name, s.street, s.district, s.region, s.phone,
                    s.latitude, s.longitude
               FROM order_pickups p
               JOIN seller_orders so ON so.id = p.seller_order_id
               JOIN orders o         ON o.id = so.order_id
               JOIN stores s         ON s.id = p.store_id
              WHERE p.seller_order_id = :id AND o.user_id = :user",
            ['id' => $sellerOrderId, 'user' => $userId]
        );

        if ($row === null) {
            throw new DomainRuleException('That collection could not be found.', 'not_found');
        }

        return array_merge($row, [
            'code_issued'  => $row['code_issued_at'] !== null,
            'is_collected' => $row['collected_at'] !== null,
            'is_overdue'   => $row['window_to'] !== null
                && $row['collected_at'] === null
                && strtotime((string) $row['window_to'] . ' UTC') < time(),
        ]);
    }

    /**
     * Collections whose window has closed, for the scheduled task.
     *
     * Moving them to `collection_overdue` rather than cancelling them: a
     * customer who is two hours late should still be able to collect, and the
     * seller should be able to see which orders are taking up shelf space.
     *
     * @return array{marked:int,orders:list<string>}
     */
    public function markOverdueCollections(int $limit = 100): array
    {
        $overdue = $this->orders->overdueCollections($limit);
        $marked  = [];

        foreach ($overdue as $row) {
            try {
                $this->orderService->transition(
                    (int) $row['id'],
                    OrderStatus::CollectionOverdue,
                    ActorType::System,
                    'The collection window closed on ' . (string) $row['window_to'] . ' UTC'
                );

                $marked[] = (string) $row['sub_number'];
            } catch (DomainRuleException $e) {
                // One order that cannot move must not stop the batch. It is
                // logged and the run continues.
                Audit::record(
                    'pickup.overdue.skipped',
                    'seller_order',
                    (int) $row['id'],
                    $e->getMessage()
                );
            }
        }

        return ['marked' => count($marked), 'orders' => $marked];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireOwnedBy(int $sellerOrderId, int $sellerId): array
    {
        if (!$this->sellers->canTrade($sellerId)) {
            throw new DomainRuleException('Your seller account is not active.', 'seller_inactive');
        }

        $sub = $this->orders->findSellerOrderOwnedBy($sellerOrderId, $sellerId);

        if ($sub === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        return $sub;
    }
}
