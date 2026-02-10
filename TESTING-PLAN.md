# Search Manager - Comprehensive Testing Plan

**Version:** 5.4.0
**Last Updated:** 2025-01-19
**Status:** 🔴 Incomplete - Needs systematic testing

---

## 🎯 Testing Objectives

1. Verify all backends work correctly (MySQL, PostgreSQL, Redis, File, Algolia, Meilisearch, Typesense)
2. Ensure config sync works for all scenarios
3. Validate AutoTransformer handles all field types
4. Confirm wildcard and fuzzy search work across all backends
5. Test multi-site functionality
6. Verify analytics and caching work correctly
7. Test edge cases and error handling

---

## 📊 Current Testing Status

| Component | MySQL | PostgreSQL | Redis | File | Algolia | Meilisearch | Typesense |
|-----------|-------|------------|-------|------|---------|-------------|-----------|
| **Basic Indexing** | ✅ Tested | ⚠️ Untested | ✅ Tested | ✅ Tested | ⚠️ Untested | ✅ Tested | ✅ Tested |
| **Config Sync** | ✅ Tested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | N/A | N/A | N/A |
| **AutoTransformer** | ✅ Tested | ⚠️ Untested | ✅ Tested | ✅ Tested | ⚠️ Untested | ✅ Tested | ✅ Tested |
| **Wildcard Search** | ✅ Fixed & Tested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | Native | Native |
| **Fuzzy Search** | ✅ Fixed & Tested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | Native | Native | Native |
| **Multi-site** | ⚠️ Partial | ⚠️ Untested | ✅ Tested | ✅ Tested | ✅ Added | ✅ Tested | ✅ Tested |
| **Autocomplete** | ✅ Tested | ⚠️ Untested | ✅ Tested | ✅ Tested | ✅ Added | ✅ Added | ✅ Added |
| **Analytics** | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested |
| **Cache** | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested | ⚠️ Untested |

### CP Interface Testing Status

| Component | Status | Notes |
|-----------|--------|-------|
| **Accessibility (a11y)** | ✅ Added | Widget preview ARIA, form controls |
| **Indices UI** | ✅ Aligned | Edit/view templates with permissions |
| **Backends UI** | ✅ Aligned | Edit/view templates with permissions |
| **Widgets UI** | ✅ Aligned | Edit/view templates with permissions |

---

## 🧪 Test Suite Breakdown

### 1. Backend-Specific Tests

Each backend needs comprehensive testing to ensure feature parity.

#### 1.1 MySQL Backend
**Setup:**
```php
'searchBackend' => 'mysql',
```

**Tests:**
- [ ] Basic indexing (single entry)
- [ ] Batch indexing (100+ entries)
- [ ] Search with exact match
- [ ] Search with fuzzy matching
- [ ] Search with wildcard (prefix)
- [ ] Search with wildcards (suffix with *)
- [ ] Search with phrase ("exact phrase")
- [ ] Search with NOT operator
- [ ] Search with OR operator
- [ ] Search with field filters (title:test)
- [ ] Search with boosting (test^2)
- [ ] Multi-site indexing
- [ ] Index rebuild
- [ ] Index clear
- [ ] Config index sync
- [ ] Database index CRUD
- [ ] Analytics tracking
- [ ] Search cache (enable/disable)
- [ ] Autocomplete/suggestions
- [ ] Stop words filtering (EN, AR)
- [ ] Large dataset (1000+ documents)

#### 1.2 PostgreSQL Backend
**Setup:**
```php
'searchBackend' => 'pgsql',
```

**Tests:** Same as MySQL (uses MySqlStorage, so should work identically)
- [ ] All MySQL tests
- [ ] Verify SQL syntax compatibility
- [ ] Test with PostgreSQL-specific collations
- [ ] Test with large text fields (TEXT vs VARCHAR)

#### 1.3 Redis Backend
**Setup:**
```php
'searchBackend' => 'redis',
'backend' => [
    'redis' => [
        'host' => 'redis',
        'port' => 6379,
        'database' => 0,
    ],
],
```

**Tests:**
- [ ] Basic indexing
- [ ] Wildcard search (new getTermsByPrefix implementation)
- [ ] Fuzzy search (n-gram similarity)
- [ ] Redis SCAN performance with large datasets
- [ ] Key expiration (if implemented)
- [ ] Memory usage monitoring
- [ ] Redis connection failure handling
- [ ] Index rebuild
- [ ] Multi-site indexing

