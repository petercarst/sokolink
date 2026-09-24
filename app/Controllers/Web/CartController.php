<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Response;
use App\Repositories\AddressRepository;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Support\GuestCart;
use App\Support\View\CartView;
use App\Support\View\Present;

/**
 * The basket.
 *
 * Signed in or not - a visitor can fill a basket before they have an account,
 * and it is merged into their account basket when they sign in (see
 * AuthController::login). Requiring registration to hold three items is how a
 * shop loses the sale.
 *
 * Nothing here computes a price. Quantities are the only number the customer
 * controls; every amount on the page comes back from PricingService.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart = new CartService(),
        private readonly CatalogService $catalog = new CatalogService(),
        private readonly CartView $presenter = new CartView(),
        private readonly AddressRepository $addresses = new AddressRepository(),
    ) {
    }

    public function show(): Response
    {
        $cartId  = $this->existingCartId();
        $methods = $this->methods();
        $address = $this->defaultAddress();

        $cart = $cartId === null
            ? CartView::emptyBasket()
            : $this->presenter->present($this->cart->summary($cartId, $methods, $address), $methods);

        return $this->view('web/cart', [
            'title'     => 'Your basket',
            'metaDesc'  => 'Review your basket before checkout.',
            'cart'      => $cart,
            // Delivery costs what the customer's zone says it costs. Without an
            // address there is no zone, so the page says the fee is worked out
            // at checkout instead of showing a figure it cannot stand behind.
            'deliveryAddress' => $address,
            'suggested' => Present::products($this->catalog->featured(4)),
            'track'     => 'transactional',
        ], 'public');
    }

    /**
     * The address used to estimate delivery on the basket page.
     *
     * Only for a signed-in customer who has saved one. A guest sees "worked out
     * at checkout", which is the truth - we do not know where they are.
     *
     * @return array<string,mixed>|null
     */
    private function defaultAddress(): ?array
    {
        $userId = Auth::id();

        return $userId === null ? null : $this->addresses->defaultFor($userId);
    }

    public function add(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $productId = (int) $request->input('product_id', 0);
            $quantity  = (int) $request->input('qty', 1);
            $storeId   = (int) $request->input('store_id', 0);

            $this->cart->add(
                $this->cartId(),
                $productId,
                $quantity,
                $storeId > 0 ? $storeId : null
            );

            return $this->success('Added to your basket.', $this->back());
        });
    }

    public function updateQuantity(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $this->cart->updateQuantity(
                $this->cartId(),
                (int) $request->input('cart_item_id', 0),
                (int) $request->input('qty', 1)
            );

            return $this->success('Basket updated.', $this->toRoute('cart'));
        }, $this->toRoute('cart'));
    }

    public function remove(): Response
    {
        return $this->attempt(function (): Response {
            $this->cart->remove(
                $this->cartId(),
                (int) $this->request()->input('cart_item_id', 0)
            );

            return $this->success('Removed from your basket.', $this->toRoute('cart'));
        }, $this->toRoute('cart'));
    }

    /**
     * Sets the collection point for every line belonging to one seller.
     *
     * Per seller rather than per line, because a seller group becomes one
     * sub-order collected from one counter - letting two lines from the same
     * seller point at different branches would create an order that cannot be
     * collected.
     */
    public function chooseStore(): Response
    {
        return $this->attempt(function (): Response {
            $request  = $this->request();
            $cartId   = $this->cartId();
            $sellerId = (int) $request->input('seller_id', 0);
            $storeId  = (int) $request->input('store_id', 0);

            $summary = $this->cart->summary($cartId);

            foreach ($summary['items'] as $item) {
                if ((int) $item['seller_id'] !== $sellerId) {
                    continue;
                }

                $this->cart->chooseStore($cartId, (int) $item['cart_item_id'], $storeId > 0 ? $storeId : null);
            }

            return $this->success('Collection point updated.', $this->toRoute('cart'));
        }, $this->toRoute('cart'));
    }

    /**
     * The basket for this visitor, creating one if there is none.
     *
     * Write paths only. A read that called this would issue a basket cookie to
     * everybody who ever loaded a page.
     */
    private function cartId(): int
    {
        return $this->cart->currentCartId(GuestCart::hash());
    }

    /** The basket if one already exists, without creating anything. */
    private function existingCartId(): ?int
    {
        $hash = GuestCart::existingHash();

        if ($hash === null && !Auth::check()) {
            return null;
        }

        return $this->cart->currentCartId($hash ?? '');
    }

    /**
     * Fulfilment choices carried in the query string.
     *
     * The basket page lets someone toggle collection and delivery to compare
     * totals before committing to either. Those choices are not stored until
     * checkout, so they travel in the URL - which also means the comparison
     * survives a page refresh and can be linked to.
     *
     * @return array<int,string>
     */
    private function methods(): array
    {
        $raw = $this->request()->query('fulfilment', []);

        if (!is_array($raw)) {
            return [];
        }

        $methods = [];

        foreach ($raw as $sellerId => $method) {
            if (in_array($method, ['pickup', 'delivery'], true)) {
                $methods[(int) $sellerId] = (string) $method;
            }
        }

        return $methods;
    }
}
