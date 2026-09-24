<?php

declare(strict_types=1);

namespace App\Controllers\Seller;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\InventoryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ReviewRepository;
use App\Repositories\SellerRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StoreRepository;
use App\Services\CatalogService;
use App\Services\DeliveryService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PickupService;
use App\Services\ProductService;
use App\Support\MockCatalog;
use App\Support\MockDashboard;
use App\Support\View\Present;
use App\Support\View\SubOrderView;

/**
 * The seller dashboard.
 *
 * **Scoping.** Every query takes its seller id from `Auth::sellerId()`, which
 * resolves the session user to a `sellers.id` through the database. There is no
 * code path that accepts it from a request parameter, so a seller cannot widen
 * their own view by editing a form
 * (USER_ROLES_AND_PERMISSIONS.md section 4.1).
 *
 * **Role is not permission.** Holding the seller role gets you this dashboard;
 * only `sellers.status = 'active'` lets you trade. An applicant awaiting a
 * decision sees the dashboard, can prepare a catalogue, and is refused at the
 * point of publishing or accepting an order — by the service, not by a hidden
 * button.
 */
final class SellerController extends Controller
{
    /**
     * The seller screens Phase 4b has connected to the database.
     *
     * Everything not listed still renders sample data and says so on the page.
     * One list, in one place, so a forgotten banner cannot make an unwired
     * screen look finished.
     */
    private const WIRED = [
        'seller/dashboard',
        'seller/orders',
        'seller/order',
        'seller/pickup',
        'seller/pickup-verify',
        'seller/dispatch',
        'seller/products',
        'seller/product-form',
        'seller/inventory',
        'seller/stores',
        'seller/reviews',
    ];

