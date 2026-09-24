<?php

declare(strict_types=1);

namespace App\Controllers\Support;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\NotificationRepository;
use App\Repositories\SupportRepository;
use App\Services\PickupService;
use App\Services\SupportService;
use App\Support\View\TicketView;

/**
 * The support desk.
 *
 * **Two rules this area exists to enforce.**
 *
 * 1. **Internal notes are filtered out of the CUSTOMER query in SQL**, not
 *    hidden in a template (FR-SUP-02). They are shown here because this is the
 *    staff view; the customer view renders the same thread with those rows
 *    removed by the query, so a template refactor cannot leak one.
 *
 * 2. **Looking up a customer's order is recorded, with a justification**
 *    (FR-SUP-06). Support can see any order - that is the job - which is
 *    exactly why every look must leave a trace naming the ticket it was for.
 *    `SupportService::lookUpOrder()` refuses without one.
 *
 * Unlike the seller and agent areas, support is not scoped to a subset of rows:
 * a desk that can only see its own tickets cannot answer the phone. The control
 * here is the audit trail rather than the WHERE clause.
 */
final class SupportController extends Controller
{
    /** The support screens Phase 4d has connected to the database. */
    private const WIRED = [
        'support/dashboard',
        'support/tickets',
        'support/ticket',
        'support/orders',
        'support/order',
        'support/escalations',
        'support/notifications',
    ];

    public function __construct(
        private readonly SupportService $support = new SupportService(),
        private readonly SupportRepository $tickets = new SupportRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
        private readonly PickupService $pickups = new PickupService(),
    ) {
    }

    // ---- Screens ---------------------------------------------------------

    public function dashboard(): Response
    {
        $counts = $this->support->queueCounts();

        return $this->page('support/dashboard', [
            'title'         => 'Support overview',
            'counts'        => $counts,
            'open'          => TicketView::rows($this->support->queue(['status' => 'open'])['rows']),
            'escalated'     => TicketView::rows($this->support->queue(['status' => 'escalated'])['rows']),
            'unassigned'    => TicketView::rows($this->support->queue(['unassigned' => true])['rows']),
            'notifications' => $this->notificationLog(5),
        ]);
    }

    public function tickets(): Response
    {
        $valid  = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed', 'escalated', 'all'];
        $status = (string) $this->request()->query('status', 'open');
        $status = in_array($status, $valid, true) ? $status : 'open';

        $result = $this->support->queue(
            ['status' => $status === 'all' ? '__all' : $status, 'q' => (string) $this->request()->query('q', '')],
            max(1, (int) $this->request()->query('page', 1))
        );

        $counts = $this->support->queueCounts();

        return $this->page('support/tickets', [
            'title'   => 'Ticket queue',
            'status'  => $status,
            'query'   => (string) $this->request()->query('q', ''),
            'tickets' => TicketView::rows($result['rows']),
            'total'   => $result['total'],
            'counts'  => [
                'open'             => $counts['open'] ?? 0,
                'escalated'        => $counts['escalated'] ?? 0,
                'waiting_customer' => $counts['waiting_customer'] ?? 0,
                'resolved'         => $counts['resolved'] ?? 0,
                'all'              => array_sum(array_intersect_key(
                    $counts,
                    array_flip(['open', 'in_progress', 'waiting_customer', 'escalated', 'resolved', 'closed'])
                )),
            ],
        ]);
    }

    public function ticket(string $ref): Response
    {
        $thread = $this->support->staffThread($this->ticketIdFor($ref));

        return $this->page('support/ticket', [
            'title'  => 'Ticket ' . $ref,
            'ticket' => TicketView::staff($thread['ticket'], $thread['messages']),
            'order'  => $this->orderPanel($thread['order'], $thread['parts'], $ref),
        ]);
    }

    public function escalations(): Response
    {
        return $this->page('support/escalations', [
            'title'   => 'Escalated to an administrator',
            'tickets' => TicketView::rows($this->support->queue(['status' => 'escalated'])['rows']),
        ]);
    }

