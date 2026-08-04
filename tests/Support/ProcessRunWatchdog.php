<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;

require __DIR__ . '/TestProjectBoundary.php';
$projectBoundary = TestProjectBoundary::resolve();
require $projectBoundary->vendorAutoload();

$journalDirectory = $argv[1] ?? null;
if (!is_string($journalDirectory) || $journalDirectory === '') {
    fwrite(STDERR, "Usage: php ProcessRunWatchdog.php EXACT-JOURNAL-DIRECTORY\n");
    exit(2);
}

exit(ProcessRunOwner::watchdogMain($journalDirectory));
