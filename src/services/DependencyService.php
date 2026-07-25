<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\Model;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Owns index dependencies, permission-safe usage disclosure, reference
 * validation, and the effective CP index catalogue.
 *
 * @since 5.53.0
 */
class DependencyService extends Component
{
    /**
     * Request-scoped effective catalogue before selected-reference decoration.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $indexCatalogue = null;

    /**
     * The permission groups are independent, so a caller holding only a delete
     * permission may not be allowed to view the referenced entity type. Each
     * usage kind maps to the permission that grants viewing that section;
     * {@see formatInUseError()} shows a count instead of names without it.
     */
    private const KIND_VIEW_PERMISSIONS = [
        'widget' => 'searchManager:manageWidgetConfigs',
        'apiKey' => 'searchManager:manageApiKeys',
        'index' => 'searchManager:manageIndices',
        'queryRule' => 'searchManager:manageQueryRules',
        'promotion' => 'searchManager:managePromotions',
    ];

    private const KIND_COUNT_MESSAGES = [
        'widget' => '{count, plural, =1{# widget} other{# widgets}}',
        'apiKey' => '{count, plural, =1{# API key} other{# API keys}}',
        'index' => '{count, plural, =1{# index} other{# indices}}',
    ];

    private const KIND_SIMPLE_COUNT_MESSAGES = [
        'queryRule' => ['{count} rule', '{count} rules'],
        'promotion' => ['{count} promotion', '{count} promotions'],
    ];

    private const EFFECTIVE_STATUS_RANK = [
        'error' => -1,
        'disabled' => 0,
        'enabled' => 1,
    ];

    /**
     * Resolve one class through Search Manager's optional-plugin availability contract.
     *
     * @return array{
     *   class: string,
     *   providerHandle: string|null,
     *   providerEnabled: bool,
     *   classExists: bool,
     *   implementsContract: bool,
     *   constructible: bool,
     *   available: bool,
     *   reason: string|null
     * }
     * @since 5.54.0
     */
    public function getClassAvailability(
        string $class,
        string $requiredInterface,
        bool $verifyConstruction = false,
    ): array {
        $classExists = class_exists($class);
        $providerHandle = $classExists ? $this->providerHandleForClass($class) : null;
        $providerEnabled = $providerHandle === null || $this->isProviderEnabled($providerHandle);
        $implementsContract = $classExists && is_subclass_of($class, $requiredInterface);
        $constructible = false;

        if ($implementsContract) {
            try {
                $reflection = new \ReflectionClass($class);
                $constructor = $reflection->getConstructor();
                $constructible = $reflection->isInstantiable()
                    && ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
                if ($constructible && $verifyConstruction && $providerEnabled) {
                    $reflection->newInstance();
                }
            } catch (\ReflectionException) {
                $constructible = false;
            } catch (\Throwable) {
                $constructible = false;
            }
        }

        $reason = match (true) {
            !$classExists => 'class-missing',
            !$implementsContract => 'invalid-contract',
            !$constructible => 'not-constructible',
            !$providerEnabled => 'provider-disabled',
            default => null,
        };

        return [
            'class' => $class,
            'providerHandle' => $providerHandle,
            'providerEnabled' => $providerEnabled,
            'classExists' => $classExists,
            'implementsContract' => $implementsContract,
            'constructible' => $constructible,
            'available' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Resolve the element and effective transformer dependencies for an index.
     *
     * @return array{
     *   available: bool,
     *   providerHandles: list<string>,
     *   element: array<string, mixed>,
     *   transformer: array<string, mixed>
     * }
     * @since 5.54.0
     */
    public function getIndexAvailability(SearchIndex $index): array
    {
        $element = $this->getClassAvailability($index->elementType, ElementInterface::class);
        $transformerClass = SearchManager::$plugin->transformers
            ->resolveTransformerClassForElementTypeSilently($index->elementType, $index->transformerClass);
        $transformer = $this->getClassAvailability((string)$transformerClass, TransformerInterface::class);
        $providerHandles = array_values(array_unique(array_filter([
            $element['providerHandle'],
            $transformer['providerHandle'],
        ], 'is_string')));

        return [
            'available' => $element['available'] && $transformer['available'],
            'providerHandles' => $providerHandles,
            'element' => $element,
            'transformer' => $transformer,
        ];
    }

    /**
     * Whether an enabled index can currently be used by Search Manager.
     *
     * @since 5.54.0
     */
    public function isIndexAvailable(string $handle, bool $allowUnmanaged = false): bool
    {
        $record = $this->getIndexCatalogue([$handle])[$handle];

        return (bool)$record['available'] || ($allowUnmanaged && !$record['exists']);
    }

    /**
     * Whether a low-level backend operation has loadable index dependencies.
     *
     * This intentionally does not impose the index's enabled flag; public
     * Search Manager resolution applies enabled-state policy before reaching
     * low-level backend proxies, which also support diagnostic/raw operations.
     *
     * @since 5.54.0
     */
    public function areIndexDependenciesAvailable(string $handle, bool $allowUnmanaged = false): bool
    {
        $record = $this->getIndexCatalogue([$handle])[$handle];
        if (!$record['exists']) {
            return $allowUnmanaged;
        }

        return !$record['configError']
            && (bool)($record['dependencyAvailability']['available'] ?? false);
    }

    /**
     * Select enabled indices whose resolved site scope includes every site.
     *
     * @return list<SearchIndex>
     * @since 5.54.0
     */
    public function getEnabledAllSitesIndices(): array
    {
        return array_values(array_filter(
            SearchIndex::findAll(),
            static fn(SearchIndex $index): bool => $index->enabled && $index->getSiteIds() === null,
        ));
    }

    /**
     * Select enabled indices owned by an optional provider through either dependency.
     *
     * @return list<SearchIndex>
     * @since 5.54.0
     */
    public function getEnabledIndicesForProvider(string $providerHandle): array
    {
        $indices = [];
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled) {
                continue;
            }

            $availability = $this->getIndexAvailability($index);
            if (in_array($providerHandle, $availability['providerHandles'], true)) {
                $indices[] = $index;
            }
        }

        return $indices;
    }

