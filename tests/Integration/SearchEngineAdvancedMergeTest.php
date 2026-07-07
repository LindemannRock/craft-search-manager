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
 * @since 5.53.0
 */
final class SearchEngineAdvancedMergeTest extends TestCase
{
    private const SITE_ID = 1;

    public function testAndQueryWithUnmatchedPhraseDoesNotFallBackToTermResults(): void
    {
        $engine = new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'alpha' => ['1:1' => 1],
                    'beta' => ['1:1' => 1],
                    'keyword' => ['1:1' => 1],
                ],
                titleByElement: [],
                docLengths: ['1:1' => 4],
                totalDocs: 1,
                avgDocLength: 4.0,
                elementsById: [
                    1 => [
                        'title' => 'Alpha keyword',
                        'documentData' => ['content' => 'Alpha keyword beta'],
                    ],
                ],
            ),
            'test-index',
        );

        self::assertSame([], $engine->search('"alpha beta" keyword', self::SITE_ID));
    }
}
