<?php

declare(strict_types=1);

/**
 * Shared harness for the test scripts.
 *
 * No PHPUnit. The brief allows Composer only where genuinely required, and a
 * runner that prints PASS/FAIL lines and exits non-zero is the whole of what
 * these tests need - with the side benefit that the output in the phase report
 * is the actual output, readable without knowing a framework's conventions.
 *
 * Every test here talks to the real `sokolink` database. They are integration
 * tests on purpose: the guarantees being checked are enforced by constraints
 * and transactions, and a mocked PDO would prove nothing about either.
 *
 * Tests clean up after themselves. Anything a test creates is removed in its
 * teardown, so the seed data is the same before and after a run - which is
 * what makes the suite re-runnable without a reimport.
 */

use App\Core\Application;
use App\Core\Auth;
use App\Core\Database;

require __DIR__ . '/../app/Core/Application.php';

Application::bootConsole(dirname(__DIR__));

final class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;

    /** @var list<string> */
    private static array $failures = [];

    private static string $suite = '';

    public static function suite(string $name): void
    {
        self::$suite = $name;

        echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
        echo strtoupper($name) . PHP_EOL;
        echo str_repeat('=', 74) . PHP_EOL;
    }

    public static function section(string $name): void
    {
        echo PHP_EOL . $name . PHP_EOL;
        echo str_repeat('-', 74) . PHP_EOL;
    }

    public static function check(string $label, bool $ok, string $detail = ''): bool
    {
        if ($ok) {
            self::$passed++;
        } else {
            self::$failed++;
            self::$failures[] = $label . ($detail !== '' ? ' - ' . $detail : '');
        }

        printf(
            "  [%s] %-58s %s%s",
            $ok ? 'PASS' : 'FAIL',
            mb_strimwidth($label, 0, 58, ''),
            $detail,
            PHP_EOL
        );

        return $ok;
    }

    public static function same(string $label, mixed $expected, mixed $actual): bool
    {
        $ok = $expected === $actual;

        return self::check(
            $label,
            $ok,
            $ok ? self::render($actual) : sprintf('expected %s, got %s', self::render($expected), self::render($actual))
        );
    }

    /**
     * Asserts that $work throws, optionally that the message contains $needle.
     * Used far more than it looks like it should be: half of what these
     * services guarantee is what they REFUSE to do.
     */
    public static function throws(string $label, callable $work, string $needle = ''): bool
    {
        try {
            $work();
        } catch (Throwable $e) {
            $matched = $needle === '' || stripos($e->getMessage(), $needle) !== false;

            return self::check(
                $label,
                $matched,
                $matched ? self::shorten($e->getMessage()) : 'wrong message: ' . self::shorten($e->getMessage())
            );
        }

        return self::check($label, false, 'no exception was thrown');
    }

    public static function doesNotThrow(string $label, callable $work): bool
    {
        try {
            $work();
        } catch (Throwable $e) {
            return self::check($label, false, get_class($e) . ': ' . self::shorten($e->getMessage()));
        }

        return self::check($label, true);
    }

    public static function finish(): never
    {
        echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
        printf('RESULT: %d passed, %d failed%s', self::$passed, self::$failed, PHP_EOL);

        if (self::$failures !== []) {
            echo PHP_EOL . 'Failures:' . PHP_EOL;
            foreach (self::$failures as $failure) {
                echo '  - ' . $failure . PHP_EOL;
            }
        }

        echo str_repeat('=', 74) . PHP_EOL . PHP_EOL;

        exit(self::$failed === 0 ? 0 : 1);
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            is_bool($value)  => $value ? 'true' : 'false',
            is_null($value)  => 'null',
            is_array($value) => '[' . implode(', ', array_map(self::render(...), $value)) . ']',
            $value instanceof BackedEnum => $value::class . '::' . $value->value,
            $value instanceof UnitEnum   => $value::class . '::' . $value->name,
            is_object($value) => get_class($value),
            default          => self::shorten((string) $value),
        };
    }

    private static function shorten(string $value): string
    {
        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '...' : $value;
    }
}

/** Thrown at the end of in_rollback() to force the transaction back. */
final class RollbackSignal extends RuntimeException
{
}

/**
 * Runs $work inside a transaction that is always rolled back.
 *
 * The cleanest possible teardown: a test can insert whatever it likes, assert
 * on it, and leave the database exactly as it found it.
 *
 * It goes through Database::transaction() rather than calling
 * PDO::beginTransaction() directly, so the depth counter stays honest and a
 * service that opens its own transaction inside gets a SAVEPOINT - which is
 * what production does too. Starting the transaction behind the wrapper's back
 * would make the tests exercise a code path that never runs for real.
 *
 * @template T
 * @param callable():T $work
 * @return T
 */
function in_rollback(callable $work): mixed
{
    $result = null;

    try {
        Database::transaction(static function () use ($work, &$result): void {
            $result = $work();

            throw new RollbackSignal('rolling back test data');
        });
    } catch (RollbackSignal) {
        // Exactly what we asked for.
    } finally {
        // On the CLI there is no real session, but Session::put() still writes
        // to $_SESSION, so a sign-in in one test would otherwise still be in
        // force in the next. Each test starts signed out.
        $_SESSION = [];
        Auth::forgetCache();
    }

    return $result;
}

/** The seeded user id for a given test account, by email. */
function seed_user(string $email): int
{
    $id = Database::scalar('SELECT id FROM users WHERE email = :e', ['e' => $email]);

    if ($id === null) {
        fwrite(STDERR, "Seed account {$email} is missing. Import database/seed.sql first." . PHP_EOL);
        exit(1);
    }

    return (int) $id;
}

/** Acts as a seeded account for the duration of a callback. */
function as_user(string $email, callable $work): mixed
{
    $previous = Auth::id();

    Auth::actAs(seed_user($email));

    try {
        return $work();
    } finally {
        Auth::actAs($previous);
    }
}
