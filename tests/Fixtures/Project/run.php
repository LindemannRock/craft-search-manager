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
$vendorRoot = resolveFixtureVendorRoot($packageRoot);
require $vendorRoot . '/autoload.php';

try {
    $result = (new DisposableCraftProject($packageRoot))->run(array_slice($argv, 1));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function resolveFixtureVendorRoot(string $packageRoot): string
{
    $environmentName = 'SEARCH_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
    $configured = $_SERVER[$environmentName] ?? null;
    $candidates = is_string($configured) && $configured !== ''
        ? [$configured]
        : [$packageRoot . '/vendor', dirname($packageRoot, 2) . '/vendor'];

    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved . '/autoload.php') && is_file($resolved . '/bin/phpunit')) {
            $_SERVER[$environmentName] = $resolved;

            return rtrim($resolved, DIRECTORY_SEPARATOR);
        }
    }

    throw new RuntimeException('Unable to resolve the explicit fixture Composer vendor root.');
}
