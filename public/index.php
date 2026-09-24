<?php

declare(strict_types=1);

/**
 * Front controller - the single entry point for every web request.
 *
 * Nothing above this directory is reachable over HTTP, so .env, the source
 * code, the SQL files and the logs cannot be downloaded even if PHP stops
 * executing for some reason (NFR-SEC-07).
 */

use App\Core\Application;

$root = dirname(__DIR__);

require $root . '/app/Core/Application.php';

Application::boot($root);
Application::run();
