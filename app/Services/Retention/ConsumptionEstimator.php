<?php

declare(strict_types=1);

namespace App\Services\Retention;

use App\Core\Database;
use App\Domain\Enums\ReminderBasis;

/**
 * When is this customer likely to need this again?
 *
 * The brief is explicit: "do not automatically assume that a customer has run
 * out of a product merely because a fixed number of days has passed." So this
 * class does not have a fixed number of days. It has four sources of evidence,
 * ranked, and it refuses to guess when it has none:
 *
 *   1. **observed_interval** - the customer's OWN median gap between buying
 *      this product. Needs two or more purchases. Nothing beats watching what
 *      somebody actually does.
 *   2. **seller_hint** - products.typical_consumption_days, scaled by how much
 *      they bought. Two litres of oil last twice as long as one.
 *   3. **category_default** - categories.default_consumption_days. Weak, but
 *      better than nothing for a first purchase in a well-understood category.
 *   4. **none** - schedule NOTHING. Silence is a valid, tested outcome.
 *
 * The median, not the mean, for the observed interval: one holiday when
 * somebody bought a month early should not drag every future estimate forward.
 *
 * The basis is returned alongside the date and stored on the reminder, so that
 * "why did you message me?" has an answer, and so a bad estimate can be traced
 * to the evidence that produced it rather than to "the algorithm".
 */
final class ConsumptionEstimator
{
    /** Below this, an interval is more likely a mistake than a habit. */
    private const MIN_PLAUSIBLE_DAYS = 3;

    /** Above this, the product is not a routine repurchase. */
    private const MAX_PLAUSIBLE_DAYS = 365;

    /**
     * Estimates the next due date for one customer and product.
     *
     * @return array{
     *     basis: ReminderBasis,
     *     days: ?int,
     *     next_due_at: ?string,
     *     detail: string
     * }
     */
    public function estimate(int $userId, int $productId, int $quantity, string $lastPurchasedAtUtc): array
    {
        $observed = $this->observedIntervalDays($userId, $productId);

        if ($observed !== null) {
            return $this->result(
                ReminderBasis::ObservedInterval,
                $observed,
                $lastPurchasedAtUtc,
                sprintf('Median of this customer own repeat gaps: %d days', $observed)
            );
        }

        $hint = $this->sellerHintDays($productId, $quantity);

        if ($hint !== null) {
            return $this->result(
                ReminderBasis::SellerHint,
                $hint,
                $lastPurchasedAtUtc,
                sprintf('Seller guidance scaled for %d units: %d days', $quantity, $hint)
            );
        }

        $category = $this->categoryDefaultDays($productId, $quantity);

        if ($category !== null) {
            return $this->result(
                ReminderBasis::CategoryDefault,
                $category,
                $lastPurchasedAtUtc,
                sprintf('Category default scaled for %d units: %d days', $quantity, $category)
            );
        }

        // Nothing to go on. This is a real answer, not a failure - and it is
        // the answer for anything nobody has told us about and nobody has
        // bought twice.
        return [
            'basis'       => ReminderBasis::None,
            'days'        => null,
            'next_due_at' => null,
            'detail'      => 'No repeat history, no seller guidance and no category default - nothing is scheduled',
        ];
    }

    /**
     * The customer's own median gap between buying this product.
     *
     * Only counts orders that were actually fulfilled. An order that was
     * cancelled or never collected says nothing about consumption - the
     * customer never had the goods.
     */
    public function observedIntervalDays(int $userId, int $productId): ?int
    {
        $dates = Database::column(
            "SELECT DISTINCT DATE(o.placed_at) AS purchased_on
               FROM orders o
               JOIN seller_orders so ON so.order_id = o.id
               JOIN order_items i    ON i.seller_order_id = so.id
              WHERE o.user_id = :user
                AND i.product_id = :product
                AND so.status IN ('collected', 'delivered', 'completed')
              ORDER BY purchased_on",
            ['user' => $userId, 'product' => $productId]
        );

        if (count($dates) < 2) {
            return null;
        }

        $gaps = [];

        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $previous = strtotime((string) $dates[$i - 1] . ' UTC');
            $current  = strtotime((string) $dates[$i] . ' UTC');

            if ($previous === false || $current === false) {
                continue;
            }

            $days = (int) round(($current - $previous) / 86400);

            // A gap of a day or two is somebody topping up an order, not a
            // consumption cycle. A gap over a year is not a routine at all.
            if ($days >= self::MIN_PLAUSIBLE_DAYS && $days <= self::MAX_PLAUSIBLE_DAYS) {
                $gaps[] = $days;
            }
        }

        if ($gaps === []) {
            return null;
        }

        return $this->median($gaps);
    }

    /**
     * The seller's own guidance, scaled by quantity.
     *
     * A seller saying "a bottle lasts about 30 days" and a customer buying
     * three bottles means ninety days, not thirty. Not scaling is the single
     * most obvious way to message somebody far too early.
     */
    public function sellerHintDays(int $productId, int $quantity): ?int
    {
        $row = Database::selectOne(
            'SELECT typical_consumption_days, is_consumable FROM products WHERE id = :id',
            ['id' => $productId]
        );

        if ($row === null || (int) $row['is_consumable'] !== 1 || $row['typical_consumption_days'] === null) {
            return null;
        }

        $days = (int) $row['typical_consumption_days'] * max(1, $quantity);

        return $this->plausible($days);
    }

    public function categoryDefaultDays(int $productId, int $quantity): ?int
    {
        $row = Database::selectOne(
            'SELECT c.default_consumption_days, p.is_consumable
               FROM products p JOIN categories c ON c.id = p.category_id
              WHERE p.id = :id',
            ['id' => $productId]
        );

        if ($row === null || (int) $row['is_consumable'] !== 1 || $row['default_consumption_days'] === null) {
            return null;
        }

        return $this->plausible((int) $row['default_consumption_days'] * max(1, $quantity));
    }

    /**
     * @return array{basis:ReminderBasis,days:?int,next_due_at:?string,detail:string}
     */
    private function result(ReminderBasis $basis, int $days, string $lastPurchasedAtUtc, string $detail): array
    {
        $last = strtotime($lastPurchasedAtUtc . ' UTC');

        if ($last === false) {
            $last = time();
        }

        // A few days BEFORE the estimate runs out, not on the day. A reminder
        // that arrives the morning after somebody ran out is a reminder they
        // did not need - they have already been to a shop.
        $lead = max(1, (int) round($days * 0.15));

        return [
            'basis'       => $basis,
            'days'        => $days,
            'next_due_at' => gmdate('Y-m-d H:i:s', $last + (($days - $lead) * 86400)),
            'detail'      => $detail . sprintf(' (sent %d days early)', $lead),
        ];
    }

    private function plausible(int $days): ?int
    {
        if ($days < self::MIN_PLAUSIBLE_DAYS || $days > self::MAX_PLAUSIBLE_DAYS) {
            return null;
        }

        return $days;
    }

    /**
     * @param list<int> $values
     */
    private function median(array $values): int
    {
        sort($values);

        $count  = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
