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
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Covers Search Manager's DB-backed handle uniqueness policies.
 *
 * @since 5.47.0
 */
final class HandleUniquenessTest extends TestCase
{
    private string $prefix = 'sm-handle-helper-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        parent::tearDown();
    }

    public function testBackendDuplicateHandleValidationUsesSharedHelper(): void
    {
        $existingId = $this->insertBackend($this->prefix . '-backend');

        $duplicate = new ConfiguredBackend();
        $duplicate->name = 'Duplicate Backend';
        $duplicate->handle = $this->prefix . '-backend';
        $duplicate->backendType = 'redis';

        self::assertFalse($duplicate->validate(['handle']));
        self::assertSame(['Handle must be unique.'], $duplicate->getErrors('handle'));

        $existing = new ConfiguredBackend();
        $existing->id = $existingId;
        $existing->name = 'Existing Backend';
        $existing->handle = $this->prefix . '-backend';
        $existing->backendType = 'redis';

        self::assertTrue($existing->validate(['handle']));
    }

    public function testIndexDuplicateHandleValidationRunsBeforeDbUniqueIndex(): void
    {
        $this->insertIndex($this->prefix . '-index');

        $duplicate = new SearchIndex();
        $duplicate->name = 'Duplicate Index';
        $duplicate->handle = $this->prefix . '-index';
        $duplicate->elementType = Entry::class;

        self::assertFalse($duplicate->validate(['handle']));
        self::assertSame(['Handle must be unique.'], $duplicate->getErrors('handle'));
    }

    public function testWidgetConfigDuplicateHandleValidationRunsBeforeDbUniqueIndex(): void
    {
        $this->insertWidgetConfig($this->prefix . '-widget');

        $duplicate = new WidgetConfig();
        $duplicate->name = 'Duplicate Widget';
        $duplicate->handle = $this->prefix . '-widget';
        $duplicate->settings = WidgetConfig::defaultSettings();

        self::assertFalse($duplicate->validate(['handle']));
        self::assertSame(['Handle must be unique.'], $duplicate->getErrors('handle'));
    }

    public function testApiKeyDuplicateHandleValidationRunsBeforeDbUniqueIndex(): void
    {
        $existingId = $this->insertApiKey($this->prefix . '-api-key');

        $duplicate = new ApiKey();
        $duplicate->name = 'Duplicate API Key';
        $duplicate->handle = $this->prefix . '-api-key';
        $duplicate->type = ApiKey::TYPE_PUBLIC;

        self::assertFalse($duplicate->validate(['handle']));
        self::assertSame(['Handle must be unique.'], $duplicate->getErrors('handle'));

        $existing = new ApiKey();
        $existing->id = $existingId;
        $existing->name = 'Existing API Key';
        $existing->handle = $this->prefix . '-api-key';
        $existing->type = ApiKey::TYPE_PUBLIC;

        self::assertTrue($existing->validate(['handle']));
    }


    public function testWidgetStyleKeepsAutoSuffixPolicyThroughSharedHelper(): void
    {
        $this->insertWidgetStyle($this->prefix . '-style');

        $style = new WidgetStyle();
        $style->name = 'Duplicate Style';
        $style->handle = $this->prefix . '-style';
        $style->type = 'modal';
        $style->styles = WidgetConfig::defaultStyleValues();

        self::assertTrue(SearchManager::$plugin->widgetStyles->save($style));
        self::assertSame($this->prefix . '-style-1', $style->handle);
    }

    public function testWidgetStyleEditRejectsDuplicateHandle(): void
    {
        $this->insertWidgetStyle($this->prefix . '-style');
        $existingId = $this->insertWidgetStyle($this->prefix . '-style-2');

        $style = new WidgetStyle();
        $style->id = $existingId;
        $style->name = 'Existing Style';
        $style->handle = $this->prefix . '-style';
        $style->type = 'modal';
        $style->styles = WidgetConfig::defaultStyleValues();

        self::assertFalse(SearchManager::$plugin->widgetStyles->save($style));
        self::assertSame(['Handle must be unique.'], $style->getErrors('handle'));
    }

    private function insertBackend(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Test Backend',
            'handle' => $handle,
            'backendType' => 'redis',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertIndex(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Test Index',
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'source' => 'database',
            'backend' => null,
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertWidgetConfig(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $handle,
            'name' => 'Test Widget',
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertWidgetStyle(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => $handle,
            'name' => 'Test Style',
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertApiKey(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_api_keys}}', [
            'name' => 'Test API Key',
            'handle' => $handle,
            'type' => ApiKey::TYPE_PUBLIC,
            'enabled' => 1,
            'keyHash' => str_repeat('a', 64),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_pub_' . substr(md5($handle), 0, 8),
            'allowedIndices' => '["*"]',
            'allowedReferrers' => '[]',
            'maxHitsPerPage' => null,
            'validUntil' => null,
            'rateLimit' => null,
            'lastUsedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function deleteTestRows(): void
    {
        foreach ([
            '{{%searchmanager_backends}}',
            '{{%searchmanager_indices}}',
            '{{%searchmanager_widget_configs}}',
            '{{%searchmanager_widget_styles}}',
            '{{%searchmanager_api_keys}}',
        ] as $table) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete($table, ['like', 'handle', $this->prefix . '%', false])
                ->execute();
        }
    }
}
