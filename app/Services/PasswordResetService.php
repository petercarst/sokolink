<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\RateLimiter;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\TokenPurpose;
use App\Repositories\NotificationRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;

/**
 * The forgotten-password flow.
 *
 * The governing rule is that the response is identical whether or not the
 * address has an account. request() returns void and never throws for an
 * unknown address, so the page always says "if that address has an account, we
 * have sent a link" - which is the only way the form cannot be used to
 * enumerate customers.
 *
 * The token itself is stored hashed and split into selector + secret, so a
 * database dump contains no working reset links.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly TokenRepository $tokens = new TokenRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * Starts a reset.
     *
     * Returns the plaintext token when one was issued, and null otherwise -
     * but the caller must not branch on that for anything the user can see.
     * It is returned so the tests and the CLI can follow the flow without
     * reading a mailbox.
     */
    public function request(string $email, string $ip = ''): ?string
    {
        $email = mb_strtolower(trim($email));

        // Throttled on the same counters as login. Without this, the reset form
        // is an unauthenticated way to make the server do work and send mail.
        if (RateLimiter::tooManyAttempts($email, $ip)) {
            return null;
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            RateLimiter::record($email, $ip, false);

            return null;
        }

        // A suspended or closed account does not get a reset link. Letting
        // somebody set a new password on a suspended account would be a way to
        // undo the suspension.
        if (!in_array((string) $user['status'], ['active', 'pending_verification', 'pending_approval'], true)) {
            Audit::record('auth.reset.refused', 'user', (int) $user['id'], 'Account status: ' . $user['status']);

            return null;
        }

        if ($this->tokens->issuedSince((int) $user['id'], TokenPurpose::PasswordReset, 10) >= 3) {
            return null;
        }

        $token = $this->tokens->issue(
            (int) $user['id'],
            TokenPurpose::PasswordReset,
            (int) Config::get('security.reset_token_minutes', 60),
            $ip
        );

        $this->notifications->queue(
            (int) $user['id'],
            NotificationChannel::Email,
            NotificationCategory::OrderUpdates,
            'auth.password_reset',
            [
                'first_name'      => $user['first_name'],
                'token'           => $token,
                'expires_minutes' => (int) Config::get('security.reset_token_minutes', 60),
            ],
            false,
            'user',
            (int) $user['id']
        );

        Audit::record('auth.reset.requested', 'user', (int) $user['id'], 'Reset link issued');

        return $token;
    }

    /**
     * Whether a reset form should be shown for this token. Checked before the
     * password fields are displayed, so somebody with a dead link is told so
     * before typing a new password rather than after.
     */
    public function tokenIsUsable(string $token): bool
    {
        return $this->tokens->isValid(TokenPurpose::PasswordReset, $token);
    }

    /**
     * Completes a reset.
     *
     * Consuming the token and setting the password happen in one transaction.
     * If the update failed after the token was consumed, the customer would be
     * left with a dead link and an unchanged password - which is the worst of
     * both outcomes.
     */
    public function reset(string $token, string $newPassword, string $confirmation, string $ip = ''): int
    {
        if ($newPassword !== $confirmation) {
            throw new DomainRuleException('The two passwords do not match.', 'mismatch');
        }

        return Database::transaction(function () use ($token, $newPassword, $ip): int {
            $userId = $this->tokens->consume(TokenPurpose::PasswordReset, $token);

            if ($userId === null) {
                throw new DomainRuleException(
                    'That reset link has expired or has already been used. Request a new one.',
                    'invalid_token'
                );
            }

            $user = $this->users->find($userId);

            if ($user === null) {
                throw new DomainRuleException('That account no longer exists.', 'unknown_user');
            }

            (new AuthService())->assertPasswordAcceptable($newPassword, (string) $user['email']);

            $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_BCRYPT));

            // Any other outstanding reset token is now worthless. Somebody who
            // requested two links should not keep a spare.
            $this->tokens->consumeAllFor($userId, TokenPurpose::PasswordReset);

            // The person has proved control of the mailbox, so the failed-login
            // history should not keep them locked out of the account they have
            // just recovered.
            RateLimiter::clear((string) $user['email']);

            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::OrderUpdates,
                'auth.password_changed',
                ['first_name' => $user['first_name'], 'ip' => $ip],
                false,
                'user',
                $userId
            );

            Audit::record('auth.password.reset', 'user', $userId, 'Password reset via emailed link');

            return $userId;
        });
    }
}
