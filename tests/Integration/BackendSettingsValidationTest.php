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
use craft\db\Query;
use lindemannrock\searchmanager\models\BackendSettings;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins legacy backend settings validation on save.
 *
 * @since 5.53.0
 */
final class BackendSettingsValidationTest extends TestCase
{
    private const TABLE = '{{%searchmanager_backend_settings}}';

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLegacyTable();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();

        if ($this->createdTable) {
            Craft::$app->getDb()->createCommand()->dropTable(self::TABLE)->execute();
        }

        parent::tearDown();
    }

    public function testInvalidDisabledBackendSettingsFailToSave(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = false;
        $settings->config = [
            'applicationId' => '',
            'adminApiKey' => '',
        ];

        self::assertFalse($settings->save());
        self::assertSame(['Application ID cannot be blank.'], $settings->getErrors('applicationId'));
        self::assertSame(['Admin API Key cannot be blank.'], $settings->getErrors('apiKey'));
        self::assertSame(0, $this->countBackendSettingsRows());
    }

    public function testInvalidEnabledBackendSettingsFailToSave(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = true;
        $settings->config = [
            'applicationId' => '',
            'adminApiKey' => '',
        ];

        self::assertFalse($settings->save());
        self::assertSame(['Application ID cannot be blank.'], $settings->getErrors('applicationId'));
        self::assertSame(['Admin API Key cannot be blank.'], $settings->getErrors('apiKey'));
        self::assertSame(0, $this->countBackendSettingsRows());
    }

    public function testValidDisabledBackendSettingsStillSave(): void
    {
        $settings = new BackendSettings();
        $settings->backend = 'algolia';
        $settings->enabled = false;
        $settings->config = [
            'applicationId' => 'test-application-id',
            'adminApiKey' => 'test-admin-api-key',
        ];

        self::assertTrue($settings->save());
        self::assertFalse($settings->hasErrors());

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['backend' => 'algolia'])
            ->one();

        self::assertIsArray($row);
        self::assertSame(0, (int)$row['enabled']);
        self::assertSame($settings->config, json_decode((string)$row['config'], true));
    }

    private function ensureLegacyTable(): void
    {
        $db = Craft::$app->getDb();

        if ($db->tableExists(self::TABLE)) {
            return;
        }

        $db->createCommand()->createTable(self::TABLE, [
            'id' => 'pk',
            'backend' => 'varchar(50) NOT NULL',
            'enabled' => 'boolean NOT NULL DEFAULT 0',
            'config' => 'text NULL',
            'dateCreated' => 'datetime NOT NULL',
            'dateUpdated' => 'datetime NOT NULL',
            'uid' => 'varchar(36) NOT NULL',
        ])->execute();

        $db->createCommand()->createIndex('idx_searchmanager_backend_settings_backend', self::TABLE, ['backend'], true)->execute();
        $this->createdTable = true;
    }

    private function deleteTestRows(): void
    {
        if (!Craft::$app->getDb()->tableExists(self::TABLE)) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete(self::TABLE, ['backend' => ['algolia']])
            ->execute();
    }

    private function countBackendSettingsRows(): int
    {
        return (int)(new Query())
            ->from(self::TABLE)
            ->where(['backend' => 'algolia'])
            ->count();
    }
}
