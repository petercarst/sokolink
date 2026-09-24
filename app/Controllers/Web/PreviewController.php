<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Response;
use App\Core\Session;

/**
 * PHASE 1 ONLY - the single POST target for forms that are not yet wired.
 *
 * The brief forbids decorative controls that do nothing, and it also forbids
 * pretending that mock login, payment or order processing is real. Those two
 * rules together mean a Phase 1 form must do exactly one honest thing: tell the
 * user, plainly, that it is not connected yet and name the phase that connects
 * it.
 *
 * Every form still carries a real method, a real action and a real CSRF token,
 * and the token really is verified here - so the plumbing that Phase 3 depends
 * on is already exercised rather than bolted on later.
 *
 * Deleted in Phase 4, when every form posts to its real handler.
 */
final class PreviewController extends Controller
{
    /** @var array<string,string> feature key => what will handle it */
    private const FEATURES = [
        'login'            => 'Authentication (Phase 3.2) will verify these credentials and start a session.',
        'register'         => 'Registration (Phase 3.2) will create the account and send a verification email.',
        'register_seller'  => 'Seller registration (Phase 3.2) will create a pending application for admin approval.',
        'password_forgot'  => 'Password reset (Phase 3.2) will email a single-use, time-limited token.',
        'password_reset'   => 'Password reset (Phase 3.2) will validate the token and set the new password.',
        'verify_resend'    => 'Email verification (Phase 3.2) will re-issue the verification token.',
        'cart_add'         => 'Cart (Phase 3.4) will add the line and revalidate availability.',
        'cart_update'      => 'Cart (Phase 3.4) will update the quantity and recalculate totals server-side.',
        'cart_remove'      => 'Cart (Phase 3.4) will remove the line and recalculate totals server-side.',
        'checkout_place'   => 'Checkout (Phase 3.5) will reserve stock in a transaction and create the order.',
        'contact'          => 'Support tickets (Phase 3.11) will create a ticket and notify the support queue.',
        'unsubscribe'      => 'Consent management (Phase 3.9) will validate the token and withdraw consent.',
        'newsletter'       => 'Consent management (Phase 3.9) will record an explicit marketing opt-in.',
        'review'           => 'Reviews (Phase 3) will check for a completed purchase before accepting this.',
    ];

    public function handle(): Response
    {
        $request = $this->request();

        // The token is genuinely verified, not decoratively rendered.
        if (!Csrf::verify((string) $request->input(Csrf::FIELD, ''))) {
            // 403, not 419: 419 is a framework convention, not a registered HTTP
            // status, and PHP degrades it to 500 - which is a lie about whose
            // fault it was and loses the actionable message.
            throw new HttpException(403, 'Your session expired before the form was submitted. Please reload the page and try again.');
        }

        $feature = (string) $request->input('feature', '');
        $detail  = self::FEATURES[$feature] ?? 'This action is implemented in a later phase.';

        Session::flash(
            'info',
            'Phase 1 preview: this form is not connected to a backend yet. ' . $detail
        );

        return $this->back();
    }
}
