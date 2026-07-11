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
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the split stored-record shape used by local and external backends.
 *
 * @since 5.53.0
 */
final class SearchRecordProjectionTest extends TestCase
{
    private const INDEX_HANDLE = 'test_projection_retrievable_exclusions';

    protected function tearDown(): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);
        parent::tearDown();
    }

    public function testExternalProjectionKeepsDedicatedBodyAndPrivateSnippetFields(): void
    {
        $record = SearchRecordProjectionHelper::externalRecord('missing-index', [
            'id' => 1087,
            'elementId' => 1087,
            'backendId' => '1087_1',
            'siteId' => 1,
            'title' => 'Projection Title',
            'description' => 'Projection description',
            'content' => 'Projection Title Projection description field-only-token',
            '_bodyClean' => 'body-only-token without title seams',
            '_fields' => [
                'summary' => 'field-only-token',
                'body' => 'body text should only be body source',
            ],
            'excerpt' => 'Old excerpt',
            'sectionBody' => 'Old section body',
        ]);

        self::assertSame('Projection Title Projection description field-only-token', $record['content'] ?? null);
        self::assertSame('body-only-token without title seams', $record['_bodyClean'] ?? null);
        self::assertSame([
            'summary' => 'field-only-token',
            'body' => 'body text should only be body source',
        ], $record['_snippetFields'] ?? null);
        self::assertSame([
            'summary' => 'field-only-token',
            'body' => 'body text should only be body source',
        ], $record['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $record);
        self::assertArrayNotHasKey('excerpt', $record);
        self::assertArrayNotHasKey('sectionBody', $record);
        self::assertStringNotContainsString('body-only-token', (string)($record['content'] ?? ''));
    }

    public function testLocalMatchingTextIncludesBodyCleanOutsideContent(): void
    {
        $matchingText = SearchRecordProjectionHelper::localMatchingText([
            'content' => 'title-only-token field-only-token',
            '_bodyClean' => 'body-only-token',
        ]);

        self::assertStringContainsString('title-only-token', $matchingText);
        self::assertStringContainsString('field-only-token', $matchingText);
        self::assertStringContainsString('body-only-token', $matchingText);
    }

    public function testStoredProjectionHonorsWildcardExclusions(): void
    {
        $this->saveTestIndex(['*', '-wysiwyg']);

        $record = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, [
            'id' => 1087,
            'elementId' => 1087,
            'backendId' => '1087_1',
            'siteId' => 1,
            'title' => 'Projection Title',
            'content' => 'Projection Title searchable-body',
            '_fields' => [
                'intro' => 'Intro field value',
                'wysiwyg' => 'Wysiwyg field value',
                'price' => '10.00',
            ],
        ]);

        self::assertSame([
            'intro' => 'Intro field value',
            'price' => '10.00',
        ], $record['fields'] ?? null);
        self::assertSame([
            'intro' => 'Intro field value',
            'wysiwyg' => 'Wysiwyg field value',
            'price' => '10.00',
        ], $record['_snippetFields'] ?? null);
    }

    public function testSplitSectionProjectionDropsPageLevelCodeBody(): void
    {
        $record = SearchRecordProjectionHelper::externalRecord('missing-index', [
            'id' => 10,
            'elementId' => 10,
            'backendId' => '10_1_install',
            'siteId' => 1,
            'title' => 'Install',
            'content' => 'Install',
            'sectionType' => 'heading',
            '_bodyClean' => 'Install the package.',
            '_bodyWithCode' => 'Full page code body that belongs to other sections.',
            '_sectionBodyWithCode' => 'Install the package. composer require vendor/package',
        ]);

        self::assertArrayNotHasKey('_bodyWithCode', $record);
        self::assertSame('Install the package.', $record['_bodyClean'] ?? null);
        self::assertSame('Install the package. composer require vendor/package', $record['_sectionBodyWithCode'] ?? null);
    }

    public function testProviderMatchingAndProjectionConfigurationIsExplicit(): void
    {
        self::assertStringContainsString(
            "['title', 'content', '_bodyClean', 'url']",
            $this->readPluginSource('src/backends/AlgoliaBackend.php'),
        );
        self::assertStringContainsString(
            "['title', 'content', '_bodyClean', 'url']",
            $this->readPluginSource('src/backends/MeilisearchBackend.php'),
        );
        self::assertStringContainsString(
            "'query_by' => 'title,content,_bodyClean,url'",
            $this->readPluginSource('src/backends/TypesenseBackend.php'),
        );
        self::assertStringContainsString(
            "'query_by_weights' => '5,3,1,1'",
            $this->readPluginSource('src/backends/TypesenseBackend.php'),
        );
    }

    private function readPluginSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath;

        return (string)file_get_contents($path);
    }

    /**
     * @param list<string> $retrievableFields
     */
    private function saveTestIndex(array $retrievableFields): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Test Projection Retrievable Exclusions',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => json_encode([2, 3], JSON_THROW_ON_ERROR),
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(SearchIndex::normalizeRetrievableFields($retrievableFields), JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
    }

    private function deleteTestIndexByHandle(string $handle): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => $handle])
            ->execute();
        SearchIndex::clearCache();
    }
}
