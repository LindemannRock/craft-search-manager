<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install migration for Search Manager plugin
 *
 * Creates all database tables required for the plugin:
 * - Settings table (single row, database-backed settings)
 * - Backends table (configured backend instances)
 * - Indices table (hybrid config - can be in config file OR database)
 * - Transformers table (element type to transformer mappings)
 * - Index queue table (for async indexing operations)
 * - Index stats table (for dashboard analytics)
 *
 * @since 5.0.0
 */
class Install extends Migration
{
    /** @inheritdoc */
    public function safeUp(): bool
    {
        $this->createSettingsTable();
        $this->createBackendsTable();
        $this->createIndicesTable();
        $this->createIndexSitesTable();
        $this->createTransformersTable();
        $this->createIndexQueueTable();
        $this->createPendingSyncsTable();
        $this->createIndexStatsTable();
        $this->createAnalyticsTable();
        $this->createSearchEngineTables();
        $this->createPromotionsTable();
        $this->createQueryRulesTable();
        $this->createRuleAnalyticsTable();
        $this->createPromotionAnalyticsTable();
        $this->createWidgetStylesTable();
        $this->createWidgetConfigsTable();
        $this->createApiKeysTable();

        // Insert default data
        $this->insertDefaultSettings();
        $this->insertDefaultWidgetStyle();
        $this->insertDefaultWidgetConfig();

        return true;
    }

