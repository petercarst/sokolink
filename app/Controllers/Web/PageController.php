<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Audit;
use App\Core\Exceptions\DomainRuleException;
use App\Domain\Enums\TokenPurpose;
use App\Repositories\ConsentRepository;
use App\Repositories\TokenRepository;
use App\Services\CatalogService;
use App\Support\View\Present;

final class PageController extends Controller
{
    public function about(): Response
    {
        return $this->view('web/about', [
            'title'    => 'About SokoLink',
            'metaDesc' => 'SokoLink connects customers with local sellers for click and collect or home delivery.',
            'stores'   => Present::stores((new CatalogService())->storeFilterOptions()),
        ], 'public');
    }

    public function contact(): Response
    {
        return $this->view('web/contact', [
            'title'    => 'Contact and support',
            'metaDesc' => 'Get help with an order, a delivery or your account.',
            // The form opens a real ticket, which needs somewhere to send the
            // reply, so it is shown only to somebody signed in. Everyone else
            // gets the way in, and comes back here.
            'signedIn' => Auth::check(),
            // A seller or an agent signed into their own dashboard is not a
            // customer; sending them to a form that would 403 is worse than
            // telling them where their support actually lives.
            'canOpen'  => Auth::check() && Auth::hasRole('customer'),
        ], 'public');
    }

    public function terms(): Response
    {
        return $this->view('web/terms', [
            'title'    => 'Terms of service',
            'metaDesc' => 'The terms that apply when you use SokoLink.',
            'track'    => 'transactional',
        ], 'public');
    }

    public function privacy(): Response
    {
        return $this->view('web/privacy', [
            'title'    => 'Privacy notice',
            'metaDesc' => 'What data SokoLink collects, why, and the choices you have.',
            'track'    => 'transactional',
        ], 'public');
    }

    public function sellWithUs(): Response
    {
        return $this->view('web/sell-with-us', [
            'title'    => 'Sell on SokoLink',
            'metaDesc' => 'Reach online customers without building your own shop. Apply to sell on SokoLink.',
        ], 'public');
    }

    /**
     * One-click unsubscribe (FR-CRM-07).
     *
     * This page is reachable WITHOUT logging in, by design: requiring a login to
     * stop marketing email is a dark pattern and, in several jurisdictions,
     * unlawful. Phase 3 validates the signed token and records the withdrawal.
     */
    /**
     * The one-click opt-out, reachable WITHOUT logging in (FR-CRM-07).
     *
     * Requiring a login to stop marketing email is a dark pattern and in
     * several jurisdictions unlawful. The token in the link identifies the
     * recipient; nothing else does.
     */
    public function unsubscribe(): Response
    {
        $token = (string) $this->request()->query('token', '');

        return $this->view('web/unsubscribe', [
            'title'     => 'Unsubscribe',
            'metaDesc'  => 'Stop receiving reorder reminders and offers from SokoLink.',
            'token'     => $token,
            // Checked before the form is shown, so somebody with a stale link
            // is told so now rather than after pressing the button.
            'hasToken'  => $token !== '' && (new TokenRepository())->isValid(TokenPurpose::Unsubscribe, $token),
            'confirmed' => $this->request()->query('done') === '1',
            'track'     => 'transactional',
        ], 'public');
    }

    /**
     * Withdraws marketing consent and switches off the offer and reorder
     * channels.
     *
     * Order updates are deliberately untouched: somebody who stops marketing
     * email has not asked to stop being told their order is ready to collect,
     * and silencing that would be a worse failure than the spam they were
     * trying to escape.
     */
    public function unsubscribeSubmit(): Response
    {
        return $this->attempt(function (): Response {
            $token  = (string) $this->request()->input('token', '');
            $userId = (new TokenRepository())->consume(TokenPurpose::Unsubscribe, $token);

            if ($userId === null) {
                throw new DomainRuleException(
                    'That unsubscribe link has expired or has already been used. '
                    . 'Use the link in a more recent message, or change your preferences after signing in.',
                    'invalid_token'
                );
            }

            (new ConsentRepository())->unsubscribeAll($userId, 'unsubscribe_link');

            Audit::record('consent.marketing.withdrawn', 'user', $userId, 'One-click unsubscribe link');

            return $this->redirect(route('page.unsubscribe') . '?done=1');
        }, $this->toRoute('page.unsubscribe'));
    }
}
