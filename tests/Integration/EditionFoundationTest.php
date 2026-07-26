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
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\View;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Event;
use yii\web\ForbiddenHttpException;

/**
 * @since 5.54.0
 */
final class EditionFoundationTest extends TestCase
{
    private const PRO_PERMISSION_HANDLES = [
        'searchManager:viewAnalytics',
        'searchManager:manageQueryRules',
        'searchManager:createQueryRules',
        'searchManager:editQueryRules',
        'searchManager:deleteQueryRules',
        'searchManager:managePromotions',
        'searchManager:createPromotions',
        'searchManager:editPromotions',
        'searchManager:deletePromotions',
        'searchManager:managePendingSyncs',
        'searchManager:retryPendingSyncs',
        'searchManager:purgePendingSyncs',
        'searchManager:manageWidgetStyles',
        'searchManager:createWidgetStyles',
        'searchManager:editWidgetStyles',
        'searchManager:deleteWidgetStyles',
    ];

    private const STANDARD_ANALYTICS_CONTROL_HANDLES = [
        'searchManager:exportAnalytics',
        'searchManager:clearAnalytics',
    ];

    public function testEditionsAreRegisteredInAscendingOrder(): void
    {
        self::assertSame([
            SearchManager::EDITION_STANDARD,
            SearchManager::EDITION_PRO,
        ], SearchManager::editions());
    }

    public function testEditionFeaturesMatchThePublishedSplit(): void
    {
        $standard = SearchManager::$plugin->getEditionFeatures(SearchManager::EDITION_STANDARD);
        $pro = SearchManager::$plugin->getEditionFeatures(SearchManager::EDITION_PRO);

        self::assertCount(15, $standard);
        self::assertCount(15, $pro);
        self::assertCount(7, array_filter($standard));
        self::assertCount(15, array_filter($pro));

        self::assertTrue($standard[Craft::t('search-manager', 'Local and external backends')]);
        self::assertTrue($standard[Craft::t('search-manager', 'Privacy controls, analytics-data export, and permanent purge')]);
        self::assertFalse($standard[Craft::t('search-manager', 'Eight-tab analytics workspace and exports')]);
        self::assertTrue($pro[Craft::t('search-manager', 'Eight-tab analytics workspace and exports')]);
    }

    public function testStandardOmitsProPermissionsButKeepsAnalyticsDataControls(): void
    {
        $permissions = $this->registeredPermissionHandles(SearchManager::EDITION_STANDARD);

        foreach (self::PRO_PERMISSION_HANDLES as $permission) {
            self::assertNotContains($permission, $permissions);
        }

        foreach (self::STANDARD_ANALYTICS_CONTROL_HANDLES as $permission) {
            self::assertContains($permission, $permissions);
        }
    }

    public function testProRegistersProPermissionsAndAnalyticsDataControls(): void
    {
        $permissions = $this->registeredPermissionHandles(SearchManager::EDITION_PRO);

        foreach ([...self::PRO_PERMISSION_HANDLES, ...self::STANDARD_ANALYTICS_CONTROL_HANDLES] as $permission) {
            self::assertContains($permission, $permissions);
        }
    }

    public function testPr142StandardPermissionOrderAndNestingAreCanonical(): void
    {
        $permissions = $this->registeredPermissions(SearchManager::EDITION_STANDARD);

        self::assertSame([
            'searchManager:manageBackends',
            'searchManager:manageIndices',
            'searchManager:manageApiKeys',
            'searchManager:manageWidgetConfigs',
            'searchManager:viewDebug',
            'searchManager:exportAnalytics',
            'searchManager:clearAnalytics',
            'searchManager:clearCache',
            'searchManager:viewLogs',
            'searchManager:manageSettings',
        ], array_keys($permissions));
        self::assertSame([
            'searchManager:manageBackends',
            'searchManager:createBackends',
            'searchManager:editBackends',
            'searchManager:deleteBackends',
            'searchManager:manageIndices',
            'searchManager:createIndices',
            'searchManager:editIndices',
            'searchManager:deleteIndices',
            'searchManager:rebuildIndices',
            'searchManager:clearIndices',
            'searchManager:manageApiKeys',
            'searchManager:createApiKeys',
            'searchManager:editApiKeys',
            'searchManager:revokeApiKeys',
            'searchManager:manageWidgetConfigs',
            'searchManager:createWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:deleteWidgetConfigs',
            'searchManager:viewDebug',
            'searchManager:exportAnalytics',
            'searchManager:clearAnalytics',
            'searchManager:clearCache',
            'searchManager:viewLogs',
            'searchManager:viewSystemLogs',
            'searchManager:downloadSystemLogs',
            'searchManager:manageSettings',
        ], $this->flattenPermissionHandles($permissions));
        $this->assertCanonicalNestedFamilies($permissions, false);
    }

