# Multi-Language Support

Search Manager detects language automatically from your Craft site settings and applies per-language stop words and boolean operators. Stop word lists ship for 12 languages, boolean operators for 12.

## Stop Words

| Language | Code | Stop Words |
|----------|------|-----------|
| English | `en` | 297 |
| Portuguese | `pt` | 266 |
| Italian | `it` | 264 |
| Spanish | `es` | 260 |
| French | `fr` | 206 |
| Norwegian | `no` | 194 |
| Dutch | `nl` | 179 |
| Swedish | `sv` | 176 |
| German | `de` | 171 |
| Danish | `da` | 162 |
| Japanese | `ja` | 115 |
| Arabic | `ar` | 112 |

Japanese note: written Japanese doesn't use whitespace between words and the built-in tokeniser splits on whitespace/punctuation only. Stop-word filtering helps for space-separated query terms (`東京 から 大阪`) and mixed Latin+Japanese content, but not for uninterrupted Japanese sentences. Full morphological segmentation would require a dedicated CJK tokeniser (MeCab, Kuromoji, Sudachi) which Search Manager doesn't currently ship.

Other site languages still benefit from tokenisation, indexing, and boolean operator parsing — they just don't filter common words. See [Regional Variants](#regional-variants) below to add your own stop word file.

## Boolean Operators

| Language | Code | AND | OR | NOT |
|----------|------|-----|-----|-----|
| English | `en` | `AND` | `OR` | `NOT` |
| German | `de` | `UND` | `ODER` | `NICHT` |
| French | `fr` | `ET` | `OU` | `SAUF` |
| Spanish | `es` | `Y` | `O` | `NO` |
| Dutch | `nl` | `EN` | `OF` | `NIET` |
| Italian | `it` | `E` | `O` | `NON` |
| Portuguese | `pt` | `E` | `OU` | `NÃO` / `NAO` |
| Swedish | `sv` | `OCH` | `ELLER` | `INTE` |
| Danish | `da` | `OG` | `ELLER` | `IKKE` |
| Norwegian | `no` | `OG` | `ELLER` | `IKKE` / `IKKJE` |
| Japanese | `ja` | `かつ` | `または` / `もしくは` | `でない` / `ではない` |
| Arabic | `ar` | `و` | `أو` / `او` | `ليس` / `لا` |

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

### Disabling Stop Words

Stop words can be:
- **Disabled globally**: `'enableStopWords' => false`
- **Disabled per-index**: `'disableStopWords' => true` in the index config
- **Customized per-region**: Create custom stop word files as described above

## Text Normalization

Search Manager normalizes text during both indexing and querying, which is especially important for Arabic and other scripts with character variants.

| Normalization | Relevant Languages |
|---|---|
| Arabic tatweel removal | Arabic — `البحـر` and `البحر` match |
| Universal digit folding | Arabic (`٢` → `2`), Thai (`๒` → `2`), Devanagari, Bengali, etc. |
| Accent folding | French, German, Spanish — `jalapeño` → `jalapeno`, `naïve` → `naive` |
| Unicode compatibility (NFKC) | All languages — fullwidth and ligature variants |

This runs automatically — no configuration needed. See [Text Normalization](search-features.md#text-normalization) for the full details.
