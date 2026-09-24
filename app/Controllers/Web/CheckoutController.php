<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\Exceptions\ValidationException;
use App\Domain\Enums\ConsentType;
use App\Repositories\AddressRepository;
use App\Repositories\ConsentRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ZoneRepository;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\Payment\CashEligibility;
use App\Services\PaymentService;
use App\Support\GuestCart;
use App\Support\View\CartView;
use App\Support\View\OrderView;

/**
 * Checkout, in three steps and two POSTs.
 *
 *   GET  /checkout              choose collection or delivery, per seller
 *   POST /checkout              save those choices, go to payment
 *   GET  /checkout/payment      review the real totals, choose how to pay
 *   POST /checkout/place        place the order
 *   GET  /checkout/confirmation what was created
 *
 * Checkout is on the TRANSACTIONAL track even though it is reached from the
 * public site: somebody entering an address and confirming a total needs
 * density and clarity, not 96px display type (DESIGN_SYSTEM.md section 1).
 *
 * Two things this class is careful about.
 *
 * **Nothing is trusted from the browser except choices.** Which seller delivers
 * and which store to collect from are the customer's to pick; every amount is
 * recomputed by PricingService from the product rows at the moment the order is
 * created. A posted total is not validated - it is ignored.
 *
 * **Placing an order carries an idempotency key** minted when the payment page
 * is rendered and held in the session. A double-clicked button, a refreshed
 * POST or a browser retry all replay the same key, and CheckoutService returns
 * the order it already made rather than making a second one.
 */
final class CheckoutController extends Controller
{
    /** Where the fulfilment choices live between step 1 and the order. */
    private const SESSION_KEY = '_checkout';

    /** The idempotency key for the order about to be placed. */
    private const IDEMPOTENCY_KEY = '_checkout_key';

    /** The order number just placed, so the confirmation page can find it. */
    private const PLACED_KEY = '_checkout_placed';

    public function __construct(
        private readonly CartService $cart = new CartService(),
        private readonly CheckoutService $checkout = new CheckoutService(),
        private readonly CartView $presenter = new CartView(),
        private readonly AddressRepository $addresses = new AddressRepository(),
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ZoneRepository $zones = new ZoneRepository(),
        private readonly CashEligibility $cash = new CashEligibility(),
    ) {
    }

    /** Step 1 - fulfilment method, store or address, per sub-order. */
    public function fulfilment(): Response
    {
        $cartId = $this->cartId();
        $choices = $this->choices();

        $summary = $this->cart->summary($cartId, $choices['methods'], $this->chosenAddress($choices));

        if (!empty($summary['empty'])) {
            return $this->emptyBasket();
        }

        $cart = $this->presenter->present($summary, $choices['methods']);

        return $this->view('web/checkout-fulfilment', [
            'title'      => 'Checkout - delivery or collection',
            'metaDesc'   => 'Choose collection or delivery for each seller in your basket.',
            'cart'       => $cart,
            'step'       => 1,
            'addresses'  => $this->addressOptions(),
            'choices'    => $choices,
            'track'      => 'transactional',
        ], 'public');
    }