    /** Sub-order statuses, grouped the way the seller's tabs group them. */
    private const GROUPS = [
        'incoming' => ['awaiting_seller', 'confirmed'],
        'pickup'   => ['preparing', 'ready_for_pickup', 'collection_overdue'],
        'dispatch' => ['ready_for_dispatch', 'out_for_delivery'],
        'done'     => ['collected', 'delivered', 'completed'],
        'problem'  => ['rejected_seller', 'cancelled_customer', 'delivery_failed', 'returned_to_seller', 'expired_unpaid', 'refunded'],
    ];

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StoreRepository $stores = new StoreRepository(),
        private readonly InventoryRepository $inventory = new InventoryRepository(),
        private readonly SellerRepository $sellers = new SellerRepository(),
        private readonly ReviewRepository $reviews = new ReviewRepository(),
        private readonly OrderService $orderService = new OrderService(),
        private readonly PickupService $pickups = new PickupService(),
        private readonly DeliveryService $deliveries = new DeliveryService(),
        private readonly ProductService $productService = new ProductService(),
        private readonly InventoryService $stock = new InventoryService(),
    ) {
    }

    // ---- Overview --------------------------------------------------------

    public function dashboard(): Response
    {
        $sellerId = $this->sellerId();

        $lifetime = $this->orders->sellerRevenue($sellerId);

        return $this->page('seller/dashboard', [
            'title'    => 'Dashboard',
            // The incoming cards list what was ordered, because that is what a
            // seller reads before deciding to accept. The other lists are
            // counts only, and stay one query each.
            'incoming' => $this->withItems($this->ordersIn('incoming')),
            'pickup'   => $this->ordersIn('pickup'),
            'dispatch' => $this->ordersIn('dispatch'),
            'series'   => $this->salesSeries($sellerId),
            'lowStock' => $this->lowStock($sellerId),
            'revenue'  => $lifetime['revenue'],
            'completedCount' => $lifetime['completed'],
            'storeName' => $this->businessName($sellerId),
        ]);
    }

    // ---- Orders ----------------------------------------------------------

    public function orders(): Response
    {
        $filter = (string) $this->request()->query('status', 'incoming');
        $filter = isset(self::GROUPS[$filter]) ? $filter : 'incoming';

        $counts   = $this->orders->statusCountsForSeller($this->sellerId());
        $grouped  = [];

        foreach (self::GROUPS as $group => $statuses) {
            $grouped[$group] = array_sum(array_map(
                static fn (string $s): int => (int) ($counts[$s] ?? 0),
                $statuses
            ));
        }

        return $this->page('seller/orders', [
            'title'  => 'Orders',
            'filter' => $filter,
            'orders' => $this->ordersIn($filter),
            'counts' => $grouped,
        ]);
    }

    public function order(string $ref): Response
    {
        return $this->page('seller/order', [
            'title' => 'Order ' . $ref,
            'order' => $this->requireOwnOrder($ref),
        ]);
    }

    public function accept(): Response
    {
        return $this->orderAction(
            fn (int $id, int $sellerId) => $this->orderService->acceptAsSeller($id, $sellerId),
            'Order accepted. Start preparing it when you are ready.'
        );
    }

    /**
     * Rejecting, which always costs a reason.
     *
     * The form asks for a category and a note. Both go to the customer as one
     * sentence, because "unsuccessful" with no explanation just becomes a
     * support ticket somebody else has to answer.
     */
    public function reject(): Response
    {
        return $this->orderAction(
            fn (int $id, int $sellerId) => $this->orderService->rejectAsSeller($id, $sellerId, $this->rejectionReason()),
            'Order rejected, and the customer has been told why.'
        );
    }

    private function rejectionReason(): string
    {
        $labels = [
            'out_of_stock'  => 'Not in stock',
            'quality'       => 'Not in sellable condition',
            'store_closed'  => 'Cannot fulfil in the promised window',
            'pricing_error' => 'The listed price was wrong',
            'other'         => 'Other',
        ];

        $code = (string) $this->request()->input('reason_code', '');
        $note = trim((string) $this->request()->input('reason', ''));

        if ($note === '') {
            throw new DomainRuleException('Write the note the customer will read.', 'reason_required');
        }

        return isset($labels[$code]) ? $labels[$code] . ': ' . $note : $note;
    }

    public function prepare(): Response
    {
        return $this->orderAction(
            fn (int $id, int $sellerId) => $this->orderService->startPreparing($id, $sellerId),
            'Marked as being prepared.'
        );
    }

    /**
     * Marks an order ready.
     *
     * Both methods issue a one-time code in the SAME transaction as the status
     * change, and both go through the service that owns that code rather than
     * through OrderService - a "ready" order with no code is an order nobody
     * can hand over, and the two would have to be kept in step by hand.
     *
     * Which service depends on who does the handover: PickupService for a
     * counter, DeliveryService for a doorstep.
     */
    public function markReady(): Response
    {
        return $this->orderAction(function (int $id, int $sellerId): string {
            $sub = $this->orders->findSellerOrderOwnedBy($id, $sellerId);

            if ($sub !== null && (string) $sub['fulfilment_method'] === 'pickup') {
                $this->pickups->markReadyAndIssueCode($id, $sellerId);

                return 'Ready to collect. The collection code has been sent to the customer - '
                    . 'we store only its hash, so nobody here can read it back.';
            }

            $this->deliveries->markReadyForDispatch($id, $sellerId);

            return 'Ready to dispatch. The delivery code has been sent to the customer, and an '
                . 'agent can now be assigned.';
        });
    }

    // ---- Collection ------------------------------------------------------

    public function pickup(): Response
    {
        return $this->page('seller/pickup', [
            'title'  => 'Ready to collect',
            'orders' => $this->ordersIn('pickup'),
        ]);
    }

    public function pickupVerify(): Response
    {
        // Only orders with a code actually issued: one still being prepared has
        // nothing to verify, and offering it would produce a refusal that reads
        // like a fault.
        $waiting = array_values(array_filter(
            $this->ordersIn('pickup'),
            static fn (array $o): bool => $o['code_issued'] && $o['status'] !== 'collected'
        ));

        $options = ['' => 'Choose the order'];
        foreach ($waiting as $order) {
            $options[(string) $order['seller_order_id']] =
                $order['ref'] . ' - ' . $order['customer_name'] . ' - ' . $order['store_name'];
        }

        // A "Verify" button on a specific order arrives with ?ref=, so the
        // person at the counter does not hunt for it in a list.
        $ref      = (string) $this->request()->query('ref', '');
        $selected = '';

        foreach ($waiting as $order) {
            if ($order['ref'] === $ref) {
                $selected = (string) $order['seller_order_id'];
                break;
            }
        }

        return $this->page('seller/pickup-verify', [
            'title'        => 'Verify a collection',
            'orders'       => $waiting,
            'orderOptions' => $options,
            'selected'     => $selected,
        ]);
    }

    /**
     * The counter: a customer presents a code, the seller types it in.
     *
     * Everything that matters happens in PickupService — the attempt counter,
     * the hash comparison, the lockout and the two transitions. This reads a
     * form and reports the outcome.
     */
    public function confirmCollection(): Response
    {
        return $this->attempt(function (): Response {
            $sellerId = $this->sellerId();
            $subId    = (int) $this->request()->input('seller_order_id', 0);
            $code     = trim((string) $this->request()->input('code', ''));

            if ($code === '') {
                throw new DomainRuleException('Enter the code the customer is showing you.', 'no_code');
            }

            $this->pickups->confirmCollection($subId, $sellerId, $code);

            return $this->success('Collection confirmed. The order is complete.', $this->toRoute('seller.pickup.verify'));
        }, $this->toRoute('seller.pickup.verify'));
    }

    public function dispatch(): Response
    {
        return $this->page('seller/dispatch', [
            'title'  => 'Ready to dispatch',
            'orders' => $this->ordersIn('dispatch'),
        ]);
    }

    // ---- Catalogue -------------------------------------------------------

    public function products(): Response
    {
        $sellerId = $this->sellerId();
        $status   = (string) $this->request()->query('status', '');
        $status   = in_array($status, ['draft', 'published', 'archived', 'suspended'], true) ? $status : null;

        $result = $this->products->forSeller($sellerId, $status, max(1, (int) $this->request()->query('page', 1)), 25);

        return $this->page('seller/products', [
            'title'    => 'Products',
            'products' => array_map($this->presentOwnProduct(...), $result['rows']),
            'status'   => $status ?? '',
            'total'    => $result['total'],
            'canTrade' => $this->sellers->canTrade($sellerId),
        ]);
    }

    public function productForm(): Response
    {
        $sellerId = $this->sellerId();
        $id       = (int) $this->request()->query('id', 0);
        $product  = null;

        if ($id > 0) {
            $row = $this->products->findOwnedBy($id, $sellerId);

            if ($row === null) {
                throw new HttpException(404, 'We could not find that product in your catalogue.');
            }

            $product = $this->presentForEditing($row);
        }

        return $this->page('seller/product-form', [
            'title'      => $product === null ? 'Add a product' : 'Edit product',
            'product'    => $product,
            'categories' => (new CatalogService())->categoryFilterOptions(),
            'units'      => $this->unitOptions(),
            'stock'      => $id > 0 ? $this->stockFor($id, $sellerId) : [],
        ]);
    }

    public function saveProduct(): Response
    {
        return $this->attempt(function (): Response {
            $sellerId = $this->sellerId();
            $id       = (int) $this->request()->input('id', 0);
            $input    = $this->request()->postAll();

            if ($id > 0) {
                $this->productService->update($id, $sellerId, $input);

                return $this->success(
                    'Product saved.',
                    $this->redirect(route('seller.products.form') . '?id=' . $id)
                );
            }

            $newId = $this->productService->create($sellerId, $input);

            return $this->success(
                'Product created as a draft. Add stock at one of your stores, then publish it.',
                $this->redirect(route('seller.products.form') . '?id=' . $newId)
            );
        });
    }

    public function publishProduct(): Response
    {
        return $this->attempt(function (): Response {
            $id = (int) $this->request()->input('id', 0);

            $this->productService->publish($id, $this->sellerId());

            return $this->success('Published. Customers can buy it now.', $this->toRoute('seller.products'));
        }, $this->toRoute('seller.products'));
    }

    public function archiveProduct(): Response
    {
        return $this->attempt(function (): Response {
            $id = (int) $this->request()->input('id', 0);

            $this->productService->archive($id, $this->sellerId());

            return $this->success(
                'Archived. It is off the catalogue but stays on the orders that bought it.',
                $this->toRoute('seller.products')
            );
        }, $this->toRoute('seller.products'));
    }

    // ---- Inventory -------------------------------------------------------

    public function inventory(): Response
    {
        $sellerId = $this->sellerId();

        return $this->page('seller/inventory', [
            'title'  => 'Inventory',
            'stores' => $this->inventoryByStore($sellerId),
        ]);
    }

    public function receiveStock(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $this->stock->receiveStock(
                $this->sellerId(),
                (int) $request->input('product_id', 0),
                (int) $request->input('store_id', 0),
                (int) $request->input('qty', 0),
                (string) $request->input('note', '')
            );

            return $this->success('Stock received.', $this->toRoute('seller.inventory'));
        }, $this->toRoute('seller.inventory'));
    }

    /**
     * A correction to a count.
     *
     * The form asks for the NEW on-hand figure, because that is what somebody
     * holding a clipboard has just counted. The service takes a delta, so the
     * difference is worked out here rather than asking a seller to do
     * subtraction at the end of a stocktake.
     */
    public function adjustStock(): Response
    {
        return $this->attempt(function (): Response {
            $request  = $this->request();
            $sellerId = $this->sellerId();

            $productId = (int) $request->input('product_id', 0);
            $storeId   = (int) $request->input('store_id', 0);
            $counted   = (int) $request->input('qty', -1);

            if ($counted < 0) {
                throw new DomainRuleException('Enter the number you counted.', 'invalid_quantity');
            }

            $row = $this->inventory->findFor($productId, $storeId);

            if ($row === null) {
                throw new DomainRuleException('That product is not stocked at that store.', 'not_stocked');
            }

            $delta = $counted - (int) $row['qty_on_hand'];

            if ($delta === 0) {
                Session::flash('info', 'That is the figure already on file - nothing changed.');

                return $this->toRoute('seller.inventory');
            }

            $this->stock->adjustStock(
                $sellerId,
                $productId,
                $storeId,
                $delta,
                (string) $request->input('reason', 'recount'),
                (string) ($request->input('note', '') ?: 'Counted ' . $counted . ' on the shelf')
            );

            return $this->success(
                sprintf('Count corrected by %+d.', $delta),
                $this->toRoute('seller.inventory')
            );
        }, $this->toRoute('seller.inventory'));
    }

    // ---- Stores ----------------------------------------------------------

    public function stores(): Response
    {
        return $this->page('seller/stores', [
            'title'  => 'Stores',
            'stores' => Present::stores($this->stores->forSeller($this->sellerId())),
        ]);
    }

    public function storeEdit(string $slug): Response
    {
        $store = MockCatalog::storeBySlug($slug);

        if ($store === null) {
            throw new HttpException(404, 'We could not find that store.');
        }

        return $this->page('seller/store-edit', [
            'title' => $store['name'],
            'store' => $store,
        ]);
    }

    // ---- Not yet wired ---------------------------------------------------

    public function reminders(): Response
    {
        return $this->page('seller/reminders', [
            'title'    => 'Reorder reminders',
            'products' => array_values(array_filter(
                MockCatalog::productsForSeller('mama-lishe'),
                static fn (array $p): bool => !empty($p['is_consumable'])
            )),
        ]);
    }

    public function settings(): Response
    {
        $sellerId = $this->sellerId();

        return $this->page('seller/settings', [
            'title'     => 'Store settings',
            'seller'    => $this->sellers->find($sellerId) ?? [],
            'codLimits' => $this->codLimits(),
        ]);
    }

    /**
     * The three order-handling settings that are real columns.
     *
     * `accepts_cod` is the one that matters most and is the only payment
     * decision a seller actually owns: every other method settles before the
     * goods move, and cash does not.
     */
    public function saveOrderHandling(): Response
    {
        return $this->attempt(function (): Response {
            $request  = $this->request();
            $sellerId = $this->sellerId();

            $prepHours = (int) $request->input('prep_hours', 4);
            $threshold = (int) $request->input('low_stock_threshold', 10);

            if ($prepHours < 1 || $prepHours > 168) {
                throw new DomainRuleException(
                    'Preparation time has to be between 1 hour and a week.',
                    'invalid_prep_hours'
                );
            }

            if ($threshold < 0 || $threshold > 1000) {
                throw new DomainRuleException('The low-stock warning has to be between 0 and 1000.', 'invalid_threshold');
            }

            $this->sellers->update($sellerId, [
                'prep_hours'          => $prepHours,
                'auto_accept'         => $request->input('auto_accept') === 'auto' ? 1 : 0,
                'low_stock_threshold' => $threshold,
                'accepts_cod'         => $request->input('accepts_cod') ? 1 : 0,
            ]);

            Audit::record(
                'seller.settings.updated',
                'seller',
                $sellerId,
                sprintf(
                    'prep %dh, %s accept, cash %s',
                    $prepHours,
                    $request->input('auto_accept') === 'auto' ? 'auto' : 'manual',
                    $request->input('accepts_cod') ? 'on' : 'off'
                )
            );

            return $this->success('Order handling saved.', $this->toRoute('seller.settings'));
        }, $this->toRoute('seller.settings'));
    }

    /**
     * The platform's cash limits, so the settings page can explain what a
     * seller is being protected from rather than just offering a switch.
     *
     * @return array<string,mixed>
     */
    private function codLimits(): array
    {
        $settings = new SettingsRepository();

        return [
            'max_order_value'    => (string) $settings->get('cod.max_order_value', '200000.00'),
            'max_open_orders'    => $settings->getInt('cod.max_open_orders', 2),
            'max_strikes'        => $settings->getInt('cod.max_strikes', 2),
            'strike_window_days' => $settings->getInt('cod.strike_window_days', 90),
        ];
    }

    public function reports(): Response
    {
        return $this->page('seller/reports', [
            'title'  => 'Sales reports',
            'series' => MockDashboard::salesSeries(),
            'orders' => MockDashboard::sellerOrders(1),
            'byProduct' => [],
        ]);
    }

    public function reviews(): Response
    {
        return $this->page('seller/reviews', [
            'title'   => 'Customer reviews',
            'reviews' => Present::reviews($this->reviews->forSeller($this->sellerId(), 50)),
        ]);
    }

    /**
     * One public reply per review.
     *
     * Scoped through the product's seller in the UPDATE itself, so a seller
     * replying to a review of somebody else's product affects zero rows and is
     * told the review is not theirs - the same answer as for one that does not
     * exist.
     */
    public function replyToReview(): Response
    {
        return $this->attempt(function (): Response {
            $reviewId = (int) $this->request()->input('review_id', 0);
            $body     = trim((string) $this->request()->input('body', ''));

            if ($body === '') {
                throw new DomainRuleException('Write the reply before posting it.', 'empty_reply');
            }

            if ($this->reviews->addSellerReply($reviewId, $this->sellerId(), $body) === 0) {
                throw new DomainRuleException(
                    'That review is not on one of your products.',
                    'not_found'
                );
            }

            return $this->success('Reply published under the review.', $this->toRoute('seller.reviews'));
        }, $this->toRoute('seller.reviews'));
    }

    // ---- internals -------------------------------------------------------

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data): Response
    {
        return $this->view($view, array_merge([
            'role'       => 'seller',
            'sampleData' => !in_array($view, self::WIRED, true),
            'canTrade'   => $this->sellers->canTrade($this->sellerId()),
        ], $data), 'dashboard');
    }

    /**
     * The seller this request acts as.
     *
     * Throws rather than returning null: every method here needs one, and a
     * null that reached a query would silently scope to nothing instead of
     * failing loudly.
     */
    private function sellerId(): int
    {
        $id = Auth::sellerId();

        if ($id === null) {
            throw new HttpException(403, 'Your account is not set up as a seller yet.');
        }

        return $id;
    }

    /**
     * Sub-orders in one of the dashboard's groups.
     *
     * @return list<array<string,mixed>>
     */
    private function ordersIn(?string $group): array
    {
        $statuses = $group === null ? null : self::GROUPS[$group];

        return SubOrderView::rows(
            $this->orders->forSellerInStatuses($this->sellerId(), $statuses, 100)
        );
    }

    /**
     * One of this seller's sub-orders, or a 404.
     *
     * @return array<string,mixed>
     */
    private function requireOwnOrder(string $ref): array
    {
        $row = $this->orders->subOrderForSeller($ref, $this->sellerId());

        if ($row === null) {
            throw new HttpException(404, 'We could not find that order in your store.');
        }

        return (new SubOrderView())->detail($row);
    }

    /**
     * The shared shape of the four order-action handlers.
     *
     * Each one resolves the sub-order reference to an id it owns, calls a
     * service, and redirects back to the order. The reference is posted rather
     * than the id, because that is what the page is showing.
     *
     * @param callable(int,int):(string|null) $action
     */
    private function orderAction(callable $action, ?string $message = null): Response
    {
        $ref = (string) $this->request()->input('ref', '');

        return $this->attempt(function () use ($action, $message, $ref): Response {
            $sellerId = $this->sellerId();
            $sub      = $this->orders->subOrderForSeller($ref, $sellerId);

            if ($sub === null) {
                throw new DomainRuleException('We could not find that order in your store.', 'not_found');
            }

            $result = $action((int) $sub['id'], $sellerId);

            return $this->success(
                is_string($result) ? $result : (string) $message,
                $this->toRoute('seller.orders.show', ['ref' => $ref])
            );
        }, $ref === ''
            ? $this->toRoute('seller.orders')
            : $this->toRoute('seller.orders.show', ['ref' => $ref]));
    }

    /**
     * A seller's own product row, which shows drafts and archived items too.
     *
     * Not `Present::product()`: that one shapes a product for a customer, and a
     * customer never sees a status or a moderation reason.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentOwnProduct(array $row): array
    {
        $available = (int) $row['available'];
        $threshold = (int) ($row['low_stock_threshold'] ?? 5);

        return [
            'id'            => (int) $row['id'],
            'slug'          => (string) $row['slug'],
            'name'          => (string) $row['name'],
            'sku'           => (string) $row['sku'],
            'brand'         => (string) ($row['brand'] ?? ''),
            'pack_size'     => (string) ($row['pack_size'] ?? ''),
            'price'         => (string) $row['price'],
            'category_name' => (string) $row['category_name'],
            'status'        => (string) $row['status'],
            'moderation_reason' => $row['moderation_reason'] !== null ? (string) $row['moderation_reason'] : null,
            'qty_available' => $available,
            'stock_state'   => match (true) {
                $available <= 0          => 'out',
                $available <= $threshold => 'low',
                default                  => 'in',
            },
            'is_consumable' => (bool) $row['is_consumable'],
            'updated_at_utc' => (string) $row['updated_at'],
        ];
    }

    /**
     * A product row shaped for the edit form.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentForEditing(array $row): array
    {
        $fulfilment = [];
        if (!empty($row['allows_pickup'])) {
            $fulfilment[] = 'pickup';
        }
        if (!empty($row['allows_delivery'])) {
            $fulfilment[] = 'delivery';
        }

        return [
            'id'          => (int) $row['id'],
            'slug'        => (string) $row['slug'],
            'name'        => (string) $row['name'],
            'brand'       => (string) ($row['brand'] ?? ''),
            'sku'         => (string) $row['sku'],
            'description' => (string) $row['description'],
            'price'       => (string) $row['price'],
            'compare_at_price' => $row['compare_at_price'] !== null ? (string) $row['compare_at_price'] : '',
            'unit'        => (string) $row['unit'],
            'pack_size'   => (string) $row['pack_size'],
            'category_slug' => (string) Database::scalar(
                'SELECT slug FROM categories WHERE id = :id',
                ['id' => (int) $row['category_id']]
            ),
            'status'      => (string) $row['status'],
            'moderation_reason' => $row['moderation_reason'] !== null ? (string) $row['moderation_reason'] : null,
            'fulfilment'  => $fulfilment,
            'is_consumable' => (bool) $row['is_consumable'],
            'typical_consumption_days' => $row['typical_consumption_days'] !== null
                ? (int) $row['typical_consumption_days']
                : '',
        ];
    }

    /**
     * Stock per store, each store carrying its own rows.
     *
     * Grouped by store rather than by product because that is how a stocktake
     * happens: somebody stands in one shop and counts what is in it.
     *
     * @return list<array<string,mixed>>
     */
    private function inventoryByStore(int $sellerId): array
    {
        $out = [];

        foreach ($this->stores->forSeller($sellerId) as $store) {
            $storeId = (int) $store['id'];

            $out[] = [
                'id'       => $storeId,
                'name'     => (string) $store['name'],
                'district' => (string) $store['district'],
                'region'   => (string) $store['region'],
                'status'   => (string) $store['status'],
                'rows'     => array_map(
                    static fn (array $r): array => [
                        'product_id'  => (int) $r['product_id'],
                        'name'        => (string) $r['name'],
                        'slug'        => (string) $r['slug'],
                        'sku'         => (string) $r['sku'],
                        'pack_size'   => (string) $r['pack_size'],
                        'status'      => (string) $r['status'],
                        'on_hand'     => (int) $r['qty_on_hand'],
                        'reserved'    => (int) $r['qty_reserved'],
                        'available'   => (int) $r['qty_available'],
                        'threshold'   => (int) $r['low_stock_threshold'],
                        'stock_state' => match (true) {
                            (int) $r['qty_available'] <= 0 => 'out',
                            (int) $r['qty_available'] <= (int) $r['low_stock_threshold'] => 'low',
                            default => 'in',
                        },
                    ],
                    $this->inventory->forStore($storeId)
                ),
            ];
        }

        return $out;
    }

    /**
     * Attaches the line items to a small list of sub-orders.
     *
     * One extra query per row, so it is used on the handful of cards the
     * dashboard shows and never on a full list.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withItems(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i]['items'] = array_map(
                static fn (array $item): array => [
                    'name' => (string) $item['name_snapshot'],
                    'sku'  => (string) $item['sku_snapshot'],
                    'qty'  => (int) $item['qty'],
                ],
                $this->orders->itemsFor((int) $row['seller_order_id'])
            );
        }

        return $rows;
    }

    /**
     * Where one product sits, across this seller's stores.
     *
     * Only their own stores: the same product id can be stocked by nobody else,
     * but the store join is scoped anyway rather than relying on that.
     *
     * @return list<array<string,mixed>>
     */
    private function stockFor(int $productId, int $sellerId): array
    {
        return array_map(
            static fn (array $r): array => [
                'store_name' => (string) $r['store_name'],
                'on_hand'    => (int) $r['qty_on_hand'],
                'reserved'   => (int) $r['qty_reserved'],
                'available'  => (int) $r['qty_available'],
            ],
            array_values(array_filter(
                $this->inventory->forSeller($sellerId),
                static fn (array $r): bool => (int) $r['product_id'] === $productId
            ))
        );
    }

    /** The trading name this seller's dashboard is headed with. */
    private function businessName(int $sellerId): string
    {
        $seller = $this->sellers->find($sellerId);

        return (string) ($seller['business_name'] ?? 'Your store');
    }

    /** @return list<array<string,mixed>> */
    private function lowStock(int $sellerId): array
    {
        return array_map(
            static fn (array $r): array => [
                'product_id'    => (int) $r['product_id'],
                'name'          => (string) $r['product_name'],
                'sku'           => (string) $r['sku'],
                'store_name'    => (string) $r['store_name'],
                'qty_available' => (int) $r['qty_available'],
                'threshold'     => (int) $r['low_stock_threshold'],
                'stock_state'   => (int) $r['qty_available'] <= 0 ? 'out' : 'low',
            ],
            $this->stock->lowStockFor($sellerId)
        );
    }

    /**
     * Revenue per day for the dashboard chart.
     *
     * @return list<array{date:string,revenue:string,orders:int}>
     */
    private function salesSeries(int $sellerId, int $days = 14): array
    {
        return $this->orders->dailyRevenueForSeller($sellerId, $days);
    }

    /** @return array<string,string> */
    private function unitOptions(): array
    {
        $units = ['each', 'pack', 'bottle', 'jerrycan', 'sachet', 'box', 'crate',
                  'bag', 'kg', 'g', 'litre', 'ml', 'bundle', 'tray'];

        return array_combine($units, $units);
    }
}
