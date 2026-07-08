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
use craft\helpers\Db;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ApiKeyService;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetsController::class)]
#[CoversClass(ApiKeysController::class)]
#[CoversClass(WidgetConfig::class)]
#[CoversClass(ApiKeyService::class)]
#[CoversClass(WidgetConfigService::class)]
final class Audit305WidgetConfigTest extends TestCase
{
    private const PREFIX = 'smaudit305';
    private const MARKER = '__sm_audit305__';

    private ?object $originalRequest = null;

    private ?object $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
        BaseConfigFileHelper::clearCache('search-manager');
        SearchIndex::clearCache();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        BaseConfigFileHelper::clearCache('search-manager');
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testSettingsFilterPreservesListDefaultsAndStripsUnknownAssociativeKeys(): void
    {
        $defaults = WidgetConfig::defaultSettings();
        $data = array_replace_recursive($defaults, [
            'unknownRoot' => 'drop-me',
            'apiKeyHandle' => 'widget-public',
            'search' => [
                'indexHandles' => ['docs', 'shortlinks'],
                'unknownSearch' => 'drop-me',
            ],
            'behavior' => [
                'maxResults' => '20',
                'unknownBehavior' => 'drop-me',
            ],
        ]);

        $filtered = $this->filterSettings($data, $defaults);

        self::assertSame(['docs', 'shortlinks'], $filtered['search']['indexHandles']);
        self::assertSame('widget-public', $filtered['apiKeyHandle']);
        self::assertSame('20', $filtered['behavior']['maxResults']);
        self::assertArrayNotHasKey('unknownRoot', $filtered);
        self::assertArrayNotHasKey('unknownSearch', $filtered['search']);
        self::assertArrayNotHasKey('unknownBehavior', $filtered['behavior']);
    }

