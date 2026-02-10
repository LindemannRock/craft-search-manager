# Multi-Language Support

Search Manager supports five languages with localized stop words, boolean operators, and automatic language detection from your Craft site settings.

## Supported Languages

| Language | Code | Stop Words | Boolean Operators |
|----------|------|-----------|-------------------|
| English | `en` | 297 | AND, OR, NOT |
| Arabic | `ar` | 122 | و, أو/او, ليس/لا |
| German | `de` | 130+ | UND, ODER, NICHT |
| French | `fr` | 140+ | ET, OU, SAUF |
| Spanish | `es` | 135+ | Y, O, NO |

## Auto-Detection

Language is automatically detected from each site's locale setting. If your Craft site is configured for German (`de`), Search Manager uses German stop words and recognizes German boolean operators.

You don't need to configure anything — it just works. Set `defaultLanguage` only if you want to override auto-detection:

```php
'defaultLanguage' => 'de',  // Force German for all sites
```

## Localized Boolean Operators

Users can search using operators in their own language:

```twig
{# German site #}
{% set results = craft.searchManager.search('products', 'kaffee ODER tee') %}
{% set results = craft.searchManager.search('products', 'kaffee NICHT entkoffeiniert') %}

{# French site #}
{% set results = craft.searchManager.search('products', 'café OU thé') %}
{% set results = craft.searchManager.search('products', 'café SAUF décaféiné') %}

{# Arabic site #}
{% set results = craft.searchManager.search('products', 'قهوة او شاي') %}
```

English operators always work as a fallback regardless of the site language:

```twig
{# On a German site, English still works #}
{% set results = craft.searchManager.search('products', 'kaffee OR tee') %}
```

All operators are case-insensitive.

## Multi-Language Indices

### Separate Indices per Language

The cleanest approach — one index per language:

```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'siteId' => 1,
        // Language auto-detected from site 1's locale
    ],
    'entries-de' => [
        'name' => 'Entries (German)',
        'siteId' => 2,
    ],
    'entries-ar' => [
        'name' => 'Entries (Arabic)',
        'siteId' => 3,
    ],
],
```

### Combined Multi-Site Index

One index with content from multiple sites:

```php
'all-entries' => [
    'siteId' => null,  // All sites
    // Language is stored per document, auto-detected from each site
],
```

When searching a multi-site index, results can be filtered by language:

```twig
{% set results = craft.searchManager.search('all-entries', 'test', {
    language: 'en',  // Only English results
}) %}
```

Without a language filter, the current site's language is used automatically.

## Regional Variants

For regional language variants (e.g., Saudi Arabic vs Egyptian Arabic), you can create custom stop word files:

```bash
mkdir -p config/search-manager/stopwords
cp vendor/lindemannrock/craft-search-manager/src/search/stopwords/ar.php config/search-manager/stopwords/ar-sa.php
```

Edit `ar-sa.php` to add or remove stop words for that region.

**Fallback chain:**

```text
ar-sa → config/ar-sa.php → plugin/ar-sa.php → config/ar.php → plugin/ar.php
```

## API Language Override

Mobile apps can specify a language explicitly for localized operator support:

```text
GET /actions/search-manager/api/search?q=kaffee+ODER+tee&language=de
```

This is useful when the API request doesn't come from a Craft site context and language can't be auto-detected.

## Stop Words

Stop words are common words (the, a, is, etc.) that are filtered from queries to improve relevance. Each language has its own stop word list.

Stop words can be:
- **Disabled globally**: `'enableStopWords' => false`
- **Disabled per-index**: `'disableStopWords' => true` in the index config
- **Customized per-region**: Create custom stop word files as described above