    /** Saves step 1 and moves on. Nothing is written to the order yet. */
    public function saveFulfilment(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $methods = $this->postedMap('fulfilment', ['pickup', 'delivery']);
            $stores  = $this->postedIntMap('store');

            $addressId = $this->resolveAddress();

            // If nothing is being delivered, an address is beside the point and
            // demanding one would block a collection-only order.
            if (!in_array('delivery', $methods, true)) {
                $addressId = null;
            } elseif ($addressId === null) {
                throw ValidationException::forField(
                    'address_id',
                    'Choose a delivery address, or switch every seller to collection.'
                );
            }

            Session::put(self::SESSION_KEY, [
                'methods'    => $methods,
                'stores'     => $stores,
                'address_id' => $addressId,
            ]);

            // A new run at the payment page is a new order attempt, so it gets
            // a fresh key. Reusing the previous one would make a second,
            // genuinely different order look like a replay of the first.
            Session::forget(self::IDEMPOTENCY_KEY);

            return $this->toRoute('checkout.payment');
        }, $this->toRoute('checkout.fulfilment'));
    }

    /** Step 2 - the real totals, and how to pay. */
    public function payment(): Response
    {
        $cartId  = $this->cartId();
        $choices = $this->choices();
        $address = $this->chosenAddress($choices);

        try {
            $summary = $this->cart->summary($cartId, $choices['methods'], $address);
        } catch (DomainRuleException $e) {
            Session::flash('error', $e->getMessage());

            return $this->toRoute('checkout.fulfilment');
        }

        if (!empty($summary['empty'])) {
            return $this->emptyBasket();
        }

        // Anything that would stop this becoming an order sends the customer
        // back to the step that can fix it, rather than letting them choose a
        // payment method for an order that will be refused.
        if ($summary['problems'] !== []) {
            Session::flash('error', (string) $summary['problems'][0]['message']);

            return $this->toRoute('cart');
        }

        // Cash carries limits the other methods do not, because it is the one
        // where the seller holds goods for somebody who has committed nothing.
        // The verdict is folded into the options list, so an unusable method is
        // shown with its reason rather than offered and then refused.
        $cashVerdict = $this->cash->forBasket(
            (int) Auth::id(),
            $summary['items'],
            (string) $summary['grand_total']
        );

        return $this->view('web/checkout-payment', [
            'title'    => 'Checkout - payment',
            'metaDesc' => 'Review your order and choose how to pay.',
            'cart'     => $this->presenter->present($summary, $choices['methods']),
            'step'     => 2,
            'address'  => $address,
            'paymentOptions' => (new PaymentService())->optionsForCheckout($cashVerdict),
            'idempotencyKey' => $this->idempotencyKey(),
            'track'    => 'transactional',
        ], 'public');
    }

    /** Places the order. The only POST in the application that creates money rows. */
    public function place(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();
            $choices = $this->choices();

            if (!$request->input('accept_terms')) {
                throw ValidationException::forField(
                    'accept_terms',
                    'Accept the terms and the privacy notice to place this order.'
                );
            }

            $result = $this->checkout->placeOrder(
                $this->cartId(),
                $choices['methods'],
                $this->chosenAddress($choices),
                $choices['stores'],
                $this->paymentMethod(),
                $this->idempotencyKey()
            );

            // Only on a real order, not a replay: a refreshed confirmation must
            // not write a second consent row saying the same thing.
            if (!$result['replayed'] && $request->input('marketing_consent')) {
                $this->grantMarketingConsent($request->ip());
            }

            Session::put(self::PLACED_KEY, $result['order_number']);

            // The basket is gone and so is the key that guarded this attempt.
            // A later POST with the old key would be a replay of an order the
            // customer has already been shown.
            Session::forget(self::SESSION_KEY);
            Session::forget(self::IDEMPOTENCY_KEY);
            GuestCart::forget();

            return $this->success(
                $result['replayed']
                    ? 'That order was already placed - here it is.'
                    : 'Order ' . $result['order_number'] . ' placed.',
                $this->toRoute('checkout.confirmation')
            );
        }, $this->toRoute('checkout.payment'));
    }

    /** Step 3 - reached only by redirect, so a refresh cannot resubmit. */
    public function confirmation(): Response
    {
        $orderNumber = Session::get(self::PLACED_KEY);
        $userId      = Auth::id();

        $order = is_string($orderNumber) && $userId !== null
            ? $this->orders->findForCustomer($orderNumber, $userId)
            : null;

        if ($order === null) {
            Session::flash('info', 'There is no recent order to show here.');

            return $this->toRoute('customer.orders');
        }

        return $this->view('web/checkout-confirmation', [
            'title'       => 'Order placed',
            'metaDesc'    => 'Your order has been placed.',
            'order'       => (new OrderView())->present(
                $order,
                $this->orders->sellerOrdersFor((int) $order['id'])
            ),
            'step'        => 3,
            // Only for an order that is actually waiting on a provider that
            // does not exist. A cash order is not waiting for anything, and a
            // paid one has nothing left to confirm.
            'canSimulate' => (string) $order['payment_status'] === 'pending'
                && (string) config('payment.driver', 'sandbox') === 'sandbox',
            'track'       => 'transactional',
        ], 'public');
    }

    /**
     * The marketing checkbox on the payment page.
     *
     * This can only ever GRANT consent, never withdraw it. An unticked box is
     * somebody who did not look at a checkbox while buying cooking oil; it is
     * not a decision to stop hearing from us, and treating it as one would
     * silently revoke a consent the customer gave deliberately somewhere else.
     * Withdrawal happens where it is meant: the preferences page, or the
     * one-click unsubscribe link in every message.
     *
     * Nothing is written when they already consent, so placing four orders
     * does not leave four identical rows in the consent history.
     */
    private function grantMarketingConsent(string $ip): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        $consents = new ConsentRepository();

        if ($consents->currentlyGrants($userId, ConsentType::Marketing)) {
            return;
        }

        $consents->record($userId, ConsentType::Marketing, true, 'checkout', $ip, $userId);
    }

    // ---- state -----------------------------------------------------------

    /**
     * The choices made in step 1.
     *
     * @return array{methods:array<int,string>,stores:array<int,int>,address_id:?int}
     */
    private function choices(): array
    {
        $stored = Session::get(self::SESSION_KEY);

        if (!is_array($stored)) {
            return ['methods' => [], 'stores' => [], 'address_id' => $this->defaultAddressId()];
        }

        return [
            'methods'    => is_array($stored['methods'] ?? null) ? $stored['methods'] : [],
            'stores'     => is_array($stored['stores'] ?? null) ? $stored['stores'] : [],
            'address_id' => isset($stored['address_id']) ? (int) $stored['address_id'] ?: null : null,
        ];
    }

    /**
     * @param  array{address_id:?int} $choices
     * @return array<string,mixed>|null
     */
    private function chosenAddress(array $choices): ?array
    {
        $userId    = Auth::id();
        $addressId = $choices['address_id'] ?? null;

        if ($userId === null) {
            return null;
        }

        // findOwned, not find: an address id posted by one customer must not
        // be able to read another's (NFR-SEC-09).
        return $addressId !== null
            ? $this->addresses->findOwned($addressId, $userId)
            : $this->addresses->defaultFor($userId);
    }

    private function defaultAddressId(): ?int
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        $address = $this->addresses->defaultFor($userId);

        return $address === null ? null : (int) $address['id'];
    }

    /**
     * A key that survives a refresh but not a new checkout.
     *
     * Minted on the payment page and reused until an order is placed, so every
     * submission of that page's form carries the same key.
     */
    private function idempotencyKey(): string
    {
        $key = Session::get(self::IDEMPOTENCY_KEY);

        if (!is_string($key) || $key === '') {
            $key = bin2hex(random_bytes(16));
            Session::put(self::IDEMPOTENCY_KEY, $key);
        }

        return $key;
    }

    private function cartId(): int
    {
        return $this->cart->currentCartId(GuestCart::existingHash() ?? '');
    }

    private function emptyBasket(): Response
    {
        Session::flash('info', 'Your basket is empty.');

        return $this->toRoute('cart');
    }

    // ---- input -----------------------------------------------------------

    /**
     * The payment method, from an allow-list.
     *
     * An unrecognised value becomes the sandbox driver rather than reaching
     * PaymentService, which would refuse it with a less useful message.
     */
    private function paymentMethod(): string
    {
        $method = (string) $this->request()->input('payment_method', 'sandbox');

        // An allow-list, not a validation. A method that is not on it - a
        // mobile-money key somebody typed in by hand, say - falls back rather
        // than reaching PaymentService, which would refuse it with a less
        // useful message. Whether the chosen method is allowed for THIS basket
        // is a separate question, answered by CheckoutService.
        return in_array($method, ['sandbox', 'cash'], true) ? $method : 'sandbox';
    }

    /**
     * `name[sellerId] = value`, keeping only values on the allow-list.
     *
     * @param  list<string> $allowed
     * @return array<int,string>
     */
    private function postedMap(string $field, array $allowed): array
    {
        $raw = $this->request()->input($field, []);

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $sellerId => $value) {
            if (in_array($value, $allowed, true)) {
                $out[(int) $sellerId] = (string) $value;
            }
        }

        return $out;
    }

    /** @return array<int,int> */
    private function postedIntMap(string $field): array
    {
        $raw = $this->request()->input($field, []);

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $sellerId => $value) {
            if ((int) $value > 0) {
                $out[(int) $sellerId] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * The delivery address for this order: one already saved, or a new one.
     *
     * A new address is saved to the address book rather than attached to the
     * order alone, because the next order should not ask for it again. The
     * order keeps its own snapshot regardless - delivery_tasks copies the
     * address rather than pointing at it, so editing it later cannot rewrite
     * where a parcel was sent.
     */
    private function resolveAddress(): ?int
    {
        $request = $this->request();
        $userId  = Auth::id();

        if ($userId === null) {
            return null;
        }

        $posted = (string) $request->input('address_id', '');

        if ($posted !== '' && $posted !== 'new') {
            $address = $this->addresses->findOwned((int) $posted, $userId);

            if ($address === null) {
                throw ValidationException::forField('address_id', 'Choose one of your saved addresses.');
            }

            return (int) $address['id'];
        }

        if ($posted !== 'new') {
            return null;
        }

        return $this->createAddress($userId);
    }

    private function createAddress(int $userId): int
    {
        $request = $this->request();

        $validator = Validator::make([
            'recipient'    => $request->input('addr_recipient', ''),
            'phone'        => $request->input('addr_phone', ''),
            'region'       => $request->input('addr_region', ''),
            'district'     => $request->input('addr_district', ''),
            'ward'         => $request->input('addr_ward', ''),
            'street'       => $request->input('addr_street', ''),
            'landmark'     => $request->input('addr_landmark', ''),
            'instructions' => $request->input('addr_instructions', ''),
        ], [
            'recipient'    => 'required|max:160',
            'phone'        => 'required|phone',
            'region'       => 'required|max:80',
            'district'     => 'required|max:80',
            'ward'         => 'nullable|max:80',
            'street'       => 'required|max:190',
            'landmark'     => 'nullable|max:190',
            'instructions' => 'nullable|max:500',
        ], [
            'recipient' => 'Recipient name',
            'phone'     => 'Phone number',
            'street'    => 'Street and house number',
        ]);

        if ($validator->fails()) {
            // The form's fields are prefixed, so the messages have to be too,
            // or they would land beside nothing.
            $errors = [];
            foreach ($validator->errors() as $field => $message) {
                $errors['addr_' . $field] = $message;
            }

            throw new ValidationException($errors);
        }

        $data = $validator->validated();

        // The zone is resolved once, when the address is saved, and stored. It
        // is what makes "we do not deliver there" answerable before payment
        // rather than after (FR-CART-07).
        $zone = $this->zones->forAddress($data['region'], $data['district']);

        $data['zone_id']    = $zone === null ? null : (int) $zone['id'];
        $data['label']      = mb_substr((string) ($this->request()->input('addr_label', '') ?: 'Delivery'), 0, 60);
        $data['is_default'] = $this->addresses->countFor($userId) === 0;

        return $this->addresses->create($userId, $data);
    }

    /**
     * Saved addresses, with the zone spelled out.
     *
     * An address in no zone is shown as such: it cannot be delivered to, and
     * the customer needs to know that here rather than at the payment step.
     *
     * @return list<array<string,mixed>>
     */
    private function addressOptions(): array
    {
        $userId = Auth::id();

        if ($userId === null) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'id'           => (int) $row['id'],
                'label'        => (string) $row['label'],
                'is_default'   => (bool) $row['is_default'],
                'recipient'    => (string) $row['recipient'],
                'phone_masked' => \App\Support\View\Present::store(['phone' => $row['phone']])['phone_masked'],
                'region'       => (string) $row['region'],
                'district'     => (string) $row['district'],
                'ward'         => (string) ($row['ward'] ?? ''),
                'street'       => (string) $row['street'],
                'landmark'     => (string) ($row['landmark'] ?? ''),
                'instructions' => (string) ($row['instructions'] ?? ''),
                'zone'         => $row['zone_name'] !== null ? (string) $row['zone_name'] : null,
                'zone_fee'     => $row['base_fee'] !== null ? (string) $row['base_fee'] : null,
            ],
            $this->addresses->forUser($userId)
        );
    }
}
