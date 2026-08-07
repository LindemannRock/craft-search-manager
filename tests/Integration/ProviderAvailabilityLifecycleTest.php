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
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\events\PluginEvent;
use craft\web\View;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Component;

/**
 * Regression coverage for provider availability lifecycle and dependency reconciliation.
 *
 * @since 5.54.0
 */
final class ProviderAvailabilityLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        $cache = Craft::$app->getCache();
        $key = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'dynamic-transformers') . 'registry';
        $registry = $cache->get($key);
        if (is_array($registry)) {
            unset(
                $registry[ProviderAvailabilityPluginElement::class],
                $registry[ProviderAvailabilityDynamicElement::class],
            );
            if ($registry === []) {
                $cache->delete($key);
            } else {
                $cache->set($key, $registry);
            }
        }

        parent::tearDown();
    }

    public function testCraftLifecycleListenersAreScopedToRequiredEvents(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/SearchManager.php');
        self::assertIsString($source);

        foreach ([
            'Sites::EVENT_AFTER_SAVE_SITE',
            'Sites::EVENT_AFTER_DELETE_SITE',
            'Plugins::EVENT_AFTER_DISABLE_PLUGIN',
            'Plugins::EVENT_AFTER_ENABLE_PLUGIN',
        ] as $event) {
            self::assertTrue(str_contains($source, $event), "Missing lifecycle listener: {$event}");
        }

        self::assertTrue(str_contains($source, 'if ($event->isNew)'));
        self::assertSame(3, substr_count($source, 'scheduleAllSitesReconciliation('));
        self::assertSame(2, substr_count($source, 'scheduleAffectedIndexRebuilds('));
    }

    public function testClassOwnershipContractFailsClosedButLeavesCoreAndProjectClassesUngated(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'custom-provider',
            ProviderAvailabilityPluginTransformer::class => 'transformer-provider',
        ], []);

        $core = $dependencies->getClassAvailability(Entry::class, ElementInterface::class);
        $project = $dependencies->getClassAvailability(ProviderAvailabilityProjectElement::class, ElementInterface::class);
        $pluginElement = $dependencies->getClassAvailability(ProviderAvailabilityPluginElement::class, ElementInterface::class);
        $pluginTransformer = $dependencies->getClassAvailability(ProviderAvailabilityPluginTransformer::class, TransformerInterface::class);

        self::assertTrue($core['available']);
        self::assertNull($core['providerHandle']);
        self::assertTrue($project['available']);
        self::assertNull($project['providerHandle']);
        self::assertFalse($pluginElement['available']);
        self::assertSame('provider-disabled', $pluginElement['reason']);
        self::assertFalse($pluginTransformer['available']);
        self::assertSame('provider-disabled', $pluginTransformer['reason']);
    }

    public function testCraftOwnershipResolutionCoversKnownAndCommerceProviders(): void
    {
        $classes = [
            'lindemannrock\\docsmanager\\DocsManager' => 'docs-manager',
            'lindemannrock\\smartlinkmanager\\elements\\SmartLink' => 'smartlink-manager',
            'lindemannrock\\shortlinkmanager\\elements\\ShortLink' => 'shortlink-manager',
            'craft\\commerce\\elements\\Product' => 'commerce',
        ];

        $plugins = new \craft\services\Plugins(['pluginConfigs' => []]);
        foreach ($classes as $class => $expectedHandle) {
            self::assertTrue(class_exists($class), "{$class} should be autoloadable in the integration fixture.");
            self::assertSame($expectedHandle, $plugins->getPluginHandleByClass($class));
        }

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/DependencyService.php');
        self::assertIsString($source);
        self::assertTrue(str_contains($source, 'getPluginHandleByClass($class)'));
    }

    public function testInvalidClassInterfaceAndConstructibilityFailClosed(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService();

        self::assertSame(
            'class-missing',
            $dependencies->getClassAvailability('missing\\Element', ElementInterface::class)['reason'],
        );
        self::assertSame(
            'invalid-contract',
            $dependencies->getClassAvailability(\stdClass::class, ElementInterface::class)['reason'],
        );
        self::assertSame(
            'not-constructible',
            $dependencies->getClassAvailability(ProviderAvailabilityRequiredConstructorTransformer::class, TransformerInterface::class)['reason'],
        );
    }

    public function testCatalogueUsesElementAndExplicitOrDynamicTransformerAvailability(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'element-provider',
            ProviderAvailabilityPluginTransformer::class => 'transformer-provider',
        ], []);
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);

        $elementIndex = $this->index('plugin-element', ProviderAvailabilityPluginElement::class);
        $explicitTransformerIndex = $this->index('explicit-transformer', Entry::class);
        $explicitTransformerIndex->transformerClass = ProviderAvailabilityPluginTransformer::class;
        SearchManager::$plugin->transformers->registerTransformer(
            ProviderAvailabilityDynamicElement::class,
            ProviderAvailabilityPluginTransformer::class,
        );
        $dynamicTransformerIndex = $this->index('dynamic-transformer', ProviderAvailabilityDynamicElement::class);
        $unaffectedIndex = $this->index('unaffected', Entry::class);

        $catalogue = $this->withOnlySearchIndices(
            [$elementIndex, $explicitTransformerIndex, $dynamicTransformerIndex, $unaffectedIndex],
            fn(): array => $dependencies->getIndexCatalogue(),
        );

        self::assertFalse($catalogue['plugin-element']['available']);
        self::assertFalse($catalogue['explicit-transformer']['available']);
        self::assertFalse($catalogue['dynamic-transformer']['available']);
        self::assertTrue($catalogue['unaffected']['available']);
        self::assertSame('error', $catalogue['plugin-element']['status']['value']);
        self::assertFalse($catalogue['plugin-element']['referenceable']);
    }

    public function testDisabledProviderFindingsAreSpecificOrderedAndDeduplicated(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService(
            [
                ProviderAvailabilityPluginElement::class => 'element-provider',
                ProviderAvailabilityPluginTransformer::class => 'transformer-provider',
            ],
            [],
            [
                'element-provider' => 'Element Provider',
                'transformer-provider' => 'Transformer Provider',
            ],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);

        $elementOnly = $this->index('element-only', ProviderAvailabilityPluginElement::class);
        $transformerOnly = $this->index('transformer-only', Entry::class);
        $transformerOnly->transformerClass = ProviderAvailabilityPluginTransformer::class;
        $distinct = $this->index('distinct-providers', ProviderAvailabilityPluginElement::class);
        $distinct->transformerClass = ProviderAvailabilityPluginTransformer::class;

        $catalogue = $this->withOnlySearchIndices(
            [$elementOnly, $transformerOnly, $distinct],
            fn(): array => $dependencies->getIndexCatalogue(),
        );

        $elementMessage = sprintf(
            'The element type "%s" belongs to the disabled plugin "Element Provider". Enable the plugin before rebuilding this index.',
            ProviderAvailabilityPluginElement::class,
        );
        $transformerMessage = sprintf(
            'The transformer "%s" belongs to the disabled plugin "Transformer Provider". Enable the plugin before rebuilding this index.',
            ProviderAvailabilityPluginTransformer::class,
        );

        self::assertSame([$elementMessage], array_column($catalogue['element-only']['findings'], 'message'));
        self::assertSame([$transformerMessage], array_column($catalogue['transformer-only']['findings'], 'message'));
        self::assertSame(
            [$elementMessage, $transformerMessage],
            array_column($catalogue['distinct-providers']['findings'], 'message'),
        );
        self::assertSame($elementMessage, $catalogue['distinct-providers']['errorTitle']);
        self::assertSame($elementMessage, $catalogue['distinct-providers']['status']['title']);

        $sameProvider = new ProviderAvailabilityDependencyService(
            [
                ProviderAvailabilityPluginElement::class => 'shared-provider',
                ProviderAvailabilityPluginTransformer::class => 'shared-provider',
            ],
            [],
            ['shared-provider' => 'Shared Provider'],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $sameProvider);
        $same = $this->index('same-provider', ProviderAvailabilityPluginElement::class);
        $same->transformerClass = ProviderAvailabilityPluginTransformer::class;

        $sameRecord = $this->withOnlySearchIndices(
            [$same],
            fn(): array => $sameProvider->getIndexCatalogue()['same-provider'],
        );

        self::assertCount(1, $sameRecord['findings']);
        self::assertStringStartsWith('The element type "', $sameRecord['findings'][0]['message']);
        self::assertSame(1, substr_count($sameRecord['findings'][0]['message'], 'Shared Provider'));
    }

    public function testActualConfigValidationIsMergedWithSpecificProviderRecovery(): void
    {
        $originalConfig = $this->configCache();
        $dependencies = new ProviderAvailabilityDependencyService(
            [ProviderAvailabilityPluginElement::class => 'config-provider'],
            [],
            ['config-provider' => 'Config Provider'],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);

        try {
            $this->withConfigFileIndices([
                'disabled-config-provider' => [
                    'name' => 'Disabled Config Provider',
                    'elementType' => ProviderAvailabilityPluginElement::class,
                    'enabled' => true,
                ],
            ]);
            $dependencies->clearIndexCatalogue();

            $record = $dependencies->getIndexCatalogue()['disabled-config-provider'];
            $messages = array_column($record['findings'], 'message');

            self::assertStringContainsString(
                sprintf(
                    'The element type "%s" belongs to the disabled plugin "Config Provider". Enable the plugin before rebuilding this index.',
                    ProviderAvailabilityPluginElement::class,
                ),
                implode("\n", $messages),
            );
            self::assertSame(1, substr_count(implode("\n", $messages), 'Config Provider'));
            self::assertStringNotContainsString(
                sprintf('Element type "%s" is unavailable.', ProviderAvailabilityPluginElement::class),
                implode("\n", $messages),
            );
        } finally {
            $this->setConfigCache($originalConfig);
            SearchIndex::clearCache();
            $dependencies->clearIndexCatalogue();
        }
    }

    public function testEnabledProviderHasNoDependencyRecoveryFinding(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService(
            [ProviderAvailabilityPluginElement::class => 'enabled-provider'],
            ['enabled-provider'],
            ['enabled-provider' => 'Enabled Provider'],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $index = $this->index('enabled-provider-index', ProviderAvailabilityPluginElement::class);

        $record = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $dependencies->getIndexCatalogue()['enabled-provider-index'],
        );

        self::assertSame([], $record['findings']);
        self::assertSame('enabled', $record['state']);
        self::assertNull($record['errorTitle']);
    }

    public function testDependencyFindingReplacesOnlyGenericProviderErrorAndPreservesMalformedConfigFindings(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService(
            [ProviderAvailabilityPluginElement::class => 'config-provider'],
            [],
            ['config-provider' => 'Config Provider'],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $validation->addFinding(
            'config-provider-index',
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'elementType',
            sprintf('Element type "%s" is unavailable.', ProviderAvailabilityPluginElement::class),
        );
        $validation->addFinding(
            'config-provider-index',
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'criteria.section',
            'Unsupported criteria key "section".',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($validation),
        );
        $index = $this->index('config-provider-index', ProviderAvailabilityPluginElement::class);
        $index->source = 'config';

        $record = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $dependencies->getIndexCatalogue()['config-provider-index'],
        );
        $messages = array_column($record['findings'], 'message');

        self::assertSame('Unsupported criteria key "section".', $messages[0]);
        self::assertStringStartsWith('The element type "', $messages[1]);
        self::assertSame(1, substr_count(implode("\n", $messages), 'Unsupported criteria key "section".'));
        self::assertStringNotContainsString(
            sprintf('Element type "%s" is unavailable.', ProviderAvailabilityPluginElement::class),
            implode("\n", $messages),
        );
    }

    public function testDependencyFindingEscapesProviderMetadataAndUsesNoProviderSpecificBranch(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService(
            [ProviderAvailabilityPluginElement::class => 'unsafe-provider'],
            [],
            ['unsafe-provider' => '<script>alert("provider")</script> & Co.'],
        );
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $index = $this->index('unsafe-provider-index', ProviderAvailabilityPluginElement::class);

        $record = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $dependencies->getIndexCatalogue()['unsafe-provider-index'],
        );
        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_index-findings',
            ['findings' => $record['findings'], 'presentation' => 'box'],
            View::TEMPLATE_MODE_CP,
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;provider&quot;)&lt;/script&gt; &amp; Co.', $html);
        self::assertSame(1, substr_count($html, 'lr-info-box--error'));

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/DependencyService.php');
        self::assertIsString($source);
        foreach (['Docs Manager', 'SmartLink Manager', 'ShortLink Manager', 'Commerce'] as $provider) {
            self::assertStringNotContainsString($provider, $source);
        }
        self::assertStringContainsString('getComposerPluginInfo($handle)', $source);
        self::assertStringNotContainsString('getPlugin($handle)', $source);
    }

    public function testDependencyRecoveryMessagesExistAcrossAllLocalesWithExactPlaceholders(): void
    {
        $keys = [
            'The element type "{class}" belongs to the disabled plugin "{plugin}". Enable the plugin before rebuilding this index.',
            'The transformer "{class}" belongs to the disabled plugin "{plugin}". Enable the plugin before rebuilding this index.',
        ];
        $files = glob(dirname(__DIR__, 2) . '/src/translations/*/search-manager.php') ?: [];

        self::assertCount(12, $files);
        foreach ($files as $file) {
            $translations = require $file;
            self::assertIsArray($translations);
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $translations, $file);
                $value = (string)$translations[$key];
                self::assertNotSame('', trim($value), $file);
                self::assertSame(1, substr_count($value, '{class}'), $file);
                self::assertSame(1, substr_count($value, '{plugin}'), $file);
            }
        }
    }

    public function testDynamicTransformerRegistrationSurvivesServiceReinitialization(): void
    {
        $first = new TransformerService();
        $first->registerTransformer(
            ProviderAvailabilityDynamicElement::class,
            ProviderAvailabilityPluginTransformer::class,
        );
        $second = new TransformerService();

        self::assertSame(
            ProviderAvailabilityPluginTransformer::class,
            $second->resolveTransformerClassForElementTypeSilently(ProviderAvailabilityDynamicElement::class),
        );
    }

    public function testAllSitesSelectionExcludesDisabledAndExplicitIndices(): void
    {
        $allSitesA = $this->index('all-sites-a', Entry::class);
        $allSitesB = $this->index('all-sites-b', Entry::class);
        $disabled = $this->index('all-sites-disabled', Entry::class, false);
        $explicit = $this->index('explicit-site', Entry::class);
        $explicit->siteId = [(int)Craft::$app->getSites()->getPrimarySite()->id];

        $selected = $this->withOnlySearchIndices(
            [$allSitesA, $allSitesB, $disabled, $explicit],
            fn(): array => SearchManager::$plugin->dependencies->getEnabledAllSitesIndices(),
        );

        self::assertSame(['all-sites-a', 'all-sites-b'], array_map(
            static fn(SearchIndex $index): string => $index->handle,
            $selected,
        ));
    }

    public function testSiteCreateThenDeleteWhileOutstandingQueuesOneRequiredFollowUp(): void
    {
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);
        $allSitesA = $this->index('event-all-sites-a', Entry::class);
        $allSitesB = $this->index('event-all-sites-b', Entry::class);
        $disabled = $this->index('event-disabled', Entry::class, false);
        $explicit = $this->index('event-explicit', Entry::class);
        $explicit->siteId = [(int)Craft::$app->getSites()->getPrimarySite()->id];
        $method = new \ReflectionMethod(SearchManager::class, 'scheduleAllSitesReconciliation');
        $method->setAccessible(true);

        $this->withOnlySearchIndices(
            [$allSitesA, $allSitesB, $disabled, $explicit],
            static function() use ($method): void {
                $method->invoke(SearchManager::$plugin, 'site-created');
                $method->invoke(SearchManager::$plugin, 'site-deleted');
            },
        );

        self::assertSame(['event-all-sites-a', 'event-all-sites-b'], $indexing->jobHandles());
        self::assertSame(['active' => true, 'dirty' => true], $indexing->state('event-all-sites-a'));
        self::assertSame(['active' => true, 'dirty' => true], $indexing->state('event-all-sites-b'));

        $indexing->completeAffectedIndexRebuild('event-all-sites-a');
        $indexing->completeAffectedIndexRebuild('event-all-sites-b');

        self::assertSame([
            'event-all-sites-a',
            'event-all-sites-b',
            'event-all-sites-a',
            'event-all-sites-b',
        ], $indexing->jobHandles());
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('event-all-sites-a'));
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('event-all-sites-b'));
    }

    public function testAffectedSchedulerCoalescesRepeatedEventsThroughFollowUpsAndRelease(): void
    {
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $local = $this->index('local-all-sites', Entry::class);
        $hosted = $this->index('hosted-all-sites', Entry::class);
        $hosted->backend = 'hosted-backend';

        $this->withOnlySearchIndices([$local, $hosted], function() use ($indexing, $local, $hosted): void {
            self::assertSame(
                ['local-all-sites', 'hosted-all-sites'],
                $indexing->scheduleAffectedIndexRebuilds([$local, $hosted], 'site-created'),
            );
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$local, $hosted], 'site-created-burst'));
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$local, $hosted], 'site-created-burst'));
        });

        self::assertCount(2, $indexing->jobs);
        foreach ($indexing->jobs as $job) {
            self::assertInstanceOf(RebuildIndexJob::class, $job);
            self::assertTrue($job->releaseAffectedSchedule);
        }
        self::assertSame(['local-all-sites', 'hosted-all-sites'], array_map(
            static fn(RebuildIndexJob $job): ?string => $job->indexHandle,
            $indexing->jobs,
        ));
        self::assertSame(['active' => true, 'dirty' => true], $indexing->state('local-all-sites'));

        $indexing->completeAffectedIndexRebuild('local-all-sites');
        self::assertSame(
            ['local-all-sites', 'hosted-all-sites', 'local-all-sites'],
            $indexing->jobHandles(),
        );
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('local-all-sites'));

        $this->withOnlySearchIndices([$local], function() use ($indexing, $local): void {
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$local], 'during-follow-up'));
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$local], 'during-follow-up-burst'));
        });
        $indexing->completeAffectedIndexRebuild('local-all-sites');
        self::assertSame(
            ['local-all-sites', 'hosted-all-sites', 'local-all-sites', 'local-all-sites'],
            $indexing->jobHandles(),
        );
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('local-all-sites'));

        $indexing->completeAffectedIndexRebuild('local-all-sites');
        self::assertNull($indexing->state('local-all-sites'));

        $this->withOnlySearchIndices([$local], function() use ($indexing, $local): void {
            self::assertSame(
                ['local-all-sites'],
                $indexing->scheduleAffectedIndexRebuilds([$local], 'after-release'),
            );
        });
        self::assertCount(5, $indexing->jobs);
    }

    public function testCrossRequestCollisionUsesTheSameDirtyFollowUpContract(): void
    {
        $handle = 'cross-request-' . uniqid();
        $firstRequest = new ProviderAvailabilityCacheBackedIndexingService();
        $secondRequest = new ProviderAvailabilityCacheBackedIndexingService();
        $index = $this->index($handle, Entry::class);

        try {
            $this->withOnlySearchIndices([$index], function() use (
                $firstRequest,
                $secondRequest,
                $index,
                $handle,
            ): void {
                self::assertSame(
                    [$handle],
                    $firstRequest->scheduleAffectedIndexRebuilds([$index], 'site-created'),
                );
                self::assertSame(
                    [],
                    $secondRequest->scheduleAffectedIndexRebuilds([$index], 'site-deleted'),
                );
            });

            self::assertSame(['active' => true, 'dirty' => true], $secondRequest->state($handle));
            $firstRequest->completeAffectedIndexRebuild($handle);
            self::assertSame([$handle, $handle], $firstRequest->jobHandles());
            self::assertSame(['active' => true, 'dirty' => false], $secondRequest->state($handle));

            $secondRequest->completeAffectedIndexRebuild($handle);
            self::assertNull($firstRequest->state($handle));
        } finally {
            $firstRequest->resetState($handle);
        }
    }

    public function testProviderSelectionAndInvalidationTouchOnlyAffectedEnabledIndexCaches(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'custom-provider',
            ProviderAvailabilityPluginTransformer::class => 'custom-provider',
        ], []);
        $backend = new ProviderAvailabilityCacheBackend();
        $autocomplete = new ProviderAvailabilityCacheAutocomplete();
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $elementIndex = $this->index('affected-element', ProviderAvailabilityPluginElement::class);
        $transformerIndex = $this->index('affected-transformer', Entry::class);
        $transformerIndex->transformerClass = ProviderAvailabilityPluginTransformer::class;
        $disabled = $this->index('affected-disabled', ProviderAvailabilityPluginElement::class, false);
        $unaffected = $this->index('unaffected-core', Entry::class);

        $affected = $this->withOnlySearchIndices(
            [$elementIndex, $transformerIndex, $disabled, $unaffected],
            fn(): array => $dependencies->getEnabledIndicesForProvider('custom-provider'),
        );
        $dependencies->invalidateIndexCaches($affected);

        self::assertSame(['affected-element', 'affected-transformer'], array_map(
            static fn(SearchIndex $index): string => $index->handle,
            $affected,
        ));
        self::assertSame(['affected-element', 'affected-transformer'], $backend->cleared);
        self::assertSame(['affected-element', 'affected-transformer'], $autocomplete->cleared);
    }

    public function testProviderDisableFailsClosedAndEnableSchedulesOnlyAffectedEnabledIndices(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'docs-manager',
        ], []);
        $backend = new ProviderAvailabilityCacheBackend();
        $autocomplete = new ProviderAvailabilityCacheAutocomplete();
        $indexing = new ProviderAvailabilityLifecycleIndexingService();
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);

        $affected = $this->index('provider-affected', ProviderAvailabilityPluginElement::class);
        $disabled = $this->index('provider-disabled-index', ProviderAvailabilityPluginElement::class, false);
        $unaffected = $this->index('provider-unaffected', Entry::class);
        $provider = Craft::$app->getPlugins()->getPlugin('docs-manager');
        self::assertNotNull($provider);
        $event = new PluginEvent(['plugin' => $provider]);
        $method = new \ReflectionMethod(SearchManager::class, 'reconcileProviderAvailability');
        $method->setAccessible(true);

        $this->withOnlySearchIndices([$affected, $disabled, $unaffected], function() use ($event, $method): void {
            $method->invoke(SearchManager::$plugin, $event, false);
        });
        $this->withOnlySearchIndices([$affected, $disabled, $unaffected], function() use (
            $dependencies,
            $indexing,
        ): void {
            self::assertFalse($dependencies->isIndexAvailable('provider-affected'));
            self::assertSame([], $indexing->calls);
        });
        $dependencies->setEnabledProviders(['docs-manager']);
        $this->withOnlySearchIndices([$affected, $disabled, $unaffected], function() use ($event, $method): void {
            $method->invoke(SearchManager::$plugin, $event, true);
        });

        self::assertSame([
            ['reason' => 'provider-enabled', 'handles' => ['provider-affected']],
        ], $indexing->calls);
        self::assertSame(['provider-affected', 'provider-affected'], $backend->cleared);
        self::assertSame(['provider-affected', 'provider-affected'], $autocomplete->cleared);
    }

    public function testAvailabilityConsumersUseTheCanonicalDependencyContract(): void
    {
        $expectations = [
            'src/services/IndexingService.php' => 'dependencies->isIndexAvailable',
            'src/services/sync/PendingSyncProcessor.php' => 'dependencies->isIndexAvailable',
            'src/jobs/RebuildIndexJob.php' => 'dependencies->getIndexCatalogue',
            'src/services/BackendService.php' => 'dependencies->areIndexDependenciesAvailable',
            'src/services/AutocompleteService.php' => 'dependencies->areIndexDependenciesAvailable',
            'src/services/TransformerService.php' => 'dependencies->getClassAvailability',
            'src/models/SearchIndex.php' => 'dependencies->getIndexCatalogue',
            'src/gql/resolvers/SearchResolver.php' => 'dependencies->getIndexCatalogue',
            'src/controllers/SearchController.php' => 'dependencies->isIndexAvailable',
            'src/variables/BackendVariableProxy.php' => 'dependencies->areIndexDependenciesAvailable',
        ];

        foreach ($expectations as $file => $contractCall) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertIsString($source);
            self::assertTrue(
                str_contains($source, $contractCall),
                "{$file} must consume the canonical dependency contract.",
            );
        }
    }

    public function testDisabledProviderFailsClosedForSearchAutocompleteAndSharedResolution(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'custom-provider',
        ], []);
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $index = $this->index('consumer-affected', ProviderAvailabilityPluginElement::class);

        $this->withOnlySearchIndices([$index], function(): void {
            self::assertSame(
                [[], true, false],
                SearchIndex::resolveRequestedIndices('consumer-affected'),
            );
            self::assertSame(
                [],
                SearchManager::$plugin->backend->search('consumer-affected', 'query'),
            );
            self::assertSame(
                [],
                SearchManager::$plugin->autocomplete->suggest('query', 'consumer-affected'),
            );

            $record = SearchManager::$plugin->dependencies
                ->getIndexCatalogue(['consumer-affected'])['consumer-affected'];
            self::assertSame('error', $record['state']);
            self::assertFalse($record['available']);
            self::assertFalse($record['referenceable']);
        });
    }

    public function testPendingSyncDrainsUnavailableProviderRowsWithoutBackendDeletion(): void
    {
        $originalConfig = $this->configCache();
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'custom-provider',
        ], []);
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $backend = $this->installStubBackend();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        try {
            $this->withConfigFileIndices([
                'pending-provider-affected' => [
                    'name' => 'Pending Provider Affected',
                    'elementType' => ProviderAvailabilityPluginElement::class,
                    'siteId' => $siteId,
                    'enabled' => true,
                ],
            ]);
            $dependencies->clearIndexCatalogue();

            $result = $this->processor->process([
                [
                    'id' => 510001,
                    'indexHandle' => 'pending-provider-affected',
                    'elementType' => ProviderAvailabilityPluginElement::class,
                    'elementId' => 1001,
                    'siteId' => $siteId,
                    'op' => PendingSyncRepository::OP_UPSERT,
                ],
                [
                    'id' => 510002,
                    'indexHandle' => 'pending-provider-affected',
                    'elementType' => ProviderAvailabilityPluginElement::class,
                    'elementId' => 1002,
                    'siteId' => $siteId,
                    'op' => PendingSyncRepository::OP_DELETE,
                ],
            ]);

            self::assertSame([510001, 510002], $result['success']);
            self::assertSame([], $result['failures']);
            self::assertSame([], $result['syncedIndexHandles']);
            self::assertSame([], $backend->calls);
        } finally {
            $this->setConfigCache($originalConfig);
            SearchIndex::clearCache();
            $dependencies->clearIndexCatalogue();
        }
    }

    public function testRebuildPreflightPreservesStoredBackendWhenProviderIsUnavailable(): void
    {
        $originalConfig = $this->configCache();
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'custom-provider',
        ], []);
        $backend = new ProviderAvailabilityCacheBackend();
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        try {
            $this->withConfigFileIndices([
                'rebuild-provider-affected' => [
                    'name' => 'Rebuild Provider Affected',
                    'elementType' => ProviderAvailabilityPluginElement::class,
                    'siteId' => $siteId,
                    'enabled' => true,
                ],
            ]);
            $dependencies->clearIndexCatalogue();

            (new RebuildIndexJob([
                'indexHandle' => 'rebuild-provider-affected',
            ]))->execute(Craft::$app->getQueue());

            self::assertSame([], $backend->clearedIndices);
        } finally {
            $this->setConfigCache($originalConfig);
            SearchIndex::clearCache();
            $dependencies->clearIndexCatalogue();
        }
    }

    public function testRebuildFinallyAdvancesSchedulerAfterSuccessAndException(): void
    {
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);
        $successIndex = $this->index('successful-job', Entry::class);
        $failureIndex = $this->index('exception-job', Entry::class);

        $this->withOnlySearchIndices([$successIndex, $failureIndex], function() use (
            $indexing,
            $successIndex,
            $failureIndex,
        ): void {
            $indexing->scheduleAffectedIndexRebuilds([$successIndex], 'initial');
            $indexing->scheduleAffectedIndexRebuilds([$successIndex], 'collision');
            (new ProviderAvailabilityControllableRebuildIndexJob([
                'indexHandle' => 'successful-job',
                'releaseAffectedSchedule' => true,
            ]))->execute(Craft::$app->getQueue());

            $indexing->scheduleAffectedIndexRebuilds([$failureIndex], 'initial');
            $indexing->scheduleAffectedIndexRebuilds([$failureIndex], 'collision');
            try {
                (new ProviderAvailabilityControllableRebuildIndexJob([
                    'indexHandle' => 'exception-job',
                    'releaseAffectedSchedule' => true,
                    'fail' => true,
                ]))->execute(Craft::$app->getQueue());
                self::fail('Synthetic rebuild failure should escape the job.');
            } catch (\RuntimeException $e) {
                self::assertSame('Synthetic rebuild failure', $e->getMessage());
            }
        });

        self::assertSame([
            'successful-job',
            'successful-job',
            'exception-job',
            'exception-job',
        ], $indexing->jobHandles());
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('successful-job'));
        self::assertSame(['active' => true, 'dirty' => false], $indexing->state('exception-job'));
    }

    public function testInitialQueueFailureReleasesSchedulerStateForRetry(): void
    {
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $indexing->failOnPush(1);
        $index = $this->index('retry-all-sites', Entry::class);

        $this->withOnlySearchIndices([$index], function() use ($indexing, $index): void {
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$index], 'site-deleted'));
            self::assertNull($indexing->state('retry-all-sites'));
            self::assertSame(
                ['retry-all-sites'],
                $indexing->scheduleAffectedIndexRebuilds([$index], 'site-deleted-retry'),
            );
        });

        self::assertCount(1, $indexing->jobs);
        self::assertSame('retry-all-sites', $indexing->jobs[0]->indexHandle);
    }

    public function testFollowUpQueueFailureReleasesSchedulerStateForRetry(): void
    {
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $indexing->failOnPush(2);
        $index = $this->index('follow-up-retry', Entry::class);

        $this->withOnlySearchIndices([$index], function() use ($indexing, $index): void {
            self::assertSame(
                ['follow-up-retry'],
                $indexing->scheduleAffectedIndexRebuilds([$index], 'site-created'),
            );
            self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$index], 'site-deleted'));
            $indexing->completeAffectedIndexRebuild('follow-up-retry');
            self::assertNull($indexing->state('follow-up-retry'));
            self::assertSame(
                ['follow-up-retry'],
                $indexing->scheduleAffectedIndexRebuilds([$index], 'after-follow-up-failure'),
            );
        });

        self::assertSame(['follow-up-retry', 'follow-up-retry'], $indexing->jobHandles());
    }

    public function testProviderLifecycleCollisionsUseSharedSchedulerAndCoalesce(): void
    {
        $dependencies = new ProviderAvailabilityDependencyService([
            ProviderAvailabilityPluginElement::class => 'docs-manager',
        ], ['docs-manager']);
        $indexing = new ProviderAvailabilityRecordingIndexingService();
        $this->swapPluginComponent('search-manager', 'dependencies', $dependencies);
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);
        $affected = $this->index('provider-collision', ProviderAvailabilityPluginElement::class);
        $unaffected = $this->index('provider-collision-unaffected', Entry::class);
        $provider = Craft::$app->getPlugins()->getPlugin('docs-manager');
        self::assertNotNull($provider);
        $event = new PluginEvent(['plugin' => $provider]);
        $method = new \ReflectionMethod(SearchManager::class, 'reconcileProviderAvailability');
        $method->setAccessible(true);

        $this->withOnlySearchIndices([$affected, $unaffected], static function() use ($event, $method): void {
            $method->invoke(SearchManager::$plugin, $event, true);
        });
        $this->withOnlySearchIndices([$affected, $unaffected], static function() use ($event, $method): void {
            $method->invoke(SearchManager::$plugin, $event, true);
        });

        self::assertSame(['provider-collision'], $indexing->jobHandles());
        self::assertSame(['active' => true, 'dirty' => true], $indexing->state('provider-collision'));
        $indexing->completeAffectedIndexRebuild('provider-collision');
        self::assertSame(['provider-collision', 'provider-collision'], $indexing->jobHandles());
    }

    private function index(string $handle, string $elementType, bool $enabled = true): SearchIndex
    {
        return new SearchIndex([
            'name' => $handle,
            'handle' => $handle,
            'elementType' => $elementType,
            'siteId' => null,
            'source' => 'database',
            'enabled' => $enabled,
        ]);
    }
}

