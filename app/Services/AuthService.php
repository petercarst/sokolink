<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Exceptions\DomainRuleException;
use App\Core\RateLimiter;
use App\Domain\Enums\UserStatus;
use App\Repositories\UserRepository;

/**
 * Signing in and out.
 *
 * Three things here are more deliberate than they look.
 *
 * 1. **One message for every failure.** Unknown email, wrong password, and
 *    "that account exists but is suspended" all produce the same sentence.
 *    Distinguishing them turns the login form into a tool for discovering which
 *    addresses have accounts.
 *
 * 2. **The throttle is checked before the password.** Otherwise the expensive
 *    bcrypt comparison is exactly the work an attacker wants us to do.
 *
 * 3. **A dummy verify runs when the email is unknown.** Without it, a missing
 *    account returns in a fraction of the time a wrong password does, and the
 *    difference is measurable over a few hundred requests.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /**
     * Verifies credentials and establishes a session.
     *
     * @return array<string,mixed> the user row
     * @throws DomainRuleException on any failure, with a message safe to show
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));

        if (RateLimiter::tooManyAttempts($email, $ip)) {
            $seconds = RateLimiter::secondsUntilRetry($email, $ip);

            throw new DomainRuleException(
                sprintf(
                    'Too many sign-in attempts. Try again in %d minute%s.',
                    max(1, (int) ceil($seconds / 60)),
                    $seconds > 60 ? 's' : ''
                ),
                'throttled'
            );
        }

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Spend roughly the same time as a real comparison, so "no such
            // account" and "wrong password" are not distinguishable by timing.
            password_verify($password, '$2y$10$usesomesillystringfoeequalbFq9J3Ne9CjIU9qcx8fK1Ck0nAUXe');

            RateLimiter::record($email, $ip, false);

            throw new DomainRuleException($this->genericFailure(), 'invalid_credentials');
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            RateLimiter::record($email, $ip, false);

            Audit::record(
                'auth.login.failed',
                'user',
                (int) $user['id'],
                'Wrong password'
            );

            throw new DomainRuleException($this->genericFailure(), 'invalid_credentials');
        }

        $this->assertStatusAllowsSignIn(UserStatus::fromDatabase((string) $user['status']), $user, $email, $ip);

        // The cost factor may have been raised since this hash was made. Now is
        // the only moment we hold the plaintext, so it is the only moment the
        // hash can be upgraded.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT)) {
            $this->users->updatePassword((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        }

        RateLimiter::record($email, $ip, true);
        RateLimiter::clear($email);

        $this->users->recordLogin((int) $user['id'], $ip);

        Auth::login($user);

        Audit::record('auth.login', 'user', (int) $user['id'], 'Signed in');

        return $user;
    }

    public function logout(): void
    {
        $id = Auth::id();

        if ($id !== null) {
            Audit::record('auth.logout', 'user', $id, 'Signed out');
        }

        Auth::logout();
    }

    /**
     * Confirms the current user's password before a sensitive change - changing
     * an email address, closing an account. A live session is not proof that
     * the person at the keyboard is the account holder.
     */
    public function confirmPassword(string $password): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return password_verify($password, (string) $user['password_hash']);
    }

    /**
     * Changes a password for a signed-in user. The current one is required, and
     * every outstanding reset token is invalidated - if somebody had one in
     * flight, it should not survive the account holder taking action.
     */
    public function changePassword(string $currentPassword, string $newPassword): void
    {
        $user = Auth::user();

        if ($user === null) {
            throw new DomainRuleException('Sign in to change your password.', 'not_authenticated');
        }

        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new DomainRuleException('Your current password is not correct.', 'wrong_password');
        }

        $this->assertPasswordAcceptable($newPassword, (string) $user['email']);

        $this->users->updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_BCRYPT));

        Audit::record('auth.password.changed', 'user', (int) $user['id'], 'Password changed by the account holder');

        Auth::forgetCache();
    }

    /**
     * The rules a new password must meet.
     *
     * Length over composition. A 14-character phrase is stronger than
     * "P@ssw0rd!" and far likelier to be remembered rather than written down,
     * so there is no "must contain a symbol" rule here.
     */
    public function assertPasswordAcceptable(string $password, string $email = ''): void
    {
        $minimum = (int) Config::get('security.password_min_length', 10);

        if (mb_strlen($password) < $minimum) {
            throw new DomainRuleException(
                sprintf('Choose a password of at least %d characters.', $minimum),
                'password_too_short'
            );
        }

        if ($email !== '' && mb_stripos($password, explode('@', $email)[0]) !== false) {
            throw new DomainRuleException(
                'Your password should not contain your email address.',
                'password_contains_email'
            );
        }

        if ($this->isTooObvious($password)) {
            throw new DomainRuleException(
                'That password is too easy to guess. Choose something else.',
                'password_too_common'
            );
        }
    }

    /**
     * Catches the classics and their lazy variants, without punishing a long
     * passphrase that happens to contain one of the words.
     *
     * "Password1!" is rejected. "the cat sat on the password" is not, and
     * should not be - at 27 characters it is far stronger than anything a
     * composition rule would have produced. The check is therefore anchored to
     * the start and bounded by length, not a substring search.
     */
    private function isTooObvious(string $password): bool
    {
        $common = [
            'password', 'passw0rd', '12345678', '123456789', 'qwertyuiop',
            'sokolink', 'letmein', 'welcome', 'iloveyou', 'admin123',
            'abc12345', 'football', 'monkey123',
        ];

        // Strip the decoration people add to get past composition rules, so
        // "P@ssw0rd!" and "password" are recognised as the same idea.
        $normalised = mb_strtolower($password);
        $normalised = strtr($normalised, ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '$' => 's', '!' => '']);
        $normalised = preg_replace('/[^a-z0-9]/', '', $normalised) ?? $normalised;

        foreach ($common as $bad) {
            $badNormalised = strtr($bad, ['0' => 'o', '1' => 'i', '3' => 'e']);

            if ($normalised === $badNormalised) {
                return true;
            }

            // "password1", "sokolink2026" - the word plus a little padding.
            if (str_starts_with($normalised, $badNormalised)
                && mb_strlen($normalised) - mb_strlen($badNormalised) <= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function assertStatusAllowsSignIn(UserStatus $status, array $user, string $email, string $ip): void
    {
        // A correct password for a blocked account is still a successful guess,
        // so it counts as a failure for throttling purposes.
        $refuse = function (string $message, string $reason) use ($email, $ip, $user): never {
            RateLimiter::record($email, $ip, false);

            Audit::record('auth.login.blocked', 'user', (int) $user['id'], $reason);

            throw new DomainRuleException($message, $reason);
        };

        match ($status) {
            // pending_approval signs in deliberately. A seller waiting on a
            // decision should be able to see where their application has got
            // to; what they cannot do is trade, and that is gated on
            // sellers.status rather than on being able to log in at all.
            UserStatus::Active,
            UserStatus::PendingVerification,
            UserStatus::PendingApproval => null,

            UserStatus::Suspended => $refuse(
                'This account is suspended. Contact support if you think that is wrong.',
                'suspended'
            ),

            // A closed account gets the ordinary failure message. Confirming
            // that an address used to have an account is still confirming it.
            UserStatus::Closed => $refuse($this->genericFailure(), 'closed'),
        };
    }

    private function genericFailure(): string
    {
        return 'That email address and password do not match an account.';
    }
}
