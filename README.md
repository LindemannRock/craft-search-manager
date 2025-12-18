# Search Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-search-manager.svg)](https://packagist.org/packages/lindemannrock/craft-search-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-search-manager.svg)](LICENSE)

Advanced multi-backend search management for Craft CMS - supports Algolia, File, Meilisearch, MySQL, PostgreSQL, Redis, and Typesense.

## Features

### 🔍 Multi-Backend Support
- **Algolia** - Cloud-hosted search service (Scout replacement)
- **File** - Local file storage in `@storage/runtime/search-manager/indices/` (no external dependencies)
- **Meilisearch** - Self-hosted, open-source alternative to Algolia
- **MySQL** - Built-in BM25 search using Craft's MySQL database
- **PostgreSQL** - Built-in BM25 search using Craft's PostgreSQL database
- **Redis** - Fast in-memory BM25 search with persistence (can reuse Craft's Redis cache)
- **Typesense** - Open-source search engine with typo tolerance

### 🎯 Advanced Search Features (MySQL, PostgreSQL, Redis, File)

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
- **Auto-Detection** - Language detected from element's site automatically
- **Regional Variants** - Support for ar-SA (Saudi), ar-EG (Egypt), fr-CA (Quebec), etc.
- **Language Filtering** - Filter results by language for multi-site indices

### 📊 Comprehensive Analytics
- **Search Tracking** - Track every search query with results count and execution time
- **Device Detection** - Powered by Matomo DeviceDetector for accurate device, browser, and OS identification
- **Geographic Detection** - Track visitor location (country, city, region) via ip-api.com
- **Bot Filtering** - Identify and filter bot traffic (GoogleBot, BingBot, etc.)
- **Zero-Result Tracking** - Identify queries that return no results for optimization
- **Performance Metrics** - Track search execution time and backend performance
- **Privacy-First** - IP hashing with salt, optional subnet masking, GDPR-friendly
- **Referrer Tracking** - See where search traffic is coming from
- **Automatic Cleanup** - Configurable retention period (0-3650 days)

### ⚡ Performance Caching
- **Search Results Cache** - Cache search results to reduce backend load and improve response times
- **Device Detection Cache** - Cache parsed user-agent strings to avoid re-parsing
- **Popular Queries Only** - Only cache frequently-searched queries to save storage space
- **Configurable Durations** - Set cache TTL per cache type (default: 1 hour)
- **Cache Management** - Clear caches via Control Panel utilities or Craft's Clear Caches
- **Craft Integration** - Search caches available in Craft's Clear Caches utility (safe, auto-regenerate)
- **Storage Locations**:
  - Device cache: `@storage/runtime/search-manager/cache/device/`
  - Search cache: `@storage/runtime/search-manager/cache/search/`

### Automatic Indexing
- Auto-index elements when saved (configurable)
- Queue-based batch indexing for better performance
- Manual rebuild via Control Panel or CLI
- Element deletion automatically removes from index

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

### Control Panel Interface
- Full CP section for managing indices
- Create, edit, delete, rebuild indices
- Backend status monitoring
- Analytics dashboard
- Comprehensive settings with config override warnings

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
        'searchBackend' => 'meilisearch',

        'backends' => [
            'meilisearch' => [
                'enabled' => true,
                'host' => App::env('MEILISEARCH_HOST'),
                'apiKey' => App::env('MEILISEARCH_API_KEY'),
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

**All features available everywhere!** 🚀

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

    // Device detection caching
    'cacheDeviceDetection' => true,
    'deviceDetectionCacheDuration' => 3600, // 1 hour
];
```

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
- 🚀 Faster response times (5-10ms vs 50-200ms)
- 💰 Reduced API costs (Algolia, Meilisearch, Typesense)
- ⚡ Lower backend load (MySQL, Redis queries)
- 💾 Smart storage (popular queries only option)

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

    const response = await fetch(`/actions/search-manager/api/suggest?q=${query}&index=entries`);
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
];
```

### AJAX / API Endpoints

Build instant search interfaces with AJAX endpoints:

**Autocomplete Endpoint:**
```javascript
// GET /actions/search-manager/api/suggest
// Simple format (default) - returns term strings
const response = await fetch('/actions/search-manager/api/suggest?q=test&index=all-sites&limit=10');
const suggestions = await response.json();
// Returns: ["test", "testing", "tested"]

// Detailed format - returns element objects with type info
const response = await fetch('/actions/search-manager/api/suggest?q=test&index=all-sites&limit=10&format=detailed');
const suggestions = await response.json();
// Returns: [
//   {"text": "Test Product", "type": "product", "id": 123},
//   {"text": "Testing Guide", "type": "article", "id": 456}
// ]

// Filter by element type
const response = await fetch('/actions/search-manager/api/suggest?q=test&index=all-sites&format=detailed&type=product');
// Returns only product suggestions
```

**Suggest API Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `index` | `all-sites` | Index handle to search |
| `limit` | `10` | Maximum suggestions |
| `format` | `simple` | Response format: `simple` (strings) or `detailed` (objects with type) |
| `type` | (none) | Filter by element type (only with `format=detailed`) |

**Suggest Response Formats:**

Simple format (`format=simple` or omitted):
```json
["test", "testing", "tested"]
```

Detailed format (`format=detailed`):
```json
[
  {"text": "Test Product", "type": "product", "id": 123},
  {"text": "Test Category", "type": "category", "id": 45},
  {"text": "Testing Guide", "type": "article", "id": 789}
]
```

**Element Type Detection:**
The `type` field is automatically derived from the index name:
- Index `products-ar` → type: `product`
- Index `categories-en` → type: `category`
- Index `blog-posts` → type: `article`
- Index `pages` → type: `page`
- Other indices → type: `entry` (default)

You can also explicitly set `elementType` in your transformer data.

**Search Endpoint:**
```javascript
// GET /actions/search-manager/api/search
const response = await fetch('/actions/search-manager/api/search?q=craft cms&index=all-sites&limit=20');
const results = await response.json();
// Returns: {hits: [{objectID: 123, score: 45.2}, ...], total: 15}
```

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
        // Fetch detailed suggestions with type info
        const suggestResponse = await fetch(
            `/actions/search-manager/api/suggest?q=${query}&index=all-sites&format=detailed`
        );
        const suggestions = await suggestResponse.json();

        // Display with icons
        suggestionsDiv.innerHTML = suggestions.map(s => `
            <div class="suggestion" data-id="${s.id}">
                <span class="icon">${typeIcons[s.type] || '📝'}</span>
                <span class="text">${s.text}</span>
                <span class="type">${s.type}</span>
            </div>
        `).join('');

        // Fetch search results
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
      "score": 45.23,
      "title": "Product Title",
      "excerpt": "Highlighted excerpt..."
    }
  ],
  "total": 150
}
```

**Default Limits:**
- Search API: 20 results (use `limit=0` for unlimited)
- Suggest API: 10 suggestions

**All search operators work:**
- Phrase: `?q="exact phrase"`
- Boolean: `?q=coffee OR tea`, `?q=coffee NOT decaf`
- Wildcards: `?q=coff*`
- Field-specific: `?q=title:muesli`
- Boosting: `?q=coffee^2 beans`

⚠️ **Note:** Default API limit (20) is hardcoded. TODO: Make configurable via settings.

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
- **English** (en) - 297 stop words
- **Arabic** (ar) - 122 stop words (Modern Standard Arabic)
- **German** (de) - 130+ stop words
- **French** (fr) - 140+ stop words
- **Spanish** (es) - 135+ stop words

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

### Algolia

```php
'backends' => [
    'algolia' => [
        'enabled' => true,
        'applicationId' => App::env('ALGOLIA_APPLICATION_ID'),
        'adminApiKey' => App::env('ALGOLIA_ADMIN_API_KEY'),
        'searchApiKey' => App::env('ALGOLIA_SEARCH_API_KEY'),
        'timeout' => 5,
        'connectTimeout' => 1,
    ],
],
```

### File (Built-in)

```php
'backends' => [
    'file' => [
        'enabled' => true,
        // Index data: storage/runtime/search-manager/indices/
        // Search cache: storage/runtime/search-manager/cache/search/
        // Device cache: storage/runtime/search-manager/cache/device/
    ],
],
```

### Meilisearch

```php
'backends' => [
    'meilisearch' => [
        'enabled' => true,
        'host' => 'http://localhost:7700',
        'apiKey' => App::env('MEILISEARCH_API_KEY'),
        'timeout' => 5,
    ],
],
```

### MySQL / PostgreSQL (Built-in)

Uses Craft's existing database connection - no additional configuration needed.

```php
'backends' => [
    'mysql' => [
        'enabled' => true,
        // Uses Craft's MySQL database
        // No additional config needed
    ],
    // Or for PostgreSQL installations:
    'pgsql' => [
        'enabled' => true,
        // Uses Craft's PostgreSQL database
        // No additional config needed
    ],
],
```

**Note:** Only the backend matching your Craft database will be available. MySQL backend requires Craft to use MySQL, PostgreSQL backend requires Craft to use PostgreSQL.

### Redis

**Option 1: Reuse Craft's Redis Cache (No Config Needed)**

If Craft is configured to use Redis cache in `config/app.php`, Search Manager can automatically reuse that connection:

```php
'backends' => [
    'redis' => [
        'enabled' => true,
        // Leave all fields empty in CP or omit config entirely
        // Plugin will automatically use Craft's Redis settings
    ],
],
```

**Option 2: Dedicated Redis Connection**

Configure a separate Redis connection for search:

```php
'backends' => [
    'redis' => [
        'enabled' => true,
        'host' => App::env('REDIS_HOST') ?: 'redis',
        'port' => App::env('REDIS_PORT') ?: 6379,
        'password' => App::env('REDIS_PASSWORD'),
        'database' => App::env('REDIS_DATABASE') ?: 0,
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

### Typesense

```php
'backends' => [
    'typesense' => [
        'enabled' => true,
        'host' => 'localhost',
        'port' => '8108',
        'protocol' => 'http',
        'apiKey' => App::env('TYPESENSE_API_KEY'),
        'connectionTimeout' => 5,
    ],
],
```

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
- **Clear All Caches** - Clear both device and search caches
  - Only shows when at least one cache is enabled
  - Shows cache file counts in real-time

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

- **View indices**: Can view search indices in CP
- **Manage indices**: Can create, edit, delete indices
  - **Create indices**: Can create new indices
  - **Edit indices**: Can edit existing indices
  - **Delete indices**: Can delete indices
  - **Rebuild indices**: Can rebuild indices
- **View analytics**: Can view analytics dashboard and search statistics
- **Export analytics**: Can export analytics data
- **View logs**: Can view plugin logs
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

        // Active search backend (mysql, pgsql, redis, file, algolia, meilisearch, typesense)
        'searchBackend' => 'mysql',

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
    ],
];
```

### Environment-Specific Configuration

```php
return [
    '*' => [
        'searchBackend' => 'mysql',
        'logLevel' => 'error',
        'enableAnalytics' => true,
    ],

    'dev' => [
        'logLevel' => 'debug',
        'indexPrefix' => 'dev_',
        'queueEnabled' => false,
        'enableCache' => false, // Disable cache for testing
        'cacheDuration' => 300, // 5 minutes (if enabled)
        'deviceDetectionCacheDuration' => 1800, // 30 minutes
        'analyticsRetention' => 30,
        'backends' => [
            'mysql' => ['enabled' => true],
            'file' => ['enabled' => true],
        ],
    ],

    'staging' => [
        'logLevel' => 'info',
        'indexPrefix' => 'staging_',
        'enableCache' => true,
        'cacheStorageMethod' => 'redis', // Use Redis for edge networks
        'cacheDuration' => 1800, // 30 minutes
        'deviceDetectionCacheDuration' => 3600, // 1 hour
        'analyticsRetention' => 90,
        'backends' => [
            'redis' => ['enabled' => true],
        ],
    ],

    'production' => [
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
        'backends' => [
            'algolia' => ['enabled' => true],
        ],
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