/**
 * @since 5.54.0
 */
class ProviderAvailabilityDependencyService extends DependencyService
{
    /**
     * @param array<class-string, string> $owners
     * @param list<string>                $enabledProviders
     */
    public function __construct(
        private readonly array $owners = [],
        private array $enabledProviders = [],
        private readonly array $providerNames = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }

    protected function providerHandleForClass(string $class): ?string
    {
        return $this->owners[$class] ?? null;
    }

    protected function isProviderEnabled(string $handle): bool
    {
        return in_array($handle, $this->enabledProviders, true);
    }

    protected function providerNameForHandle(string $handle): string
    {
        return $this->providerNames[$handle] ?? $handle;
    }

    /**
     * @param list<string> $handles
     */
    public function setEnabledProviders(array $handles): void
    {
        $this->enabledProviders = $handles;
        $this->clearIndexCatalogue();
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityRecordingIndexingService extends IndexingService
{
    /** @var list<RebuildIndexJob> */
    public array $jobs = [];

    /** @var array<string, array<string, array{active: true, dirty: bool}>> */
    private static array $states = [];

    /** @var list<int> */
    private array $failedPushes = [];
    private int $pushCount = 0;
    private readonly string $storeId;

    public function __construct(?string $storeId = null, array $config = [])
    {
        $this->storeId = $storeId ?? uniqid('scheduler-', true);
        parent::__construct($config);
    }

    public function failOnPush(int $pushNumber): void
    {
        $this->failedPushes[] = $pushNumber;
    }

    /**
     * @return array{active: true, dirty: bool}|null
     */
    public function state(string $indexHandle): ?array
    {
        return self::$states[$this->storeId][$indexHandle] ?? null;
    }

    /**
     * @return list<string>
     */
    public function jobHandles(): array
    {
        return array_map(
            static fn(RebuildIndexJob $job): string => (string)$job->indexHandle,
            $this->jobs,
        );
    }

    protected function withAffectedRebuildLock(string $indexHandle, callable $callback): mixed
    {
        return $callback();
    }

    protected function getAffectedRebuildState(string $indexHandle): ?array
    {
        return $this->state($indexHandle);
    }

    protected function setAffectedRebuildState(string $indexHandle, bool $dirty): void
    {
        self::$states[$this->storeId][$indexHandle] = ['active' => true, 'dirty' => $dirty];
    }

    protected function deleteAffectedRebuildState(string $indexHandle): void
    {
        unset(self::$states[$this->storeId][$indexHandle]);
    }

    protected function pushAffectedRebuildJob(RebuildIndexJob $job): string|int|null
    {
        $this->pushCount++;
        if (in_array($this->pushCount, $this->failedPushes, true)) {
            throw new \RuntimeException('Synthetic queue failure');
        }

        $this->jobs[] = $job;
        return count($this->jobs);
    }

    protected function isAffectedIndexAvailable(string $indexHandle): bool
    {
        return true;
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityCacheBackedIndexingService extends IndexingService
{
    /** @var list<RebuildIndexJob> */
    private array $jobs = [];

    /**
     * @return array{active: true, dirty: bool}|null
     */
    public function state(string $indexHandle): ?array
    {
        return $this->getAffectedRebuildState($indexHandle);
    }

    /**
     * @return list<string>
     */
    public function jobHandles(): array
    {
        return array_map(
            static fn(RebuildIndexJob $job): string => (string)$job->indexHandle,
            $this->jobs,
        );
    }

    public function resetState(string $indexHandle): void
    {
        $this->deleteAffectedRebuildState($indexHandle);
    }

    protected function pushAffectedRebuildJob(RebuildIndexJob $job): string|int|null
    {
        $this->jobs[] = $job;

        return count($this->jobs);
    }

    protected function isAffectedIndexAvailable(string $indexHandle): bool
    {
        return true;
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityControllableRebuildIndexJob extends RebuildIndexJob
{
    public bool $fail = false;

    protected function executeRebuild($queue): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Synthetic rebuild failure');
        }
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityLifecycleIndexingService extends IndexingService
{
    /** @var list<array{reason: string, handles: list<string>}> */
    public array $calls = [];

    public function scheduleAffectedIndexRebuilds(iterable $indices, string $reason): array
    {
        $handles = [];
        foreach ($indices as $index) {
            $handles[] = $index->handle;
        }
        $this->calls[] = ['reason' => $reason, 'handles' => $handles];

        return $handles;
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityCacheBackend extends BackendService
{
    /** @var list<string> */
    public array $cleared = [];
    /** @var list<string> */
    public array $clearedIndices = [];

    public function clearSearchCache(string $indexHandle): void
    {
        $this->cleared[] = $indexHandle;
    }

    public function clearIndex(string $indexHandle): bool
    {
        $this->clearedIndices[] = $indexHandle;

        return true;
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityCacheAutocomplete extends Component
{
    /** @var list<string> */
    public array $cleared = [];

    public function clearCache(string $indexHandle): void
    {
        $this->cleared[] = $indexHandle;
    }
}

/**
 * @since 5.54.0
 */
class ProviderAvailabilityProjectElement extends Entry
{
}

/**
 * @since 5.54.0
 */
class ProviderAvailabilityPluginElement extends Entry
{
}

/**
 * @since 5.54.0
 */
class ProviderAvailabilityDynamicElement extends Entry
{
}

/**
 * @since 5.54.0
 */
class ProviderAvailabilityPluginTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): array
    {
        return [];
    }

    public function supports(ElementInterface $element): bool
    {
        return true;
    }
}

/**
 * @since 5.54.0
 */
final class ProviderAvailabilityRequiredConstructorTransformer extends ProviderAvailabilityPluginTransformer
{
    public function __construct(public readonly string $required)
    {
    }
}
