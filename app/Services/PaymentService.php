<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Logger;
use App\Core\Token;
use App\Domain\Enums\ActorType;
use App\Domain\Enums\OrderStatus;
use App\Repositories\OrderRepository;
use App\Services\Payment\CashOnFulfilmentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SandboxGateway;
use PDOException;

/**
 * Payments.
 *
 * Four rules, and every one of them exists because the alternative is a system
 * that can be told it has been paid:
 *
 *   1. **A browser never confirms a payment.** The only thing that marks an
 *      order paid is a signature-verified callback, processed server-side.
 *      confirmPayment() takes a payload and a signature, not a "success" flag.
 *
 *   2. **A replayed callback cannot credit an order twice.** The UNIQUE index
 *      on (gateway, gateway_reference) is what enforces it. The code catches
 *      the duplicate-key error and reports success - because the caller IS a
 *      gateway retrying, and it should stop retrying.
 *
 *   3. **The amount is checked against the order.** A callback that says
 *      TSh 1 for a TSh 80,900 order is recorded as `flagged_for_review` and
 *      does not mark anything paid.
 *
 *   4. **An unverified callback is recorded, never acted on.** It goes into
 *      payment_transactions with signature_verified = 0 so somebody can see
 *      that a forged callback arrived.
 *
 * No real provider is connected. See PaymentGateway's docblock.
 */
