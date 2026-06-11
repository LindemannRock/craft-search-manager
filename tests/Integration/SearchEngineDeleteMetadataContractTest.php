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
 * Pins the shared local-engine delete metadata contract.
 *
 * @since 5.47.0
 */
final class SearchEngineDeleteMetadataContractTest extends TestCase
{
    public function testMissingDocumentDeleteDoesNotTouchMetadata(): void
    {
        $storage = new RecordingStorage([], [], [], 1, 1.0);
        $engine = new SearchEngine($storage, 'test_delete_metadata_contract');

        self::assertTrue($engine->deleteDocument(1, 999999));

        self::assertSame(0, $storage->updateMetadataCalls);
        self::assertSame([], $storage->updateMetadataEvents);
    }

    public function testExistingDocumentDeleteSubtractsMetadataOnce(): void
    {
        $storage = new RecordingStorage(
            [],
            [],
            [],
            1,
            3.0,
            [],
            ['1:100001' => ['alpha' => 1, 'beta' => 1]],
            ['1:100001' => 3],
        );
        $engine = new SearchEngine($storage, 'test_delete_metadata_contract');

        self::assertTrue($engine->deleteDocument(1, 100001));

        self::assertSame(1, $storage->updateMetadataCalls);
        self::assertSame([
            [
                'siteId' => 1,
                'docLength' => 3,
                'isAddition' => false,
            ],
        ], $storage->updateMetadataEvents);
    }
}
