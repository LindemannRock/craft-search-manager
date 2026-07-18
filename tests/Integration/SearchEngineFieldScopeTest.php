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
 * Regression coverage for local-engine title/content pseudo-field scopes.
 *
 * @since 5.54.0
 */
final class SearchEngineFieldScopeTest extends TestCase
{
    private const SITE_ID = 1;

    public function testContentScopeExcludesTitleOnlyOccurrenceAndKeepsBodyOccurrence(): void
    {
        $results = $this->makeEngine()->search('content:topic', self::SITE_ID);

        self::assertSame([2], array_keys($results));
    }

    public function testTitleScopeBehaviorRemainsUnchanged(): void
    {
        $results = $this->makeEngine()->search('title:topic', self::SITE_ID);

        self::assertSame([1], array_keys($results));
    }

    public function testCombinedTitleAndContentScopesRequireBothFields(): void
    {
        $results = $this->makeEngine()->search('title:alpha content:beta', self::SITE_ID);

        self::assertSame([3], array_keys($results));
    }

    private function makeEngine(): SearchEngine
    {
        $storage = new RecordingStorage(
            termDocs: [
                'topic' => ['1:1' => 1, '1:2' => 1],
                'alpha' => ['1:3' => 1, '1:4' => 1],
                'beta' => ['1:3' => 1, '1:4' => 1],
            ],
            titleByElement: [
                1 => ['topic'],
                2 => ['guide'],
                3 => ['alpha'],
                4 => ['alpha', 'beta'],
            ],
            docLengths: [
                '1:1' => 1,
                '1:2' => 1,
                '1:3' => 2,
                '1:4' => 2,
            ],
            totalDocs: 4,
            avgDocLength: 1.5,
            documentTermsById: [
                '1:1' => ['topic' => 1],
                '1:2' => ['topic' => 1],
                '1:3' => ['alpha' => 1, 'beta' => 1],
                '1:4' => ['alpha' => 1, 'beta' => 1],
            ],
        );

        return new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);
    }
}
