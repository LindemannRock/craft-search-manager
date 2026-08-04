<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

/**
 * PHPUnit bootstrap for the search-manager plugin.
 *
 * Delegates to the shared base-plugin bootstrap, which initialises Craft as a
 * console application. Search Manager's TestCase adds a per-test transaction,
 * isolated queue/runtime components, and exact-owner cleanup on top.
 *
 * @since 5.46.0
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;

$projectBoundaryFile = __DIR__ . '/Support/TestProjectBoundary.php';
$processRunOwner = __DIR__ . '/Support/ProcessRunOwner.php';
require_once $projectBoundaryFile;
$projectBoundary = TestProjectBoundary::resolve();
require_once $projectBoundary->vendorAutoload();
require_once $processRunOwner;
ProcessRunOwner::bootstrap();
require_once $projectBoundary->baseBootstrap();
\lindemannrock\base\testing\bootstrap($projectBoundary->projectRoot);