    /**
     * Order lookup.
     *
     * A search alone is not a look: nothing sensitive is returned until an
     * order is opened, and opening one needs a justification.
     */
    public function orders(): Response
    {
        $query = trim((string) $this->request()->query('q', ''));

        // The justification travels with the search rather than being asked for
        // after the agent has already seen the row. It is carried into each
        // result's link, so opening one is a single click that is nonetheless
        // audited against a stated reason.
        $ticket = trim((string) $this->request()->query('ticket', ''));

        return $this->page('support/orders', [
            'title'  => 'Order lookup',
            'query'  => $query,
            'ticket' => $ticket,
            'orders' => $query === '' ? [] : $this->tickets->searchOrders($query),
        ]);
    }

    public function order(string $ref): Response
    {
        $justification = trim((string) $this->request()->query('ticket', ''));

        if ($justification === '') {
            Session::flash(
                'warning',
                'Open a customer order from the ticket it relates to. Every look is recorded '
                . 'against a reason, and "none given" is not one.'
            );

            return $this->toRoute('support.orders');
        }

        try {
            $order = $this->support->lookUpOrder($ref, $justification);
        } catch (DomainRuleException $e) {
            Session::flash('error', $e->getMessage());

            return $this->toRoute('support.orders');
        }

        if ($order === null) {
            throw new HttpException(404, 'We could not find that order.');
        }

        return $this->page('support/order', [
            'title'         => 'Order ' . $ref,
            'order'         => $order,
            'justification' => $justification,
        ]);
    }

    public function notifications(): Response
    {
        return $this->page('support/notifications', [
            'title'         => 'Message log',
            'log'   => $this->notificationLog(100),
        ]);
    }

    // ---- Actions ---------------------------------------------------------

    public function reply(): Response
    {
        return $this->ticketAction(function (int $ticketId): void {
            $body = trim((string) $this->request()->input('body', ''));

            if ($body === '') {
                throw new DomainRuleException('Write the reply before sending it.', 'empty_reply');
            }

            // Internal is opt-in, and the form says which it is on the button.
            // A reply that reaches the customer by accident is recoverable; a
            // note about them that reaches them is not.
            $this->support->staffReply(
                $ticketId,
                (int) Auth::id(),
                $body,
                (bool) $this->request()->input('internal', false)
            );
        }, 'Reply sent.');
    }

    public function assign(): Response
    {
        return $this->ticketAction(
            fn (int $ticketId) => $this->support->assign($ticketId, (int) Auth::id()),
            'Assigned to you.'
        );
    }

    public function resolve(): Response
    {
        return $this->ticketAction(function (int $ticketId): void {
            $summary = trim((string) $this->request()->input('summary', ''));

            if ($summary === '') {
                throw new DomainRuleException(
                    'Say what the resolution was. "Resolved" on its own tells the next person nothing.',
                    'summary_required'
                );
            }

            $this->support->resolve($ticketId, $summary);
        }, 'Ticket resolved, and the customer has been told how.');
    }

    public function escalate(): Response
    {
        return $this->ticketAction(function (int $ticketId): void {
            $reason = trim((string) $this->request()->input('reason', ''));

            if ($reason === '') {
                throw new DomainRuleException('Say why an administrator is needed.', 'reason_required');
            }

            $admin = (int) $this->tickets->scalarAdminId();

            if ($admin === 0) {
                throw new DomainRuleException('There is no administrator to escalate to.', 'no_admin');
            }

            $this->support->escalate($ticketId, $admin, $reason);
        }, 'Escalated to an administrator.');
    }

    /**
     * Reissues a collection code the customer never received.
     *
     * The one order-changing action support has. It is safe to give them
     * because it cannot reveal anything: the new code goes to the customer's
     * own channel and the agent never sees it, so the worst an abused
     * reissue can do is invalidate a code - annoying, reversible, and loudly
     * audited.
     */
    public function reissueCode(): Response
    {
        $ref    = trim((string) $this->request()->input('ref', ''));
        $ticket = trim((string) $this->request()->input('ticket', ''));

        $back = $ref === ''
            ? $this->toRoute('support.orders')
            : $this->toRoute('support.orders.show', ['ref' => $ref, 'ticket' => $ticket]);

        return $this->attempt(function () use ($ref, $ticket, $back): Response {
            $order = $this->tickets->subOrderForSupport($ref);

            if ($order === null) {
                throw new DomainRuleException('We could not find that order.', 'not_found');
            }

            $this->pickups->regenerateCodeForSupport((int) $order['id'], $ticket);

            return $this->success(
                'A new collection code has been sent to the customer. The old one no longer works. '
                . 'You cannot see the new one, and neither can the seller.',
                $back
            );
        }, $back);
    }

