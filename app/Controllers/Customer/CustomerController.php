<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Services\NotificationService;
use App\Services\ReorderService;
use App\Services\SupportService;
use App\Support\View\Present;
use App\Support\View\SubOrderView;
use App\Support\View\TicketView;
use App\Support\MockCatalog;
use App\Support\MockDashboard;

/**
 * Customer dashboard screens.
 *
 * PHASE 1 NOTE ON STRUCTURE: SYSTEM_ARCHITECTURE.md section 4 lists separate
 * Dashboard / Profile / Address / Order / Reorder / Payment / Notification /
 * Preference / Review / Ticket controllers. Splitting them now would create ten
 * files that each pick a view model and a template and contain nothing else,
 * because there is no behaviour to separate until Phase 3. They are split along
 * exactly those lines when the services arrive; the method names below already
 * match the intended file names.
 *
 * ACCESS: the routes carry `auth` and `role:customer`, and every query behind
 * them is scoped by `orders.user_id` in its WHERE clause. Both matter, and the
 * second is the one that counts - the route prefix is organisation, the filter
 * is the access control. A customer asking for another customer's order
 * reference gets the same 404 as one that does not exist.
 */
final class CustomerController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
        private readonly SupportService $support = new SupportService(),
        private readonly NotificationService $messages = new NotificationService(),
        private readonly ReorderService $reorders = new ReorderService(),
    ) {
    }

    /**
     * The customer screens Phase 4 has connected to the database.
     *
     * Everything not on this list still renders sample data, and says so on the
     * page. The list is here rather than in the views so that "is this screen
     * real?" has one answer in one place, and so that forgetting to remove a
     * banner cannot make an unwired screen look finished.
     */
    private const WIRED = [
        'customer/dashboard',
        'customer/orders',
        'customer/history',
        'customer/order',
        'customer/tickets',
        'customer/ticket',
        'customer/notifications',
        'customer/preferences',
        'customer/reorder',
    ];

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->view($view, array_merge([
            'role'       => 'customer',
            'sampleData' => !in_array($view, self::WIRED, true),
        ], $data), 'dashboard');
    }

    public function dashboard(): Response
    {
        $userId = (int) Auth::id();

        $lifetime = $this->orders->lifetimeSpendFor($userId);

        return $this->page('customer/dashboard', [
            'title'        => 'Overview',
            'firstName'    => (string) (Auth::user()['first_name'] ?? 'there'),
            'active'       => $this->subOrders('active'),
            'inbox'        => $this->inbox($userId, 3),
            'reorder'      => $this->reorderable($userId, 3),
            'spend'        => $lifetime['spend'],
            'historyCount' => $lifetime['completed'],
        ]);
    }

    /**
     * The in-app message list.
     *
     * Rendered from the template key and the payload, exactly as the email
     * channel renders it - so the inbox cannot drift out of step with what was
     * actually sent. Payloads that carried a collection code have had it
     * scrubbed on delivery, so a code cannot reappear on a web page.
     *
     * @return list<array<string,mixed>>
     */
    private function inbox(int $userId, int $limit): array
    {
        $service = $this->messages;

        return array_map(
            static function (array $row) use ($service): array {
                $rendered = $service->render($row);

                return [
                    'id'        => (int) $row['id'],
                    'title'     => $rendered['subject'],
                    'body'      => $rendered['body'],
                    'read'      => $row['read_at'] !== null,
                    'marketing' => (bool) ($row['is_marketing'] ?? false),
                    // Whichever of these is set decides where the row links.
                    // A ticket reference is only resolved when the ticket is
                    // this customer's, which the join enforces.
                    'order_ref'  => $row['order_ref'] !== null ? (string) $row['order_ref'] : null,
                    'ticket_ref' => $row['ticket_ref'] !== null ? (string) $row['ticket_ref'] : null,
                    'at_utc'    => (string) ($row['sent_at'] ?? $row['created_at']),
                ];
            },
            $this->notifications->forUser($userId, $limit)
        );
    }

    /**
     * Things this customer has bought before and could buy again.
     *
     * @return list<array<string,mixed>>
     */
    private function reorderable(int $userId, int $limit): array
    {
        return array_map(
            static fn (array $row): array => [
                'product_id'      => (int) $row['product_id'],
                'slug'            => (string) $row['slug'],
                'product'         => (string) $row['name'],
                'pack_size'       => (string) $row['pack_size'],
                'current_price'   => (string) $row['current_price'],
                'last_bought_utc' => (string) $row['last_bought_at'],
                'times_bought'    => (int) $row['times_bought'],
                'available'       => (int) $row['available'],
                'tone'            => Present::product(['slug' => (string) $row['slug']])['tone'],
            ],
            $this->orders->purchaseHistoryFor($userId, $limit)
        );
    }

    public function orders(): Response
    {
        return $this->page('customer/orders', [
            'title'  => 'Active orders',
            'orders' => $this->subOrders('active'),
        ]);
    }

    public function history(): Response
    {
        return $this->page('customer/history', [
            'title'  => 'Order history',
            'orders' => $this->subOrders('history'),
        ]);
    }

    public function order(string $ref): Response
    {
        $userId = (int) Auth::id();
        $row    = $this->orders->subOrderForCustomer($ref, $userId);

        // A customer requesting somebody else's reference gets this same 404 -
        // "not yours" and "does not exist" must be indistinguishable.
        if ($row === null) {
            throw new HttpException(404, 'We could not find that order on your account.');
        }

        $order = (new SubOrderView())->detail($row);

        // The other parts of the same parent order, so somebody can move
        // between the halves of one basket without going back to the list.
        $siblings = array_values(array_filter(
            SubOrderView::rows($this->orders->sellerOrdersForCustomer(
                (int) $row['order_id'],
                $userId
            )),
            static fn (array $o): bool => $o['ref'] !== $order['ref']
        ));

        return $this->page('customer/order', [
            'title'    => 'Order ' . $order['parent_ref'],
            'order'    => $order,
            'siblings' => $siblings,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function subOrders(string $filter): array
    {
        $page = max(1, (int) $this->request()->query('page', 1));

        return SubOrderView::rows(
            $this->orders->subOrdersForCustomer((int) Auth::id(), $filter, $page, 25)['rows']
        );
    }

    public function payments(): Response
    {
        $payments = array_values(array_filter(
            MockDashboard::adminSet('payments'),
            static fn (array $p): bool => $p['customer'] === 'Asha Mwinyi'
        ));

        return $this->page('customer/payments', [
            'title'    => 'Payments',
            'payments' => $payments,
        ]);
    }

    public function profile(): Response
    {
        return $this->page('customer/profile', ['title' => 'Profile']);
    }

    public function addresses(): Response
    {
        return $this->page('customer/addresses', [
            'title'     => 'Delivery addresses',
            'addresses' => self::savedAddresses(),
        ]);
    }

    public function notifications(): Response
    {
        return $this->page('customer/notifications', [
            'title' => 'Notifications',
            'inbox' => $this->inbox((int) Auth::id(), 50),
        ]);
    }

    /**
     * Marks the inbox read.
     *
     * Scoped by user id inside the repository - `markRead` takes both the
     * message id and the owner, so a guessed id belonging to somebody else
     * updates nothing.
     */
    public function markNotificationsRead(): Response
    {
        $userId = (int) Auth::id();
        $id     = (int) $this->request()->input('id', 0);

        $marked = 0;

        if ($id > 0) {
            $marked = $this->notifications->markRead($id, $userId);
        } else {
            foreach ($this->notifications->forUser($userId, 200) as $row) {
                if ($row['read_at'] === null) {
                    $marked += $this->notifications->markRead((int) $row['id'], $userId);
                }
            }
        }

        // Say what happened even when nothing did. A button that returns the
        // same page in silence reads as broken whether or not it worked.
        return $this->success(
            $marked === 0
                ? 'Nothing was unread.'
                : sprintf('%d message%s marked as read.', $marked, $marked === 1 ? '' : 's'),
            $this->toRoute('customer.notifications')
        );
    }

    public function preferences(): Response
    {
        $panel = $this->messages->preferencePanel((int) Auth::id());

        return $this->page('customer/preferences', array_merge($panel, [
            'title' => 'Email preferences',
            'email' => (string) (Auth::user()['email'] ?? ''),
        ]));
    }

    public function savePreferences(): Response
    {
        $wanted = $this->request()->input('category', []);
        $wanted = is_array($wanted) ? array_map('strval', $wanted) : [];

        return $this->attempt(function () use ($wanted): Response {
            $this->messages->savePreferences((int) Auth::id(), $wanted);

            return $this->success('Saved. This takes effect on the next message.', $this->toRoute('customer.preferences'));
        }, $this->toRoute('customer.preferences'));
    }

    public function unsubscribe(): Response
    {
        return $this->attempt(function (): Response {
            $this->messages->unsubscribeAll((int) Auth::id(), 'preferences_page');

            return $this->success(
                'Done. Optional messages are off and anything already queued has been cancelled. '
                . 'Updates about orders you have placed still come through.',
                $this->toRoute('customer.preferences')
            );
        }, $this->toRoute('customer.preferences'));
    }

    /**
     * Things worth buying again.
     *
     * Every item here is still published and still in stock somewhere - the
     * query says so. A one-tap reorder of something nobody can supply wastes
     * the tap and reads as broken.
     */
    public function reorder(): Response
    {
        return $this->page('customer/reorder', [
            'title' => 'Reorder',
            'items' => $this->reorders->revalidate((int) Auth::id(), 24),
        ]);
    }

    /**
     * Puts one previously-bought product back in the basket.
     *
     * The service checks the product against this customer's own purchase
     * history before anything else, so this is not a general "add product id
     * N" endpoint wearing a friendlier name.
     */
    public function reorderAdd(): Response
    {
        return $this->attempt(function (): Response {
            $message = $this->reorders->addToBasket(
                (int) Auth::id(),
                (int) $this->request()->input('product_id', 0)
            );

            return $this->success($message, $this->toRoute('customer.reorder'));
        }, $this->toRoute('customer.reorder'));
    }

    public function reviews(): Response
    {
        return $this->page('customer/reviews', [
            'title'     => 'My reviews',
            'written'   => MockCatalog::reviewsFor(101),
            'pending'   => MockDashboard::customerOrders('history'),
        ]);
    }

    public function writeReview(): Response
    {
        $slug    = (string) $this->request()->query('product', 'alizeti-sunflower-oil-5l');
        $product = MockCatalog::productBySlug($slug);

        if ($product === null) {
            throw new HttpException(404, 'That product is no longer listed.');
        }

        return $this->page('customer/write-review', [
            'title'   => 'Write a review',
            'product' => $product,
        ]);
    }

    /**
     * The customer's own support requests.
     *
     * Scoped by `support_tickets.user_id` in the query, not filtered here.
     */
    public function tickets(): Response
    {
        return $this->page('customer/tickets', [
            'title'   => 'Support',
            'tickets' => TicketView::rows($this->support->customerTickets((int) Auth::id())),
            'orders'  => $this->openOrderRefs(),
        ]);
    }

    /**
     * One thread.
     *
     * The internal notes are absent because `customerMessages()` does not
     * select them - not because anything here removed them. A customer asking
     * for somebody else's reference gets the same 404 as one that does not
     * exist.
     */
    public function ticket(string $ref): Response
    {
        $userId = (int) Auth::id();
        $row    = $this->support->findTicketRefForCustomer($ref, $userId);

        if ($row === null) {
            throw new HttpException(404, 'We could not find that support request on your account.');
        }

        $thread = $this->support->customerThread((int) $row['id'], $userId);

        return $this->page('customer/ticket', [
            'title'  => 'Ticket ' . $ref,
            'ticket' => TicketView::customer($thread['ticket'], $thread['messages']),
        ]);
    }

    /** Opens a request. */
    public function openTicket(): Response
    {
        return $this->attempt(function (): Response {
            $result = $this->support->openTicket((int) Auth::id(), [
                'subject'      => (string) $this->request()->input('subject', ''),
                'category'     => (string) $this->request()->input('category', 'other'),
                'body'         => (string) $this->request()->input('body', ''),
                'order_number' => trim((string) $this->request()->input('order_number', '')) ?: null,
            ]);

            return $this->success(
                'Request ' . $result['ticket_ref'] . ' is open. We reply by email and here.',
                $this->toRoute('customer.tickets.show', ['ref' => $result['ticket_ref']])
            );
        }, $this->toRoute('customer.tickets'));
    }

    /** Replies on a thread, or reopens a resolved one by replying to it. */
    public function replyToTicket(): Response
    {
        $ref    = (string) $this->request()->input('ref', '');
        $userId = (int) Auth::id();
        $row    = $this->support->findTicketRefForCustomer($ref, $userId);

        // Where a failure lands is decided BEFORE the work, and a reference
        // that is not theirs lands on the list rather than on a page that
        // would then 404. Bouncing somebody to a dead end to tell them the
        // thing does not exist is two problems, not one.
        $back = $row === null
            ? $this->toRoute('customer.tickets')
            : $this->toRoute('customer.tickets.show', ['ref' => $ref]);

        return $this->attempt(function () use ($row, $userId, $back): Response {
            if ($row === null) {
                throw new DomainRuleException('We could not find that request.', 'not_found');
            }

            $this->support->customerReply(
                (int) $row['id'],
                $userId,
                (string) $this->request()->input('body', '')
            );

            return $this->success('Sent. Your request is back on the queue.', $back);
        }, $back);
    }

    /**
     * Order references this customer could attach to a request.
     *
     * Offering a list beats asking somebody to copy a reference out of an
     * email, and it means the reference is always one that exists and is
     * theirs - the service checks ownership again regardless.
     *
     * @return list<string>
     */
    private function openOrderRefs(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $o): string => (string) $o['parent_ref'],
            SubOrderView::rows(
                $this->orders->subOrdersForCustomer((int) Auth::id(), 'active', 1, 25)['rows']
            )
        )));
    }

    /**
     * @return list<array<string,mixed>>
     * PHASE 1 ONLY - replaced by AddressRepository::forCustomer().
     */
    public static function savedAddresses(): array
    {
        return [
            ['id' => 1, 'label' => 'Home', 'is_default' => true, 'recipient' => 'Asha Mwinyi',
             'phone_masked' => '+255 7** *** 118', 'region' => 'Dar es Salaam', 'district' => 'Kinondoni',
             'ward' => 'Msasani', 'street' => 'Chole Road 22',
             'landmark' => 'Blue gate opposite the pharmacy',
             'instructions' => 'Call on arrival, the gate bell does not work.',
             'zone' => 'DSM Central', 'zone_fee' => '6000.00'],
            ['id' => 2, 'label' => 'Work', 'is_default' => false, 'recipient' => 'Asha Mwinyi',
             'phone_masked' => '+255 7** *** 118', 'region' => 'Dar es Salaam', 'district' => 'Ilala',
             'ward' => 'Upanga', 'street' => 'Ufukoni Street 4',
             'landmark' => 'Third floor, Amani House',
             'instructions' => 'Leave with reception if I am not in.',
             'zone' => 'DSM Central', 'zone_fee' => '6000.00'],
            ['id' => 3, 'label' => 'Mother', 'is_default' => false, 'recipient' => 'Mariam Mwinyi',
             'phone_masked' => '+255 6** *** 904', 'region' => 'Morogoro', 'district' => 'Morogoro Urban',
             'ward' => 'Kihonda', 'street' => 'Mazimbu Road 11', 'landmark' => null,
             'instructions' => null, 'zone' => null, 'zone_fee' => null],
        ];
    }
}
