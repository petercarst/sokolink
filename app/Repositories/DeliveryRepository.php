<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Enums\DeliveryEventType;
use App\Domain\Enums\DeliveryTaskStatus;

/**
 * Delivery tasks and their event log.
 *
 * `agent_user_id` is the scoping key for the entire delivery role. Every read
 * an agent makes filters on it, in SQL, using the index idx_task_agent_status -
 * so the boundary costs a single indexed lookup and there is never a reason to
 * skip it.
 *
 * `delivery_events` is append-only. Every assignment, scan, attempt and failure
 * is a row; nothing is updated. "Why does the customer say it never arrived?"
 * is answered by reading the log, not by trusting a status column that somebody
 * may have set twice.
 */
final class DeliveryRepository extends Repository
{
    protected string $table = 'delivery_tasks';

    /** @return array<string,mixed>|null */
    public function findByRef(string $taskRef): ?array
    {
        return $this->selectOne('SELECT * FROM delivery_tasks WHERE task_ref = :ref', ['ref' => $taskRef]);
    }

    /**
     * The task for one sub-order, with the assigned agent's name.
     *
     * The name only - never the agent's phone or email. A customer is told who
     * is bringing their parcel; they are not given a way to contact them
     * directly (USER_ROLES_AND_PERMISSIONS.md 4.1).
     *
     * @return array<string,mixed>|null
     */
    public function findForSellerOrder(int $sellerOrderId): ?array
    {
        return $this->selectOne(
            "SELECT t.*,
                    CASE WHEN u.id IS NULL THEN NULL
                         ELSE CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.')
                    END AS agent_name
               FROM delivery_tasks t
               LEFT JOIN users u ON u.id = t.agent_user_id
              WHERE t.seller_order_id = :id",
            ['id' => $sellerOrderId]
        );
    }

    /**
     * A task, but only if it is assigned to this agent.
     *
     * Guessing another agent's task id returns null. An agent has no way to
     * read the recipient, the address or the phone number of a delivery that is
     * not theirs.
     *
     * @return array<string,mixed>|null
     */
    public function findForAgent(int $taskId, int $agentUserId): ?array
    {
        return $this->selectOne(
            "SELECT t.*, so.sub_number, so.status AS order_status, so.fulfilment_method,
                    o.order_number, o.payment_method, o.payment_status,
                    st.name AS store_name, st.street AS store_street, st.district AS store_district,
                    st.phone AS store_phone,
                    z.name AS zone_name
               FROM delivery_tasks t
               JOIN seller_orders so ON so.id = t.seller_order_id
               JOIN orders o         ON o.id = so.order_id
               JOIN stores st        ON st.id = so.store_id
               LEFT JOIN delivery_zones z ON z.id = t.zone_id
              WHERE t.id = :id AND t.agent_user_id = :agent",
            ['id' => $taskId, 'agent' => $agentUserId]
        );
    }

    /**
     * An agent's own tasks.
     *
     * Note what this SELECT does NOT include: the customer's email address,
     * their order history, what else they bought or what they paid. An agent
     * needs a name, a phone number and an address to complete a delivery, and
     * that is what they get.
     *
     * @return list<array<string,mixed>>
     */
    public function forAgent(int $agentUserId, bool $activeOnly = true): array
    {
        $statusFilter = $activeOnly
            ? "AND t.status IN ('assigned', 'picked_up', 'out_for_delivery', 'failed')"
            : '';

        return $this->select(
            "SELECT t.id, t.task_ref, t.status, t.recipient_name, t.recipient_phone,
                    t.address_line, t.landmark, t.instructions, t.fee, t.cod_amount,
                    t.attempts, t.max_attempts, t.assigned_at, t.delivered_at,
                    so.sub_number,
                    st.name AS store_name, st.street AS store_street, st.district AS store_district,
                    z.name AS zone_name
               FROM delivery_tasks t
               JOIN seller_orders so ON so.id = t.seller_order_id
               JOIN stores st        ON st.id = so.store_id
               LEFT JOIN delivery_zones z ON z.id = t.zone_id
              WHERE t.agent_user_id = :agent
                {$statusFilter}
              ORDER BY FIELD(t.status, 'out_for_delivery', 'picked_up', 'assigned', 'failed'), t.assigned_at",
            ['agent' => $agentUserId]
        );
    }

    /**
     * Unassigned tasks in the zones an agent covers - the pool an agent can
     * claim from, when the zone works that way.
     *
     * @return list<array<string,mixed>>
     */
    public function availableForAgent(int $agentUserId): array
    {
        // Note what is NOT selected: recipient_name, recipient_phone,
        // address_line, landmark. An agent deciding whether to take a job needs
        // the zone, the collection point, the weight and the fee - not somebody's
        // name and front door. Leaving those columns out of the query, rather
        // than out of the template, is what makes that true: a later change to
        // the view cannot leak what was never fetched (FR-DEL-04).
        return $this->select(
            "SELECT t.id, t.task_ref, t.fee, t.cod_amount, t.created_at,
                    so.sub_number, so.total AS order_total,
                    (SELECT COUNT(*) FROM order_items i WHERE i.seller_order_id = so.id) AS line_count,
                    st.name AS store_name, st.district AS store_district,
                    z.name AS zone_name, z.assignment_mode
               FROM delivery_tasks t
               JOIN seller_orders so ON so.id = t.seller_order_id
               JOIN stores st        ON st.id = so.store_id
               JOIN delivery_zones z ON z.id = t.zone_id
               JOIN agent_zones az   ON az.zone_id = z.id AND az.user_id = :agent
              WHERE t.status = 'unassigned'
                AND z.assignment_mode = 'pool'
              ORDER BY t.created_at",
            ['agent' => $agentUserId]
        );
    }