    /** @inheritdoc */
    public function safeDown(): bool
    {
        // Drop tables in reverse order (respecting dependencies)
        $this->dropTableIfExists('{{%searchmanager_api_keys}}');
        $this->dropTableIfExists('{{%searchmanager_widget_configs}}');
        $this->dropTableIfExists('{{%searchmanager_widget_styles}}');
        $this->dropTableIfExists('{{%searchmanager_promotion_analytics}}');
        $this->dropTableIfExists('{{%searchmanager_rule_analytics}}');
        $this->dropTableIfExists('{{%searchmanager_query_rules}}');
        $this->dropTableIfExists('{{%searchmanager_promotions}}');
        $this->dropTableIfExists('{{%searchmanager_search_compounds}}');
        $this->dropTableIfExists('{{%searchmanager_search_elements}}');
        $this->dropTableIfExists('{{%searchmanager_search_metadata}}');
        $this->dropTableIfExists('{{%searchmanager_search_ngram_counts}}');
        $this->dropTableIfExists('{{%searchmanager_search_ngrams}}');
        $this->dropTableIfExists('{{%searchmanager_search_titles}}');
        $this->dropTableIfExists('{{%searchmanager_search_terms}}');
        $this->dropTableIfExists('{{%searchmanager_search_documents}}');
        $this->dropTableIfExists('{{%searchmanager_analytics}}');
        $this->dropTableIfExists('{{%searchmanager_index_stats}}');
        $this->dropTableIfExists('{{%searchmanager_pending_syncs}}');
        $this->dropTableIfExists('{{%searchmanager_index_queue}}');
        $this->dropTableIfExists('{{%searchmanager_transformers}}');
        $this->dropTableIfExists('{{%searchmanager_index_sites}}');
        $this->dropTableIfExists('{{%searchmanager_indices}}');
        $this->dropTableIfExists('{{%searchmanager_backends}}');
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
            'defaultBackendHandle' => $this->string(255)->null()->comment('Handle of the default configured backend'),
            'defaultWidgetHandle' => $this->string(255)->null()->comment('Handle of the default widget config'),
            'batchSize' => $this->integer()->notNull()->defaultValue(100),
            'lastIndexedDebounceSeconds' => $this->integer()->notNull()->defaultValue(60),
            'syncBatchSize' => $this->integer()->notNull()->defaultValue(200),
            'batchFlushInterval' => $this->integer()->notNull()->defaultValue(5),
            'pendingMaxAge' => $this->integer()->notNull()->defaultValue(3600),
            'batchMaxAttempts' => $this->integer()->notNull()->defaultValue(5),
            'replaceNativeSearch' => $this->boolean()->notNull()->defaultValue(false),
            'requireApiKey' => $this->boolean()->notNull()->defaultValue(false),
            'enableAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            'analyticsRetention' => $this->integer()->notNull()->defaultValue(90),
            'anonymizeIpAddress' => $this->boolean()->notNull()->defaultValue(false),
            'enableGeoDetection' => $this->boolean()->notNull()->defaultValue(false),
            'geoProvider' => $this->string(50)->notNull()->defaultValue('ip-api.com'),
            'geoApiKey' => $this->string(255)->null(),
            'cacheDeviceDetection' => $this->boolean()->notNull()->defaultValue(true),
            'deviceDetectionCacheDuration' => $this->integer()->notNull()->defaultValue(3600),
            'indexPrefix' => $this->string(50)->null(),
            // BM25 Algorithm Parameters
            'bm25K1' => $this->decimal(3, 2)->notNull()->defaultValue(1.5),
            'bm25B' => $this->decimal(3, 2)->notNull()->defaultValue(0.75),
            'titleBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(5.0),
            'exactMatchBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(3.0),
            'phraseBoostFactor' => $this->decimal(4, 1)->notNull()->defaultValue(4.0),
            'enableFuzzy' => $this->boolean()->notNull()->defaultValue(true),
            'ngramSizes' => $this->string(50)->notNull()->defaultValue('2,3'),
            'similarityThreshold' => $this->decimal(3, 2)->notNull()->defaultValue(0.25),
            'maxFuzzyCandidates' => $this->integer()->notNull()->defaultValue(100),
            // Language & Stop Words
            'enableStopWords' => $this->boolean()->notNull()->defaultValue(true),
            'defaultLanguage' => $this->string(10)->null(),
            // Highlighting Settings (for template helpers, not widget)
            'highlightResultsEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'highlightTag' => $this->string(20)->notNull()->defaultValue('mark'),
            'highlightClass' => $this->string(100)->null(),
            'snippetMaxLength' => $this->integer()->notNull()->defaultValue(200),
            'maxSnippets' => $this->integer()->notNull()->defaultValue(3),
            // Autocomplete Settings
            'enableAutocomplete' => $this->boolean()->notNull()->defaultValue(true),
            'autocompleteMinLength' => $this->integer()->notNull()->defaultValue(2),
            'autocompleteLimit' => $this->integer()->notNull()->defaultValue(10),
            'enableAutocompleteCache' => $this->boolean()->notNull()->defaultValue(true),
            'autocompleteCacheDuration' => $this->integer()->notNull()->defaultValue(300),
            // Cache Settings
            'enableCache' => $this->boolean()->notNull()->defaultValue(true),
            'cacheDuration' => $this->integer()->notNull()->defaultValue(3600),
            'cacheStorageMethod' => $this->string(10)->notNull()->defaultValue('file')->comment('Cache storage method: file or redis'),
            'clearCacheOnSave' => $this->boolean()->notNull()->defaultValue(true),
            'statusSyncInterval' => $this->integer()->notNull()->defaultValue(15),
            // Cache Warming Settings
            'enableCacheWarming' => $this->boolean()->notNull()->defaultValue(true),
            'cacheWarmingQueryCount' => $this->integer()->notNull()->defaultValue(50),
            // Base plugin overrides — nullable. Null = inherit from config/lindemannrock-base.php.
            'timeFormat' => $this->string(2)->null(),
            'monthFormat' => $this->string(20)->null(),
            'dateOrder' => $this->string(3)->null(),
            'dateSeparator' => $this->string(1)->null(),
            'showSeconds' => $this->boolean()->null(),
            'defaultDateRange' => $this->string(15)->null(),
            'exportsCsv' => $this->boolean()->null(),
            'exportsJson' => $this->boolean()->null(),
            'exportsExcel' => $this->boolean()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * Create backends table
     * Stores configured backend instances (e.g., "Production Algolia", "Dev Meilisearch")
     */
    private function createBackendsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_backends}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_backends}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'backendType' => $this->enum('backendType', ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense'])->notNull(),
            'settings' => $this->text()->null()->comment('JSON settings for the backend'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create unique index on handle
        $this->createIndex(null, '{{%searchmanager_backends}}', ['handle'], true);
        $this->createIndex(null, '{{%searchmanager_backends}}', ['backendType'], false);
        $this->createIndex(null, '{{%searchmanager_backends}}', ['enabled'], false);
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
            'criteria' => $this->text()->null(),
            'transformerClass' => $this->string(255)->notNull(),
            'headingLevels' => $this->text()->null(),
            'language' => $this->string(10)->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'enableAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            'disableStopWords' => $this->boolean()->notNull()->defaultValue(false),
            'skipEntriesWithoutUrl' => $this->boolean()->notNull()->defaultValue(false),
            'splitSections' => $this->boolean()->notNull()->defaultValue(false),
            'retrievableFields' => $this->text()->notNull()->defaultValue('["*"]')->comment('JSON list of public custom field handles to return'),
            'source' => $this->enum('source', ['config', 'database'])->notNull()->defaultValue('database'),
            'backend' => $this->string(255)->null()->comment('Handle of configured backend to use'),
            'lastIndexed' => $this->dateTime()->null(),
            'documentCount' => $this->integer()->notNull()->defaultValue(0),
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
     * Create index sites table
     * Stores multi-site mappings for indices (database indices only)
     */
    private function createIndexSitesTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_index_sites}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_index_sites}}', [
            'indexId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
        ]);

        // Composite primary key
        $this->addPrimaryKey(null, '{{%searchmanager_index_sites}}', ['indexId', 'siteId']);

        // Indexes
        $this->createIndex(null, '{{%searchmanager_index_sites}}', ['siteId'], false);

        // Foreign keys
        $this->addForeignKey(
            null,
            '{{%searchmanager_index_sites}}',
            ['indexId'],
            '{{%searchmanager_indices}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            null,
            '{{%searchmanager_index_sites}}',
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
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
            'config' => $this->text()->null(),
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
     * Create pending syncs table
     * Collapses element save/delete events into per-index sync rows for batch processing
     */
    private function createPendingSyncsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_pending_syncs}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_pending_syncs}}', [
            'id' => $this->primaryKey(),
            'indexHandle' => $this->string(255)->notNull(),
            'elementType' => $this->string(255)->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'op' => $this->enum('op', ['upsert', 'delete'])->notNull(),
            'status' => $this->enum('status', ['pending', 'processing', 'failed', 'abandoned'])->notNull()->defaultValue('pending'),
            'attemptCount' => $this->integer()->notNull()->defaultValue(0),
            'queuedAt' => $this->dateTime()->notNull(),
            'nextAttemptAt' => $this->dateTime()->notNull(),
            'claimedAt' => $this->dateTime()->null(),
            'claimToken' => $this->string(64)->null(),
            'dirtyAt' => $this->dateTime()->null(),
            'lastError' => $this->text()->null(),
            'lastProcessedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%searchmanager_pending_syncs}}', ['indexHandle', 'elementId', 'siteId'], true);
        $this->createIndex(null, '{{%searchmanager_pending_syncs}}', ['status', 'nextAttemptAt'], false);
        $this->createIndex(null, '{{%searchmanager_pending_syncs}}', ['status', 'claimedAt'], false);
        $this->createIndex(null, '{{%searchmanager_pending_syncs}}', ['indexHandle', 'status'], false);
        $this->createIndex(null, '{{%searchmanager_pending_syncs}}', ['queuedAt'], false);
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
            'defaultBackendHandle' => null,
            'defaultWidgetHandle' => null,
            'batchSize' => 100,
            'lastIndexedDebounceSeconds' => 60,
            'syncBatchSize' => 200,
            'batchFlushInterval' => 5,
            'pendingMaxAge' => 3600,
            'batchMaxAttempts' => 5,
            'replaceNativeSearch' => 0,
            'requireApiKey' => 0,
            'enableAnalytics' => 1,
            'analyticsRetention' => 90,
            'indexPrefix' => null,
            'bm25K1' => 1.5,
            'bm25B' => 0.75,
            'titleBoostFactor' => 5.0,
            'exactMatchBoostFactor' => 3.0,
            'phraseBoostFactor' => 4.0,
            'enableFuzzy' => 1,
            'ngramSizes' => '2,3',
            'similarityThreshold' => 0.25,
            'maxFuzzyCandidates' => 100,
            'enableStopWords' => 1,
            'defaultLanguage' => null,
            'highlightResultsEnabled' => 1,
            'highlightTag' => 'mark',
            'highlightClass' => null,
            'snippetMaxLength' => 200,
            'maxSnippets' => 3,
            'enableAutocomplete' => 1,
            'autocompleteMinLength' => 2,
            'autocompleteLimit' => 10,
            'enableAutocompleteCache' => 1,
            'autocompleteCacheDuration' => 300,
            'enableCache' => 1,
            'cacheDuration' => 3600,
            'clearCacheOnSave' => 1,
            'statusSyncInterval' => 15,
            // Base plugin overrides — seeded null so cascade falls through to base config / defaults.
            'timeFormat' => null,
            'monthFormat' => null,
            'dateOrder' => null,
            'dateSeparator' => null,
            'showSeconds' => null,
            'defaultDateRange' => null,
            'exportsCsv' => null,
            'exportsJson' => null,
            'exportsExcel' => null,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
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
            'normalizedQuery' => $this->string(500)->notNull(),
            'resultsCount' => $this->integer()->notNull()->defaultValue(0),
            'executionTime' => $this->float()->null(),
            'backend' => $this->string(50)->notNull(),
            'siteId' => $this->integer()->null(),
            // Analytics enhancement fields
            'intent' => $this->enum('intent', ['informational', 'product', 'navigational', 'question'])->null(),
            'source' => $this->string(50)->notNull()->defaultValue('frontend'),
            'trigger' => $this->enum('trigger', ['click', 'enter', 'idle', 'unknown'])->null()->comment('What triggered the analytics tracking'),
            'platform' => $this->string(50)->null(),
            'appVersion' => $this->string(20)->null(),
            'ip' => $this->string(64)->null(),
            'userAgent' => $this->text()->null(),
            'referer' => $this->string(2048)->null(),
            // API key attribution (null = anonymous / unkeyed request). apiKeyId is
            // a plain nullable int with no foreign key: it is retained after a key
            // is revoked so historical rows stay correlatable, while prefix/type are
            // snapshots that stay readable once the key row is gone.
            'apiKeyId' => $this->integer()->null(),
            'apiKeyPrefix' => $this->string(32)->null(),
            'apiKeyType' => $this->string(16)->null(),
            'isHit' => $this->boolean()->notNull()->defaultValue(true),
            // Query rules & promotions tracking
            'synonymsExpanded' => $this->boolean()->notNull()->defaultValue(false)->comment('Was query expanded with synonyms'),
            'rulesMatched' => $this->integer()->notNull()->defaultValue(0)->comment('Number of query rules that matched'),
            'promotionsShown' => $this->integer()->notNull()->defaultValue(0)->comment('Number of promotions shown'),
            'wasRedirected' => $this->boolean()->notNull()->defaultValue(false)->comment('Did a redirect rule match'),
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
            'botCategory' => $this->string(100)->null(),
            'botUrl' => $this->string(255)->null(),
            'botProducerName' => $this->string(100)->null(),
            'botProducerUrl' => $this->string(255)->null(),
            'isSystemAgent' => $this->boolean()->defaultValue(false),
            'trafficType' => $this->string(20)->notNull()->defaultValue('human'),
            // Geo-location fields
            'country' => $this->string(2)->null(),
            'city' => $this->string(100)->null(),
            'language' => $this->string(10)->null(),
            'region' => $this->string(100)->null(),
            'latitude' => $this->decimal(10, 8)->null(),
            'longitude' => $this->decimal(11, 8)->null(),
            // Session tracking (links multi-index rows; future: full session tracking)
            'sessionId' => $this->string(36)->null()->comment('Groups rows from same search action'),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes for analytics queries
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['indexHandle'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['query'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['normalizedQuery'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['normalizedQuery', 'dateCreated'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['backend'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['intent'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['source'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['trigger'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['isHit'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['deviceType'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['browser'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['osName'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['clientType'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['isRobot'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['trafficType'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['siteId', 'dateCreated'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['synonymsExpanded'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['wasRedirected'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['sessionId'], false);
        $this->createIndex(null, '{{%searchmanager_analytics}}', ['apiKeyId'], false);
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
                'documentKey' => $this->string(255)->notNull()->comment('Backend document ID, unique per indexed page or section'),
                'term' => $this->string(255)->notNull(),
                'frequency' => $this->integer()->notNull(),
                'language' => $this->string(10)->notNull()->defaultValue('en'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'documentKey', 'term']);
            $this->createIndex(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'elementId'], false);
            $this->createIndex(null, '{{%searchmanager_search_documents}}', ['indexHandle', 'siteId', 'documentKey'], false);
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
                'documentKey' => $this->string(255)->notNull()->comment('Backend document ID, unique per indexed page or section'),
                'frequency' => $this->integer()->notNull(),
                'language' => $this->string(10)->notNull()->defaultValue('en'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'term', 'siteId', 'documentKey']);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'term', 'siteId'], false);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'siteId', 'elementId'], false);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'siteId', 'documentKey'], false);
            $this->createIndex(null, '{{%searchmanager_search_terms}}', ['indexHandle', 'language'], false);
        }

        // Titles table: stores terms that appear in document titles for boosting
        if (!$this->db->tableExists('{{%searchmanager_search_titles}}')) {
            $this->createTable('{{%searchmanager_search_titles}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'documentKey' => $this->string(255)->notNull()->comment('Backend document ID, unique per indexed page or section'),
                'term' => $this->string(255)->notNull(),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_titles}}', ['indexHandle', 'siteId', 'documentKey', 'term']);
            $this->createIndex(null, '{{%searchmanager_search_titles}}', ['indexHandle', 'siteId', 'elementId'], false);
            $this->createIndex(null, '{{%searchmanager_search_titles}}', ['indexHandle', 'siteId', 'documentKey'], false);
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

        // Elements table: stores element metadata for rich autocomplete suggestions
        // Returns full titles with element type (product, category) for display
        if (!$this->db->tableExists('{{%searchmanager_search_elements}}')) {
            $this->createTable('{{%searchmanager_search_elements}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'documentKey' => $this->string(255)->notNull()->comment('Backend document ID, unique per indexed page or section'),
                'title' => $this->string(500)->notNull(),
                'elementType' => $this->string(50)->notNull()->comment('product, category, etc.'),
                'searchText' => $this->string(500)->notNull()->comment('Normalized lowercase for prefix matching'),
                'documentData' => $this->mediumText()->null()->comment('JSON transformer output for rich results'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_elements}}', ['indexHandle', 'siteId', 'documentKey']);
            $this->createIndex(null, '{{%searchmanager_search_elements}}', ['indexHandle', 'siteId', 'elementId'], false);
            // Index for prefix search on searchText
            $this->createIndex('idx_elements_search', '{{%searchmanager_search_elements}}', ['indexHandle', 'siteId', 'searchText'], false);
            // Index for filtering by elementType
            $this->createIndex('idx_elements_type', '{{%searchmanager_search_elements}}', ['indexHandle', 'siteId', 'elementType'], false);
        }

        // Compound suggestions table: stores filename-like dotted compounds for autocomplete
        // while the normal term index continues to tokenize them into searchable parts.
        if (!$this->db->tableExists('{{%searchmanager_search_compounds}}')) {
            $this->createTable('{{%searchmanager_search_compounds}}', [
                'indexHandle' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'elementId' => $this->integer()->notNull(),
                'documentKey' => $this->string(255)->notNull()->comment('Backend document ID, unique per indexed page or section'),
                'suggestion' => $this->string(255)->notNull()->comment('Display suggestion, e.g. Redirect.twig'),
                'normalizedSuggestion' => $this->string(255)->notNull()->comment('Prefix-searchable normalized suggestion'),
                'tokenKey' => $this->string(255)->notNull()->comment('Tokenized compound intent, e.g. redirect twig'),
                'frequency' => $this->integer()->notNull()->defaultValue(1),
                'language' => $this->string(10)->notNull()->defaultValue('en'),
            ]);

            $this->addPrimaryKey(null, '{{%searchmanager_search_compounds}}', ['indexHandle', 'siteId', 'documentKey', 'suggestion']);
            $this->createIndex(null, '{{%searchmanager_search_compounds}}', ['indexHandle', 'siteId', 'elementId'], false);
            $this->createIndex('idx_compounds_prefix', '{{%searchmanager_search_compounds}}', ['indexHandle', 'siteId', 'normalizedSuggestion'], false);
            $this->createIndex('idx_compounds_token_key', '{{%searchmanager_search_compounds}}', ['indexHandle', 'siteId', 'tokenKey'], false);
            $this->createIndex('idx_compounds_language', '{{%searchmanager_search_compounds}}', ['indexHandle', 'language'], false);
        }
    }

    /**
     * Create promotions table
     * Stores pinned/promoted results that bypass normal scoring
     */
    private function createPromotionsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_promotions}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_promotions}}', [
            'id' => $this->primaryKey(),
            'indexHandle' => $this->string(255)->null()->comment('null = applies to all indices'),
            'title' => $this->string(255)->null()->comment('Descriptive title for organization'),
            'query' => $this->string(500)->notNull()->comment('Query pattern to match'),
            'matchType' => $this->enum('matchType', ['exact', 'contains', 'prefix'])->notNull()->defaultValue('exact'),
            'elementId' => $this->integer()->notNull(),
            'elementType' => $this->string(255)->null()->comment('Element class, e.g. craft\\elements\\Entry'),
            'position' => $this->integer()->notNull()->defaultValue(1)->comment('1 = first position'),
            'siteId' => $this->integer()->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for efficient lookup
        $this->createIndex(null, '{{%searchmanager_promotions}}', ['indexHandle', 'siteId', 'enabled'], false);
        $this->createIndex(null, '{{%searchmanager_promotions}}', ['query'], false);
        $this->createIndex(null, '{{%searchmanager_promotions}}', ['elementId'], false);
    }

    /**
     * Create query rules table
     * Stores rules for synonyms, category boosts, redirects, etc.
     */
    private function createQueryRulesTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_query_rules}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_query_rules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull()->comment('Descriptive name for the rule'),
            'indexHandle' => $this->string(255)->null()->comment('null = applies to all indices'),
            'matchType' => $this->enum('matchType', ['exact', 'contains', 'prefix', 'regex'])->notNull()->defaultValue('exact'),
            'matchValue' => $this->string(500)->notNull()->comment('Query pattern to match'),
            'actionType' => $this->enum('actionType', ['synonym', 'boost_section', 'boost_category', 'boost_element', 'redirect'])->notNull(),
            'actionValue' => $this->text()->notNull()->comment('JSON config for the action'),
            'priority' => $this->integer()->notNull()->defaultValue(0)->comment('Higher = applied first'),
            'siteId' => $this->integer()->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for efficient lookup
        $this->createIndex(null, '{{%searchmanager_query_rules}}', ['indexHandle', 'siteId', 'enabled'], false);
        $this->createIndex(null, '{{%searchmanager_query_rules}}', ['matchType', 'matchValue'], false);
        $this->createIndex(null, '{{%searchmanager_query_rules}}', ['actionType'], false);
        $this->createIndex(null, '{{%searchmanager_query_rules}}', ['priority'], false);
    }

    /**
     * Create rule analytics table
     * Tracks which query rules are triggered and how often
     */
    private function createRuleAnalyticsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_rule_analytics}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_rule_analytics}}', [
            'id' => $this->primaryKey(),
            'queryRuleId' => $this->integer()->notNull()->comment('FK to query_rules.id'),
            'ruleName' => $this->string(255)->notNull()->comment('Denormalized for reporting after rule deletion'),
            'actionType' => $this->enum('actionType', ['synonym', 'boost_section', 'boost_category', 'boost_element', 'redirect'])->notNull(),
            'query' => $this->string(500)->notNull()->comment('The search query that triggered this rule'),
            'indexHandle' => $this->string(255)->null(),
            'siteId' => $this->integer()->null(),
            'resultsCount' => $this->integer()->notNull()->defaultValue(0)->comment('Results count after rule applied'),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for analytics queries
        $this->createIndex(null, '{{%searchmanager_rule_analytics}}', ['queryRuleId'], false);
        $this->createIndex(null, '{{%searchmanager_rule_analytics}}', ['actionType'], false);
        $this->createIndex(null, '{{%searchmanager_rule_analytics}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%searchmanager_rule_analytics}}', ['indexHandle', 'siteId'], false);
    }

    /**
     * Create promotion analytics table
     * Tracks which promotions are shown and how often
     */
    private function createPromotionAnalyticsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_promotion_analytics}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_promotion_analytics}}', [
            'id' => $this->primaryKey(),
            'promotionId' => $this->integer()->notNull()->comment('FK to promotions.id'),
            'elementId' => $this->integer()->notNull()->comment('Denormalized - the promoted element'),
            'elementTitle' => $this->string(500)->null()->comment('Denormalized for reporting after element deletion'),
            'query' => $this->string(500)->notNull()->comment('The search query that triggered this promotion'),
            'position' => $this->integer()->notNull()->comment('Position the promotion was shown at'),
            'indexHandle' => $this->string(255)->notNull(),
            'siteId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for analytics queries
        $this->createIndex(null, '{{%searchmanager_promotion_analytics}}', ['promotionId'], false);
        $this->createIndex(null, '{{%searchmanager_promotion_analytics}}', ['elementId'], false);
        $this->createIndex(null, '{{%searchmanager_promotion_analytics}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%searchmanager_promotion_analytics}}', ['indexHandle', 'siteId'], false);
    }

    /**
     * Create widget configs table
     * Stores named widget configurations with appearance/behavior settings
     */
    private function createWidgetConfigsTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_widget_configs}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_widget_configs}}', [
            'id' => $this->primaryKey(),
            'handle' => $this->string(64)->notNull(),
            'name' => $this->string(255)->notNull(),
            'type' => $this->string(32)->notNull()->defaultValue('modal'),
            'styleHandle' => $this->string(64)->null(),
            'settings' => $this->text()->null()->comment('JSON settings for highlighting, backdrop, behavior'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Create indexes
        $this->createIndex(null, '{{%searchmanager_widget_configs}}', ['handle'], true);
        $this->createIndex(null, '{{%searchmanager_widget_configs}}', ['enabled'], false);
        $this->createIndex(null, '{{%searchmanager_widget_configs}}', ['styleHandle'], false);
    }

    /**
     * Insert default widget configuration
     */
    private function insertDefaultWidgetConfig(): void
    {
        $defaultSettings = [
            'behavior' => [
                'modalPreventBodyScroll' => true,
                'searchDebounceMs' => 200,
                'searchMinChars' => 2,
                'resultsLimit' => 10,
                'recentlyViewedEnabled' => true,
                'recentlyViewedLimit' => 5,
                'resultsGroupingEnabled' => true,
                'triggerHotkey' => 'k',
                'resultsRequireUrl' => false,
                'loadingIndicatorEnabled' => true,
            ],
            'trigger' => [
                'triggerEnabled' => true,
                'triggerLabel' => 'Search',
            ],
            'analytics' => [
                'analyticsSource' => '',        // Custom source identifier
                'analyticsIdleTimeoutMs' => 1500, // Track search after idle timeout in ms (0 = disabled)
            ],
        ];

        $this->insert('{{%searchmanager_widget_configs}}', [
            'handle' => 'default',
            'name' => 'Default Widget',
            'type' => 'modal',
            'styleHandle' => 'default',
            'settings' => json_encode($defaultSettings),
            'enabled' => 1,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);

        // Set default widget handle in settings
        $this->update('{{%searchmanager_settings}}', [
            'defaultWidgetHandle' => 'default',
        ], ['id' => 1]);
    }

    /**
     * Create widget styles table
     */
    private function createWidgetStylesTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_widget_styles}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_widget_styles}}', [
            'id' => $this->primaryKey(),
            'handle' => $this->string(64)->notNull(),
            'name' => $this->string(255)->notNull(),
            'type' => $this->string(32)->notNull()->defaultValue('modal'),
            'styles' => $this->text()->null()->comment('JSON styles for widget appearance'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%searchmanager_widget_styles}}', ['handle'], true);
        $this->createIndex(null, '{{%searchmanager_widget_styles}}', ['enabled'], false);
        $this->createIndex(null, '{{%searchmanager_widget_styles}}', ['type'], false);
    }

    /**
     * Insert default widget style preset
     */
    private function insertDefaultWidgetStyle(): void
    {
        $this->insert('{{%searchmanager_widget_styles}}', [
            'handle' => 'default',
            'name' => 'Default Style',
            'type' => 'modal',
            'styles' => json_encode([]),
            'enabled' => 1,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Create API keys table
     *
     * Stores hashed API keys that gate access to the public search,
     * autocomplete, and analytics tracking (track-search / track-click)
     * endpoints when `requireApiKey = true`. Each key declares its restrictions
     * (allowed indices, allowed referrers, max hits per page, expiry, rate
     * limit). The plaintext key is shown to the operator exactly once on
     * creation. Authentication uses only the hash + 15-char prefix. Public
     * keys may also store encrypted plaintext material so widgets can send
     * selected public keys without showing full keys in the control panel.
     *
     * @since 5.46.0
     */
    private function createApiKeysTable(): void
    {
        if ($this->db->tableExists('{{%searchmanager_api_keys}}')) {
            return;
        }

        $this->createTable('{{%searchmanager_api_keys}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'type' => $this->enum('type', ['public', 'server'])->notNull()->defaultValue('public'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'keyHash' => $this->string(128)->notNull()->comment('HMAC-SHA256 of plaintext key, keyed by Craft securityKey'),
            'encryptedKey' => $this->text()->null()->comment('Encrypted plaintext for public keys used by browser-rendered widgets'),
            'keyPrefix' => $this->string(32)->notNull()->comment('Unhashed prefix for CP display + lookup (e.g. sm_pub_a1b2c3d4)'),
            'allowedIndices' => $this->text()->null()->comment('JSON array of index handles, or ["*"] for all indices'),
            'allowedReferrers' => $this->text()->null()->comment('JSON array of domain patterns (example.com, *.example.com)'),
            'maxHitsPerPage' => $this->integer()->null()->comment('Cap on resultsLimit; null = use endpoint default'),
            'validUntil' => $this->dateTime()->null()->comment('Expiry datetime; null = never expires'),
            'rateLimit' => $this->integer()->null()->comment('Requests per minute; null = no rate limit (slice 3)'),
            'lastUsedAt' => $this->dateTime()->null()->comment('Updated on successful enforcement (slice 2)'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // handle is the stable CP/config reference used by widget configs
        $this->createIndex('searchmanager_api_keys_handle_unq', '{{%searchmanager_api_keys}}', ['handle'], true);
        // keyPrefix is the lookup column on the enforcement hot path → unique index
        $this->createIndex(null, '{{%searchmanager_api_keys}}', ['keyPrefix'], true);
        // For "show keys expiring soon" CP queries + ad-hoc cleanup
        $this->createIndex(null, '{{%searchmanager_api_keys}}', ['validUntil'], false);
        // For type-filtered CP listings (public vs server)
        $this->createIndex(null, '{{%searchmanager_api_keys}}', ['type'], false);
    }
}
