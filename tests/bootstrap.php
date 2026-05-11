<?php

/**
 * PHPUnit bootstrap for the search-manager plugin.
 *
 * Runs inside DDEV. Initialises Craft as a console application so tests can
 * touch the live database, services, and plugins. Tests are responsible for
 * cleaning up any state they create — there is no DB transaction rollback,
 * because Craft's services do their own writes that don't compose well with
 * transactional fixtures.
 *
 * @since 5.45.0
 */

declare(strict_types=1);

// Walk up to the project root (the parent that holds composer.json, craft, vendor).
$projectRoot = dirname(__DIR__, 3);

if (!file_exists($projectRoot . '/bootstrap.php')) {
    fwrite(STDERR, "Project root bootstrap.php not found at {$projectRoot}/bootstrap.php\n");
    fwrite(STDERR, "Tests must run inside the DDEV plugins workspace.\n");
    exit(1);
}

require_once $projectRoot . '/bootstrap.php';

// Loading Craft's console.php both initialises Craft::$app and returns the
// Application instance. We don't need to call ->run() — tests drive the app
// directly via Craft::$app and SearchManager::$plugin.
require $projectRoot . '/vendor/craftcms/cms/bootstrap/console.php';
