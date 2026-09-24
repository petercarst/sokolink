<?php

declare(strict_types=1);

/**
 * Runs every test script and reports the totals.
 *
 * Each suite is a separate process on purpose. A fatal error in one file then
 * cannot take the rest of the run with it, and the per-suite exit codes are
 * what a CI job would read.
 *
 *   php tests/run.php            # everything
 *   php tests/run.php --quiet    # totals only
 *
 * Exit code 0 when every suite passed.
 */

$root   = dirname(__DIR__);
$quiet  = in_array('--quiet', array_slice($argv, 1), true);

$suites = [
    'Core (unit)'            => 'tests/Unit/core_test.php',
    'Domain and state machine' => 'tests/Integration/domain_test.php',
    'Schema contracts'       => 'tests/Integration/schema_contracts_test.php',
    'Auth and RBAC'          => 'tests/Integration/auth_test.php',
    'Catalogue and inventory' => 'tests/Integration/catalog_test.php',
    'Checkout and orders'    => 'tests/Integration/checkout_test.php',
    'Fulfilment and payment' => 'tests/Integration/fulfilment_test.php',
    'Retention and messaging' => 'tests/Integration/retention_test.php',
    'Support, admin, CLI'    => 'tests/Integration/support_admin_test.php',
    'Oversell (concurrency)' => 'tests/Concurrency/oversell_test.php',
    'Customer journey (HTTP)' => 'tests/Http/customer_journey_test.php',
    'Seller workflow (HTTP)'  => 'tests/Http/seller_workflow_test.php',
    'Delivery workflow (HTTP)' => 'tests/Http/delivery_workflow_test.php',
    'Support and retention (HTTP)' => 'tests/Http/support_workflow_test.php',
];

echo PHP_EOL . str_repeat('=', 74) . PHP_EOL;
echo 'SOKOLINK TEST SUITE' . PHP_EOL;
echo str_repeat('=', 74) . PHP_EOL . PHP_EOL;

$totalPassed = 0;
$totalFailed = 0;
$failedSuites = [];

foreach ($suites as $label => $path) {
    $command = sprintf('php %s 2>&1', escapeshellarg($root . '/' . $path));

    $output = [];
    $code   = 0;
    exec($command, $output, $code);

    $text = implode("\n", $output);

    // Both runners print a RESULT line; the concurrency script prints its own
    // wording, so fall back to counting PASS/FAIL markers.
    if (preg_match('/RESULT: (\d+) passed, (\d+) failed/', $text, $m) === 1) {
        $passed = (int) $m[1];
        $failed = (int) $m[2];
    } else {
        $passed = substr_count($text, '[PASS]');
        $failed = substr_count($text, '[FAIL]');
    }

    $totalPassed += $passed;
    $totalFailed += $failed;

    if ($code !== 0 || $failed > 0) {
        $failedSuites[$label] = $text;
    }

    printf(
        "  %-26s %s  %3d passed, %d failed%s",
        $label,
        $code === 0 && $failed === 0 ? 'OK  ' : 'FAIL',
        $passed,
        $failed,
        PHP_EOL
    );

    if (!$quiet && ($code !== 0 || $failed > 0)) {
        foreach ($output as $line) {
            if (str_contains($line, '[FAIL]') || str_contains($line, 'Fatal error')) {
                echo '      ' . trim($line) . PHP_EOL;
            }
        }
    }
}

echo PHP_EOL . str_repeat('-', 74) . PHP_EOL;
printf(
    'TOTAL: %d assertions, %d passed, %d failed, across %d suites%s',
    $totalPassed + $totalFailed,
    $totalPassed,
    $totalFailed,
    count($suites),
    PHP_EOL
);
echo str_repeat('-', 74) . PHP_EOL . PHP_EOL;

if ($failedSuites !== []) {
    echo 'Suites needing attention: ' . implode(', ', array_keys($failedSuites)) . PHP_EOL . PHP_EOL;
}

exit($totalFailed === 0 && $failedSuites === [] ? 0 : 1);
