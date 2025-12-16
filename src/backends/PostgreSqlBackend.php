<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\SearchManager;

/**
 * PostgreSQL Backend
 *
 * Search backend using BM25 algorithm with PostgreSQL storage
 * Includes fuzzy matching, title boosting, and n-gram based typo tolerance
 * Uses the same storage implementation as MySQL (Yii Query Builder is database-agnostic)
 */
class PostgreSqlBackend extends BaseBackend
{
    /**
     * @var array<string, SearchEngine> Search engine instances per index
     */
    private array $searchEngines = [];

    /**
     * @var array<string, MySqlStorage> Storage instances per index
     */
    private array $storages = [];

    /**
     * Get or create SearchEngine instance for an index
     *
     * @param string $indexHandle Index handle
     * @return SearchEngine
     */
    private function getSearchEngine(string $indexHandle): SearchEngine
    {
        if (!isset($this->searchEngines[$indexHandle])) {
            $storage = $this->getStorage($indexHandle);
            $settings = SearchManager::$plugin->getSettings();

            $this->searchEngines[$indexHandle] = new SearchEngine($storage, $indexHandle, [
                'k1' => $settings->bm25K1 ?? 1.5,
                'b' => $settings->bm25B ?? 0.75,
                'titleBoost' => $settings->titleBoostFactor ?? 5.0,
                'exactMatchBoost' => $settings->exactMatchBoostFactor ?? 3.0,
                'phraseBoost' => $settings->phraseBoostFactor ?? 4.0,
                'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
                'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
                'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
            ]);
        }

        return $this->searchEngines[$indexHandle];
    }

    /**
     * Get or create storage instance (public for autocomplete/other services)
     *
     * @param string $indexHandle Index handle
     * @return MySqlStorage
     */
    public function getStorage(string $indexHandle): MySqlStorage
    {
        if (!isset($this->storages[$indexHandle])) {
            $this->storages[$indexHandle] = new MySqlStorage($indexHandle);
        }

        return $this->storages[$indexHandle];
    }

    public function getName(): string
    {
        return 'pgsql';
    }

    public function isAvailable(): bool
    {
        // PostgreSQL backend is available only if Craft uses PostgreSQL
        return Craft::$app->getDb()->getDriverName() === 'pgsql';
    }

    public function getStatus(): array
    {
        return [
            'name' => 'Craft Database (PostgreSQL)',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'driver' => 'pgsql',
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            // Extract title and content
            $title = $data['title'] ?? '';
            $content = implode(' ', [
                $data['content'] ?? '',
                $data['excerpt'] ?? '',
                $data['body'] ?? '',
            ]);

            // Get site ID and element ID
            $siteId = $data['siteId'] ?? 1;
            $elementId = $data['objectID'] ?? $data['id'];

            // Use SearchEngine to index
            $success = $engine->indexDocument($siteId, $elementId, $title, $content);

            if ($success) {
                $this->logDebug('Document indexed with SearchEngine', [
                    'index' => $fullIndexName,
                    'element_id' => $elementId,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in PostgreSQL', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        foreach ($items as $item) {
            $this->index($indexName, $item);
        }
        $this->logInfo('Batch indexed in PostgreSQL', ['count' => count($items)]);
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $fullIndexName,
                    'element_id' => $elementId,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from PostgreSQL', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            // Get site ID from options or use current site
            $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $limit = $options['limit'] ?? 0;

            // Use SearchEngine to search
            $results = $engine->search($query, $siteId, $limit);

            // Convert to backend format (element IDs with scores)
            $hits = [];
            foreach ($results as $elementId => $score) {
                $hits[] = [
                    'objectID' => $elementId,
                    'id' => $elementId,
                    'score' => $score,
                ];
            }

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $fullIndexName,
                'query' => $query,
                'result_count' => count($hits),
            ]);

            return ['hits' => $hits, 'total' => count($hits)];
        } catch (\Throwable $e) {
            $this->logError('PostgreSQL search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $storage = new MySqlStorage($fullIndexName);

            // Clear all data for this index
            $storage->clearAll();

            $this->logInfo('Cleared PostgreSQL index with SearchEngine', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear PostgreSQL index', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
