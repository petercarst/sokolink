<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\FulfilmentMethod;
use App\Domain\Enums\OrderStatus;
use RuntimeException;

/**
 * Which order transitions are legal, and who may make them.
 *
 * This is a table, not a pile of if-statements, for one reason: the question
 * "can a seller mark an order collected without the customer's code?" should be
 * answerable by reading a list, not by tracing a call graph.
 *
 * Three things are checked on every transition:
 *
 *   1. Is the target reachable from the current status at all?
 *   2. Does the fulfilment method allow it? `ready_for_pickup` is meaningless
 *      on a delivery order and `out_for_delivery` is meaningless on a pickup.
 *   3. Is this actor allowed to make it? A customer may cancel their own order
 *      while it is still `awaiting_seller`; they may not mark it `delivered`.
 *
 * Failing any of the three throws. Services never "check then set" - they call
 * transition() and let it refuse, so there is exactly one place the rules live.
 */
final class OrderStateMachine
{
    /**
     * from => list of reachable statuses.
     *
     * Terminal states map to an empty list. That is deliberate: `collected`,
     * `delivered`, `refunded` and the cancellations are the end. A correction
     * after the fact is a refund or a support action with its own record, not
     * a quiet edit of history.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        // Before payment clears, only three things can happen.
        'pending_payment' => ['awaiting_seller', 'expired_unpaid', 'cancelled_customer'],

        // Paid, waiting for the seller to accept or reject.
        'awaiting_seller' => ['confirmed', 'rejected_seller', 'cancelled_customer'],

        'confirmed'  => ['preparing', 'rejected_seller', 'cancelled_customer'],
        'preparing'  => ['ready_for_pickup', 'ready_for_dispatch', 'rejected_seller'],

        // ---- Collection branch ----
        'ready_for_pickup'    => ['collected', 'collection_overdue', 'cancelled_customer'],
        'collection_overdue'  => ['collected', 'returned_to_stock'],
        'collected'           => ['completed'],
        'returned_to_stock'   => ['refund_pending'],

        // ---- Delivery branch ----
        'ready_for_dispatch' => ['assigned', 'cancelled_customer'],
        'assigned'           => ['picked_up', 'ready_for_dispatch'],
        'picked_up'          => ['out_for_delivery', 'returned_to_seller'],
        'out_for_delivery'   => ['delivered', 'delivery_failed'],
        'delivery_failed'    => ['out_for_delivery', 'returned_to_seller'],
        'delivered'          => ['completed'],
        'returned_to_seller' => ['refund_pending'],

        // ---- Money ----
        'rejected_seller'    => ['refund_pending'],
        'cancelled_customer' => ['refund_pending'],
        'refund_pending'     => ['refunded'],

        // ---- Terminal ----
        'completed'      => [],
        'refunded'       => [],
        'expired_unpaid' => [],
    ];

    /**
     * Which actors may perform each transition, by target status.
     *
     * `system` covers the scheduled tasks - expiring unpaid orders, marking a
     * collection overdue. `admin_override` is a separate actor type from
     * `admin` on purpose: an override is always recorded as one, so
     * "an administrator forced this" is visible in the history rather than
     * looking like a normal step.
     *
     * @var array<string,list<string>>
     */
    private const ACTORS = [
        'awaiting_seller'    => ['system'],
        'expired_unpaid'     => ['system', 'admin_override'],
        'confirmed'          => ['seller', 'system', 'admin_override'],
        'preparing'          => ['seller', 'admin_override'],
        'ready_for_pickup'   => ['seller', 'admin_override'],
        'ready_for_dispatch' => ['seller', 'admin_override'],

        // Only the seller's store staff can confirm a collection, and only
        // against the customer's code. PickupService enforces the code; this
        // enforces who may even attempt it.
        'collected'          => ['seller', 'admin_override'],
        'collection_overdue' => ['system', 'seller', 'admin_override'],
        'returned_to_stock'  => ['seller', 'admin_override'],

        'assigned'           => ['admin', 'agent', 'system', 'admin_override'],
        'picked_up'          => ['agent', 'admin_override'],
        'out_for_delivery'   => ['agent', 'admin_override'],
        'delivered'          => ['agent', 'admin_override'],
        'delivery_failed'    => ['agent', 'admin_override'],
        'returned_to_seller' => ['agent', 'admin', 'admin_override'],

        'cancelled_customer' => ['customer', 'support', 'admin_override'],
        'rejected_seller'    => ['seller', 'admin_override'],
        'refund_pending'     => ['seller', 'support', 'admin', 'system', 'admin_override'],
        'refunded'           => ['support', 'admin', 'system', 'admin_override'],
        'completed'          => ['system', 'admin_override'],
    ];

    /**
     * Statuses that only make sense for one fulfilment method.
     *
     * @var array<string,string>
     */
    private const METHOD_ONLY = [
        'ready_for_pickup'   => 'pickup',
        'collection_overdue' => 'pickup',
        'collected'          => 'pickup',
        'returned_to_stock'  => 'pickup',
        'ready_for_dispatch' => 'delivery',
        'assigned'           => 'delivery',
        'picked_up'          => 'delivery',
        'out_for_delivery'   => 'delivery',
        'delivered'          => 'delivery',
        'delivery_failed'    => 'delivery',
        'returned_to_seller' => 'delivery',
    ];

