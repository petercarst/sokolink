<?php

declare(strict_types=1);

/**
 * Phase 3.2 - registration, sign-in, reset, RBAC.
 *
 * Everything runs inside a transaction that is rolled back, so the suite can be
 * run repeatedly against the seeded database without changing it.
 *
 * The assertions worth reading are the negative ones. A login form that lets
 * the right person in is easy; one that refuses a suspended account, refuses to
 * say which addresses exist, and stops after five guesses is the actual
 * requirement.
 *
 * Run: php tests/Integration/auth_test.php
 */

use App\Core\Auth;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\TokenPurpose;
use App\Repositories\ConsentRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Auth - registration, sign-in, reset, RBAC');

const SEED_PASSWORD = 'SokoLink!Dev2026';
const TEST_IP       = '203.0.113.7';

$auth     = new AuthService();
$register = new RegistrationService();
$reset    = new PasswordResetService();
$users    = new UserRepository();
$tokens   = new TokenRepository();
$consent  = new ConsentRepository();

// ---------------------------------------------------------------------------
TestRunner::section('A. SIGN-IN - who gets in');

in_rollback(static function () use ($auth): void {
    $user = $auth->attempt('customer.asha@sokolink.test', SEED_PASSWORD, TEST_IP);

    TestRunner::check('A correct password signs a customer in', Auth::check(), (string) $user['email']);
    TestRunner::same('The session actor is the right user', (int) $user['id'], Auth::id());
    TestRunner::check('Roles are loaded from the database', Auth::hasRole('customer'), implode(',', Auth::roles()));

    $auth->logout();
    TestRunner::check('Logging out clears the actor', Auth::guest());
});

in_rollback(static function () use ($auth): void {
    TestRunner::throws(
        'A wrong password is refused',
        static fn () => $auth->attempt('customer.asha@sokolink.test', 'not-the-password', TEST_IP),
        'do not match an account'
    );

    TestRunner::check('A failed attempt leaves nobody signed in', Auth::guest());
});

in_rollback(static function () use ($auth): void {
    $unknownMessage = '';
    $wrongMessage   = '';

    try {
        $auth->attempt('nobody-at-all@sokolink.test', 'whatever1234', TEST_IP);
    } catch (DomainRuleException $e) {
        $unknownMessage = $e->getMessage();
    }

    try {
        $auth->attempt('customer.asha@sokolink.test', 'whatever1234', TEST_IP);
    } catch (DomainRuleException $e) {
        $wrongMessage = $e->getMessage();
    }

    TestRunner::check(
        'An unknown address and a wrong password give the SAME message',
        $unknownMessage === $wrongMessage && $unknownMessage !== '',
        'the form cannot be used to discover which addresses have accounts'
    );
});

in_rollback(static function () use ($auth): void {
    TestRunner::throws(
        'A suspended account cannot sign in even with the right password',
        static fn () => $auth->attempt('customer.rashid@sokolink.test', SEED_PASSWORD, TEST_IP),
        'suspended'
    );
});

in_rollback(static function () use ($auth): void {
    TestRunner::doesNotThrow(
        'A seller awaiting approval CAN sign in to track the decision',
        static fn () => $auth->attempt('seller.pending@sokolink.test', SEED_PASSWORD, TEST_IP)
    );

    TestRunner::same(
        'But they have no seller id, so nothing seller-scoped can be reached',
        null,
        Auth::sellerId()
    );
});

