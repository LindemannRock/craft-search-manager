# Autocomplete

Suggest what visitors are typing before they finish typing it. Search Manager returns matching indexed terms as they type, backed by its own shorter-TTL cache layer, and it's accessible from Twig templates or the REST API.

## What you'll use it for

- Add search-as-you-type suggestions backed by the same index as full search
- Complete multi-word queries word by word, only suggesting combinations that actually return results
- Reuse the same typo tolerance and text normalization as full search, so suggestions and results never disagree
- Power an AJAX-driven autocomplete box via the REST API, or render suggestions directly in Twig

## How it works

As users type, autocomplete returns matching terms from the index. Built-in backends (MySQL, PostgreSQL, Redis, File) return individual term suggestions. External backends (Algolia, Meilisearch, Typesense) return full entry titles.

Multi-word input is completed word by word: the last word is completed with matching indexed terms, and only completions that actually co-occur with the preceding words in at least one document are suggested. Typing `testing tool` suggests `testing tools` (because documents contain both words) — never a combination that would return zero search results.

Queries are normalized the same way as search — accents are folded, Arabic tatweel is removed, and Unicode digits are converted to ASCII. This means typing `Maámoul` will suggest terms stored as `maamoul`, and `البحـر` (with tatweel) matches `البحر`. See [Text normalization](search-features.md#text-normalization) for the full list.

Typo tolerance follows the engine-wide `enableFuzzy` setting, so autocomplete and search always share the same fuzzy behavior — see [Fuzzy matching](search-features.md#fuzzy-matching).

## Configuration

Configure autocomplete in the CP under **Search Manager > Settings > Autocomplete**, or set it in config:

```php
// config/search-manager.php
'enableAutocomplete' => true,
'autocompleteMinLength' => 2,   // Min characters before suggesting
'autocompleteLimit' => 10,      // Max suggestions returned
'enableFuzzy' => true,          // Engine-wide typo tolerance (shared with search)

// Separate cache for autocomplete
'enableAutocompleteCache' => true,
'autocompleteCacheDuration' => 300,  // 5 minutes (shorter than search cache)
```

## Twig usage

```twig
{% set suggestions = craft.searchManager.suggest('cra', 'entries-en') %}
{# Returns: ['craft', 'craftcms', 'create'] #}

{% for suggestion in suggestions %}
    <a href="?q={{ suggestion }}">{{ suggestion }}</a>
{% endfor %}
```

### With options

```twig
{% set suggestions = craft.searchManager.suggest('te', 'entries-en', {
    limit: 5,
    minLength: 2,
    fuzzy: true,
    language: 'en',
}) %}
```

## REST API

For AJAX-powered autocomplete, use the API endpoint:

```text
GET /actions/search-manager/api/autocomplete
```

See [API Endpoints](../template-guides/api-endpoints.md) for full documentation.

## Caching

Autocomplete results are cached separately from search results, with a shorter default TTL (5 minutes vs 1 hour). The cache is keyed per query prefix, index, and language.

When cache warming is enabled, popular autocomplete prefixes (2–5 characters) are pre-cached after index rebuilds.

See [Caching](caching.md) for details.

## Template guide

For complete implementation examples including AJAX integration, see [Autocomplete & Suggestions](../template-guides/autocomplete-suggestions.md).
