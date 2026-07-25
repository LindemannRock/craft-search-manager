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
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\View;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for canonical index identity, catalogue, and status.
 *
 * @since 5.54.0
 */
#[CoversClass(DependencyService::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(ApiKey::class)]
#[CoversClass(WidgetConfig::class)]
final class IndexCatalogueLifecycleTest extends TestCase
{
    private const PREFIX = 'sm-index-catalogue';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeMarkedRows();
        $this->setSearchManagerConfig([]);
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
    }

    protected function tearDown(): void
    {
        $this->purgeMarkedRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        $this->clearCatalogue();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{mixed, string, string, string}>
     */
    public static function configNameProvider(): iterable
    {
        yield 'missing' => [self::missingName(), self::PREFIX . '-missing-name', self::PREFIX . '-missing-name', self::PREFIX . '-missing-name'];
        yield 'null' => [null, self::PREFIX . '-null-name', self::PREFIX . '-null-name', self::PREFIX . '-null-name'];
        yield 'empty' => ['', self::PREFIX . '-empty-name', self::PREFIX . '-empty-name', self::PREFIX . '-empty-name'];
        yield 'whitespace' => ['   ', self::PREFIX . '-whitespace-name', self::PREFIX . '-whitespace-name', self::PREFIX . '-whitespace-name'];
        yield 'same as handle' => [self::PREFIX . '-same-name', self::PREFIX . '-same-name', self::PREFIX . '-same-name', self::PREFIX . '-same-name'];
        yield 'distinct' => ['Readable Name', self::PREFIX . '-distinct-name', 'Readable Name', 'Readable Name (' . self::PREFIX . '-distinct-name)'];
    }

    #[DataProvider('configNameProvider')]
    public function testConfigNamesAndCanonicalIdentityNeverRenderBlankOrDuplicateHandles(
        mixed $name,
        string $handle,
        string $expectedDisplayName,
        string $expectedIdentityLabel,
    ): void {
        $definition = [
            'elementType' => Entry::class,
            'enabled' => true,
        ];
        if ($name !== self::missingName()) {
            $definition['name'] = $name;
        }
        $this->setSearchManagerConfig(['indices' => [$handle => $definition]]);

        $index = SearchIndex::findByHandle($handle);
        self::assertInstanceOf(SearchIndex::class, $index);
        self::assertSame($expectedDisplayName, $index->name);

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        self::assertSame($expectedDisplayName, $catalogue[$handle]['displayName']);
        self::assertSame($expectedIdentityLabel, $catalogue[$handle]['identityLabel']);
        self::assertSame($expectedIdentityLabel, $catalogue[$handle]['choiceLabel']);
        self::assertArrayNotHasKey('label', $catalogue[$handle]);
        self::assertStringNotContainsString($handle . ' (' . $handle . ')', $catalogue[$handle]['identityLabel']);
    }

    public function testBrokenFixtureIdentityIsNonBlankAndIndexListUsesCanonicalClickableLabel(): void
    {
        $handle = 'fixture-broken-everything';
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => [
                    'name' => '   ',
                    'elementType' => 'missing\\ElementType',
                    'enabled' => true,
                ],
            ],
        ]);

        $index = SearchIndex::findByHandle($handle);
        self::assertInstanceOf(SearchIndex::class, $index);
        self::assertSame($handle, $index->name);

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue([$handle]);
        self::assertSame($handle, $catalogue[$handle]['displayName']);
        self::assertSame($handle, $catalogue[$handle]['identityLabel']);
        self::assertSame($handle . ' — Error', $catalogue[$handle]['choiceLabel']);
        self::assertArrayNotHasKey('label', $catalogue[$handle]);

        $template = $this->readPluginFile('src/templates/indices/index.twig');
        self::assertStringContainsString('indexReference.displayName', $template);
        self::assertStringContainsString("url('search-manager/indices/view/' ~ item.handle)", $template);
    }

    public function testCatalogueClassifiesHealthyDisabledWarningErrorAndMissingReferences(): void
    {
        $enabled = self::PREFIX . '-enabled';
        $disabled = self::PREFIX . '-disabled';
        $warning = self::PREFIX . '-warning';
        $error = self::PREFIX . '-error';
        $missing = self::PREFIX . '-missing';
        $this->insertIndex($enabled, 'Enabled Database', true);
        $this->insertIndex($disabled, 'Disabled Database', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $warning => [
                    'name' => ' ',
                    'elementType' => Entry::class,
                    'enabled' => true,
                ],
                $error => [
                    'name' => 'Broken Config',
                    'elementType' => 'missing\\ElementType',
                    'enabled' => true,
                ],
            ],
        ]);

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue([$missing, $error]);

        self::assertCatalogueState($catalogue[$enabled], 'enabled', true, true, false);
        self::assertCatalogueState($catalogue[$disabled], 'disabled', true, true, false);
        self::assertCatalogueState($catalogue[$warning], 'enabled', true, true, false);
        self::assertCatalogueState($catalogue[$error], 'error', true, false, true);
        self::assertCatalogueState($catalogue[$missing], 'error', false, false, true);
        self::assertSame('Disabled', $catalogue[$disabled]['status']['label']);
        self::assertSame('Error', $catalogue[$error]['status']['label']);
        self::assertSame('Error', $catalogue[$missing]['status']['label']);
        self::assertSame('status', $catalogue[$enabled]['status']['colorSet']);
        self::assertSame('status', $catalogue[$disabled]['status']['colorSet']);
        self::assertSame('status', $catalogue[$error]['status']['colorSet']);
    }

    public function testApplicableGlobalConfigErrorMarksOnlyConfigIndicesUnavailable(): void
    {
        $database = self::PREFIX . '-global-database';
        $config = self::PREFIX . '-global-config';
        $this->insertIndex($database, 'Global Database');
        $this->setSearchManagerConfig([
            'indices' => [
                $config => [
                    'name' => 'Global Config',
                    'elementType' => Entry::class,
                ],
            ],
        ]);

        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_LOAD_FAILURE);
        $validation->addFinding(
            null,
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'indices',
            'Synthetic global config error.',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedCatalogueConfigIndexValidator($validation),
        );
        $this->clearCatalogue();

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();

        self::assertSame('enabled', $catalogue[$database]['state']);
        self::assertSame('error', $catalogue[$config]['state']);
        self::assertSame('Synthetic global config error.', $catalogue[$config]['errorTitle']);
    }

    public function testConfigPrecedenceProducesOneCanonicalChoiceForCollidingHandle(): void
    {
        $handle = self::PREFIX . '-collision';
        $this->insertIndex($handle, 'Shadowed Database Name');
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => [
                    'name' => 'Effective Config Name',
                    'elementType' => Entry::class,
                    'enabled' => true,
                ],
            ],
        ]);

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $options = array_values(array_filter(
            SearchManager::$plugin->dependencies->getIndexOptions(null, false),
            static fn(array $option): bool => $option['value'] === $handle,
        ));

        self::assertSame('config', $catalogue[$handle]['source']);
        self::assertSame('Effective Config Name', $catalogue[$handle]['displayName']);
        self::assertSame("Effective Config Name ({$handle})", $catalogue[$handle]['identityLabel']);
        self::assertSame("Effective Config Name ({$handle})", $catalogue[$handle]['choiceLabel']);
        self::assertArrayNotHasKey('label', $catalogue[$handle]);
        self::assertCount(1, $options);
        self::assertSame("Effective Config Name ({$handle})", $options[0]['label']);
    }

    public function testChoiceOptionsShareCanonicalLabelsAndRetainOnlySelectedUnavailableHandles(): void
    {
        $enabled = self::PREFIX . '-choice-enabled';
        $disabled = self::PREFIX . '-choice-disabled';
        $error = self::PREFIX . '-choice-error';
        $missing = self::PREFIX . '-choice-missing';
        $this->insertIndex($enabled, 'Choice Enabled');
        $this->insertIndex($disabled, 'Choice Disabled', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $error => [
                    'name' => 'Choice Error',
                    'elementType' => 'missing\\ElementType',
                ],
            ],
        ]);

        $newOptions = SearchManager::$plugin->dependencies->getIndexOptions(null, false);
        $recoveryOptions = SearchManager::$plugin->dependencies->getIndexOptions([$error, $missing], false);

        $newOptionMap = $this->optionMap($newOptions);
        self::assertSame('Choice Enabled (' . $enabled . ')', $newOptionMap[$enabled]);
        self::assertSame('Choice Disabled (' . $disabled . ') — Disabled', $newOptionMap[$disabled]);
        self::assertArrayNotHasKey($error, $newOptionMap);
        self::assertArrayNotHasKey($missing, $newOptionMap);
        self::assertSame(
            'Choice Error (' . $error . ') — Error',
            $this->optionMap($recoveryOptions)[$error],
        );
        self::assertSame($missing . ' — Error', $this->optionMap($recoveryOptions)[$missing]);
        self::assertTrue($this->optionByValue($recoveryOptions, $error)['retained']);
        self::assertTrue($this->optionByValue($recoveryOptions, $missing)['retained']);
    }

    /**
     * @return array<string, array{class-string<QueryRule|Promotion>}>
     */
    public static function scopedModelProvider(): array
    {
        return [
            'query rule' => [QueryRule::class],
            'promotion' => [Promotion::class],
        ];
    }

    #[DataProvider('scopedModelProvider')]
    public function testSharedScopedReferenceValidationUsesCatalogueAvailability(string $modelClass): void
    {
        $disabled = self::PREFIX . '-scoped-disabled';
        $error = self::PREFIX . '-scoped-error';
        $missing = self::PREFIX . '-scoped-missing';
        $this->insertIndex($disabled, 'Scoped Disabled', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $error => [
                    'name' => 'Scoped Error',
                    'elementType' => 'missing\\ElementType',
                ],
            ],
        ]);

        foreach ([null, '', '   ', $disabled] as $validHandle) {
            $model = new $modelClass();
            $model->indexHandle = $validHandle;
            self::assertTrue($model->validate(['indexHandle']), json_encode($model->getErrors()));
        }

        foreach ([$error, $missing] as $invalidHandle) {
            $model = new $modelClass();
            $model->indexHandle = $invalidHandle;
            self::assertFalse($model->validate(['indexHandle']));
            self::assertSame(['Index not found'], $model->getErrors('indexHandle'));
        }
    }

    public function testApiKeyAndWidgetValidationUseTheSameCatalogueBoundary(): void
    {
        $disabled = self::PREFIX . '-array-disabled';
        $error = self::PREFIX . '-array-error';
        $missing = self::PREFIX . '-array-missing';
        $this->insertIndex($disabled, 'Array Disabled', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $error => [
                    'name' => 'Array Error',
                    'elementType' => 'missing\\ElementType',
                ],
            ],
        ]);

        foreach ([[ApiKey::ALL_INDICES], [$disabled]] as $validHandles) {
            $key = $this->apiKey($validHandles);
            self::assertTrue($key->validate(['allowedIndices']), json_encode($key->getErrors()));
        }
        foreach ([[$error], [$missing], [$disabled, ApiKey::ALL_INDICES], ['']] as $invalidHandles) {
            $key = $this->apiKey($invalidHandles);
            self::assertFalse($key->validate(['allowedIndices']));
            self::assertNotEmpty($key->getErrors('allowedIndices'));
        }

        foreach ([[], [$disabled]] as $validHandles) {
            $widget = $this->widget($validHandles);
            self::assertTrue($widget->validate(['settings']), json_encode($widget->getErrors()));
        }
        foreach ([[$error], [$missing], $disabled] as $invalidHandles) {
            $widget = $this->widget($invalidHandles);
            self::assertFalse($widget->validate(['settings']));
            self::assertNotEmpty($widget->getErrors('settings.search.indexHandles'));
        }
    }

    public function testForgedScalarApiKeySubmissionIsRejectedBeforePersistence(): void
    {
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams([
            'name' => 'Forged Scalar',
            'handle' => 'forged-scalar',
            'allowAllIndices' => false,
            'allowedIndices' => self::PREFIX . '-array-disabled',
        ]);
        $apiKey = $this->apiKey([ApiKey::ALL_INDICES]);
        $controller = new ApiKeysController('api-keys', SearchManager::$plugin);
        $populate = new \ReflectionMethod($controller, 'populateRestrictionsFromRequest');

        $valid = $populate->invoke($controller, $apiKey, $request);

        self::assertFalse($valid);
        self::assertSame([], $apiKey->allowedIndices);
        self::assertSame(
            ['One or more selected search indices are invalid.'],
            $apiKey->getErrors('allowedIndices'),
        );
        self::assertNull(ApiKey::findByHandle('forged-scalar'));
    }

    public function testEffectiveStatusSortingUsesDisplayedErrorDisabledEnabledOrder(): void
    {
        $error = $this->indexModel('sort-error', true);
        $disabled = $this->indexModel('sort-disabled', false);
        $enabled = $this->indexModel('sort-enabled', true);
        $catalogue = [
            'sort-error' => ['state' => 'error', 'displayName' => 'Error', 'status' => ['value' => 'error']],
            'sort-disabled' => ['state' => 'disabled', 'displayName' => 'Disabled', 'status' => ['value' => 'disabled']],
            'sort-enabled' => ['state' => 'enabled', 'displayName' => 'Enabled', 'status' => ['value' => 'enabled']],
        ];
        $controller = new IndicesController('indices', SearchManager::$plugin);
        $sort = new \ReflectionMethod($controller, 'sortIndices');

        $ascending = $sort->invoke($controller, [$enabled, $error, $disabled], 'enabled', 'asc', $catalogue);
        $descending = $sort->invoke($controller, [$disabled, $error, $enabled], 'enabled', 'desc', $catalogue);

        self::assertSame(
            ['sort-error', 'sort-disabled', 'sort-enabled'],
            array_map(static fn(SearchIndex $index): string => $index->handle, $ascending),
        );
        self::assertSame(
            ['sort-enabled', 'sort-disabled', 'sort-error'],
            array_map(static fn(SearchIndex $index): string => $index->handle, $descending),
        );
    }

    public function testSharedEffectiveStatusRendersBadgeAndSidebarDotForEveryState(): void
    {
        $enabled = $this->reference('enabled', true, null);
        $disabled = $this->reference('disabled', false, null);
        $error = $this->reference('error', true, 'Broken configuration.');

        $enabledBadge = $this->renderEffectiveStatus($enabled, true, 'badge');
        $disabledSidebar = $this->renderEffectiveStatus($disabled, false, 'sidebar');
        $errorBadge = $this->renderEffectiveStatus($error, true, 'badge');
        $errorSidebar = $this->renderEffectiveStatus($error, true, 'sidebar');

        self::assertStringContainsString('status-label', $enabledBadge);
        self::assertStringContainsString('Enabled', $enabledBadge);
        self::assertStringContainsString('class="status ', $disabledSidebar);
        self::assertStringContainsString('Disabled', $disabledSidebar);
        self::assertStringNotContainsString('status-label', $disabledSidebar);
        self::assertStringContainsString('Error', $errorBadge);
        self::assertStringContainsString('Broken configuration.', $errorBadge);
        self::assertStringContainsString('class="status ', $errorSidebar);
        self::assertStringContainsString('Error', $errorSidebar);
        self::assertStringContainsString('Broken configuration.', $errorSidebar);
    }

    public function testRequiredPrivateComponentsReplaceObsoleteStatusAndReferencePartials(): void
    {
        $root = dirname(__DIR__, 2) . '/src/templates';
        foreach ([
            '_components/_config-index-findings.twig',
            '_components/_index-reference.twig',
            '_components/_index-reference-error.twig',
            '_components/_effective-status.twig',
        ] as $path) {
            self::assertFileExists($root . '/' . $path);
        }

        foreach ([
            '_components/index-reference.twig',
            '_components/index-reference-error.twig',
            '_components/index-reference-status.twig',
            'indices/_status-badge.twig',
        ] as $path) {
            self::assertFileDoesNotExist($root . '/' . $path);
        }

        foreach (glob($root . '/_components/*.twig') ?: [] as $path) {
            self::assertStringStartsWith('_', basename($path), $path);
        }
    }

    public function testEveryCpChoiceConsumerUsesDependencyCatalogueAndNoTwigOptionBuilder(): void
    {
        $controllerSources = [
            $this->readPluginFile('src/controllers/QueryRulesController.php'),
            $this->readPluginFile('src/controllers/PromotionsController.php'),
            $this->readPluginFile('src/controllers/WidgetsController.php'),
            $this->readPluginFile('src/controllers/ApiKeysController.php'),
        ];
        foreach ($controllerSources as $source) {
            self::assertStringContainsString('dependencies->getIndexOptions(', $source);
        }

        $widgetTemplate = $this->readPluginFile('src/templates/widgets/_partials/settings.twig');
        $widgetEditTemplate = $this->readPluginFile('src/templates/widgets/edit.twig');
        $apiKeyTemplate = $this->readPluginFile('src/templates/api-keys/edit.twig');
        self::assertStringNotContainsString('{% for index in indices %}', $widgetTemplate);
        self::assertStringNotContainsString('allIndices|map(', $apiKeyTemplate);
        self::assertStringContainsString('allIndexOptions', $widgetTemplate);
        self::assertStringContainsString('indexOptions: indexOptions', $widgetEditTemplate);
        self::assertStringContainsString('allIndexOptions: allIndexOptions', $widgetEditTemplate);
        self::assertStringNotContainsString('indices: indices', $widgetEditTemplate);
        self::assertStringContainsString('indexOptions', $apiKeyTemplate);
        self::assertStringContainsString(
            "'label' => \$record['choiceLabel']",
            $this->readPluginFile('src/services/DependencyService.php'),
        );
    }

    public function testExplicitCatalogueRepresentationsAreMappedToTheirPresentationSurfaces(): void
    {
        $handle = self::PREFIX . '-surface';
        $this->insertIndex($handle, 'Surface Name');
        $reference = SearchManager::$plugin->dependencies->getIndexCatalogue()[$handle];

        self::assertSame('Surface Name', $reference['displayName']);
        self::assertSame("Surface Name ({$handle})", $reference['identityLabel']);
        self::assertSame("Surface Name ({$handle})", $reference['choiceLabel']);
        self::assertArrayNotHasKey('label', $reference);

        $referenceHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_index-reference',
            ['reference' => $reference],
            View::TEMPLATE_MODE_CP,
        );
        self::assertStringContainsString("Surface Name ({$handle})", $referenceHtml);

        $indexList = $this->readPluginFile('src/templates/indices/index.twig');
        self::assertSame(7, substr_count($indexList, 'indexReference.displayName'));
        self::assertStringNotContainsString('indexReference.identityLabel', $indexList);
        self::assertStringNotContainsString('indexReference.label', $indexList);

        $indexEdit = $this->readPluginFile('src/templates/indices/edit.twig');
        self::assertSame(2, substr_count($indexEdit, 'indexReference.displayName'));
        self::assertStringNotContainsString('indexReference.identityLabel', $indexEdit);
        self::assertStringNotContainsString('indexReference.label', $indexEdit);

        $indexView = $this->readPluginFile('src/templates/indices/view.twig');
        self::assertSame(2, substr_count($indexView, 'indexReference.displayName'));
        self::assertStringNotContainsString('indexReference.identityLabel', $indexView);
        self::assertStringNotContainsString('indexReference.label', $indexView);

        $referenceComponent = $this->readPluginFile('src/templates/_components/_index-reference.twig');
        self::assertSame(3, substr_count($referenceComponent, 'reference.identityLabel'));
        self::assertStringNotContainsString('reference.displayName', $referenceComponent);
        self::assertStringNotContainsString('reference.label', $referenceComponent);

        $indexController = $this->readPluginFile('src/controllers/IndicesController.php');
        self::assertStringContainsString("['displayName']", $indexController);
        self::assertStringNotContainsString("['label']", $indexController);
    }

    public function testStatusSurfacesAndIndexControllerShareEffectiveStatusContract(): void
    {
        foreach ([
            'src/templates/indices/index.twig',
            'src/templates/indices/view.twig',
            'src/templates/indices/edit.twig',
            'src/templates/query-rules/index.twig',
            'src/templates/query-rules/edit.twig',
            'src/templates/promotions/index.twig',
            'src/templates/promotions/edit.twig',
        ] as $path) {
            self::assertStringContainsString(
                'search-manager/_components/_effective-status',
                $this->readPluginFile($path),
                $path,
            );
        }

        $controller = $this->readPluginFile('src/controllers/IndicesController.php');
        $indexTemplate = $this->readPluginFile('src/templates/indices/index.twig');
        self::assertStringContainsString("'error'", $controller);
        self::assertStringContainsString("['state']", $controller);
        self::assertStringNotContainsString('configIndexValidator->validate()', $controller);
        self::assertStringNotContainsString('((int) $a->enabled) <=> ((int) $b->enabled)', $controller);
        self::assertSame(2, substr_count($indexTemplate, '{% set indexReference = indexCatalogue[item.handle] %}'));
    }

    private static function missingName(): object
    {
        static $missing;
        return $missing ??= new \stdClass();
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function assertCatalogueState(
        array $record,
        string $state,
        bool $exists,
        bool $canChoose,
        bool $retained,
    ): void {
        self::assertSame($state, $record['state']);
        self::assertSame($exists, $record['exists']);
        self::assertSame($canChoose, $record['canChoose']);
        self::assertSame($retained, $record['retained']);
    }

    /**
     * @param list<array{value: string, label: string}> $options
     * @return array<string, string>
     */
    private function optionMap(array $options): array
    {
        $map = [];
        foreach ($options as $option) {
            $map[$option['value']] = $option['label'];
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function optionByValue(array $options, string $value): array
    {
        foreach ($options as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        self::fail("Missing option {$value}.");
    }

    /**
     * @param array<string, mixed> $reference
     */
    private function renderEffectiveStatus(array $reference, bool $enabled, string $presentation): string
    {
        return Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_effective-status',
            [
                'status' => SearchManager::$plugin->dependencies->resolveEffectiveStatus(
                    $enabled,
                    $reference,
                ),
                'presentation' => $presentation,
            ],
            View::TEMPLATE_MODE_CP,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reference(string $state, bool $enabled, ?string $errorTitle): array
    {
        return [
            'state' => $state,
            'enabled' => $enabled,
            'errorTitle' => $errorTitle,
        ];
    }

    /**
     * @param list<string> $allowedIndices
     */
    private function apiKey(array $allowedIndices): ApiKey
    {
        $key = new ApiKey();
        $key->enabled = false;
        $key->allowedIndices = $allowedIndices;

        return $key;
    }

    private function widget(mixed $indexHandles): WidgetConfig
    {
        $settings = WidgetConfig::defaultSettings();
        $settings['search']['indexHandles'] = $indexHandles;
        $widget = new WidgetConfig();
        $widget->settings = $settings;

        return $widget;
    }

    private function indexModel(string $handle, bool $enabled): SearchIndex
    {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->name = $handle;
        $index->enabled = $enabled;

        return $index;
    }

    private function setSearchManagerConfig(array $config): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = $config;
        $this->setConfigCache($cache);
        $validation = (new ConfigIndexValidator())->validateConfig($config);
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedCatalogueConfigIndexValidator($validation),
        );
        SearchIndex::clearCache();
        $this->clearCatalogue();
    }

    private function clearCatalogue(): void
    {
        $dependencies = SearchManager::$plugin->dependencies;
        if (method_exists($dependencies, 'clearIndexCatalogue')) {
            $dependencies->clearIndexCatalogue();
        }
    }

    private function insertIndex(string $handle, string $name, bool $enabled = true): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => (int)$enabled,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'backend' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        $this->clearCatalogue();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function purgeMarkedRows(): void
    {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $ids)])
                ->execute();
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $ids)])
                ->execute();
        }
        SearchIndex::clearCache();
        $this->clearCatalogue();
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}

/**
 * @since 5.54.0
 */
final class FixedCatalogueConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(
        private readonly ConfigIndexValidationResult $result,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function validate(): ConfigIndexValidationResult
    {
        return $this->result;
    }
}
