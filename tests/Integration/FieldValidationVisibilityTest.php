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
 * Focused regression coverage for audit findings #447-#452.
 *
 * @since 5.54.0
 */
final class FieldValidationVisibilityTest extends TestCase
{
    private const DATABASE_INDEX_HANDLE = '__sm_audit_batch8_database';
    private const CONFIG_INDEX_HANDLE = '__sm_audit_batch8_config';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseIndex();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();

        parent::tearDown();
    }

    public function testFieldSweepGapBindingsHaveInlineErrors(): void
    {
        $inventories = [
            'src/templates/settings/general.twig' => [
                "settings.getErrors('requireApiKey')",
            ],
            'src/templates/settings/indexing.twig' => [
                "settings.getErrors('autoIndex')",
            ],
            'src/templates/settings/analytics.twig' => [
                "settings.getErrors('enableAnalytics')",
                "settings.getErrors('enableGeoDetection')",
                "settings.getErrors('anonymizeIpAddress')",
            ],
            'src/templates/settings/cache.twig' => [
                "settings.getErrors('enableCache')",
                "settings.getErrors('enableAutocompleteCache')",
                "settings.getErrors('clearCacheOnSave')",
                "settings.getErrors('enableCacheWarming')",
                "settings.getErrors('cacheDeviceDetection')",
            ],
            'src/templates/settings/highlighting.twig' => [
                "settings.getErrors('highlightResultsEnabled')",
            ],
            'src/templates/settings/autocomplete.twig' => [
                "settings.getErrors('enableAutocomplete')",
            ],
            'src/templates/settings/search.twig' => [
                "settings.getErrors('enableFuzzy')",
                "settings.getErrors('replaceNativeSearch')",
            ],
            'src/templates/settings/language.twig' => [
                "settings.getErrors('enableStopWords')",
            ],
            'src/templates/widgets/edit.twig' => [
                "widgetConfig.getErrors('enabled')",
            ],
            'src/templates/widgets/_partials/recently-viewed.twig' => [
                "widgetConfig.getErrors('settings.behavior.recentlyViewedEnabled')",
            ],
            'src/templates/widgets/_partials/snippets.twig' => [
                "widgetConfig.getErrors('settings.behavior.snippetIncludeCodeBlocks')",
                "widgetConfig.getErrors('settings.behavior.snippetCleanMarkdown')",
            ],
            'src/templates/widgets/_partials/modal-trigger.twig' => [
                "widgetConfig.getErrors('settings.behavior.modalPreventBodyScroll')",
                "widgetConfig.getErrors('settings.behavior.loadingIndicatorEnabled')",
                "widgetConfig.getErrors('settings.trigger.triggerEnabled')",
            ],
            'src/templates/widgets/_partials/destination-highlighting.twig' => [
                "widgetConfig.getErrors('settings.behavior.highlightDestinationEnabled')",
                "widgetConfig.getErrors('settings.behavior.highlightDestinationPersistQuery')",
            ],
            'src/templates/widgets/_partials/results.twig' => [
                "widgetConfig.getErrors('settings.behavior.resultsRequireUrl')",
                "widgetConfig.getErrors('settings.behavior.resultsGroupingEnabled')",
            ],
            'src/templates/widgets/styles/edit.twig' => [
                "widgetStyle.getErrors('enabled')",
            ],
            'src/templates/widgets/styles/_partials/modal.twig' => [
                "widgetStyle.getErrors('styles.backdropBlur')",
            ],
            'src/templates/widgets/styles/_partials/results.twig' => [
                "widgetStyle.getErrors('styles.highlightTag')",
                "widgetStyle.getErrors('styles.highlightClass')",
            ],
        ];

        foreach ($inventories as $relativePath => $errorBindings) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
            self::assertIsString($source, $relativePath);

            foreach ($errorBindings as $errorBinding) {
                self::assertStringContainsString($errorBinding, $source, $relativePath);
            }
        }
    }

    private function insertDatabaseIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 8 Database Index',
            'handle' => self::DATABASE_INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 9,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => [
                self::DATABASE_INDEX_HANDLE,
                self::CONFIG_INDEX_HANDLE,
            ]])
            ->execute();
        SearchIndex::clearCache();
    }
}