    /**
     * Claims a task for an agent.
     *
     * Guarded on the task still being unassigned, and the affected-row count is
     * returned. Two agents pressing "accept" at the same moment means one gets
     * 1 and one gets 0 - and the one that gets 0 is told, rather than both
     * setting off for the same address.
     */
    public function assign(int $taskId, int $agentUserId): int
    {
        return $this->statement(
            "UPDATE delivery_tasks
                SET agent_user_id = :agent, status = 'assigned', assigned_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN ('unassigned', 'offered') AND agent_user_id IS NULL",
            ['agent' => $agentUserId, 'id' => $taskId]
        );
    }

    /**
     * Returns a task to the pool - the agent declined, or an admin reassigned.
     */
    public function unassign(int $taskId): int
    {
        return $this->statement(
            "UPDATE delivery_tasks
                SET agent_user_id = NULL, status = 'unassigned', assigned_at = NULL
              WHERE id = :id AND status IN ('assigned', 'offered')",
            ['id' => $taskId]
        );
    }

    /**
     * Moves a task, guarded on its current status for the same reason every
     * other status change is guarded.
     */
    public function setStatus(int $taskId, DeliveryTaskStatus $from, DeliveryTaskStatus $to): int
    {
        $extra = match ($to) {
            DeliveryTaskStatus::Delivered => ', delivered_at = UTC_TIMESTAMP()',
            default                       => '',
        };

        return $this->statement(
            "UPDATE delivery_tasks SET status = :to{$extra} WHERE id = :id AND status = :from",
            ['to' => $to->value, 'id' => $taskId, 'from' => $from->value]
        );
    }

    public function incrementAttempts(int $taskId): int
    {
        return $this->statement(
            'UPDATE delivery_tasks SET attempts = attempts + 1 WHERE id = :id',
            ['id' => $taskId]
        );
    }

    public function setCodeHash(int $taskId, string $hash): int
    {
        return $this->statement(
            'UPDATE delivery_tasks SET code_hash = :hash WHERE id = :id',
            ['hash' => $hash, 'id' => $taskId]
        );
    }

    /**
     * Records an event. Append-only - there is no update and no delete path.
     */
    public function logEvent(
        int $taskId,
        DeliveryEventType $type,
        ?int $actorUserId,
        ?string $note = null,
        ?string $reasonCode = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?bool $contactedRecipient = null
    ): int {
        return $this->insertInto('delivery_events', [
            'task_id'             => $taskId,
            'event_type'          => $type->value,
            'from_status'         => $fromStatus,
            'to_status'           => $toStatus,
            'reason_code'         => $reasonCode,
            'note'                => $note !== null ? mb_substr($note, 0, 1000) : null,
            'contacted_recipient' => $contactedRecipient === null ? null : ($contactedRecipient ? 1 : 0),
            'actor_user_id'       => $actorUserId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function eventsFor(int $taskId): array
    {
        return $this->select(
            "SELECT e.event_type, e.from_status, e.to_status, e.reason_code, e.note,
                    e.contacted_recipient, e.created_at,
                    CONCAT(COALESCE(u.first_name, 'System'), ' ', COALESCE(u.last_name, '')) AS actor
               FROM delivery_events e
               LEFT JOIN users u ON u.id = e.actor_user_id
              WHERE e.task_id = :id
              ORDER BY e.created_at, e.id",
            ['id' => $taskId]
        );
    }

    /**
     * Tasks waiting to be assigned - the admin dispatch board.
     *
     * @return list<array<string,mixed>>
     */
    public function unassignedTasks(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->select(
            "SELECT t.id, t.task_ref, t.address_line, t.fee, t.created_at, t.zone_id,
                    so.sub_number, so.status AS order_status,
                    st.name AS store_name, st.district AS store_district,
                    z.name AS zone_name, z.assignment_mode
               FROM delivery_tasks t
               JOIN seller_orders so ON so.id = t.seller_order_id
               JOIN stores st        ON st.id = so.store_id
               LEFT JOIN delivery_zones z ON z.id = t.zone_id
              WHERE t.status = 'unassigned'
                AND so.status = 'ready_for_dispatch'
              ORDER BY t.created_at
              LIMIT {$limit}"
        );
    }

    /**
     * An agent's completion record, for their own dashboard.
     *
     * @return array<string,mixed>
     */
    public function statsForAgent(int $agentUserId): array
    {
        return $this->selectOne(
            "SELECT
                COALESCE(SUM(status = 'delivered'), 0) AS delivered,
                COALESCE(SUM(status = 'failed'), 0) AS failed,
                COALESCE(SUM(status IN ('assigned','picked_up','out_for_delivery')), 0) AS active,
                COALESCE(SUM(CASE WHEN status = 'delivered' THEN fee ELSE 0 END), 0) AS fees_earned
               FROM delivery_tasks
              WHERE agent_user_id = :agent",
            ['agent' => $agentUserId]
        ) ?? [];
    }

    public function updateAgentCounters(int $agentUserId, bool $succeeded): int
    {
        $column = $succeeded ? 'deliveries_completed' : 'deliveries_failed';

        return $this->statement(
            "UPDATE delivery_agent_profiles SET {$column} = {$column} + 1 WHERE user_id = :agent",
            ['agent' => $agentUserId]
        );
    }
}
