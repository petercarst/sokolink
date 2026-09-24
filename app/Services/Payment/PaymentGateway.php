<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * What a payment provider has to be able to do.
 *
 * The interface exists so that connecting a real provider later is adding a
 * class, not rewriting the checkout. It is deliberately small: create an
 * intent, verify a callback, and describe yourself.
 *
 * **No implementation of this interface is connected to a real payment
 * provider.** Two drivers ship in v1:
 *
 *   - SandboxGateway, which simulates success and failure locally. It moves no
 *     money and says so on every screen it touches.
 *   - CashOnFulfilmentGateway, where the customer pays a person. That one is
 *     real, because the money changes hands in a shop rather than online.
 *
 * Mobile money (M-Pesa, Airtel Money, Mixx, HaloPesa) appears in the payment
 * enum because the schema was designed for it. Nothing implements it. The
 * checkout must show those options as unavailable with the reason stated, not
 * as buttons that do nothing.
 */
interface PaymentGateway
{
    /** The key stored in `payment_transactions.gateway`. */
    public function key(): string;

    /** What the customer sees. */
    public function label(): string;

    /**
     * Whether this driver can be selected right now.
     *
     * A driver that is not connected returns false and gives a reason, which
     * the checkout shows. A greyed-out option with an explanation is honest; a
     * button that silently fails is not.
     */
    public function isAvailable(): bool;

    /** Why it is unavailable, shown to the customer. Empty when available. */
    public function unavailableReason(): string;

    /**
     * Starts a payment attempt.
     *
     * @param array<string,mixed> $order
     * @return array{intent_ref:string,redirect_url:?string,expires_in:int,instructions:?string}
     */
    public function createIntent(array $order, string $amount, string $currency): array;

    /**
     * Verifies an inbound callback and returns what it claims.
     *
     * `verified` is the only field a caller may trust. A payload that fails
     * signature verification is recorded and marked unverified - never acted
     * on. "Never trust a client-side success message as proof of payment"
     * applies to webhooks too: a webhook is a client, and one that anybody can
     * POST to is not proof of anything without a signature.
     *
     * @param array<string,mixed> $payload
     * @return array{verified:bool,reference:string,status:string,amount:string,intent_ref:?string,reason:?string}
     */
    public function verifyCallback(array $payload, string $signature): array;

    /**
     * Requests a refund from the provider.
     *
     * @return array{accepted:bool,reference:string,reason:?string}
     */
    public function refund(string $originalReference, string $amount, string $reason): array;
}
