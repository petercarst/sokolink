<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\OrderRepository;
use App\Services\Payment\SandboxGateway;
use App\Services\PaymentService;

/**
 * Payment callbacks, and the local stand-in for a provider.
 *
 * **The callback is the only thing that can mark an order paid.** Not the
 * checkout, not a redirect the customer's browser followed, not a message from
 * a page. A browser saying "it worked" is a claim; a signed callback verified
 * against a shared secret is evidence (FR-PAY-06).
 *
 * The simulator below exists because no provider is connected. It does not
 * shortcut anything: it builds the same payload a provider would send, signs it
 * with the same secret, and posts it to the same endpoint. What it proves is
 * that the endpoint works — which is why it is worth having, and why it must
 * never be reachable when a real driver is configured.
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments = new PaymentService(),
        private readonly OrderRepository $orders = new OrderRepository(),
    ) {
    }

    /**
     * The provider's endpoint.
     *
     * Always answers 200 for anything it has finished with, including a replay
     * and including a payload it refused — a provider that gets a 500 will keep
     * retrying, and retrying a forged callback forever helps nobody. The
     * response body says what happened; the status code says "stop sending it".
     */
    public function callback(string $gateway): Response
    {
        $request   = $this->request();
        $payload   = $request->postAll();
        $signature = (string) ($request->header('X-Signature') ?? $payload['signature'] ?? '');

        unset($payload['signature']);

        try {
            $result = $this->payments->confirmPayment($gateway, $payload, $signature);
        } catch (DomainRuleException $e) {
            Logger::warning('Webhook refused', ['gateway' => $gateway, 'reason' => $e->reason()]);

            return Response::json(['outcome' => 'refused', 'reason' => $e->reason()], 200);
        }

        return Response::json([
            'outcome'      => $result['outcome'],
            'order_number' => $result['order_number'],
        ], 200);
    }

    /**
     * Settles an order through the sandbox driver, as a provider would.
     *
     * Only ever available when the sandbox driver is the configured one. With a
     * real provider configured this is a 404, not a disabled button — an
     * endpoint that can mark orders paid must not exist at all in that case.
     */
    public function simulate(): Response
    {
        $this->assertSandboxOnly();

        return $this->attempt(function (): Response {
            $orderNumber = (string) $this->request()->input('order_number', '');
            $outcome     = (string) $this->request()->input('outcome', 'paid');
            $userId      = Auth::id();

            $order = $userId === null ? null : $this->orders->findForCustomer($orderNumber, $userId);

            if ($order === null) {
                throw new DomainRuleException('That order could not be found on your account.', 'not_found');
            }

            $intent = $this->payments->createIntent($orderNumber, 'sandbox');

            $sandbox = new SandboxGateway();
            $payload = [
                'reference'    => 'SBX-CB-' . bin2hex(random_bytes(6)),
                'intent_ref'   => $intent['intent_ref'],
                'order_number' => $orderNumber,
                'amount'       => (string) $order['grand_total'],
                'currency'     => (string) $order['currency'],
                'status'       => $outcome === 'failed' ? 'failed' : 'paid',
            ];

            // Straight into the same handler the endpoint calls. Signed with
            // the same secret, verified by the same code.
            $result = $this->payments->confirmPayment('sandbox', $payload, $sandbox->sign($payload));

            if (($payload['status']) === 'failed') {
                Session::flash(
                    'warning',
                    'Simulated a failed payment. The order is still unpaid and will be released when it expires.'
                );

                return $this->toRoute('checkout.confirmation');
            }

            return $this->success(
                $result['outcome'] === 'already_processed'
                    ? 'That payment was already confirmed.'
                    : 'Simulated payment confirmed. The order has gone to the seller.',
                $this->toRoute('checkout.confirmation')
            );
        }, $this->toRoute('checkout.confirmation'));
    }

    private function assertSandboxOnly(): void
    {
        if ((string) Config::get('payment.driver', 'sandbox') !== 'sandbox') {
            throw new HttpException(404, 'We could not find that page.');
        }
    }
}
