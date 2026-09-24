<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Config;
use App\Core\Token;

/**
 * A local simulator. **It moves no money.**
 *
 * It exists so the order lifecycle can be exercised end to end - placed, paid,
 * accepted, collected - without a provider account, and so the webhook handling
 * can be tested for the things that actually go wrong: replays, bad signatures,
 * amounts that do not match.
 *
 * Every screen that offers this driver says it is a simulation. The brief is
 * explicit that mock payment processing must not be presented as real, and a
 * driver called "sandbox" that quietly behaves like a bank would be exactly
 * that.
 *
 * The signature scheme is a real HMAC over the payload, not a stub. That part
 * is not simulated, because it is the part a real integration would reuse - and
 * testing a signature check against a fake signature check proves nothing.
 */
final class SandboxGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (simulated payment - no money moves)';
    }

    public function isAvailable(): bool
    {
        // Available only outside production. Shipping a simulator that accepts
        // real orders is how a demo becomes an incident.
        return !Config::isProduction();
    }

    public function unavailableReason(): string
    {
        return $this->isAvailable()
            ? ''
            : 'The simulated payment driver is disabled outside development.';
    }

    /**
     * @param  array<string,mixed> $order
     * @return array{intent_ref:string,redirect_url:?string,expires_in:int,instructions:?string}
     */
    public function createIntent(array $order, string $amount, string $currency): array
    {
        return [
            'intent_ref'   => 'SBX-' . Token::code(10),
            'redirect_url' => null,
            'expires_in'   => (int) Config::get('payment.expiry_minutes', 60) * 60,
            'instructions' => 'This is a simulated payment. Nothing is charged and no money moves.',
        ];
    }

    /**
     * Verifies an HMAC-SHA256 signature over the canonical payload.
     *
     * hash_equals, not ===. A timing-safe comparison on a signature is not
     * paranoia: an attacker who can measure the comparison can forge one byte
     * at a time.
     *
     * @param  array<string,mixed> $payload
     * @return array{verified:bool,reference:string,status:string,amount:string,intent_ref:?string,reason:?string}
     */
    public function verifyCallback(array $payload, string $signature): array
    {
        $result = [
            'verified'   => false,
            'reference'  => (string) ($payload['reference'] ?? ''),
            'status'     => (string) ($payload['status'] ?? 'failed'),
            'amount'     => (string) ($payload['amount'] ?? '0.00'),
            'intent_ref' => isset($payload['intent_ref']) ? (string) $payload['intent_ref'] : null,
            'reason'     => null,
        ];

        $secret = (string) Config::get('payment.webhook_secret', '');

        if ($secret === '') {
            // Refusing rather than accepting. An unsigned webhook endpoint is
            // an unauthenticated way to mark orders paid, which is worse than
            // an endpoint that does not work.
            $result['reason'] = 'No webhook secret is configured, so callbacks cannot be verified.';

            return $result;
        }

        if ($result['reference'] === '') {
            $result['reason'] = 'The callback carried no reference.';

            return $result;
        }

        $expected = $this->sign($payload, $secret);

        if (!hash_equals($expected, $signature)) {
            $result['reason'] = 'The signature did not match.';

            return $result;
        }

        $result['verified'] = true;

        return $result;
    }

    /**
     * @return array{accepted:bool,reference:string,reason:?string}
     */
    public function refund(string $originalReference, string $amount, string $reason): array
    {
        return [
            'accepted'  => true,
            'reference' => 'SBXR-' . Token::code(10),
            'reason'    => null,
        ];
    }

    /**
     * The canonical signing form.
     *
     * Public so the tests - and a future provider adapter - can produce a valid
     * signature without duplicating the ordering rules. Keys are sorted so the
     * signature does not depend on the order a JSON encoder happened to use.
     *
     * @param array<string,mixed> $payload
     */
    public function sign(array $payload, ?string $secret = null): string
    {
        $secret ??= (string) Config::get('payment.webhook_secret', '');

        unset($payload['signature']);
        ksort($payload);

        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', is_string($canonical) ? $canonical : '', $secret);
    }
}
