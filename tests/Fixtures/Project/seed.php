<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;
use lindemannrock\searchmanager\tests\Support\TestProjectFixtureSeeder;

require dirname(__DIR__, 2) . '/Support/TestProjectBoundary.php';
$boundary = TestProjectBoundary::resolve();
require $boundary->vendorAutoload();
require $boundary->projectRoot . '/bootstrap.php';
require $boundary->vendorRoot . '/craftcms/cms/bootstrap/console.php';

try {
    $identity = (new TestProjectFixtureSeeder($boundary))->seed();
    fwrite(STDOUT, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
