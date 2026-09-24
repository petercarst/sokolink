<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Database;
use App\Repositories\SettingsRepository;

/**
 * Whether a customer may pay for this basket in cash, and if not, why.
 *
 * Cash is the only method where the platform holds nothing and risks nothing.
 * The seller picks, packs and holds goods for somebody who has committed
 * nothing at all; if that person never turns up, the seller has paid for the
 * picking and lost the shelf space for however long the order sat there. Every
 * other method takes the money first.
 *
 * So cash gets limits the others do not need. Four of them, checked in this
 * order, each with a message written for the customer reading it:
 *
 *   1. **The sellers must accept it.** One order has one payment method, so
 *      cash is offered only when every seller in the basket takes it.
 *   2. **The order must not be too large.** A big cash order is a bigger loss
 *      when it is abandoned, and more change for somebody to find.
 *   3. **Not too many at once.** Somebody with two cash orders already open has
 *      not yet shown they turn up for either.
 *   4. **Not after repeated no-shows.** Enough customer-caused failures inside
 *      the window and the method is withdrawn from that customer - not the
 *      account, just the method. They can still order, and still pay.
 *
 * What counts as a no-show is deliberately narrow: a delivery that failed
 * because nobody was there, would not take it, or could not be reached, and a
 * collection abandoned past its window. A delivery that failed because the
 * parcel was too heavy for the agent, or the weather was unsafe, is not the
 * customer's fault and is not counted. Getting that list wrong would punish
 * people for other people's problems.
 *
 * Every limit is a setting, because the right numbers are a business decision
 * that will change and must not need a deployment to change.
 */
final class CashEligibility
{
    /**
     * Delivery failures the customer caused.
     *
     * Everything not on this list - too_far, too_heavy, unsafe_conditions,
     * damaged_in_transit, timing, busy, wrong_address - is the agent's, the
     * seller's or nobody's problem, and must never count against a customer.
     */
    private const CUSTOMER_FAULT = ['recipient_absent', 'unreachable_phone', 'refused', 'access_denied'];

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {
    }

    /**
     * @param  list<array<string,mixed>> $basketItems rows from CartRepository::itemsFor()
     * @return array{allowed:bool,reason:string}
     */
    public function forBasket(int $userId, array $basketItems, string $orderTotal): array
    {
        $refusingSeller = $this->sellerRefusing($basketItems);

        if ($refusingSeller !== null) {
            return $this->no(sprintf(
                '%s does not accept cash on collection or delivery. Choose another payment method, '
                . 'or order from them separately.',
                $refusingSeller
            ));
        }

        $cap = $this->settings->get('cod.max_order_value', '200000.00');

        if ($this->minor($orderTotal) > $this->minor((string) $cap)) {
            return $this->no(sprintf(
                'Cash is only available on orders up to %s. Choose another payment method for this one.',
                money((string) $cap)
            ));
        }

        $maxOpen = $this->settings->getInt('cod.max_open_orders', 2);
        $open    = $this->openCashOrders($userId);

        if ($open >= $maxOpen) {
            return $this->no(sprintf(
                'You already have %d cash %s on the go. Collect or receive %s before placing another, '
                . 'or pay for this one another way.',
                $open,
                $open === 1 ? 'order' : 'orders',
                $open === 1 ? 'it' : 'them'
            ));
        }

        $maxStrikes = $this->settings->getInt('cod.max_strikes', 2);
        $strikes    = $this->strikes($userId);

        if ($strikes >= $maxStrikes) {
            return $this->no(
                'Cash is not available on this account at the moment, because recent cash orders '
                . 'were not collected or received. You can still order and pay another way, and '
                . 'support can look at this with you.'
            );
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * How many unfinished cash orders this customer has.
     *
     * Unfinished means **the seller is still holding goods for this customer**,
     * which is the exposure the cap exists to limit: anything from
     * awaiting_seller through to ready or out for delivery.
     *
     * Everything else stops counting, including `refund_pending`. A parcel that
     * came back after failed attempts is on the seller's shelf again, not held
     * for anybody - and a refund can sit pending for days, so counting it would
     * quietly lock somebody out of cash for a week over a delivery that failed
     * once. The repeated-failure case is what STRIKES are for, and that failure
     * has already produced one.
     */
    public function openCashOrders(int $userId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*)
               FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE o.user_id = :user
                AND o.payment_method = 'cash'
                AND so.status NOT IN ('completed', 'collected', 'delivered', 'refunded',
                                      'refund_pending', 'expired_unpaid', 'cancelled_customer',
                                      'rejected_seller', 'returned_to_seller')",
            ['user' => $userId]
        );
    }

    /**
     * No-shows inside the window.
     *
     * Two shapes, counted together:
     *
     *   - a delivery that failed for a reason the customer caused
     *   - a collection that was never made and whose window has closed
     *
     * Both are restricted to cash orders. Somebody who fails to collect an
     * order they have already paid for has only inconvenienced themselves.
     */
    public function strikes(int $userId): int
    {
        $days = $this->settings->getInt('cod.strike_window_days', 90);

        $placeholders = [];
        $bindings     = ['user' => $userId, 'user2' => $userId];

        foreach (self::CUSTOMER_FAULT as $i => $reason) {
            $placeholders[]           = ':reason' . $i;
            $bindings['reason' . $i]  = $reason;
        }

        $reasonList = implode(', ', $placeholders);

        return (int) Database::scalar(
            "SELECT
                (SELECT COUNT(DISTINCT de.task_id)
                   FROM delivery_events de
                   JOIN delivery_tasks dt ON dt.id = de.task_id
                   JOIN seller_orders so  ON so.id = dt.seller_order_id
                   JOIN orders o          ON o.id = so.order_id
                  WHERE o.user_id = :user
                    AND o.payment_method = 'cash'
                    AND de.reason_code IN ({$reasonList})
                    AND de.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$days} DAY))
              + (SELECT COUNT(*)
                   FROM order_pickups p
                   JOIN seller_orders so ON so.id = p.seller_order_id
                   JOIN orders o         ON o.id = so.order_id
                  WHERE o.user_id = :user2
                    AND o.payment_method = 'cash'
                    AND p.collected_at IS NULL
                    AND p.window_to IS NOT NULL
                    AND p.window_to < UTC_TIMESTAMP()
                    AND p.window_to >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$days} DAY))
              AS strikes",
            $bindings
        );
    }

    /**
     * The first seller in the basket who will not take cash, or null.
     *
     * By name, because "one of your sellers does not accept cash" sends
     * somebody hunting through their own basket.
     *
     * @param list<array<string,mixed>> $items
     */
    private function sellerRefusing(array $items): ?string
    {
        $sellerIds = array_values(array_unique(array_map(
            static fn (array $i): int => (int) $i['seller_id'],
            $items
        )));

        if ($sellerIds === []) {
            return null;
        }

        $placeholders = [];
        $bindings     = [];

        foreach ($sellerIds as $i => $id) {
            $placeholders[]        = ':seller' . $i;
            $bindings['seller' . $i] = $id;
        }

        $row = Database::selectOne(
            'SELECT business_name FROM sellers
              WHERE id IN (' . implode(', ', $placeholders) . ') AND accepts_cod = 0
              ORDER BY business_name LIMIT 1',
            $bindings
        );

        return $row === null ? null : (string) $row['business_name'];
    }

    /** @return array{allowed:bool,reason:string} */
    private function no(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    /** Money compared as integers, never as floats. */
    private function minor(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
