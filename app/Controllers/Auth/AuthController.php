<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;
use App\Services\CartService;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;
use App\Support\GuestCart;

/**
 * The write side of authentication.
 *
 * Everything that decides anything lives in the services; this class reads the
 * form, calls one of them, and picks where to send the browser afterwards. Two
 * things it does have opinions about, because neither belongs in a service:
 *
 *   - The basket a visitor filled before signing in is merged into their
 *     account basket, then the guest cookie is dropped.
 *   - A password reset link is shown in the flash message when the mail driver
 *     is `log`, because on a local install there is no inbox to open. That is
 *     gated on the driver, never on the environment name.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly RegistrationService $registration = new RegistrationService(),
        private readonly PasswordResetService $passwords = new PasswordResetService(),
        private readonly CartService $carts = new CartService(),
    ) {
    }

    public function login(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $user = $this->auth->attempt(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                $request->ip()
            );

            $this->adoptGuestBasket((int) $user['id']);

            return $this->success(
                'Welcome back, ' . $user['first_name'] . '.',
                $this->redirect($this->destinationAfterLogin())
            );
        }, $this->toRoute('auth.login'));
    }

    public function logout(): Response
    {
        $this->auth->logout();

        return $this->success('You are signed out.', $this->toRoute('home'));
    }

    public function register(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $result = $this->registration->registerCustomer($request->postAll(), $request->ip());

            $this->adoptGuestBasket($result['userId']);

            // Deliberately NOT signed in. The account is pending_verification
            // until the emailed link is clicked, and signing them in here would
            // make that status meaningless.
            Session::flash(
                'success',
                'Account created. Check ' . (string) $request->input('email', 'your inbox')
                . ' for the link that confirms your address.'
            );

            $this->offerLocalLink('verification', $result['verificationToken'], 'auth.verify');

            return $this->redirect(route('auth.verify'));
        }, $this->toRoute('auth.register'));
    }

    public function registerSeller(): Response
    {
        return $this->attempt(function (): Response {
            $request = $this->request();

            $result = $this->registration->registerSeller($request->postAll(), $request->ip());

            Session::flash(
                'success',
                'Application received. Confirm your email address, and an administrator will review your application.'
            );

            $this->offerLocalLink('verification', $result['verificationToken'], 'auth.verify');

            return $this->redirect(route('auth.verify'));
        }, $this->toRoute('auth.register.seller'));
    }

    /**
     * Requests a reset link.
     *
     * Always the same response, whether or not the address is registered. A
     * different message for a known address is an account enumeration oracle,
     * which is the whole reason PasswordResetService returns null rather than
     * throwing for an unknown email (USER_FLOWS.md Flow A).
     */
    public function forgotPassword(): Response
    {
        $request = $this->request();

        try {
            $token = $this->passwords->request((string) $request->input('email', ''), $request->ip());

            if ($token !== null) {
                $this->offerLocalLink('password reset', $token, 'auth.reset');
            }
        } catch (DomainRuleException $e) {
            // Throttling is the one thing worth saying out loud: it is about
            // this browser, not about whether the account exists.
            Session::flash('error', $e->getMessage());

            return $this->toRoute('auth.forgot');
        }

        return $this->redirect(route('auth.forgot') . '?sent=1');
    }

    public function resetPassword(): Response
    {
        $token = (string) $this->request()->input('token', '');

        return $this->attempt(function () use ($token): Response {
            $request = $this->request();

            $this->passwords->reset(
                $token,
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
                $request->ip()
            );

            return $this->success(
                'Your password has been changed. Sign in with the new one.',
                $this->toRoute('auth.login')
            );
        }, $this->redirect(route('auth.reset') . '?token=' . rawurlencode($token)));
    }

    public function resendVerification(): Response
    {
        $userId = Auth::id();

        if ($userId === null) {
            // Without a session there is no account to resend for, and asking
            // for an email address here would turn this into a way to find out
            // which addresses are registered.
            Session::flash('info', 'Sign in and we can send the confirmation link again.');

            return $this->toRoute('auth.login');
        }

        return $this->attempt(function () use ($userId): Response {
            $token = $this->registration->resendVerification($userId, $this->request()->ip());

            $this->offerLocalLink('verification', $token, 'auth.verify');

            return $this->success('Confirmation email sent again.', $this->toRoute('auth.verify'));
        }, $this->toRoute('auth.verify'));
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * Moves a guest basket onto the account, then drops the cookie.
     *
     * Quantities are added rather than replaced, so nothing somebody put in a
     * basket before signing in is silently discarded.
     */
    private function adoptGuestBasket(int $userId): void
    {
        $hash = GuestCart::existingHash();

        if ($hash === null) {
            return;
        }

        $moved = $this->carts->mergeGuestCart($hash, $userId);

        GuestCart::forget();

        if ($moved > 0) {
            Session::flash(
                'info',
                $moved === 1
                    ? 'The item in your basket has been saved to your account.'
                    : 'The ' . $moved . ' items in your basket have been saved to your account.'
            );
        }
    }

    /**
     * Where a successful sign-in lands.
     *
     * A customer who was sent to the login page from checkout goes back to
     * checkout; everybody else goes to the dashboard their role implies.
     */
    private function destinationAfterLogin(): string
    {
        $intended = Session::pull('_intended_url');

        // A path, never a full URL: AuthMiddleware stores Request::path(),
        // which is same-origin by construction. Accepting an absolute URL here
        // would turn the login form into an open redirect.
        if (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
            return url($intended);
        }

        return route(match (Auth::primaryRole()) {
            'admin'          => 'admin.dashboard',
            'support'        => 'support.dashboard',
            'delivery_agent' => 'delivery.dashboard',
            'seller'         => 'seller.dashboard',
            default          => 'customer.dashboard',
        });
    }

    /**
     * On a local install with no mail server, puts the link in the page.
     *
     * Gated on the mail driver being `log` - the condition is "these emails are
     * going to a file, so nobody can click them", not "this is development".
     * With a real driver configured, nothing is ever shown.
     */
    private function offerLocalLink(string $what, string $token, string $routeName): void
    {
        if ((string) config('mail.driver') !== 'log') {
            return;
        }

        Session::flash(
            'info',
            'No mail server is configured, so the ' . $what . ' email was written to storage/logs instead. '
            . 'The link is: ' . route($routeName) . '?token=' . $token
        );
    }
}
