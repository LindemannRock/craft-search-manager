<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the query_by / query_by_weights pairing contract for Typesense.
 *
 * Typesense rejects a search outright when the weight count doesn't match the
 * query_by field count ("Number of weights in query_by_weights does not match
 * number of query_by fields"). Autocomplete overrode query_by to two fields
 * while search() independently defaulted query_by_weights to the four-field
 * weights — every autocomplete request 400'd inside the swallow-and-log path,
 * so Typesense suggestions were silently empty (live-reproduced against a
 * real Typesense server).
 */
final class TypesenseQueryByWeightsTest extends TestCase
{
    public function testDefaultQueryByAndWeightsHaveMatchingFieldCounts(): void
    {
        $fields = explode(',', SearchRecordProjectionHelper::typesenseQueryBy());
        $weights = explode(',', SearchRecordProjectionHelper::typesenseQueryByWeights());

        self::assertSame(count($fields), count($weights), 'Default query_by and query_by_weights must stay in lockstep.');
    }

    public function testSearchOnlyAppliesDefaultWeightsWithDefaultQueryBy(): void
    {
        $body = $this->methodBody($this->readPluginSource('src/backends/TypesenseBackend.php'), 'search');

        // Defaults must travel together: weights only default inside the
        // same branch that defaults query_by.
        self::assertStringContainsString("if (!isset(\$searchParams['query_by'])) {", $body);
        self::assertStringNotContainsString(
            "\$searchParams['query_by'] = \$searchParams['query_by'] ??",
            $body,
            'query_by must not default independently of query_by_weights.'
        );
    }

    public function testAutocompletePairsWeightsWithItsQueryByOverride(): void
    {
        $body = $this->methodBody($this->readPluginSource('src/backends/TypesenseBackend.php'), 'autocomplete');

        self::assertStringContainsString("'query_by' => 'title,content',", $body);
        self::assertStringContainsString("'query_by_weights' => '3,1',", $body);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'public'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        self::assertNotEmpty($matches, sprintf('Could not find %s::%s body.', $visibility, $method));

        return $matches[0];
    }
}
