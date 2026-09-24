<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\OrderStatus;

/**
 * Orders, and the seller sub-orders they split into.
 *
 * The shape is the one decision everything else follows from: `orders` holds
 * the customer, the payment and the grand total; `seller_orders` holds a
 * fulfilment status per seller. One basket, one payment, several independent
 * fulfilments.
 *
 * Every scoped read takes its owner id as a parameter and puts it in the WHERE
 * clause. A seller's list is built from `seller_orders.seller_id`; a customer's
 * from `orders.user_id`; an agent's from `delivery_tasks.agent_user_id`. None
 * of those values ever comes from a request.
 */
final class OrderRepository extends Repository
{
    protected string $table = 'orders';

    // ---- Creation ----------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function createOrder(array $data): int
    {
        return $this->insertInto('orders', $data);
    }

    /** @param array<string,mixed> $data */
    public function createSellerOrder(array $data): int
    {
        return $this->insertInto('seller_orders', $data);
    }

    /** @param array<string,mixed> $data */
    public function createItem(array $data): int
    {
        return $this->insertInto('order_items', $data);
    }

    /**
     * Records a transition. Append-only: there is no update and no delete.
     *
     * Called by OrderService after the state machine has allowed the move, so
     * the history records what happened rather than what was attempted.
     */
    public function recordTransition(
        int $sellerOrderId,
        ?OrderStatus $from,
        OrderStatus $to,
        ActorType $actor,
        ?int $actorUserId,
        ?string $reason = null
    ): int {
        return $this->insertInto('order_status_history', [
            'seller_order_id' => $sellerOrderId,
            'from_status'     => $from?->value,
            'to_status'       => $to->value,
            'actor_user_id'   => $actorUserId,
            'actor_type'      => $actor->value,
            'reason'          => $reason !== null ? mb_substr($reason, 0, 1000) : null,
        ]);
    }

    /**
     * Moves a sub-order to a new status, guarded on it still being in the
     * status the caller believed it was.
     *
     * The guard is what stops two dashboards open on the same order from both
     * "accepting" it. The second gets 0 affected rows and is told it has
     * already moved on - rather than overwriting the first.
     */
    public function transitionSellerOrder(
        int $sellerOrderId,
        OrderStatus $from,
        OrderStatus $to,
        ?string $reason = null
    ): int {
        $timestampColumn = match ($to) {
            OrderStatus::Confirmed => 'accepted_at',
            OrderStatus::ReadyForPickup, OrderStatus::ReadyForDispatch => 'ready_at',
            OrderStatus::Completed, OrderStatus::Collected, OrderStatus::Delivered => 'completed_at',
            default => null,
        };

        $extra = $timestampColumn !== null ? ", {$timestampColumn} = UTC_TIMESTAMP()" : '';

        return $this->statement(
            "UPDATE seller_orders
                SET status = :to, status_reason = :reason{$extra}
              WHERE id = :id AND status = :from",
            [
                'to'     => $to->value,
                'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
                'id'     => $sellerOrderId,
                'from'   => $from->value,
            ]
        );
    }

    // ---- Reads -------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findByNumber(string $orderNumber): ?array
    {
        return $this->selectOne(
            'SELECT * FROM orders WHERE order_number = :n',
            ['n' => $orderNumber]
        );
    }

