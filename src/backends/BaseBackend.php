<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Base Backend
 *
 * Abstract base class for all search backend adapters
 * Provides common functionality and enforces the BackendInterface contract
 *
 * @since 5.0.0
 */
abstract class BaseBackend extends Component implements BackendInterface
{
    use LoggingTrait;

    /**
     * @var array|null Settings from a ConfiguredBackend (overrides global config)
     */
    protected ?array $_configuredSettings = null;

    /**
     * @var string|null Handle of the ConfiguredBackend this adapter is associated with
     */
    protected ?string $_backendHandle = null;

    /**
     * @var list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>
     */
    protected array $lastIndexingFailures = [];

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Set configured settings from a ConfiguredBackend
     * These settings will be used instead of global config
     *
     * @param array $settings
     * @return void
     * @since 5.28.0
     */
    public function setConfiguredSettings(array $settings): void
    {
        $this->_configuredSettings = $settings;
        $this->logDebug('ConfiguredSettings set', [
            'backend' => $this->getName(),
            'settingsKeys' => array_keys($settings),
            'host' => $settings['host'] ?? 'NOT SET',
            'port' => $settings['port'] ?? 'NOT SET',
        ]);
    }

    /**
     * Set the backend handle this adapter is associated with
     *
     * @since 5.28.0
     */
    public function setBackendHandle(string $handle): void
    {
        $this->_backendHandle = $handle;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the full index name with prefix
     *
     * @param string $indexName Base index name
     * @return string Full index name with prefix
     */
    protected function getFullIndexName(string $indexName): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($indexName);
    }

    /**
     * Get backend settings
     *
     * Priority: ConfiguredBackend settings > Config file
     *
     * @return array Backend configuration
     */
    protected function getBackendSettings(): array
    {
        // If we have configured settings from a ConfiguredBackend, use those
        if ($this->_configuredSettings !== null) {
            $this->logDebug('Using ConfiguredBackend settings', [
                'backend' => $this->getName(),
                'host' => $this->_configuredSettings['host'] ?? 'NOT SET',
            ]);
            return $this->_configuredSettings;
        }

        $this->logDebug('No ConfiguredBackend settings, falling back', [
            'backend' => $this->getName(),
        ]);

        // Try config file first
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('search-manager');
            $backends = $config['backends'] ?? [];
            if (isset($backends[$this->getName()])) {
                return $backends[$this->getName()];
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to load backend settings from config', [
                'backend' => $this->getName(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->logDebug('No backend settings found', [
            'backend' => $this->getName(),
        ]);

        return [];
    }

    /**
     * Check if backend is enabled in config
     *
     * @return bool
     */
    protected function isEnabledInConfig(): bool
    {
        $settings = $this->getBackendSettings();
        return ($settings['enabled'] ?? false) === true;
    }

    /**
     * Resolve environment variable
     * Strips $ prefix if present and calls App::env()
     *
     * @param mixed $value Config value (e.g., "$REDIS_HOST" or "REDIS_HOST" or "redis")
     * @param mixed $default Default value if env var not found
     * @return mixed Resolved value
     */
    protected function resolveEnvVar($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // If value starts with $, it's an env var reference
        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            return \craft\helpers\App::env($envVarName) ?? $default;
        }

        // Otherwise return the value as-is (it's a plain string, not an env var)
        return $value;
    }

    // =========================================================================
    // ABSTRACT METHODS (must be implemented by subclasses)
    // =========================================================================

    abstract public function index(string $indexName, array $data): bool;

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        $elementId = \lindemannrock\searchmanager\helpers\SearchHitIdentityHelper::elementId($data);
        $siteId = isset($data['siteId']) ? (int)$data['siteId'] : null;
        $exists = $elementId !== null ? $this->documentExists($indexName, $elementId, $siteId) : null;

        return [
            'success' => $this->index($indexName, $data),
            'wasCreated' => $exists === null ? null : !$exists,
        ];
    }

    abstract public function batchIndex(string $indexName, array $items): bool;

    /**
     * @return list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>
     * @since 5.53.0
     */
    public function getLastIndexingFailures(): array
    {
        return $this->lastIndexingFailures;
    }

