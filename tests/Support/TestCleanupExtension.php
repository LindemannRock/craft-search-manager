<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Runs Search Manager's isolation cleanup even when a child teardown fails
 * before calling its parent.
 *
 * @since 5.54.0
 */
final class TestCleanupExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new TestFinishedCleanupSubscriber());
    }
}

/** @internal */
final class TestFinishedCleanupSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        TestCase::finishActiveTestIsolation();
    }
}