    /**
     * An order, but only if it belongs to this customer.
     *
     * @return array<string,mixed>|null
     */
    public function findForCustomer(string $orderNumber, int $userId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM orders WHERE order_number = :n AND user_id = :user',
            ['n' => $orderNumber, 'user' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findSellerOrder(int $sellerOrderId): ?array
    {
        return $this->selectOne('SELECT * FROM seller_orders WHERE id = :id', ['id' => $sellerOrderId]);
    }

    /**
     * A sub-order, but only if it belongs to this seller.
     *
     * The ownership test is in the WHERE clause, so guessing another seller's
     * sub-order id returns null - the same as an id that does not exist.
     *
     * @return array<string,mixed>|null
     */
    public function findSellerOrderOwnedBy(int $sellerOrderId, int $sellerId): ?array
    {
        return $this->selectOne(
            "SELECT so.*, o.order_number, o.contact_name, o.contact_phone, o.payment_status,
                    o.payment_method, o.placed_at, o.currency,
                    st.name AS store_name, st.district AS store_district
               FROM seller_orders so
               JOIN orders o  ON o.id = so.order_id
               JOIN stores st ON st.id = so.store_id
              WHERE so.id = :id AND so.seller_id = :seller",
            ['id' => $sellerOrderId, 'seller' => $sellerId]
        );
    }

    /**
     * The sub-orders of one parent order, with their line items counted.
     *
     * @return list<array<string,mixed>>
     */
    public function sellerOrdersFor(int $orderId): array
    {
        return $this->select(
            "SELECT so.*, sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name, st.street, st.district, st.region, st.phone AS store_phone,
                    st.pickup_instructions, st.collection_window_hours,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count
               FROM seller_orders so
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
              WHERE so.order_id = :order
              ORDER BY so.id",
            ['order' => $orderId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function itemsFor(int $sellerOrderId): array
    {
        return $this->select(
            'SELECT i.*, p.slug AS product_slug
               FROM order_items i
               LEFT JOIN products p ON p.id = i.product_id
              WHERE i.seller_order_id = :sub
              ORDER BY i.id',
            ['sub' => $sellerOrderId]
        );
    }

    /**
     * The lines of a sub-order, shaped for InventoryService.
     *
     * Used when stock has to be released or consumed, so the two services agree
     * on what a "line" is without either of them reshaping the other's data.
     *
     * @return list<array{product_id:int,store_id:int,quantity:int,name:string}>
     */
    public function stockLinesFor(int $sellerOrderId): array
    {
        $rows = $this->select(
            'SELECT i.product_id, so.store_id, i.qty, i.name_snapshot
               FROM order_items i
               JOIN seller_orders so ON so.id = i.seller_order_id
              WHERE i.seller_order_id = :sub AND i.product_id IS NOT NULL',
            ['sub' => $sellerOrderId]
        );

        return array_map(
            static fn (array $r): array => [
                'product_id' => (int) $r['product_id'],
                'store_id'   => (int) $r['store_id'],
                'quantity'   => (int) $r['qty'],
                'name'       => (string) $r['name_snapshot'],
            ],
            $rows
        );
    }

    /** @return list<array<string,mixed>> */
    public function historyFor(int $sellerOrderId): array
    {
        return $this->select(
            "SELECT h.from_status, h.to_status, h.actor_type, h.reason, h.created_at,
                    CONCAT(COALESCE(u.first_name, 'System'), ' ', COALESCE(u.last_name, '')) AS actor_name
               FROM order_status_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.seller_order_id = :sub
              ORDER BY h.created_at, h.id",
            ['sub' => $sellerOrderId]
        );
    }

    // ---- Customer-scoped ---------------------------------------------------

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function forCustomer(int $userId, ?string $filter = null, int $page = 1, int $perPage = 10): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = ['o.user_id = :user'];
        $bindings = ['user' => $userId];

        if ($filter === 'active') {
            $where[] = "EXISTS (SELECT 1 FROM seller_orders so WHERE so.order_id = o.id
                                 AND so.status NOT IN ('completed','refunded','expired_unpaid',
                                                       'cancelled_customer','rejected_seller'))";
        } elseif ($filter === 'completed') {
            $where[] = "NOT EXISTS (SELECT 1 FROM seller_orders so WHERE so.order_id = o.id
                                     AND so.status NOT IN ('completed','refunded','expired_unpaid',
                                                           'cancelled_customer','rejected_seller'))";
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM orders o WHERE {$whereSql}", $bindings);

        $rows = $this->select(
            "SELECT o.id, o.order_number, o.payment_status, o.payment_method, o.grand_total,
                    o.currency, o.placed_at, o.expires_at,
                    (SELECT COUNT(*) FROM seller_orders so WHERE so.order_id = o.id) AS seller_count,
                    (SELECT COUNT(*) FROM order_items i
                       JOIN seller_orders so ON so.id = i.seller_order_id
                      WHERE so.order_id = o.id) AS line_count,
                    (SELECT GROUP_CONCAT(DISTINCT so.status ORDER BY so.id)
                       FROM seller_orders so WHERE so.order_id = o.id) AS statuses
               FROM orders o
              WHERE {$whereSql}
              ORDER BY o.placed_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * The customer's SUB-orders, which is what their order list shows.
     *
     * One parent order split between two sellers appears as two rows, because
     * the two halves have separate statuses and separate fulfilment (A-03). A
     * single row would have to pick one status and be wrong about the other.
     *
     * Scoped by o.user_id in the SQL. Another customer's sub-order is not
     * fetched and then hidden - it is never selected.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function subOrdersForCustomer(int $userId, ?string $filter = null, int $page = 1, int $perPage = 10): array
    {
        $p = $this->paginate($page, $perPage);

        $closed = "'completed','refunded','expired_unpaid','cancelled_customer','rejected_seller'";

        $where    = ['o.user_id = :user'];
        $bindings = ['user' => $userId];

        if ($filter === 'active') {
            $where[] = "so.status NOT IN ({$closed})";
        } elseif ($filter === 'history') {
            $where[] = "so.status IN ({$closed})";
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE {$whereSql}",
            $bindings
        );

        $rows = $this->select(
            "SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
              WHERE {$whereSql}
              ORDER BY o.placed_at DESC, so.id
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * Every sub-order of one parent order, scoped to the customer who owns it.
     *
     * @return list<array<string,mixed>>
     */
    public function sellerOrdersForCustomer(int $orderId, int $userId): array
    {
        return $this->select(
            'SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
              WHERE so.order_id = :order AND o.user_id = :user
              ORDER BY so.id',
            ['order' => $orderId, 'user' => $userId]
        );
    }

    /**
     * One sub-order by its reference, but only if it belongs to this customer.
     *
     * Returns null for somebody else's reference, so the controller can give
     * the same 404 it gives for a reference that does not exist. "Not yours"
     * and "no such order" must be indistinguishable, or the difference between
     * them is an oracle for guessing valid references.
     *
     * @return array<string,mixed>|null
     */
    public function subOrderForCustomer(string $subNumber, int $userId): ?array
    {
        return $this->selectOne(
            'SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    o.contact_email, o.contact_phone, o.currency,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name, st.street, st.district, st.region
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
              WHERE so.sub_number = :ref AND o.user_id = :user',
            ['ref' => $subNumber, 'user' => $userId]
        );
    }

    /**
     * What this customer has bought before, for the "buy again" list.
     *
     * Only products that are still published and still in stock somewhere:
     * offering a one-tap reorder of something nobody can supply wastes a tap
     * and reads as broken. The price is today's, not what they paid - the
     * point of the panel is to decide whether to buy it again now.
     *
     * Completed fulfilments only. Something cancelled or refunded is not a
     * purchase and should not come back as a suggestion.
     *
     * @return list<array<string,mixed>>
     */
    public function purchaseHistoryFor(int $userId, int $limit = 12): array
    {
        $limit = max(1, min($limit, 50));

        return $this->select(
            "SELECT p.id AS product_id, p.slug, p.name, p.brand, p.pack_size, p.unit,
                    p.price AS current_price, p.is_consumable, p.typical_consumption_days,
                    MAX(o.placed_at) AS last_bought_at,
                    SUM(i.qty)       AS total_bought,
                    COUNT(DISTINCT so.id) AS times_bought,
                    COALESCE((SELECT SUM(inv.qty_available) FROM inventory inv
                               WHERE inv.product_id = p.id), 0) AS available
               FROM order_items i
               JOIN seller_orders so ON so.id = i.seller_order_id
               JOIN orders o         ON o.id = so.order_id
               JOIN products p       ON p.id = i.product_id
               JOIN sellers sel      ON sel.id = p.seller_id
              WHERE o.user_id = :user
                AND so.status IN ('completed', 'collected', 'delivered')
                AND p.status = 'published'
                AND sel.status = 'active'
              GROUP BY p.id
              HAVING available > 0
              ORDER BY last_bought_at DESC
              LIMIT {$limit}",
            ['user' => $userId]
        );
    }

    /**
     * Candidates for the reorder page.
     *
     * DELIBERATELY WIDER than `purchaseHistoryFor()`. That one powers a
     * three-item teaser and filters out anything unbuyable, because suggesting
     * something nobody can supply wastes a tap. This one keeps those rows,
     * because the reorder page's job is to say what CHANGED since last time -
     * and "the seller stopped listing it" is the change somebody most needs to
     * be told about. A page that silently drops it looks like a page that
     * forgot.
     *
     * `last_unit_price` and `last_qty` come from the most recent purchase, so
     * the page can show "you paid X, it is now Y" rather than asserting a
     * change it cannot evidence.
     *
     * @return list<array<string,mixed>>
     */
    public function reorderCandidatesFor(int $userId, int $limit = 24): array
    {
        $limit = max(1, min($limit, 50));

        return $this->select(
            "SELECT p.id AS product_id, p.slug, p.name, p.brand, p.pack_size, p.unit,
                    p.price AS current_price, p.status AS product_status,
                    p.is_consumable, p.typical_consumption_days,
                    sel.business_name AS seller_name, sel.status AS seller_status,
                    MAX(o.placed_at)      AS last_bought_at,
                    SUM(i.qty)            AS total_bought,
                    COUNT(DISTINCT so.id) AS times_bought,
                    -- The BEST SINGLE STORE, not the sum across all of them.
                    -- A basket line is sourced from one counter, so the sum
                    -- would promise a quantity no single line could ever take,
                    -- and the refusal would arrive after the customer pressed
                    -- the button rather than before.
                    COALESCE((SELECT MAX(inv.qty_available) FROM inventory inv
                               WHERE inv.product_id = p.id), 0) AS available,
                    (SELECT i2.unit_price
                       FROM order_items i2
                       JOIN seller_orders so2 ON so2.id = i2.seller_order_id
                       JOIN orders o2         ON o2.id = so2.order_id
                      WHERE i2.product_id = p.id AND o2.user_id = :user2
                      ORDER BY o2.placed_at DESC, i2.id DESC
                      LIMIT 1) AS last_unit_price,
                    (SELECT i3.qty
                       FROM order_items i3
                       JOIN seller_orders so3 ON so3.id = i3.seller_order_id
                       JOIN orders o3         ON o3.id = so3.order_id
                      WHERE i3.product_id = p.id AND o3.user_id = :user3
                      ORDER BY o3.placed_at DESC, i3.id DESC
                      LIMIT 1) AS last_qty
               FROM order_items i
               JOIN seller_orders so ON so.id = i.seller_order_id
               JOIN orders o         ON o.id = so.order_id
               JOIN products p       ON p.id = i.product_id
               JOIN sellers sel      ON sel.id = p.seller_id
              WHERE o.user_id = :user
                AND so.status IN ('completed', 'collected', 'delivered')
              GROUP BY p.id
              ORDER BY last_bought_at DESC
              LIMIT {$limit}",
            ['user' => $userId, 'user2' => $userId, 'user3' => $userId]
        );
    }

    /**
     * What this customer has actually spent, and on how many completed parts.
     *
     * Summed in SQL over the sub-order totals, because a parent order split
     * between two sellers can be completed by one and refunded by the other -
     * adding the parent grand_total would count money that came back.
     *
     * @return array{spend:string,completed:int}
     */
    public function lifetimeSpendFor(int $userId): array
    {
        $row = $this->selectOne(
            "SELECT COALESCE(SUM(so.total), 0) AS spend, COUNT(*) AS completed
               FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE o.user_id = :user
                AND so.status IN ('completed', 'collected', 'delivered')",
            ['user' => $userId]
        );

        return [
            'spend'     => (string) ($row['spend'] ?? '0.00'),
            'completed' => (int) ($row['completed'] ?? 0),
        ];
    }

    // ---- Seller-scoped -----------------------------------------------------

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function forSeller(int $sellerId, ?string $status = null, int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = ['so.seller_id = :seller'];
        $bindings = ['seller' => $sellerId];

        if ($status === 'open') {
            $where[] = "so.status NOT IN ('completed','refunded','expired_unpaid',
                                          'cancelled_customer','rejected_seller')";
        } elseif ($status !== null && $status !== '') {
            $where[]            = 'so.status = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar(
            "SELECT COUNT(*) FROM seller_orders so WHERE {$whereSql}",
            $bindings
        );

        $rows = $this->select(
            "SELECT so.id, so.sub_number, so.status, so.fulfilment_method, so.subtotal,
                    so.delivery_fee, so.total, so.created_at, so.ready_at, so.status_reason,
                    o.order_number, o.contact_name, o.payment_status, o.placed_at, o.currency,
                    st.name AS store_name,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count
               FROM seller_orders so
               JOIN orders o  ON o.id = so.order_id
               JOIN stores st ON st.id = so.store_id
              WHERE {$whereSql}
              ORDER BY so.created_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * Counts by status for a seller's dashboard tiles.
     *
     * @return array<string,int>
     */
    /**
     * A seller's sub-orders, optionally narrowed to a set of statuses.
     *
     * The dashboard groups statuses into tabs - "incoming" is awaiting_seller
     * plus confirmed - so this takes a list rather than one value. An empty
     * list would match nothing, so null means "all" and a list means exactly
     * those.
     *
     * Scoped by seller_id in the WHERE clause, like every other seller read.
     *
     * @param  list<string>|null $statuses
     * @return list<array<string,mixed>>
     */
    public function forSellerInStatuses(int $sellerId, ?array $statuses = null, int $limit = 100): array
    {
        $limit    = max(1, min($limit, 200));
        $where    = ['so.seller_id = :seller'];
        $bindings = ['seller' => $sellerId];

        if ($statuses !== null) {
            $placeholders = [];

            foreach (array_values($statuses) as $i => $status) {
                $placeholders[]        = ':status' . $i;
                $bindings['status' . $i] = $status;
            }

            // An empty allow-list means nothing matches, and saying so in SQL
            // is safer than building `IN ()`, which is a syntax error.
            $where[] = $placeholders === [] ? '1 = 0' : 'so.status IN (' . implode(', ', $placeholders) . ')';
        }

        $whereSql = implode(' AND ', $where);

        return $this->select(
            "SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    o.contact_name, o.contact_phone,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name,
                    pk.window_to AS pickup_window_to,
                    pk.code_issued_at AS pickup_code_issued_at,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
               LEFT JOIN order_pickups pk ON pk.seller_order_id = so.id
              WHERE {$whereSql}
              ORDER BY so.updated_at DESC, so.id DESC
              LIMIT {$limit}",
            $bindings
        );
    }

    /**
     * One sub-order by reference, but only if it belongs to this seller.
     *
     * Returns null for another seller's reference, so the controller gives the
     * same 404 it gives for one that does not exist.
     *
     * @return array<string,mixed>|null
     */
    public function subOrderForSeller(string $subNumber, int $sellerId): ?array
    {
        return $this->selectOne(
            'SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    o.contact_name, o.contact_phone, o.currency,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name, st.street, st.district, st.region
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
               JOIN stores st   ON st.id = so.store_id
              WHERE so.sub_number = :ref AND so.seller_id = :seller',
            ['ref' => $subNumber, 'seller' => $sellerId]
        );
    }

    /**
     * Revenue per day, for the dashboard chart.
     *
     * Completed fulfilments only, and every day in the window appears even when
     * nothing sold - a chart that silently skips empty days misrepresents a
     * quiet week as a short one.
     *
     * @return list<array{date:string,label:string,revenue:string,orders:int}>
     */
    public function dailyRevenueForSeller(int $sellerId, int $days = 14): array
    {
        $days = max(1, min($days, 90));

        $rows = $this->select(
            "SELECT DATE(o.placed_at) AS day, SUM(so.total) AS revenue, COUNT(*) AS orders
               FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE so.seller_id = :seller
                AND so.status IN ('completed', 'collected', 'delivered')
                AND o.placed_at >= DATE_SUB(UTC_DATE(), INTERVAL {$days} DAY)
              GROUP BY DATE(o.placed_at)",
            ['seller' => $sellerId]
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = $row;
        }

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = gmdate('Y-m-d', strtotime('-' . $i . ' days', strtotime(gmdate('Y-m-d'))));
            $row  = $byDay[$date] ?? null;

            $series[] = [
                'date'    => $date,
                // Short weekday, in the DISPLAY timezone: a chart labelled in
                // UTC would put an evening order on the wrong bar.
                'label'   => Clock::local($date . ' 12:00:00')->format('D'),
                'revenue' => $row === null ? '0.00' : (string) $row['revenue'],
                'orders'  => $row === null ? 0 : (int) $row['orders'],
            ];
        }

        return $series;
    }

    /**
     * What this seller has actually earned, and on how many completed parts.
     *
     * Their own sub-order totals, not the parent order's — a basket split
     * between two sellers belongs to neither of them whole.
     *
     * @return array{revenue:string,completed:int}
     */
    public function sellerRevenue(int $sellerId): array
    {
        $row = $this->selectOne(
            "SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS completed
               FROM seller_orders
              WHERE seller_id = :seller
                AND status IN ('completed', 'collected', 'delivered')",
            ['seller' => $sellerId]
        );

        return [
            'revenue'   => (string) ($row['revenue'] ?? '0.00'),
            'completed' => (int) ($row['completed'] ?? 0),
        ];
    }

    public function statusCountsForSeller(int $sellerId): array
    {
        $rows = $this->select(
            'SELECT status, COUNT(*) AS total FROM seller_orders WHERE seller_id = :seller GROUP BY status',
            ['seller' => $sellerId]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // ---- Payment state ------------------------------------------------------

    /**
     * Marks an order paid, guarded so a replayed gateway callback cannot pay it
     * twice. Returns affected rows: 0 means it was already paid, which the
     * caller treats as success rather than an error.
     */
    /**
     * Marks an order paid, from whichever of the two things can do that.
     *
     * The guard is the point: only an order actually waiting for money can
     * become paid. It is what makes a replayed webhook affect zero rows instead
     * of overwriting a refund, and it returns the affected count so a caller
     * can tell the difference.
     *
     * `pending_cod` is in the list because cash settles at the handover rather
     * than through a callback. Leaving it out - which it was - meant every cash
     * order stayed unpaid for ever: collected, delivered, complete, and still
     * recorded as owing money.
     */
    public function markPaid(int $orderId): int
    {
        return $this->statement(
            "UPDATE orders
                SET payment_status = 'paid', paid_at = UTC_TIMESTAMP(), expires_at = NULL
              WHERE id = :id AND payment_status IN ('pending', 'processing', 'pending_cod')",
            ['id' => $orderId]
        );
    }

    public function setPaymentStatus(int $orderId, string $status): int
    {
        return $this->updateWhere('orders', ['payment_status' => $status], 'id = :id', ['id' => $orderId]);
    }

    /**
     * Orders that were never paid and have run out of time.
     *
     * Read by the scheduled task. The LIMIT keeps one run bounded - a backlog
     * should take several runs rather than one very long one that holds locks
     * across every expired order on the platform.
     *
     * @return list<array<string,mixed>>
     */
    public function expiredUnpaid(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT id, order_number, user_id
               FROM orders
              WHERE payment_status = 'pending'
                AND expires_at IS NOT NULL
                AND expires_at < UTC_TIMESTAMP()
              ORDER BY expires_at
              LIMIT {$limit}"
        );
    }

    /**
     * Sub-orders ready for collection whose window has closed.
     *
     * @return list<array<string,mixed>>
     */
    public function overdueCollections(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT so.id, so.sub_number, so.order_id, o.user_id, p.window_to
               FROM seller_orders so
               JOIN orders o        ON o.id = so.order_id
               JOIN order_pickups p ON p.seller_order_id = so.id
              WHERE so.status = 'ready_for_pickup'
                AND p.window_to IS NOT NULL
                AND p.window_to < UTC_TIMESTAMP()
              ORDER BY p.window_to
              LIMIT {$limit}"
        );
    }

    public function nextOrderNumber(): string
    {
        // Year plus six random characters. Sequential numbers leak how many
        // orders the platform takes, and a customer service call is easier with
        // something short than with a UUID.
        do {
            $candidate = sprintf('SL-%s-%s', gmdate('Y'), \App\Core\Token::code(6));
        } while ($this->findByNumber($candidate) !== null);

        return $candidate;
    }

    /**
     * Totals for the admin dashboard. Deliberately excludes unpaid and refunded
     * orders from revenue - counting money that never arrived is how a
     * dashboard becomes something nobody trusts.
     *
     * @return array<string,mixed>
     */
    public function platformTotals(int $days = 30): array
    {
        return $this->selectOne(
            "SELECT
                COUNT(DISTINCT o.id) AS orders,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.grand_total ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders,
                COALESCE(SUM(CASE WHEN o.payment_status IN ('refunded','partially_refunded') THEN 1 ELSE 0 END), 0) AS refunded_orders,
                COALESCE(AVG(CASE WHEN o.payment_status = 'paid' THEN o.grand_total END), 0) AS average_order
               FROM orders o
              WHERE o.placed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)",
            ['days' => $days]
        ) ?? [];
    }
}
