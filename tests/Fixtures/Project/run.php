<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\DisposableCraftProject;

$packageRoot = dirname(__DIR__, 3);
$vendorEnvironmentName = 'SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
$vendorRoot = $_SERVER[$vendorEnvironmentName] ?? null;
if (!is_string($vendorRoot) || $vendorRoot === '') {
    fwrite(STDERR, $vendorEnvironmentName . " must be set.\n");
    exit(2);
}
require rtrim($vendorRoot, DIRECTORY_SEPARATOR) . '/autoload.php';

try {
    $result = (new DisposableCraftProject($packageRoot))->run(array_slice($argv, 1));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
