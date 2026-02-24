# Autocomplete

Search Manager provides search-as-you-type suggestions based on indexed terms. Autocomplete has its own cache layer with a shorter TTL, and can be accessed via Twig templates or the REST API.

## How It Works

As users type, autocomplete returns matching terms from the index. Built-in backends (MySQL, PostgreSQL, Redis, File) return individual term suggestions. External backends (Algolia, Meilisearch, Typesense) return full entry titles.

Queries are normalized the same way as search — accents are folded, Arabic tatweel is removed, and Unicode digits are converted to ASCII. This means typing `Maámoul` will suggest terms stored as `maamoul`, and `البحـر` (with tatweel) matches `البحر`. See [Text Normalization](search-features.md#text-normalization) for the full list.

## Configuration

```php
// config/search-manager.php
'enableAutocomplete' => true,
'autocompleteMinLength' => 2,   // Min characters before suggesting
'autocompleteLimit' => 10,      // Max suggestions returned
'autocompleteFuzzy' => false,   // Enable typo tolerance (slower)

// Separate cache for autocomplete
'enableAutocompleteCache' => true,
'autocompleteCacheDuration' => 300,  // 5 minutes (shorter than search cache)
```

## Twig Usage

```twig
{% set suggestions = craft.searchManager.suggest('cra', 'entries-en') %}
{# Returns: ['craft', 'craftcms', 'create'] #}

{% for suggestion in suggestions %}
    <a href="?q={{ suggestion }}">{{ suggestion }}</a>
{% endfor %}
```

### With Options

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

## Template Guide

For complete implementation examples including AJAX integration, see [Autocomplete & Suggestions](../template-guides/autocomplete-suggestions.md).
