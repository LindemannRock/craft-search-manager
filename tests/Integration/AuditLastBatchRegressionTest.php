<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;

/**
 * Regression coverage for audit #221, #239, and #240.
 */
final class AuditLastBatchRegressionTest extends TestCase
{
    public function testAutocompleteResultDedupKeepsFirstResultPerSiteElementAndType(): void
    {
        $results = [
            ['siteId' => 1, 'id' => 10, 'type' => 'entry', 'text' => 'First'],
            ['siteId' => 1, 'id' => 10, 'type' => 'entry', 'text' => 'Duplicate'],
            ['siteId' => 2, 'id' => 10, 'type' => 'entry', 'text' => 'Other Site'],
            ['siteId' => 1, 'id' => 10, 'type' => 'asset', 'text' => 'Other Type'],
        ];

        self::assertSame(
            [$results[0], $results[2], $results[3]],
            $this->callApiDedupe($results),
        );
        self::assertSame(
            [$results[0], $results[2], $results[3]],
            $this->callGqlDedupe($results),
        );
    }

    public function testAutocompleteServicePreservesSuggestionSiteId(): void
    {
        $source = $this->readPluginFile('src/services/AutocompleteService.php');

        self::assertStringContainsString("'siteId' => isset(\$suggestion['siteId']) ? (int)\$suggestion['siteId'] : null", $source);
    }

    public function testAutoTransformerDoesNotSingularizeUsIsOrOsWords(): void
    {
        $transformer = new AutoTransformer();
        $method = new \ReflectionMethod($transformer, 'singularize');
        $method->setAccessible(true);

        self::assertSame('news', $method->invoke($transformer, 'news'));
        self::assertSame('status', $method->invoke($transformer, 'status'));
        self::assertSame('analysis', $method->invoke($transformer, 'analysis'));
        self::assertSame('logos', $method->invoke($transformer, 'logos'));
        self::assertSame('product', $method->invoke($transformer, 'products'));
    }

    public function testAutoTransformerUsesSubclassAwareFieldTypeChecks(): void
    {
        $source = $this->readPluginFile('src/transformers/AutoTransformer.php');

        self::assertStringContainsString('is_a($field, \'craft\\ckeditor\\Field\')', $source);
        self::assertStringContainsString('is_a($field, \'craft\\redactor\\Field\')', $source);
        self::assertStringNotContainsString('$fieldClass === \'craft\\ckeditor\\Field\'', $source);
        self::assertStringNotContainsString('$fieldClass === \'craft\\redactor\\Field\'', $source);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function callApiDedupe(array $results): array
    {
        $controller = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ApiController::class, 'dedupeAutocompleteResults');
        $method->setAccessible(true);

        return $method->invoke($controller, $results);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function callGqlDedupe(array $results): array
    {
        $method = new \ReflectionMethod(SearchResolver::class, 'dedupeAutocompleteResults');
        $method->setAccessible(true);

        return $method->invoke(null, $results);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
