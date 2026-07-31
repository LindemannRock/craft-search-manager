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
use craft\db\Query;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\IndexCountBackendInterface;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Owns dependency inventories, permission-safe usage disclosure, reference
 * validation, and the effective CP index catalogue.
 *
 * @since 5.53.0
 */
class DependencyService extends Component
{
    /** @since 5.54.0 */
    public const ACTION_VIEW = 'view';

    /** @since 5.54.0 */
    public const ACTION_TARGETED_REBUILD = 'targetedRebuild';

    /** @since 5.54.0 */
    public const ACTION_AUTOMATIC_REBUILD = 'automaticRebuild';

    /** @since 5.54.0 */
    public const ACTION_REBUILD_ALL = 'rebuildAll';

    /** @since 5.54.0 */
    public const ACTION_CLEAR_DATA = 'clearData';

    /** @since 5.54.0 */
    public const ACTION_CLEAR_CACHE = 'clearCache';

    /** @since 5.54.0 */
    public const ACTION_SYNC_COUNT = 'syncCount';

    /** @since 5.54.0 */
    public const ACTION_DELETE = 'delete';

    /**
     * Request-scoped effective catalogue before selected-reference decoration.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $indexCatalogue = null;

    private ?ConfigIndexValidationResult $indexConfigValidation = null;

    /** @var array<string, array{backend: BackendInterface, handle: string, type: string, fullIndexName: string}> */
    private array $strictBackendTargets = [];

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
        $catalogue = $this->getIndexCatalogue();

        return array_values(array_filter(
            SearchIndex::findAll(),
            static fn(SearchIndex $index): bool => ($catalogue[$index->handle]['actions'][self::ACTION_AUTOMATIC_REBUILD]['allowed'] ?? false)
                && $index->getSiteIds() === null,
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
     *   siteIds: list<int>|null,
     *   siteLabel: string,
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
     *   backendIdentity: array<string, mixed>,
     *   actions: array<string, array{allowed: bool, reasonCode: string|null, reason: string|null}>,
     *   status: array{label: string, value: string, colorSet: string, title: string|null}
     * }>
     * @since 5.54.0
     */
    public function getIndexCatalogue(array|string|null $selectedHandles = null): array
    {
        $selected = $this->normalizeSelectedHandles($selectedHandles);
        if ($this->indexCatalogue === null) {
            $validation = $this->getIndexConfigValidation();
            $catalogue = [];
            $siteNames = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $siteNames[(int)$site->id] = $site->name;
            }

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
                $siteScope = $this->indexSiteScope($index, $siteNames);
                $status = $this->resolveEffectiveStatus((bool)$index->enabled, [
                    'state' => $errorTitle !== null ? 'error' : 'enabled',
                    'errorTitle' => $errorTitle,
                ]);
                $state = $status['value'];
                $backendIdentity = $this->resolveStrictBackendIdentity($index);
                $structuralReason = $this->structuralReasonCode(
                    $configFindings,
                    $dependencyAvailability,
                    $backendIdentity,
                );
                $actions = $this->projectIndexActions($index, $structuralReason, $backendIdentity);

                $catalogue[$index->handle] = [
                    'handle' => $index->handle,
                    'displayName' => $displayName,
                    'identityLabel' => $identityLabel,
                    'choiceLabel' => $this->choiceLabel($identityLabel, $state, $status['label']),
                    'siteIds' => $siteScope['siteIds'],
                    'siteLabel' => $siteScope['siteLabel'],
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
                    'backendIdentity' => $backendIdentity,
                    'actions' => $actions,
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
                'siteIds' => [],
                'siteLabel' => '',
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
                'backendIdentity' => $this->missingBackendIdentity(),
                'actions' => $this->deniedActions('index-not-found', false),
                'status' => $status,
            ];
        }

        return $catalogue;
    }

