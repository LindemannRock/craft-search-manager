# Stop Words

Stop words are common words filtered out during search indexing to improve relevance and reduce index size.

## Available Languages

Search Manager includes stop words for:
- **en.php** - English (Universal)
- **ar.php** - Arabic (Modern Standard Arabic - MSA)
- **de.php** - German (Germany, Austria, Switzerland)
- **fr.php** - French (France, Canada, Belgium, Switzerland)
- **es.php** - Spanish (Spain, Mexico, Argentina, Colombia, etc.)

## Customization

### Option 1: Use Plugin Defaults (No Action Needed)

The plugin automatically uses the appropriate language file based on your site's language setting.

**Example:**
- Site language: `en-US` → Uses `en.php`
- Site language: `ar-SA` → Uses `ar.php` (generic Arabic)
- Site language: `de-DE` → Uses `de.php`

### Option 2: Regional Customization

For region-specific stop words (e.g., Saudi Arabic vs Egyptian Arabic):

1. **Create directory:**
   ```bash
   mkdir -p config/search-manager/stopwords
   ```

2. **Copy plugin default:**
   ```bash
   cp vendor/lindemannrock/craft-search-manager/src/search/stopwords/ar.php \
      config/search-manager/stopwords/ar-sa.php
   ```

3. **Edit for your region:**
   ```php
   <?php
   // config/search-manager/stopwords/ar-sa.php

   // Start with generic Arabic
   $stopWords = require __DIR__ . '/../../../vendor/lindemannrock/craft-search-manager/src/search/stopwords/ar.php';

   // Add Saudi-specific colloquialisms
   $stopWords = array_merge($stopWords, [
       'يعني',      // KSA colloquial
       'والله',     // Common expression
       'يا',        // Vocative particle
   ]);

   return $stopWords;
   ```

4. **Configure index:**
   ```php
   // config/search-manager.php
   'indices' => [
       'products-ksa' => [
           'language' => 'ar-sa',  // Uses ar-sa.php if exists, falls back to ar.php
       ],
   ];
   ```

## Fallback Chain

When loading stop words for `ar-sa`:

1. ✅ `config/search-manager/stopwords/ar-sa.php` (user's custom)
2. ✅ `src/search/stopwords/ar-sa.php` (plugin regional - if exists)
3. ✅ `config/search-manager/stopwords/ar.php` (user's generic)
4. ✅ `src/search/stopwords/ar.php` (plugin generic)
5. ❌ Empty array (no filtering)

## Disable Stop Words

To disable stop words for specific content:

```php
// config/search-manager.php
'indices' => [
    'technical-docs' => [
        'disableStopWords' => true,  // Keep all words (useful for technical content)
    ],
];
```

## Language Detection

Language is automatically detected from:
1. Index configuration: `'language' => 'ar-sa'`
2. Element's site language: `$element->site->language` (e.g., 'ar-SA' → 'ar-sa')
3. Fallback: `'en'`

## Examples

### Universal Arabic Site
```php
'products' => [
    'siteId' => 2,
    'language' => 'ar',  // Uses ar.php - works for all regions
],
```

### Multi-Region Arabic Sites
```php
'products-ksa' => [
    'siteId' => 2,
    'language' => 'ar-sa',  // KSA-specific
],
'products-egypt' => [
    'siteId' => 3,
    'language' => 'ar-eg',  // Egypt-specific (if you create ar-eg.php)
],
```

### Auto-Detect from Site
```php
'all-entries' => [
    'siteId' => null,       // All sites
    'language' => null,     // Auto-detect from element's site language
],
```

## Contributing Stop Words

To contribute improved stop word lists:
1. Fork the repository
2. Edit files in `src/search/stopwords/`
3. Submit pull request with regional dialect improvements

---

**Note:** Stop words are loaded once per search operation and cached in memory for performance.