    public function testWidgetConfigSavePersistsSelectedIndexHandlesAndApiKeyHandle(): void
    {
        $docs = $this->seedIndex('docs');
        $shortlinks = $this->seedIndex('shortlinks');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs, $shortlinks]);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;
        $settings['search']['indexHandles'] = [$docs, $shortlinks];

        $widget = $this->makeWidget('persist');
        $widget->settings = $settings;

        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($widget), print_r($widget->getErrors(), true));

        $row = $this->fetchRow('{{%searchmanager_widget_configs}}', ['id' => $widget->id]);
        self::assertNotNull($row);

        $stored = json_decode((string)$row['settings'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($key->handle, $stored['apiKeyHandle']);
        self::assertArrayNotHasKey('apiKeyId', $stored);
        self::assertSame([$docs, $shortlinks], $stored['search']['indexHandles']);
    }

    public function testApiKeyCreateNormalizesAndGeneratesUniqueHandleFromName(): void
    {
        $name = self::MARKER . 'Duplicate handle source';
        $expectedHandle = SlugHandleHelper::normalizeSlug('', $name);

        $this->createApiKeyFromName($name);
        $this->createApiKeyFromName($name);

        $handles = (new \craft\db\Query())
            ->select(['handle'])
            ->from('{{%searchmanager_api_keys}}')
            ->where(['name' => $name])
            ->orderBy(['id' => SORT_ASC])
            ->column();

        self::assertSame([$expectedHandle, $expectedHandle . '-1'], $handles);
    }

    public function testFreshInstallApiKeySchemaIncludesHandleAfterNameAndUniqueIndex(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/migrations/Install.php');
        self::assertIsString($source);

        self::assertLessThan(strpos($source, "'handle' => \$this->string(255)->notNull(),"), strpos($source, "'name' => \$this->string(255)->notNull(),"));
        self::assertLessThan(strpos($source, "'type' => \$this->enum('type'"), strpos($source, "'handle' => \$this->string(255)->notNull(),"));
        self::assertStringContainsString("\$this->createIndex('searchmanager_api_keys_handle_unq', '{{%searchmanager_api_keys}}', ['handle'], true);", $source);
    }

    public function testWidgetSelectorListsOnlyWidgetUsablePublicKeysWithPrefixLabels(): void
    {
        [$usablePublic] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES], encrypted: true, name: 'Another one');
        $this->seedApiKey(ApiKey::TYPE_SERVER, [ApiKey::ALL_INDICES], encrypted: false, name: 'Server key');
        $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES], encrypted: false, name: 'Direct public without encrypted material');

        $keys = array_values(array_filter(
            SearchManager::$plugin->apiKeys->widgetUsablePublicKeys(),
            static fn(ApiKey $key): bool => str_starts_with($key->name, self::MARKER),
        ));

        self::assertCount(1, $keys);
        self::assertSame($usablePublic->id, $keys[0]->id);
        self::assertSame($usablePublic->name . ' (' . $usablePublic->handle . ') — ' . $usablePublic->keyPrefix . '...', SearchManager::$plugin->apiKeys->widgetKeyLabel($usablePublic));
    }

    public function testWidgetValidationRejectsIndicesOutsideSelectedKeyScope(): void
    {
        $docs = $this->seedIndex('docs');
        $secret = $this->seedIndex('secret');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs]);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;
        $settings['search']['indexHandles'] = [$secret];

        $widget = $this->makeWidget('scope-reject');
        $widget->settings = $settings;

        self::assertFalse($widget->validate(['settings']));
        self::assertSame(
            ['Selected indices must be allowed by the selected API key.'],
            $widget->getErrors('settings.search.indexHandles'),
        );
    }

    public function testWidgetValidationAllowsEmptyIndexHandlesWithRestrictedKey(): void
    {
        $docs = $this->seedIndex('docs');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs]);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;
        $settings['search']['indexHandles'] = [];

        $widget = $this->makeWidget('scope-empty');
        $widget->settings = $settings;

        self::assertTrue($widget->validate(['settings']), print_r($widget->getErrors(), true));
    }

    public function testWidgetSettingsRenderFiltersIndexOptionsForRestrictedSelectedKey(): void
    {
        $docs = $this->seedIndex('docs');
        $secret = $this->seedIndex('secret');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs]);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;

        $widget = $this->makeWidget('filtered-options');
        $widget->settings = $settings;

        $docsIndex = SearchIndex::findByHandle($docs);
        $secretIndex = SearchIndex::findByHandle($secret);
        self::assertNotNull($docsIndex);
        self::assertNotNull($secretIndex);

        $html = Craft::$app->getView()->renderTemplate('search-manager/widgets/_partials/settings', [
            'widgetConfig' => $widget,
            'indices' => [$docsIndex, $secretIndex],
            'widgetApiKeyOptions' => [
                ['value' => '', 'label' => 'None'],
                ['value' => $key->handle, 'label' => SearchManager::$plugin->apiKeys->widgetKeyLabel($key)],
            ],
            'widgetApiKeyScopes' => [
                '' => ApiKey::ALL_INDICES,
                $key->handle => [$docs],
            ],
            'selectedApiKey' => $key,
            'hasWidgetUsableApiKeys' => true,
            'isNew' => false,
        ]);

        self::assertSame(1, substr_count($html, 'name="settings[search][indexHandles][]"'));
        self::assertStringContainsString('value="' . $docs . '"', $html);
        self::assertStringNotContainsString('value="' . $secret . '"', $html);
        self::assertStringContainsString('search-manager-widget-index-options', $html);
    }

    public function testSelectedApiKeyHandleResolvesToRenderedApiKeyAttribute(): void
    {
        [$key, $plaintext] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;

        $widget = $this->makeWidget('render-selected');
        $widget->settings = $settings;

        self::assertSame($plaintext, $widget->getApiKey());
        self::assertStringContainsString('api-key="' . $plaintext . '"', $this->renderWidget($widget));
    }

    public function testRenderTimeApiKeyOverrideWinsOverSelectedApiKeyHandle(): void
    {
        [$key, $plaintext] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $override = 'sm_pub_' . str_repeat('b', 32);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;

        $widget = $this->makeWidget('render-override');
        $widget->settings = $settings;

        $html = $this->renderWidget($widget, ['apiKey' => $override]);

        self::assertStringContainsString('api-key="' . $override . '"', $html);
        self::assertStringNotContainsString('api-key="' . $plaintext . '"', $html);
    }

    public function testDirectSettingsApiKeyFallbackStillWorks(): void
    {
        $directKey = 'sm_pub_' . str_repeat('c', 32);

        $settings = WidgetConfig::defaultSettings();
        $settings['apiKey'] = $directKey;

        $widget = $this->makeWidget('direct-key');
        $widget->settings = $settings;

        self::assertSame($directKey, $widget->getApiKey());
        self::assertTrue($widget->validate(['settings']));
    }

    public function testWidgetSettingsTemplateUsesSelectorBeforeSearchIndicesAndNoFullKeyField(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/templates/widgets/_partials/settings.twig');
        self::assertIsString($source);

        self::assertStringContainsString('forms.selectField', $source);
        self::assertStringContainsString("name: 'settings[apiKeyHandle]'", $source);
        self::assertStringContainsString('widgetApiKeyOptions', $source);
        self::assertStringContainsString('availableIndices', $source);
        self::assertStringContainsString('search-manager-widget-api-key-scopes', $source);
        self::assertStringNotContainsString("name: 'settings[apiKey]'", $source);
        self::assertLessThan(
            strpos($source, "label: 'Search Indices'|t('search-manager')"),
            strpos($source, "label: 'API Key'|t('search-manager')"),
        );
    }

    public function testApiKeysPrecedeWidgetsInCpNavSourceOrder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/SearchManager.php');
        self::assertIsString($source);

        self::assertLessThan(
            strpos($source, "'key' => 'widgets'"),
            strpos($source, "'key' => 'api-keys'"),
        );
    }

    public function testFindConfigsUsingApiKeyHandleFindsDatabaseWidgetConfigs(): void
    {
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $widget = $this->saveWidgetUsingApiKey($key, 'dependency-db');

        $configs = SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($key->handle);

        self::assertContains($widget->handle, array_map(static fn(WidgetConfig $config): string => $config->handle, $configs));
    }

    public function testFindConfigsUsingApiKeyHandleIncludesConfigFileWidgets(): void
    {
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->withConfigFileWidgets([
            self::PREFIX . 'config-dependent' => [
                'name' => 'Audit 305 config dependent',
                'settings' => [
                    'apiKeyHandle' => $key->handle,
                ],
            ],
        ]);

        $configs = SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($key->handle);

        self::assertContains(self::PREFIX . 'config-dependent', array_map(static fn(WidgetConfig $config): string => $config->handle, $configs));
    }

    public function testExistingApiKeyIdFallbackStillResolvesWidgetConfig(): void
    {
        [$key, $plaintext] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);

        $settings = WidgetConfig::defaultSettings();
        unset($settings['apiKeyHandle']);
        $settings['apiKeyId'] = $key->id;

        $widget = $this->makeWidget('id-fallback');
        $widget->settings = $settings;

        self::assertSame($plaintext, $widget->getApiKey());
        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($widget), print_r($widget->getErrors(), true));

        $configs = SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($key->handle);
        self::assertContains($widget->handle, array_map(static fn(WidgetConfig $config): string => $config->handle, $configs));
    }

    public function testFindConfigsBrokenByApiKeyScopeReturnsOnlyWidgetsOutsideRestrictedKey(): void
    {
        $docs = $this->seedIndex('docs');
        $secret = $this->seedIndex('secret');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs, $secret]);
        $broken = $this->saveWidgetUsingApiKey($key, 'scope-broken', [$secret]);
        $this->saveWidgetUsingApiKey($key, 'scope-empty', []);

        $key->allowedIndices = [$docs];

        $configs = SearchManager::$plugin->widgetConfigs->findConfigsBrokenByApiKeyScope($key);

        self::assertSame([$broken->handle], array_map(static fn(WidgetConfig $config): string => $config->handle, $configs));
    }

    public function testApiKeySaveBlocksScopeChangeThatWouldBreakDependentWidget(): void
    {
        $docs = $this->seedIndex('docs');
        $secret = $this->seedIndex('secret');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs, $secret]);
        $this->saveWidgetUsingApiKey($key, 'save-scope-block', [$secret]);

        $this->postApiKeySave($key, ['allowedIndices' => [$docs]]);

        $fresh = ApiKey::findById((int)$key->id);
        self::assertNotNull($fresh);
        self::assertSame([$docs, $secret], $fresh->allowedIndices);
    }

    public function testApiKeySaveAllowsScopeChangeForEmptyDependentWidgetSelection(): void
    {
        $docs = $this->seedIndex('docs');
        $secret = $this->seedIndex('secret');
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [$docs, $secret]);
        $this->saveWidgetUsingApiKey($key, 'save-scope-empty', []);

        $this->postApiKeySave($key, ['allowedIndices' => [$docs]]);

        $fresh = ApiKey::findById((int)$key->id);
        self::assertNotNull($fresh);
        self::assertSame([$docs], $fresh->allowedIndices);
    }

    public function testApiKeySaveBlocksDisablingUsedPublicKey(): void
    {
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->saveWidgetUsingApiKey($key, 'save-disable-block');

        $this->postApiKeySave($key, ['enabled' => false]);

        $fresh = ApiKey::findById((int)$key->id);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->enabled);
    }

    public function testApiKeySaveBlocksPastValidUntilForUsedPublicKey(): void
    {
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->saveWidgetUsingApiKey($key, 'save-expire-block');

        $this->postApiKeySave($key, ['validUntil' => '2000-01-01 00:00:00']);

        $fresh = ApiKey::findById((int)$key->id);
        self::assertNotNull($fresh);
        self::assertNull($fresh->validUntil);
    }

    public function testApiKeySaveBlocksHandleChangeForUsedPublicKey(): void
    {
        [$key] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->saveWidgetUsingApiKey($key, 'save-handle-block');
        $oldHandle = $key->handle;

        $this->postApiKeySave($key, ['handle' => 'renamed-widget-key']);

        $fresh = ApiKey::findById((int)$key->id);
        self::assertNotNull($fresh);
        self::assertSame($oldHandle, $fresh->handle);
    }

    public function testApiKeyDeleteBlocksUsedKeyAndAllowsUnusedKey(): void
    {
        [$usedKey] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        [$unusedKey] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->saveWidgetUsingApiKey($usedKey, 'delete-block');

        $this->postApiKeyDelete($usedKey);
        self::assertNotNull(ApiKey::findById((int)$usedKey->id));

        $this->postApiKeyDelete($unusedKey);
        self::assertNull(ApiKey::findById((int)$unusedKey->id));
    }

    public function testBulkDeleteAndDisableGuardUsedKeys(): void
    {
        [$usedKey] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        [$unusedKey] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);
        $this->saveWidgetUsingApiKey($usedKey, 'bulk-block');

        $this->postApiKeyBulk('bulk-disable', [(int)$usedKey->id, (int)$unusedKey->id]);
        self::assertTrue(ApiKey::findById((int)$usedKey->id)?->enabled);
        self::assertTrue(ApiKey::findById((int)$unusedKey->id)?->enabled);

        $this->postApiKeyBulk('bulk-delete', [(int)$usedKey->id, (int)$unusedKey->id]);
        self::assertNotNull(ApiKey::findById((int)$usedKey->id));
        self::assertNotNull(ApiKey::findById((int)$unusedKey->id));
    }

    public function testUnusedKeyBulkDisableStillWorks(): void
    {
        [$unusedKey] = $this->seedApiKey(ApiKey::TYPE_PUBLIC, [ApiKey::ALL_INDICES]);

        $this->postApiKeyBulk('bulk-disable', [(int)$unusedKey->id]);

        self::assertFalse(ApiKey::findById((int)$unusedKey->id)?->enabled);
    }

    private function filterSettings(array $data, array $defaults): array
    {
        $controller = new WidgetsController('widgets', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_filterSettingsKeys');
        $method->setAccessible(true);

        return $method->invoke($controller, $data, $defaults);
    }

    private function makeWidget(string $suffix): WidgetConfig
    {
        $widget = new WidgetConfig();
        $widget->handle = self::PREFIX . $suffix;
        $widget->name = 'Audit 305 ' . $suffix;
        $widget->enabled = true;

        return $widget;
    }

    /**
     * @param string[] $allowedIndices
     * @return array{0: ApiKey, 1: string}
     */
    private function seedApiKey(string $type, array $allowedIndices, bool $encrypted = true, string $name = 'Widget key'): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);

        $key = new ApiKey();
        $key->name = self::MARKER . $name . '_' . uniqid();
        $key->type = $type;
        $key->enabled = true;
        $key->keyHash = $generated['hash'];
        $key->encryptedKey = $type === ApiKey::TYPE_PUBLIC && $encrypted
            ? SearchManager::$plugin->apiKeys->encryptPlaintextKey($generated['plaintext'])
            : null;
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = $allowedIndices;

        self::assertTrue($key->save(), print_r($key->getErrors(), true));

        return [$key, $generated['plaintext']];
    }

    private function seedIndex(string $handle): string
    {
        $fullHandle = self::PREFIX . $handle;
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Audit 305 ' . $handle,
            'handle' => $fullHandle,
            'elementType' => \craft\elements\Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => \lindemannrock\searchmanager\transformers\EntryTransformer::class,
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
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();

        return $fullHandle;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderWidget(WidgetConfig $widget, array $params = []): string
    {
        $service = new class($widget) extends WidgetConfigService {
            public function __construct(private WidgetConfig $widget)
            {
                parent::__construct();
            }

            public function getConfigForWidget(?string $handle = null): WidgetConfig
            {
                return $this->widget;
            }
        };

        $this->swapPluginComponent('search-manager', 'widgetConfigs', $service);

        return Craft::$app->getView()->renderTemplate('search-manager/_widget/search-modal', $params);
    }

    /**
     * @param string[] $indexHandles
     */
    private function saveWidgetUsingApiKey(ApiKey $key, string $suffix, array $indexHandles = []): WidgetConfig
    {
        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $key->handle;
        $settings['search']['indexHandles'] = $indexHandles;

        $widget = $this->makeWidget($suffix);
        $widget->settings = $settings;

        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($widget), print_r($widget->getErrors(), true));

        return $widget;
    }

    /**
     * @param array<string, mixed> $widgets
     */
    private function withConfigFileWidgets(array $widgets): void
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $cache = $property->getValue();
        $cache['search-manager'] = ['widgets' => $widgets];
        $property->setValue(null, $cache);

        $serviceReflection = new \ReflectionClass(SearchManager::$plugin->widgetConfigs);
        $serviceProperty = $serviceReflection->getProperty('_configFileConfigs');
        $serviceProperty->setAccessible(true);
        $serviceProperty->setValue(SearchManager::$plugin->widgetConfigs, null);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function postApiKeySave(ApiKey $key, array $overrides): void
    {
        $params = array_merge([
            'keyId' => $key->id,
            'name' => $key->name,
            'handle' => $key->handle,
            'enabled' => true,
            'allowAllIndices' => $key->allowsAllIndices(),
            'allowedIndices' => $key->allowsAllIndices() ? [] : $key->allowedIndices,
            'allowedReferrers' => implode("\n", $key->allowedReferrers),
            'maxHitsPerPage' => $key->maxHitsPerPage,
            'rateLimit' => $key->rateLimit,
            'validUntil' => $key->validUntil?->format('Y-m-d H:i:s'),
        ], $overrides);

        $this->withPostParams($params);

        $controller = new ApiKeysController('api-keys', SearchManager::$plugin);

        $populate = new \ReflectionMethod($controller, 'populateRestrictionsFromRequest');
        $populate->setAccessible(true);
        $populate->invoke($controller, $key, Craft::$app->getRequest());

        $guard = new \ReflectionMethod($controller, 'guardApiKeyWidgetDependenciesForSave');
        $guard->setAccessible(true);
        if ($guard->invoke($controller, $key)) {
            self::assertTrue($key->save(), print_r($key->getErrors(), true));
        }
    }

    private function createApiKeyFromName(string $name): ApiKey
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = $name;
        $key->handle = SlugHandleHelper::makeUnique(
            '{{%searchmanager_api_keys}}',
            'handle',
            SlugHandleHelper::normalizeSlug('', $key->name),
        );
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];

        self::assertTrue($key->save(), print_r($key->getErrors(), true));

        return $key;
    }

    private function postApiKeyDelete(ApiKey $key): void
    {
        $this->withApiKeyManagerPermissions(['searchManager:revokeApiKeys']);
        $this->withPostParams(['keyId' => $key->id]);

        (new ApiKeysController('api-keys', SearchManager::$plugin))->actionDelete();
    }

    /**
     * @param int[] $ids
     */
    private function postApiKeyBulk(string $action, array $ids): void
    {
        $permission = $action === 'bulk-delete' ? 'searchManager:revokeApiKeys' : 'searchManager:editApiKeys';
        $this->withApiKeyManagerPermissions([$permission]);
        $this->withPostParams(['ids' => $ids]);

        $controller = new ApiKeysController('api-keys', SearchManager::$plugin);
        if ($action === 'bulk-delete') {
            $controller->actionBulkDelete();
            return;
        }

        $controller->actionBulkDisable();
    }

    /**
     * @param list<string> $permissions
     */
    private function withApiKeyManagerPermissions(array $permissions): void
    {
        $user = $this->createTestUser(self::PREFIX, [
            'admin' => true,
        ]);
        $this->grantPermissions($user, array_values(array_unique(array_merge(['accessCp', 'searchManager:manageApiKeys'], $permissions))));
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostParams(array $params): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::MARKER . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
