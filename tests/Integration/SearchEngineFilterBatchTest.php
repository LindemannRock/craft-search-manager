<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins batching for local search filters that operate after scoring.
 *
 * @since 5.53.0
 */
final class SearchEngineFilterBatchTest extends TestCase
{
    private const SITE_ID = 1;

    private function makeStorage(): RecordingStorage
    {
        return new RecordingStorage(
            termDocs: [
                'protein' => ['1:1' => 3, '1:2' => 3, '1:3' => 3],
            ],
            titleByElement: [
                1 => ['protein'],
                2 => ['shake'],
                3 => ['protein'],
            ],
            docLengths: [
                '1:1' => 10,
                '1:2' => 10,
                '1:3' => 10,
            ],
            totalDocs: 3,
            avgDocLength: 10.0,
            documentLanguagesById: [
                '1:1' => 'en',
                '1:2' => 'de',
                '1:3' => 'en',
            ],
            documentTermsById: [
                '1:1' => ['protein' => 1],
                '1:2' => ['protein' => 1],
                '1:3' => ['protein' => 1],
            ],
        );
    }

    public function testTitleFieldFilterUsesTitleTermsBatch(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('protein title:protein', self::SITE_ID);

        $this->assertSame(2, $storage->getTitleTermsBatchCalls);
        $this->assertSame(0, $storage->getTitleTermsCalls);
        $this->assertSame([3, 3], $storage->getTitleTermsBatchSizes);
        $this->assertSame([1, 3], array_keys($results));
    }

    public function testLanguageFilterUsesDocumentLanguagesBatch(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('protein', self::SITE_ID, 0, ['language' => 'en']);

        $this->assertSame(1, $storage->getDocumentLanguagesBatchCalls);
        $this->assertSame(0, $storage->getDocumentLanguageCalls);
        $this->assertSame([3], $storage->getDocumentLanguagesBatchSizes);
        $this->assertSame([1, 3], array_keys($results));
    }

    public function testContentFieldFilterUsesTitleAndDocumentTermBatches(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('content:protein', self::SITE_ID);

        $this->assertSame(0, $storage->getTitleTermsCalls);
        $this->assertSame([3, 3], $storage->getTitleTermsBatchSizes);
        $this->assertSame(0, $storage->getDocumentTermsCalls);
        $this->assertSame(1, $storage->getDocumentTermsBatchCalls);
        $this->assertSame([3], $storage->getDocumentTermsBatchSizes);
        $this->assertSame([2], array_keys($results));
    }
}
