<?php

declare(strict_types=1);

/**
 * Phase 3.1 - the core pieces everything else is built on.
 *
 * Validator and Token need no database. Database::transaction() does, because
 * savepoint behaviour is a property of the server, not of the wrapper, and
 * asserting it against a mock would only prove the mock works.
 *
 * Run: php tests/Unit/core_test.php
 */

use App\Core\Database;
use App\Core\Token;
use App\Core\Validator;

require __DIR__ . '/../bootstrap.php';

TestRunner::suite('Core - validation, tokens, transactions');

// ---------------------------------------------------------------------------
TestRunner::section('A. VALIDATOR - what it accepts');

$v = Validator::make(
    ['email' => '  Asha@Example.COM ', 'quantity' => '3', 'price' => '1500'],
    ['email' => 'required|email|max:190', 'quantity' => 'required|integer|between:1,99', 'price' => 'required|decimal:2']
);

TestRunner::check('A valid payload passes', $v->passes(), implode(',', array_keys($v->errors())));
TestRunner::same('Whitespace is trimmed', 'Asha@Example.COM', $v->validated()['email']);
TestRunner::same('integer normalises the type', 3, $v->validated()['quantity']);
TestRunner::same('decimal keeps money as a fixed-point string', '1500.00', $v->validated()['price']);

$v = Validator::make(['name' => 'Asha', 'sneaky' => 'DROP TABLE users'], ['name' => 'required|max:80']);
TestRunner::check(
    'validated() drops fields that had no rules',
    !array_key_exists('sneaky', $v->validated()),
    'an unexpected field cannot reach an INSERT'
);

// ---------------------------------------------------------------------------
TestRunner::section('B. VALIDATOR - what it refuses');

$cases = [
    ['required',  ['a' => ''],              ['a' => 'required'],              'A is required.'],
    ['email',     ['a' => 'not-an-email'],  ['a' => 'required|email'],        'Enter a valid email address.'],
    ['integer',   ['a' => '3.5'],           ['a' => 'required|integer'],      'A must be a whole number.'],
    ['between',   ['a' => '0'],             ['a' => 'required|integer|between:1,99'], 'A must be between 1 and 99.'],
    ['max chars', ['a' => str_repeat('x', 200)], ['a' => 'required|max:190'], 'A must be no more than 190 characters.'],
    ['in',        ['a' => 'teleport'],      ['a' => 'required|in:pickup,delivery'], 'Choose a valid option for a.'],
    ['confirmed', ['a' => 'secret1234', 'a_confirmation' => 'different'], ['a' => 'required|confirmed'], 'A does not match the confirmation.'],
];

foreach ($cases as [$label, $data, $rules, $expectedMessage]) {
    $v = Validator::make($data, $rules);
    TestRunner::check(
        sprintf('%s is rejected with a readable message', $label),
        $v->fails() && $v->errors()['a'] === $expectedMessage,
        $v->errors()['a'] ?? 'no error raised'
    );
}

$v = Validator::make(['note' => null], ['note' => 'nullable|max:10']);
TestRunner::check('An optional field that is absent does not fail length rules', $v->passes());

// ---------------------------------------------------------------------------
TestRunner::section('C. TOKENS - generation and storage form');

$code = Token::code();
TestRunner::same('A collection code is 6 characters', 6, strlen($code));
TestRunner::check(
    'The alphabet excludes characters people misread',
    preg_match('/[01OIL5S8B]/', $code) === 0,
    $code . ' - no 0/O, 1/I/L, 5/S, 8/B'
);

$codes = [];
for ($i = 0; $i < 500; $i++) {
    $codes[] = Token::code();
}
TestRunner::check(
    '500 generated codes are near-unique',
    count(array_unique($codes)) >= 498,
    count(array_unique($codes)) . ' distinct'
);

$hash = Token::hash($code);
TestRunner::same('The stored form is a 64-character SHA-256', 64, strlen($hash));
TestRunner::check('The plaintext does not appear in the hash', !str_contains($hash, $code));
TestRunner::check('A code verifies against its hash', Token::matches($code, $hash));
TestRunner::check('Lowercase input still matches', Token::matches(mb_strtolower($code), $hash), 'typed at a counter');
TestRunner::check('A wrong code does not match', !Token::matches(Token::code() . 'X', $hash));

$secret   = Token::secret();
$selector = Token::selector();
TestRunner::same('A link secret is 64 hex characters (32 bytes)', 64, strlen($secret));
TestRunner::same('A selector is 24 characters', 24, strlen($selector));
TestRunner::same('split() reverses combine()', [$selector, $secret], Token::split(Token::combine($selector, $secret)));
TestRunner::same('A malformed token splits to null', null, Token::split('no-dot-here'));

TestRunner::check(
    'Idempotency hashes are scoped to the user',
    Token::idempotencyHash('k1', 9, '/checkout') !== Token::idempotencyHash('k1', 10, '/checkout'),
    'the same key from two users is two different orders'
);

// ---------------------------------------------------------------------------
TestRunner::section('D. TRANSACTIONS - commit, rollback and nesting');

Database::statement('DROP TEMPORARY TABLE IF EXISTS tx_probe');
Database::statement('CREATE TEMPORARY TABLE tx_probe (id INT PRIMARY KEY AUTO_INCREMENT, tag VARCHAR(20)) ENGINE=InnoDB');

$count = static fn (): int => (int) Database::scalar('SELECT COUNT(*) FROM tx_probe');