    // ---- internals -------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->view($view, array_merge([
            'role'       => 'support',
            'sampleData' => !in_array($view, self::WIRED, true),
        ], $data), 'dashboard');
    }

    /**
     * The order summary beside a ticket.
     *
     * Enough for an agent to see what the customer is talking about without
     * opening the order itself - which is a separate, audited act. Nothing
     * here is per-item or per-address; it is the shape of the order, not its
     * contents.
     *
     * Each part links to the full view with THIS ticket as the justification
     * already attached. An agent should not have to type a reason they are
     * currently looking at, and a reason that is filled in for them is a reason
     * that is accurate.
     *
     * @param  array<string,mixed>|null  $order
     * @param  list<array<string,mixed>> $parts
     * @return array<string,mixed>|null
     */
    private function orderPanel(?array $order, array $parts, string $ticketRef): ?array
    {
        if ($order === null) {
            return null;
        }

        return [
            'ref'            => (string) $order['order_number'],
            'parent_ref'     => (string) $order['order_number'],
            'customer_name'  => (string) $order['customer_name'],
            'payment_status' => (string) $order['payment_status'],
            'payment_method' => (string) $order['payment_method'],
            'total'          => (string) $order['grand_total'],
            'placed_at_utc'  => (string) $order['placed_at'],
            'parts'          => array_map(
                static fn (array $p): array => [
                    'ref'        => (string) $p['sub_number'],
                    'seller'     => (string) $p['seller_name'],
                    'status'     => (string) $p['status'],
                    'fulfilment' => (string) $p['fulfilment_method'],
                    'total'      => (string) $p['total'],
                    'url'        => route('support.orders.show', [
                        'ref'    => (string) $p['sub_number'],
                        'ticket' => $ticketRef,
                    ]),
                ],
                $parts
            ),
        ];
    }

    /** Resolves a printed ticket reference to its id, or 404s. */
    private function ticketIdFor(string $ref): int
    {
        $row = $this->tickets->findTicketByRef($ref);

        if ($row === null) {
            throw new HttpException(404, 'We could not find that ticket.');
        }

        return (int) $row['id'];
    }

    /**
     * The shared shape of every ticket action.
     *
     * @param callable(int):void $action
     */
    private function ticketAction(callable $action, string $message): Response
    {
        $ref = (string) $this->request()->input('ref', '');

        return $this->attempt(function () use ($action, $message, $ref): Response {
            $row = $this->tickets->findTicketByRef($ref);

            if ($row === null) {
                throw new DomainRuleException('We could not find that ticket.', 'not_found');
            }

            $action((int) $row['id']);

            return $this->success($message, $this->toRoute('support.tickets.show', ['ref' => $ref]));
        }, $ref === ''
            ? $this->toRoute('support.tickets')
            : $this->toRoute('support.tickets.show', ['ref' => $ref]));
    }

    /**
     * The outbound message log.
     *
     * Support needs this to answer "did they get the email?", which is the
     * second most common question after "where is my order". A skipped message
     * is shown as skipped with its reason - never as sent.
     *
     * @return list<array<string,mixed>>
     */
    private function notificationLog(int $limit): array
    {
        return array_map(
            static fn (array $row): array => [
                'customer'          => trim((string) $row['customer_name']) ?: 'Unknown',
                'channel'           => (string) $row['channel'],
                'category'          => (string) $row['category'],
                'template'          => (string) $row['template_key'],
                'status'            => (string) $row['status'],
                'skip_reason'       => $row['skip_reason'] !== null ? (string) $row['skip_reason'] : null,
                'attempts'          => (int) $row['attempts'],
                'provider_response' => (string) ($row['provider_response'] ?? ''),
                'queued_at_utc'     => (string) $row['created_at'],
            ],
            $this->notifications->recentForSupport($limit)
        );
    }

}
