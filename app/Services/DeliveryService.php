<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Token;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\DeliveryEventType;
use App\Domain\Enums\DeliveryFailureReason;
use App\Domain\Enums\DeliveryTaskStatus;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\OrderStatus;
use App\Repositories\DeliveryRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SellerRepository;
use App\Repositories\ZoneRepository;
use App\Services\PaymentService;

/**
 * Home delivery.
 *
 * Two boundaries matter here and both are enforced in SQL rather than in a
 * template:
 *
 *   - An agent can only see and act on tasks where `agent_user_id` is their own
 *     user id. Guessing a task id returns nothing.
 *   - What an agent sees is a name, a phone number and an address. Not an email
 *     address, not an order history, not what was paid. The SELECT in
 *     DeliveryRepository is the access control.
 *
 * The delivery code works exactly like the collection code: SHA-256 only, the
 * plaintext exists once, and nobody can read it back.
 */
final class DeliveryService
{
    public function __construct(
        private readonly DeliveryRepository $tasks = new DeliveryRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ZoneRepository $zones = new ZoneRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * A seller marking a delivery order ready for dispatch, which issues the
     * delivery code the customer will give the agent.
     *
     * @return string the plaintext code - sent to the customer, never stored
     */
    public function markReadyForDispatch(int $sellerOrderId, int $sellerId): string
    {
        $sub = $this->requireSellerOwns($sellerOrderId, $sellerId);

        if ((string) $sub['fulfilment_method'] !== 'delivery') {
            throw new DomainRuleException('That order is being collected, not delivered.', 'wrong_method');
        }

        return Database::transaction(function () use ($sellerOrderId, $sub): string {
            $this->orderService->transition($sellerOrderId, OrderStatus::ReadyForDispatch, ActorType::Seller);

            $task = $this->tasks->findForSellerOrder($sellerOrderId);

            if ($task === null) {
                throw new DomainRuleException('This order has no delivery task.', 'no_task');
            }

            $code = Token::code(6);
            $this->tasks->setCodeHash((int) $task['id'], Token::hash($code));

            $userId = (int) Database::scalar(
                'SELECT user_id FROM orders WHERE id = :id',
                ['id' => (int) $sub['order_id']]
            );

            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::PickupDelivery,
                'delivery.code_issued',
                ['sub_number' => (string) $sub['sub_number'], 'code' => $code],
                false,
                'seller_order',
                $sellerOrderId
            );

            Audit::record('delivery.code.issued', 'delivery_task', (int) $task['id'], 'Delivery code issued');

            return $code;
        });
    }

    // ---- Assignment ---------------------------------------------------------

