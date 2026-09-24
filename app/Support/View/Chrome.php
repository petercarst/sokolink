<?php

declare(strict_types=1);

namespace App\Support\View;

use App\Core\Auth;
use App\Repositories\CartRepository;
use App\Repositories\CategoryRepository;
use App\Support\GuestCart;
use Throwable;

/**
 * The bits of every page that are not about the page: the category menu, the
 * basket count, who is signed in.
 *
 * These are needed by the navigation partial, which is rendered by the layout,
 * and no controller should have to remember to pass them. Sharing them at boot
 * would mean two queries on every request including 404s and error pages, so
 * they are fetched on first use and memoised for the rest of the request.
 *
 * Each accessor swallows a database failure and returns an empty value. The
 * navigation is chrome: if the database is down, the error page explaining that
 * should still render, with a menu that is short rather than a second fatal
 * error thrown from inside the layout.
 */
final class Chrome
{
    /** @var list<array<string,mixed>>|null */
    private static ?array $categories = null;

    private static ?int $cartCount = null;

    /**
     * Top-level categories for the menu.
     *
     * @return list<array<string,mixed>>
     */
    public static function categories(int $limit = 5): array
    {
        if (self::$categories === null) {
            try {
                self::$categories = Present::categories((new CategoryRepository())->topLevelWithCounts());
            } catch (Throwable) {
                self::$categories = [];
            }
        }

        return array_slice(self::$categories, 0, $limit);
    }

    /**
     * How many items are in this visitor's basket.
     *
     * Read-only: it never creates a basket and never issues the guest cookie.
     * A visitor who has not added anything has no row and no cookie, and
     * loading a page must not change that.
     */
    public static function cartCount(): int
    {
        if (self::$cartCount !== null) {
            return self::$cartCount;
        }

        self::$cartCount = 0;

        try {
            $carts  = new CartRepository();
            $userId = Auth::id();

            $cart = $userId !== null
                ? $carts->activeForUser($userId)
                : self::guestCart($carts);

            if ($cart !== null) {
                self::$cartCount = $carts->itemCount((int) $cart['id']);
            }
        } catch (Throwable) {
            self::$cartCount = 0;
        }

        return self::$cartCount;
    }

    /** Forgets the memoised basket count, for a test that drives several requests. */
    public static function flush(): void
    {
        self::$categories = null;
        self::$cartCount  = null;
    }

    /** @return array<string,mixed>|null */
    private static function guestCart(CartRepository $carts): ?array
    {
        $hash = GuestCart::existingHash();

        return $hash === null ? null : $carts->activeForCookie($hash);
    }
}
