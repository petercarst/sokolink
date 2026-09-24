<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Token;

/**
 * Pay in cash when you collect, or when the agent hands the parcel over.
 *
 * The one genuinely real payment method in v1 - the money changes hands between
 * two people, and the platform records that it happened rather than processing
 * it.
 *
 * Which is why an order paying this way is `pending_cod` rather than `pending`,
 * and has no expiry: there is nothing to wait for and nothing to time out. The
 * expiry sweeper skips it, and the seller may start preparing straight away.
 */
final class CashOnFulfilmentGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'cash';
    }

    public function label(): string
    {
        return 'Cash on collection or delivery';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): string
    {
        return '';
    }

    /**
     * @param  array<string,mixed> $order
     * @return array{intent_ref:string,redirect_url:?string,expires_in:int,instructions:?string}
     */
    public function createIntent(array $order, string $amount, string $currency): array
    {
        return [
            'intent_ref'   => 'COD-' . Token::code(10),
            'redirect_url' => null,
            'expires_in'   => 0,
            'instructions' => sprintf(
                'Pay %s %s when you collect the order or when it is delivered. Have the exact amount if you can.',
                $currency,
                $amount
            ),
        ];
    }

    /**
     * There is no callback. Cash is confirmed by a person at handover, through
     * the collection or delivery code, not by an HTTP request.
     *
     * @param  array<string,mixed> $payload
     * @return array{verified:bool,reference:string,status:string,amount:string,intent_ref:?string,reason:?string}
     */
    public function verifyCallback(array $payload, string $signature): array
    {
        return [
            'verified'   => false,
            'reference'  => '',
            'status'     => 'failed',
            'amount'     => '0.00',
            'intent_ref' => null,
            'reason'     => 'Cash payments are confirmed at handover, not by a callback.',
        ];
    }

    /**
     * A cash refund is handed back by a person, so this records the intention
     * rather than claiming to have moved anything.
     *
     * @return array{accepted:bool,reference:string,reason:?string}
     */
    public function refund(string $originalReference, string $amount, string $reason): array
    {
        return [
            'accepted'  => false,
            'reference' => '',
            'reason'    => 'A cash refund has to be given back in person and recorded by support.',
        ];
    }
}
