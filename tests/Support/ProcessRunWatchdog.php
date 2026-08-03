<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$journalDirectory = $argv[1] ?? null;
if (!is_string($journalDirectory) || $journalDirectory === '') {
    fwrite(STDERR, "Usage: php ProcessRunWatchdog.php EXACT-JOURNAL-DIRECTORY\n");
    exit(2);
}

exit(ProcessRunOwner::watchdogMain($journalDirectory));
