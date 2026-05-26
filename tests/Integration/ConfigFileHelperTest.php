<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use ReflectionClass;

/**
 * Pins Search Manager's wrapper around the shared base config helper.
 *
 * @since 5.47.0
 */
final class ConfigFileHelperTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        BaseConfigFileHelper::clearCache();
        parent::tearDown();
    }

    public function testSearchConfigSectionsDelegateToBaseHelper(): void
    {
        $this->seedBaseConfigCache([
            'search-manager' => [
                'backends' => [
                    'primary' => ['type' => 'algolia'],
                ],
                'indices' => [
                    'content' => ['elementType' => 'craft\\elements\\Entry'],
                ],
                'widgets' => [
                    'site-search' => ['type' => 'modal'],
                ],
                'widgetStyles' => [
                    'compact' => ['type' => 'modal'],
                ],
            ],
            'sms-manager' => [
                'providers' => [
                    'primary' => ['type' => 'stub'],
                ],
            ],
        ]);

        self::assertSame(['primary' => ['type' => 'algolia']], ConfigFileHelper::getConfiguredBackends());
        self::assertSame(['content' => ['elementType' => 'craft\\elements\\Entry']], ConfigFileHelper::getIndices());
        self::assertSame(['site-search' => ['type' => 'modal']], ConfigFileHelper::getWidgetConfigs());
        self::assertSame(['compact' => ['type' => 'modal']], ConfigFileHelper::getWidgetStyles());
        self::assertSame(['primary'], ConfigFileHelper::getHandles('backends'));
        self::assertSame(['type' => 'algolia'], ConfigFileHelper::getConfigByHandle('backends', 'primary'));
        self::assertTrue(ConfigFileHelper::handleExistsInConfig('backends', 'primary'));
        self::assertFalse(ConfigFileHelper::handleExistsInConfig('backends', 'missing'));
    }

    public function testClearCacheOnlyClearsSearchManagerConfig(): void
    {
        $this->seedBaseConfigCache([
            'search-manager' => [
                'backends' => [
                    'primary' => ['type' => 'algolia'],
                ],
            ],
            'sms-manager' => [
                'providers' => [
                    'primary' => ['type' => 'stub'],
                ],
            ],
        ]);

        ConfigFileHelper::clearCache();

        self::assertSame([
            'sms-manager' => [
                'providers' => [
                    'primary' => ['type' => 'stub'],
                ],
            ],
        ], $this->getBaseConfigCache());
    }

    /**
     * @param array<string, array> $config
     */
    private function seedBaseConfigCache(array $config): void
    {
        $reflection = new ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setValue(null, $config);
    }

    /**
     * @return array<string, array>
     */
    private function getBaseConfigCache(): array
    {
        $reflection = new ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        return $property->getValue();
    }
}