    /**
     * Statuses after which stock is no longer reserved but consumed, so a
     * release would hand back units that have physically left the shop.
     *
     * @var list<string>
     */
    private const STOCK_CONSUMED = ['collected', 'delivered', 'completed'];

    /** Statuses where the customer's money should come back. */
    private const REFUNDABLE = [
        'rejected_seller', 'cancelled_customer', 'returned_to_stock',
        'returned_to_seller', 'refund_pending',
    ];

    /**
     * The only way a status changes.
     *
     * Throws DomainRuleException, which extends RuntimeException - so a
     * `catch (RuntimeException)` still works, and the web layer can tell the
     * difference between "you are not allowed to do that", which it shows to
     * the person who tried, and a fault, which it logs and hides behind a
     * reference. Pressing a stale button on a page loaded five minutes ago is
     * the first of those, and it used to produce a 500.
     *
     * @throws DomainRuleException with a message written for the person who
     *         will read it - "This order has already been collected" rather
     *         than "invalid transition collected->preparing".
     */
    public static function assertCan(
        OrderStatus $from,
        OrderStatus $to,
        FulfilmentMethod $method,
        ActorType $actor
    ): void {
        if ($from === $to) {
            throw new DomainRuleException(sprintf('This order is already %s.', mb_strtolower($to->label())));
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if ($allowed === []) {
            throw new DomainRuleException(sprintf(
                'This order is %s and cannot be changed further.',
                mb_strtolower($from->label())
            ));
        }

        if (!in_array($to->value, $allowed, true)) {
            throw new DomainRuleException(sprintf(
                'An order that is %s cannot become %s.',
                mb_strtolower($from->label()),
                mb_strtolower($to->label())
            ));
        }

        $requiredMethod = self::METHOD_ONLY[$to->value] ?? null;

        if ($requiredMethod !== null && $requiredMethod !== $method->value) {
            throw new DomainRuleException(sprintf(
                '"%s" applies to %s orders, and this one is %s.',
                $to->label(),
                $requiredMethod,
                $method->value
            ));
        }

        $actors = self::ACTORS[$to->value] ?? [];

        if (!in_array($actor->value, $actors, true)) {
            $who = str_replace('_', ' ', $actor->value);

            throw new DomainRuleException(sprintf(
                '%s %s cannot mark an order %s.',
                str_contains('aeiou', mb_substr($who, 0, 1)) ? 'An' : 'A',
                $who,
                mb_strtolower($to->label())
            ));
        }
    }

    public static function can(
        OrderStatus $from,
        OrderStatus $to,
        FulfilmentMethod $method,
        ActorType $actor
    ): bool {
        try {
            self::assertCan($from, $to, $method, $actor);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * Everything reachable from here for this method and actor. Used to decide
     * which buttons a dashboard offers - the buttons follow the rules rather
     * than the rules being re-stated in a template.
     *
     * @return list<OrderStatus>
     */
    public static function nextFor(OrderStatus $from, FulfilmentMethod $method, ActorType $actor): array
    {
        $next = [];

        foreach (self::TRANSITIONS[$from->value] ?? [] as $candidate) {
            $to = OrderStatus::from($candidate);

            if (self::can($from, $to, $method, $actor)) {
                $next[] = $to;
            }
        }

        return $next;
    }

    public static function isTerminal(OrderStatus $status): bool
    {
        return (self::TRANSITIONS[$status->value] ?? []) === [];
    }

    /**
     * True once the goods have gone. Reserved stock must be converted to a
     * sale at this point, never released - releasing it would hand back units
     * that are physically out of the shop.
     */
    public static function consumesStock(OrderStatus $status): bool
    {
        return in_array($status->value, self::STOCK_CONSUMED, true);
    }

    /**
     * True when reaching this status should return reserved units to the shelf.
     * A rejection or a cancellation before handover frees the stock; anything
     * after handover does not.
     */
    public static function releasesStock(OrderStatus $status): bool
    {
        return in_array($status->value, [
            'expired_unpaid', 'cancelled_customer', 'rejected_seller',
            'returned_to_stock', 'returned_to_seller',
        ], true);
    }

    public static function requiresRefund(OrderStatus $status): bool
    {
        return in_array($status->value, self::REFUNDABLE, true);
    }

    /** A reason is mandatory when the platform is telling the customer no. */
    public static function requiresReason(OrderStatus $status): bool
    {
        return in_array($status->value, [
            'rejected_seller', 'cancelled_customer', 'delivery_failed', 'returned_to_seller',
        ], true);
    }

    /**
     * The whole table, for documentation and for the test that asserts every
     * status named here exists in the database enum.
     *
     * @return array<string,list<string>>
     */
    public static function table(): array
    {
        return self::TRANSITIONS;
    }

    /** @return array<string,list<string>> */
    public static function actorTable(): array
    {
        return self::ACTORS;
    }
}
