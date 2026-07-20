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

    public function testConsoleRequestUsesExceptionGateForStandard(): void
    {
        $this->withEdition(SearchManager::EDITION_STANDARD, function(): void {
            $this->expectException(ForbiddenHttpException::class);
            SearchManager::$plugin->requireProOrPrompt('Analytics');
        });
    }

    public function testProGatePassesWithoutResponse(): void
    {
        $this->withEdition(SearchManager::EDITION_PRO, function(): void {
            self::assertNull(SearchManager::$plugin->requireProOrPrompt('Analytics'));
        });
    }

    public function testUpgradePromptRendersFeatureAndPluginStoreLink(): void
    {
        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_partials/upgrade-prompt',
            ['featureName' => 'Analytics'],
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
        return $this->withEdition($edition, function(): array {
            $event = new RegisterUserPermissionsEvent();
            Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

            foreach ($event->permissions as $group) {
                if (isset($group['permissions']['searchManager:manageBackends'])) {
                    return $this->flattenPermissionHandles($group['permissions']);
                }
            }

            self::fail('Search Manager permission group was not registered.');
        });
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
