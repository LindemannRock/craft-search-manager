<?php

/**
 * PHPUnit bootstrap for the search-manager plugin.
 *
 * Delegates to the shared base-plugin bootstrap, which initialises Craft as a
 * console application. Tests run against the live DDEV database — there is no
 * transactional rollback. Cleanup is by marker (see `tests/TestCase.php`).
 *
 * @since 5.45.0
 */

declare(strict_types=1);

$baseBootstrap = dirname(__DIR__, 3) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php';

if (!file_exists($baseBootstrap)) {
    fwrite(STDERR, "Base plugin testing bootstrap not found at {$baseBootstrap}\n");
    fwrite(STDERR, "Run `composer install` and ensure lindemannrock/craft-plugin-base ^5.25 is present.\n");
    exit(1);
}

require_once $baseBootstrap;

\lindemannrock\base\testing\bootstrap();