    /**
     * Build the CP Test selector from the canonical effective catalogue.
     *
     * Only enabled, referenceable indices are exposed. The companion site map
     * preserves the Test tool's single-site request behavior without requiring
     * Twig to resolve index models or Craft sites independently.
     *
     * @return array{
     *   choices: list<array{label: string, value: string}>,
     *   indexSiteIds: array<string, int|null>
     * }
     * @since 5.54.0
     */
    public function getTestIndexChoices(): array
    {
        $choices = [];
        $indexSiteIds = [];
        foreach ($this->getIndexCatalogue() as $handle => $record) {
            if (!$record['available'] || !$record['referenceable']) {
                continue;
            }

            $siteIds = $record['siteIds'];
            $choices[] = [
                'label' => sprintf('%s — %s', $record['identityLabel'], $record['siteLabel']),
                'value' => $handle,
            ];
            $indexSiteIds[$handle] = is_array($siteIds) && count($siteIds) === 1
                ? $siteIds[0]
                : null;
        }

        return [
            'choices' => $choices,
            'indexSiteIds' => $indexSiteIds,
        ];
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
        $this->indexConfigValidation = null;
        $this->strictBackendTargets = [];
    }

    /**
     * Resolve an allowed action and its strict backend target without fallback.
     *
     * @return array{backend: BackendInterface, handle: string, type: string, fullIndexName: string}|null
     * @since 5.54.0
     */
    public function getStrictBackendTarget(string $indexHandle, string $action): ?array
    {
        $record = $this->getIndexCatalogue([$indexHandle])[$indexHandle];
        if (!($record['actions'][$action]['allowed'] ?? false)) {
            return null;
        }

        return $this->strictBackendTargets[$indexHandle] ?? null;
    }

    /**
     * Return the canonical capability result for one index action.
     *
     * @return array{allowed: bool, reasonCode: string|null, reason: string|null}
     * @since 5.54.0
     */
    public function getIndexActionCapability(string $indexHandle, string $action): array
    {
        $record = $this->getIndexCatalogue([$indexHandle])[$indexHandle];

        return $record['actions'][$action] ?? $this->actionResult(false, 'index-not-found');
    }

