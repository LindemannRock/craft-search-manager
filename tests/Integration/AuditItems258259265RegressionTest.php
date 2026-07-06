<?php
/**
 * LindemannRock Search Manager
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
 * Focused regression coverage for audit #258, #259, and #265.
 */
final class AuditItems258259265RegressionTest extends TestCase
{
    public function testPhraseVerificationMatchesHtmlEntityAndUnicodeSpacesBetweenTerms(): void
    {
        $storage = new RecordingStorage(
            [
                'alpha' => ['1:101' => 1, '1:102' => 1, '1:103' => 1],
                'beta' => ['1:101' => 1, '1:102' => 1, '1:103' => 1],
            ],
            [],
            [
                '1:101' => 2,
                '1:102' => 2,
                '1:103' => 2,
            ],
            3,
            2.0,
            elementsById: [
                101 => [
                    'title' => 'Entity space',
                    'documentData' => ['content' => 'alpha&nbsp;beta'],
                ],
                102 => [
                    'title' => 'No-break space',
                    'documentData' => ['content' => "alpha\u{00A0}beta"],
                ],
                103 => [
                    'title' => 'Ideographic space',
                    'documentData' => ['content' => "alpha\u{3000}beta"],
                ],
            ],
        );
        $engine = new SearchEngine($storage, 'audit-259', ['enableStopWords' => false]);

        $results = $engine->search('"alpha beta"', 1);

        self::assertArrayHasKey(101, $results, 'HTML entity non-breaking spaces should verify as phrase whitespace.');
        self::assertArrayHasKey(102, $results, 'Literal U+00A0 should verify as phrase whitespace.');
        self::assertArrayHasKey(103, $results, 'U+3000 should verify as phrase whitespace.');
        self::assertSame(1, $storage->getElementsByIdsCalls);
    }

    public function testPhraseVerificationPreservesEntityEncodedAngleBrackets(): void
    {
        $storage = new RecordingStorage(
            [
                'foo' => ['1:201' => 1],
                'bar' => ['1:201' => 1],
                'baz' => ['1:201' => 1],
            ],
            [],
            ['1:201' => 3],
            1,
            3.0,
            elementsById: [
                201 => [
                    'title' => 'Encoded angle brackets',
                    'documentData' => ['content' => 'foo &lt; bar &gt; baz'],
                ],
            ],
        );
        $engine = new SearchEngine($storage, 'audit-259-angles', ['enableStopWords' => false]);

        $results = $engine->search('"foo < bar > baz"', 1);

        self::assertArrayHasKey(201, $results, 'Entity-encoded angle brackets should survive tag stripping and phrase normalization.');
    }
}