final class PaymentService
{
    /** @var array<string,PaymentGateway>|null */
    private static ?array $gateways = null;

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly OrderService $orderService = new OrderService(),
    ) {
    }

    /**
     * Every driver, whether or not it can be used.
     *
     * The checkout shows all of them, with the unavailable ones disabled and
     * the reason stated. Hiding an option the customer expects to see leaves
     * them wondering; saying "mobile money is not connected yet" does not.
     *
     * @return array<string,PaymentGateway>
     */
    public function gateways(): array
    {
        return self::$gateways ??= [
            'sandbox' => new SandboxGateway(),
            'cash'    => new CashOnFulfilmentGateway(),
        ];
    }

    public function gateway(string $key): PaymentGateway
    {
        $gateways = $this->gateways();

        return $gateways[$key] ?? throw new DomainRuleException(
            'That payment method is not available.',
            'unknown_gateway'
        );
    }

    /**
     * The payment options a checkout page should render, each with whether it
     * can be used right now and, if not, why.
     *
     * Mobile money is listed as unavailable rather than omitted, because the
     * schema supports it, customers will ask for it, and the honest answer is
     * "not connected yet" rather than silence.
     *
     * @param  array{allowed:bool,reason:string}|null $cashVerdict from CashEligibility, for this basket
     * @return list<array{key:string,label:string,description:string,available:bool,reason:string,simulated:bool}>
     */
    public function optionsForCheckout(?array $cashVerdict = null): array
    {
        $descriptions = [
            'sandbox' => 'A development-only gateway that simulates a successful or failed payment so the '
                       . 'order lifecycle can be exercised end to end. No money moves and nothing is charged.',
            'cash'    => 'Pay the seller when you collect, or the agent when it arrives. The order is marked '
                       . 'paid only once that person confirms they received the cash.',
        ];

        $options = [];

        foreach ($this->gateways() as $key => $gateway) {
            $available = $gateway->isAvailable();
            $reason    = $gateway->unavailableReason();

            // A driver can be perfectly available and still be wrong for THIS
            // basket - cash on a large order, or from a seller who refuses it.
            // The driver cannot know that; CashEligibility does.
            if ($key === 'cash' && $available && $cashVerdict !== null && !$cashVerdict['allowed']) {
                $available = false;
                $reason    = $cashVerdict['reason'];
            }

            $options[] = [
                'key'         => $key,
                'label'       => $gateway->label(),
                'description' => $descriptions[$key] ?? '',
                'available'   => $available,
                'reason'      => $reason,
                'simulated'   => $key === 'sandbox',
            ];
        }

        // Listed, and listed as unavailable with the reason. The schema was
        // designed for these and the interface is ready for them; no provider
        // agreement exists. Leaving them off the page would hide a plan;
        // showing them as buttons that fail would be worse than either.
        foreach (['mpesa' => 'M-Pesa', 'airtel_money' => 'Airtel Money', 'mixx' => 'Mixx by Yas', 'halopesa' => 'HaloPesa'] as $key => $label) {
            $options[] = [
                'key'         => $key,
                'label'       => $label,
                'description' => 'Mobile money, paid from your phone at checkout.',
                'available'   => false,
                'reason'      => 'Not connected yet. No agreement with this provider is in place.',
                'simulated'   => false,
            ];
        }

        return $options;
    }

    /**
     * Opens a payment attempt.
     *
     * An intent is not a transaction. Separating them is what lets an abandoned
     * checkout expire without leaving a phantom payment record, and what makes
     * "three attempts, one success" describable.
     *
     * @return array{intent_id:int,intent_ref:string,redirect_url:?string,instructions:?string}
     */
    public function createIntent(string $orderNumber, string $gatewayKey): array
    {
        $order = $this->orders->findByNumber($orderNumber);

        if ($order === null) {
            throw new DomainRuleException('That order could not be found.', 'not_found');
        }

        if (in_array((string) $order['payment_status'], ['paid', 'refunded'], true)) {
            throw new DomainRuleException('That order has already been paid.', 'already_paid');
        }

        $gateway = $this->gateway($gatewayKey);

        if (!$gateway->isAvailable()) {
            throw new DomainRuleException($gateway->unavailableReason(), 'gateway_unavailable');
        }

        return Database::transaction(function () use ($order, $gateway): array {
            $created = $gateway->createIntent(
                $order,
                (string) $order['grand_total'],
                (string) $order['currency']
            );

            $intentId = Database::insert('payment_intents', [
                'order_id'           => (int) $order['id'],
                'gateway'            => $gateway->key(),
                'gateway_intent_ref' => $created['intent_ref'],
                'amount'             => (string) $order['grand_total'],
                'currency'           => (string) $order['currency'],
                'status'             => 'created',
                'expires_at'         => $created['expires_in'] > 0
                    ? gmdate('Y-m-d H:i:s', time() + $created['expires_in'])
                    : null,
            ]);

            Audit::record(
                'payment.intent.created',
                'order',
                (int) $order['id'],
                sprintf('%s intent for %s', $gateway->key(), (string) $order['grand_total'])
            );

            return [
                'intent_id'    => $intentId,
                'intent_ref'   => $created['intent_ref'],
                'redirect_url' => $created['redirect_url'],
                'instructions' => $created['instructions'],
            ];
        });
    }

    /**
     * Records cash taken at a handover.
     *
     * Cash is the one method with no callback, because there is no provider:
     * the money moves between two people and the platform writes down that it
     * did. The handover **is** the payment event, so the person who confirms
     * the handover - a seller at their counter, an agent at a door - is the
     * person who took the money.
     *
     * Without this, a cash order stayed `pending_cod` for ever: delivered,
     * collected, complete, and still recorded as unpaid. Every revenue figure
     * built on `payment_status` was wrong by exactly the cash takings.
     *
     * Two things it is careful about:
     *
     *   1. **A split order is paid in parts.** One basket shared between two
     *      sellers is collected from one counter and delivered to a door; the
     *      customer pays each of them. So a transaction is written per
     *      sub-order, and the parent only becomes `paid` once nothing is
     *      outstanding.
     *   2. **A handover confirmed twice must not be charged twice.** The
     *      reference is derived from the sub-order, so the unique key on
     *      (gateway, gateway_reference) refuses the second one exactly as it
     *      refuses a replayed webhook.
     *
     * @return bool whether this handover completed payment for the whole order
     */
    public function settleCashOnHandover(int $sellerOrderId, ActorType $takenBy): bool
    {
        $sub = Database::selectOne(
            'SELECT so.id, so.sub_number, so.total, o.id AS order_id, o.order_number,
                    o.payment_method, o.payment_status, o.currency, o.grand_total
               FROM seller_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE so.id = :id',
            ['id' => $sellerOrderId]
        );

        if ($sub === null || (string) $sub['payment_method'] !== 'cash') {
            return false;
        }

        return Database::transaction(function () use ($sub, $sellerOrderId, $takenBy): bool {
            $reference = 'CASH-' . (string) $sub['sub_number'];

            try {
                Database::insert('payment_transactions', [
                    'transaction_ref'    => 'TXN-' . Token::code(10),
                    'order_id'           => (int) $sub['order_id'],
                    'gateway'            => 'cash',
                    'gateway_reference'  => $reference,
                    'direction'          => 'charge',
                    'amount'             => (string) $sub['total'],
                    'currency'           => (string) $sub['currency'],
                    'status'             => 'paid',
                    // No payload: nothing was received from anywhere. What
                    // happened is that a person handed over money, and who
                    // recorded it is in the audit row below.
                    'raw_payload'        => null,
                    // Not a signature. Cash is verified by the handover code,
                    // which is checked before this is ever called.
                    'signature_verified' => 0,
                    'processed_at'       => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                // Already recorded. Not an error: a handover confirmed twice is
                // still one handover.
                return false;
            }

            Audit::record(
                'payment.cash.received',
                'seller_order',
                $sellerOrderId,
                sprintf(
                    '%s %s taken at handover by %s',
                    (string) $sub['currency'],
                    (string) $sub['total'],
                    $takenBy->value
                )
            );

            $outstanding = (int) Database::scalar(
                "SELECT COUNT(*)
                   FROM seller_orders so
                  WHERE so.order_id = :order
                    AND so.status NOT IN ('collected', 'delivered', 'completed',
                                          'cancelled_customer', 'rejected_seller',
                                          'returned_to_seller', 'refunded', 'expired_unpaid')",
                ['order' => (int) $sub['order_id']]
            );

            if ($outstanding > 0) {
                return false;
            }

            $this->orders->markPaid((int) $sub['order_id']);

            Audit::record(
                'payment.confirmed',
                'order',
                (int) $sub['order_id'],
                sprintf('%s %s in cash, across every part of the order',
                    (string) $sub['currency'],
                    (string) $sub['grand_total']
                )
            );

            return true;
        });
    }

    /**
     * Processes a gateway callback. **The only thing that can mark an order paid.**
     *
     * Returns a short outcome describing what happened, so the endpoint can
     * answer the gateway correctly - a 200 for "handled" and "already handled"
     * alike, because both mean "stop retrying".
     *
     * @param array<string,mixed> $payload
     * @return array{handled:bool,outcome:string,order_number:?string}
     */
    public function confirmPayment(string $gatewayKey, array $payload, string $signature): array
    {
        $gateway = $this->gateway($gatewayKey);
        $result  = $gateway->verifyCallback($payload, $signature);

        // An unverified callback is RECORDED and then ignored. Recording it
        // matters: a forged callback arriving is something somebody should be
        // able to see afterwards.
        if (!$result['verified']) {
            $this->recordUnverified($gatewayKey, $payload, (string) ($result['reason'] ?? 'unverified'));

            Logger::warning('Rejected an unverified payment callback', [
                'gateway'   => $gatewayKey,
                'reference' => $result['reference'],
                'reason'    => $result['reason'],
            ]);

            return ['handled' => false, 'outcome' => 'signature_invalid', 'order_number' => null];
        }

        $order = $this->orderForCallback($payload);

        if ($order === null) {
            $this->recordUnverified($gatewayKey, $payload, 'No matching order');

            return ['handled' => false, 'outcome' => 'unknown_order', 'order_number' => null];
        }

        // The amount must match. A verified callback for the wrong amount is
        // either a bug on their side or an attack, and either way it must not
        // mark an order paid.
        if ($this->minor((string) $result['amount']) !== $this->minor((string) $order['grand_total'])) {
            $this->recordTransaction(
                $order,
                $gatewayKey,
                $result['reference'],
                'flagged_for_review',
                (string) $result['amount'],
                $payload,
                true
            );

            Audit::record(
                'payment.amount_mismatch',
                'order',
                (int) $order['id'],
                sprintf('Callback said %s, order is %s', $result['amount'], $order['grand_total'])
            );

            return [
                'handled'      => false,
                'outcome'      => 'amount_mismatch',
                'order_number' => (string) $order['order_number'],
            ];
        }

        if ($result['status'] !== 'paid' && $result['status'] !== 'succeeded') {
            return $this->recordFailure($order, $gatewayKey, $result, $payload);
        }

        // An order is paid once.
        //
        // The UNIQUE key on (gateway, gateway_reference) stops the SAME
        // callback being applied twice, which is the replay case. It does not
        // stop a SECOND, genuinely different callback claiming to pay an order
        // that is already settled - two references, two charges, twice the
        // money captured. That is a different failure and it needs its own
        // guard, checked before anything is written.
        //
        // Recorded rather than silently dropped: a provider sending a second
        // payment for a settled order is either a bug on their side or an
        // attempt, and somebody should be able to see that it happened.
        if (in_array((string) $order['payment_status'], ['paid', 'refunded'], true)) {
            try {
                $this->recordTransaction(
                    $order,
                    $gatewayKey,
                    $result['reference'],
                    'flagged_for_review',
                    (string) $result['amount'],
                    $payload,
                    true
                );
            } catch (PDOException $e) {
                // 23000 here means this exact reference is already on file:
                // the provider is retrying a callback we have already handled,
                // which is ordinary and is not a duplicate payment attempt.
                // Anything else is a real fault and is rethrown.
                if (($e->errorInfo[0] ?? '') !== '23000') {
                    throw $e;
                }

                return [
                    'handled'      => true,
                    'outcome'      => 'already_processed',
                    'order_number' => (string) $order['order_number'],
                ];
            }

            Audit::record(
                'payment.duplicate_attempt',
                'order',
                (int) $order['id'],
                sprintf(
                    'A second %s callback (%s) arrived for an order already %s',
                    $gatewayKey,
                    $result['reference'],
                    (string) $order['payment_status']
                )
            );

            return [
                'handled'      => true,
                'outcome'      => 'already_paid',
                'order_number' => (string) $order['order_number'],
            ];
        }

        return $this->applySuccessfulPayment($order, $gatewayKey, $result, $payload);
    }

    /**
     * Records a successful payment and releases the order to its sellers.
     *
     * @param array<string,mixed> $order
     * @param array{reference:string,amount:string,intent_ref:?string} $result
     * @param array<string,mixed> $payload
     * @return array{handled:bool,outcome:string,order_number:?string}
     */
    private function applySuccessfulPayment(array $order, string $gatewayKey, array $result, array $payload): array
    {
        try {
            return Database::transaction(function () use ($order, $gatewayKey, $result, $payload): array {
                // This INSERT is the idempotency guarantee. The UNIQUE index on
                // (gateway, gateway_reference) refuses the second one, and the
                // whole transaction rolls back - so a replay cannot mark an
                // order paid twice, or move its sub-orders twice.
                $this->recordTransaction(
                    $order,
                    $gatewayKey,
                    $result['reference'],
                    'paid',
                    (string) $result['amount'],
                    $payload,
                    true
                );

                if ($result['intent_ref'] !== null) {
                    Database::statement(
                        "UPDATE payment_intents SET status = 'succeeded'
                          WHERE gateway_intent_ref = :ref AND order_id = :order",
                        ['ref' => $result['intent_ref'], 'order' => (int) $order['id']]
                    );
                }

                $this->orders->markPaid((int) $order['id']);

                // Every part of the order moves on to its seller. Guarded, so
                // an order that somehow got here twice does not double-move.
                foreach ($this->orders->sellerOrdersFor((int) $order['id']) as $sub) {
                    if ((string) $sub['status'] !== OrderStatus::PendingPayment->value) {
                        continue;
                    }

                    $this->orderService->transition(
                        (int) $sub['id'],
                        OrderStatus::AwaitingSeller,
                        ActorType::System,
                        'Payment confirmed'
                    );
                }

                Audit::record(
                    'payment.confirmed',
                    'order',
                    (int) $order['id'],
                    sprintf('%s %s via %s', (string) $order['currency'], (string) $result['amount'], $gatewayKey)
                );

                return [
                    'handled'      => true,
                    'outcome'      => 'paid',
                    'order_number' => (string) $order['order_number'],
                ];
            });
        } catch (PDOException $e) {
            // 23000 is an integrity constraint violation - here, the unique
            // reference. That means this exact callback has already been
            // processed, which is a SUCCESS from the gateway's point of view:
            // it should stop retrying.
            if ($this->isDuplicateKey($e)) {
                return [
                    'handled'      => true,
                    'outcome'      => 'already_processed',
                    'order_number' => (string) $order['order_number'],
                ];
            }

            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $order
     * @param array{reference:string,amount:string,status:string} $result
     * @param array<string,mixed> $payload
     * @return array{handled:bool,outcome:string,order_number:?string}
     */
    private function recordFailure(array $order, string $gatewayKey, array $result, array $payload): array
    {
        try {
            Database::transaction(function () use ($order, $gatewayKey, $result, $payload): void {
                $this->recordTransaction(
                    $order,
                    $gatewayKey,
                    $result['reference'],
                    'failed',
                    (string) $result['amount'],
                    $payload,
                    true
                );

                // The order is NOT cancelled. A failed attempt is one attempt -
                // the customer may try another method, and the expiry sweeper
                // will deal with it if they do not.
                $this->orders->setPaymentStatus((int) $order['id'], 'failed');
            });
        } catch (PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }

        return [
            'handled'      => true,
            'outcome'      => 'failed',
            'order_number' => (string) $order['order_number'],
        ];
    }

    /**
     * Expires unpaid orders. Run by the scheduled task.
     *
     * Releasing the stock is the point. An abandoned checkout that keeps its
     * reservation takes units off the shelf for an order nobody will ever pay
     * for, and no amount of seller diligence fixes that from the outside.
     *
     * @return array{expired:int,orders:list<string>}
     */
    public function expireUnpaidOrders(int $limit = 100): array
    {
        $expired = [];

        foreach ($this->orders->expiredUnpaid($limit) as $order) {
            try {
                Database::transaction(function () use ($order, &$expired): void {
                    $this->orders->setPaymentStatus((int) $order['id'], 'expired');

                    foreach ($this->orders->sellerOrdersFor((int) $order['id']) as $sub) {
                        if ((string) $sub['status'] !== OrderStatus::PendingPayment->value) {
                            continue;
                        }

                        $this->orderService->transition(
                            (int) $sub['id'],
                            OrderStatus::ExpiredUnpaid,
                            ActorType::System,
                            'Not paid within the time allowed'
                        );
                    }

                    $expired[] = (string) $order['order_number'];
                });
            } catch (DomainRuleException $e) {
                // One order that will not move must not stop the sweep.
                Logger::warning('Could not expire an order', [
                    'order'  => $order['order_number'],
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return ['expired' => count($expired), 'orders' => $expired];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function transactionsFor(int $orderId): array
    {
        return Database::select(
            'SELECT transaction_ref, gateway, gateway_reference, direction, amount, currency,
                    status, signature_verified, processed_at, created_at
               FROM payment_transactions
              WHERE order_id = :id
              ORDER BY created_at',
            ['id' => $orderId]
        );
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     */
    private function recordTransaction(
        array $order,
        string $gatewayKey,
        string $reference,
        string $status,
        string $amount,
        array $payload,
        bool $signatureVerified
    ): int {
        return Database::insert('payment_transactions', [
            'transaction_ref'    => 'TXN-' . Token::code(10),
            'order_id'           => (int) $order['id'],
            'gateway'            => $gatewayKey,
            'gateway_reference'  => mb_substr($reference, 0, 190),
            'direction'          => 'charge',
            'amount'             => $amount,
            'currency'           => (string) $order['currency'],
            'status'             => $status,
            'raw_payload'        => $this->safePayload($payload),
            'signature_verified' => $signatureVerified ? 1 : 0,
            'processed_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Records a callback that failed verification, against no order.
     *
     * A synthetic reference keeps the unique index meaningful - two forged
     * callbacks are two rows, not one that overwrites the other.
     *
     * @param array<string,mixed> $payload
     */
    private function recordUnverified(string $gatewayKey, array $payload, string $reason): void
    {
        $order = $this->orderForCallback($payload);

        if ($order === null) {
            // Nothing to attach it to. The log is the record; the table has a
            // RESTRICT foreign key on order_id for a reason.
            Logger::warning('Unverified payment callback for an unknown order', [
                'gateway' => $gatewayKey,
                'reason'  => $reason,
            ]);

            return;
        }

        try {
            Database::insert('payment_transactions', [
                'transaction_ref'    => 'TXN-' . Token::code(10),
                'order_id'           => (int) $order['id'],
                'gateway'            => $gatewayKey,
                'gateway_reference'  => 'UNVERIFIED-' . Token::code(12),
                'direction'          => 'charge',
                'amount'             => (string) ($payload['amount'] ?? '0.00'),
                'currency'           => (string) $order['currency'],
                'status'             => 'flagged_for_review',
                'raw_payload'        => $this->safePayload($payload),
                'signature_verified' => 0,
                'processed_at'       => gmdate('Y-m-d H:i:s'),
            ]);

            Audit::record(
                'payment.callback.unverified',
                'order',
                (int) $order['id'],
                $reason
            );
        } catch (PDOException $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function orderForCallback(array $payload): ?array
    {
        $orderNumber = (string) ($payload['order_number'] ?? '');

        if ($orderNumber !== '') {
            return $this->orders->findByNumber($orderNumber);
        }

        $intentRef = (string) ($payload['intent_ref'] ?? '');

        if ($intentRef === '') {
            return null;
        }

        $orderId = Database::scalar(
            'SELECT order_id FROM payment_intents WHERE gateway_intent_ref = :ref',
            ['ref' => $intentRef]
        );

        return $orderId === null
            ? null
            : Database::selectOne('SELECT * FROM orders WHERE id = :id', ['id' => (int) $orderId]);
    }

    /**
     * The provider's payload, with anything that looks like a credential
     * stripped.
     *
     * A well-behaved provider never sends one. This is here because we do not
     * control what arrives, and "we stored their card number because they put
     * it in the webhook" is not a defence.
     *
     * @param array<string,mixed> $payload
     */
    private function safePayload(array $payload): string
    {
        $blocked = ['card', 'card_number', 'pan', 'cvv', 'cvc', 'pin', 'password', 'token', 'secret'];

        foreach ($payload as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $blocked, true)) {
                $payload[$key] = '[stripped before storage]';
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? mb_substr($json, 0, 60000) : '{}';
    }

    private function minor(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
    }
}