    protected function clearLastIndexingFailures(): void
    {
        $this->lastIndexingFailures = [];
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function recordIndexingFailure(array $document, string $error): void
    {
        $elementId = SearchHitIdentityHelper::elementId($document);
        $this->lastIndexingFailures[] = [
            'backendId' => SearchHitIdentityHelper::documentId($document),
            'elementId' => $elementId,
            'title' => isset($document['title']) && is_scalar($document['title']) ? (string)$document['title'] : null,
            'error' => $error,
        ];
    }

    /**
     * @inheritdoc
     * @since 5.45.0
     */
    public function batchDelete(string $indexName, array $items): bool
    {
        $success = true;

        foreach ($items as $item) {
            if (isset($item['backendId']) && is_string($item['backendId']) && $item['backendId'] !== '') {
                if (!$this->deleteByBackendId($indexName, $item['backendId'])) {
                    $success = false;
                }
                continue;
            }

            $elementId = (int)($item['elementId'] ?? $item['id'] ?? 0);
            if ($elementId <= 0) {
                $success = false;
                continue;
            }

            $siteId = isset($item['siteId']) ? (int)$item['siteId'] : null;
            if (!$this->delete($indexName, $elementId, $siteId)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @inheritdoc
     * @since 5.55.0
     */
    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        $keep = array_flip(array_map('strval', $keepBackendIds));
        $deleteItems = [];

        foreach ($this->browseDocumentsForElement($indexName, $elementId, $siteId) as $hit) {
            if (!is_array($hit)) {
                continue;
            }

            $hit = SearchHitIdentityHelper::normalizeHit($hit);
            if (SearchHitIdentityHelper::elementId($hit) !== $elementId) {
                continue;
            }

            if ($siteId !== null && isset($hit['siteId']) && (int)$hit['siteId'] !== $siteId) {
                continue;
            }

            $backendId = SearchHitIdentityHelper::rawBackendId($hit);
            if ($backendId === null || isset($keep[$backendId])) {
                continue;
            }

            $deleteItems[] = ['backendId' => $backendId];
        }

        return $deleteItems === [] || $this->batchDelete($indexName, $deleteItems);
    }

    protected function browseDocumentsForElement(string $indexName, int $elementId, ?int $siteId): iterable
    {
        return $this->browse($indexName);
    }

    protected function deleteByBackendId(string $indexName, string $backendId): bool
    {
        return false;
    }

    abstract public function delete(string $indexName, int $elementId, ?int $siteId = null): bool;

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        $exists = $this->documentExists($indexName, $elementId, $siteId);
        if (!$exists) {
            return [
                'success' => true,
                'existed' => false,
            ];
        }

        return [
            'success' => $this->delete($indexName, $elementId, $siteId),
            'existed' => true,
        ];
    }

    abstract public function search(string $indexName, string $query, array $options = []): array;

    abstract public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array;

    abstract public function clearIndex(string $indexName): bool;

    abstract public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool;

    abstract public function isAvailable(): bool;

    abstract public function getStatus(): array;

    abstract public function getName(): string;

    /**
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, int> $elementIds
     * @return array<int, array<string, mixed>>
     */
    protected function bestDocumentsByElementId(array $documents, array $elementIds, ?int $siteId = null): array
    {
        $requested = array_flip(array_values(array_unique(array_map('intval', $elementIds))));
        $candidates = [];

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            $document = SearchHitIdentityHelper::normalizeHit($document);
            $elementId = SearchHitIdentityHelper::elementId($document);
            if ($elementId === null || !isset($requested[$elementId])) {
                continue;
            }

            if ($siteId !== null && isset($document['siteId']) && (int)$document['siteId'] !== $siteId) {
                continue;
            }

            $candidates[$elementId][] = $document;
        }

        $selected = [];
        foreach ($candidates as $elementId => $elementDocuments) {
            usort($elementDocuments, fn(array $a, array $b): int => $this->indexedDocumentSortTuple($a) <=> $this->indexedDocumentSortTuple($b));
            $selected[(int)$elementId] = $elementDocuments[0];
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{0: int, 1: int, 2: string}
     */
    private function indexedDocumentSortTuple(array $document): array
    {
        $siteId = isset($document['siteId']) && is_numeric($document['siteId'])
            ? (int)$document['siteId']
            : PHP_INT_MAX;
        $sectionId = isset($document['sectionId']) ? (string)$document['sectionId'] : '';
        $sectionIndex = isset($document['sectionIndex']) && is_numeric($document['sectionIndex'])
            ? (int)$document['sectionIndex']
            : ($sectionId === '' ? 0 : PHP_INT_MAX);
        $sectionRank = $sectionId === 'intro' ? -1 : $sectionIndex;

        return [
            $siteId,
            $sectionRank,
            SearchHitIdentityHelper::rawBackendId($document) ?? '',
        ];
    }

    // =========================================================================
    // DEFAULT IMPLEMENTATIONS (can be overridden by subclasses)
    // =========================================================================

    /**
     * Browse/iterate through all documents in an index
     * Default: not supported, returns empty array
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        $this->logWarning('browse() not supported by this backend', ['backend' => $this->getName()]);
        return [];
    }

    /**
     * Perform multiple search queries in a single request
     * Default: sequential search fallback
     */
    public function multipleQueries(array $queries = []): array
    {
        // Fallback: execute queries sequentially
        $results = [];
        foreach ($queries as $query) {
            $indexName = $query['indexName'] ?? '';
            $searchQuery = $query['query'] ?? '';
            $params = $query['params'] ?? [];

            $results[] = $this->search($indexName, $searchQuery, $params);
        }

        return ['results' => $results];
    }

    /**
     * Parse filters array into backend-specific filter string
     * Default: basic SQL-like syntax
     */
    public function parseFilters(array $filters = []): string
    {
        $filterParts = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                // Multiple values = OR condition
                $orParts = array_map(function($v) use ($key) {
                    $v = is_bool($v) ? ($v ? 'true' : 'false') : str_replace('"', '\\"', (string) $v);
                    return $key . ' = "' . $v . '"';
                }, $value);
                $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
            } else {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : str_replace('"', '\\"', (string) $value);
                $filterParts[] = $key . ' = "' . $value . '"';
            }
        }

        return implode(' AND ', $filterParts);
    }

    /**
     * Check if this backend supports browse functionality
     */
    public function supportsBrowse(): bool
    {
        return false;
    }

    /**
     * Check if this backend supports multiple queries
     */
    public function supportsMultipleQueries(): bool
    {
        return false;
    }

    /**
     * List all indices available in the backend
     * Default: returns indices from database that use this backend
     */
    public function listIndices(): array
    {
        $settings = SearchManager::$plugin->getSettings();
        $indices = [];

        // Get indices from database that use this backend
        $searchIndices = SearchIndex::findAll();
        $defaultBackendHandle = $settings->defaultBackendHandle;

        foreach ($searchIndices as $searchIndex) {
            // Check if this index uses this backend
            $indexBackend = $searchIndex->backend ?: $defaultBackendHandle;

            if ($this->_backendHandle && $indexBackend === $this->_backendHandle) {
                $indices[] = [
                    'name' => $this->getFullIndexName($searchIndex->handle),
                    'handle' => $searchIndex->handle,
                    'entries' => $searchIndex->documentCount,
                    'source' => $searchIndex->source,
                ];
            }
        }

        // Also check config-based indices
        foreach ($settings->indices ?? [] as $handle => $config) {
            $indexBackend = $config['backend'] ?? $defaultBackendHandle;

            if ($this->_backendHandle && $indexBackend === $this->_backendHandle) {
                // Check if not already added from database
                $alreadyAdded = false;
                foreach ($indices as $index) {
                    if ($index['handle'] === $handle) {
                        $alreadyAdded = true;
                        break;
                    }
                }

                if (!$alreadyAdded) {
                    $indices[] = [
                        'name' => $this->getFullIndexName($handle),
                        'handle' => $handle,
                        'source' => 'config',
                    ];
                }
            }
        }

        return $indices;
    }
}