    /**
     * Invalidate Search Manager's derived state without deleting backend storage.
     *
     * @param iterable<SearchIndex> $indices
     * @since 5.54.0
     */
    public function invalidateIndexCaches(iterable $indices): void
    {
        $this->clearIndexCatalogue();
        SearchIndex::clearCache();

        foreach ($indices as $index) {
            SearchManager::$plugin->backend->clearSearchCache($index->handle);
            SearchManager::$plugin->autocomplete->clearCache($index->handle);
        }
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
     */
    public function getBackendUsages(string $handle): array
    {
        $usages = [];
        $catalogue = $this->getIndexCatalogue();

        foreach (SearchIndex::findAll() as $index) {
            if ($index->backend !== $handle) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Index'),
                'label' => $catalogue[$index->handle]['identityLabel'] ?? $index->handle,
                'kind' => 'index',
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
     */
    public function getIndexUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widgetConfig) {
            if (!in_array($handle, $widgetConfig->getIndexHandles(), true)) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
                'kind' => 'widget',
            ];
        }

        foreach (ApiKey::findAll() as $apiKey) {
            if (!in_array($handle, $apiKey->allowedIndices, true)) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'API key'),
                'label' => $apiKey->name,
                'kind' => 'apiKey',
            ];
        }

        foreach (QueryRule::findAll($handle) as $queryRule) {
            $usages[] = [
                'type' => Craft::t('search-manager', 'Query Rules'),
                'label' => $queryRule->name,
                'kind' => 'queryRule',
            ];
        }

        foreach (Promotion::findAll($handle) as $promotion) {
            $usages[] = [
                'type' => Craft::t('search-manager', 'Promotions'),
                'label' => $promotion->title ?? Craft::t('search-manager', 'Untitled'),
                'kind' => 'promotion',
            ];
        }

        return $usages;
    }

    /**
     * Validate and normalize a nullable index reference.
     *
     * @since 5.54.0
     */
    public function validateIndexReference(Model $model, string $attribute): void
    {
        $value = $model->$attribute;
        $handle = is_string($value) ? trim($value) : '';

        if ($handle === '') {
            $model->$attribute = null;
            return;
        }

        $model->$attribute = $handle;
        $reference = $this->getIndexCatalogue([$handle])[$handle];
        if (!$reference['referenceable']) {
            $model->addError($attribute, Craft::t('search-manager', 'Index not found'));
        }
    }

    /**
     * Validate and normalize an array of submitted index handles.
     *
     * A null return means the submitted shape or one of its references is
     * invalid. A wildcard is valid only as the sole member when explicitly
     * allowed.
     *
     * @return list<string>|null
     * @since 5.54.0
     */
    public function normalizeIndexHandleList(mixed $handles, bool $allowWildcard = false): ?array
    {
        if (!is_array($handles) || !array_is_list($handles)) {
            return null;
        }

        $normalized = [];
        foreach ($handles as $rawHandle) {
            if (!is_string($rawHandle)) {
                return null;
            }

            $handle = trim($rawHandle);
            if ($handle === '' || in_array($handle, $normalized, true)) {
                return null;
            }
            $normalized[] = $handle;
        }

        if (in_array(ApiKey::ALL_INDICES, $normalized, true)) {
            return $allowWildcard && $normalized === [ApiKey::ALL_INDICES]
                ? $normalized
                : null;
        }

        $catalogue = $this->getIndexCatalogue($normalized);
        foreach ($normalized as $handle) {
            if (!($catalogue[$handle]['referenceable'] ?? false)) {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * Normalize Craft's exact empty checkbox sentinel at the HTTP boundary.
     *
     * All other values are returned unchanged so model validation remains the
     * strict authority for malformed and forged submissions.
     *
     * @since 5.54.0
     */
    public function normalizeSubmittedIndexHandleList(mixed $handles): mixed
    {
        return $handles === '' ? [] : $handles;
    }

    /**
     * Resolve the displayed status for an index-scoped entity.
     *
     * An unavailable reference overrides the entity's raw enabled state.
     *
     * @param array<string, mixed> $reference
     * @return array{label: string, value: string, colorSet: string, title: string|null}
     * @since 5.54.0
     */
    public function resolveEffectiveStatus(bool $enabled, array $reference = []): array
    {
        $referenceError = ($reference['state'] ?? null) === 'error';
        $state = $referenceError
            ? 'error'
            : ($enabled ? 'enabled' : 'disabled');
        $errorTitle = $referenceError && is_string($reference['errorTitle'] ?? null)
            ? $reference['errorTitle']
            : null;

        return $this->statusData($state, $errorTitle);
    }

    /**
     * @param array{value: string} $left
     * @param array{value: string} $right
     * @since 5.54.0
     */
    public function compareEffectiveStatuses(array $left, array $right): int
    {
        return (self::EFFECTIVE_STATUS_RANK[$left['value']] ?? 0)
            <=> (self::EFFECTIVE_STATUS_RANK[$right['value']] ?? 0);
    }

    /**
     * Build the effective request-scoped index catalogue.
     *
     * Config validation is computed once and applied only to config-backed
     * indices. Config precedence is inherited from SearchIndex::findAll().
     * Selected missing or config-error handles are retained for correction.
     *
     * @param array<int, string|null>|string|null $selectedHandles
     * @return array<string, array{
     *   handle: string,
     *   displayName: string,
     *   identityLabel: string,
     *   choiceLabel: string,
     *   exists: bool,
     *   global: bool,
     *   source: string|null,
     *   configError: bool,
     *   enabled: bool,
     *   state: string,
     *   referenceable: bool,
     *   available: bool,
     *   canChoose: bool,
     *   retained: bool,
     *   errorTitle: string|null,
     *   findings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>,
     *   dependencyAvailability: array{
     *     available: bool,
     *     providerHandles: list<string>,
     *     element: array<string, mixed>,
     *     transformer: array<string, mixed>
     *   }|null,
     *   status: array{label: string, value: string, colorSet: string, title: string|null}
     * }>
     * @since 5.54.0
     */
    public function getIndexCatalogue(array|string|null $selectedHandles = null): array
    {
        $selected = $this->normalizeSelectedHandles($selectedHandles);
        if ($this->indexCatalogue === null) {
            $validation = SearchManager::$plugin->configIndexValidator->validate();
            $catalogue = [];

            foreach (SearchIndex::findAll() as $index) {
                $configFindings = $index->source === 'config'
                    ? $validation->getFindingsForHandle($index->handle)
                    : [];
                $dependencyAvailability = $this->getIndexAvailability($index);
                $findings = $this->mergeIndexFindings(
                    $index,
                    $configFindings,
                    $dependencyAvailability,
                );
                $errorTitle = $this->firstError($findings);
                if ($errorTitle === null && !$dependencyAvailability['available']) {
                    $errorTitle = $this->statusData('error', null)['label'];
                }
                $displayName = $this->indexDisplayName($index);
                $identityLabel = $this->indexIdentityLabel($displayName, $index->handle);
                $status = $this->resolveEffectiveStatus((bool)$index->enabled, [
                    'state' => $errorTitle !== null ? 'error' : 'enabled',
                    'errorTitle' => $errorTitle,
                ]);
                $state = $status['value'];

                $catalogue[$index->handle] = [
                    'handle' => $index->handle,
                    'displayName' => $displayName,
                    'identityLabel' => $identityLabel,
                    'choiceLabel' => $this->choiceLabel($identityLabel, $state, $status['label']),
                    'exists' => true,
                    'global' => false,
                    'source' => $index->source,
                    'configError' => $errorTitle !== null,
                    'enabled' => (bool)$index->enabled,
                    'state' => $state,
                    'referenceable' => $errorTitle === null,
                    'available' => $errorTitle === null && (bool)$index->enabled,
                    'canChoose' => $errorTitle === null,
                    'retained' => false,
                    'errorTitle' => $errorTitle,
                    'findings' => $findings,
                    'dependencyAvailability' => $dependencyAvailability,
                    'status' => $status,
                ];
            }

            $this->indexCatalogue = $catalogue;
        }

        $catalogue = $this->indexCatalogue;
        foreach ($selected as $handle) {
            if (isset($catalogue[$handle])) {
                if (!$catalogue[$handle]['canChoose']) {
                    $catalogue[$handle]['retained'] = true;
                }
                continue;
            }

            $errorTitle = Craft::t('search-manager', 'Index not found');
            $status = $this->resolveEffectiveStatus(false, [
                'state' => 'error',
                'errorTitle' => $errorTitle,
            ]);
            $catalogue[$handle] = [
                'handle' => $handle,
                'displayName' => $handle,
                'identityLabel' => $handle,
                'choiceLabel' => $this->choiceLabel($handle, 'error', $status['label']),
                'exists' => false,
                'global' => false,
                'source' => null,
                'configError' => false,
                'enabled' => false,
                'state' => 'error',
                'referenceable' => false,
                'available' => false,
                'canChoose' => false,
                'retained' => true,
                'errorTitle' => $errorTitle,
                'findings' => [],
                'dependencyAvailability' => null,
                'status' => $status,
            ];
        }

        return $catalogue;
    }

    /**
     * Resolve nullable index handles for CP presentation.
     *
     * The empty-string key represents global scope because PHP converts a null
     * array key to an empty string.
     *
     * @param array<int, string|null> $handles
     * @return array<string, array<string, mixed>>
     * @since 5.54.0
     */
    public function resolveIndexReferences(array $handles): array
    {
        $catalogue = $this->getIndexCatalogue($handles);
        $references = [];
        foreach ($handles as $rawHandle) {
            $handle = is_string($rawHandle) ? trim($rawHandle) : '';
            if (array_key_exists($handle, $references)) {
                continue;
            }

            if ($handle === '') {
                $status = $this->resolveEffectiveStatus(true);
                $globalLabel = Craft::t('search-manager', 'All Indices');
                $references[''] = [
                    'handle' => null,
                    'displayName' => $globalLabel,
                    'identityLabel' => $globalLabel,
                    'choiceLabel' => $globalLabel,
                    'exists' => true,
                    'global' => true,
                    'configError' => false,
                    'referenceable' => true,
                    'available' => true,
                    'canChoose' => true,
                    'retained' => false,
                    'state' => 'enabled',
                    'enabled' => true,
                    'errorTitle' => null,
                    'findings' => [],
                    'status' => $status,
                ];
                continue;
            }

            $references[$handle] = $catalogue[$handle];
        }

        return $references;
    }

    /**
     * Build index-scope options from the canonical catalogue.
     *
     * An optional allowlist narrows a Widget to its selected API-key scope.
     * Selected values remain visible when outside that scope or otherwise
     * unavailable so validation redisplay never hides submitted state.
     *
     * @param array<int, string|null>|string|null $selectedHandles
     * @param list<string>|null $allowedHandles
     * @return array<int, array{label: string, value: string, state: string, canChoose: bool, retained: bool}>
     * @since 5.54.0
     */
    public function getIndexOptions(
        array|string|null $selectedHandles = null,
        bool $includeGlobal = true,
        ?array $allowedHandles = null,
    ): array {
        $selected = $this->normalizeSelectedHandles($selectedHandles);
        $catalogue = $this->getIndexCatalogue($selected);
        $options = [];
        if ($includeGlobal) {
            $options[] = [
                'label' => Craft::t('search-manager', 'All Indices'),
                'value' => '',
                'state' => 'enabled',
                'canChoose' => true,
                'retained' => false,
            ];
        }

        foreach ($catalogue as $handle => $record) {
            $isSelected = in_array($handle, $selected, true);
            $isAllowed = $allowedHandles === null || in_array($handle, $allowedHandles, true);
            if ((!$record['canChoose'] || !$isAllowed) && !$isSelected) {
                continue;
            }

            $options[] = [
                'label' => $record['choiceLabel'],
                'value' => $handle,
                'state' => $record['state'],
                'canChoose' => $record['canChoose'] && $isAllowed,
                'retained' => $record['retained'] || ($isSelected && !$isAllowed),
            ];
        }

        return $options;
    }

    /**
     * Clear the request-scoped catalogue cache.
     *
     * Primarily useful to long-running test/console processes after explicit
     * config or index fixture changes.
     *
     * @since 5.54.0
     */
    public function clearIndexCatalogue(): void
    {
        $this->indexCatalogue = null;
    }

    /**
     * Resolve class ownership using Craft's Composer-aware plugin lookup.
     */
    protected function providerHandleForClass(string $class): ?string
    {
        return Craft::$app->getPlugins()->getPluginHandleByClass($class);
    }

    /**
     * Resolve enabled state through Base's shared plugin authority.
     */
    protected function isProviderEnabled(string $handle): bool
    {
        return PluginHelper::isPluginEnabled($handle);
    }

    /**
     * Resolve a provider's display name without loading its disabled plugin.
     */
    protected function providerNameForHandle(string $handle): string
    {
        try {
            $info = Craft::$app->getPlugins()->getComposerPluginInfo($handle);
            $name = is_array($info) ? trim((string)($info['name'] ?? '')) : '';

            return $name !== '' ? $name : $handle;
        } catch (\Throwable) {
            return $handle;
        }
    }

    /**
     * @param array<int, string|null>|string|null $handles
     * @return list<string>
     */
    private function normalizeSelectedHandles(array|string|null $handles): array
    {
        $values = is_array($handles) ? $handles : [$handles];
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $handle = trim($value);
            if ($handle !== '' && !in_array($handle, $normalized, true)) {
                $normalized[] = $handle;
            }
        }

        return $normalized;
    }

    private function indexDisplayName(SearchIndex $index): string
    {
        $name = trim((string)$index->name);

        return $name !== '' ? $name : trim($index->handle);
    }

    private function indexIdentityLabel(string $displayName, string $rawHandle): string
    {
        $handle = trim($rawHandle);

        return $displayName === $handle
            ? $handle
            : sprintf('%s (%s)', $displayName, $handle);
    }

    /**
     * @param list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}> $findings
     */
    private function firstError(array $findings): ?string
    {
        foreach ($findings as $finding) {
            if ($finding['severity'] === ConfigIndexValidationResult::SEVERITY_ERROR) {
                return $finding['message'];
            }
        }

        return null;
    }

    /**
     * Merge validator findings with disabled-provider recovery details.
     *
     * Config validation already records a generic unavailable-element error.
     * The catalogue replaces only that provider-disabled result with its
     * actionable equivalent; every malformed-config finding remains intact.
     *
     * @param list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}> $configFindings
     * @param array{
     *   available: bool,
     *   providerHandles: list<string>,
     *   element: array<string, mixed>,
     *   transformer: array<string, mixed>
     * } $availability
     * @return list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>
     */
    private function mergeIndexFindings(
        SearchIndex $index,
        array $configFindings,
        array $availability,
    ): array {
        if (($availability['element']['reason'] ?? null) === 'provider-disabled') {
            $configFindings = array_values(array_filter(
                $configFindings,
                static fn(array $finding): bool => $finding['key'] !== 'elementType',
            ));
        }

        $findings = $configFindings;
        $seenProviders = [];
        foreach (['element', 'transformer'] as $dependencyKind) {
            $dependency = $availability[$dependencyKind];
            if (($dependency['reason'] ?? null) !== 'provider-disabled') {
                continue;
            }

            $providerHandle = $dependency['providerHandle'] ?? null;
            if (!is_string($providerHandle) || in_array($providerHandle, $seenProviders, true)) {
                continue;
            }
            $seenProviders[] = $providerHandle;

            $providerName = $this->providerNameForHandle($providerHandle);
            $class = (string)$dependency['class'];
            $message = $dependencyKind === 'element'
                ? Craft::t('search-manager', 'The element type "{class}" belongs to the disabled plugin "{plugin}". Enable the plugin before rebuilding this index.', [
                    'class' => $class,
                    'plugin' => $providerName,
                ])
                : Craft::t('search-manager', 'The transformer "{class}" belongs to the disabled plugin "{plugin}". Enable the plugin before rebuilding this index.', [
                    'class' => $class,
                    'plugin' => $providerName,
                ]);

            $findings[] = [
                'severity' => ConfigIndexValidationResult::SEVERITY_ERROR,
                'handle' => $index->handle,
                'key' => sprintf('%s-provider-disabled', $dependencyKind),
                'message' => $message,
                'emphasis' => $providerName,
            ];
        }

        return $findings;
    }

    /**
     * @return array{label: string, value: string, colorSet: string, title: string|null}
     */
    private function statusData(string $state, ?string $errorTitle): array
    {
        $label = match ($state) {
            'error' => Craft::t('search-manager', 'Error'),
            'disabled' => Craft::t('search-manager', 'Disabled'),
            default => Craft::t('search-manager', 'Enabled'),
        };

        return [
            'label' => $label,
            'value' => $state,
            'colorSet' => 'status',
            'title' => $errorTitle,
        ];
    }

    private function choiceLabel(string $identityLabel, string $state, string $statusLabel): string
    {
        return $state === 'enabled'
            ? $identityLabel
            : sprintf('%s — %s', $identityLabel, $statusLabel);
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
     */
    public function getApiKeyUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($handle) as $widgetConfig) {
            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
                'kind' => 'widget',
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
     */
    public function getStyleUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widgetConfig) {
            if ($widgetConfig->styleHandle !== $handle) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
                'kind' => 'widget',
            ];
        }

        return $usages;
    }

    /**
     * Format the delete-guard block message. Usage names are shown only for
     * kinds the current user holds the view permission for; other kinds are
     * summarized as a count so entity names don't leak across permission
     * groups (a name is only actionable to someone who can open that section
     * anyway). No identity (guest/console) fails closed to counts.
     *
     * @param array<int, array{type: string, label: string, kind: string}> $usages
     */
    public function formatInUseError(string $name, array $usages): string
    {
        return Craft::t('search-manager', 'Cannot delete “{name}” — it is in use by: {usages}.', [
            'name' => $name,
            'usages' => implode(', ', $this->formatUsageLabels($usages)),
        ]);
    }

    /**
     * Format the index handle-change guard using the same permission-safe
     * dependency disclosure as deletion.
     *
     * @param array<int, array{type: string, label: string, kind: string}> $usages
     * @since 5.54.0
     */
    public function formatHandleChangeError(string $name, array $usages): string
    {
        return Craft::t('search-manager', 'Cannot change the handle for “{name}” — it is in use by: {usages}.', [
            'name' => $name,
            'usages' => implode(', ', $this->formatUsageLabels($usages)),
        ]);
    }

    /**
     * @param array<int, array{type: string, label: string, kind: string}> $usages
     * @return string[]
     */
    private function formatUsageLabels(array $usages): array
    {
        $user = Craft::$app->getUser();
        $usageLabels = [];
        $hiddenCounts = [];

        foreach ($usages as $usage) {
            $viewPermission = self::KIND_VIEW_PERMISSIONS[$usage['kind']] ?? null;
            if ($viewPermission === null || $user->checkPermission($viewPermission)) {
                $usageLabels[] = $usage['type'] . ': ' . $usage['label'];
            } else {
                $hiddenCounts[$usage['kind']] = ($hiddenCounts[$usage['kind']] ?? 0) + 1;
            }
        }

        foreach ($hiddenCounts as $kind => $count) {
            if (isset(self::KIND_SIMPLE_COUNT_MESSAGES[$kind])) {
                $messages = self::KIND_SIMPLE_COUNT_MESSAGES[$kind];
                $usageLabels[] = Craft::t('search-manager', $messages[$count === 1 ? 0 : 1], ['count' => $count]);
                continue;
            }

            $usageLabels[] = Craft::t('search-manager', self::KIND_COUNT_MESSAGES[$kind], ['count' => $count]);
        }

        return $usageLabels;
    }
}