Database::transaction(static function (): void {
    Database::statement("INSERT INTO tx_probe (tag) VALUES ('committed')");
});
TestRunner::same('A completed transaction commits', 1, $count());

try {
    Database::transaction(static function (): void {
        Database::statement("INSERT INTO tx_probe (tag) VALUES ('doomed')");
        throw new RuntimeException('order failed halfway');
    });
} catch (RuntimeException) {
    // expected
}
TestRunner::same('A throwing transaction rolls everything back', 1, $count());

TestRunner::throws(
    'The throwable is re-thrown, not swallowed',
    static fn () => Database::transaction(static fn () => throw new RuntimeException('payment declined')),
    'payment declined'
);

Database::transaction(static function (): void {
    Database::statement("INSERT INTO tx_probe (tag) VALUES ('outer')");

    try {
        Database::transaction(static function (): void {
            Database::statement("INSERT INTO tx_probe (tag) VALUES ('inner')");
            throw new RuntimeException('inner step failed');
        });
    } catch (RuntimeException) {
        // The outer transaction decides what to do about it.
    }
});

$tags = Database::column('SELECT tag FROM tx_probe ORDER BY id');
TestRunner::check(
    'A nested failure rolls back to its savepoint only',
    !in_array('inner', $tags, true),
    'tags: ' . implode(',', array_map('strval', $tags))
);
TestRunner::check(
    'An inner rollback does not force the outer one back',
    in_array('outer', $tags, true),
    'the outer work survives - that is what SAVEPOINT buys'
);

TestRunner::same('Depth returns to zero after a transaction', 0, Database::transactionDepth());
TestRunner::throws(
    'requireTransaction() refuses to run stock work outside one',
    static fn () => Database::requireTransaction('Reserving stock'),
    'must run inside a transaction'
);

Database::statement('DROP TEMPORARY TABLE IF EXISTS tx_probe');

// ---------------------------------------------------------------------------
TestRunner::section('E. CONNECTION SETTINGS - the ones that silently corrupt data');

$mode = (string) Database::scalar('SELECT @@session.sql_mode');
TestRunner::check(
    'The session is in STRICT_ALL_TABLES',
    str_contains($mode, 'STRICT_ALL_TABLES'),
    'without it, money truncates silently'
);

TestRunner::same('The session time zone is UTC', '+00:00', (string) Database::scalar('SELECT @@session.time_zone'));

Database::statement('DROP TEMPORARY TABLE IF EXISTS strict_probe');
Database::statement('CREATE TEMPORARY TABLE strict_probe (v VARCHAR(4))');
TestRunner::throws(
    'An over-long value is refused, not truncated',
    static fn () => Database::statement("INSERT INTO strict_probe (v) VALUES ('TOOLONGVALUE')"),
    'Data too long'
);
Database::statement('DROP TEMPORARY TABLE IF EXISTS strict_probe');

$emulates = Database::connection()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
TestRunner::check(
    'Prepared statements are real, not emulated',
    $emulates === false || $emulates === 0,
    'values are bound server-side'
);

// =============================================================================
TestRunner::section('Session keys survive PHP serialization');

/**
 * A session key containing `|` makes PHP's default session serializer write
 * NOTHING - not just that entry, the whole session - and it does so silently.
 *
 * That is not hypothetical. A throttle bucket key of "auth.login.submit|POST"
 * meant every login wrote _auth_user_id into a session that was then discarded,
 * so signing in appeared to work and the next page asked you to sign in again.
 * No warning, no exception, nothing in any log.
 *
 * The check runs in a SUBPROCESS (session_key_probe.php) because
 * session_start() refuses once anything has been printed, and a test runner
 * prints. It is worth the subprocess: this asserts what PHP actually does
 * rather than a description of it, so if a future version fixes the behaviour
 * these will say so and the sanitising in RateLimiter::bucketKey() can go.
 */
$serializes = static function (string $key): bool {
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/session_key_probe.php')
        . ' ' . escapeshellarg($key);

    return trim((string) shell_exec($command)) === 'YES';
};

TestRunner::check(
    'PHP refuses to serialize a session key containing a pipe',
    !$serializes('has|pipe'),
    'if this fails, PHP fixed it and RateLimiter::bucketKey() can be simplified'
);

// `!` is a delimiter for the `php_binary` handler rather than the default one,
// so it is fine here today. It is asserted anyway, as a statement of what is
// currently true: if this starts failing, the sanitising below is what is
// stopping it becoming another silent session loss.
TestRunner::check(
    'An exclamation mark is currently harmless under the default handler',
    $serializes('has!bang'),
    'ini session.serialize_handler=' . ini_get('session.serialize_handler')
);

TestRunner::check(
    'While an ordinary key serializes, so the probe itself is sound',
    $serializes('_throttle_auth.login.submit.POST')
);

// The property that has to hold: whatever a caller passes, the key the rate
// limiter puts in the session is one PHP can write.
$bucketKey = new ReflectionMethod(App\Core\RateLimiter::class, 'bucketKey');
$bucketKey->setAccessible(true);

foreach ([
    'auth.login.submit|POST' => 'a route name and a verb',
    'something!odd'          => 'an exclamation mark',
    'plain.key.POST'         => 'a key that was already safe',
] as $raw => $what) {
    $safe = (string) $bucketKey->invoke(null, $raw);

    TestRunner::check(
        'A throttle key built from ' . $what . ' is serializable',
        $serializes($safe),
        $raw . ' -> ' . $safe
    );
}

TestRunner::finish();
