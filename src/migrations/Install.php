<?php

namespace lindemannrock\searchmanager\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install migration for Search Manager plugin
 *
 * Creates all database tables required for the plugin:
 * - Settings table (single row, database-backed settings)
 * - Backend settings table (per-backend configuration)
 * - Indices table (hybrid config - can be in config file OR database)
 * - Transformers table (element type to transformer mappings)
 * - Index queue table (for async indexing operations)
 * - Index stats table (for dashboard analytics)
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createSettingsTable();
        $this->createBackendSettingsTable();
        $this->createIndicesTable();
        $this->createTransformersTable();
        $this->createIndexQueueTable();
        $this->createIndexStatsTable();
        $this->createAnalyticsTable();
        $this->createSearchEngineTables();

        // Insert default data
        $this->insertDefaultSettings();
        $this->insertDefaultBackendSettings();

        return true;
    }

    public function safeDown(): bool
    {
        // Drop tables in reverse order (respecting dependencies)
        $this->dropTableIfExists('{{%searchmanager_search_metadata}}');
        $this->dropTableIfExists('{{%searchmanager_search_ngram_counts}}');
        $this->dropTableIfExists('{{%searchmanager_search_ngrams}}');
        $this->dropTableIfExists('{{%searchmanager_search_titles}}');
        $this->dropTableIfExists('{{%searchmanager_search_terms}}');
        $this->dropTableIfExists('{{%searchmanager_search_documents}}');
        $this->dropTableIfExists('{{%searchmanager_analytics}}');
        $this->dropTableIfExists('{{%searchmanager_index_stats}}');
        $this->dropTableIfExists('{{%searchmanager_index_queue}}');
        $this->dropTableIfExists('{{%searchmanager_transformers}}');
        $this->dropTableIfExists('{{%searchmanager_indices}}');
        $this->dropTableIfExists('{{%searchmanager_backend_settings}}');
        $this->dropTableIfExists('{{%searchmanager_settings}}');

        return true;
    }

    /**
     * Create settings table (single row, always ID=1)
     * Stores global plugin settings that can be overridden by config file
     */
    private function createSettingsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_settings}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_settings}}', [
            'id' => $this->primaryKey(),
            'pluginName' => $this->string(255)->notNull()->defaultValue('Search Manager'),
            'logLevel' => $this->enum('logLevel', ['debug', 'info', 'warning', 'error'])->notNull()->defaultValue('error'),
            'itemsPerPage' => $this->integer()->notNull()->defaultValue(100),
            'autoIndex' => $this->boolean()->notNull()->defaultValue(true),
            'searchBackend' => $this->enum('searchBackend', ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense'])->notNull()->defaultValue('file'),
            'batchSize' => $this->integer()->notNull()->defaultValue(100),
            'queueEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'replaceNativeSearch' => $this->boolean()->notNull()->defaultValue(false),
            'enableAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            'analyticsRetention' => $this->integer()->notNull()->defaultValue(90),
            'anonymizeIpAddress' => $this->boolean()->notNull()->defaultValue(false),
            'enableGeoDetection' => $this->boolean()->notNull()->defaultValue(false),
            'cacheDeviceDetection' => $this->boolean()->notNull()->defaultValue(true),
            'deviceDetectionCacheDuration' => $this->integer()->notNull()->defaultValue(3600),
            'indexPrefix' => $this->string(50)->null(),
            // BM25 Algorithm Parameters
            'bm25K1' => $this->decimal(3, 2)->notNull()->defaultValue(1.5),
            'bm25B' => $this->decimal(3, 2)->notNull()->defaultValue(0.75),
            'titleBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(5.0),
            'exactMatchBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(3.0),
            'phraseBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(4.0),
            'ngramSizes' => $this->string(50)->notNull()->defaultValue('2,3'),
            'similarityThreshold' => $this->decimal(3, 2)->notNull()->defaultValue(0.50),
            'maxFuzzyCandidates' => $this->integer()->notNull()->defaultValue(100),
            // Language & Stop Words
            'enableStopWords' => $this->boolean()->notNull()->defaultValue(true),
            'defaultLanguage' => $this->string(10)->null(),
            // Highlighting Settings
            'enableHighlighting' => $this->boolean()->notNull()->defaultValue(true),
            'highlightTag' => $this->string(20)->notNull()->defaultValue('mark'),
            'highlightClass' => $this->string(100)->null(),
            'snippetLength' => $this->integer()->notNull()->defaultValue(200),
            'maxSnippets' => $this->integer()->notNull()->defaultValue(3),
            // Autocomplete Settings
            'enableAutocomplete' => $this->boolean()->notNull()->defaultValue(true),
            'autocompleteMinLength' => $this->integer()->notNull()->defaultValue(2),
            'autocompleteLimit' => $this->integer()->notNull()->defaultValue(10),
            'autocompleteFuzzy' => $this->boolean()->notNull()->defaultValue(false),
            // Cache Settings
            'enableCache' => $this->boolean()->notNull()->defaultValue(true),
            'cacheDuration' => $this->integer()->notNull()->defaultValue(3600),
            'cacheStorageMethod' => $this->string(10)->notNull()->defaultValue('file')->comment('Cache storage method: file or redis'),
            'cachePopularQueriesOnly' => $this->boolean()->notNull()->defaultValue(false),
            'popularQueryThreshold' => $this->integer()->notNull()->defaultValue(5),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Create backend settings table
     * Stores configuration for each search backend (Algolia, Meilisearch, etc.)
     */
    private function createBackendSettingsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_backend_settings}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_backend_settings}}', [
            'id' => $this->primaryKey(),
            'backend' => $this->enum('backend', ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense'])->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'configJson' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create unique index on backend
        $this->createIndex(null, '{{%searchmanager_backend_settings}}', ['backend'], true);
    }

    /**
     * Create indices table
     * Stores search index configurations (can be from config file or database)
     */
    private function createIndicesTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_indices}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_indices}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'elementType' => $this->string(255)->notNull(),
            'siteId' => $this->integer()->null(),
            'criteriaJson' => $this->text()->null(),
            'transformerClass' => $this->string(255)->notNull(),
            'language' => $this->string(10)->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'source' => $this->enum('source', ['config', 'database'])->notNull()->defaultValue('database'),
            'lastIndexed' => $this->dateTime()->null(),
            'documentCount' => $this->integer()->notNull()->defaultValue(0),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for performance
        $this->createIndex(null, '{{%searchmanager_indices}}', ['handle'], true);
        $this->createIndex(null, '{{%searchmanager_indices}}', ['elementType'], false);
        $this->createIndex(null, '{{%searchmanager_indices}}', ['enabled'], false);
        $this->createIndex(null, '{{%searchmanager_indices}}', ['source'], false);
    }

    /**
     * Create transformers table
     * Maps element types to transformer classes
     */
    private function createTransformersTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_transformers}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_transformers}}', [
            'id' => $this->primaryKey(),
            'elementType' => $this->string(255)->notNull(),
            'siteId' => $this->integer()->null(),
            'section' => $this->string(255)->null(),
            'transformerClass' => $this->string(255)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'priority' => $this->integer()->notNull()->defaultValue(0),
            'configJson' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes
        $this->createIndex(null, '{{%searchmanager_transformers}}', ['elementType', 'siteId'], false);
        $this->createIndex(null, '{{%searchmanager_transformers}}', ['enabled'], false);

        // Create unique constraint for element type + site + section combination
        $this->createIndex(null, '{{%searchmanager_transformers}}', ['elementType', 'siteId', 'section'], true);
    }

    /**
     * Create index queue table
     * Tracks pending indexing operations for async processing
     */
    private function createIndexQueueTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_index_queue}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_index_queue}}', [
            'id' => $this->primaryKey(),
            'elementId' => $this->integer()->notNull(),
            'elementType' => $this->string(255)->notNull(),
            'siteId' => $this->integer()->null(),
            'action' => $this->enum('action', ['index', 'delete'])->notNull(),
            'status' => $this->enum('status', ['pending', 'processing', 'completed', 'failed'])->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastAttempt' => $this->dateTime()->null(),
            'error' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for queue processing
        $this->createIndex(null, '{{%searchmanager_index_queue}}', ['elementId', 'elementType'], false);
        $this->createIndex(null, '{{%searchmanager_index_queue}}', ['status'], false);
        $this->createIndex(null, '{{%searchmanager_index_queue}}', ['action'], false);
        $this->createIndex(null, '{{%searchmanager_index_queue}}', ['dateCreated'], false);
    }

    /**
     * Create index stats table
     * Stores daily statistics for dashboard/analytics
     */
    private function createIndexStatsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_index_stats}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_index_stats}}', [
            'id' => $this->primaryKey(),
            'indexHandle' => $this->string(255)->notNull(),
            'date' => $this->date()->notNull(),
            'documentsIndexed' => $this->integer()->notNull()->defaultValue(0),
            'documentsDeleted' => $this->integer()->notNull()->defaultValue(0),
            'searchQueries' => $this->integer()->notNull()->defaultValue(0),
            'avgSearchTime' => $this->float()->null(),
            'errorCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for analytics queries
        $this->createIndex(null, '{{%searchmanager_index_stats}}', ['indexHandle', 'date'], true);
        $this->createIndex(null, '{{%searchmanager_index_stats}}', ['date'], false);
    }

    /**
     * Insert default settings (single row with ID=1)
     */
    private function insertDefaultSettings(): void
    {
        $this->insert('{{%searchmanager_settings}}', [
            'id' => 1,
            'pluginName' => 'Search Manager',
            'logLevel' => 'error',
            'itemsPerPage' => 100,
            'autoIndex' => 1,
            'searchBackend' => 'file',
            'batchSize' => 100,
            'queueEnabled' => 1,
            'replaceNativeSearch' => 0,
            'enableAnalytics' => 1,
            'analyticsRetention' => 90,
            'indexPrefix' => null,
            'bm25K1' => 1.5,
            'bm25B' => 0.75,
            'titleBoostFactor' => 5.0,
            'exactMatchBoostFactor' => 3.0,
            'phraseBoostFactor' => 4.0,
            'ngramSizes' => '2,3',
            'similarityThreshold' => 0.50,
            'maxFuzzyCandidates' => 100,
            'enableStopWords' => 1,
            'defaultLanguage' => null,
            'enableHighlighting' => 1,
            'highlightTag' => 'mark',
            'highlightClass' => null,
            'snippetLength' => 200,
            'maxSnippets' => 3,
            'enableAutocomplete' => 1,
            'autocompleteMinLength' => 2,
            'autocompleteLimit' => 10,
            'autocompleteFuzzy' => 0,
            'enableCache' => 1,
            'cacheDuration' => 3600,
            'cachePopularQueriesOnly' => 0,
            'popularQueryThreshold' => 5,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Insert default backend settings (one row per backend)
     */
    private function insertDefaultBackendSettings(): void
    {
        $backends = ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense'];
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($backends as $backend) {
            $this->insert('{{%searchmanager_backend_settings}}', [
                'backend' => $backend,
                'enabled' => 0,
                'configJson' => '{}',
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }

    /**
     * Create analytics table
     * Stores search query analytics (what users search for, results, performance)
     */
    private function createAnalyticsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_analytics}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_analytics}}', [
            'id' => $this->primaryKey(),
            'indexHandle' => $this->string(255)->notNull(),
            'query' => $this->string(500)->notNull(),
            'resultsCount' => $this->integer()->notNull()->defaultValue(0),
            'executionTime' => $this->float()->null(),
            'backend' => $this->string(50)->notNull(),
            'siteId' => $this->integer()->null(),
            'ip' => $this->string(64)->null(),
            'userAgent' => $this->text()->null(),
            'referer' => $this->string()->null(),
            'isHit' => $this->boolean()->notNull()->defaultValue(true),
            // Device detection fields (via Matomo DeviceDetector)
            'deviceType' => $this->string(50)->null(),
            'deviceBrand' => $this->string(50)->null(),
            'deviceModel' => $this->string(100)->null(),
            'browser' => $this->string(100)->null(),
            'browserVersion' => $this->string(20)->null(),
            'browserEngine' => $this->string(50)->null(),
            'osName' => $this->string(50)->null(),
            'osVersion' => $this->string(50)->null(),
            'clientType' => $this->string(50)->null(),
            'isRobot' => $this->boolean()->defaultValue(false),
            'isMobileApp' => $this->boolean()->defaultValue(false),
            'botName' => $this->string(100)->null(),
            // Geo-location fields
            'country' => $this->string(2)->null(),
            'city' => $this->string(100)->null(),
            'language' => $this->string(10)->null(),
            'region' => $this->string(100)->null(),
            'latitude' => $this->decimal(10, 8)->null(),
            'longitude' => $this->decimal(11, 8)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for analytics queries
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['indexHandle'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['query'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['backend'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['isHit'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['deviceType'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['browser'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['osName'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['clientType'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['isRobot'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['dateCreated'], false);
    }

    /**
     * Create search engine tables for BM25 search implementation
     * Used by MySQL, File, and Redis backends for inverted index storage
     */
    private function createSearchEngineTables(): void
    {
        // Documents table: stores term frequencies and document length per document
        if (!$this->db->tableExists('{{%searchmanager_search_documents}}')) {
            $this->createTable('{{%searchmanager_search_documents}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'term' => $this->string(255)->notNull(),
                'frequency' => $this->integer()->notNull(),
                'language' => $this->string(10)->notNull()->defaultValue('en'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'elementId', 'term']);
            $this->createIndex(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'elementId'], false);
            $this->createIndex(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'language'], false);
            // Performance index for term lookups (search queries)
            $this->createIndex('idx_term_lookup', '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'term'], false);
        }

        // Terms table: inverted index mapping terms to documents
        if (!$this->db->tableExists('{{%searchmanager_search_terms}}')) {
            $this->createTable('{{%searchmanager_search_terms}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'term' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'frequency' => $this->integer()->notNull(),
                'language' => $this->string(10)->notNull()->defaultValue('en'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'term', 'siteId', 'elementId']);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'term', 'siteId'], false);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'language'], false);
        }

        // Titles table: stores terms that appear in document titles for boosting
        if (!$this->db->tableExists('{{%searchmanager_search_titles}}')) {
            $this->createTable('{{%searchmanager_search_titles}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'term' => $this->string(255)->notNull(),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_titles}}', ['indexHandle', 'siteId', 'elementId', 'term']);
            $this->createIndex(null, '{{%searchmanager_search_titles}}', ['indexHandle', 'term', 'siteId'], false);
        }

        // N-grams table: stores n-grams for fuzzy matching
        if (!$this->db->tableExists('{{%searchmanager_search_ngrams}}')) {
            $this->createTable('{{%searchmanager_search_ngrams}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'ngram' => $this->string(10)->notNull(),
                'term' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_ngrams}}', ['indexHandle', 'ngram', 'term', 'siteId']);
            $this->createIndex(null, '{{%searchmanager_search_ngrams}}', ['indexHandle', 'ngram', 'siteId'], false);
            // Performance index for n-gram lookups (fuzzy search)
            $this->createIndex('idx_ngram_lookup', '{{%searchmanager_search_ngrams}}', ['indexHandle', 'siteId', 'ngram'], false);
        }

        // N-gram counts table: stores n-gram count per term for Jaccard similarity
        if (!$this->db->tableExists('{{%searchmanager_search_ngram_counts}}')) {
            $this->createTable('{{%searchmanager_search_ngram_counts}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'term' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'ngramCount' => $this->integer()->notNull(),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_ngram_counts}}', ['indexHandle', 'term', 'siteId']);
            // Performance index for fuzzy matching JOIN optimization
            $this->createIndex('idx_ngram_count_lookup', '{{%searchmanager_search_ngram_counts}}', ['indexHandle', 'siteId', 'term', 'ngramCount'], false);
        }

        // Metadata table: stores global statistics (doc count, total length, etc.)
        if (!$this->db->tableExists('{{%searchmanager_search_metadata}}')) {
            $this->createTable('{{%searchmanager_search_metadata}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'metaKey' => $this->string(100)->notNull(),
                'metaValue' => $this->text()->notNull(),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_metadata}}', ['indexHandle', 'siteId', 'metaKey']);
        }
    }
}
