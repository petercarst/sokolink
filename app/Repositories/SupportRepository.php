<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Support tickets and their message threads.
 *
 * The one thing in this file that must never be got wrong: a customer's view of
 * a thread excludes internal notes **in SQL**. The rows are not fetched and
 * then hidden in a template - they are never fetched.
 *
 * That distinction is the whole control. A template-level hide is one careless
 * refactor, one JSON endpoint or one "export this thread" feature away from
 * showing a customer what staff said about them. A WHERE clause is not.
 */
final class SupportRepository extends Repository
{
    protected string $table = 'support_tickets';

    /** @param array<string,mixed> $data */
    public function createTicket(array $data): int
    {
        return $this->insertInto('support_tickets', $data);
    }

    public function addMessage(
        int $ticketId,
        ?int $authorUserId,
        string $authorRole,
        string $body,
        bool $isInternal = false
    ): int {
        return $this->insertInto('support_messages', [
            'ticket_id'      => $ticketId,
            'author_user_id' => $authorUserId,
            'author_role'    => $authorRole,
            'body'           => mb_substr($body, 0, 3000),
            'is_internal'    => $isInternal ? 1 : 0,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findTicket(int $ticketId): ?array
    {
        return $this->selectOne(
            "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS customer_name, u.email AS customer_email,
                    o.order_number,
                    CONCAT(a.first_name, ' ', a.last_name) AS assignee_name
               FROM support_tickets t
               JOIN users u        ON u.id = t.user_id
               LEFT JOIN orders o  ON o.id = t.order_id
               LEFT JOIN users a   ON a.id = t.assigned_to
              WHERE t.id = :id",
            ['id' => $ticketId]
        );
    }

    /**
     * A ticket by its printed reference.
     *
     * The pages show `SUP-...`, not an id, because that is what a customer
     * quotes on the phone and what an agent reads back. Ids stay internal.
     *
     * @return array<string,mixed>|null
     */
    public function findTicketByRef(string $ref): ?array
    {
        return $this->selectOne(
            'SELECT id FROM support_tickets WHERE ticket_ref = :ref',
            ['ref' => $ref]
        );
    }

    /**
     * A ticket, but only if it belongs to this customer.
     *
     * @return array<string,mixed>|null
     */
    public function findTicketForCustomer(int $ticketId, int $userId): ?array
    {
        return $this->selectOne(
            'SELECT t.*, o.order_number
               FROM support_tickets t
               LEFT JOIN orders o ON o.id = t.order_id
              WHERE t.id = :id AND t.user_id = :user',
            ['id' => $ticketId, 'user' => $userId]
        );
    }

    /**
     * A ticket by its printed reference, but only if it is THIS customer's.
     *
     * The user id is in the WHERE clause rather than checked afterwards, so a
     * reference belonging to somebody else returns null and the page 404s -
     * indistinguishable from a reference that was never issued.
     *
     * @return array<string,mixed>|null
     */
    public function findTicketRefForCustomer(string $ref, int $userId): ?array
    {
        return $this->selectOne(
            'SELECT id FROM support_tickets WHERE ticket_ref = :ref AND user_id = :user',
            ['ref' => $ref, 'user' => $userId]
        );
    }

    /**
     * This customer's own requests, newest activity first.
     *
     * `escalated_to` is selected as a flag rather than a name: that a request
     * has gone to an administrator is worth knowing, WHICH administrator is
     * not the customer's business.
     *
     * @return list<array<string,mixed>>
     */
    public function customerTickets(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));

        return $this->select(
            "SELECT t.id, t.ticket_ref, t.subject, t.category, t.priority, t.status,
                    t.escalated_to, t.created_at, t.updated_at,
                    o.order_number,
                    (SELECT COUNT(*) FROM support_messages m
                      WHERE m.ticket_id = t.id AND m.is_internal = 0) AS message_count,
                    (SELECT m2.body FROM support_messages m2
                      WHERE m2.ticket_id = t.id AND m2.is_internal = 0
                      ORDER BY m2.created_at, m2.id LIMIT 1) AS first_message,
                    TIMESTAMPDIFF(HOUR, t.created_at, UTC_TIMESTAMP()) AS age_hours
               FROM support_tickets t
               LEFT JOIN orders o ON o.id = t.order_id
              WHERE t.user_id = :user
              ORDER BY t.updated_at DESC
              LIMIT {$limit}",
            ['user' => $userId]
        );
    }

    /**
     * The thread AS THE CUSTOMER SEES IT.
     *
     * `is_internal = 0` is in the WHERE clause. Read that line again if you are
     * ever tempted to move the filter into the template.
     *
     * @return list<array<string,mixed>>
     */
    public function customerMessages(int $ticketId, int $userId): array
    {
        return $this->select(
            "SELECT m.id, m.author_role, m.body, m.created_at,
                    CASE WHEN m.author_role = 'customer' THEN 'You' ELSE 'SokoLink support' END AS author_label
               FROM support_messages m
               JOIN support_tickets t ON t.id = m.ticket_id
              WHERE m.ticket_id = :ticket
                AND t.user_id = :user
                AND m.is_internal = 0
              ORDER BY m.created_at, m.id",
            ['ticket' => $ticketId, 'user' => $userId]
        );
    }

    /**
     * The thread as staff see it - everything, including internal notes, each
     * clearly marked so nobody pastes one into a reply by accident.
     *
     * @return list<array<string,mixed>>
     */
    public function staffMessages(int $ticketId): array
    {
        return $this->select(
            "SELECT m.id, m.author_role, m.body, m.is_internal, m.created_at,
                    CONCAT(COALESCE(u.first_name, 'System'), ' ', COALESCE(u.last_name, '')) AS author_name
               FROM support_messages m
               LEFT JOIN users u ON u.id = m.author_user_id
              WHERE m.ticket_id = :ticket
              ORDER BY m.created_at, m.id",
            ['ticket' => $ticketId]
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function forCustomer(int $userId, int $page = 1, int $perPage = 20): array
    {
        $p = $this->paginate($page, $perPage);

        $total = (int) $this->scalar(
            'SELECT COUNT(*) FROM support_tickets WHERE user_id = :user',
            ['user' => $userId]
        );

        $rows = $this->select(
            "SELECT t.id, t.ticket_ref, t.subject, t.category, t.status, t.created_at, t.updated_at,
                    o.order_number,
                    (SELECT COUNT(*) FROM support_messages m
                      WHERE m.ticket_id = t.id AND m.is_internal = 0) AS message_count
               FROM support_tickets t
               LEFT JOIN orders o ON o.id = t.order_id
              WHERE t.user_id = :user
              ORDER BY t.updated_at DESC
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            ['user' => $userId]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    /**
     * The support queue.
     *
     * @param array{status?:string,priority?:string,assigned_to?:int,q?:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function queue(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $p = $this->paginate($page, $perPage);

        $where    = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['status'])) {
            $where[]            = 't.status = :status';
            $bindings['status'] = $filters['status'];
        } elseif (($filters['status'] ?? null) !== '__all') {
            // The default queue is work still to do. A list that includes every
            // closed ticket ever is a list nobody opens.
            $where[] = "t.status NOT IN ('resolved', 'closed')";
        }

        if (!empty($filters['priority'])) {
            $where[]              = 't.priority = :priority';
            $bindings['priority'] = $filters['priority'];
        }

        if (!empty($filters['assigned_to'])) {
            $where[]                 = 't.assigned_to = :assigned';
            $bindings['assigned']    = (int) $filters['assigned_to'];
        }

        // Nobody has picked these up. The most useful panel on the dashboard,
        // because an unassigned ticket is the one at risk of being nobody's.
        if (!empty($filters['unassigned'])) {
            $where[] = 't.assigned_to IS NULL';
        }

        if (!empty($filters['q'])) {
            $where[]       = '(t.subject LIKE :q OR t.ticket_ref LIKE :q2)';
            $bindings['q']  = '%' . $filters['q'] . '%';
            $bindings['q2'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->scalar("SELECT COUNT(*) FROM support_tickets t WHERE {$whereSql}", $bindings);

        $rows = $this->select(
            "SELECT t.id, t.ticket_ref, t.subject, t.category, t.priority, t.status,
                    t.created_at, t.updated_at, t.escalated_to, t.escalation_reason,
                    CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
                    o.order_number,
                    CONCAT(a.first_name, ' ', a.last_name) AS assignee_name,
                    (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS message_count,
                    -- The opening message, so a queue row says what the ticket
                    -- is ABOUT rather than only how many replies it has. One
                    -- subquery beats fetching every thread to show one line.
                    (SELECT m2.body FROM support_messages m2
                      WHERE m2.ticket_id = t.id AND m2.is_internal = 0
                      ORDER BY m2.created_at, m2.id LIMIT 1) AS first_message,
                    TIMESTAMPDIFF(HOUR, t.created_at, UTC_TIMESTAMP()) AS age_hours
               FROM support_tickets t
               JOIN users u       ON u.id = t.user_id
               LEFT JOIN orders o ON o.id = t.order_id
               LEFT JOIN users a  ON a.id = t.assigned_to
              WHERE {$whereSql}
              ORDER BY FIELD(t.priority, 'high', 'normal', 'low'), t.created_at
              LIMIT {$p['limit']} OFFSET {$p['offset']}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $p['page'], 'perPage' => $p['perPage']];
    }

    public function assign(int $ticketId, int $staffUserId): int
    {
        return $this->statement(
            "UPDATE support_tickets
                SET assigned_to = :staff,
                    status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END
              WHERE id = :id",
            ['staff' => $staffUserId, 'id' => $ticketId]
        );
    }

    public function setStatus(int $ticketId, string $status): int
    {
        $extra = in_array($status, ['resolved', 'closed'], true)
            ? ', resolved_at = UTC_TIMESTAMP()'
            : '';

        return $this->statement(
            "UPDATE support_tickets SET status = :status{$extra} WHERE id = :id",
            ['status' => $status, 'id' => $ticketId]
        );
    }

    public function escalate(int $ticketId, int $toUserId, string $reason): int
    {
        return $this->statement(
            "UPDATE support_tickets
                SET status = 'escalated', escalated_to = :to, escalation_reason = :reason, priority = 'high'
              WHERE id = :id",
            ['to' => $toUserId, 'reason' => mb_substr($reason, 0, 1000), 'id' => $ticketId]
        );
    }

    public function touch(int $ticketId): int
    {
        return $this->statement(
            'UPDATE support_tickets SET updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $ticketId]
        );
    }

    public function nextTicketRef(): string
    {
        do {
            $candidate = 'TKT-' . gmdate('y') . '-' . \App\Core\Token::code(6);
        } while ((int) $this->scalar(
            'SELECT COUNT(*) FROM support_tickets WHERE ticket_ref = :r',
            ['r' => $candidate]
        ) > 0);

        return $candidate;
    }

    /**
     * Queue counters for the support dashboard.
     *
     * @return array<string,int>
     */
    /**
     * Any administrator, for escalation.
     *
     * Escalation goes to the role, not to a named person: whoever is on that
     * day picks it up. A ticket addressed to somebody on leave is a ticket
     * nobody answers.
     */
    public function scalarAdminId(): int
    {
        return (int) $this->scalar(
            "SELECT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r       ON r.id = ur.role_id
              WHERE r.role_key = 'admin' AND u.status = 'active'
              ORDER BY u.id LIMIT 1"
        );
    }

    /**
     * Order search for the desk.
     *
     * Returns only what identifies an order - a reference, a status, a date.
     * Nothing about the customer beyond a name, and no amounts. Opening one is
     * a separate, audited act; searching must not be a way to browse.
     *
     * @return list<array<string,mixed>>
     */
    public function searchOrders(string $query, int $limit = 25): array
    {
        $limit = max(1, min($limit, 50));

        return $this->select(
            "SELECT so.sub_number AS ref, o.order_number AS parent_ref, so.status,
                    so.fulfilment_method AS fulfilment, o.payment_status, o.placed_at,
                    so.total, sel.business_name AS seller_name,
                    CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.') AS customer_name
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN users u     ON u.id = o.user_id
               JOIN sellers sel ON sel.id = so.seller_id
              WHERE so.sub_number LIKE :a OR o.order_number LIKE :b OR u.email = :c
                 OR CONCAT(u.first_name, ' ', u.last_name) LIKE :d
              ORDER BY o.placed_at DESC
              LIMIT {$limit}",
            [
                'a' => '%' . $query . '%',
                'b' => '%' . $query . '%',
                'c' => $query,
                'd' => '%' . $query . '%',
            ]
        );
    }

    /**
     * The parts of a parent order, so a ticket can link to each of them.
     *
     * A parent order split between two sellers is two rows here, with two
     * statuses. The ticket panel lists them rather than picking one, because
     * "your order" to a customer is often only the half that went wrong.
     *
     * @return list<array<string,mixed>>
     */
    public function partsForOrder(string $orderNumber): array
    {
        return $this->select(
            "SELECT so.sub_number, so.status, so.total, so.fulfilment_method,
                    sel.business_name AS seller_name
               FROM seller_orders so
               JOIN orders o    ON o.id = so.order_id
               JOIN sellers sel ON sel.id = so.seller_id
              WHERE o.order_number = :number
              ORDER BY so.id",
            ['number' => $orderNumber]
        );
    }

    /**
     * One order part, in full, for an agent who has justified opening it.
     *
     * Support is not scoped to a subset of orders - a desk that can only see
     * some orders cannot answer the phone - so there is no ownership clause
     * here. The control is `SupportService::lookUpOrder()`, which will not call
     * this without a justification and writes the audit row before it does.
     *
     * The store join is LEFT because a store can be archived after an order was
     * placed against it, and an order that has outlived its shop is exactly the
     * kind support gets called about.
     *
     * @return array<string,mixed>|null
     */
    public function subOrderForSupport(string $subNumber): ?array
    {
        return $this->selectOne(
            'SELECT so.*, o.order_number, o.payment_status, o.payment_method, o.placed_at,
                    o.contact_name, o.contact_phone, o.currency, o.user_id,
                    sel.business_name, sel.slug AS seller_slug,
                    st.name AS store_name
               FROM seller_orders so
               JOIN orders o     ON o.id = so.order_id
               JOIN sellers sel  ON sel.id = so.seller_id
               LEFT JOIN stores st ON st.id = so.store_id
              WHERE so.sub_number = :ref',
            ['ref' => $subNumber]
        );
    }

    public function queueCounts(): array
    {
        $rows = $this->select('SELECT status, COUNT(*) AS total FROM support_tickets GROUP BY status');

        $counts = ['open' => 0, 'in_progress' => 0, 'waiting_customer' => 0, 'escalated' => 0, 'resolved' => 0, 'closed' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        $counts['unassigned'] = (int) $this->scalar(
            "SELECT COUNT(*) FROM support_tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed')"
        );

        return $counts;
    }

    /**
     * The order summary support is allowed to see.
     *
     * Deliberately narrow. No payment card details exist anywhere, but this
     * also leaves out the gateway payload and the raw transaction rows - a
     * support agent needs to know whether an order is paid, not how.
     *
     * @return array<string,mixed>|null
     */
    public function orderSummaryForSupport(string $orderNumber): ?array
    {
        return $this->selectOne(
            "SELECT o.order_number, o.payment_status, o.payment_method, o.grand_total, o.currency,
                    o.placed_at, o.paid_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS customer_name,
                    (SELECT GROUP_CONCAT(CONCAT(so.sub_number, ': ', so.status) SEPARATOR ' | ')
                       FROM seller_orders so WHERE so.order_id = o.id) AS parts
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.order_number = :number",
            ['number' => $orderNumber]
        );
    }
}
