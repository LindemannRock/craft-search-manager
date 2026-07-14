<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Db;
use craft\web\Controller;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\PluginThemeStyleHelper;
use lindemannrock\base\helpers\SettingsPostHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\CanonicalHitPipeline;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\helpers\TargetElementTypeHelper;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @since 5.0.0
 */
class SettingsController extends Controller
{
    use LoggingTrait;
    use ElementTypeGuardTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function actionIndex(): Response
    {
        return $this->actionGeneral();
    }

    /**
     * Setup checklist.
     *
     * @since 5.53.0
     */
    public function actionSetup(): Response
    {
        $this->requirePermission('searchManager:manageSettings');

        $plugin = SearchManager::$plugin;
        $settings = $plugin->getSettings();
        $iconSvg = PluginHelper::getIconSvg($plugin);
        $setupStatus = $plugin->setup->getStatus($settings);

        return $this->renderTemplate('search-manager/setup', [
            'settings' => $settings,
            'pluginVersion' => PluginHelper::getPluginVersion($plugin),
            'pluginIconSvg' => $iconSvg,
            'pluginHeroStyle' => PluginThemeStyleHelper::heroCssVarsFromSvg($iconSvg),
            'logoPaths' => PluginHelper::lrLogoPaths(),
            'ipSaltConfigured' => $setupStatus['ipSaltConfigured'],
        ]);
    }

    public function actionGeneral(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        // Load configured backends
        $backends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
        $enabledBackends = array_filter($backends, fn($b) => $b->enabled);

        // Load configured widgets
        $widgets = SearchManager::$plugin->widgetConfigs->getAll();
        $enabledWidgets = array_filter($widgets, fn($w) => $w->enabled);

        return $this->renderTemplate('search-manager/settings/general', [
            'settings' => $settings,
            'backends' => $backends,
            'enabledBackends' => $enabledBackends,
            'widgets' => $widgets,
            'enabledWidgets' => $enabledWidgets,
        ]);
    }

    /**
     * Redirect to general settings (backend settings consolidated)
     */
    public function actionBackend(): Response
    {
        return $this->redirect('search-manager/settings/general');
    }

    public function actionIndexing(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();
        $nativeSearchCoverage = SearchManager::$plugin->nativeSearchCoverage;

        return $this->renderTemplate('search-manager/settings/indexing', [
            'settings' => $settings,
            'nativeSearchCoverageReport' => $nativeSearchCoverage->getReport(),
            'nativeSearchHasLocalBackend' => $nativeSearchCoverage->hasLocalBackend(),
            'nativeSearchDefaultBackendIsLocal' => $nativeSearchCoverage->defaultBackendIsLocal(),
            'nativeSearchLocalBackendOptions' => $nativeSearchCoverage->getLocalBackendOptions(),
        ]);
    }

