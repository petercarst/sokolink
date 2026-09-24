<?php

declare(strict_types=1);

/**
 * Route table.
 *
 * Every route is named, and templates link with route('name') rather than a
 * literal path, so a URL can change here without touching a view.
 *
 * Phase 1 registered the marketplace and the authentication screens; Phase 4
 * added the POST handlers behind them. CSRF is applied to every POST by the
 * router itself rather than listed per route, so it cannot be forgotten on the
 * one route where it mattered.
 */

use App\Controllers\Auth\AuthController;
use App\Controllers\Auth\AuthPageController;
use App\Controllers\Web\CartController;
use App\Controllers\Web\CatalogController;
use App\Controllers\Web\CheckoutController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\PageController;
use App\Controllers\Web\PaymentController;
use App\Controllers\Web\PreviewController;
use App\Controllers\Web\ProductController;
use App\Controllers\Web\SearchController;
use App\Controllers\Web\StoreController;
use App\Controllers\Web\StyleguideController;
use App\Core\Router;

// ---- Public marketplace (cinematic track) ---------------------------------
Router::get('/',                  [HomeController::class, 'index'],       'home');
Router::get('/products',          [CatalogController::class, 'index'],    'catalog.index');
Router::get('/category/{slug}',   [CatalogController::class, 'category'], 'catalog.category');
Router::get('/products/{slug}',   [ProductController::class, 'show'],     'product.show');
Router::get('/store/{slug}',      [StoreController::class, 'show'],       'store.show');
Router::get('/search',            [SearchController::class, 'index'],     'search');

// ---- Basket (transactional track) -----------------------------------------
// The basket is open to guests: somebody can fill one before they have an
// account, and it is merged into their account basket when they sign in.
Router::get('/cart',            [CartController::class, 'show'],           'cart');
Router::post('/cart/add',       [CartController::class, 'add'],            'cart.add');
Router::post('/cart/update',    [CartController::class, 'updateQuantity'], 'cart.update');
Router::post('/cart/remove',    [CartController::class, 'remove'],         'cart.remove');
Router::post('/cart/store',     [CartController::class, 'chooseStore'],    'cart.store');

// ---- Checkout --------------------------------------------------------------
// Signed in AND verified. Both gates are here as well as in CheckoutService:
// the middleware gives an unverified customer a useful page instead of an
// exception, and the service check is what actually stops the order.
Router::group(['middleware' => ['auth', 'verified']], function (): void {
    Router::get('/checkout',              [CheckoutController::class, 'fulfilment'],   'checkout.fulfilment');
    Router::post('/checkout',             [CheckoutController::class, 'saveFulfilment'], 'checkout.fulfilment.save');
    Router::get('/checkout/payment',      [CheckoutController::class, 'payment'],      'checkout.payment');
    Router::post('/checkout/place',       [CheckoutController::class, 'place'],        'checkout.place');
    Router::get('/checkout/confirmation', [CheckoutController::class, 'confirmation'], 'checkout.confirmation');
});

// ---- Authentication --------------------------------------------------------
// The forms are guest-only; a signed-in visitor asking for /login is sent to
// their dashboard rather than shown a form that would log them out of nothing.
Router::group(['middleware' => ['guest']], function (): void {
    Router::get('/login',           [AuthPageController::class, 'login'],          'auth.login');
    Router::get('/register',        [AuthPageController::class, 'register'],       'auth.register');
    Router::get('/register/seller', [AuthPageController::class, 'registerSeller'], 'auth.register.seller');
    Router::get('/forgot-password', [AuthPageController::class, 'forgotPassword'], 'auth.forgot');
    Router::get('/reset-password',  [AuthPageController::class, 'resetPassword'],  'auth.reset');
});

// Verification is reachable while signed in: an account created in one browser
// may well be verified in another, and a signed-in but unverified customer
// needs this page to resend the email.
Router::get('/verify-email', [AuthPageController::class, 'verifyEmail'], 'auth.verify');

// Two limiters, doing different jobs. AuthService uses the table-backed
// RateLimiter keyed on email AND ip, which is what stops one account being
// ground down across sessions. The `throttle` here is session-backed and
// cheaper: it caps how fast one browser can submit at all, including the
// registration and reset forms, which have no account to key on yet.
Router::post('/login',           [AuthController::class, 'login'],          'auth.login.submit',           ['guest', 'throttle:10,60']);
Router::post('/register',        [AuthController::class, 'register'],       'auth.register.submit',        ['guest', 'throttle:5,600']);
Router::post('/register/seller', [AuthController::class, 'registerSeller'], 'auth.register.seller.submit', ['guest', 'throttle:5,600']);
Router::post('/forgot-password', [AuthController::class, 'forgotPassword'], 'auth.forgot.submit',          ['guest', 'throttle:5,900']);
Router::post('/reset-password',  [AuthController::class, 'resetPassword'],  'auth.reset.submit',           ['guest', 'throttle:10,900']);
Router::post('/verify-email/resend', [AuthController::class, 'resendVerification'], 'auth.verify.resend',  ['throttle:3,600']);
Router::post('/logout',          [AuthController::class, 'logout'],         'auth.logout',                 ['auth']);

// ---- Static and informational ---------------------------------------------
Router::get('/about',         [PageController::class, 'about'],       'page.about');
Router::get('/contact',       [PageController::class, 'contact'],     'page.contact');
Router::get('/terms',         [PageController::class, 'terms'],       'page.terms');
Router::get('/privacy',       [PageController::class, 'privacy'],     'page.privacy');
Router::get('/sell-with-us',  [PageController::class, 'sellWithUs'],  'page.sell');
Router::get('/unsubscribe',   [PageController::class, 'unsubscribe'], 'page.unsubscribe');
// No auth: the token in the link is the identity. Requiring a login to stop
// marketing email is a dark pattern (FR-CRM-07).
Router::post('/unsubscribe',  [PageController::class, 'unsubscribeSubmit'], 'page.unsubscribe.submit', ['throttle:10,600']);

// ---- Design system ---------------------------------------------------------
Router::get('/styleguide',            [StyleguideController::class, 'index'],        'styleguide');
Router::get('/styleguide/error/{code}', [StyleguideController::class, 'errorPreview'], 'styleguide.error');

// ---- Payments ---------------------------------------------------------------
// The provider's endpoint. No session, no CSRF token - a provider's server has
// neither and cannot get one. What authenticates it is an HMAC signature over
// the payload, checked in the gateway driver before anything is believed. The
// `webhook` marker is what exempts it, and it is here in the route table so the
// exemption is visible rather than assumed.
Router::post('/payments/callback/{gateway}', [PaymentController::class, 'callback'], 'payment.callback', ['webhook']);

// The local stand-in for a provider, available only while the sandbox driver is
// the configured one. It signs a real payload and posts it to the real handler.
Router::post('/payments/simulate', [PaymentController::class, 'simulate'], 'payment.simulate', ['auth']);

// ---- Form sink for the areas Phase 4 has not reached yet --------------------
// Phase 4 is being wired one area at a time. The customer path - auth, basket,
// checkout, orders - posts to its real handlers above. The seller, delivery,
// support and admin forms still land here, where the CSRF token is genuinely
// verified and the response says plainly that the action is not connected yet.
// This route is deleted when the last of those forms is wired, which is the
// same moment app/Views/_mock/ is deleted.
Router::post('/preview/submit', [PreviewController::class, 'handle'], 'preview.submit');

// ---- Role dashboards (Phase 1b) --------------------------------------------
// Kept in their own file: 60+ routes in one table stops being readable.
require __DIR__ . '/dashboard.php';