    /**
     * Build the shared rebuild-all precondition and participant projection.
     *
     * @return array{
     *   allowed: bool,
     *   reasonCode: string|null,
     *   reason: string|null,
     *   participants: list<string>,
     *   skips: list<array{handle: string, reasonCode: string, reason: string}>,
     *   collisions: list<string>
     * }
     * @since 5.54.0
     */
    public function getRebuildAllPlan(): array
    {
        $configHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::loadFromConfig(),
        );
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_indices}}')
            ->where(['source' => 'database'])
            ->column();
        $collisions = array_values(array_intersect($configHandles, $databaseHandles));
        sort($collisions, SORT_STRING);

        if ($collisions !== []) {
            return [
                'allowed' => false,
                'reasonCode' => 'index-handle-collision',
                'reason' => Craft::t('search-manager', 'Rebuild All is unavailable because one or more index handles exist in both config and the database.'),
                'participants' => [],
                'skips' => [],
                'collisions' => $collisions,
            ];
        }

        $participants = [];
        $skips = [];
        $catalogue = $this->getIndexCatalogue();
        foreach ($catalogue as $handle => $record) {
            $capability = $record['actions'][self::ACTION_REBUILD_ALL];
            if ($capability['allowed']) {
                $participants[] = $handle;
                continue;
            }

            $skips[] = [
                'handle' => $handle,
                'reasonCode' => (string)$capability['reasonCode'],
                'reason' => (string)$capability['reason'],
            ];
        }

        $resolvedHandles = array_fill_keys(array_keys($catalogue), true);
        foreach ($this->getIndexConfigValidation()->getFindingGroups() as $group) {
            if ($group['severity'] !== ConfigIndexValidationResult::SEVERITY_ERROR) {
                continue;
            }
            $handle = $group['handle'];
            if (is_string($handle) && $handle !== '' && isset($resolvedHandles[$handle])) {
                continue;
            }
            $skips[] = [
                'handle' => is_string($handle) && $handle !== '' ? $handle : 'config',
                'reasonCode' => 'config-definition-unresolved',
                'reason' => $this->reasonMessage('config-definition-unresolved'),
            ];
        }

        sort($participants, SORT_STRING);
        usort($skips, static fn(array $left, array $right): int => strcmp($left['handle'], $right['handle']));
        $allowed = $participants !== [];

        return [
            'allowed' => $allowed,
            'reasonCode' => $allowed ? null : 'no-eligible-indices',
            'reason' => $allowed ? null : $this->reasonMessage('no-eligible-indices'),
            'participants' => $participants,
            'skips' => $skips,
            'collisions' => [],
        ];
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
     * @param array<int, string> $siteNames
     * @return array{siteIds: list<int>|null, siteLabel: string}
     */
    private function indexSiteScope(SearchIndex $index, array $siteNames): array
    {
        $siteIds = $index->getSiteIds();
        if ($siteIds === null) {
            return [
                'siteIds' => null,
                'siteLabel' => Craft::t('search-manager', 'All Sites'),
            ];
        }

        sort($siteIds, SORT_NUMERIC);
        $labels = array_map(
            static fn(int $siteId): string => $siteNames[$siteId]
                ?? Craft::t('search-manager', 'Site #{id}', ['id' => $siteId]),
            $siteIds,
        );

        return [
            'siteIds' => $siteIds,
            'siteLabel' => implode(', ', $labels),
        ];
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
     * @return array<string, array{allowed: bool, reasonCode: string|null, reason: string|null}>
     */
    private function projectIndexActions(
        SearchIndex $index,
        ?string $structuralReason,
        array $backendIdentity,
    ): array {
        $targetedRebuild = $structuralReason === null
            ? $this->actionResult(true)
            : $this->actionResult(false, $structuralReason);
        $automaticRebuild = !$targetedRebuild['allowed']
            ? $targetedRebuild
            : ($index->enabled
                ? $this->actionResult(true)
                : $this->actionResult(false, 'index-disabled'));
        $clearData = $targetedRebuild['allowed'] && $backendIdentity['available']
            ? $this->actionResult(true)
            : ($targetedRebuild['allowed']
                ? $this->actionResult(false, (string)$backendIdentity['reasonCode'])
                : $targetedRebuild);
        $syncCount = $targetedRebuild['allowed'] && $backendIdentity['available']
            ? (($backendIdentity['supportsSyncCount'] ?? false)
                ? $this->actionResult(true)
                : $this->actionResult(false, 'backend-count-unsupported'))
            : ($targetedRebuild['allowed']
                ? $this->actionResult(false, (string)$backendIdentity['reasonCode'])
                : $targetedRebuild);

        if ($index->source !== 'database') {
            $delete = $this->actionResult(false, 'config-owned-index');
        } else {
            $usages = $this->getIndexUsages($index->handle);
            if ($usages !== []) {
                $delete = [
                    'allowed' => false,
                    'reasonCode' => 'index-in-use',
                    'reason' => $this->formatInUseError($index->name, $usages),
                ];
            } elseif (!$backendIdentity['available']) {
                $delete = $this->actionResult(false, (string)$backendIdentity['reasonCode']);
            } else {
                $delete = $this->actionResult(true);
            }
        }

        return [
            self::ACTION_VIEW => $this->actionResult(true),
            self::ACTION_TARGETED_REBUILD => $targetedRebuild,
            self::ACTION_AUTOMATIC_REBUILD => $automaticRebuild,
            self::ACTION_REBUILD_ALL => $automaticRebuild,
            self::ACTION_CLEAR_DATA => $clearData,
            self::ACTION_CLEAR_CACHE => $this->actionResult(true),
            self::ACTION_SYNC_COUNT => $syncCount,
            self::ACTION_DELETE => $delete,
        ];
    }

    /**
     * @return array<string, array{allowed: bool, reasonCode: string|null, reason: string|null}>
     */
    private function deniedActions(string $reasonCode, bool $clearCache): array
    {
        $denied = $this->actionResult(false, $reasonCode);

        return [
            self::ACTION_VIEW => $denied,
            self::ACTION_TARGETED_REBUILD => $denied,
            self::ACTION_AUTOMATIC_REBUILD => $denied,
            self::ACTION_REBUILD_ALL => $denied,
            self::ACTION_CLEAR_DATA => $denied,
            self::ACTION_CLEAR_CACHE => $clearCache ? $this->actionResult(true) : $denied,
            self::ACTION_SYNC_COUNT => $denied,
            self::ACTION_DELETE => $denied,
        ];
    }

    /**
     * @return array{allowed: bool, reasonCode: string|null, reason: string|null}
     */
    private function actionResult(bool $allowed, ?string $reasonCode = null): array
    {
        return [
            'allowed' => $allowed,
            'reasonCode' => $allowed ? null : $reasonCode,
            'reason' => $allowed || $reasonCode === null ? null : $this->reasonMessage($reasonCode),
        ];
    }

    private function structuralReasonCode(
        array $configFindings,
        array $dependencyAvailability,
        array $backendIdentity,
    ): ?string {
        foreach ($configFindings as $finding) {
            if (($finding['severity'] ?? null) === ConfigIndexValidationResult::SEVERITY_ERROR) {
                return 'config-invalid';
            }
        }

        foreach (['element' => 'element-type-unavailable', 'transformer' => 'transformer-unavailable'] as $kind => $reason) {
            $dependency = $dependencyAvailability[$kind] ?? [];
            if (($dependency['available'] ?? false) === true) {
                continue;
            }

            return ($dependency['reason'] ?? null) === 'provider-disabled'
                ? 'dependency-owner-disabled'
                : $reason;
        }

        return $backendIdentity['available'] ? null : $backendIdentity['reasonCode'];
    }

    /**
     * @return array{
     *   available: bool,
     *   handle: string|null,
     *   type: string|null,
     *   fullIndexName: string|null,
     *   recordExists: bool,
     *   enabled: bool,
     *   configured: bool,
     *   supported: bool,
     *   constructible: bool,
     *   supportsSyncCount: bool,
     *   reasonCode: string|null
     * }
     */
    private function resolveStrictBackendIdentity(SearchIndex $index): array
    {
        $identity = $this->missingBackendIdentity();
        $handle = trim((string)$index->getEffectiveBackend());
        $identity['handle'] = $handle !== '' ? $handle : null;
        if ($handle === '') {
            $identity['reasonCode'] = 'backend-not-configured';
            return $identity;
        }

        $rawConfig = BaseConfigFileHelper::getConfigSection('search-manager', 'backends');
        $configuredBackend = null;
        if (array_key_exists($handle, $rawConfig)) {
            foreach (ConfiguredBackend::findAllFromConfig() as $candidate) {
                if ($candidate->handle === $handle) {
                    $configuredBackend = $candidate;
                    break;
                }
            }
            if ($configuredBackend === null) {
                $identity['reasonCode'] = 'backend-configuration-invalid';
                return $identity;
            }
        } else {
            $configuredBackend = ConfiguredBackend::findByHandle($handle);
        }

        if ($configuredBackend === null) {
            $identity['reasonCode'] = 'backend-not-found';
            return $identity;
        }

        $identity['recordExists'] = true;
        $identity['enabled'] = (bool)$configuredBackend->enabled;
        $identity['type'] = trim($configuredBackend->backendType) ?: null;
        if (!$configuredBackend->enabled) {
            $identity['reasonCode'] = 'backend-disabled';
            return $identity;
        }

        $identity['supported'] = isset(ConfiguredBackend::BACKEND_TYPES[$configuredBackend->backendType]);
        if (!$identity['supported']) {
            $identity['reasonCode'] = 'backend-type-unsupported';
            return $identity;
        }

        $configuredBackend->clearErrors();
        $identity['configured'] = $configuredBackend->validate(['backendType', 'settings']);
        if (!$identity['configured']) {
            $identity['reasonCode'] = 'backend-configuration-invalid';
            return $identity;
        }

        $backend = SearchManager::$plugin->backend->createBackendFromConfig($configuredBackend);
        $identity['constructible'] = $backend instanceof BackendInterface;
        if (!$identity['constructible']) {
            $identity['reasonCode'] = 'backend-not-constructible';
            return $identity;
        }

        $fullIndexName = trim(SearchManager::$plugin->getSettings()->getFullIndexName($index->handle));
        if ($fullIndexName === '') {
            $identity['reasonCode'] = 'backend-index-identity-invalid';
            return $identity;
        }

        $identity['fullIndexName'] = $fullIndexName;
        $identity['supportsSyncCount'] = in_array($configuredBackend->backendType, ['algolia', 'meilisearch', 'typesense'], true)
            && $backend instanceof IndexCountBackendInterface;
        $identity['available'] = true;
        $identity['reasonCode'] = null;
        $this->strictBackendTargets[$index->handle] = [
            'backend' => $backend,
            'handle' => $handle,
            'type' => $configuredBackend->backendType,
            'fullIndexName' => $fullIndexName,
        ];

        return $identity;
    }

    private function missingBackendIdentity(): array
    {
        return [
            'available' => false,
            'handle' => null,
            'type' => null,
            'fullIndexName' => null,
            'recordExists' => false,
            'enabled' => false,
            'configured' => false,
            'supported' => false,
            'constructible' => false,
            'supportsSyncCount' => false,
            'reasonCode' => 'backend-not-configured',
        ];
    }

    private function reasonMessage(string $reasonCode): string
    {
        return Craft::t('search-manager', match ($reasonCode) {
            'index-not-found' => 'This index no longer exists.',
            'config-invalid' => 'Fix this index in config/search-manager.php before using this action.',
            'config-definition-unresolved' => 'Fix this unresolved index definition in config/search-manager.php before rebuilding.',
            'element-type-unavailable' => 'The configured element type is unavailable. Restore it before using this action.',
            'transformer-unavailable' => 'The configured transformer is unavailable. Restore it before using this action.',
            'dependency-owner-disabled' => 'A plugin required by this index is disabled. Enable it before using this action.',
            'backend-not-configured' => 'Select a valid default backend or index backend before using this action.',
            'backend-not-found' => 'The selected backend does not exist. Correct the backend handle before using this action.',
            'backend-disabled' => 'The selected backend is disabled. Enable it or select another backend before using this action.',
            'backend-configuration-invalid' => 'The selected backend configuration is invalid. Correct it before using this action.',
            'backend-type-unsupported' => 'The selected backend type is not supported.',
            'backend-not-constructible' => 'The selected backend cannot be initialized from its configuration.',
            'backend-index-identity-invalid' => 'The index storage identity is invalid. Correct the index prefix or handle before using this action.',
            'backend-count-unsupported' => 'This backend does not support syncing the document count.',
            'index-disabled' => 'Disabled indices are excluded from automatic and Rebuild All operations.',
            'config-owned-index' => 'This index is defined in config and cannot be deleted.',
            'index-in-use' => 'This index is still in use and cannot be deleted.',
            'index-handle-collision' => 'Rebuild All is unavailable because one or more index handles exist in both config and the database.',
            'no-eligible-indices' => 'No enabled, structurally valid indices are eligible for Rebuild All.',
            default => 'This action is not available for the current index configuration.',
        });
    }

    private function getIndexConfigValidation(): ConfigIndexValidationResult
    {
        return $this->indexConfigValidation ??= SearchManager::$plugin->configIndexValidator->validate();
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
        return $this->getStyleUsageInventory()[$handle] ?? [];
    }

    /**
     * Count effective widget references for every Widget Style handle.
     *
     * @return array<string, int>
     * @since 5.54.0
     */
    public function getStyleUsageCountsByHandle(): array
    {
        return array_map(
            static fn(array $usages): int => count($usages),
            $this->getStyleUsageInventory(),
        );
    }

    /**
     * Group Widget Style references from the config-precedence-resolved widget set.
     *
     * @return array<string, array<int, array{type: string, label: string, kind: string}>>
     */
    private function getStyleUsageInventory(): array
    {
        $inventory = [];
        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widgetConfig) {
            $styleHandle = $widgetConfig->styleHandle;
            if ($styleHandle === null || trim($styleHandle) === '') {
                continue;
            }

            $inventory[$styleHandle][] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
                'kind' => 'widget',
            ];
        }

        return $inventory;
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