#### 1.4 File Backend
**Setup:**
```php
'searchBackend' => 'file',
```

**Tests:**
- [ ] Basic indexing
- [ ] Wildcard search (new filesystem-based prefix search)
- [ ] Fuzzy search (file-based n-gram matching)
- [ ] File permissions handling
- [ ] Large dataset (file system performance)
- [ ] Concurrent access (locking)
- [ ] Disk space monitoring
- [ ] Index rebuild
- [ ] File cleanup on deletion

#### 1.5 Algolia Backend
**Setup:**
```php
'searchBackend' => 'algolia',
'backend' => [
    'algolia' => [
        'appId' => 'YOUR_APP_ID',
        'apiKey' => 'YOUR_API_KEY',
    ],
],
```

**Tests:**
- [ ] Basic indexing (verify data sent to Algolia)
- [ ] Search (uses Algolia's native search)
- [ ] Autocomplete (Algolia native)
- [ ] Faceted search (if implemented)
- [ ] Wildcard search (Algolia handles differently)
- [ ] Fuzzy matching (Algolia native typo tolerance)
- [ ] API quota/rate limiting
- [ ] Connection failure handling
- [ ] Index rebuild
- [ ] Multi-site with Algolia indices
- [ ] Verify AutoTransformer data format works with Algolia

#### 1.6 Meilisearch Backend
**Setup (Synology Docker):**
```php
'searchBackend' => 'meilisearch',
'backend' => [
    'meilisearch' => [
        'host' => 'http://YOUR_SYNOLOGY_IP:7700',
        'apiKey' => 'YOUR_MASTER_KEY',
    ],
],
```

**Docker Setup on Synology:**
```yaml
# docker-compose.yml
services:
  meilisearch:
    image: getmeili/meilisearch:latest
    container_name: meilisearch
    ports:
      - "7700:7700"
    environment:
      - MEILI_MASTER_KEY=your_master_key_here
      - MEILI_ENV=development
    volumes:
      - /volume1/docker/meilisearch/data:/meili_data
    restart: unless-stopped
```

**Tests:**
- [x] Basic indexing
- [x] Search (Meilisearch native)
- [x] Wildcard search (verify behavior) - Native prefix matching
- [x] Fuzzy matching (Meilisearch native)
- [ ] Typo tolerance settings
- [x] Filterable attributes - Auto-configured for siteId, elementType
- [ ] Sortable attributes
- [x] Index rebuild
- [ ] Connection failure handling
- [ ] Remote connection latency
- [x] Index persistence after container restart
- [x] Multi-site support - Composite objectID (elementId_siteId)
- [x] Autocomplete - Native search-based suggestions
- [x] Ranking score support (showRankingScore)

#### 1.7 Typesense Backend
**Setup (Synology Docker):**
```php
'searchBackend' => 'typesense',
'backend' => [
    'typesense' => [
        'host' => 'YOUR_SYNOLOGY_IP',
        'port' => 8108,
        'protocol' => 'http',
        'apiKey' => 'YOUR_API_KEY',
    ],
],
```

**Docker Setup on Synology:**
```yaml
# docker-compose.yml
services:
  typesense:
    image: typesense/typesense:latest
    container_name: typesense
    ports:
      - "8108:8108"
    environment:
      - TYPESENSE_API_KEY=your_api_key_here
      - TYPESENSE_DATA_DIR=/data
    volumes:
      - /volume1/docker/typesense/data:/data
    restart: unless-stopped
```

**Tests:**
- [x] Basic indexing
- [x] Search (Typesense native) - query_by defaults to title,content,url
- [x] Wildcard search - Native prefix matching
- [x] Fuzzy matching (Typesense native)
- [ ] Faceting
- [ ] Geo search (if implemented)
- [x] Index rebuild
- [x] Schema management - Auto-collection creation with flexible schema
- [ ] Remote connection latency
- [x] Collection persistence after container restart
- [x] Multi-site support - Composite id (elementId_siteId), stores elementId field
- [x] Autocomplete - Native search-based suggestions
- [x] Clear index - Deletes and recreates collection (filter-based deletion didn't work)
- [x] listIndices() - List all collections

---

### 2. AutoTransformer Tests

Test with different field types and element types.

#### 2.1 Field Type Coverage
- [ ] **PlainText** - Basic text field
- [ ] **Number** - Numeric field
- [ ] **Email** - Email field
- [ ] **URL** - URL field
- [ ] **Color** - Color picker
- [ ] **Dropdown** - Select field
- [ ] **CKEditor** - Rich text (verify HTML stripping)
- [ ] **Redactor** - Rich text (verify HTML stripping)
- [ ] **Entries** - Relational (verify titles extracted)
- [ ] **Categories** - Relational (verify titles extracted)
- [ ] **Tags** - Relational (verify titles extracted)
- [ ] **Assets** - Relational (verify titles extracted)
- [ ] **Users** - Relational (verify usernames/emails)
- [ ] **Matrix** - Complex (verify nested field extraction)
- [ ] **Table** - Complex (verify all cell values)
- [ ] **Icon Manager** - Custom (verify labels extracted)
- [ ] **Lightswitch** - Boolean
- [ ] **Date/Time** - Date fields
- [ ] **Link Field** (Verbb) - If installed
- [ ] **Super Table** - If installed

#### 2.2 Element Type Coverage
- [ ] **Entries** - All entry types
- [ ] **Categories** - Category elements
- [ ] **Assets** - Files/images
- [ ] **Users** - User accounts
- [ ] **Global Sets** - If indexed
- [ ] **Commerce Products** - If Commerce installed

---

### 3. Config Index Sync Tests

Verify metadata syncs correctly from config to database.

**Setup:**
```php
'indices' => [
    'test-sync' => [
        'name' => 'Test Sync Index',
        'elementType' => Entry::class,
        'transformer' => AutoTransformer::class,
        'language' => 'en',
        'enabled' => true,
    ],
],
```

**Test Cases:**
- [ ] Create config index - verify metadata record created on first rebuild
- [ ] Change name in config - verify database updates on rebuild
- [ ] Change transformer in config - verify database updates on rebuild
- [ ] Change language in config - verify database updates on rebuild
- [ ] Disable in config - verify database updates on rebuild
- [ ] Remove from config - verify index disappears from UI
- [ ] Invalid transformer class - verify validation prevents sync
- [ ] Handle collision (same handle in config + database) - verify precedence
- [ ] Verify stats (lastIndexed, documentCount) persist correctly
- [ ] Verify config cache clearing works

---

### 4. Search Feature Tests

#### 4.1 Wildcard Search
**Test across all backends:**
```
Query: "freez*"
Expected: Matches "freeze", "freezable", "freezing"
```

- [ ] MySQL - Single wildcard
- [ ] MySQL - Multiple wildcards (test* craft*)
- [ ] PostgreSQL - Same as MySQL
- [ ] Redis - SCAN-based prefix matching
- [ ] File - Filesystem prefix matching
- [ ] Algolia - Native wildcard behavior
- [ ] Meilisearch - Native prefix behavior
- [ ] Typesense - Native prefix behavior

#### 4.2 Fuzzy Matching
**Test across all backends:**
```
Query: "suga" (no wildcard)
Expected: Matches "sugar" via n-gram similarity
```

- [ ] MySQL - N-gram similarity query
- [ ] PostgreSQL - N-gram similarity query
- [ ] Redis - N-gram in-memory matching
- [ ] File - N-gram file-based matching
- [ ] Algolia - Native typo tolerance (1 typo, 2 typos)
- [ ] Meilisearch - Native typo tolerance
- [ ] Typesense - Native typo tolerance

**Edge Cases:**
- [ ] Very short terms (2-3 chars) - adaptive threshold
- [ ] Long terms (10+ chars)
- [ ] Special characters (café, naïve)
- [ ] Numbers vs words
- [ ] Similarity threshold tuning (0.1, 0.25, 0.5)

#### 4.3 Advanced Operators
- [ ] Phrase search: `"exact phrase"`
- [ ] NOT operator: `test NOT spam`
- [ ] OR operator: `test OR spam`
- [ ] Field-specific: `title:blog`
- [ ] Boosting: `craft^2 cms`
- [ ] Combined: `"craft cms" NOT wordpress title:blog*`

#### 4.4 Multi-language
- [ ] English (EN) - verify stop words work
- [ ] Arabic (AR) - verify RTL, stop words
- [ ] French (FR) - verify accents (café)
- [ ] German (DE) - verify umlauts (über)
- [ ] Spanish (ES) - verify ñ handling
- [ ] Auto-detect from site language
- [ ] Mixed language content

---

### 5. Multi-Site Tests

**Setup:**
```php
'indices' => [
    'all-sites' => [
        'siteId' => null, // All sites
    ],
    'en-only' => [
        'siteId' => 1, // English only
    ],
    'ar-only' => [
        'siteId' => 2, // Arabic only
    ],
],
```

**Test Cases:**
- [ ] Index all sites - verify all sites indexed separately
- [ ] Index specific site - verify only that site indexed
- [ ] Search with siteId filter - verify results from correct site
- [ ] Element enabled in one site, disabled in another
- [ ] Same element, different content per site
- [ ] Language auto-detection per site

---

### 6. Performance Tests

#### 6.1 Indexing Performance
- [ ] Small dataset (10 entries) - baseline
- [ ] Medium dataset (100 entries)
- [ ] Large dataset (1,000 entries)
- [ ] Very large dataset (10,000+ entries)
- [ ] Batch size optimization (50, 100, 500)
- [ ] Memory usage during indexing
- [ ] Time to index per backend

#### 6.2 Search Performance
- [ ] Simple query (single term)
- [ ] Complex query (5+ terms, operators)
- [ ] Wildcard query (prefix expansion)
- [ ] Fuzzy query (n-gram similarity)
- [ ] Cached vs uncached performance
- [ ] Concurrent searches (load testing)

---

### 7. Analytics Tests

**Features to test:**
- [ ] Search query tracking
- [ ] Results count tracking
- [ ] Zero-result queries tracking
- [ ] Popular queries detection
- [ ] Click-through tracking (if implemented)
- [ ] Analytics retention (cleanup after X days)
- [ ] IP hashing (privacy)
- [ ] Device detection caching
- [ ] Analytics export/reporting

---

### 8. Cache Tests

#### 8.1 Redis Cache
**Setup:**
```php
'cacheStorageMethod' => 'redis',
'enableCache' => true,
'cacheDuration' => 300,
```

**Tests:**
- [ ] Cache HIT - same query returns cached result
- [ ] Cache MISS - new query fetches from backend
- [ ] Cache expiration - verify TTL works
- [ ] Cache invalidation on rebuild
- [ ] Cache clearing (per index, all indices)
- [ ] Popular queries only mode
- [ ] Cache warming (if implemented)

#### 8.2 File Cache
**Setup:**
```php
'cacheStorageMethod' => 'file',
```

**Tests:**
- [ ] Same as Redis cache tests
- [ ] File permissions handling
- [ ] Disk space monitoring
- [ ] Cache file cleanup

#### 8.3 Database Cache
**Setup:**
```php
'cacheStorageMethod' => 'database',
```

**Tests:**
- [ ] Same as above
- [ ] Database table performance
- [ ] Cleanup old entries

---

### 9. Edge Cases & Error Handling

#### 9.1 Data Integrity
- [ ] Empty index - search returns 0 results gracefully
- [ ] Deleted element - verify removed from index
- [ ] Disabled element - verify not searchable
- [ ] Draft entry - verify not indexed
- [ ] Revision - verify not indexed
- [ ] Expired entry - verify not indexed
- [ ] Future entry (pending) - verify not indexed

#### 9.1.1 URL-less Entry Handling (NEW)
- [x] **skipEntriesWithoutUrl** (Index setting) - Skip indexing entries without URLs
  - [ ] Verify entries without URL are skipped during indexing
  - [ ] Verify entries with URL are indexed normally
  - [ ] Verify index count reflects only URL entries when enabled
  - [ ] Verify setting persists in database and config
- [x] **hideResultsWithoutUrl** (Widget behavior) - Hide URL-less results in frontend
  - [ ] Verify results without URL are filtered out in search response
  - [ ] Verify results with URL display normally
  - [ ] Verify setting works via widget config and Twig override
- [x] **modalMaxHeight** (Widget style) - Control widget modal max height
  - [ ] Verify modal respects max height setting (vh units)
  - [ ] Verify setting works via widget config and Twig override

#### 9.2 Invalid Configuration
- [ ] Invalid transformer class - verify validation catches it
- [ ] Missing config file - verify graceful fallback
- [ ] Invalid element type - verify validation
- [ ] Invalid backend settings - verify connection fails gracefully
- [ ] Backend unavailable - verify fallback/error handling

#### 9.3 Duplicate Handling
- [ ] Duplicate terms in same document - verify handled correctly
- [ ] Special characters causing duplicates (café vs cafe)
- [ ] Case sensitivity (Test vs test)
- [ ] Accents/diacritics (resume vs résumé)

#### 9.4 Large Data
- [ ] Very long titles (500+ chars)
- [ ] Very long content (10,000+ words)
- [ ] Many relational fields (50+ related entries)
- [ ] Deep Matrix nesting
- [ ] Table with 100+ rows

---

### 10. AutoTransformer Field Tests

Create test entries with ALL field types and verify extraction.

**Test Entry Structure:**
```
Entry: "AutoTransformer Test"
Fields:
- plainText: "This is plain text"
- richText: "<p>This is <strong>rich</strong> text</p>"
- relatedEntries: [Entry1, Entry2, Entry3]
- categories: [Cat1, Cat2]
- tags: [Tag1, Tag2]
- matrix: [Block1{text: "foo"}, Block2{text: "bar"}]
- table: [[col1: "a", col2: "b"], [col1: "c", col2: "d"]]
- icons: [Icon1, Icon2]
```

**Verify Indexed Data:**
- [ ] Plain text indexed correctly
- [ ] Rich text HTML stripped
- [ ] All related entry titles indexed
- [ ] All category titles indexed
- [ ] All tag titles indexed
- [ ] All matrix block content indexed
- [ ] All table cells indexed
- [ ] All icon labels indexed
- [ ] Content field contains all searchable content
- [ ] Excerpt generated correctly

---

### 11. Accessibility (a11y) Tests

Test CP interface accessibility compliance.

#### 11.1 Widget Preview
- [ ] Light/dark mode toggle has proper ARIA attributes
- [ ] Toggle buttons have `aria-pressed` state
- [ ] Preview containers have appropriate contrast

#### 11.2 Form Controls
- [ ] All form inputs have associated labels
- [ ] Lightswitches are keyboard accessible
- [ ] Error messages are announced to screen readers
- [ ] Focus states are visible

#### 11.3 Tables & Lists
- [ ] Index/backend/widget tables have proper headers
- [ ] Action menus are keyboard navigable
- [ ] Checkboxes have accessible labels
- [ ] Status indicators have text alternatives

#### 11.4 Navigation
- [ ] Breadcrumbs are navigable
- [ ] Tabs have proper ARIA roles
- [ ] Skip links work correctly
- [ ] Focus order is logical

#### 11.5 Modals & Dialogs
- [ ] Confirm dialogs trap focus
- [ ] Escape key closes modals
- [ ] Modal headings are announced

---

### 12. Integration Tests

#### 12.1 Craft CMS Integration
- [ ] Element save triggers indexing
- [ ] Element delete triggers removal
- [ ] Element status change (enabled/disabled)
- [ ] Element published/unpublished
- [ ] Site change affects indexing
- [ ] Section change (criteria filtering)

#### 11.2 Queue Jobs
- [ ] RebuildIndexJob - single index
- [ ] RebuildIndexJob - all indices
- [ ] Queue failure handling
- [ ] Queue progress tracking
- [ ] Concurrent rebuild jobs

#### 11.3 CP Interface
- [ ] Indices list page
- [ ] Create index form (database indices)
- [ ] Edit index form
- [ ] Delete index
- [ ] Rebuild button
- [ ] Clear index button
- [ ] Test search page
- [ ] Analytics dashboard
- [ ] Settings pages (all tabs)

---

### 12. API/Template Tests

#### 12.1 Twig Variable
```twig
{% set results = craft.searchManager.search('products-en', 'test', {
    fuzzy: true,
    wildcard: true,
    limit: 10,
}) %}
```

**Tests:**
- [ ] Basic search returns results
- [ ] Fuzzy option works
- [ ] Wildcard option works
- [ ] Limit option works
- [ ] Offset/pagination works
- [ ] Empty query handling
- [ ] Invalid index handle error

#### 12.2 PHP API
```php
SearchManager::$plugin->backend->search('index-handle', 'query', [
    'fuzzy' => true,
    'limit' => 20,
]);
```

**Tests:**
- [ ] Same as Twig tests
- [ ] Exception handling
- [ ] Return value structure

---

### 13. Regression Tests

Track previously fixed bugs to ensure they don't reoccur.

- [ ] **Bug:** Config metadata not syncing on rebuild
  - **Test:** Change transformer in config, rebuild, verify DB updated
  - **Status:** ✅ Fixed 2025-12-17

- [ ] **Bug:** Ambiguous SQL column in fuzzy matching
  - **Test:** Search with fuzzy fallback, verify no SQL error
  - **Status:** ✅ Fixed 2025-12-17

- [ ] **Bug:** Wildcard search using broken fuzzy matcher
  - **Test:** Search `test*`, verify prefix expansion works
  - **Status:** ✅ Fixed 2025-12-17

- [ ] **Bug:** N+1 query in loadFromConfig()
  - **Test:** Load all indices, verify only 1 metadata query
  - **Status:** ✅ Fixed 2025-12-17

---

## 🏗️ Test Infrastructure Needed

### Automated Test Suite
**Recommended: PHPUnit**

```php
// tests/unit/SearchIndex/ConfigSyncTest.php
class ConfigSyncTest extends TestCase
{
    public function testConfigMetadataSync()
    {
        // Test config sync works correctly
    }
}

// tests/unit/Transformer/AutoTransformerTest.php
class AutoTransformerTest extends TestCase
{
    public function testEntriesFieldExtraction()
    {
        // Test relational field extraction
    }
}

// tests/integration/Backend/MySqlBackendTest.php
class MySqlBackendTest extends TestCase
{
    public function testWildcardSearch()
    {
        // Test wildcard search works
    }
}
```

### Test Data Generator
**Create fixture data:**
```php
// tests/_support/fixtures/TestDataFixture.php
class TestDataFixture
{
    public static function createTestEntries(int $count): array
    {
        // Generate test entries with all field types
    }
}
```

### Backend Test Matrix Script
**Automated backend switching:**
```php
// tests/scripts/test-all-backends.php
$backends = ['mysql', 'pgsql', 'redis', 'file', 'algolia', 'meilisearch', 'typesense'];

foreach ($backends as $backend) {
    echo "Testing $backend...\n";
    // Run test suite for backend
}
```

---

## 📝 Testing Checklist Template

For each backend, use this checklist:

```markdown
### [BACKEND_NAME] Testing Session

**Date:** YYYY-MM-DD
**Tester:** Name
**Environment:** dev/staging/prod
**Data Set:** small/medium/large

#### Setup
- [ ] Backend configured correctly
- [ ] Connection verified
- [ ] Test data created (X entries)
- [ ] All field types represented

#### Indexing
- [ ] Index created successfully
- [ ] All elements indexed (count matches)
- [ ] AutoTransformer extracted all fields
- [ ] Rebuild works correctly
- [ ] Clear works correctly

#### Search - Basic
- [ ] Exact match works
- [ ] Case insensitive works
- [ ] Multi-term AND works
- [ ] Multi-term OR works
- [ ] NOT operator works

#### Search - Advanced
- [ ] Wildcard prefix (test*)
- [ ] Fuzzy matching (typos)
- [ ] Phrase search ("exact phrase")
- [ ] Field filters (title:test)
- [ ] Boosting (test^2)

#### Issues Found
1. Issue description
2. Another issue

#### Performance
- Index time: Xms for Y docs
- Search time: Xms avg
- Memory usage: X MB

#### Overall Status
✅ Pass / ⚠️ Pass with issues / ❌ Fail
```

---

## 🎯 Priority Testing Order

### Phase 1: Core Functionality (This Week)
1. ✅ MySQL - Basic + wildcard + fuzzy (DONE)
2. [ ] PostgreSQL - Verify MySQL fixes work
3. [ ] Redis - Wildcard + fuzzy with SCAN
4. [ ] File - Wildcard + fuzzy with filesystem

### Phase 2: External Services (Next Week)
5. [ ] Algolia - Full integration
6. [ ] Meilisearch - Full integration
7. [ ] Typesense - Full integration

### Phase 3: Advanced Features
8. [ ] Multi-site comprehensive testing
9. [ ] Analytics full testing
10. [ ] Cache strategies testing
11. [ ] Performance benchmarking

### Phase 4: Automation
12. [ ] Write PHPUnit test suite
13. [ ] Create CI/CD pipeline
14. [ ] Add automated backend matrix tests

---

## 🐛 Known Issues to Verify

1. **Duplicate key errors during indexing** - Element 61417 fails with duplicate 'béchamel'
   - Needs investigation: Why are duplicates in same INSERT?
   - Check collation settings
   - Check if AutoTransformer is creating duplicates

2. **Test page cache confusion** - Shows "Cached" when cache is disabled
   - Fixed cache detection, needs verification

3. **Config cache not clearing in production** - May need explicit cache busting
   - Test in staging/production environments

### Fixed Issues (2025-01-20)

4. ✅ **Typesense clearIndex() not working** - Filter-based deletion `id:>0` failed with string IDs
   - **Fix:** Delete entire collection instead, auto-recreate on next index
   - **Status:** Fixed

5. ✅ **External backends missing multi-site support** - Same element across sites overwrote each other
   - **Fix:** Added composite objectID (elementId_siteId) for Meilisearch, Algolia, Typesense
   - **Status:** Fixed

6. ✅ **External backends missing autocomplete** - AutocompleteService only worked with internal backends
   - **Fix:** Added `autocomplete()` method to all external backends, fallback in AutocompleteService
   - **Status:** Fixed

7. ✅ **Meilisearch/Algolia siteId filtering not working** - Filterable attributes not configured
   - **Fix:** Added `ensureFilterableAttributes()` to auto-configure siteId, elementType
   - **Status:** Fixed

8. ✅ **Typesense search results not unwrapped** - Results wrapped in `{document: {...}}`
   - **Fix:** Unwrap documents in search method, normalize scores
   - **Status:** Fixed

9. ✅ **SettingsController element lookup failing for Typesense** - Used `objectID` instead of `elementId`
   - **Fix:** Use `hit['elementId'] ?? hit['id']` for lookups
   - **Status:** Fixed

---

## 📊 Test Results Log

Keep a log of test sessions:

| Date | Backend | Tester | Status | Issues | Notes |
|------|---------|--------|--------|--------|-------|
| 2025-12-17 | MySQL | Dev | ✅ Pass | Duplicate keys on some docs | Wildcard + fuzzy working |
| 2025-01-20 | Meilisearch | Dev | ✅ Pass | None | Multi-site, autocomplete, filterable attrs working |
| 2025-01-20 | Typesense | Dev | ✅ Pass | clearIndex fix needed | Auto-collection, multi-site, autocomplete working |
| 2025-01-20 | Redis | Dev | ✅ Pass | None | Multi-site indexing verified |
| 2025-01-20 | File | Dev | ✅ Pass | None | Multi-site indexing verified |
| | PostgreSQL | | ⚠️ Pending | | |
| | Algolia | | ⚠️ Partial | | Code added, needs live testing |

---

## 🔄 Continuous Testing

### After Each Change
- [ ] Run PHPStan
- [ ] Run ECS
- [ ] Test affected backend
- [ ] Run smoke tests (basic search)

### Before Each Release
- [ ] All backends smoke tested
- [ ] Multi-site tested
- [ ] AutoTransformer tested with sample data
- [ ] Performance benchmarks collected
- [ ] Documentation updated

---

## 📚 Documentation Needed

- [ ] Backend setup guides (each service)
- [ ] AutoTransformer field support matrix
- [ ] Search syntax guide for end users
- [ ] Performance tuning guide
- [ ] Troubleshooting guide
- [ ] Migration guide (between backends)

---

## 🚀 Next Steps

1. **Immediate:** Test PostgreSQL, Redis, File backends with current fixes
2. **Short-term:** Create PHPUnit test suite skeleton
3. **Medium-term:** Test external services (Algolia, Meilisearch, Typesense)
4. **Long-term:** Automated CI/CD with all backends

---

**This testing plan should be updated as we discover new issues and add new features.**
