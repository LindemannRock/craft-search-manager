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
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\jobs\GeoLookupJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;

/**
 * Pins local/private IP geo fallback behavior.
 *
 * @since 5.53.0
 */
final class AnalyticsGeoDefaultsTest extends TestCase
{
    private const TEST_BACKEND = 'test-geo-defaults';
    private const TEST_SITE_ID = 999994;

    private ?string $savedDefaultCountry = null;
    private ?string $savedDefaultCity = null;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->savedDefaultCountry = $settings->defaultCountry;
        $this->savedDefaultCity = $settings->defaultCity;

        $this->truncateAnalytics();
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultCountry = $this->savedDefaultCountry;
        $settings->defaultCity = $this->savedDefaultCity;

        $this->truncateAnalytics();

        parent::tearDown();
    }

    public function testPrivateIpHasNoGeoLocationWithoutExplicitDefaults(): void
    {
        $this->withoutDefaultLocationEnv(function(): void {
            $settings = SearchManager::$plugin->getSettings();
            $settings->defaultCountry = null;
            $settings->defaultCity = null;

            self::assertNull(
                SearchManager::$plugin->analytics->getLocationFromIp('127.0.0.1'),
                'Private/local IPs must not synthesize a default geo location unless both defaults are configured.',
            );
        });
    }

    public function testPrivateIpUsesExplicitSupportedDefaults(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultCountry = 'US';
        $settings->defaultCity = 'New York';

        $location = SearchManager::$plugin->analytics->getLocationFromIp('192.168.1.42');

        self::assertIsArray($location);
        self::assertSame('US', $location['countryCode']);
        self::assertSame('New York', $location['city']);
    }

    public function testPrivateIpUsesEuropeanLocaleDefaults(): void
    {
        $expectedDefaults = [
            'NL' => ['Amsterdam', 'Netherlands'],
            'SE' => ['Stockholm', 'Sweden'],
            'DK' => ['Copenhagen', 'Denmark'],
            'NO' => ['Oslo', 'Norway'],
        ];

        foreach ($expectedDefaults as $countryCode => [$city, $country]) {
            $settings = SearchManager::$plugin->getSettings();
            $settings->defaultCountry = $countryCode;
            $settings->defaultCity = $city;

            $location = SearchManager::$plugin->analytics->getLocationFromIp('192.168.1.42');

            self::assertIsArray($location, $countryCode . '/' . $city . ' should resolve to default geo metadata.');
            self::assertSame($countryCode, $location['countryCode']);
            self::assertSame($country, $location['country']);
            self::assertSame($city, $location['city']);
        }
    }

    public function testPrivateIpHasNoGeoLocationForUnsupportedDefaults(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultCountry = 'ZZ';
        $settings->defaultCity = 'Missing City';

        $logger = Craft::getLogger();
        $before = count($logger->messages);

        self::assertNull(
            SearchManager::$plugin->analytics->getLocationFromIp('10.0.0.10'),
            'Unsupported local/private IP geo defaults should leave geo fields empty instead of falling back to Dubai.',
        );

        $messages = array_slice($logger->messages, $before);
        $warnings = array_filter($messages, static function(array $message): bool {
            return ($message[1] ?? null) === Logger::LEVEL_WARNING
                && ($message[2] ?? null) === SearchManager::$plugin->id
                && str_contains((string)($message[0] ?? ''), 'Configured default analytics location was not found')
                && str_contains((string)($message[0] ?? ''), 'leaving local/private IP geo fields empty')
                && str_contains((string)($message[0] ?? ''), '"configuredCountry":"ZZ"')
                && str_contains((string)($message[0] ?? ''), '"configuredCity":"Missing City"');
        });

        self::assertNotEmpty($warnings, 'Unsupported configured default location should emit a diagnostic warning.');
    }

    public function testGeoLookupJobLeavesGeoFieldsNullWithoutExplicitDefaults(): void
    {
        $this->withoutDefaultLocationEnv(function(): void {
            $settings = SearchManager::$plugin->getSettings();
            $settings->defaultCountry = null;
            $settings->defaultCity = null;
            $analyticsId = $this->seedAnalyticsRow();

            (new GeoLookupJob([
                'analyticsId' => $analyticsId,
                'ip' => '127.0.0.1',
            ]))->execute(null);

            $row = (new \craft\db\Query())
                ->select(['country', 'city', 'region', 'latitude', 'longitude'])
                ->from('{{%searchmanager_analytics}}')
                ->where(['id' => $analyticsId])
                ->one();

            self::assertIsArray($row);
            self::assertNull($row['country']);
            self::assertNull($row['city']);
            self::assertNull($row['region']);
            self::assertNull($row['latitude']);
            self::assertNull($row['longitude']);
        });
    }

    public function testDefaultLocationUsesResolvedSettingsOnly(): void
    {
        $source = $this->readPluginFile('src/services/analytics/AnalyticsBreakdownService.php');
        $method = $this->methodBody($source, 'getDefaultLocation', 'private');

        self::assertStringContainsString('$defaultCountry = $settings->defaultCountry;', $method);
        self::assertStringContainsString('$defaultCity = $settings->defaultCity;', $method);
        self::assertStringNotContainsString('App::env(', $method);
        self::assertStringNotContainsString('SEARCH_MANAGER_DEFAULT_COUNTRY', $method);
        self::assertStringNotContainsString('SEARCH_MANAGER_DEFAULT_CITY', $method);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withoutDefaultLocationEnv(callable $callback): mixed
    {
        $countryServer = $_SERVER['SEARCH_MANAGER_DEFAULT_COUNTRY'] ?? null;
        $cityServer = $_SERVER['SEARCH_MANAGER_DEFAULT_CITY'] ?? null;
        $countryEnv = $_ENV['SEARCH_MANAGER_DEFAULT_COUNTRY'] ?? null;
        $cityEnv = $_ENV['SEARCH_MANAGER_DEFAULT_CITY'] ?? null;
        $countryEffective = App::env('SEARCH_MANAGER_DEFAULT_COUNTRY');
        $cityEffective = App::env('SEARCH_MANAGER_DEFAULT_CITY');

        unset(
            $_SERVER['SEARCH_MANAGER_DEFAULT_COUNTRY'],
            $_SERVER['SEARCH_MANAGER_DEFAULT_CITY'],
            $_ENV['SEARCH_MANAGER_DEFAULT_COUNTRY'],
            $_ENV['SEARCH_MANAGER_DEFAULT_CITY'],
        );
        putenv('SEARCH_MANAGER_DEFAULT_COUNTRY');
        putenv('SEARCH_MANAGER_DEFAULT_CITY');

        try {
            return $callback();
        } finally {
            $this->restoreEnvValue('SEARCH_MANAGER_DEFAULT_COUNTRY', $countryServer, $countryEnv, $countryEffective);
            $this->restoreEnvValue('SEARCH_MANAGER_DEFAULT_CITY', $cityServer, $cityEnv, $cityEffective);
        }
    }

    private function restoreEnvValue(string $name, ?string $serverValue, ?string $envValue, mixed $effectiveValue): void
    {
        if ($serverValue !== null) {
            $_SERVER[$name] = $serverValue;
        }

        if ($envValue !== null) {
            $_ENV[$name] = $envValue;
        }

        if (is_string($effectiveValue)) {
            putenv($name . '=' . $effectiveValue);
        } else {
            putenv($name);
        }
    }

    private function seedAnalyticsRow(): int
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => '__sm_geo_defaults',
            'normalizedQuery' => '__sm_geo_defaults',
            'resultsCount' => 1,
            'executionTime' => 1.0,
            'backend' => self::TEST_BACKEND,
            'siteId' => self::TEST_SITE_ID,
            'sessionId' => null,
            'source' => 'test',
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['backend' => self::TEST_BACKEND])
            ->execute();
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $methodName, string $visibility): string
    {
        $signature = $visibility . ' function ' . $methodName . '(';
        $start = strpos($source, $signature);
        $this->assertIsInt($start);

        $brace = strpos($source, '{', $start);
        $this->assertIsInt($brace);

        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        self::fail('Unable to extract method body for ' . $methodName);
    }
}
