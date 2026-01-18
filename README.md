# Search Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-search-manager.svg)](https://packagist.org/packages/lindemannrock/craft-search-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-search-manager.svg)](LICENSE)

Advanced multi-backend search management for Craft CMS - supports Algolia, File, Meilisearch, MySQL, PostgreSQL, Redis, and Typesense.

## ⚠️ Beta Notice

This plugin is currently in active development and provided under the MIT License for testing purposes.

**Licensing is subject to change.** We are finalizing our licensing structure and some or all features may require a paid license when officially released on the Craft Plugin Store. Some plugins may remain free, others may offer free and Pro editions, or be fully commercial.

If you are using this plugin, please be aware that future versions may have different licensing terms.

## Features

### Multi-Backend Support
- **Algolia** - Cloud-hosted search service (Scout replacement)
- **File** - Local file storage in `@storage/runtime/search-manager/indices/` (no external dependencies)
- **Meilisearch** - Self-hosted, open-source alternative to Algolia
- **MySQL** - Built-in BM25 search using Craft's MySQL database
- **PostgreSQL** - Built-in BM25 search using Craft's PostgreSQL database
- **Redis** - Fast in-memory BM25 search with persistence (can reuse Craft's Redis cache)
- **Typesense** - Open-source search engine with typo tolerance

### Advanced Search Features (MySQL, PostgreSQL, Redis, File)

**Search Operators:**
- **Phrase Search** - `"exact phrase"` for sequential word matching
- **NOT Operator** - `test NOT spam` to exclude terms
- **Field-Specific** - `title:blog` or `content:test` to search specific fields
- **Wildcards** - `test*` matches test, tests, testing, tested
- **Per-term Boosting** - `test^2 entry` to weight specific terms
- **Boolean Operators** - `test OR entry` and `test AND entry`

**Ranking & Relevance:**
- **BM25 Ranking Algorithm** - Industry-standard relevance scoring
- **Fuzzy Matching** - Typo tolerance with n-gram similarity (finds "test" when searching "tst")
- **Title Boosting** - Results with query terms in titles rank 5x higher (configurable)
- **Phrase Boosting** - Exact phrase matches rank 4x higher (configurable)
- **Exact Match Boosting** - Documents matching all terms rank 3x higher (configurable)
- **Stop Words Filtering** - Automatic removal of common words (the, a, is, etc.)

**UX Features:**
- **Highlighting** - Highlight matched terms with `<mark>` tags (configurable)
- **Context Snippets** - Show excerpts around matched terms
- **Autocomplete** - Search-as-you-type suggestions based on indexed terms

**Multi-Language:**
- **5 Languages Supported** - English, Arabic, German, French, Spanish stop words
- **Localized Boolean Operators** - AND/OR/NOT in all 5 languages (UND/ODER/NICHT, ET/OU/SAUF, etc.)
- **Auto-Detection** - Language detected from element's site automatically
- **Regional Variants** - Support for ar-SA (Saudi), ar-EG (Egypt), fr-CA (Quebec), etc.
- **Language Filtering** - Filter results by language for multi-site indices
- **API Language Override** - Mobile apps can specify language for localized operators

### Comprehensive Analytics
- **Search Tracking** - Track every search query with hits count and execution time
- **Per-Index Analytics Toggle** - Enable/disable analytics tracking per index (useful for internal/admin indices)
- **Query Rules Tracking** - Track which rules fire, how often, and their effectiveness
- **Promotions Tracking** - Track promotion impressions, positions, and triggering queries
- **Synonyms Tracking** - Track when synonym expansion is used
- **Source Detection** - Auto-detect search origin (frontend, CP, API) or pass custom sources
- **Platform & App Tracking** - Track platform (iOS 17, Android 14) and app version for mobile apps
- **Device Detection** - Powered by Matomo DeviceDetector for accurate device, browser, and OS identification
- **Geographic Detection** - Track visitor location (country, city, region) via ip-api.com
- **Async Geo-Lookup** - Geographic detection runs asynchronously via queue job to avoid blocking search responses
- **Bot Filtering** - Identify and filter bot traffic (GoogleBot, BingBot, etc.)
- **Zero-Hit Tracking** - Identify queries that return no results (content gaps)
- **Performance Metrics** - Dedicated Performance tab with cache hit rate, response time trends, fastest/slowest queries
- **Intent & Source Charts** - Visual breakdown of search intent and source distribution
- **Privacy-First** - IP hashing with salt, optional subnet masking, GDPR-friendly
- **Referrer Tracking** - See where search traffic is coming from
- **Export Options** - CSV and JSON export with clean column names (Hits, Synonyms, Rules, Promotions, Redirected)
- **Automatic Cleanup** - Configurable retention period (0-3650 days)

**Analytics Tabs:**
- **Overview** - Summary stats, search trends, intent/source breakdown
- **Recent Searches** - Detailed log with hits, synonyms, rules, promotions columns
- **Query Rules** - Top triggered rules, rules by action type, triggering queries (only shown if rules exist)
- **Promotions** - Top promoted elements, impressions by position, triggering queries (only shown if promotions exist)
- **Content Gaps** - Zero-hit clusters and recent failed queries
- **Performance** - Cache stats, response times, fastest/slowest queries
- **Traffic & Devices** - Device, browser, OS breakdown, peak hours
- **Geographic** - Country and city breakdown (when geo detection enabled)

### Performance Caching
- **Search Results Cache** - Cache search results to reduce backend load and improve response times
- **Autocomplete Cache** - Separate cache for autocomplete suggestions with shorter TTL (default: 5 minutes)
- **Device Detection Cache** - Cache parsed user-agent strings to avoid re-parsing
- **Popular Queries Only** - Only cache frequently-searched queries to save storage space
- **Configurable Durations** - Set cache TTL per cache type (search: 1 hour, autocomplete: 5 minutes, device: 1 hour)
- **Independent Cache Settings** - Enable/disable search cache and autocomplete cache independently
- **Per-Index Cache Clearing** - Clear cache for specific indices without affecting others
- **Cache Management** - Clear caches via Control Panel utilities or Craft's Clear Caches
- **Craft Integration** - Search caches available in Craft's Clear Caches utility (safe, auto-regenerate)
- **Storage Locations**:
  - Device cache: `@storage/runtime/search-manager/cache/device/`
  - Search cache: `@storage/runtime/search-manager/cache/search/`
  - Autocomplete cache: `@storage/runtime/search-manager/cache/autocomplete/`

### Cache Invalidation
- **Clear on Save** - Optionally clear search cache when elements are saved (disable for high-traffic sites)
- **Status Sync Interval** - Periodic job to sync entries that become live/expired based on dates
- **Natural TTL Expiry** - When "Clear on Save" is disabled, cache expires based on configured duration
- **Per-Index Cache Clear** - Cache is cleared per-index, not globally

### Cache Warming
- **Automatic Warming** - After index rebuild, popular queries are pre-cached automatically
- **Analytics-Driven** - Uses search analytics to identify the most searched queries
- **Dual Cache Support** - Warms both search results and autocomplete suggestions
- **Configurable Depth** - Choose how many queries to warm (10-200, default: 50)
- **Background Processing** - Runs as a queue job after rebuild completes
- **Prefix Warming** - Autocomplete cache warms common prefixes (2-5 chars) for each query

### Automatic Indexing
- Auto-index elements when saved (configurable)
- Queue-based batch indexing for better performance
- Manual rebuild via Control Panel or CLI
- Element deletion automatically removes from index
- **Status Sync Job** - Automatically syncs entries that become live (postDate passed) or expired (expiryDate passed) without a save event
- **Per-index sync** - Each site version of an element is synced independently

### Native Search Replacement
- **Replace Craft's search service** - Optional setting to replace `Craft::$app->search`
- **CP search integration** - Control Panel searches use your backend
- **Template compatibility** - `Entry::find()->search('query')` uses your backend
- **Seamless fallback** - Falls back to Craft's search if no index configured
- **Built-in backends only** - Works with MySQL, PostgreSQL, Redis, and File backends

### Custom Transformers
- Transform elements into searchable documents
- Scout-compatible transformer API for easy migration
- Built-in transformers for entries, assets, categories
- Custom transformers per element type, site, or section
- Priority-based transformer resolution

### Promotions (Pinned Results)
- **Pin Elements** - Force specific elements to fixed positions in search results
- **Match Types** - Exact match, contains, or prefix matching for query patterns
- **Position Control** - Specify exact position (1st, 2nd, 3rd, etc.)
- **Scope Control** - Apply to specific indices and/or sites
- **Enable/Disable** - Toggle promotions without deleting
- **Bulk Actions** - Enable, disable, or delete multiple promotions at once
- **Per-Site Status** - Respects element status per site (disabled/pending/expired elements excluded from that site's results)

### Query Rules
- **Synonyms** - Expand searches to include related terms (e.g., "laptop" → "notebook, computer")
- **Section Boosting** - Boost results from specific sections by multiplier
- **Category Boosting** - Boost results in specific categories
- **Element Boosting** - Boost specific elements by ID
- **Result Filtering** - Filter results by field values when query matches
- **Query Redirects** - Redirect users to a URL instead of showing results
- **Match Types** - Exact, contains, prefix, or regex pattern matching
- **Priority System** - Higher priority rules applied first
- **Global or Index-Specific** - Apply rules to all indices or specific ones

### Search Widget (Frontend)
- **CMD+K Style Modal** - Beautiful, accessible search modal with keyboard navigation
- **Customizable Appearance** - Full control over colors, fonts, spacing, and border radius
- **Light & Dark Themes** - Built-in theme support with customizable colors for each
- **Term Highlighting** - Highlight matched terms in results with configurable colors
- **Recent Searches** - Store and display recent search history (optional)
- **Grouped Results** - Group results by type/section (optional)
- **Keyboard Shortcuts** - Configurable hotkey to open (default: CMD+K / Ctrl+K)
- **Trigger Button** - Optional trigger button with customizable text
- **External Triggers** - Connect any element via CSS selector to open the modal
- **Click Analytics** - Track which results users click
- **RTL Support** - Full right-to-left language support
- **Backdrop Options** - Configurable opacity and blur effect
- **Multiple Configs** - Create different widget configurations for different use cases
- **Web Component** - Uses `<search-widget>` custom element for easy integration

### Control Panel Interface
- Full CP section for managing indices
- Promotions management with filtering and bulk actions
- Query Rules management with action type configuration
- Create, edit, delete, rebuild indices
- Backend status monitoring
- Analytics dashboard
- Comprehensive settings with config override warnings
- **Test Search** - Test searches across all sites with element type and site info per result

### Developer-Friendly
- Console commands for all operations
- Event system for before/after indexing hooks
- Template variables for frontend search
- Multi-site support
- Database-backed settings (not project config)
- Config file override layer

## Requirements

- PHP 8.2+
- Craft CMS 5.0+
- LindemannRock Logging Library ^5.0 (installed automatically)
- matomo/device-detector ^6.4 (installed automatically for analytics)

### Optional Backend Requirements

- **Algolia**: PHP cURL extension
- **Meilisearch**: Meilisearch server running
- **Redis**: PHP Redis extension
- **Typesense**: Typesense server running

## Installation

### Via Composer

```bash
cd /path/to/project
```

```bash
composer require lindemannrock/craft-search-manager
```

```bash
./craft plugin/install search-manager
```

### Using DDEV

```bash
cd /path/to/project
```

```bash
ddev composer require lindemannrock/craft-search-manager
```

```bash
ddev craft plugin/install search-manager
```

### Via Control Panel

In the Control Panel, go to Settings → Plugins and click "Install" for Search Manager.

### ⚠️ Required Post-Install Step

**IMPORTANT:** After installation, you MUST generate the IP hash salt for analytics to work:

```bash
php craft search-manager/security/generate-salt
```

**Or with DDEV:**
```bash
ddev craft search-manager/security/generate-salt
```

**What happens if you skip this:**
- ❌ Analytics tracking will fail with error: `IP hash salt not configured`
- ❌ Search will still work, but won't track queries
- ✅ You can generate the salt later, but no analytics will be collected until you do

**Quick Start:**
```bash
# After plugin installation:
php craft search-manager/security/generate-salt

# The command will automatically add SEARCH_MANAGER_IP_SALT to your .env file
# Copy this value to staging/production .env files manually
```

### Optional: Copy Config File

```bash
cp vendor/lindemannrock/craft-search-manager/src/config.php config/search-manager.php
```

### Important: IP Privacy Protection

Search Manager uses **privacy-focused IP hashing** with a secure salt:

- ✅ **Rainbow-table proof** - Salted SHA256 prevents pre-computed attacks
- ✅ **Unique visitor tracking** - Same IP = same hash
- ✅ **Geo-location preserved** - Country/city extracted BEFORE hashing
- ✅ **Maximum privacy** - Original IPs never stored, unrecoverable

**Setup Instructions:**
1. Generate salt: `php craft search-manager/security/generate-salt`
2. Command automatically adds `SEARCH_MANAGER_IP_SALT` to your `.env` file
3. **Manually copy** the salt value to staging/production `.env` files
4. **Never regenerate** the salt in production

**How It Works:**
- Plugin automatically reads salt from `.env` (no config file needed!)
- Config file can override if needed: `'ipHashSalt' => App::env('SEARCH_MANAGER_IP_SALT')`
- If no salt found, error banner shown in settings

**Security Notes:**
- Never commit the salt to version control
- Store salt securely (password manager recommended)
- Use the SAME salt across all environments (dev, staging, production)
- Changing the salt will break unique visitor tracking history

### Local Development: Analytics Location Override

When running locally (DDEV, localhost), analytics will **default to Dubai, UAE** because local IPs can't be geolocated. To set your actual location for testing:

**Option 1: Config File** (recommended for project-wide default)
```php
// config/search-manager.php
return [
    'defaultCountry' => 'US',
    'defaultCity' => 'New York',
];
```

**Option 2: Environment Variable** (recommended for per-environment control)
```bash
# .env
SEARCH_MANAGER_DEFAULT_COUNTRY=US
SEARCH_MANAGER_DEFAULT_CITY="New York"
```

**Fallback Priority:**
1. Config file setting
2. .env variable
3. Hardcoded default: Dubai, UAE

**Supported locations:**
- **US**: New York, Los Angeles, Chicago, San Francisco
- **GB**: London, Manchester
- **AE**: Dubai, Abu Dhabi (default: Dubai)
- **SA**: Riyadh, Jeddah
- **DE**: Berlin, Munich
- **FR**: Paris
- **CA**: Toronto, Vancouver
- **AU**: Sydney, Melbourne
- **JP**: Tokyo
- **SG**: Singapore
- **IN**: Mumbai, Delhi

**Important:** This setting is **safe to use in all environments** (dev, staging, production). It **only affects private/local IP addresses** (127.0.0.1, 192.168.x.x, 10.x.x.x, etc.). Real visitor IPs in production will always use actual geolocation from ip-api.com. This means you can safely commit config file settings without impacting production analytics.

## Quick Start

### 1. Configure Backend

Create `config/search-manager.php`:

```php
<?php
use craft\helpers\App;

return [
    '*' => [
        // Default backend to use (must match a handle from backends)
        'defaultBackendHandle' => 'my-meilisearch',

        // Define backend instances
        'backends' => [
            'my-meilisearch' => [
                'name' => 'My Meilisearch',
                'backendType' => 'meilisearch',
                'enabled' => true,
                'settings' => [
                    'host' => App::env('MEILISEARCH_HOST') ?: 'http://localhost:7700',
                    'apiKey' => App::env('MEILISEARCH_API_KEY'),
                ],
            ],
        ],
    ],
];
```

### 2. Define Indices

Add indices to `config/search-manager.php`:

```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => 1,
        'criteria' => function($query) {
            return $query->section(['news', 'blog']);
        },
        'transformer' => \modules\transformers\EntryTransformer::class,
        'enabled' => true,
    ],
],
```

### 3. Create a Transformer

```php
<?php
namespace modules\transformers;

use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\transformers\BaseTransformer;

class EntryTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        $data['content'] = $this->stripHtml($element->body);
        $data['excerpt'] = $this->getExcerpt($element->body, 200);
        $data['section'] = $element->section->handle;

        return $data;
    }
}
```

### 4. Rebuild Indices

```bash
php craft search-manager/index/rebuild
```

## Usage

### Using Native Search Replacement (Automatic)

**⚠️ Note:** Native search replacement only works with **MySQL, PostgreSQL, Redis, and File** backends. Not available for Algolia, Meilisearch, or Typesense.

Enable in settings (CP → Search Manager → Settings → Indexing) or config:

```php
'replaceNativeSearch' => true,
```

**What This Does:**
- ✅ **Control Panel searches** use your backend (Entries → Search, Assets → Search, etc.)
- ✅ **Template searches** use your backend automatically
- ✅ **Element queries** use your backend (`Entry::find()->search()`)
- ✅ **All search operators work** in CP search boxes!

**Usage:**

```twig
{# In templates - automatically uses your backend #}
{% set entries = craft.entries.search('my query').all() %}

{# Advanced operators work in CP and templates! #}
{% set entries = craft.entries.search('"craft cms" NOT plugin').all() %}
{% set entries = craft.entries.search('title:tutorial test*').all() %}
```

**In Control Panel:**
When enabled, you can use advanced operators directly in CP search boxes:
- Type: `"exact phrase"` in Entries search → Phrase search works!
- Type: `craft NOT plugin` → Exclusion works!
- Type: `title:blog` → Field-specific search works!
- Type: `test*` → Wildcards work!

**All features available everywhere!**

### Multi-Index Search

Search across multiple indices at once and get merged, scored results:

```twig
{# Search across multiple indices #}
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], 'search query') %}

{# Total results across all indices #}
<p>Found {{ results.total }} results</p>

{# Per-index breakdown #}
<ul>
{% for indexName, count in results.indices %}
    <li>{{ indexName }}: {{ count }} results</li>
{% endfor %}
</ul>

{# Loop through merged results (sorted by score) #}
{% for hit in results.hits %}
    {% set element = craft.entries.id(hit.objectID).one() %}
    <div class="result result--{{ hit._index }}">
        <h3>{{ element.title }}</h3>
        <span class="source">From: {{ hit._index }}</span>
        <span class="score">Score: {{ hit.score|number_format(2) }}</span>
    </div>
{% endfor %}
```

**Return Structure:**
```php
[
    'hits' => [
        ['objectID' => 123, 'score' => 45.2, '_index' => 'products'],
        ['objectID' => 456, 'score' => 38.1, '_index' => 'blog'],
        // ... merged and sorted by score
    ],
    'total' => 150,              // Sum across all indices
    'indices' => [               // Per-index breakdown
        'products' => 50,
        'blog' => 100,
    ],
]
```

**Features:**
- ✅ Results merged and sorted by relevance score
- ✅ Each hit tagged with `_index` for source identification
- ✅ Per-index result counts for faceted display
- ✅ Respects current site context automatically
- ✅ Cache-aware (per-index, per-site caching)

### Using Search Manager Directly (Explicit)

```twig
{# Basic search #}
{% set results = craft.searchManager.search('entries-en', 'search query') %}

{% for hit in results.hits %}
    <h3>{{ hit.title }}</h3>
    <p>{{ hit.excerpt }}</p>
    <p>Relevance Score: {{ hit.score }}</p>
    <a href="{{ hit.url }}">Read more</a>
{% endfor %}

<p>Total results: {{ results.total }}</p>

{# Search with boolean operators #}
{% set orResults = craft.searchManager.search('entries-en', 'test OR entry') %}
{% set andResults = craft.searchManager.search('entries-en', 'test AND entry') %}

{# Fuzzy search (typo tolerance) #}
{% set fuzzyResults = craft.searchManager.search('entries-en', 'tst') %}
{# Will find documents containing "test" #}
```

### Cross-Backend Methods (Algolia, Meilisearch, Typesense)

These methods provide unified access to backend-specific features, making it easy to migrate from Scout or trendyminds/algolia while maintaining compatibility.

| Method | Twig Usage | Description |
|--------|------------|-------------|
| `withBackend()` | `craft.searchManager.withBackend('handle')` | Get proxy for a specific configured backend |
| `listIndices()` | `craft.searchManager.listIndices()` | List all indices from backend |
| `search()` | `craft.searchManager.search(index, query, options)` | Search an index |
| `browse()` | `craft.searchManager.browse({index, query, params})` | Iterate through all documents |
| `multipleQueries()` | `craft.searchManager.multipleQueries([...])` | Batch search multiple indices |
| `parseFilters()` | `craft.searchManager.parseFilters({...})` | Generate backend-specific filter strings |
| `supportsBrowse()` | `craft.searchManager.supportsBrowse()` | Check if browse is supported |
| `supportsMultipleQueries()` | `craft.searchManager.supportsMultipleQueries()` | Check if batch queries supported |

**Backend Support:**

| Feature | Algolia | Meilisearch | Typesense | MySQL/PostgreSQL/Redis/File |
|---------|---------|-------------|-----------|----------------------------|
| `listIndices()` | ✅ | ✅ | ✅ | ✅ (from config) |
| `browse()` | ✅ | ✅ | ✅ | ❌ |
| `multipleQueries()` | ✅ Native | ✅ Native | ✅ Native | ✅ Sequential fallback |
| `parseFilters()` | ✅ | ✅ | ✅ | ✅ (SQL-like) |

#### Using a Specific Backend (withBackend)

By default, all `craft.searchManager` methods use the backend specified by `defaultBackendHandle`. Use `withBackend()` to explicitly use a different configured backend:

```twig
{# Get a proxy for a specific configured backend #}
{% set algolia = craft.searchManager.withBackend('production-algolia') %}

{# Now use it - all methods work on this backend #}
{% set indices = algolia.listIndices() %}
{% set results = algolia.search('my-index', 'query', {hitsPerPage: 10}) %}
{% set browseResults = algolia.browse({index: 'products', query: ''}) %}

{# Check backend info #}
<p>Using: {{ algolia.getName() }}</p>
<p>Available: {{ algolia.isAvailable() ? 'Yes' : 'No' }}</p>
```

**Use Cases:**
- Testing a specific backend when another is the default
- Querying multiple backends in the same template
- Accessing external indices (e.g., Algolia indices not managed by Search Manager)

**Available Methods on the Proxy:**
- `search(index, query, options)` - Search an index
- `browse(options)` - Iterate through all documents
- `multipleQueries(queries)` - Batch search
- `parseFilters(filters)` - Generate filter strings
- `listIndices()` - List all indices
- `supportsBrowse()` - Check browse support
- `supportsMultipleQueries()` - Check batch query support
- `getName()` - Get backend name
- `isAvailable()` - Check if backend is available
- `getStatus()` - Get backend status info

#### List Indices

```twig
{# List all indices from the backend #}
{% set indices = craft.searchManager.listIndices() %}

<table>
    <thead>
        <tr>
            <th>Index Name</th>
            <th>Entries</th>
            <th>Data Size</th>
        </tr>
    </thead>
    <tbody>
        {% for index in indices %}
        <tr>
            <td>{{ index.name }}</td>
            <td>{{ index.entries|default('-') }}</td>
            <td>{{ index.dataSize|default(0)|number_format }} bytes</td>
        </tr>
        {% endfor %}
    </tbody>
</table>
```

#### Browse (Iterate All Documents)

```twig
{# Browse all documents in an index #}
{% if craft.searchManager.supportsBrowse() %}
    {% set allDocs = craft.searchManager.browse({
        index: 'products',
        query: '',
        params: {}
    }) %}

    {% for doc in allDocs %}
        <div>{{ doc.title }}</div>
    {% endfor %}
{% endif %}
```

#### Multiple Queries (Batch Search)

```twig
{# Search multiple indices in one request #}
{% set results = craft.searchManager.multipleQueries([
    {indexName: 'products', query: 'laptop'},
    {indexName: 'categories', query: 'electronics'},
    {indexName: 'blog', query: 'review'}
]) %}

{% for result in results.results %}
    <h3>Results from index {{ loop.index }}</h3>
    <p>{{ result.nbHits ?? result.total }} hits</p>
{% endfor %}
```

#### Parse Filters

Automatically generates the correct filter syntax for your active backend:

```twig
{# Generate backend-specific filter string #}
{% set filterString = craft.searchManager.parseFilters({
    category: ['Electronics', 'Computers'],
    inStock: true,
    brand: 'Apple'
}) %}

{# Algolia output: (category:"Electronics" OR category:"Computers") AND (inStock:"true") AND (brand:"Apple") #}
{# Meilisearch output: (category = "Electronics" OR category = "Computers") AND inStock = "true" AND brand = "Apple" #}
{# Typesense output: category:=[`Electronics`, `Computers`] && inStock:=`true` && brand:=`Apple` #}
```

**Use with search:**
```twig
{% set results = craft.searchManager.search('products', 'laptop', {
    filters: craft.searchManager.parseFilters({category: 'Electronics'})
}) %}
```

### Search Operators (MySQL, PostgreSQL, Redis, File)

Search Manager supports powerful query operators for precise search control:

#### **1. Phrase Search (Exact Sequences)**
```twig
{# Find exact phrase in sequence #}
{% set results = craft.searchManager.search('entries', '"craft cms"') %}
{# Only matches documents with "craft" followed by "cms" #}
{# Ranks 4x higher than regular matches #}
```

#### **2. NOT Operator (Exclusion)**
```twig
{# Find "craft" but exclude documents with "plugin" #}
{% set results = craft.searchManager.search('entries', 'craft NOT plugin') %}

{# Combine with other operators #}
{% set results = craft.searchManager.search('entries', '"craft cms" NOT plugin NOT theme') %}
```

#### **3. Field-Specific Search**
```twig
{# Search only in titles #}
{% set results = craft.searchManager.search('entries', 'title:blog') %}

{# Search only in content #}
{% set results = craft.searchManager.search('entries', 'content:tutorial') %}

{# Combine fields #}
{% set results = craft.searchManager.search('entries', 'title:craft content:plugin') %}
```

#### **4. Wildcard Search (Prefix Matching)**
```twig
{# Find all words starting with "test" #}
{% set results = craft.searchManager.search('entries', 'test*') %}
{# Matches: test, tests, testing, tested, etc. #}

{# Multiple wildcards #}
{% set results = craft.searchManager.search('entries', 'test* OR craft*') %}
```

#### **5. Per-Term Boosting**
```twig
{# Boost "craft" more than "cms" #}
{% set results = craft.searchManager.search('entries', 'craft^2 cms') %}

{# Custom weights for multiple terms #}
{% set results = craft.searchManager.search('entries', 'craft^3 plugin^2 tutorial^1.5') %}
```

#### **6. Boolean Operators (AND/OR)**
```twig
{# OR: Find docs with either term #}
{% set results = craft.searchManager.search('entries', 'craft OR cms') %}

{# AND: Find docs with both terms (default) #}
{% set results = craft.searchManager.search('entries', 'craft AND cms') %}
{% set results = craft.searchManager.search('entries', 'craft cms') %} {# Same as above #}
```

**Localized Boolean Operators:**

Operators work in 5 languages (case-insensitive). Language is auto-detected from site settings:

| Language | AND | OR | NOT |
|----------|-----|-----|-----|
| English | AND | OR | NOT |
| German | UND | ODER | NICHT |
| French | ET | OU | SAUF |
| Spanish | Y | O | NO |
| Arabic | و | أو / او | ليس / لا |

```twig
{# German site - both work #}
{% set results = craft.searchManager.search('products', 'kaffee ODER tee') %}
{% set results = craft.searchManager.search('products', 'kaffee OR tee') %} {# English fallback #}

{# French site #}
{% set results = craft.searchManager.search('products', 'café OU thé') %}
{% set results = craft.searchManager.search('products', 'café SAUF décaféiné') %}
```

**Note:** English operators always work as fallback regardless of site language.

#### **7. Combined Operators (Power Queries)**
```twig
{# Complex query combining multiple operators #}
{% set results = craft.searchManager.search('entries',
    'craft* OR plugin title:tutorial NOT beginner "getting started"^2'
) %}
{# Finds:
   - Words starting with "craft" OR "plugin"
   - Must have "tutorial" in title
   - Excludes "beginner"
   - Boosts exact phrase "getting started" 2x
#}
```

**Fuzzy Matching:**
- Automatically finds similar terms using n-gram similarity
- Configurable similarity threshold (default: 0.50)
- Works with typos, missing letters, transpositions
- Examples: "tst" finds "test", "craaft" finds "craft"

**Ranking Priority (Highest to Lowest):**
1. Phrase matches (`"exact phrase"`) - 4x boost
2. Title matches - 5x boost
3. Exact matches (all terms present) - 3x boost
4. Per-term boosts (`term^2`) - custom multiplier
5. Single term matches - base BM25 score

### Caching

Search Manager includes two caching layers for optimal performance:

**Search Results Cache:**
- Caches search results to avoid repeated backend queries
- Reduces API costs for external services (Algolia, Meilisearch, Typesense)
- Improves response times for all backends
- Optional "Popular Queries Only" mode - only cache queries searched ≥ N times
- Configurable cache duration (default: 1 hour)
- **Storage options:**
  - **File** (default): `@storage/runtime/search-manager/cache/search/`
  - **Redis**: Uses Craft's Redis cache (recommended for edge networks)

**Device Detection Cache:**
- Caches parsed user-agent strings (device, browser, OS info)
- Powered by Matomo DeviceDetector library
- Prevents re-parsing the same user-agent repeatedly
- Configurable cache duration (default: 1 hour)
- Stored in: `@storage/runtime/search-manager/cache/device/`

**Configuration:**
```php
// config/search-manager.php
return [
    // Search results caching
    'enableCache' => true,
    'cacheStorageMethod' => 'file', // 'file' or 'redis' (use 'redis' for edge networks)
    'cacheDuration' => 3600, // 1 hour
    'cachePopularQueriesOnly' => false,
    'popularQueryThreshold' => 5, // Cache after 5 searches

    // Autocomplete caching (separate from search cache)
    'enableAutocompleteCache' => true,
    'autocompleteCacheDuration' => 300, // 5 minutes (shorter TTL for frequently-typed queries)

    // Cache warming (after index rebuild)
    'enableCacheWarming' => true,
    'cacheWarmingQueryCount' => 50, // Number of popular queries to pre-cache (10-200)

    // Device detection caching
    'cacheDeviceDetection' => true,
    'deviceDetectionCacheDuration' => 3600, // 1 hour
];
```

**Autocomplete Caching:**
- Cached per query prefix, index, and language
- Uses same storage method as search cache (file or Redis)
- Shorter default TTL (5 minutes) since autocomplete is called more frequently
- Can be enabled/disabled independently from search cache
- Cache keys are unique per index (no overlap between indices)

**Cache Warming:**
- Automatically runs after index rebuild completes
- Pulls popular queries from search analytics data
- Warms both search results cache and autocomplete suggestions
- Autocomplete warming includes common prefixes (2-5 characters) for each query
- Requires analytics to be enabled for the index
- Runs as a background queue job (doesn't block rebuild)

**When to Use Redis Cache:**
- ✅ Edge networks (Servd, Platform.sh, AWS with ElastiCache)
- ✅ Multi-server setups (shared cache across servers)
- ✅ High traffic sites (faster than file I/O)
- ✅ When Craft already uses Redis cache (reuses connection)

**When to Use File Cache:**
- ✅ Single-server setups
- ✅ Shared hosting without Redis
- ✅ Development environments
- ✅ Simple deployments

**Popular Queries Example:**
```
Query: "craft cms"
Search #1-4: Not cached (below threshold)
Search #5: Cached! (threshold met)
Search #6+: Served from cache (5ms vs 150ms)
```

**Benefits:**
- Faster response times (5-10ms vs 50-200ms)
- Reduced API costs (Algolia, Meilisearch, Typesense)
- Lower backend load (MySQL, Redis queries)
- Smart storage (popular queries only option)

### Highlighting & Snippets

Highlight matched search terms and show contextual excerpts:

```twig
{# Basic highlighting #}
{% set results = craft.searchManager.search('entries', 'craft cms') %}
{% for hit in results.hits %}
    {% set element = craft.entries.id(hit.objectID).one() %}
    <h2>{{ craft.searchManager.highlight(element.title, 'craft cms')|raw }}</h2>
    {# Output: <h2>This is about <mark>craft</mark> <mark>cms</mark></h2> #}
{% endfor %}

{# Generate context snippets #}
{% set snippets = craft.searchManager.snippets(element.body, 'craft cms', {
    snippetLength: 200,
    maxSnippets: 3
}) %}
{% for snippet in snippets %}
    <p>{{ snippet|raw }}</p>
    {# Output: "...tutorial about <mark>craft</mark> <mark>cms</mark> development..." #}
{% endfor %}

{# Custom highlighting options #}
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',
    class: 'search-highlight',
    stripTags: true
})|raw }}
```

**Configuration:**
```php
// config/search-manager.php
return [
    'enableHighlighting' => true,
    'highlightTag' => 'mark',           // HTML tag (mark, em, strong, span)
    'highlightClass' => 'search-highlight', // Optional CSS class
    'snippetLength' => 200,             // Characters per snippet
    'maxSnippets' => 3,                 // Max snippets per result
];
```

**CSS Styling:**
```css
mark {
    background-color: #ffeb3b;
    padding: 2px 4px;
    border-radius: 2px;
}

/* Or with custom class */
.search-highlight {
    background-color: #ff9800;
    color: #fff;
}
```

### Autocomplete / Suggestions

Provide search-as-you-type suggestions based on indexed terms:

```twig
{# Basic autocomplete #}
{% set suggestions = craft.searchManager.suggest('cra', 'entries') %}
{# Returns: ['craft', 'craftcms', 'create'] #}

{% for suggestion in suggestions %}
    <a href="?q={{ suggestion }}">{{ suggestion }}</a>
{% endfor %}

{# With options #}
{% set suggestions = craft.searchManager.suggest('te', 'entries', {
    limit: 5,              // Max suggestions
    minLength: 2,          // Min characters
    fuzzy: true,           // Enable typo-tolerance
    language: 'en'         // Filter by language
}) %}

{# AJAX endpoint example #}
<input type="search" id="search-input">
<script>
document.getElementById('search-input').addEventListener('input', async (e) => {
    const query = e.target.value;
    if (query.length < 2) return;

    const response = await fetch(`/actions/search-manager/api/autocomplete?q=${query}&index=entries&only=suggestions`);
    const suggestions = await response.json();
    // Display suggestions...
});
</script>
```

**Configuration:**
```php
// config/search-manager.php
return [
    'enableAutocomplete' => true,
    'autocompleteMinLength' => 2,      // Min chars before suggesting
    'autocompleteLimit' => 10,         // Max suggestions
    'autocompleteFuzzy' => false,      // Typo-tolerance (slower)

    // Autocomplete caching (separate from search cache)
    'enableAutocompleteCache' => true,
    'autocompleteCacheDuration' => 300, // 5 minutes
];
```

### AJAX / API Endpoints

Build instant search interfaces with AJAX endpoints:

**Autocomplete Endpoint:**
```javascript
// GET /actions/search-manager/api/autocomplete
// Default - returns both suggestions and element results
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&index=all-sites&limit=10');
const data = await response.json();
// Returns: {
//   "suggestions": ["test", "testing", "tested"],
//   "results": [
//     {"text": "Test Product", "type": "product", "id": 123},
//     {"text": "Testing Guide", "type": "article", "id": 456}
//   ]
// }

// Only suggestions - returns term strings
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&index=all-sites&only=suggestions');
const suggestions = await response.json();
// Returns: ["test", "testing", "tested"]

// Only results - returns element objects with type info
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&index=all-sites&only=results');
const results = await response.json();
// Returns: [
//   {"text": "Test Product", "type": "product", "id": 123},
//   {"text": "Testing Guide", "type": "article", "id": 456}
// ]

// Filter results by element type
const response = await fetch('/actions/search-manager/api/autocomplete?q=test&index=all-sites&only=results&type=product');
// Returns only product results
```

**Autocomplete API Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `index` | `all-sites` | Index handle to search |
| `limit` | `10` | Maximum suggestions/results |
| `only` | (none) | Return only `suggestions` or `results` (default returns both) |
| `type` | (none) | Filter results by element type (only affects `results`) |

**Autocomplete Response Formats:**

Default (no `only` param):
```json
{
  "suggestions": ["test", "testing", "tested"],
  "results": [
    {"text": "Test Product", "type": "product", "id": 123},
    {"text": "Test Category", "type": "category", "id": 45}
  ]
}
```

Only suggestions (`only=suggestions`):
```json
["test", "testing", "tested"]
```

Only results (`only=results`):
```json
[
  {"text": "Test Product", "type": "product", "id": 123},
  {"text": "Test Category", "type": "category", "id": 45},
  {"text": "Testing Guide", "type": "article", "id": 789}
]
```

**Element Type Detection:**
The `type` field is automatically derived from the element's section handle (singularized):

| Section Handle | Type |
|----------------|------|
| `products` | `product` |
| `categories` | `category` |
| `stores` | `store` |
| `blog-posts` | `blog-post` |

For non-Entry elements:
- Craft Categories → `category`
- Assets → `asset`
- Users → `user`
- Tags → `tag`

**Multi-section indices work correctly:**
```php
// all-ar index with multiple sections
'criteria' => fn($q) => $q->section(['products', 'categories', 'stores']),
// Each entry gets type from its own section:
// - Entry from products → type: "product"
// - Entry from stores → type: "store"
```

**Override in custom transformer:**
```php
$data['elementType'] = 'custom-type';
```

**Search Endpoint:**
```javascript
// GET /actions/search-manager/api/search
const response = await fetch('/actions/search-manager/api/search?q=craft cms&index=all-sites&limit=20');
const results = await response.json();
// Returns: {hits: [{objectID: 123, id: 123, score: 45.2, type: "product"}, ...], total: 15}

// Filter by element type
const response = await fetch('/actions/search-manager/api/search?q=bread&index=all-sites&type=product,category');
// Returns only products and categories

// Mobile app with localized operators (German)
const response = await fetch('/actions/search-manager/api/search?q=kaffee+ODER+tee&index=products&language=de');
// German OR operator works!
```

**Search API Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `index` | `all-sites` | Index handle to search |
| `limit` | `20` | Maximum results (use `0` for unlimited) |
| `type` | (none) | Filter by element type (e.g., `product`, `category`, `product,category`) |
| `language` | (site default) | Language code for localized operators (`en`, `de`, `fr`, `es`, `ar`) |
| `source` | (auto-detected) | Analytics source identifier (e.g., `ios-app`, `android-app`) |
| `platform` | (none) | Platform info for analytics (e.g., `iOS 17.2`, `Android 14`) |
| `appVersion` | (none) | App version for analytics (e.g., `2.1.0`) |

**Example: Instant Search with Type Icons**
```html
<input type="search" id="instant-search" placeholder="Search...">
<div id="suggestions"></div>
<div id="results"></div>

<script>
const input = document.getElementById('instant-search');
const suggestionsDiv = document.getElementById('suggestions');
let debounceTimer;

// Type to icon mapping
const typeIcons = {
    'product': '📦',
    'category': '🏷️',
    'article': '📄',
    'page': '📃',
    'entry': '📝'
};

input.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    const query = e.target.value;

    if (query.length < 2) return;

    debounceTimer = setTimeout(async () => {
        // Fetch both suggestions and results in one call
        const response = await fetch(
            `/actions/search-manager/api/autocomplete?q=${query}&index=all-sites`
        );
        const data = await response.json();

        // Display suggestions with icons
        suggestionsDiv.innerHTML = data.results.map(s => `
            <div class="suggestion" data-id="${s.id}">
                <span class="icon">${typeIcons[s.type] || '📝'}</span>
                <span class="text">${s.text}</span>
                <span class="type">${s.type}</span>
            </div>
        `).join('');

        // Fetch full search results
        const searchResponse = await fetch(
            `/actions/search-manager/api/search?q=${query}&index=all-sites`
        );
        const results = await searchResponse.json();
        displayResults(results.hits);
    }, 300);
});
</script>
```

**Features:**
- ✅ Works with MySQL, PostgreSQL, Redis, and File backends
- ✅ Returns real indexed terms and search results
- ✅ Supports all search operators in queries
- ✅ Language-aware (auto-detects from current site)
- ✅ Respects all configured settings (min length, limits, etc.)
- ✅ Element type detection for rich UI (icons, filtering)

**API Response Structure:**

Search response:
```json
{
  "hits": [
    {
      "objectID": 123,
      "id": 123,
      "promoted": true,
      "position": 1,
      "score": null,
      "type": "product",
      "title": "Featured Product"
    },
    {
      "objectID": 456,
      "id": 456,
      "score": 45.23,
      "type": "product"
    }
  ],
  "total": 150,
  "meta": {
    "synonymsExpanded": true,
    "expandedQueries": ["laptop", "notebook", "computer"],
    "rulesMatched": [
      {
        "id": 5,
        "name": "Laptop synonyms",
        "actionType": "synonym",
        "actionValue": ["notebook", "computer"]
      }
    ],
    "promotionsMatched": [
      {
        "id": 1,
        "elementId": 123,
        "position": 1
      }
    ]
  }
}
```

**Hit Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `objectID` | int | Element ID |
| `id` | int | Element ID (alias) |
| `score` | float\|null | BM25 relevance score (null for promoted items) |
| `type` | string | Element type (product, category, entry, etc.) |
| `promoted` | bool | Present and true for promoted/pinned results |
| `position` | int | Position in results (for promoted items) |
| `title` | string | Element title (for promoted items) |

**Default Limits:**
- Search API: 20 results (use `limit=0` for unlimited)
- Suggest API: 10 suggestions

**All search operators work:**
- Phrase: `?q="exact phrase"`
- Boolean: `?q=coffee OR tea`, `?q=coffee NOT decaf`
- Localized Boolean: `?q=kaffee ODER tee&language=de` (German)
- Wildcards: `?q=coff*`
- Field-specific: `?q=title:muesli`
- Boosting: `?q=coffee^2 beans`

**Mobile App Example (German):**
```javascript
// iOS app searching in German
const response = await fetch('/actions/search-manager/api/search?' + new URLSearchParams({
    q: 'kaffee ODER tee NICHT entkoffeiniert',
    index: 'products',
    language: 'de',
    source: 'ios-app',
    platform: 'iOS 17.2',
    appVersion: '2.1.0'
}));
// Uses German operators: ODER (OR), NICHT (NOT)
```

⚠️ **Note:** Default API limit (20) is hardcoded. TODO: Make configurable via settings.

### Analytics Source Detection

Search Manager automatically detects the source of search requests and tracks analytics accordingly.

**Auto-Detection Logic:**
- **CP** - Craft Control Panel requests (detected via `getIsCpRequest()`)
- **Frontend** - Referrer from same host (same-site search forms)
- **API** - No referrer or external referrer (direct API calls)

**Custom Source Tracking:**

For mobile apps or custom integrations, you can pass custom analytics data:

```php
// PHP - Pass custom analytics options
$results = SearchManager::$plugin->backend->search('products', 'shoes', [
    'siteId' => 1,
    'source' => 'ios-app',           // Custom source identifier
    'platform' => 'iOS 17.2',        // Platform/OS version
    'appVersion' => '2.1.0',         // Your app version
]);

// Or via Twig
{% set results = craft.searchManager.search('products', 'shoes', {
    source: 'android-app',
    platform: 'Android 14',
    appVersion: '1.5.2'
}) %}
```

**Example Source Values:**
- `frontend` - Website search (auto-detected)
- `cp` - Control Panel search (auto-detected)
- `api` - Direct API calls (auto-detected)
- `ios-app` - iOS mobile app
- `android-app` - Android mobile app
- `mobile-web` - Mobile web PWA
- `partner-api` - Third-party integrations

**What Gets Tracked:**
| Field | Auto-Detected | Can Override |
|-------|---------------|--------------|
| `source` | Yes (frontend/cp/api) | Yes |
| `platform` | No | Yes |
| `appVersion` | No | Yes |
| `ip` | Yes | No (security) |
| `country/city` | Yes | No (security) |
| `device/browser/os` | Yes (from User-Agent) | No |

**Analytics Dashboard:**

The Recent Searches tab displays source information:
- Source type (Frontend, CP, API, or custom)
- Platform and app version (when provided)
- Device, browser, and OS details

**CSV Export:**

Exported analytics include Platform and App Version columns for detailed analysis.

### Search Widget (Frontend Component)

The Search Widget provides a ready-to-use CMD+K style search modal for your frontend. It's a web component that handles all the UI, keyboard navigation, and search functionality.

**Basic Usage:**

```twig
{# Include the search widget with default config #}
{% include 'search-manager/_widget/search' %}

{# Include with a specific config handle #}
{% include 'search-manager/_widget/search' with {
    config: 'homepage',
} %}
```

**Customizing via Twig Parameters:**

```twig
{% include 'search-manager/_widget/search' with {
    config: 'homepage',
    indices: ['blog', 'products'],
    placeholder: 'Search articles...',
    theme: 'dark',
    maxResults: 15,
    debounce: 300,
    minChars: 2,
    showRecent: true,
    groupResults: true,
    hotkey: 'k',
    showTrigger: true,
    triggerText: 'Search',
    triggerSelector: '#my-search-btn',
    enableHighlighting: true,
    highlightTag: 'mark',
    highlightClass: 'search-highlight',
    backdropOpacity: 50,
    enableBackdropBlur: true,
    preventBodyScroll: true,
} %}
```

**Available Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `config` | string | Widget config handle (loads settings from CP) |
| `indices` | array | Search index handles (empty = search all) |
| `placeholder` | string | Input placeholder text |
| `theme` | string | 'light' or 'dark' |
| `maxResults` | int | Maximum results to show |
| `debounce` | int | Debounce delay in ms |
| `minChars` | int | Minimum characters before searching |
| `showRecent` | bool | Show recent searches |
| `groupResults` | bool | Group results by type/section |
| `hotkey` | string | Keyboard shortcut key |
| `showTrigger` | bool | Show the trigger button |
| `triggerText` | string | Trigger button text |
| `triggerSelector` | string | CSS selector for external trigger |
| `siteId` | int | Specific site ID to search |
| `class` | string | Additional CSS class |
| `dir` | string | Text direction 'ltr' or 'rtl' |
| `enableHighlighting` | bool | Enable term highlighting |
| `highlightTag` | string | HTML tag for highlights |
| `highlightClass` | string | CSS class for highlights |
| `backdropOpacity` | int | Backdrop opacity 0-100 |
| `enableBackdropBlur` | bool | Enable backdrop blur |
| `preventBodyScroll` | bool | Prevent body scroll when open |
| `styles` | object | Override individual style values |

**Connecting External Triggers:**

```twig
{# Your custom search button #}
<button id="my-search-btn" class="search-button">
    <svg>...</svg> Search
</button>

{# Widget with external trigger #}
{% include 'search-manager/_widget/search' with {
    showTrigger: false,
    triggerSelector: '#my-search-btn',
} %}
```

**Style Overrides:**

```twig
{% include 'search-manager/_widget/search' with {
    styles: {
        modalBg: '#1a1a1a',
        modalBorderRadius: '16',
        inputBg: '#2a2a2a',
        inputTextColor: '#ffffff',
        resultHoverBg: '#333333',
    }
} %}
```

**Managing Widget Configs (CP):**

Widget configurations can be managed in the Control Panel under Search Manager → Widgets:

- **Settings Tab**: Name, handle, search indices
- **Behavior Tab**: Debounce, min chars, max results, recent searches, grouped results, hotkey
- **Appearance Tab**: Colors, fonts, spacing, border radius, highlighting colors

**Programmatic Opening:**

```javascript
// Get the widget element
const widget = document.querySelector('search-widget');

// Open programmatically
widget.open();

// Close programmatically
widget.close();

// Toggle
widget.toggle();
```

**Click Analytics:**

The widget automatically tracks which results users click. This data appears in the Analytics section of the CP.

### Promotions (Pinned Results)

Promotions allow you to pin specific elements to fixed positions in search results, bypassing normal relevance scoring.

**Use Cases:**
- Feature a specific product when users search for a category
- Promote sale items for seasonal keywords
- Ensure important content appears first for specific queries

**Creating Promotions (Control Panel):**

1. Go to Search Manager → Promotions
2. Click "New Promotion"
3. Configure:
   - **Title**: Descriptive name for organization (e.g., "Holiday Sale Banner")
   - **Query Pattern**: The search query to match. Use commas for multiple patterns:
     - Single: `sale`
     - Multi-language: `sale, تخفيض, soldes, angebot` (EN, AR, FR, DE)
   - **Match Type**: How to match the query
     - **Exact**: Query must exactly match one of the patterns
     - **Contains**: Query must contain one of the patterns anywhere
     - **Prefix**: Query must start with one of the patterns
   - **Promoted Element**: Select the element to promote
   - **Position**: Where to place it (1 = first, 2 = second, etc.)
   - **Index**: All Indexes or a specific search index
   - **Site**: All Sites or a specific site

**Example Scenarios:**

```
Query Pattern: "laptop"
Match Type: Exact
Promoted Element: "MacBook Pro 2024" (Entry #123)
Position: 1

Result: When user searches exactly "laptop", MacBook Pro appears first
```

```
Query Pattern: "sale"
Match Type: Contains
Promoted Element: "Black Friday Deals" (Entry #456)
Position: 1

Result: Any query containing "sale" (e.g., "laptop sale", "sale items")
shows Black Friday Deals first
```

```
Query Pattern: "sale, تخفيض, soldes, angebot"
Match Type: Exact
Promoted Element: "Holiday Sale Banner" (Entry #789)
Position: 1
Index: All Indexes
Site: All Sites

Result: One promotion works across all languages - matches "sale" (EN),
"تخفيض" (AR), "soldes" (FR), or "angebot" (DE)
```

**Bulk Actions:**
- Select multiple promotions using checkboxes
- Enable/disable or delete in bulk
- Filter by status or match type

**Per-Site Element Status:**

Promotions automatically respect element status on a per-site basis:
- If an element is **disabled** for Site 1 but **enabled** for Site 2, the promotion will only appear on Site 2
- Elements with **pending** or **expired** post dates are excluded
- Uses Craft's `status('live')` to check all status conditions

```
Example:
- Product "Summer Sale" is linked to promotion for query "sale"
- Product is disabled for English site, enabled for French/Arabic sites
- English site searches: promotion NOT shown
- French site searches: promotion shown at position 1
```

**API Response:**

Promoted items appear in `hits` with `promoted: true`, `position`, and `score: null`. See the main [API Response Structure](#api-response-structure) for full details.

### Query Rules

Query Rules modify search behavior when queries match specific patterns. They support synonyms, boosting, filtering, and redirects.

**Creating Query Rules (Control Panel):**

1. Go to Search Manager → Query Rules
2. Click "New Query Rule"
3. Select action type and configure

**Action Types:**

#### 1. Synonyms
Expand search queries to include related terms.

```
Name: Laptop Synonyms
Match Value: laptop
Match Type: Exact
Action: Synonyms
Terms: notebook, portable computer, macbook

Result: Searching "laptop" also finds results containing
"notebook", "portable computer", or "macbook"
```

#### 2. Boost Section
Increase relevance score for results from a specific section.

```
Name: Boost News for Current Events
Match Value: election
Match Type: Contains
Action: Boost Section
Section: news
Multiplier: 2.0

Result: News articles rank 2x higher when query contains "election"
```

#### 3. Boost Category
Increase relevance score for results in a specific category.

```
Name: Boost Electronics for Tech Queries
Match Value: tech
Match Type: Prefix
Action: Boost Category
Category: Electronics
Multiplier: 1.5

Result: Queries starting with "tech" boost Electronics category results 1.5x
```

#### 4. Boost Element
Increase relevance score for a specific element.

```
Name: Boost FAQ for Help Queries
Match Value: help
Match Type: Contains
Action: Boost Element
Element ID: 789
Multiplier: 3.0

Result: FAQ page (ID 789) ranks 3x higher for queries containing "help"
```

#### 5. Filter Results
Filter search results by field value when query matches.

```
Name: Filter to In-Stock Only
Match Value: buy
Match Type: Contains
Action: Filter
Field: inStock
Value: true

Result: Queries containing "buy" only show in-stock items
```

#### 6. Redirect
Redirect users to a specific page instead of showing search results. Supports four redirect types:

**Custom URL:**
```
Name: Contact Redirect
Match Value: contact us
Match Type: Exact
Action: Redirect
Redirect To: Custom URL
URL: /contact

Result: Searching exactly "contact us" redirects to /contact page
```

**Entry, Category, or Asset:**
```
Name: Support Redirect
Match Value: help, support, assistance
Match Type: Exact
Action: Redirect
Redirect To: Entry
Select Entry: "Help Center" (Entry #456)

Result: Searching "help", "support", or "assistance" redirects to Help Center entry
```

Redirect types:
- **Custom URL** - Path (/page) or full URL (https://...)
- **Entry** - Select any entry via element picker
- **Category** - Select any category via element picker
- **Asset** - Select any asset via element picker (e.g., PDF download)

**Priority System:**

Rules are applied in priority order. Higher priority rules are checked first.

| Priority | Label | Use Case |
|----------|-------|----------|
| 10 | Highest priority | Specific, high-value rules (e.g., "buy iphone 15 pro max") |
| 5 | High priority | Important rules (e.g., "buy iphone") |
| 0 | Normal (default) | Standard rules |
| -5 | Low priority | General rules |
| -10 | Lowest priority | Catch-all/fallback rules (e.g., "buy") |

**Example:** Set specific rule "buy iphone 15" to priority 10, and general rule "buy" to priority -10. The specific rule matches first when applicable.

**Scope:**
- **Index**: Apply to all indices (leave blank) or a specific index
- **Site**: Apply to all sites (leave blank) or a specific site

**API Response Metadata:**

When query rules are applied, they appear in the `meta.rulesMatched` array:
```json
{
  "hits": [...],
  "meta": {
    "rulesMatched": [
      {
        "id": 5,
        "name": "Boost Electronics",
        "actionType": "boost_element",
        "actionValue": {"elementId": 123, "multiplier": 2.0}
      }
    ]
  }
}
```

The `actionValue` format varies by action type:
- **boost_element**: `{"elementId": 123, "multiplier": 2.0}`
- **boost_section**: `{"sectionHandle": "products", "multiplier": 2.0}`
- **boost_category**: `{"categoryId": 5, "multiplier": 1.5}`
- **synonym**: `["notebook", "computer", "laptop"]`
- **filter**: `{"field": "status", "value": "featured"}`
- **redirect**: `"/sale-page"`

**Match Types:**
| Type | Description | Example |
|------|-------------|---------|
| Exact | Query must match exactly | `laptop` matches only "laptop" |
| Contains | Query must contain pattern | `laptop` matches "best laptop deals" |
| Prefix | Query must start with pattern | `lap` matches "laptop", "lapel" |
| Regex | Regular expression pattern | `^(buy\|purchase)` matches "buy..." or "purchase..." |

**Multi-Language Patterns:**

Use commas to match multiple patterns in one rule (Exact, Contains, Prefix):
```
sale, تخفيض, soldes, angebot
```
This matches "sale" (EN), "تخفيض" (AR), "soldes" (FR), or "angebot" (DE).

For Regex, use the `|` operator instead:
```
^(sale|تخفيض|soldes|angebot)
```

### Multi-Language Support

Search Manager automatically handles multiple languages:

```php
// config/search-manager.php
'indices' => [
    'entries-en' => [
        'siteId' => 1,
        'language' => 'en',  // Optional override (auto-detected from site)
    ],
    'entries-ar' => [
        'siteId' => 2,
        'language' => 'ar',  // Arabic with regional fallback
    ],
    'all-entries' => [
        'siteId' => null,    // All sites - language per document
    ],
],
```

**Supported Languages:**
- **English** (en) - 297 stop words + AND/OR/NOT operators
- **Arabic** (ar) - 122 stop words + و/أو/او/ليس/لا operators (supports spelling variations)
- **German** (de) - 130+ stop words + UND/ODER/NICHT operators
- **French** (fr) - 140+ stop words + ET/OU/SAUF operators
- **Spanish** (es) - 135+ stop words + Y/O/NO operators

**Localized Boolean Operators:**

Each language supports native boolean operators (case-insensitive):

| Language | AND | OR | NOT | Example |
|----------|-----|-----|-----|---------|
| English | AND | OR | NOT | `coffee OR tea NOT decaf` |
| German | UND | ODER | NICHT | `kaffee ODER tee NICHT entkoffeiniert` |
| French | ET | OU | SAUF | `café OU thé SAUF décaféiné` |
| Spanish | Y | O | NO | `café O té NO descafeinado` |
| Arabic | و | أو / او | ليس / لا | `قهوة او شاي لا منزوع` |

**Note:** English operators always work as fallback regardless of site language.

**Regional Variants:**
```bash
# Create regional stop words
mkdir -p config/search-manager/stopwords
cp vendor/.../src/search/stopwords/ar.php config/search-manager/stopwords/ar-sa.php
# Edit ar-sa.php for Saudi-specific terms
```

**Language Filtering:**
```twig
{# Search specific language #}
{% set enResults = craft.searchManager.search('all-entries', 'test', {
    language: 'en'  // Only English results
}) %}

{# Auto-detects from current site #}
{% set results = craft.searchManager.search('all-entries', 'test') %}
{# On English site → filters to 'en' automatically #}
```

**Fallback Chain:**
```
ar-sa → config/ar-sa.php → plugin/ar-sa.php → config/ar.php → plugin/ar.php
```

### Multi-Environment Index Prefix

Use `indexPrefix` to automatically prefix all index names per environment. This allows you to define indices once and deploy across dev/staging/production without conflicts.

**How It Works:**

1. Define indices with base names (no prefix):
```php
// config/search-manager.php
'indices' => [
    'vehicles_used_en' => [
        'name' => 'Vehicles (English)',
        'elementType' => Entry::class,
        'siteId' => 1,
        // ...
    ],
    'vehicles_used_ar' => [
        'name' => 'Vehicles (Arabic)',
        'elementType' => Entry::class,
        'siteId' => 2,
        // ...
    ],
],
```

2. Set `indexPrefix` per environment:
```php
'dev' => [
    'indexPrefix' => 'local_',
],
'staging' => [
    'indexPrefix' => 'stage_',
],
'production' => [
    'indexPrefix' => 'prod_',
],
```

3. The plugin automatically creates prefixed index names in your backend:

| Environment | Index Handle | Actual Backend Index Name |
|-------------|--------------|---------------------------|
| Dev | `vehicles_used_en` | `local_vehicles_used_en` |
| Staging | `vehicles_used_en` | `stage_vehicles_used_en` |
| Production | `vehicles_used_en` | `prod_vehicles_used_en` |

**Benefits:**
- Single index configuration across all environments
- No need for separate `.env` variables per index
- Safe to run dev/staging/production on same Algolia account
- Prevents accidental data overwrites between environments

**Using Environment Variables:**
```php
// config/search-manager.php
'*' => [
    'indexPrefix' => App::env('SEARCH_INDEX_PREFIX'),
],
```

```bash
# .env.dev
SEARCH_INDEX_PREFIX=local_

# .env.staging
SEARCH_INDEX_PREFIX=stage_

# .env.production
SEARCH_INDEX_PREFIX=prod_
```

### Auto-Indexing

Elements are automatically indexed when saved if `autoIndex` is enabled in settings.

### Manual Indexing

```php
use lindemannrock\searchmanager\SearchManager;

// Index single element
SearchManager::$plugin->indexing->indexElement($entry);

// Rebuild an index
SearchManager::$plugin->indexing->rebuildIndex('entries-en');

// Rebuild all indices
SearchManager::$plugin->indexing->rebuildAll();
```

## Backend Configuration

Backends are configured as named instances using `backends`. Each backend has a unique handle and can be defined in config or created via the Control Panel.

### Algolia

```php
'backends' => [
    'production-algolia' => [
        'name' => 'Production Algolia',
        'backendType' => 'algolia',
        'enabled' => true,
        'settings' => [
            'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
            'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
            'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
            'timeout' => 5,
            'connectTimeout' => 1,
        ],
    ],
],
```

### File (Built-in)

```php
'backends' => [
    'local-file' => [
        'name' => 'Local File Storage',
        'backendType' => 'file',
        'enabled' => true,
        'settings' => [],
        // Index data: storage/runtime/search-manager/indices/
        // Search cache: storage/runtime/search-manager/cache/search/
        // Device cache: storage/runtime/search-manager/cache/device/
    ],
],
```

### Meilisearch

```php
'backends' => [
    'dev-meilisearch' => [
        'name' => 'Development Meilisearch',
        'backendType' => 'meilisearch',
        'enabled' => true,
        'settings' => [
            'host' => App::env('MEILISEARCH_HOST') ?: 'http://localhost:7700',
            'apiKey' => App::env('MEILISEARCH_API_KEY'),
            'timeout' => 5,
        ],
    ],
],
```

### MySQL / PostgreSQL (Built-in)

Uses Craft's existing database connection - no additional configuration needed.

```php
'backends' => [
    'craft-mysql' => [
        'name' => 'Craft MySQL',
        'backendType' => 'mysql',
        'enabled' => true,
        'settings' => [],
        // Uses Craft's MySQL database
    ],
    // Or for PostgreSQL installations:
    'craft-pgsql' => [
        'name' => 'Craft PostgreSQL',
        'backendType' => 'pgsql',
        'enabled' => true,
        'settings' => [],
        // Uses Craft's PostgreSQL database
    ],
],
```

**Note:** Only the backend matching your Craft database will be available. MySQL backend requires Craft to use MySQL, PostgreSQL backend requires Craft to use PostgreSQL.

### Redis

**Option 1: Reuse Craft's Redis Cache (No Config Needed)**

If Craft is configured to use Redis cache in `config/app.php`, Search Manager can automatically reuse that connection:

```php
'backends' => [
    'craft-redis' => [
        'name' => 'Craft Redis Cache',
        'backendType' => 'redis',
        'enabled' => true,
        'settings' => [],
        // Leave settings empty to use Craft's Redis connection
    ],
],
```

**Option 2: Dedicated Redis Connection**

Configure a separate Redis connection for search:

```php
'backends' => [
    'dedicated-redis' => [
        'name' => 'Dedicated Redis',
        'backendType' => 'redis',
        'enabled' => true,
        'settings' => [
            'host' => App::env('REDIS_HOST') ?: 'redis',
            'port' => App::env('REDIS_PORT') ?: 6379,
            'password' => App::env('REDIS_PASSWORD'),
            'database' => App::env('REDIS_DATABASE') ?: 0,
        ],
    ],
],
```

**Environment Variables (.env):**
```bash
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

**Or via Control Panel:**
- Leave all fields empty to reuse Craft's Redis cache
- Or use `$REDIS_HOST` format in settings (plugin resolves environment variables automatically)
- Required fields: Host, Port, Database (Password is optional)

**⚠️ Important: Docker/DDEV Environments**

When running in Docker containers (DDEV, Docker Compose, etc.):

- **`127.0.0.1` won't work** - This refers to localhost inside the container, not your host machine
- **Use the service hostname** - For DDEV, use `redis` as the host (matches the Redis service name)
- **Craft's cache may work differently** - Index rebuilds may succeed (using Craft's Redis cache) while auto-sync fails (using configured backend settings)

If you see `Connection refused` errors in logs, check your Redis host setting:
```
[ERROR] Redis connection error | {"host":"127.0.0.1","port":6379,"error":"Connection refused"}
```

**Fix:** Update your config or environment variables to use the correct hostname:
```bash
# .env for DDEV
REDIS_HOST=redis

# .env for Docker Compose (use your service name)
REDIS_HOST=redis-server
```

### Typesense

```php
'backends' => [
    'typesense-server' => [
        'name' => 'Typesense Server',
        'backendType' => 'typesense',
        'enabled' => true,
        'settings' => [
            'host' => 'localhost',
            'port' => '8108',
            'protocol' => 'http',
            'apiKey' => App::env('TYPESENSE_API_KEY'),
            'connectionTimeout' => 5,
        ],
    ],
],
```

### Multiple Backends

You can configure multiple backends and switch between them per environment:

```php
return [
    '*' => [
        'defaultBackendHandle' => 'dev-meilisearch',
        'backends' => [
            'dev-meilisearch' => [
                'name' => 'Development Meilisearch',
                'backendType' => 'meilisearch',
                'enabled' => true,
                'settings' => [
                    'host' => 'http://localhost:7700',
                    'apiKey' => App::env('MEILISEARCH_API_KEY'),
                ],
            ],
            'production-algolia' => [
                'name' => 'Production Algolia',
                'backendType' => 'algolia',
                'enabled' => true,
                'settings' => [
                    'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
                    'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
                    'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
                ],
            ],
        ],
    ],
    'production' => [
        'defaultBackendHandle' => 'production-algolia',
    ],
];
```

### Config vs Database Backends

Backends can be defined in two ways:

- **Config-defined**: Defined in `config/search-manager.php`. Cannot be edited in Control Panel. Shows "Config" source badge.
- **Database-defined**: Created via Control Panel. Fully editable. Shows "Database" source badge.

If a config backend has the same handle as a database backend, the config version takes precedence.

## Utilities & Cache Management

### Plugin Utilities (Control Panel → Utilities → Search Manager)

#### Index Management
- **Rebuild All Indices** - Refresh all indexed data from Craft elements
- **Clear Backend Storage** - Delete all indexed data (File/MySQL/Redis/Algolia/etc.)
  - Adapts to your active backend automatically
  - Shows current storage count (files, rows, or indices)

#### Cache Management
- **Clear Device Cache** - Delete cached device detection results
- **Clear Search Cache** - Delete cached search query results
- **Clear Autocomplete Cache** - Delete cached autocomplete suggestions
- **Clear All Caches** - Clear device, search, and autocomplete caches
  - Only shows when at least one cache is enabled
  - Shows cache file counts in real-time

#### Per-Index Cache Clearing
- **Clear Index Cache** - Available in indices listing and index edit page
  - Clears both search and autocomplete cache for a specific index
  - Other indices' caches remain untouched
  - Requires `searchManager:clearCache` permission
  - Only shows when search or autocomplete caching is enabled

#### Analytics Data Management
- **Clear All Analytics** - Permanently delete all search tracking data
  - Double confirmation required (destructive action)
  - Only shows when analytics is enabled

### Craft's Clear Caches Utility

Search Manager integrates with Craft's built-in Clear Caches utility:

- **{pluginName} search caches** - Clear cached search results (safe, auto-regenerate on next search)

**Note:** Index clearing is intentionally not available in Clear Caches because clearing indices breaks search until manually rebuilt. Use the plugin's "Rebuild All Indices" utility action instead.

## Console Commands

### Index Management

```bash
# List all indices
php craft search-manager/index/list

# Rebuild all indices
php craft search-manager/index/rebuild

# Rebuild specific index
php craft search-manager/index/rebuild entries-en

# Clear all indices
php craft search-manager/index/clear

# Clear specific index
php craft search-manager/index/clear entries-en
```

### Security & Analytics

```bash
# Generate IP hash salt for analytics (REQUIRED for analytics)
php craft search-manager/security/generate-salt

# With DDEV
ddev craft search-manager/security/generate-salt
```

**Important:** Run `generate-salt` immediately after installation to enable analytics tracking.

## Events

```php
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\events\IndexEvent;
use yii\base\Event;

// Modify data before indexing
Event::on(
    IndexingService::class,
    IndexingService::EVENT_BEFORE_INDEX,
    function(IndexEvent $event) {
        // Modify $event->element or set $event->isValid = false to cancel
    }
);

// React after indexing
Event::on(
    IndexingService::class,
    IndexingService::EVENT_AFTER_INDEX,
    function(IndexEvent $event) {
        // Access $event->data (indexed document)
        // Access $event->indexHandle
    }
);
```

## Permissions

### Backends
- **Manage backends**: Full access to search backends
  - **View backends**: Can view backends in CP
  - **Create backends**: Can create new backends
  - **Edit backends**: Can edit existing backends
  - **Delete backends**: Can delete backends

### Indices
- **Manage indices**: Full access to search indices
  - **View indices**: Can view search indices in CP
  - **Create indices**: Can create new indices
  - **Edit indices**: Can edit existing indices
  - **Delete indices**: Can delete indices
  - **Rebuild indices**: Can rebuild indices
  - **Clear indices**: Can clear index data

### Promotions
- **Manage promotions**: Full access to promotions (pinned results)
  - **View promotions**: Can view promotions in CP
  - **Create promotions**: Can create new promotions
  - **Edit promotions**: Can edit existing promotions
  - **Delete promotions**: Can delete promotions

### Query Rules
- **Manage query rules**: Full access to query rules (synonyms, boosts, etc.)
  - **View query rules**: Can view query rules in CP
  - **Create query rules**: Can create new query rules
  - **Edit query rules**: Can edit existing query rules
  - **Delete query rules**: Can delete query rules

### Widget Configs
- **Manage widget configs**: Full access to frontend search widget configurations
  - **View widget configs**: Can view widget configs in CP
  - **Create widget configs**: Can create new widget configs
  - **Edit widget configs**: Can edit existing widget configs
  - **Delete widget configs**: Can delete widget configs

### Analytics
- **View analytics**: Can view analytics dashboard and search statistics
  - **Export analytics**: Can export analytics data
  - **Clear analytics**: Can clear analytics data

### Other
- **Clear cache**: Can clear search, autocomplete, and device caches (global and per-index)
- **View logs**: Can view plugin logs
  - **Download logs**: Can download log files
- **Manage settings**: Can change plugin settings

## Configuration

### General Settings

```php
return [
    '*' => [
        // Plugin display name
        'pluginName' => 'Search Manager',

        // Logging level: debug, info, warning, error
        'logLevel' => 'error',

        // Auto-index elements when saved
        'autoIndex' => true,

        // Use queue for indexing operations
        'queueEnabled' => true,

        // Replace Craft's native search service
        'replaceNativeSearch' => false,

        // Batch size for bulk operations
        // Reduce if experiencing memory issues with large relational data
        'batchSize' => 100,

        // Prefix for index names (useful for multi-environment)
        'indexPrefix' => App::env('SEARCH_INDEX_PREFIX'),

        // Default backend to use (must match a handle from backends)
        'defaultBackendHandle' => 'my-backend',

        // Analytics settings
        'enableAnalytics' => true,
        'analyticsRetention' => 90, // days
        'anonymizeIpAddress' => false, // Subnet masking for privacy
        'enableGeoDetection' => false, // Track visitor location
        'ipHashSalt' => App::env('SEARCH_MANAGER_IP_SALT'),
        'defaultCountry' => App::env('SEARCH_MANAGER_DEFAULT_COUNTRY') ?: 'AE',
        'defaultCity' => App::env('SEARCH_MANAGER_DEFAULT_CITY') ?: 'Dubai',

        // BM25 Algorithm Parameters (MySQL, Redis, File backends)
        'bm25K1' => 1.5,
        'bm25B' => 0.75,
        'titleBoostFactor' => 5.0,
        'exactMatchBoostFactor' => 3.0,
        'ngramSizes' => '2,3',
        'similarityThreshold' => 0.50,
        'maxFuzzyCandidates' => 100,

        // Cache settings
        'enableCache' => true, // Enable search results caching
        'cacheStorageMethod' => 'file', // Storage: 'file' or 'redis'
        'cacheDuration' => 3600, // Cache TTL in seconds (3600 = 1 hour)
        'cachePopularQueriesOnly' => false, // Only cache frequently-searched queries
        'popularQueryThreshold' => 5, // Minimum search count before caching
        'cacheDeviceDetection' => true, // Cache device detection results
        'deviceDetectionCacheDuration' => 3600, // Device cache TTL in seconds

        // Autocomplete cache settings (separate from search cache)
        'enableAutocompleteCache' => true, // Cache autocomplete suggestions
        'autocompleteCacheDuration' => 300, // Autocomplete cache TTL (300 = 5 minutes)

        // Cache warming settings (after index rebuild)
        'enableCacheWarming' => true, // Pre-cache popular queries after rebuild
        'cacheWarmingQueryCount' => 50, // Number of queries to warm (10-200)

        // Cache invalidation settings
        'clearCacheOnSave' => true, // Clear search cache when elements are saved
        'statusSyncInterval' => 15, // Minutes between status sync jobs (0 = disabled)
    ],
];
```

### Environment-Specific Configuration

```php
return [
    '*' => [
        'defaultBackendHandle' => 'dev-mysql',
        'logLevel' => 'error',
        'enableAnalytics' => true,

        // Define all backends in one place
        'backends' => [
            'dev-mysql' => [
                'name' => 'Development MySQL',
                'backendType' => 'mysql',
                'enabled' => true,
                'settings' => [],
            ],
            'staging-redis' => [
                'name' => 'Staging Redis',
                'backendType' => 'redis',
                'enabled' => true,
                'settings' => [],
            ],
            'production-algolia' => [
                'name' => 'Production Algolia',
                'backendType' => 'algolia',
                'enabled' => true,
                'settings' => [
                    'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
                    'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
                    'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
                ],
            ],
        ],
    ],

    'dev' => [
        'defaultBackendHandle' => 'dev-mysql',
        'logLevel' => 'debug',
        'indexPrefix' => 'dev_',
        'queueEnabled' => false,
        'enableCache' => false, // Disable cache for testing
        'cacheDuration' => 300, // 5 minutes (if enabled)
        'deviceDetectionCacheDuration' => 1800, // 30 minutes
        'analyticsRetention' => 30,
    ],

    'staging' => [
        'defaultBackendHandle' => 'staging-redis',
        'logLevel' => 'info',
        'indexPrefix' => 'staging_',
        'enableCache' => true,
        'cacheStorageMethod' => 'redis', // Use Redis for edge networks
        'cacheDuration' => 1800, // 30 minutes
        'deviceDetectionCacheDuration' => 3600, // 1 hour
        'analyticsRetention' => 90,
    ],

    'production' => [
        'defaultBackendHandle' => 'production-algolia',
        'logLevel' => 'error',
        'indexPrefix' => 'prod_',
        'queueEnabled' => true,
        'enableCache' => true,
        'cacheStorageMethod' => 'redis', // Use Redis for edge networks (Servd/AWS/Platform.sh)
        'cacheDuration' => 7200, // 2 hours (optimize for performance)
        'deviceDetectionCacheDuration' => 86400, // 24 hours
        'cachePopularQueriesOnly' => true, // Save cache space
        'popularQueryThreshold' => 3, // Cache after 3 searches
        'analyticsRetention' => 365,
        'enableGeoDetection' => true,
    ],
];
```

## Performance & Troubleshooting

### Memory Issues During Indexing

⚠️ **If you experience memory exhaustion errors during index rebuilds:**

**Symptoms:**
```
PHP Fatal error: Allowed memory size of 536870912 bytes exhausted
Queue job failed with memory error
```

**Common Causes:**
- AutoTransformer loading large amounts of relational data (Entries, Categories, Matrix blocks)
- Products with many related entries (20+ relations per product)
- Batch size too large for available memory
- Deeply nested Matrix fields

**Solutions:**

**1. Reduce Batch Size (Recommended)**
```php
// config/search-manager.php
return [
    '*' => [
        'batchSize' => 100, // Default
    ],
    'staging' => [
        'batchSize' => 10, // Smaller for memory-constrained environments
    ],
    'production' => [
        'batchSize' => 25, // Balance between speed and memory
    ],
];
```

**Guidelines:**
- ✅ **Default (100):** Works for simple entries without many relations
- ✅ **Medium (25-50):** Good for entries with moderate relational fields
- ✅ **Small (10-25):** Use when entries have extensive relational data
- ✅ **Very Small (5-10):** Last resort for extremely complex data structures

**2. Increase PHP Memory Limit**

The rebuild job automatically increases memory to 1GB, but you may need more:

```php
// In your .env or php.ini
memory_limit = 2G
```

**3. Optimize AutoTransformer**

If you don't need to index all relational field data, create a custom transformer:

```php
class ProductTransformer extends BaseTransformer
{
    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        // Only index specific fields (avoid loading all relations)
        $data['content'] = $element->description;
        $data['sku'] = $element->sku;

        // Don't traverse deep relational fields
        // $data['related'] = ... // Skip if causing memory issues

        return $data;
    }
}
```

**Memory Usage Reference:**
- 100 simple entries: ~50-100MB
- 100 entries with 5-10 relations each: ~200-400MB
- 100 entries with 20+ relations each: ~500MB-1GB
- 341 products with extensive relations: ~500MB+ (reduce batch size to 10-25)

**Best Practices:**
- Monitor memory usage in production logs
- Start with default batch size (100)
- Reduce if seeing memory errors
- Use staging environment to test optimal batch size
- Consider custom transformers for complex data

### Fuzzy Search Tuning

**Fuzzy matching uses n-gram similarity for typo tolerance.** Default threshold is 0.50 (balanced).

**If you see too many false positives:**

**Symptoms:**
- "freezab" finds "free" (too different)
- "suga" finds unrelated terms
- Search results include irrelevant matches

**Solution - Increase Similarity Threshold:**

```php
// config/search-manager.php
return [
    '*' => [
        'similarityThreshold' => 0.60, // Stricter matching
    ],
];
```

**If you want more lenient matching:**

**Symptoms:**
- Common typos not found ("teh" doesn't find "the")
- Missing relevant results
- Need more typo tolerance

**Solution - Decrease Similarity Threshold:**

```php
// config/search-manager.php
return [
    '*' => [
        'similarityThreshold' => 0.35, // More lenient
    ],
];
```

**Threshold Guidelines:**
- ✅ **0.25:** Maximum typo tolerance, more false positives
- ✅ **0.35:** Good typo tolerance, some false positives
- ✅ **0.50 (Default):** Balanced - good typos, fewer false positives
- ✅ **0.60:** Strict - only very similar terms
- ✅ **0.75:** Very strict - almost exact matches only

**Test and adjust based on your content and search behavior.**

## Migrating from Scout

### 1. Install Search Manager

```bash
composer require lindemannrock/craft-search-manager
php craft plugin/install search-manager
```

### 2. Copy Indices Configuration

Convert your Scout indices from `config/scout.php` to `config/search-manager.php`:

**Scout format:**
```php
'indices' => [
    \rias\scout\ScoutIndex::create('entries-en')
        ->elementType(\craft\elements\Entry::class)
        ->criteria(fn($query) => $query->section('news')->siteId(1))
        ->transformer(new \modules\transformers\EntryTransformer()),
],
```

**Search Manager format:**
```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => 1,
        'criteria' => fn($query) => $query->section('news'),
        'transformer' => \modules\transformers\EntryTransformer::class,
        'enabled' => true,
    ],
],
```

### 3. Update Transformers

Change transformer parent class:

**Scout:**
```php
use League\Fractal\TransformerAbstract;

class EntryTransformer extends TransformerAbstract
{
    public function transform(Entry $entry) { ... }
}
```

**Search Manager:**
```php
use lindemannrock\searchmanager\transformers\BaseTransformer;

class EntryTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array { ... }
}
```

### 4. Rebuild Indices

```bash
php craft search-manager/index/rebuild
```

### 5. Remove Scout

```bash
composer remove rias/craft-scout
```

## Logging

Search Manager uses the [LindemannRock Logging Library](https://github.com/LindemannRock/craft-logging-library) for centralized logging.

### Log Levels
- **Error**: Critical errors only (default)
- **Warning**: Errors and warnings
- **Info**: General information
- **Debug**: Detailed debugging (requires devMode)

### Configuration
```php
// config/search-manager.php
return [
    'logLevel' => 'error', // error, warning, info, or debug
];
```

**Note:** Debug level requires Craft's `devMode` to be enabled. If set to debug with devMode disabled, it automatically falls back to info level.

### Log Files
- **Location**: `storage/logs/search-manager-YYYY-MM-DD.log`
- **Retention**: 30 days (automatic cleanup via Logging Library)
- **Format**: Structured JSON logs with context data
- **Web Interface**: View and filter logs in CP at Search Manager → Logs

### Log Management
Access logs through the Control Panel:
1. Navigate to Search Manager → Logs
2. Filter by date, level, or search terms
3. Download log files for external analysis
4. View file sizes and entry counts
5. Auto-cleanup after 30 days (configurable via Logging Library)

**Requires:** `lindemannrock/craft-logging-library` plugin (installed automatically as dependency)

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-search-manager](https://github.com/LindemannRock/craft-search-manager)
- **Issues**: [https://github.com/LindemannRock/craft-search-manager/issues](https://github.com/LindemannRock/craft-search-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the MIT License. See [LICENSE](LICENSE) for details.

## Credits

Created by [LindemannRock](https://lindemannrock.com)
