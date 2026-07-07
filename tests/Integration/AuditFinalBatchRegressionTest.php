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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;

/**
 * Focused regressions for audit #141, #142, and #143.
 *
 * @since 5.53.0
 */
final class AuditFinalBatchRegressionTest extends TestCase
{
    private const TEST_SITE_ID = 999996;
    private const OTHER_SITE_ID = 999995;
    private const TEST_BACKEND = 'test-audit-final-batch';

    private ?string $fileStorageBasePath = null;
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

        if ($this->fileStorageBasePath !== null) {
            $this->deleteDirectory($this->fileStorageBasePath);
        }

        parent::tearDown();
    }

    public function testUniqueQueriesCountIncludesNonFrontendSources(): void
    {
        $this->seedAnalyticsRow('frontend query', 'frontend');
        $this->seedAnalyticsRow('api query', 'api');
        $this->seedAnalyticsRow('cp query', 'cp');
        $this->seedAnalyticsRow('api query', 'frontend');
        $this->seedAnalyticsRow('old api query', 'api', self::TEST_SITE_ID, new \DateTime('-40 days'));
        $this->seedAnalyticsRow('other site query', 'api', self::OTHER_SITE_ID);

        self::assertSame(3, SearchManager::$plugin->analytics->getUniqueQueriesCount(self::TEST_SITE_ID, 30));
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

    public function testFileStorageTermFilenameExtractionRoundTripsUnderscoreTerms(): void
    {
        $storage = $this->makeFileStorage();

        $storage->storeTermDocument('foo_bar_baz', self::TEST_SITE_ID, 123, 1);
        $storage->storeTermDocument('foo_other', self::TEST_SITE_ID, 124, 1);
        $storage->storeTermDocument('other_term', self::TEST_SITE_ID, 125, 1);
        $storage->storeTermNgrams('alpha_beta_gamma', ['al', 'lp', 'ph'], self::TEST_SITE_ID);

        $prefixTerms = $storage->getTermsByPrefix('foo_', self::TEST_SITE_ID);
        sort($prefixTerms);

        self::assertSame(['foo_bar_baz', 'foo_other'], $prefixTerms);
        self::assertArrayHasKey(
            'alpha_beta_gamma',
            $storage->getTermsByNgramSimilarity(['al', 'lp', 'ph'], self::TEST_SITE_ID, 1.0),
        );
    }

    private function seedAnalyticsRow(
        string $query,
        string $source,
        int $siteId = self::TEST_SITE_ID,
        ?\DateTimeInterface $dateCreated = null,
    ): void {
        $dateCreated ??= new \DateTime();

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => $query,
            'normalizedQuery' => QueryNormalizer::forCacheIdentity($query),
            'resultsCount' => 1,
            'executionTime' => 1.0,
            'backend' => self::TEST_BACKEND,
            'siteId' => $siteId,
            'sessionId' => null,
            'source' => $source,
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb($dateCreated),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['backend' => self::TEST_BACKEND])
            ->execute();
    }

    private function makeFileStorage(): FileStorage
    {
        $this->fileStorageBasePath = Craft::getAlias('@storage/search-manager-test-' . StringHelper::UUID());

        return new FileStorage('audit-final-batch', $this->fileStorageBasePath);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
