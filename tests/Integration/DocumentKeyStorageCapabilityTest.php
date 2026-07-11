<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.54.0
 */
final class DocumentKeyStorageCapabilityTest extends TestCase
{
    public function testSplitSectionsValidationRejectsBackendWithoutDocumentKeys(): void
    {
        $index = new SearchIndex();
        $index->name = 'Docs';
        $index->handle = 'docs';
        $index->elementType = 'lindemannrock\\docsmanager\\elements\\SourceDoc';
        $index->backend = '__custom_local_without_document_keys__';
        $index->splitSections = true;

        $index->validateSplitSectionsStorage('splitSections');

        self::assertSame([
            'Split Sections requires a backend that supports document keys.',
        ], $index->getErrors('splitSections'));
    }

    public function testKnownBackendsAdvertiseDocumentKeyCapability(): void
    {
        foreach (['mysql', 'pgsql', 'redis', 'file', 'algolia', 'meilisearch', 'typesense'] as $backendType) {
            self::assertTrue(SearchIndex::backendTypeSupportsDocumentKeys($backendType), $backendType);
        }

        self::assertFalse(SearchIndex::backendTypeSupportsDocumentKeys('__custom__'));
        self::assertFalse(SearchIndex::backendTypeSupportsDocumentKeys(null));
    }

    public function testIdentityPathsDoNotUseMethodExistsDocumentKeyFallbacks(): void
    {
        $files = [
            'src/search/SearchEngine.php',
            'src/backends/AbstractSearchEngineBackend.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertIsString($source);
            self::assertStringNotContainsString('method_exists', $source, $file);
        }
    }
}