    /**
     * An administrator assigning a task to a specific agent.
     *
     * The agent must cover the zone. Assigning a delivery in Kinondoni to an
     * agent who only works Ilala is not a policy question - it is a delivery
     * that will not happen.
     */
    public function assignToAgent(int $taskId, int $agentUserId): void
    {
        Database::transaction(function () use ($taskId, $agentUserId): void {
            $task = $this->tasks->find($taskId);

            if ($task === null) {
                throw new DomainRuleException('That delivery could not be found.', 'not_found');
            }

            if ($task['zone_id'] !== null && !$this->zones->agentCoversZone($agentUserId, (int) $task['zone_id'])) {
                throw new DomainRuleException(
                    'That agent does not cover the zone this delivery is in.',
                    'zone_mismatch'
                );
            }

            if ($this->tasks->assign($taskId, $agentUserId) !== 1) {
                throw new DomainRuleException(
                    'That delivery has already been assigned to someone.',
                    'already_assigned'
                );
            }

            $this->tasks->logEvent($taskId, DeliveryEventType::Assigned, Auth::id(), 'Assigned by an administrator');

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::Assigned,
                ActorType::Admin
            );

            Audit::record('delivery.assigned', 'delivery_task', $taskId, 'Assigned to agent ' . $agentUserId);
        });
    }

    /**
     * An agent claiming a task from the pool.
     *
     * The guarded UPDATE is what makes this safe: two agents accepting the same
     * task means one affected row and one refusal, not two agents at the same
     * address.
     */
    public function claimTask(int $taskId, int $agentUserId): void
    {
        Database::transaction(function () use ($taskId, $agentUserId): void {
            $available = array_filter(
                $this->tasks->availableForAgent($agentUserId),
                static fn (array $t): bool => (int) $t['id'] === $taskId
            );

            if ($available === []) {
                throw new DomainRuleException(
                    'That delivery is not available to you.',
                    'not_available'
                );
            }

            if ($this->tasks->assign($taskId, $agentUserId) !== 1) {
                throw new DomainRuleException(
                    'Another agent took that delivery a moment ago.',
                    'already_assigned'
                );
            }

            $task = $this->tasks->find($taskId);

            $this->tasks->logEvent($taskId, DeliveryEventType::Assigned, $agentUserId, 'Claimed from the pool');

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::Assigned,
                ActorType::Agent
            );
        });
    }

    /** An agent handing a task back. The order returns to ready_for_dispatch. */
    public function declineTask(int $taskId, int $agentUserId, string $reason): void
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        if ((string) $task['status'] !== 'assigned') {
            throw new DomainRuleException(
                'You can only decline a delivery you have not collected yet.',
                'too_late'
            );
        }

        Database::transaction(function () use ($taskId, $agentUserId, $reason, $task): void {
            $this->tasks->logEvent($taskId, DeliveryEventType::Declined, $agentUserId, $reason);
            $this->tasks->unassign($taskId);

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::ReadyForDispatch,
                ActorType::Agent,
                'Agent declined: ' . $reason
            );
        });
    }

    // ---- Agent progress -----------------------------------------------------

    public function markPickedUp(int $taskId, int $agentUserId): void
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        Database::transaction(function () use ($taskId, $agentUserId, $task): void {
            if ($this->tasks->setStatus($taskId, DeliveryTaskStatus::Assigned, DeliveryTaskStatus::PickedUp) !== 1) {
                throw new DomainRuleException('That delivery is not waiting to be collected.', 'wrong_status');
            }

            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::StatusChange,
                $agentUserId,
                'Collected from the seller',
                null,
                'assigned',
                'picked_up'
            );

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::PickedUp,
                ActorType::Agent
            );
        });
    }

    public function markOutForDelivery(int $taskId, int $agentUserId): void
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        Database::transaction(function () use ($taskId, $agentUserId, $task): void {
            if ($this->tasks->setStatus($taskId, DeliveryTaskStatus::PickedUp, DeliveryTaskStatus::OutForDelivery) !== 1) {
                throw new DomainRuleException('Collect the parcel from the seller first.', 'wrong_status');
            }

            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::StatusChange,
                $agentUserId,
                'On the way to the customer',
                null,
                'picked_up',
                'out_for_delivery'
            );

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::OutForDelivery,
                ActorType::Agent
            );
        });
    }

    /**
     * Completes a delivery against the customer's code.
     *
     * The code is the proof of handover. Without it an agent could mark a
     * parcel delivered from anywhere, which is the delivery equivalent of the
     * brief's "do not allow arbitrary users to mark orders as collected".
     */
    public function confirmDelivery(int $taskId, int $agentUserId, string $presentedCode): void
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        if ($task['code_hash'] === null) {
            throw new DomainRuleException(
                'No delivery code has been issued for this parcel yet.',
                'no_code'
            );
        }

        if (!Token::matches($presentedCode, (string) $task['code_hash'])) {
            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::Note,
                $agentUserId,
                'A wrong delivery code was entered'
            );

            throw new DomainRuleException(
                'That code does not match this delivery. Ask the customer to check their message.',
                'wrong_code'
            );
        }

        Database::transaction(function () use ($taskId, $agentUserId, $task): void {
            if ($this->tasks->setStatus($taskId, DeliveryTaskStatus::OutForDelivery, DeliveryTaskStatus::Delivered) !== 1) {
                throw new DomainRuleException('That delivery is not out for delivery.', 'wrong_status');
            }

            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::StatusChange,
                $agentUserId,
                'Delivered and confirmed with the customer code',
                null,
                'out_for_delivery',
                'delivered'
            );

            $this->tasks->updateAgentCounters($agentUserId, true);

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::Delivered,
                ActorType::Agent
            );

            // The doorstep IS the payment event for a cash order: the agent has
            // just taken the money. Recorded before the order is completed, so
            // a completed cash order is never one the books say is unpaid.
            (new PaymentService())->settleCashOnHandover(
                (int) $task['seller_order_id'],
                ActorType::Agent
            );

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::Completed,
                ActorType::System
            );

            Audit::record('delivery.completed', 'delivery_task', $taskId, 'Confirmed with the customer code');
        });
    }

    /**
     * A failed attempt.
     *
     * The reason is mandatory and comes from a fixed list rather than free
     * text, so "why do deliveries fail in this zone?" is a query rather than a
     * reading exercise.
     *
     * Attempts are counted. Once the zone's allowance is used the parcel goes
     * back to the seller rather than being attempted forever.
     */
    public function recordFailedAttempt(
        int $taskId,
        int $agentUserId,
        DeliveryFailureReason $reason,
        string $note = ''
    ): void {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        Database::transaction(function () use ($taskId, $agentUserId, $task, $reason, $note): void {
            $this->tasks->incrementAttempts($taskId);
            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::AttemptFailed,
                $agentUserId,
                $note !== '' ? $note : $reason->label(),
                $reason->value,
                'out_for_delivery',
                'failed'
            );

            $attempts    = (int) $task['attempts'] + 1;
            $maxAttempts = (int) $task['max_attempts'];

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::DeliveryFailed,
                ActorType::Agent,
                $reason->label() . ($note !== '' ? ' - ' . $note : '')
            );

            // The task records the failure first. Skipping straight to
            // returned_to_seller would leave the guarded UPDATE looking for a
            // status the task never had.
            $this->tasks->setStatus($taskId, DeliveryTaskStatus::OutForDelivery, DeliveryTaskStatus::Failed);

            if ($attempts >= $maxAttempts) {
                // Out of attempts. The parcel goes back, the stock comes back,
                // and the money follows - rather than a delivery that is
                // retried indefinitely and a customer nobody tells.
                $this->tasks->setStatus($taskId, DeliveryTaskStatus::Failed, DeliveryTaskStatus::ReturnedToSeller);
                $this->tasks->updateAgentCounters($agentUserId, false);

                $this->orderService->transition(
                    (int) $task['seller_order_id'],
                    OrderStatus::ReturnedToSeller,
                    ActorType::Agent,
                    sprintf('Returned after %d failed attempts', $attempts)
                );

                $this->orderService->transition(
                    (int) $task['seller_order_id'],
                    OrderStatus::RefundPending,
                    ActorType::System,
                    'Undeliverable - returned to the seller'
                );

                return;
            }
        });
    }

    /** An agent retrying after a failed attempt. */
    public function retryDelivery(int $taskId, int $agentUserId): void
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        if ((int) $task['attempts'] >= (int) $task['max_attempts']) {
            throw new DomainRuleException(
                'This parcel has used all its delivery attempts and is going back to the seller.',
                'no_attempts_left'
            );
        }

        Database::transaction(function () use ($taskId, $agentUserId, $task): void {
            if ($this->tasks->setStatus($taskId, DeliveryTaskStatus::Failed, DeliveryTaskStatus::OutForDelivery) !== 1) {
                throw new DomainRuleException('That delivery is not waiting for a retry.', 'wrong_status');
            }

            $this->tasks->logEvent(
                $taskId,
                DeliveryEventType::StatusChange,
                $agentUserId,
                'Trying again',
                null,
                'failed',
                'out_for_delivery'
            );

            $this->orderService->transition(
                (int) $task['seller_order_id'],
                OrderStatus::OutForDelivery,
                ActorType::Agent
            );
        });
    }

    // ---- Reads --------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function myTasks(int $agentUserId, bool $activeOnly = true): array
    {
        return $this->tasks->forAgent($agentUserId, $activeOnly);
    }

    /** @return list<array<string,mixed>> */
    public function availableToClaim(int $agentUserId): array
    {
        return $this->tasks->availableForAgent($agentUserId);
    }

    /** @return array<string,mixed> */
    public function taskDetail(int $taskId, int $agentUserId): array
    {
        $task = $this->requireAgentOwns($taskId, $agentUserId);

        return array_merge($task, ['events' => $this->tasks->eventsFor($taskId)]);
    }

    /** @return array<string,mixed> */
    public function agentSummary(int $agentUserId): array
    {
        return array_merge(
            $this->tasks->statsForAgent($agentUserId),
            ['zones' => $this->zones->zonesForAgent($agentUserId)]
        );
    }

    /**
     * Suggests agents for a task - the dispatch board's shortlist.
     *
     * @return list<array<string,mixed>>
     */
    public function suggestAgentsFor(int $taskId): array
    {
        $task = $this->tasks->find($taskId);

        if ($task === null || $task['zone_id'] === null) {
            return [];
        }

        return $this->zones->availableAgentsIn((int) $task['zone_id']);
    }

    /**
     * @return array<string,mixed>
     * @throws DomainRuleException when the task is not this agent's
     */
    private function requireAgentOwns(int $taskId, int $agentUserId): array
    {
        $task = $this->tasks->findForAgent($taskId, $agentUserId);

        if ($task === null) {
            // The same answer as for a task that does not exist. There is
            // nothing here to tell apart.
            throw new DomainRuleException('That delivery could not be found.', 'not_found');
        }

        return $task;
    }

    /** @return array<string,mixed> */
    private function requireSellerOwns(int $sellerOrderId, int $sellerId): array
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
