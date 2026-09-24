<?php

declare(strict_types=1);

namespace App\Support\View;

use App\Repositories\OrderRepository;

/**
 * An order and its sub-orders, shaped for the pages that show them.
 *
 * A SokoLink order is a parent plus one sub-order per seller (A-03), and every
 * page that displays one - the confirmation, the customer's order detail, the
 * order list - needs the same walk: the parent for the money, the sub-orders
 * for the statuses, the items for the lines. Doing that walk in one place is
 * what stops the confirmation page and the order page disagreeing about how
 * many parts an order has.
 *
 * Amounts are read from the order rows, never recomputed. The order is a record
 * of what was charged; recalculating it here would mean a price change tomorrow
 * silently rewrote what somebody paid today.
 */
final class OrderView
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
    ) {
    }

    /**
     * @param  array<string,mixed>       $order      the parent row
     * @param  list<array<string,mixed>> $subOrders  from sellerOrdersFor()
     * @return array<string,mixed>
     */
    public function present(array $order, array $subOrders): array
    {
        $groups = [];

        foreach ($subOrders as $index => $sub) {
            $subId    = (int) $sub['id'];
            $isPickup = (string) $sub['fulfilment_method'] === 'pickup';

            $groups[] = [
                'seller_order_id' => $subId,
                'sub_number'      => (string) $sub['sub_number'],
                'part'            => $index + 1,
                'seller_id'       => (int) $sub['seller_id'],
                'seller_name'     => (string) $sub['business_name'],
                'seller_slug'     => (string) $sub['seller_slug'],

                'status'              => (string) $sub['status'],
                'selected_fulfilment' => $isPickup ? 'pickup' : 'delivery',

                'store' => $sub['store_id'] === null ? null : [
                    'id'       => (int) $sub['store_id'],
                    'name'     => (string) $sub['store_name'],
                    'street'   => (string) $sub['street'],
                    'district' => (string) $sub['district'],
                    'region'   => (string) $sub['region'],
                    'instructions' => (string) $sub['pickup_instructions'],
                    'collection_window_hours' => (int) $sub['collection_window_hours'],
                ],

                'items'        => $this->lines($subId),
                'subtotal'     => (string) $sub['subtotal'],
                'delivery_fee' => (string) $sub['delivery_fee'],
                'total'        => (string) $sub['total'],
            ];
        }

        return [
            'id'           => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'placed_at'    => (string) $order['placed_at'],
            'expires_at'   => $order['expires_at'] !== null ? (string) $order['expires_at'] : null,

            'payment_status' => (string) $order['payment_status'],
            'payment_method' => (string) $order['payment_method'],
            'currency'       => (string) ($order['currency'] ?? 'TZS'),

            'groups'     => $groups,
            'item_count' => array_sum(array_map(
                static fn (array $g): int => array_sum(array_map(
                    static fn (array $i): int => (int) $i['qty'],
                    $g['items']
                )),
                $groups
            )),

            'totals' => [
                'items_subtotal' => (string) $order['items_subtotal'],
                'delivery_total' => (string) $order['delivery_total'],
                'discount_total' => (string) $order['discount_total'],
                'grand_total'    => (string) $order['grand_total'],
            ],
        ];
    }

    /**
     * The lines of one sub-order.
     *
     * Names, SKUs and pack sizes come from the order's own snapshot columns,
     * not from the product table. A seller renaming a product next month must
     * not change what an old receipt says was bought. The slug is joined in
     * only so the line can still link to the product page, and it is null when
     * the product has since been deleted.
     *
     * @return list<array<string,mixed>>
     */
    private function lines(int $sellerOrderId): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
                'slug'       => $row['product_slug'] !== null ? (string) $row['product_slug'] : null,
                'name'       => (string) $row['name_snapshot'],
                'sku'        => (string) $row['sku_snapshot'],
                'pack_size'  => (string) $row['pack_size_snapshot'],
                'unit_price' => (string) $row['unit_price'],
                'qty'        => (int) $row['qty'],
                'line_total' => (string) $row['line_total'],
                'tone'       => Present::product(['slug' => (string) ($row['product_slug'] ?? '')])['tone'],
            ],
            $this->orders->itemsFor($sellerOrderId)
        );
    }
}
