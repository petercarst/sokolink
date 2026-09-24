<?php

declare(strict_types=1);

/**
 * The scheduled task runner.
 *
 * Everything time-based in this application runs from here, and nothing runs
 * from a browser. That is the brief's requirement - "do not rely on a browser
 * being open to execute scheduled reminders" - and it applies to the rest of it
 * too: an unpaid order should expire whether or not anyone is looking at the
 * site.
 *
 * Usage:
 *   php bin/console.php <task> [--limit=N] [--quiet]
 *   php bin/console.php list
 *
 * Every task is idempotent. Running one twice in a row does the work once,
 * because each guards on the state it is about to change - a guarded UPDATE, a
 * unique key, or both. That matters more than it sounds: cron will eventually
 * run something twice, and a second run must not send a second reminder.
 *
 * Exit codes: 0 success, 1 task failed, 2 unknown task.
 *
 * See docs/BACKEND_MODULES.md for the suggested schedule.
 */

use App\Core\Application;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Repositories\CartRepository;
use App\Repositories\TokenRepository;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\PickupService;
use App\Services\Retention\ReminderService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/Core/Application.php';

Application::bootConsole(dirname(__DIR__));

/**
 * The tasks, with what they do and how often they should run.
 *
 * @var array<string,array{description:string,schedule:string,run:callable(int):array<string,mixed>}>
 */
$tasks = [
    'expire-unpaid-orders' => [
        'description' => 'Cancels orders that were never paid and puts their stock back on the shelf.',
        'schedule'    => 'every 5 minutes',
        'run'         => static fn (int $limit): array => (new PaymentService())->expireUnpaidOrders($limit),
    ],

    'mark-overdue-collections' => [
        'description' => 'Flags collections whose window has closed, so sellers can see what is taking up space.',
        'schedule'    => 'hourly',
        'run'         => static fn (int $limit): array => (new PickupService())->markOverdueCollections($limit),
    ],

    'schedule-reminders' => [
        'description' => 'Works out when customers might need things again. Schedules nothing it cannot justify.',
        'schedule'    => 'daily, early morning',
        'run'         => static fn (int $limit): array => (new ReminderService())->schedule($limit),
    ],

    'send-reminders' => [
        'description' => 'Sends the reminders that are due, or records exactly why each one was not sent.',
        'schedule'    => 'hourly during the day',
        'run'         => static fn (int $limit): array => (new ReminderService())->dispatch($limit),
    ],

    'send-notifications' => [
        'description' => 'Drains the outbound message queue. SMS and WhatsApp are recorded as skipped - no provider is connected.',
        'schedule'    => 'every 2 minutes',
        'run'         => static fn (int $limit): array => (new NotificationService())->dispatchQueue($limit),
    ],

    'prune-expired-tokens' => [
        'description' => 'Removes consumed and expired reset/verification tokens, and old login attempts.',
        'schedule'    => 'daily',
        'run'         => static fn (int $limit): array => [
            'tokens_removed'   => (new TokenRepository())->pruneExpired(),
            'attempts_removed' => RateLimiter::prune(),
        ],
    ],

    'abandon-stale-carts' => [
        'description' => 'Marks guest baskets nobody came back to. Account baskets are left alone.',
        'schedule'    => 'weekly',
        'run'         => static fn (int $limit): array => [
            'carts_abandoned' => (new CartRepository())->abandonStaleGuestCarts(),
        ],
    ],
];

// ---- Arguments --------------------------------------------------------------

$arguments = array_slice($argv, 1);
$task      = $arguments[0] ?? 'list';
$limit     = 100;
$quiet     = false;

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, 8));
    }

    if ($argument === '--quiet' || $argument === '-q') {
        $quiet = true;
    }
}

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . PHP_EOL;
    }
};

// ---- list -------------------------------------------------------------------

if ($task === 'list' || $task === '--help' || $task === '-h') {
    echo PHP_EOL . 'SokoLink scheduled tasks' . PHP_EOL;
    echo str_repeat('=', 74) . PHP_EOL . PHP_EOL;

    foreach ($tasks as $name => $definition) {
        printf("  %-26s %s%s", $name, $definition['schedule'], PHP_EOL);
        printf("  %-26s %s%s%s", '', $definition['description'], PHP_EOL, PHP_EOL);
    }

    echo 'Run one with:  php bin/console.php <task> [--limit=N] [--quiet]' . PHP_EOL . PHP_EOL;
    echo 'On Windows, Task Scheduler runs these; on Linux, cron. Examples are in' . PHP_EOL;
    echo 'docs/BACKEND_MODULES.md.' . PHP_EOL . PHP_EOL;

    exit(0);
}

if (!isset($tasks[$task])) {
    fwrite(STDERR, sprintf('Unknown task "%s". Run "php bin/console.php list" to see them.%s', $task, PHP_EOL));
    exit(2);
}

// ---- Run --------------------------------------------------------------------

// Scheduled work is attributed to the system, not to a person. Auth::id()
// returns null, so every audit row and history entry written by a task is
// recorded as having no human actor - which is the truth.
Auth::actAs(null);

$startedAt = microtime(true);

$say(sprintf('[%s UTC] %s', gmdate('Y-m-d H:i:s'), $task));

try {
    $result = $tasks[$task]['run']($limit);

    $elapsed = round((microtime(true) - $startedAt) * 1000);

    foreach ($result as $key => $value) {
        $say(sprintf('  %-18s %s', $key, is_scalar($value) ? (string) $value : json_encode($value)));
    }

    $say(sprintf('  %-18s %d ms', 'took', $elapsed));

    Logger::info('Scheduled task completed', [
        'task'    => $task,
        'result'  => $result,
        'ms'      => $elapsed,
    ]);

    exit(0);
} catch (Throwable $e) {
    // A failing task must be loud in the log and non-zero to the scheduler, so
    // a monitoring system can see it. It must not print a stack trace into
    // whatever collects cron output.
    $reference = Logger::exception($e);

    fwrite(STDERR, sprintf(
        '[%s UTC] %s FAILED: %s (reference %s)%s',
        gmdate('Y-m-d H:i:s'),
        $task,
        $e->getMessage(),
        $reference,
        PHP_EOL
    ));

    if (Database::inTransaction()) {
        // Should not happen - every service closes its own - but leaving a
        // transaction open would hold locks until the connection dropped.
        fwrite(STDERR, 'A transaction was still open and has been rolled back.' . PHP_EOL);
    }

    exit(1);
}
