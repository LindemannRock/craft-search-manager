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
final class FileStorageTermIdentityTest extends TestCase
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

        parent::tearDown();
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

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['backend' => self::TEST_BACKEND])
            ->execute();
    }

    private function makeFileStorage(): FileStorage
    {
        if ($this->fileStorageBasePath !== null) {
            throw new \LogicException('This test already owns a File storage root.');
        }
        $path = $this->createOwnedStorageDirectory('audit-final-file-storage');
        $this->fileStorageBasePath = $path;

        return new FileStorage('audit-final-batch', $this->fileStorageBasePath);
    }
}