    public function actionAnalytics(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/analytics', [
            'settings' => $settings,
        ]);
    }

    public function actionSearch(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/search', [
            'settings' => $settings,
        ]);
    }

    public function actionLanguage(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/language', [
            'settings' => $settings,
        ]);
    }

    public function actionHighlighting(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/highlighting', [
            'settings' => $settings,
        ]);
    }

    public function actionCache(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/cache', [
            'settings' => $settings,
        ]);
    }

    public function actionInterface(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/settings/interface', [
            'settings' => $settings,
        ]);
    }

    /**
     * Redirect to general settings (widget settings consolidated)
     *
     * @since 5.30.0
     */
    public function actionWidget(): Response
    {
        return $this->redirect('search-manager/settings/general');
    }

    public function actionTest(): Response
    {
        $this->requirePermission('searchManager:manageSettings');

        $settings = SearchManager::$plugin->getSettings();

        // Get all configured backends for the backend selector
        $backends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();

        return $this->renderTemplate('search-manager/settings/test', [
            'settings' => $settings,
            'cacheEnabled' => $settings->enableCache ?? true,
            'backends' => $backends,
            'snippetOptions' => SnippetOptionsHelper::widgetDefaults(),
        ]);
    }

    public function actionDownloadPostmanCollection(): Response
    {
        $this->requirePermission('searchManager:manageSettings');

        $postmanPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'postman';
        $files = [];

        foreach ([
            'Search-Manager.postman_collection.json',
            'Search-Manager.postman_environment.json',
            'README.md',
        ] as $filename) {
            $path = $postmanPath . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $files[$filename] = $content;
                }
            }
        }

        return ExportHelper::toZip($files, 'search-manager-postman.zip');
    }

    public function actionTestSearch(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');
        $wildcard = Craft::$app->getRequest()->getBodyParam('wildcard', false);
        $liveComparison = (bool) Craft::$app->getRequest()->getBodyParam('liveComparison', false);

        try {
            $request = Craft::$app->getRequest();
            // Get the index
            $index = SearchIndex::findByHandle($indexHandle);

            $originalQuery = $query;

            // Respect the index's resolved site scope for scoping.
            $searchOptions = [];
            $indexSiteIds = $index ? $index->getSiteIds() : null;
            if ($indexSiteIds !== null) {
                $searchOptions['siteId'] = count($indexSiteIds) === 1 ? $indexSiteIds[0] : $indexSiteIds;
            }
            $includeQueryRuleDebug = (bool)Craft::$app->getRequest()->getBodyParam('includeQueryRuleDebug', false);
            if ($includeQueryRuleDebug) {
                $searchOptions['includeQueryRuleDebug'] = true;
            }

            // Add wildcard support (auto-append * if enabled and no wildcard present)
            if ($wildcard && !str_contains($query, '*')) {
                // For testing: add * to each term to enable prefix matching
                $query = implode('* ', explode(' ', $query)) . '*';
            }

            $startTime = microtime(true);
            $results = SearchManager::$plugin->backend->search($indexHandle, $query, $searchOptions);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Get the actual backend used for this index (not the default)
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);
            $backendName = $backend ? $backend->getName() : 'unknown';

            // Check if result was actually cached from metadata
            $cached = (bool)($results['meta']['cached'] ?? false);
            $cacheStatus = $includeQueryRuleDebug
                ? 'bypassed'
                : ($cached ? 'hit' : 'miss');
            $cacheDriver = $results['meta']['cacheDriver'] ?? null;

            // Get settings for highlighting and cache info
            $settings = SearchManager::$plugin->getSettings();
            $includeDebugMeta = (bool) $request->getBodyParam('includeDebugMeta', false);
            $indexedDocumentDebug = $includeDebugMeta
                ? $this->settingsTestIndexedDocumentDebugByIdentity($results['hits'] ?? [], $index)
                : [];

            $enhancedHits = CanonicalHitPipeline::presentHits($results['hits'] ?? [], $originalQuery, [$indexHandle], [
                'snippetMode' => (string) $request->getBodyParam('snippetMode', SnippetOptionsHelper::DEFAULT_MODE),
                'snippetMaxLength' => (int) $request->getBodyParam('snippetMaxLength', SnippetOptionsHelper::DEFAULT_LENGTH),
                'snippetIncludeCodeBlocks' => (bool) $request->getBodyParam('snippetIncludeCodeBlocks', SnippetOptionsHelper::DEFAULT_SHOW_CODE),
                'snippetCleanMarkdown' => (bool) $request->getBodyParam('snippetCleanMarkdown', SnippetOptionsHelper::DEFAULT_PARSE_MARKDOWN),
                'resultsRequireUrl' => (bool) $request->getBodyParam('resultsRequireUrl', false),
                'includeSnippetDebug' => $includeDebugMeta,
                'retrievableFieldsByIndex' => SearchIndex::retrievableFieldsByIndex([$indexHandle]),
            ], $includeQueryRuleDebug);

            if ($includeDebugMeta) {
                foreach ($enhancedHits as &$hit) {
                    $debugKey = $this->settingsTestHitDebugKey($hit);
                    if ($debugKey !== null && isset($indexedDocumentDebug[$debugKey])) {
                        $hit['_indexedDocument'] = $indexedDocumentDebug[$debugKey];
                    }
                }
                unset($hit);
            }

            if ($liveComparison) {
                $enhancedHits = SearchManager::$plugin->liveComparison->compareHits($enhancedHits, [$indexHandle], [
                    'siteId' => $searchOptions['siteId'] ?? null,
                ]);
            }

            return $this->asJson([
                'success' => true,
                'total' => $results['total'] ?? 0,
                'hits' => $enhancedHits,
                'backend' => $backendName,
                'executionTime' => $executionTime,
                'cacheEnabled' => $settings->enableCache ?? false,
                'cacheStatus' => $cacheStatus,
                'cacheDriver' => $cacheDriver,
                'wildcard' => $wildcard,
                'queryUsed' => $query,
                'originalQuery' => $originalQuery,
                'enriched' => $liveComparison,
                'liveComparison' => $liveComparison,
                'indexSiteId' => $index->siteId ?? null,
                'redirect' => $results['redirect'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Test search failed', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Search test failed. Check logs for details.'),
            ]);
        }
    }

    /**
     * @param array<int, mixed> $hits
     * @return array<string, array<string, mixed>>
     */
    private function settingsTestIndexedDocumentDebugByIdentity(array $hits, ?SearchIndex $index): array
    {
        $debugByIdentity = [];

        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $debugKey = $this->settingsTestHitDebugKey($hit);
            if ($debugKey === null) {
                continue;
            }

            $debug = $this->settingsTestIndexedDocumentDebug($hit, $index);
            if (!empty($debug)) {
                $debugByIdentity[$debugKey] = $debug;
            }
        }

        return $debugByIdentity;
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function settingsTestIndexedDocumentDebug(array $hit, ?SearchIndex $index): array
    {
        $debug = [];
        $transformerClass = trim((string)($index?->transformerClass ?? ''));
        if ($transformerClass !== '') {
            $debug['transformerClass'] = $transformerClass;
        } elseif ($this->settingsTestHitLooksLikeCommerceDocument($hit)) {
            $debug['transformerClass'] = CommerceTransformer::class;
        }

        if (!empty($index?->elementType)) {
            $debug['indexElementType'] = $index->elementType;
        }

        $documentKey = SearchHitIdentityHelper::documentId($hit);
        if ($documentKey !== null) {
            $debug['documentKey'] = $documentKey;
        }

        $documentType = $this->settingsTestScalarDebugValue($hit['type'] ?? null);
        if ($documentType !== null) {
            $debug['documentType'] = $documentType;
        }

        $elementKind = $this->settingsTestElementKindDebug($hit);
        if (!empty($elementKind)) {
            $debug['elementKind'] = $elementKind;
        }

        $commerce = $this->settingsTestCommerceDebug($hit);
        if (!empty($commerce)) {
            $debug['commerce'] = $commerce;
        }

        $customFields = $this->settingsTestCustomIndexedFields($hit, $this->settingsTestDebugElement($hit, $index));
        if (!empty($customFields)) {
            $debug['customFields'] = $customFields;
        }

        return $debug;
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function settingsTestElementKindDebug(array $hit): array
    {
        $type = strtolower((string)($hit['type'] ?? ''));

        if ($type === 'entry') {
            return $this->settingsTestFilterElementKindDebug([
                'entrySection' => $this->settingsTestScalarDebugValue($hit['entrySection'] ?? null),
                'entrySectionHandle' => $this->settingsTestScalarDebugValue($hit['entrySectionHandle'] ?? null),
                'entrySectionType' => $this->settingsTestScalarDebugValue($hit['entrySectionType'] ?? null),
                'ancestors' => $this->settingsTestAncestorsDebugValue($hit['ancestors'] ?? null),
                'level' => $this->settingsTestIntegerDebugValue($hit['level'] ?? null),
            ]);
        }

        if ($type === 'asset') {
            return $this->settingsTestFilterElementKindDebug([
                'volume' => $this->settingsTestScalarDebugValue($hit['volume'] ?? null),
                'volumeHandle' => $this->settingsTestScalarDebugValue($hit['volumeHandle'] ?? null),
                'ancestors' => $this->settingsTestAncestorsDebugValue($hit['ancestors'] ?? null),
                'folderPath' => $this->settingsTestScalarDebugValue($hit['folderPath'] ?? null),
            ]);
        }

        if ($type === 'category') {
            return $this->settingsTestFilterElementKindDebug([
                'categoryGroup' => $this->settingsTestScalarDebugValue($hit['categoryGroup'] ?? null),
                'categoryGroupHandle' => $this->settingsTestScalarDebugValue($hit['categoryGroupHandle'] ?? null),
                'ancestors' => $this->settingsTestAncestorsDebugValue($hit['ancestors'] ?? null),
                'level' => $this->settingsTestIntegerDebugValue($hit['level'] ?? null),
            ]);
        }

        return [];
    }

    private function settingsTestIntegerDebugValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function settingsTestAncestorsDebugValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ancestors = [];
        foreach ($value as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            $id = $ancestor['id'] ?? null;
            $title = $this->settingsTestScalarDebugValue($ancestor['title'] ?? null);
            if (!is_numeric($id) || $title === null) {
                continue;
            }

            $ancestors[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $ancestors;
    }

    /**
     * @param array<string, mixed> $debug
     * @return array<string, mixed>
     */
    private function settingsTestFilterElementKindDebug(array $debug): array
    {
        return array_filter($debug, static function(mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return !is_array($value) || $value !== [];
        });
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function settingsTestCommerceDebug(array $hit): array
    {
        $commerce = [];
        $productTypeDisplayName = $this->settingsTestScalarDebugValue($hit['productType'] ?? null);
        $productTypeHandle = $this->settingsTestScalarDebugValue($hit['productTypeHandle'] ?? null);
        if ($productTypeDisplayName !== null || $productTypeHandle !== null) {
            $commerce['productType'] = array_filter([
                'name' => $productTypeDisplayName,
                'handle' => $productTypeHandle,
            ], static fn(?string $value): bool => $value !== null);
        }

        foreach ([
            'variantSkus' => 'variantSkus',
            'variantOptions' => 'variantOptions',
        ] as $sourceKey => $targetKey) {
            $values = $this->settingsTestListDebugValue($hit[$sourceKey] ?? null);
            if (!empty($values)) {
                $commerce[$targetKey] = $values;
            }
        }

        $parentProduct = array_filter([
            'title' => $this->settingsTestScalarDebugValue($hit['productTitle'] ?? null),
            'slug' => $this->settingsTestScalarDebugValue($hit['productSlug'] ?? null),
            'url' => $this->settingsTestScalarDebugValue($hit['productUrl'] ?? null),
        ], static fn(?string $value): bool => $value !== null);
        if (!empty($parentProduct)) {
            $commerce['parentProduct'] = $parentProduct;
        }

        return $commerce;
    }

    /**
     * @param array<string, mixed> $hit
     * @return list<array{label: string, value?: string, children?: list<array{label: string, value: string}>}>
     */
    private function settingsTestCustomIndexedFields(array $hit, ?ElementInterface $element = null): array
    {
        $fields = [];
        $layoutFields = $this->settingsTestLayoutFieldsByHandle($element);
        foreach (SearchFieldValueHelper::fieldsFromHit($hit) as $key => $value) {
            if (!$this->settingsTestCanShowCustomIndexedField((string)$key)) {
                continue;
            }

            $layoutField = $layoutFields[(string)$key] ?? null;
            $displayValue = is_array($value)
                ? implode(', ', $this->settingsTestListDebugValue($value, 6))
                : $this->settingsTestScalarDebugValue($value);

            if ($displayValue === null || $displayValue === '') {
                continue;
            }

            $nestedFields = $layoutField instanceof Field
                ? $this->settingsTestNestedCustomIndexedFields($layoutField, $element)
                : [];

            if (!empty($nestedFields)) {
                $fields[] = [
                    'label' => $this->settingsTestDebugFieldLabel($layoutField, (string)$key),
                    'children' => $nestedFields,
                ];
            } else {
                $fields[] = [
                    'label' => $this->settingsTestDebugFieldLabel($layoutField, (string)$key),
                    'value' => $this->settingsTestTruncateDebugValue($displayValue, 140),
                ];
            }

            if (count($fields) >= 6) {
                break;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function settingsTestDebugElement(array $hit, ?SearchIndex $index): ?ElementInterface
    {
        $elementId = SearchHitIdentityHelper::elementId($hit);
        if ($elementId === null) {
            return null;
        }

        $elementType = $index->elementType ?? \craft\elements\Entry::class;
        if (!$this->isElementTypeAvailable($elementType, 'settings-test-debug')) {
            return null;
        }

        $siteId = isset($hit['siteId']) && is_numeric($hit['siteId'])
            ? (int)$hit['siteId']
            : ($index?->getSiteIds()[0] ?? Craft::$app->getSites()->getCurrentSite()->id);

        try {
            return $elementType::find()
                ->id($elementId)
                ->siteId($siteId)
                ->status(null)
                ->one();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, Field>
     */
    private function settingsTestLayoutFieldsByHandle(?ElementInterface $element): array
    {
        $layout = $element?->getFieldLayout();
        if ($layout === null) {
            return [];
        }

        $fields = [];
        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof Field && $field->handle !== null && $field->handle !== '') {
                $fields[$field->handle] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function settingsTestNestedCustomIndexedFields(Field $field, ?ElementInterface $element, int $depth = 0): array
    {
        if ($element === null || $field->handle === null || $field->handle === '' || !$this->settingsTestCanShowNestedIndexedFieldGroup($field) || $depth > 1) {
            return [];
        }

        $value = $element->getFieldValue($field->handle);
        $nestedElements = $this->settingsTestNestedDebugElements($value);
        if (empty($nestedElements)) {
            return [];
        }

        $children = [];
        foreach ($nestedElements as $nestedElement) {
            foreach ($this->settingsTestLayoutFieldsByHandle($nestedElement) as $nestedField) {
                if (!$nestedField->searchable || $nestedField->handle === null || !$this->settingsTestCanShowCustomIndexedField($nestedField->handle)) {
                    continue;
                }

                try {
                    $keywords = $this->settingsTestScalarDebugValue($nestedField->getSearchKeywords(
                        $nestedElement->getFieldValue($nestedField->handle),
                        $nestedElement,
                    ));
                } catch (\Throwable) {
                    continue;
                }
                if ($keywords === null || $keywords === '') {
                    continue;
                }

                $children[] = [
                    'label' => $this->settingsTestDebugFieldLabel($nestedField, $nestedField->handle),
                    'value' => $this->settingsTestTruncateDebugValue($keywords, 140),
                ];

                if (count($children) >= 12) {
                    return $children;
                }
            }
        }

        return $children;
    }

    private function settingsTestCanShowNestedIndexedFieldGroup(Field $field): bool
    {
        return is_a($field, 'craft\\fields\\Matrix') || is_a($field, 'craft\\fields\\ContentBlock');
    }

    /**
     * @return list<ElementInterface>
     */
    private function settingsTestNestedDebugElements(mixed $value): array
    {
        if ($value instanceof ElementInterface) {
            return [$value];
        }

        if (is_object($value) && method_exists($value, 'all')) {
            $value = $value->all();
        }

        if (!is_iterable($value)) {
            return [];
        }

        $elements = [];
        foreach ($value as $item) {
            if ($item instanceof ElementInterface) {
                $elements[] = $item;
            }
            if (count($elements) >= 12) {
                break;
            }
        }

        return $elements;
    }

    private function settingsTestCanShowCustomIndexedField(string $key): bool
    {
        $normalized = strtolower($key);
        if ($key === '' || str_starts_with($key, '_')) {
            return false;
        }
        if (preg_match('/(?:apikey|api_key|token|secret|password|authorization)/i', $key) === 1) {
            return false;
        }

        return !in_array($normalized, [
            'id',
            'elementid',
            'objectid',
            'backendid',
            'title',
            'url',
            'description',
            'descriptionsafe',
            'content',
            'body',
            'excerpt',
            'score',
            'matchedterms',
            'matchedphrases',
            'matchedin',
            'site',
            'sitename',
            'siteid',
            'sitehandle',
            'language',
            'elementtype',
            'type',
            'source',
            'entrysection',
            'entrysectionhandle',
            'entrysectiontype',
            'sectiontype',
            'sectionid',
            'sectiontitle',
            'sectionlevel',
            'sectionanchor',
            'sectionurl',
            'sectionindex',
            'volume',
            'volumehandle',
            'categorygroup',
            'categorygrouphandle',
            'categoryids',
            'backend',
            'datecreated',
            'dateupdated',
            'promoted',
            'boosted',
            'position',
            'doccategory',
            'slug',
            'producttype',
            'producttypehandle',
            'variantskus',
            'varianttitles',
            'variantoptions',
            'defaultvariantsku',
            'defaultvarianttitle',
            'sku',
            'varianttitle',
            'productid',
            'producttitle',
            'productslug',
            'producturl',
            'searchtext',
        ], true);
    }

    /**
     * @param mixed $value
     */
    private function settingsTestScalarDebugValue(mixed $value): ?string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            $string = trim((string)$value);

            return $string !== '' ? $this->settingsTestTruncateDebugValue($string, 180) : null;
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function settingsTestListDebugValue(mixed $value, int $limit = 10): array
    {
        if (!is_array($value)) {
            $scalar = $this->settingsTestScalarDebugValue($value);

            return $scalar !== null ? [$scalar] : [];
        }

        $values = [];
        foreach ($value as $item) {
            $scalar = $this->settingsTestScalarDebugValue($item);
            if ($scalar !== null) {
                $values[] = $scalar;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return array_values(array_unique($values));
    }

    private function settingsTestTruncateDebugValue(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit - 3) . '...' : $value;
    }

    private function settingsTestHumanizeDebugField(string $key): string
    {
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', $key);
        $label = str_replace(['_', '-'], ' ', (string)$label);
        $label = preg_replace('/\s+/', ' ', (string)$label);

        return ucwords(trim((string)$label));
    }

    private function settingsTestDebugFieldLabel(?Field $field, string $fallbackKey): string
    {
        $label = trim((string)($field?->name ?? ''));

        return $label !== '' ? $label : $this->settingsTestHumanizeDebugField($fallbackKey);
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function settingsTestHitDebugKey(array $hit): ?string
    {
        $elementId = SearchHitIdentityHelper::elementId($hit);
        if ($elementId === null) {
            return null;
        }

        return (string)($hit['siteId'] ?? 'site') . ':' . $elementId;
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function settingsTestHitLooksLikeCommerceDocument(array $hit): bool
    {
        if (isset($hit['productType'], $hit['variantOptions']) || isset($hit['productTypeHandle'])) {
            return true;
        }

        $type = (string)($hit['type'] ?? '');

        return in_array($type, ['product', 'variant', CommerceElementTypeHelper::productElementType(), CommerceElementTypeHelper::variantElementType()], true);
    }

    public function actionTestAutocomplete(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;

        try {
            $options = [
                'includeMeta' => true,
            ];
            if ($siteId !== null) {
                $options['siteId'] = $siteId;
            }

            $result = SearchManager::$plugin->autocomplete->suggest($query, $indexHandle, $options);

            // When includeMeta is true, suggest() returns array with suggestions + meta
            $suggestions = $result['suggestions'] ?? [];
            $meta = $result['meta'] ?? [];

            return $this->asJson([
                'success' => true,
                'suggestions' => $suggestions,
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Test autocomplete failed', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Autocomplete test failed. Check logs for details.'),
            ]);
        }
    }

    public function actionClearTestCache(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $indexHandle = Craft::$app->getRequest()->getBodyParam('indexHandle');

        try {
            if ($indexHandle) {
                // Clear cache for specific index
                SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                $message = Craft::t('search-manager', 'Search cache cleared for index: {handle}', ['handle' => $indexHandle]);
            } else {
                // Clear all search caches (only search-manager's, not all of Craft)
                SearchManager::$plugin->backend->clearAllSearchCache();
                $message = Craft::t('search-manager', 'All search caches cleared');
            }

            $this->logInfo('Test page cache cleared', ['indexHandle' => $indexHandle ?: 'all']);

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear test cache', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to clear cache. Check logs for details.'),
            ]);
        }
    }

    /**
     * Test which promotions match a query
     *
     * @since 5.10.0
     */
    public function actionTestPromotions(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getRequiredBodyParam('indexHandle');

        try {
            // CP Test: Get ALL promotions that match the query pattern (ignoring element status)
            // This shows all promotions for testing, with status info per site
            $allPromotions = \lindemannrock\searchmanager\models\Promotion::findByIndex($indexHandle);

            $promotions = [];
            $matchingPromotions = [];
            foreach ($allPromotions as $promotion) {
                // Check if query pattern matches
                if (!$promotion->matches(mb_strtolower(trim($query)))) {
                    continue;
                }

                $matchingPromotions[] = $promotion;
            }

            $sites = Craft::$app->getSites()->getAllSites();
            $promotionElements = $this->preloadTestPromotionElements($matchingPromotions);
            $promotionLiveElements = $this->preloadTestPromotionLiveElements($matchingPromotions, $promotionElements, $sites);

            foreach ($matchingPromotions as $promotion) {
                $element = $promotionElements[$promotion->id ?? 0] ?? null;

                // Get element status per site for display
                $siteStatuses = [];
                if ($element) {
                    $elementClass = $promotion->elementType ?? get_class($element);
                    if (!SearchElementAvailabilityHelper::isSiteIndependent($elementClass)) {
                        foreach ($sites as $site) {
                            $cacheKey = $this->elementCacheKey($elementClass, $site->id, (int)$promotion->elementId);
                            $siteStatuses[] = [
                                'siteId' => $site->id,
                                'siteName' => $site->name,
                                'isLive' => isset($promotionLiveElements[$cacheKey]),
                            ];
                        }
                    }
                }

                $promotions[] = [
                    'id' => $promotion->id,
                    'query' => $promotion->query,
                    'matchType' => $promotion->matchType,
                    'position' => $promotion->position,
                    'elementId' => $promotion->elementId,
                    'elementTitle' => $element ? $this->elementDisplayTitle($element) : Craft::t('search-manager', 'Element not found'),
                    'elementEditUrl' => $element ? $element->getCpEditUrl() : '#',
                    'elementType' => $promotion->elementType,
                    'elementTypeLabel' => $this->promotionElementTypeLabel($promotion->elementType, $element),
                    'enabled' => $promotion->enabled,
                    'siteIndependent' => $element ? SearchElementAvailabilityHelper::isSiteIndependent($promotion->elementType ?? get_class($element)) : false,
                    'siteStatuses' => $siteStatuses,
                ];
            }

            return $this->asJson([
                'success' => true,
                'promotions' => $promotions,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Test promotions failed', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Promotions test failed. Check logs for details.'),
            ]);
        }
    }

    /**
     * Test which query rules match a query
     *
     * @since 5.10.0
     */
    public function actionTestQueryRules(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $query = Craft::$app->getRequest()->getRequiredBodyParam('query');
        $indexHandle = Craft::$app->getRequest()->getBodyParam('indexHandle');

        try {
            // Get matching rules
            $matchingRules = \lindemannrock\searchmanager\models\QueryRule::findMatching($query, $indexHandle);

            $rules = [];
            $redirect = null;
            $synonyms = [$query]; // Start with original query
            $redirectElements = $this->preloadTestQueryRuleRedirectElements($matchingRules);

            foreach ($matchingRules as $rule) {
                $elementInfo = null;
                $targetElementId = null;
                $targetElementType = null;
                $targetSectionHandle = null;
                $targetCategoryId = null;
                $targetCategoryHandle = null;

                switch ($rule->actionType) {
                    case QueryRule::ACTION_SYNONYM:
                        $effectDescription = $rule->getActionDescription();
                        $terms = $rule->getSynonyms();
                        $synonyms = array_merge($synonyms, $terms);
                        break;

                    case QueryRule::ACTION_BOOST_SECTION:
                        $effectDescription = $rule->getActionDescription();
                        if (isset($rule->actionValue['sectionHandle']) && is_string($rule->actionValue['sectionHandle'])) {
                            $targetSectionHandle = $rule->actionValue['sectionHandle'];
                        }
                        break;

                    case QueryRule::ACTION_BOOST_CATEGORY:
                        $effectDescription = $rule->getActionDescription();
                        if (isset($rule->actionValue['categoryId']) && is_numeric($rule->actionValue['categoryId'])) {
                            $targetCategoryId = (int)$rule->actionValue['categoryId'];
                        }
                        if (isset($rule->actionValue['categoryHandle']) && is_string($rule->actionValue['categoryHandle'])) {
                            $targetCategoryHandle = $rule->actionValue['categoryHandle'];
                        }
                        break;

                    case QueryRule::ACTION_BOOST_ELEMENT:
                        $effectDescription = $rule->getActionDescription();
                        if (isset($rule->actionValue['elementId']) && is_numeric($rule->actionValue['elementId'])) {
                            $targetElementId = (int)$rule->actionValue['elementId'];
                        }
                        if (isset($rule->actionValue['elementType']) && is_string($rule->actionValue['elementType'])) {
                            $targetElementType = $rule->actionValue['elementType'];
                        }
                        break;

                    case QueryRule::ACTION_REDIRECT:
                        $redirect = $rule->getRedirectUrl();
                        $effectDescription = $rule->getActionDescription($redirect);
                        if (!empty($rule->actionValue['elementId']) && !empty($rule->actionValue['elementType'])) {
                            $elementType = (string)$rule->actionValue['elementType'];
                            $targetElementId = (int)$rule->actionValue['elementId'];
                            $targetElementType = $elementType;
                            $element = $redirectElements[$this->elementCacheKey($elementType, null, (int)$rule->actionValue['elementId'])] ?? null;
                            if ($element) {
                                $elementInfo = [
                                    'id' => $element->id,
                                    'title' => $element->title ?? Craft::t('search-manager', 'Untitled'),
                                    'type' => (new \ReflectionClass($element))->getShortName(),
                                    'url' => $element->getUrl(),
                                    'cpEditUrl' => $element->getCpEditUrl(),
                                ];
                            }
                        }
                        break;

                    default:
                        $effectDescription = $rule->getActionDescription();
                        break;
                }

                $rules[] = [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'actionType' => $rule->actionType,
                    'matchType' => $rule->matchType,
                    'matchValue' => $rule->matchValue,
                    'effectDescription' => $effectDescription,
                    'elementInfo' => $elementInfo ?? null,
                    'targetElementId' => $targetElementId,
                    'targetElementType' => $targetElementType,
                    'targetSectionHandle' => $targetSectionHandle,
                    'targetCategoryId' => $targetCategoryId,
                    'targetCategoryHandle' => $targetCategoryHandle,
                    'editUrl' => Craft::$app->getUrlManager()->createUrl('search-manager/query-rules/edit/' . $rule->id),
                ];
            }

            return $this->asJson([
                'success' => true,
                'rules' => $rules,
                'redirect' => $redirect,
                'synonyms' => array_unique($synonyms),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Test query rules failed', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Query rules test failed. Check logs for details.'),
            ]);
        }
    }

    public function actionSave(): ?Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();

        $section = $this->_validSettingsSection(Craft::$app->getRequest()->getBodyParam('section', 'general'));
        $attributesToValidate = $this->_validationAttributesForSection($section);

        $settings = Settings::loadFromDatabase();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);

        // Convert ngramSizes array to comma-separated string
        if (isset($postedSettings['ngramSizes'])) {
            if (is_array($postedSettings['ngramSizes'])) {
                $postedSettings['ngramSizes'] = !empty($postedSettings['ngramSizes'])
                    ? implode(',', $postedSettings['ngramSizes'])
                    : ''; // Empty array = disable fuzzy
            }
        }

        $result = SettingsPostHelper::apply(
            model: $settings,
            postedValues: $postedSettings,
            allowedAttributes: $attributesToValidate,
            shouldSkipAttribute: fn(string $attribute): bool => $settings->isOverriddenByConfig($attribute),
        );
        $attributesToValidate = $result->attributesToValidate;

        if ($result->hasErrors || !$settings->validate($attributesToValidate)) {
            $this->logError('Settings validation failed', ['errors' => $settings->getErrors()]);
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings'));
            return $this->_renderSettingsTemplate($section, $settings);
        }

        if (!$settings->saveToDatabase($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save settings'));
            return $this->_renderSettingsTemplate($section, $settings);
        }

        SearchManager::$plugin->backend->clearAllSearchCache();
        SearchManager::$plugin->autocomplete->clearCache();
        SearchManager::$plugin->deviceDetection->clearCache();

        $this->logInfo('Settings saved successfully');
        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Settings saved'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @param array<int, \lindemannrock\searchmanager\models\Promotion> $promotions
     * @return array<int, ElementInterface>
     */
    private function preloadTestPromotionElements(array $promotions): array
    {
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id ?? null;
        $idsByTypeAndSite = [];
        $fallbackPromotions = [];

        foreach ($promotions as $promotion) {
            if ($promotion->id === null || $promotion->elementId === null) {
                continue;
            }

            $elementClass = $promotion->elementType;
            if (!$this->isElementClass($elementClass)) {
                $fallbackPromotions[] = $promotion;
                continue;
            }

            $siteId = $promotion->siteId ?? $currentSiteId;
            $groupKey = $elementClass . ':' . ($siteId ?? 'default');
            $idsByTypeAndSite[$groupKey]['class'] = $elementClass;
            $idsByTypeAndSite[$groupKey]['siteId'] = $siteId;
            $idsByTypeAndSite[$groupKey]['ids'][(int)$promotion->elementId] = (int)$promotion->elementId;
        }

        $elementsByLookup = [];
        foreach ($idsByTypeAndSite as $group) {
            /** @var class-string<ElementInterface> $elementClass */
            $elementClass = $group['class'];
            $query = $elementClass::find()
                ->id(array_values($group['ids']))
                ->status(null)
                ->indexBy('id');

            if ($group['siteId'] !== null) {
                $query->siteId($group['siteId']);
            }

            foreach ($query->all() as $element) {
                if ($element instanceof ElementInterface) {
                    $elementsByLookup[$this->elementCacheKey($elementClass, $group['siteId'], (int)$element->id)] = $element;
                }
            }
        }

        $elementsByPromotionId = [];
        foreach ($promotions as $promotion) {
            if ($promotion->id === null || $promotion->elementId === null) {
                continue;
            }

            $elementClass = $promotion->elementType;
            $siteId = $promotion->siteId ?? $currentSiteId;
            if ($this->isElementClass($elementClass)) {
                $element = $elementsByLookup[$this->elementCacheKey($elementClass, $siteId, (int)$promotion->elementId)] ?? null;
                if ($element instanceof ElementInterface) {
                    $elementsByPromotionId[$promotion->id] = $element;
                }
            }
        }

        foreach ($fallbackPromotions as $promotion) {
            if ($promotion->id === null) {
                continue;
            }
            $element = $promotion->getElement();
            if ($element instanceof ElementInterface) {
                $elementsByPromotionId[$promotion->id] = $element;
            }
        }

        return $elementsByPromotionId;
    }

    /**
     * @param array<int, \lindemannrock\searchmanager\models\Promotion> $promotions
     * @param array<int, ElementInterface> $promotionElements
     * @param array<int, \craft\models\Site> $sites
     * @return array<string, ElementInterface>
     */
    private function preloadTestPromotionLiveElements(array $promotions, array $promotionElements, array $sites): array
    {
        $idsByTypeAndSite = [];

        foreach ($promotions as $promotion) {
            if ($promotion->id === null || $promotion->elementId === null || !isset($promotionElements[$promotion->id])) {
                continue;
            }

            $elementClass = $promotion->elementType ?? get_class($promotionElements[$promotion->id]);
            if (!$this->isElementClass($elementClass)) {
                continue;
            }

            if (SearchElementAvailabilityHelper::isSiteIndependent($elementClass)) {
                $groupKey = $elementClass . ':*';
                $idsByTypeAndSite[$groupKey]['class'] = $elementClass;
                $idsByTypeAndSite[$groupKey]['siteId'] = null;
                $idsByTypeAndSite[$groupKey]['ids'][(int)$promotion->elementId] = (int)$promotion->elementId;
                continue;
            }

            foreach ($sites as $site) {
                $groupKey = $elementClass . ':' . $site->id;
                $idsByTypeAndSite[$groupKey]['class'] = $elementClass;
                $idsByTypeAndSite[$groupKey]['siteId'] = (int)$site->id;
                $idsByTypeAndSite[$groupKey]['ids'][(int)$promotion->elementId] = (int)$promotion->elementId;
            }
        }

        $elements = [];
        foreach ($idsByTypeAndSite as $group) {
            /** @var class-string<ElementInterface> $elementClass */
            $elementClass = $group['class'];
            $elementQuery = $elementClass::find()
                ->id(array_values($group['ids']));
            if ($group['siteId'] !== null) {
                $elementQuery->siteId($group['siteId']);
            }
            foreach (SearchElementAvailabilityHelper::applyToQuery($elementQuery, $elementClass)->all() as $element) {
                if ($element instanceof ElementInterface) {
                    $elements[$this->elementCacheKey($elementClass, $group['siteId'] ?? null, (int)$element->id)] = $element;
                }
            }
        }

        return $elements;
    }

    /**
     * @param array<int, \lindemannrock\searchmanager\models\QueryRule> $rules
     * @return array<string, ElementInterface>
     */
    private function preloadTestQueryRuleRedirectElements(array $rules): array
    {
        $idsByType = [];

        foreach ($rules as $rule) {
            if ($rule->actionType !== 'redirect') {
                continue;
            }

            $elementId = $rule->actionValue['elementId'] ?? null;
            $elementClass = $rule->actionValue['elementType'] ?? null;
            if (!is_numeric($elementId) || !is_string($elementClass) || !$this->isElementClass($elementClass)) {
                continue;
            }

            $idsByType[$elementClass][(int)$elementId] = (int)$elementId;
        }

        $elements = [];
        foreach ($idsByType as $elementClass => $ids) {
            if (!is_string($elementClass)) {
                continue;
            }

            /** @var class-string<ElementInterface> $elementClassName */
            $elementClassName = $elementClass;
            foreach ($elementClassName::find()
                ->id(array_values($ids))
                ->status(null)
                ->all() as $element) {
                if ($element instanceof ElementInterface) {
                    $elements[$this->elementCacheKey($elementClassName, null, (int)$element->id)] = $element;
                }
            }
        }

        return $elements;
    }

    private function isElementClass(?string $elementClass): bool
    {
        return $elementClass !== null && is_subclass_of($elementClass, ElementInterface::class);
    }

    private function elementCacheKey(string $elementClass, ?int $siteId, int $elementId): string
    {
        return $elementClass . ':' . ($siteId ?? '*') . ':' . $elementId;
    }

    private function promotionElementTypeLabel(?string $elementClass, ?ElementInterface $element): string
    {
        $elementClass ??= $element !== null ? get_class($element) : null;
        if ($elementClass === null) {
            return Craft::t('search-manager', 'Unknown');
        }

        $labels = TargetElementTypeHelper::translatedLabels();

        return $labels[$elementClass] ?? ($this->isElementClass($elementClass) ? $elementClass::displayName() : $elementClass);
    }

    private function elementDisplayTitle(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = $element->{$property} ?? null;
                if (is_scalar($value) && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        $title = $element->title ?? null;

        return is_scalar($title) && trim((string)$title) !== ''
            ? trim((string)$title)
            : Craft::t('search-manager', 'Untitled');
    }

    /**
     * Validate and sanitize the settings section parameter.
     */
    private function _validSettingsSection(string $section): string
    {
        $allowed = ['general', 'indexing', 'analytics', 'search', 'language', 'highlighting', 'cache', 'interface', 'test'];

        return in_array($section, $allowed, true) ? $section : 'general';
    }

    /**
     * Return top-level settings attributes validated for the active settings section.
     */
    private function _validationAttributesForSection(string $section): array
    {
        return match ($section) {
            'general' => ['pluginName', 'defaultBackendHandle', 'defaultWidgetHandle', 'requireApiKey', 'logLevel'],
            'indexing' => ['autoIndex', 'replaceNativeSearch', 'batchSize', 'lastIndexedDebounceSeconds', 'syncBatchSize', 'batchFlushInterval', 'pendingMaxAge', 'batchMaxAttempts', 'indexPrefix'],
            'analytics' => ['enableAnalytics', 'enableGeoDetection', 'geoProvider', 'geoApiKey', 'anonymizeIpAddress', 'analyticsRetention'],
            'search' => ['bm25K1', 'bm25B', 'titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor', 'similarityThreshold', 'maxFuzzyCandidates', 'ngramSizes'],
            'language' => ['defaultLanguage', 'enableStopWords'],
            'highlighting' => ['highlightResultsEnabled', 'highlightTag', 'highlightClass', 'snippetMaxLength', 'maxSnippets', 'enableAutocomplete', 'autocompleteMinLength', 'autocompleteLimit', 'autocompleteFuzzy'],
            'cache' => ['cacheStorageMethod', 'enableCache', 'cacheDuration', 'enableAutocompleteCache', 'autocompleteCacheDuration', 'clearCacheOnSave', 'statusSyncInterval', 'enableCacheWarming', 'cacheWarmingQueryCount', 'cacheDeviceDetection', 'deviceDetectionCacheDuration'],
            'interface' => ['itemsPerPage', 'timeFormat', 'monthFormat', 'dateOrder', 'dateSeparator', 'showSeconds', 'defaultDateRange', 'exportsCsv', 'exportsJson', 'exportsExcel'],
            default => [],
        };
    }

    /**
     * Render the current settings section with the failed settings model.
     */
    private function _renderSettingsTemplate(string $section, Settings $settings): Response
    {
        $template = "search-manager/settings/{$section}";

        if ($section === 'general') {
            $backends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
            $enabledBackends = array_filter($backends, fn($b) => $b->enabled);
            $widgets = SearchManager::$plugin->widgetConfigs->getAll();
            $enabledWidgets = array_filter($widgets, fn($w) => $w->enabled);

            return $this->renderTemplate($template, [
                'settings' => $settings,
                'backends' => $backends,
                'enabledBackends' => $enabledBackends,
                'widgets' => $widgets,
                'enabledWidgets' => $enabledWidgets,
            ]);
        }

        if ($section === 'test') {
            $backends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();

            return $this->renderTemplate($template, [
                'settings' => $settings,
                'cacheEnabled' => $settings->enableCache ?? true,
                'backends' => $backends,
            ]);
        }

        return $this->renderTemplate($template, [
            'settings' => $settings,
        ]);
    }

    public function actionCleanupAnalytics(): Response
    {
        $this->requirePermission('searchManager:manageSettings');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $settings = SearchManager::$plugin->getSettings();
        $retention = $settings->analyticsRetention;

        if ($retention <= 0) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Analytics retention must be greater than 0 to perform cleanup.'),
            ]);
        }

        try {
            $cutoffDate = new \DateTime("-{$retention} days");
            $deleted = Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_analytics}}', ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)])
                ->execute();

            $this->logInfo('Analytics cleanup completed', [
                'retention_days' => $retention,
                'deleted_count' => $deleted,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'Deleted {count} old analytics records', ['count' => $deleted]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to cleanup analytics', ['error' => $e->getMessage()]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to cleanup analytics. Check logs for details.'),
            ]);
        }
    }
}
