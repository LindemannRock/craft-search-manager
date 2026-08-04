<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;

/**
 * Focused regressions for audit #141, #142, and #143.
 *
 * @since 5.53.0
 */
final class AnalyticsGeoFailureTest extends TestCase
{
    private const TEST_BACKEND = 'test-audit-final-batch';

    private ?string $originalDefaultCountry = null;
    private ?string $originalDefaultCity = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateAnalytics();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalDefaultCountry = $settings->defaultCountry;
        $this->originalDefaultCity = $settings->defaultCity;
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultCountry = $this->originalDefaultCountry;
        $settings->defaultCity = $this->originalDefaultCity;

        $this->truncateAnalytics();

        parent::tearDown();
    }

    public function testMissingDefaultLocationLogsWarningAndReturnsNull(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultCountry = 'ZZ';
        $settings->defaultCity = 'Missing City';

        $logger = Craft::getLogger();
        $before = count($logger->messages);

        $location = SearchManager::$plugin->analytics->getLocationFromIp('127.0.0.1');

        self::assertNull($location);

        $messages = array_slice($logger->messages, $before);
        $warnings = array_filter($messages, static function(array $message): bool {
            return ($message[1] ?? null) === Logger::LEVEL_WARNING
                && ($message[2] ?? null) === SearchManager::$plugin->id
                && str_contains((string)($message[0] ?? ''), 'Configured default analytics location was not found')
                && str_contains((string)($message[0] ?? ''), '"configuredCountry":"ZZ"')
                && str_contains((string)($message[0] ?? ''), '"configuredCity":"Missing City"');
        });

        self::assertNotEmpty($warnings, 'Missing configured default location should emit a diagnostic warning.');
    }

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['backend' => self::TEST_BACKEND])
            ->execute();
    }
}
