<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

/**
 * Who the current actor is.
 *
 * The session holds one thing: a user id. Everything else - roles, permissions,
 * the seller id used for scoping - is loaded from the database on demand and
 * cached for the request only.
 *
 * That matters. If roles were stored in the session, suspending an account or
 * revoking a permission would not take effect until the person logged out,
 * and a stale session would keep its old authority. Reading them per request
 * costs one indexed query and makes revocation immediate.
 *
 * Nothing here ever reads a role, a user id or a permission from the request.
 * "Never trust a role supplied by the browser" is not a guideline that needs
 * remembering if the browser is never asked.
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    /**
     * Statuses that may hold a session. `suspended` and `closed` are absent on
     * purpose: a suspension takes effect on the very next request rather than
     * whenever the person next signs in.
     */
    private const USABLE_STATUSES = ['active', 'pending_verification', 'pending_approval'];

    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    /** @var list<string>|null */
    private static ?array $roles = null;

    /** @var list<string>|null */
    private static ?array $permissions = null;

    private static ?int $sellerId = null;

    private static bool $sellerIdResolved = false;

    private static ?UserRepository $users = null;

    /**
     * Establishes a session for a user that has already been authenticated.
     *
     * AuthService verifies the password; this only records the outcome. The
     * separation is deliberate - there is exactly one place that can create a
     * logged-in session, and it takes a user row, not a set of credentials.
     *
     * @param array<string,mixed> $user
     */
    public static function login(array $user): void
    {
        // A fresh id on every privilege change, so a session id captured before
        // login is worthless afterwards (NFR-SEC-04, session fixation).
        Session::regenerate();
        Csrf::rotate();

        Session::put(self::SESSION_KEY, (int) $user['id']);
        Session::put('_auth_login_at', time());

        self::forgetCache();
        self::$user = $user;
    }

    public static function logout(): void
    {
        self::forgetCache();

        Session::forget(self::SESSION_KEY);
        Session::forget('_auth_login_at');

        Csrf::rotate();
        Session::regenerate();
    }

    /**
     * The id of whoever is acting.
     *
     * The cached user comes first, so a CLI task or a test that called actAs()
     * is the actor even though there is no session. Otherwise it is the
     * session's. Services and Audit call this, which is why neither needs to
     * know whether it is running in a web request or a cron job.
     */
    public static function id(): ?int
    {
        if (self::$user !== null) {
            return (int) self::$user['id'];
        }

        return self::sessionId();
    }

    private static function sessionId(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /**
     * The current user row, or null.
     *
     * An account whose status is no longer usable is treated as logged out -
     * and the session is cleared, so a suspension takes effect on the very next
     * request rather than whenever the person next signs in.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = self::sessionId();
        if ($id === null) {
            return null;
        }

        $user = self::users()->find($id);

        if ($user === null || !in_array((string) $user['status'], self::USABLE_STATUSES, true)) {
            self::logout();
            return null;
        }

        self::$user = $user;

        return self::$user;
    }

    public static function name(): string
    {
        $user = self::user();

        if ($user === null) {
            return 'Guest';
        }

        return trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
    }

    public static function email(): ?string
    {
        $user = self::user();

        return $user === null ? null : (string) $user['email'];
    }

    public static function isVerified(): bool
    {
        $user = self::user();

        return $user !== null && $user['email_verified_at'] !== null;
    }

    // ---- Roles -------------------------------------------------------------

    /** @return list<string> */
    public static function roles(): array
    {
        if (self::$roles !== null) {
            return self::$roles;
        }

        $id = self::currentId();

        self::$roles = $id === null ? [] : self::users()->roleKeys($id);

        return self::$roles;
    }

    public static function hasRole(string ...$roleKeys): bool
    {
        return array_intersect($roleKeys, self::roles()) !== [];
    }

    /**
     * The role a dashboard should open in when a user holds several. Ordered by
     * authority, because an administrator who also sells should land on the
     * admin console rather than guess.
     */
    public static function primaryRole(): ?string
    {
        $roles = self::roles();

        foreach (['admin', 'support', 'delivery_agent', 'seller', 'customer'] as $candidate) {
            if (in_array($candidate, $roles, true)) {
                return $candidate;
            }
        }

        return $roles[0] ?? null;
    }

    // ---- Permissions -------------------------------------------------------

    /** @return list<string> */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        $id = self::currentId();

        self::$permissions = $id === null ? [] : self::users()->permissionKeys($id);

        return self::$permissions;
    }

    /**
     * The only question authorisation should ever ask.
     *
     * Supports a trailing wildcard - can('product.*') - because an admin screen
     * that offers a whole area is a real case, and writing out forty keys in a
     * template is how one gets missed.
     */
    public static function can(string $permission): bool
    {
        $permissions = self::permissions();

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        if (str_ends_with($permission, '.*')) {
            $prefix = substr($permission, 0, -1);

            foreach ($permissions as $held) {
                if (str_starts_with($held, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function cannot(string $permission): bool
    {
        return !self::can($permission);
    }

    // ---- Scoping keys ------------------------------------------------------

    /**
     * The `sellers.id` this user trades as, or null.
     *
     * Every seller-scoped query filters on this value. It comes from the
     * session user via the database; there is no code path that accepts it
     * from a request parameter.
     */
    public static function sellerId(): ?int
    {
        if (self::$sellerIdResolved) {
            return self::$sellerId;
        }

        $id = self::currentId();

        self::$sellerId         = $id === null ? null : self::users()->sellerIdFor($id);
        self::$sellerIdResolved = true;

        return self::$sellerId;
    }

    /** The agent scoping key is the user id itself - delivery_tasks.agent_user_id. */
    public static function agentUserId(): ?int
    {
        $id = self::currentId();

        if ($id === null || !self::hasRole('delivery_agent')) {
            return null;
        }

        return $id;
    }

    // ---- Test and CLI support ----------------------------------------------

    /**
     * Acts as a user without a session - used by the CLI tasks, which have no
     * browser, and by tests. It does not touch $_SESSION, so it cannot leak a
     * logged-in state into a web request.
     */
    public static function actAs(?int $userId): void
    {
        self::forgetCache();

        if ($userId === null) {
            return;
        }

        $user = self::users()->find($userId);

        // The same status gate as user(). A CLI task or a test acting as a
        // suspended account must get nothing, exactly as a web request would -
        // otherwise the scheduled jobs would be a way around a suspension.
        if ($user === null || !in_array((string) $user['status'], self::USABLE_STATUSES, true)) {
            return;
        }

        self::$user = $user;
    }

    /**
     * Drops the per-request cache. Called after anything that changes a user's
     * roles or status mid-request, so the next check sees the new state.
     */
    public static function forgetCache(): void
    {
        self::$user             = null;
        self::$roles            = null;
        self::$permissions      = null;
        self::$sellerId         = null;
        self::$sellerIdResolved = false;
    }

    /**
     * The id of whoever we are acting as: the cached user when actAs() set one
     * (CLI and tests have no session), otherwise the session's. Going through
     * user() first also means a suspended account resolves to null here, so
     * every downstream lookup gets nothing rather than stale authority.
     */
    private static function currentId(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    private static function users(): UserRepository
    {
        return self::$users ??= new UserRepository();
    }
}