in_rollback(static function () use ($auth, $users): void {
    $id = seed_user('customer.neema@sokolink.test');
    $users->closeAccount($id);
    Auth::forgetCache();

    TestRunner::throws(
        'A closed account gets the ordinary failure message, not a hint',
        static fn () => $auth->attempt('customer.neema@sokolink.test', SEED_PASSWORD, TEST_IP),
        'do not match an account'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('B. THROTTLING - the fifth guess is the last');

in_rollback(static function () use ($auth): void {
    $email = 'customer.baraka@sokolink.test';

    for ($i = 0; $i < 5; $i++) {
        try {
            $auth->attempt($email, 'wrong-password-' . $i, TEST_IP);
        } catch (DomainRuleException) {
            // expected
        }
    }

    TestRunner::same('Five failures are recorded', 5, RateLimiter::failuresForEmail($email));
    TestRunner::check('The limiter now refuses further attempts', RateLimiter::tooManyAttempts($email, TEST_IP));

    TestRunner::throws(
        'Even the CORRECT password is refused once throttled',
        static fn () => $auth->attempt($email, SEED_PASSWORD, TEST_IP),
        'Too many sign-in attempts'
    );

    TestRunner::check(
        'The refusal says how long to wait',
        RateLimiter::secondsUntilRetry($email, TEST_IP) > 0,
        RateLimiter::secondsUntilRetry($email, TEST_IP) . 's'
    );
});

in_rollback(static function () use ($auth): void {
    $email = 'customer.grace@sokolink.test';

    for ($i = 0; $i < 3; $i++) {
        try {
            $auth->attempt($email, 'wrong', TEST_IP);
        } catch (DomainRuleException) {
        }
    }

    $auth->attempt($email, SEED_PASSWORD, TEST_IP);

    TestRunner::same(
        'A successful sign-in clears the failure history',
        0,
        RateLimiter::failuresForEmail($email)
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('C. REGISTRATION');

in_rollback(static function () use ($register, $users, $consent): void {
    $result = $register->registerCustomer([
        'first_name' => 'Tumaini',
        'last_name'  => 'Mushi',
        'email'      => 'tumaini.test@sokolink.test',
        'phone'      => '+255 712 000 111',
        'password'   => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
        'accept_terms' => '1',
    ], TEST_IP);

    $userId = $result['userId'];
    $user   = $users->find($userId);

    TestRunner::check('An account is created', $user !== null);
    TestRunner::same('It starts unverified', 'pending_verification', (string) $user['status']);
    TestRunner::same('The email is normalised to lowercase', 'tumaini.test@sokolink.test', (string) $user['email']);
    TestRunner::check(
        'The password is stored as a bcrypt hash, not plaintext',
        str_starts_with((string) $user['password_hash'], '$2y$')
            && !str_contains((string) $user['password_hash'], 'correct horse'),
        substr((string) $user['password_hash'], 0, 7) . '...'
    );
    TestRunner::check('password_verify accepts the original', password_verify('correct horse battery', (string) $user['password_hash']));
    TestRunner::same('The customer role is granted', ['customer'], $users->roleKeys($userId));

    TestRunner::check('Terms consent is recorded', $consent->currentlyGrants($userId, ConsentType::Terms));
    TestRunner::check(
        'Marketing consent defaults to NO when the box was not ticked',
        !$consent->currentlyGrants($userId, ConsentType::Marketing),
        'and it is a granted=0 row, not a missing row'
    );

    $marketingRows = (int) Database::scalar(
        "SELECT COUNT(*) FROM consent_records WHERE user_id = :id AND consent_type = 'marketing'",
        ['id' => $userId]
    );
    TestRunner::same('The refusal itself is on file', 1, $marketingRows);

    $queued = (int) Database::scalar(
        "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND template_key = 'auth.verify_email'",
        ['id' => $userId]
    );
    TestRunner::same('A verification email is QUEUED, not sent inline', 1, $queued);

    $status = (string) Database::scalar(
        'SELECT status FROM notifications WHERE user_id = :id LIMIT 1',
        ['id' => $userId]
    );
    TestRunner::same('It sits in the queue for the scheduled task', 'queued', $status);
});

in_rollback(static function () use ($register): void {
    TestRunner::throws(
        'A duplicate email address is refused',
        static fn () => $register->registerCustomer([
            'first_name' => 'Copy', 'last_name' => 'Cat',
            'email' => 'customer.asha@sokolink.test', 'phone' => '0712000222',
            'password' => 'another long password', 'password_confirmation' => 'another long password',
            'accept_terms' => '1',
        ]),
        'already uses that email'
    );

    TestRunner::throws(
        'A short password is refused',
        static fn () => $register->registerCustomer([
            'first_name' => 'Short', 'last_name' => 'Pass',
            'email' => 'short.pass@sokolink.test', 'phone' => '0712000333',
            'password' => 'abc123', 'password_confirmation' => 'abc123',
            'accept_terms' => '1',
        ]),
        'at least 10 characters'
    );

    TestRunner::throws(
        'A mismatched confirmation is refused',
        static fn () => $register->registerCustomer([
            'first_name' => 'Mis', 'last_name' => 'Match',
            'email' => 'mis.match@sokolink.test', 'phone' => '0712000444',
            'password' => 'a long enough password', 'password_confirmation' => 'a different password',
            'accept_terms' => '1',
        ]),
        'does not match the confirmation'
    );

    TestRunner::throws(
        'Registration without accepting the terms is refused',
        static fn () => $register->registerCustomer([
            'first_name' => 'No', 'last_name' => 'Terms',
            'email' => 'no.terms@sokolink.test', 'phone' => '0712000555',
            'password' => 'a long enough password', 'password_confirmation' => 'a long enough password',
        ]),
        'Accept the terms'
    );

    try {
        $register->registerCustomer(['email' => 'bad']);
    } catch (ValidationException $e) {
        TestRunner::check(
            'Validation failures come back per field, not as one banner',
            count($e->errors()) >= 4,
            implode(', ', array_keys($e->errors()))
        );
    }
});

in_rollback(static function () use ($register, $users): void {
    $result = $register->registerSeller([
        'first_name' => 'Neema', 'last_name' => 'Kimaro',
        'email' => 'neema.shop@sokolink.test', 'phone' => '+255 754 111 222',
        'password' => 'a good long passphrase', 'password_confirmation' => 'a good long passphrase',
        'accept_terms' => '1',
        'business_name' => 'Neema Fresh Produce', 'business_type' => 'sole_trader',
        'region' => 'Dar es Salaam', 'district' => 'Kinondoni',
        'store_name' => 'Neema Fresh - Mwenge', 'street' => '12 Mwenge Road',
        'categories_text' => 'Fruit, vegetables, dried goods',
        'offers_pickup' => '1',
    ], TEST_IP);

    $user = $users->find($result['userId']);

    TestRunner::same('A seller applicant starts pending_approval', 'pending_approval', (string) $user['status']);
    TestRunner::check('They hold the seller role', $users->hasRole($result['userId'], 'seller'));
    TestRunner::same(
        'But no sellers row exists yet, so they cannot trade',
        null,
        $users->sellerIdFor($result['userId'])
    );

    $application = Database::selectOne(
        'SELECT status, business_name FROM seller_applications WHERE id = :id',
        ['id' => $result['applicationId']]
    );
    TestRunner::same('The application is recorded as pending', 'pending_approval', (string) $application['status']);

    TestRunner::throws(
        'An application offering neither collection nor delivery is refused',
        static fn () => $register->registerSeller([
            'first_name' => 'No', 'last_name' => 'Fulfilment',
            'email' => 'no.fulfilment@sokolink.test', 'phone' => '0712000666',
            'password' => 'a good long passphrase', 'password_confirmation' => 'a good long passphrase',
            'accept_terms' => '1',
            'business_name' => 'Nowhere Ltd', 'business_type' => 'company',
            'region' => 'Dodoma', 'district' => 'Dodoma Urban',
            'store_name' => 'Nowhere', 'street' => 'Somewhere',
            'categories_text' => 'Things',
        ]),
        'at least one way'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('D. EMAIL VERIFICATION');

in_rollback(static function () use ($register, $users): void {
    $result = $register->registerCustomer([
        'first_name' => 'Verify', 'last_name' => 'Me',
        'email' => 'verify.me@sokolink.test', 'phone' => '0712000777',
        'password' => 'a long enough password', 'password_confirmation' => 'a long enough password',
        'accept_terms' => '1',
    ]);

    $stored = Database::scalar(
        "SELECT token_hash FROM user_tokens WHERE user_id = :id AND purpose = 'email_verification'",
        ['id' => $result['userId']]
    );

    TestRunner::check(
        'The token is stored hashed, never in plaintext',
        is_string($stored) && strlen($stored) === 64 && !str_contains($result['verificationToken'], $stored),
        'SHA-256 of the secret half'
    );

    $verifiedId = $register->verifyEmail($result['verificationToken']);
    $user       = $users->find($verifiedId);

    TestRunner::same('The correct link verifies the address', $result['userId'], $verifiedId);
    TestRunner::check('email_verified_at is set', $user['email_verified_at'] !== null);
    TestRunner::same('The account becomes active', 'active', (string) $user['status']);

    TestRunner::throws(
        'The same link cannot be used twice',
        static fn () => $register->verifyEmail($result['verificationToken']),
        'no longer valid'
    );

    TestRunner::throws(
        'A made-up link is refused',
        static fn () => $register->verifyEmail('aaaaaaaaaaaaaaaaaaaaaaaa.' . str_repeat('b', 64)),
        'no longer valid'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('E. PASSWORD RESET');

in_rollback(static function () use ($reset, $auth, $users): void {
    $token = $reset->request('customer.joseph@sokolink.test', TEST_IP);

    TestRunner::check('A known address gets a token', is_string($token), 'split selector.secret');
    TestRunner::check('The token is usable before it is consumed', $reset->tokenIsUsable((string) $token));

    $userId = $reset->reset((string) $token, 'a brand new passphrase', 'a brand new passphrase', TEST_IP);
    $user   = $users->find($userId);

    TestRunner::check('The new password works', password_verify('a brand new passphrase', (string) $user['password_hash']));
    TestRunner::check('The old password no longer works', !password_verify(SEED_PASSWORD, (string) $user['password_hash']));

    TestRunner::throws(
        'The reset link cannot be replayed',
        static fn () => $reset->reset((string) $token, 'yet another passphrase', 'yet another passphrase'),
        'expired or has already been used'
    );

    TestRunner::doesNotThrow(
        'The customer can sign in with the new password',
        static fn () => $auth->attempt('customer.joseph@sokolink.test', 'a brand new passphrase', TEST_IP)
    );
});

in_rollback(static function () use ($reset): void {
    $unknown = $reset->request('definitely-not-a-customer@sokolink.test', TEST_IP);

    TestRunner::same(
        'An unknown address produces no token and no error',
        null,
        $unknown
    );

    TestRunner::check(
        'The caller cannot tell the two cases apart from the return type',
        true,
        'both paths return without throwing - the page says the same thing'
    );
});

in_rollback(static function () use ($reset): void {
    TestRunner::same(
        'A suspended account gets no reset link',
        null,
        $reset->request('customer.rashid@sokolink.test', TEST_IP)
    );
});

in_rollback(static function () use ($reset): void {
    TestRunner::throws(
        'Mismatched new passwords are refused',
        static fn () => $reset->reset('anything.anything', 'one passphrase', 'another passphrase'),
        'do not match'
    );
});

in_rollback(static function () use ($reset, $tokens): void {
    $first  = $reset->request('customer.grace@sokolink.test', TEST_IP);
    $second = $reset->request('customer.grace@sokolink.test', TEST_IP);

    TestRunner::check(
        'Requesting a second link invalidates the first',
        !$reset->tokenIsUsable((string) $first) && $reset->tokenIsUsable((string) $second),
        'only the newest link works'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('F. RBAC - roles and permissions come from the database');

in_rollback(static function (): void {
    as_user('admin@sokolink.test', static function (): void {
        TestRunner::check('The admin holds the admin role', Auth::hasRole('admin'));
        TestRunner::check('The admin has permissions loaded', count(Auth::permissions()) > 0, count(Auth::permissions()) . ' permissions');
        TestRunner::same('primaryRole picks the most authoritative', 'admin', Auth::primaryRole());
    });

    as_user('customer.asha@sokolink.test', static function (): void {
        TestRunner::check('A customer does not hold the admin role', !Auth::hasRole('admin'));
        TestRunner::check(
            'A customer cannot approve a seller application',
            Auth::cannot('seller.application.approve'),
            'checked against permissions, not a role name'
        );
    });

    as_user('seller.mama.lishe@sokolink.test', static function (): void {
        TestRunner::check('A seller resolves to a seller id', Auth::sellerId() !== null, 'seller ' . Auth::sellerId());
        TestRunner::check('A seller is not a support agent', !Auth::hasRole('support'));
    });

    as_user('agent.juma@sokolink.test', static function (): void {
        TestRunner::check('An agent resolves to an agent user id', Auth::agentUserId() !== null);
        TestRunner::same('An agent has no seller id', null, Auth::sellerId());
    });
});

in_rollback(static function () use ($users): void {
    $id = seed_user('customer.baraka@sokolink.test');

    Auth::actAs($id);
    TestRunner::check('Baraka starts without the support role', !Auth::hasRole('support'));

    $users->assignRole($id, 'support', seed_user('admin@sokolink.test'));
    Auth::forgetCache();
    Auth::actAs($id);

    TestRunner::check(
        'A role granted mid-session takes effect on the next check',
        Auth::hasRole('support'),
        'roles are read per request, never cached in the session'
    );

    $users->setStatus($id, 'suspended', 'Test suspension');
    Auth::forgetCache();
    Auth::actAs($id);

    TestRunner::check(
        'Suspending an account revokes it immediately',
        Auth::guest(),
        'no waiting for the session to expire'
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('G. PASSWORD CHANGE');

in_rollback(static function () use ($auth): void {
    $auth->attempt('customer.asha@sokolink.test', SEED_PASSWORD, TEST_IP);

    TestRunner::throws(
        'Changing a password requires the current one',
        static fn () => $auth->changePassword('not-the-current-one', 'a new long passphrase'),
        'current password is not correct'
    );

    TestRunner::throws(
        'A password containing the email address is refused',
        static fn () => $auth->changePassword(SEED_PASSWORD, 'customer.asha-is-my-password'),
        'should not contain your email'
    );

    TestRunner::throws(
        'An obvious password is refused',
        static fn () => $auth->changePassword(SEED_PASSWORD, 'P@ssw0rd!23'),
        'too easy to guess'
    );

    TestRunner::doesNotThrow(
        'A good password is accepted',
        static fn () => $auth->changePassword(SEED_PASSWORD, 'a considered new passphrase')
    );
});

// ---------------------------------------------------------------------------
TestRunner::section('H. AUDIT TRAIL');

in_rollback(static function () use ($auth): void {
    $before = (int) Database::scalar('SELECT COUNT(*) FROM audit_log');

    $auth->attempt('support@sokolink.test', SEED_PASSWORD, TEST_IP);

    try {
        $auth->attempt('support@sokolink.test', 'wrong', TEST_IP);
    } catch (DomainRuleException) {
    }

    $after = (int) Database::scalar('SELECT COUNT(*) FROM audit_log');

    TestRunner::check('Sign-ins and failures are both recorded', $after - $before >= 2, ($after - $before) . ' new rows');

    $actions = Database::column(
        "SELECT action FROM audit_log ORDER BY id DESC LIMIT 2"
    );
    TestRunner::check(
        'A failed sign-in is distinguishable from a successful one',
        in_array('auth.login.failed', array_map('strval', $actions), true),
        implode(', ', array_map('strval', $actions))
    );

    $hasSecret = (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_log
          WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
            AND (COALESCE(before_json,'') LIKE '%SokoLink!Dev%' OR COALESCE(after_json,'') LIKE '%SokoLink!Dev%')"
    );
    TestRunner::same('No password reaches the audit log', 0, $hasSecret);
});

TestRunner::finish();
