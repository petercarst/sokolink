<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Validator;
use App\Core\Exceptions\ValidationException;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SupportRepository;
use App\Support\View\SubOrderView;

/**
 * Customer support.
 *
 * Two boundaries, both real:
 *
 *   - **A customer sees their own tickets, and never an internal note.** The
 *     note filter is in the repository's SQL.
 *   - **Support does not get unrestricted access to everything.** They can look
 *     up an order summary and act on tickets; they cannot read payment payloads
 *     and cannot change an order's status outside the transitions the state
 *     machine allows a support actor to make.
 *
 * Looking up a customer's order is audited with a justification. "Why did
 * support open this record?" should have an answer, and the way to guarantee
 * one is to require it at the point of access rather than hope for a note.
 */
final class SupportService
{
    public function __construct(
        private readonly SupportRepository $support = new SupportRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * A customer opening a ticket.
     *
     * @param array<string,mixed> $input
     * @return array{ticket_id:int,ticket_ref:string}
     */
    public function openTicket(int $userId, array $input): array
    {
        $v = Validator::make($input, [
            'subject'      => 'required|max:190',
            'category'     => 'required|in:order,collection,delivery,payment,account,seller,other',
            'body'         => 'required|min:10|max:3000',
            'order_number' => 'nullable|max:32',
        ], [
            'subject' => 'Subject',
            'body'    => 'Message',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        $clean = $v->validated();

        // An order reference is only accepted if it is THEIRS. Otherwise a
        // ticket becomes a way to ask about somebody else's order.
        $orderId = null;
        if (!empty($clean['order_number'])) {
            $order = $this->orders->findForCustomer((string) $clean['order_number'], $userId);

            if ($order === null) {
                throw ValidationException::forField(
                    'order_number',
                    'We cannot find that order on your account. Check the number.'
                );
            }

            $orderId = (int) $order['id'];
        }

        return Database::transaction(function () use ($userId, $clean, $orderId): array {
            $ref = $this->support->nextTicketRef();

            $ticketId = $this->support->createTicket([
                'ticket_ref' => $ref,
                'user_id'    => $userId,
                'order_id'   => $orderId,
                'category'   => $clean['category'],
                'subject'    => $clean['subject'],
                'priority'   => 'normal',
                'status'     => 'open',
            ]);

            $this->support->addMessage($ticketId, $userId, 'customer', (string) $clean['body']);

            Audit::record('support.ticket.opened', 'support_ticket', $ticketId, $ref);

            return ['ticket_id' => $ticketId, 'ticket_ref' => $ref];
        });
    }

    /**
     * This customer's requests.
     *
     * @return list<array<string,mixed>>
     */
    public function customerTickets(int $userId): array
    {
        return $this->support->customerTickets($userId);
    }

    /**
     * Resolves a reference to a ticket this customer owns, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findTicketRefForCustomer(string $ref, int $userId): ?array
    {
        return $this->support->findTicketRefForCustomer($ref, $userId);
    }

    /**
     * The customer's own view of a thread.
     *
     * @return array{ticket:array<string,mixed>,messages:list<array<string,mixed>>}
     */
    public function customerThread(int $ticketId, int $userId): array
    {
        $ticket = $this->support->findTicketForCustomer($ticketId, $userId);

        if ($ticket === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        return [
            'ticket'   => $ticket,
            'messages' => $this->support->customerMessages($ticketId, $userId),
        ];
    }

    public function customerReply(int $ticketId, int $userId, string $body): void
    {
        $ticket = $this->support->findTicketForCustomer($ticketId, $userId);

        if ($ticket === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        if (in_array((string) $ticket['status'], ['closed'], true)) {
            throw new DomainRuleException(
                'This ticket is closed. Open a new one and we will pick it up.',
                'closed'
            );
        }

        if (mb_strlen(trim($body)) < 2) {
            throw new DomainRuleException('Write a message before sending.', 'empty');
        }

        Database::transaction(function () use ($ticketId, $userId, $body): void {
            $this->support->addMessage($ticketId, $userId, 'customer', $body);

            // A customer reply puts the ticket back on the queue. Leaving it in
            // waiting_customer is how a reply sits unread for a week.
            $this->support->setStatus($ticketId, 'open');
            $this->support->touch($ticketId);
        });
    }

    // ---- Staff --------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,perPage:int}
     */
    public function queue(array $filters = [], int $page = 1): array
    {
        return $this->support->queue($filters, $page);
    }

    /**
     * The staff view: the whole thread, internal notes marked as such.
     *
     * @return array{ticket:array<string,mixed>,messages:list<array<string,mixed>>,
     *               order:?array<string,mixed>,parts:list<array<string,mixed>>}
     */
    public function staffThread(int $ticketId): array
    {
        $ticket = $this->support->findTicket($ticketId);

        if ($ticket === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        $order = null;
        $parts = [];

        if ($ticket['order_number'] !== null) {
            $order = $this->support->orderSummaryForSupport((string) $ticket['order_number']);
            $parts = $this->support->partsForOrder((string) $ticket['order_number']);
        }

        return [
            'ticket'   => $ticket,
            'messages' => $this->support->staffMessages($ticketId),
            'order'    => $order,
            'parts'    => $parts,
        ];
    }

    /**
     * A staff reply.
     *
     * $internal decides whether the customer ever sees it. It defaults to
     * false - the safe default for a reply is that the person you are replying
     * to can read it.
     */
    public function staffReply(int $ticketId, int $staffUserId, string $body, bool $internal = false): void
    {
        $ticket = $this->support->findTicket($ticketId);

        if ($ticket === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        if (mb_strlen(trim($body)) < 2) {
            throw new DomainRuleException('Write a message before sending.', 'empty');
        }

        $role = Auth::hasRole('admin') ? 'admin' : 'support';

        Database::transaction(function () use ($ticket, $ticketId, $staffUserId, $body, $internal, $role): void {
            $this->support->addMessage($ticketId, $staffUserId, $role, $body, $internal);

            if (!$internal) {
                $this->support->setStatus($ticketId, 'waiting_customer');

                $this->notifications->queue(
                    (int) $ticket['user_id'],
                    NotificationChannel::Email,
                    NotificationCategory::Support,
                    'support.reply',
                    ['ticket_number' => (string) $ticket['ticket_ref']],
                    false,
                    'support_ticket',
                    $ticketId
                );
            }

            $this->support->touch($ticketId);

            Audit::record(
                $internal ? 'support.note.added' : 'support.reply.sent',
                'support_ticket',
                $ticketId,
                $internal ? 'Internal note - not visible to the customer' : 'Reply sent to the customer'
            );
        });
    }

    /**
     * Takes a ticket.
     *
     * Note what is NOT used to decide whether this worked: the number of rows
     * the UPDATE changed. MySQL reports zero changed rows when the new values
     * match the old ones, so assigning a ticket to the person it is already
     * assigned to changes nothing and would look exactly like a ticket that
     * does not exist. Telling an agent "that ticket could not be found" about
     * the ticket they are looking at is worse than the no-op it is reporting.
     * Existence is therefore checked by a read, and the write is allowed to be
     * a no-op.
     */
    public function assign(int $ticketId, int $staffUserId): void
    {
        $ticket = $this->support->findTicket($ticketId);

        if ($ticket === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        if ((int) ($ticket['assigned_to'] ?? 0) === $staffUserId) {
            // Already theirs. Not an error, and not worth an audit row either.
            return;
        }

        $this->support->assign($ticketId, $staffUserId);

        Audit::record(
            'support.ticket.assigned',
            'support_ticket',
            $ticketId,
            $ticket['assigned_to'] === null
                ? 'Taken from the unassigned queue'
                : 'Reassigned from user ' . (int) $ticket['assigned_to']
        );
    }

    public function resolve(int $ticketId, string $summary): void
    {
        if (trim($summary) === '') {
            throw new DomainRuleException('Say how it was resolved before closing it.', 'reason_required');
        }

        Database::transaction(function () use ($ticketId, $summary): void {
            $this->support->addMessage($ticketId, Auth::id(), 'support', $summary, false);
            $this->support->setStatus($ticketId, 'resolved');

            Audit::record('support.ticket.resolved', 'support_ticket', $ticketId, $summary);
        });
    }

    /**
     * Escalates to an administrator.
     *
     * Support cannot do everything - refunds above a threshold, account
     * suspensions and anything touching another seller's data go up rather
     * than being worked around. The reason is mandatory, because an escalation
     * without one is a ticket an administrator has to reverse-engineer.
     */
    public function escalate(int $ticketId, int $toAdminUserId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainRuleException('Say why this needs an administrator.', 'reason_required');
        }

        // Checked by a read for the same reason as assign(): an escalation that
        // repeats the current values changes no rows and is not a missing
        // ticket.
        if ($this->support->findTicket($ticketId) === null) {
            throw new DomainRuleException('That ticket could not be found.', 'not_found');
        }

        Database::transaction(function () use ($ticketId, $toAdminUserId, $reason): void {
            $this->support->escalate($ticketId, $toAdminUserId, $reason);

            $this->support->addMessage(
                $ticketId,
                Auth::id(),
                'support',
                'Escalated to an administrator. Reason: ' . $reason,
                true
            );

            Audit::sensitive('support.ticket.escalated', 'support_ticket', $ticketId, $reason);
        });
    }

    /**
     * An order lookup by a support agent.
     *
     * The justification is a required parameter rather than an optional note,
     * and it is written to the audit trail before the data is returned. That is
     * the difference between an access policy and an access policy somebody
     * remembers to follow.
     *
     * @return array<string,mixed>|null
     */
    public function lookUpOrder(string $subNumber, string $justification): ?array
    {
        if (mb_strlen(trim($justification)) < 5) {
            throw new DomainRuleException(
                'Say why you need to look this order up. It is recorded.',
                'justification_required'
            );
        }

        // The audit row is written BEFORE the read, and outside any transaction
        // this sits in, so a lookup that then fails - or that finds nothing -
        // is still recorded. "I opened it but it errored" must not be a way to
        // look at a record without leaving a trace.
        Audit::sensitive(
            'support.order.viewed',
            'seller_order',
            $subNumber,
            $justification,
            'Support looked up an order'
        );

        $row = $this->support->subOrderForSupport($subNumber);

        return $row === null ? null : (new SubOrderView())->detail($row);
    }

    /** @return array<string,int> */
    public function queueCounts(): array
    {
        return $this->support->queueCounts();
    }
}
