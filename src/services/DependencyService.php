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
use craft\base\Model;
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
                $findings = $index->source === 'config'
                    ? $validation->getFindingsForHandle($index->handle)
                    : [];
                $errorTitle = $this->firstConfigError($findings);
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
    private function firstConfigError(array $findings): ?string
    {
        foreach ($findings as $finding) {
            if ($finding['severity'] === ConfigIndexValidationResult::SEVERITY_ERROR) {
                return $finding['message'];
            }
        }

        return null;
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
