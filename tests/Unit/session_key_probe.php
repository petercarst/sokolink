<?php

declare(strict_types=1);

/**
 * Asks PHP whether it can serialize a session containing a given key.
 *
 * A fixture for core_test.php, not a test itself. It exists as a separate file
 * because `session_start()` refuses once anything has been printed, and a test
 * runner prints - so the only way to ask PHP this question honestly is in a
 * process that has printed nothing yet.
 *
 * Usage:  php tests/Unit/session_key_probe.php '<key>'
 * Prints: YES if the session serializes, NO if PHP discards it.
 */

$key = $argv[1] ?? '';

session_start();

$_SESSION = [$key => 'x'];

echo @session_encode() === false ? 'NO' : 'YES';
