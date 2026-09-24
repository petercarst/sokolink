<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Response;
use App\Core\Session;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;

/**
 * The authentication screens.
 *
 * The forms post to AuthController. Two of these pages do more than render,
 * because they are the landing point of an emailed link and the link itself is
 * the action: /verify-email?token=... confirms the address, and
 * /reset-password?token=... has to decide whether to show the form at all.
 *
 * Verification is a GET that changes state, which is normally worth avoiding.
 * It is the right trade here: the alternative is an email containing a form,
 * and a single-use token consumed in a transaction already makes a second
 * click harmless.
 */
final class AuthPageController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration = new RegistrationService(),
        private readonly PasswordResetService $passwords = new PasswordResetService(),
    ) {
    }

    public function login(): Response
    {
        return $this->view('auth/login', [
            'title'    => 'Log in',
            'metaDesc' => 'Log in to your SokoLink account.',
        ], 'auth');
    }

    public function register(): Response
    {
        return $this->view('auth/register', [
            'title'    => 'Create an account',
            'metaDesc' => 'Create a SokoLink account to order online and collect or receive delivery.',
        ], 'auth');
    }

    public function registerSeller(): Response
    {
        return $this->view('auth/register-seller', [
            'title'    => 'Apply to sell',
            'metaDesc' => 'Apply to sell on SokoLink. Applications are reviewed by an administrator.',
        ], 'auth');
    }

    public function forgotPassword(): Response
    {
        return $this->view('auth/forgot-password', [
            'title'    => 'Reset your password',
            'metaDesc' => 'Request a password reset link.',
            'sent'     => $this->request()->query('sent') === '1',
        ], 'auth');
    }

    /**
     * The form is only shown for a token that would actually work.
     *
     * Checking first means somebody with a two-day-old link is told so
     * immediately, rather than choosing a password, submitting it, and being
     * told then.
     */
    public function resetPassword(): Response
    {
        $token = (string) $this->request()->query('token', '');

        return $this->view('auth/reset-password', [
            'title'    => 'Choose a new password',
            'metaDesc' => 'Set a new password for your SokoLink account.',
            'token'    => $token,
            'hasToken' => $token !== '' && $this->passwords->tokenIsUsable($token),
        ], 'auth');
    }

    /**
     * The landing page for a verification link, and the page a signed-in but
     * unverified customer is sent to.
     */
    public function verifyEmail(): Response
    {
        $token = (string) $this->request()->query('token', '');
        $state = 'pending';

        if ($token !== '') {
            try {
                $this->registration->verifyEmail($token);

                // The session id changes on any privilege change: an account
                // that was pending_verification a moment ago can now place
                // orders (NFR-SEC-04).
                Session::regenerate();
                Auth::forgetCache();

                $state = 'success';

                Session::flash('success', 'Your email address is confirmed.');
            } catch (DomainRuleException) {
                $state = 'expired';
            }
        }

        return $this->view('auth/verify-email', [
            'title'    => 'Verify your email address',
            'metaDesc' => 'Confirm your email address to finish setting up your account.',
            'state'    => $state,
            'email'    => Auth::email(),
            'canResend' => Auth::check() && !Auth::isVerified(),
        ], 'auth');
    }
}
