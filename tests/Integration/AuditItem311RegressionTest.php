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
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit finding #311.
 *
 * @since 5.53.0
 */
final class AuditItem311RegressionTest extends TestCase
{
    private const PREFIX = 'sm-audit-311-';

    private string $originalTimezone = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        if ($this->originalTimezone !== '') {
            date_default_timezone_set($this->originalTimezone);
        }
        parent::tearDown();
    }

    public function testWidgetConfigDatesHydrateAsUtc(): void
    {
        $handle = self::PREFIX . 'config';
        $this->insertWidgetConfig($handle, '2026-07-12 08:30:00', '2026-07-12 09:45:00');

        $config = SearchManager::$plugin->widgetConfigs->getByHandle($handle);

        self::assertNotNull($config);
        self::assertSame('UTC', $config->dateCreated?->getTimezone()->getName());
        self::assertSame('UTC', $config->dateUpdated?->getTimezone()->getName());
        self::assertSame('2026-07-12 08:30:00', $config->dateCreated?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-12 09:45:00', $config->dateUpdated?->format('Y-m-d H:i:s'));
    }

    public function testWidgetStyleDatesHydrateAsUtc(): void
    {
        $handle = self::PREFIX . 'style';
        $this->insertWidgetStyle($handle, '2026-07-12 10:15:00', '2026-07-12 11:20:00');

        $style = SearchManager::$plugin->widgetStyles->getByHandle($handle);

        self::assertNotNull($style);
        self::assertSame('UTC', $style->dateCreated?->getTimezone()->getName());
        self::assertSame('UTC', $style->dateUpdated?->getTimezone()->getName());
        self::assertSame('2026-07-12 10:15:00', $style->dateCreated?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-12 11:20:00', $style->dateUpdated?->format('Y-m-d H:i:s'));
    }

    private function insertWidgetConfig(string $handle, string $dateCreated, string $dateUpdated): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $handle,
            'name' => 'Audit 311 Widget',
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $dateCreated,
            'dateUpdated' => $dateUpdated,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function insertWidgetStyle(string $handle, string $dateCreated, string $dateUpdated): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => $handle,
            'name' => 'Audit 311 Style',
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => 1,
            'dateCreated' => $dateCreated,
            'dateUpdated' => $dateUpdated,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function deleteTestRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
