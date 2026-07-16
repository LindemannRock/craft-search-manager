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
use craft\elements\User;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #386.
 *
 * The delete-guard "in use by" messages leaked entity names across independent
 * permission groups: a caller holding only a delete permission (e.g.
 * `deleteIndices`) saw widget and API-key NAMES they had no view permission
 * for. The fix tags every usage with its owning permission group and
 * {@see \lindemannrock\searchmanager\services\DependencyService::formatInUseError()}
 * shows names only for kinds the current user can view, falling back to a
 * per-kind count ("2 widgets") otherwise.
 *
 * @since 5.53.0
 */
final class AuditItem386UsageDisclosureTest extends TestCase
{
    private const VIEW_INDICES = 'searchManager:manageIndices';
    private const VIEW_API_KEYS = 'searchManager:manageApiKeys';
    private const VIEW_WIDGETS = 'searchManager:manageWidgetConfigs';
    private const KEY_PREFIX = '__sm_audit386_test__';

    private ?User $originalIdentity = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIdentity = Craft::$app->getUser()->getIdentity();
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity($this->originalIdentity);
        $this->purgeTestKeys();
        parent::tearDown();
    }

    // ---- Usage metadata: every getter tags its permission group --------------

    public function testBackendUsagesCarryIndexKind(): void
    {
        $index = $this->fakeIndex('audit-386-index-1', 'Audit 386 Index');
        $index->backend = 'audit-386-backend';

        $usages = $this->withOnlySearchIndices(
            [$index],
            fn(): array => SearchManager::$plugin->dependencies->getBackendUsages('audit-386-backend'),
        );

        self::assertCount(1, $usages);
        self::assertSame('index', $usages[0]['kind']);
        self::assertSame('Audit 386 Index', $usages[0]['label']);
    }

    public function testIndexUsagesCarryWidgetAndApiKeyKinds(): void
    {
        $this->installWidgetConfigStub([$this->fakeWidgetConfig('Audit 386 Widget', ['audit-386-index-1'])]);
        $this->seedApiKey('audit-386-index-1');

        $usages = SearchManager::$plugin->dependencies->getIndexUsages('audit-386-index-1');

        $kinds = array_column($usages, 'kind');
        self::assertContains('widget', $kinds);
        self::assertContains('apiKey', $kinds);
    }

    public function testApiKeyUsagesCarryWidgetKind(): void
    {
        $this->installWidgetConfigStub([$this->fakeWidgetConfig('Audit 386 Widget', [])]);

        $usages = SearchManager::$plugin->dependencies->getApiKeyUsages('any-key-handle');

        self::assertCount(1, $usages);
        self::assertSame('widget', $usages[0]['kind']);
    }

    public function testStyleUsagesCarryWidgetKind(): void
    {
        $config = $this->fakeWidgetConfig('Audit 386 Widget', []);
        $config->styleHandle = 'audit-386-style';
        $this->installWidgetConfigStub([$config]);

        $usages = SearchManager::$plugin->dependencies->getStyleUsages('audit-386-style');

        self::assertCount(1, $usages);
        self::assertSame('widget', $usages[0]['kind']);
    }

    // ---- formatInUseError(): names for viewable kinds, counts otherwise ------

    public function testFormatShowsNamesForViewableKinds(): void
    {
        $this->actAs([self::VIEW_INDICES, self::VIEW_API_KEYS, self::VIEW_WIDGETS]);

        $message = SearchManager::$plugin->dependencies->formatInUseError('Products', [
            ['type' => 'Widget', 'label' => 'Header Search', 'kind' => 'widget'],
            ['type' => 'API key', 'label' => 'Mobile App', 'kind' => 'apiKey'],
        ]);

        self::assertSame(
            'Cannot delete “Products” — it is in use by: Widget: Header Search, API key: Mobile App.',
            $message,
        );
    }

    public function testFormatReplacesUnviewableNamesWithCounts(): void
    {
        $this->actAs([self::VIEW_INDICES]);

        $message = SearchManager::$plugin->dependencies->formatInUseError('Products', [
            ['type' => 'Index', 'label' => 'Blog', 'kind' => 'index'],
            ['type' => 'Widget', 'label' => 'Header Search', 'kind' => 'widget'],
            ['type' => 'Widget', 'label' => 'Footer Search', 'kind' => 'widget'],
            ['type' => 'API key', 'label' => 'Mobile App', 'kind' => 'apiKey'],
        ]);

        self::assertSame(
            'Cannot delete “Products” — it is in use by: Index: Blog, 2 widgets, 1 API key.',
            $message,
        );
        self::assertStringNotContainsString('Header Search', $message);
        self::assertStringNotContainsString('Footer Search', $message);
        self::assertStringNotContainsString('Mobile App', $message);
    }

    public function testGuestSeesOnlyCounts(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        $message = SearchManager::$plugin->dependencies->formatInUseError('Products', [
            ['type' => 'Widget', 'label' => 'Header Search', 'kind' => 'widget'],
            ['type' => 'Index', 'label' => 'Blog', 'kind' => 'index'],
        ]);

        self::assertSame(
            'Cannot delete “Products” — it is in use by: 1 widget, 1 index.',
            $message,
        );
        self::assertStringNotContainsString('Header Search', $message);
        self::assertStringNotContainsString('Blog', $message);
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * @param list<string> $permissions
     */
    private function actAs(array $permissions): void
    {
        $identity = new class extends User {
            /** @var list<string> */
            public array $grantedPermissions = [];

            public function can(string $permission): bool
            {
                return in_array($permission, $this->grantedPermissions, true);
            }
        };
        $identity->grantedPermissions = $permissions;
        Craft::$app->getUser()->setIdentity($identity);
    }

    private function fakeIndex(string $handle, string $name): SearchIndex
    {
        return new SearchIndex([
            'name' => $name,
            'handle' => $handle,
            'elementType' => Entry::class,
            'enabled' => true,
        ]);
    }

    /**
     * @param list<string> $indexHandles
     */
    private function fakeWidgetConfig(string $name, array $indexHandles): WidgetConfig
    {
        $config = new class extends WidgetConfig {
            /** @var list<string> */
            public array $fakeIndexHandles = [];

            public function getIndexHandles(): array
            {
                return $this->fakeIndexHandles;
            }
        };
        $config->name = $name;
        $config->fakeIndexHandles = $indexHandles;

        return $config;
    }

    /**
     * @param list<WidgetConfig> $configs
     */
    private function installWidgetConfigStub(array $configs): void
    {
        $stub = new class extends WidgetConfigService {
            /** @var list<WidgetConfig> */
            public array $fakeConfigs = [];

            public function getAll(bool $enabledOnly = false): array
            {
                return $this->fakeConfigs;
            }

            public function findConfigsUsingApiKeyHandle(string $handle): array
            {
                return $this->fakeConfigs;
            }
        };
        $stub->fakeConfigs = $configs;
        $this->swapPluginComponent('search-manager', 'widgetConfigs', $stub);
    }

    private function seedApiKey(string $allowedIndexHandle): void
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = self::KEY_PREFIX . 'key';
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [$allowedIndexHandle];
        $key->allowedReferrers = [];
        self::assertTrue($key->save(), 'Seeded key save() must succeed');
    }

    private function purgeTestKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::KEY_PREFIX . '%', false])
            ->execute();
    }
}