    public function testPr142ProPermissionOrderAndNestingAreCanonical(): void
    {
        $permissions = $this->registeredPermissions(SearchManager::EDITION_PRO);

        self::assertSame([
            'searchManager:manageBackends',
            'searchManager:manageIndices',
            'searchManager:managePendingSyncs',
            'searchManager:managePromotions',
            'searchManager:manageQueryRules',
            'searchManager:manageApiKeys',
            'searchManager:manageWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:viewDebug',
            'searchManager:viewAnalytics',
            'searchManager:exportAnalytics',
            'searchManager:clearAnalytics',
            'searchManager:clearCache',
            'searchManager:viewLogs',
            'searchManager:manageSettings',
        ], array_keys($permissions));
        self::assertSame([
            'searchManager:manageBackends',
            'searchManager:createBackends',
            'searchManager:editBackends',
            'searchManager:deleteBackends',
            'searchManager:manageIndices',
            'searchManager:createIndices',
            'searchManager:editIndices',
            'searchManager:deleteIndices',
            'searchManager:rebuildIndices',
            'searchManager:clearIndices',
            'searchManager:managePendingSyncs',
            'searchManager:retryPendingSyncs',
            'searchManager:purgePendingSyncs',
            'searchManager:managePromotions',
            'searchManager:createPromotions',
            'searchManager:editPromotions',
            'searchManager:deletePromotions',
            'searchManager:manageQueryRules',
            'searchManager:createQueryRules',
            'searchManager:editQueryRules',
            'searchManager:deleteQueryRules',
            'searchManager:manageApiKeys',
            'searchManager:createApiKeys',
            'searchManager:editApiKeys',
            'searchManager:revokeApiKeys',
            'searchManager:manageWidgetConfigs',
            'searchManager:createWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:deleteWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:createWidgetStyles',
            'searchManager:editWidgetStyles',
            'searchManager:deleteWidgetStyles',
            'searchManager:viewDebug',
            'searchManager:viewAnalytics',
            'searchManager:exportAnalytics',
            'searchManager:clearAnalytics',
            'searchManager:clearCache',
            'searchManager:viewLogs',
            'searchManager:viewSystemLogs',
            'searchManager:downloadSystemLogs',
            'searchManager:manageSettings',
        ], $this->flattenPermissionHandles($permissions));
        $this->assertCanonicalNestedFamilies($permissions, true);
    }

    public function testConsoleRequestUsesExceptionGateForStandard(): void
    {
        $this->withEdition(SearchManager::EDITION_STANDARD, function(): void {
            $this->expectException(ForbiddenHttpException::class);
            SearchManager::$plugin->requireEditionOrPrompt(SearchManager::EDITION_PRO, 'Analytics');
        });
    }

    public function testProGatePassesWithoutResponse(): void
    {
        $this->withEdition(SearchManager::EDITION_PRO, function(): void {
            self::assertNull(SearchManager::$plugin->requireEditionOrPrompt(SearchManager::EDITION_PRO, 'Analytics'));
        });
    }

