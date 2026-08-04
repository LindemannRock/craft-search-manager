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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #453 and #456.
 *
 * @since 5.54.0
 */
final class WidgetStyleValidationRoutingTest extends TestCase
{
    private const BACKEND_HANDLE = '__sm_batch9_backend';
    private const INDEX_HANDLE = 'sm-batch9-index';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseBackendAndIndex();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRows();
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testWidgetStyleValidationErrorsRouteEveryNonGeneralTab(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/widgets/styles/edit.twig');
        self::assertIsString($source);

        foreach ([
            "'styles.modal'" => "'modal'",
            "'styles.backdrop'" => "'modal'",
            "'styles.header'" => "'modal'",
            "'styles.footer'" => "'modal'",
            "'styles.input'" => "'input'",
            "'styles.result'" => "'results'",
            "'styles.promoted'" => "'results'",
            "'styles.highlight'" => "'results'",
            "'styles.trigger'" => "'controls'",
            "'styles.kbd'" => "'controls'",
        ] as $prefix => $tab) {
            self::assertStringContainsString('k starts with ' . $prefix, $source);
            self::assertStringContainsString('{% set selectedTab = ' . $tab . ' %}', $source);
        }

        foreach (['general', 'modal', 'input', 'results', 'controls'] as $tab) {
            self::assertStringContainsString(
                '<div id="' . $tab . '"{% if selectedTab != \'' . $tab . '\' %} class="hidden"{% endif %}>',
                $source,
            );
        }
    }

    private function insertDatabaseBackendAndIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Database Fallback Backend',
            'handle' => self::BACKEND_HANDLE,
            'backendType' => 'file',
            'settings' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 9 Index',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => self::BACKEND_HANDLE,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => self::BACKEND_HANDLE])
            ->execute();
        SearchIndex::clearCache();
    }
}
