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
 * Guards audit #293 local-backend creation reporting.
 *
 * @since 5.53.0
 */
final class AuditItem293RegressionTest extends TestCase
{
    public function testLocalBackendGetsCreationResultFromLockedSearchEnginePath(): void
    {
        $source = $this->readPluginSource('src/backends/AbstractSearchEngineBackend.php');
        $body = $this->methodBody($source, 'indexWithResult', 'public');

        self::assertStringContainsString('->indexDocumentWithResult($siteId, $elementId, $title, $content)', $body);
        self::assertStringNotContainsString('getDocumentTerms($siteId, $elementId)', $body);
        self::assertStringNotContainsString('$existed', $body);
    }

    public function testSearchEngineChecksCreationInsideTheMutexedReplacementPath(): void
    {
        $source = $this->readPluginSource('src/search/SearchEngine.php');
        $body = $this->methodBody($source, 'indexDocumentWithResult', 'public');

        $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
        $termsPosition = strpos($body, '$oldTerms = $this->storage->getDocumentTerms($siteId, $elementId);');
        $createdPosition = strpos($body, '$wasCreated = $oldDocLength <= 0 && empty($oldTerms);');

        self::assertIsInt($lockPosition);
        self::assertIsInt($termsPosition);
        self::assertIsInt($createdPosition);
        self::assertLessThan($termsPosition, $lockPosition);
        self::assertLessThan($createdPosition, $termsPosition);
    }

    public function testTwoSameElementIndexingOperationsCannotBothReportCreated(): void
    {
        $storage = new RecordingStorage([], [], [], 0, 0.0);
        $engine = new SearchEngine($storage, 'audit_293_same_element');

        $first = $engine->indexDocumentWithResult(1, 293001, 'First title', 'alpha beta');
        $second = $engine->indexDocumentWithResult(1, 293001, 'Second title', 'alpha beta gamma');

        self::assertSame(['success' => true, 'wasCreated' => true], $first);
        self::assertSame(['success' => true, 'wasCreated' => false], $second);
    }

    public function testReindexingExistingLocalBackendDocumentReportsNotCreated(): void
    {
        $storage = new RecordingStorage(
            [],
            [],
            [],
            1,
            2.0,
            [],
            ['1:293002' => ['alpha' => 1]],
            ['1:293002' => 2],
        );
        $engine = new SearchEngine($storage, 'audit_293_existing_document');

        $result = $engine->indexDocumentWithResult(1, 293002, 'Updated title', 'alpha gamma');

        self::assertSame(['success' => true, 'wasCreated' => false], $result);
    }

    public function testExternalPreflightBackendsMutexExistenceAndWriteTogether(): void
    {
        foreach ([
            'src/backends/AlgoliaBackend.php' => 'algoliaObjectExists',
            'src/backends/MeilisearchBackend.php' => 'meilisearchDocumentExists',
        ] as $relativePath => $existsMethod) {
            $source = $this->readPluginSource($relativePath);
            $body = $this->methodBody($source, 'indexWithResult', 'public');

            $lockPosition = strpos($body, 'getMutex()->acquire($lockName, 30)');
            $existsPosition = strpos($body, '$existed = $this->' . $existsMethod);
            $releasePosition = strpos($body, 'getMutex()->release($lockName)');

            self::assertIsInt($lockPosition, $relativePath);
            self::assertIsInt($existsPosition, $relativePath);
            self::assertIsInt($releasePosition, $relativePath);
            self::assertLessThan($existsPosition, $lockPosition, $relativePath);
            self::assertLessThan($releasePosition, $existsPosition, $relativePath);
        }
    }

    public function testTypesenseUsesAtomicCreateForCreationDecision(): void
    {
        $source = $this->readPluginSource('src/backends/TypesenseBackend.php');
        $body = $this->methodBody($source, 'indexWithResult', 'public');

        $createPosition = strpos($body, '->documents->create($data)');
        $createdPosition = strpos($body, '$wasCreated = true;');
        $upsertPosition = strpos($body, '->documents->upsert($data)');

        self::assertIsInt($createPosition);
        self::assertIsInt($createdPosition);
        self::assertIsInt($upsertPosition);
        self::assertLessThan($createdPosition, $createPosition);
        self::assertLessThan($upsertPosition, $createdPosition);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'private'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