    public function testUpgradePromptRendersFeatureAndPluginStoreLink(): void
    {
        $html = Craft::$app->getView()->renderTemplate(
            'lindemannrock-base/_partials/edition-upgrade-prompt',
            [
                'plugin' => SearchManager::$plugin,
                'edition' => SearchManager::EDITION_PRO,
                'featureName' => 'Analytics',
                'pitch' => Craft::t('search-manager', 'Search Manager Pro adds analytics, query rules, promotions, pending-sync operations, and reusable widget style presets.'),
            ],
            View::TEMPLATE_MODE_CP,
        );

        self::assertStringContainsString('Analytics requires Search Manager Pro', $html);
        self::assertStringContainsString('plugin-store/search-manager', $html);
        self::assertStringContainsString('View Search Manager Pro in the Plugin Store', $html);
    }

    /**
     * @return list<string>
     */
    private function registeredPermissionHandles(string $edition): array
    {
        return $this->flattenPermissionHandles($this->registeredPermissions($edition));
    }

    /**
     * @return array<string, array{nested?: array}>
     */
    private function registeredPermissions(string $edition): array
    {
        return $this->withEdition($edition, function(): array {
            $event = new RegisterUserPermissionsEvent();
            Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

            foreach ($event->permissions as $group) {
                if (isset($group['permissions']['searchManager:manageBackends'])) {
                    return $group['permissions'];
                }
            }

            self::fail('Search Manager permission group was not registered.');
        });
    }

    /**
     * @param array<string, array{nested?: array}> $permissions
     */
    private function assertCanonicalNestedFamilies(array $permissions, bool $pro): void
    {
        $expected = [
            'searchManager:manageBackends' => [
                'searchManager:createBackends',
                'searchManager:editBackends',
                'searchManager:deleteBackends',
            ],
            'searchManager:manageIndices' => [
                'searchManager:createIndices',
                'searchManager:editIndices',
                'searchManager:deleteIndices',
                'searchManager:rebuildIndices',
                'searchManager:clearIndices',
            ],
            'searchManager:manageApiKeys' => [
                'searchManager:createApiKeys',
                'searchManager:editApiKeys',
                'searchManager:revokeApiKeys',
            ],
            'searchManager:manageWidgetConfigs' => [
                'searchManager:createWidgetConfigs',
                'searchManager:editWidgetConfigs',
                'searchManager:deleteWidgetConfigs',
            ],
            'searchManager:viewLogs' => [
                'searchManager:viewSystemLogs',
            ],
        ];
        if ($pro) {
            $expected += [
                'searchManager:managePendingSyncs' => [
                    'searchManager:retryPendingSyncs',
                    'searchManager:purgePendingSyncs',
                ],
                'searchManager:managePromotions' => [
                    'searchManager:createPromotions',
                    'searchManager:editPromotions',
                    'searchManager:deletePromotions',
                ],
                'searchManager:manageQueryRules' => [
                    'searchManager:createQueryRules',
                    'searchManager:editQueryRules',
                    'searchManager:deleteQueryRules',
                ],
                'searchManager:manageWidgetStyles' => [
                    'searchManager:createWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:deleteWidgetStyles',
                ],
            ];
        }

        foreach ($expected as $parent => $children) {
            self::assertSame($children, array_keys($permissions[$parent]['nested'] ?? []), $parent);
        }
        self::assertSame(
            ['searchManager:downloadSystemLogs'],
            array_keys($permissions['searchManager:viewLogs']['nested']['searchManager:viewSystemLogs']['nested'] ?? []),
        );
    }

    /**
     * @param array<string, array{nested?: array}> $permissions
     * @return list<string>
     */
    private function flattenPermissionHandles(array $permissions): array
    {
        $handles = [];

        foreach ($permissions as $handle => $definition) {
            $handles[] = $handle;

            if (!empty($definition['nested'])) {
                array_push($handles, ...$this->flattenPermissionHandles($definition['nested']));
            }
        }

        return $handles;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withEdition(string $edition, callable $callback): mixed
    {
        $originalEdition = SearchManager::$plugin->edition;
        SearchManager::$plugin->edition = $edition;

        try {
            return $callback();
        } finally {
            SearchManager::$plugin->edition = $originalEdition;
        }
    }
}
