# Search Features

Search Manager's built-in backends (MySQL, PostgreSQL, Redis, File) provide a powerful search engine with BM25 ranking, boolean operators, fuzzy matching, and more. These features work identically across all four built-in backends.

## BM25 Ranking

All search results are ranked using the BM25 algorithm — the same algorithm used by Elasticsearch and other major search engines. BM25 considers three factors:

1. **Term frequency** — how often the search term appears in a document (more = more relevant, with diminishing returns)
2. **Inverse document frequency** — how rare the term is across all documents (rarer terms are more important)
3. **Document length** — shorter documents with the term rank higher than longer documents

The defaults work well for most sites, but you can tune the parameters:

```php
// config/search-manager.php
'bm25K1' => 1.5,   // Term frequency saturation (higher = more weight on repetition)
'bm25B' => 0.75,   // Length normalization (0 = ignore length, 1 = full penalty)
```

## Search Operators

### Phrase Search

Wrap terms in double quotes to find exact sequences:

```twig
{% set results = craft.searchManager.search('entries', '"craft cms"') %}
```

Only matches documents where "craft" is immediately followed by "cms". Phrase matches receive a 4x boost by default.

### NOT Operator

Exclude documents containing specific terms:

```twig
{% set results = craft.searchManager.search('entries', 'craft NOT plugin') %}
{% set results = craft.searchManager.search('entries', '"craft cms" NOT plugin NOT theme') %}
```

### Field-Specific Search

Search within specific fields of your indexed documents:

```twig
{% set results = craft.searchManager.search('entries', 'title:blog') %}
{% set results = craft.searchManager.search('entries', 'content:tutorial') %}
{% set results = craft.searchManager.search('entries', 'title:craft content:plugin') %}
```

Field names correspond to the keys in your transformer output.

### Wildcards

Use `*` for prefix matching:

```twig
{% set results = craft.searchManager.search('entries', 'test*') %}
```

Matches: test, tests, testing, tested, etc.

### Per-Term Boosting

Assign custom weights to individual terms:

```twig
{% set results = craft.searchManager.search('entries', 'craft^2 cms') %}
{% set results = craft.searchManager.search('entries', 'craft^3 plugin^2 tutorial^1.5') %}
```

Higher numbers = more weight for that term.

### Boolean Operators

```twig
{# OR: find documents with either term #}
{% set results = craft.searchManager.search('entries', 'craft OR cms') %}

{# AND: find documents with both terms (this is the default) #}
{% set results = craft.searchManager.search('entries', 'craft AND cms') %}
{% set results = craft.searchManager.search('entries', 'craft cms') %}
```

### Combined Operators

All operators can be combined in a single query:

```twig
{% set results = craft.searchManager.search('entries',
    'craft* OR plugin title:tutorial NOT beginner "getting started"^2'
) %}
```

This query:
- Matches words starting with "craft" OR containing "plugin"
- Requires "tutorial" in the title field
- Excludes documents containing "beginner"
- Gives a 2x boost to the exact phrase "getting started"

## Ranking Priority

When multiple boost factors apply, they stack. From highest to lowest impact:

| Rank | Factor | Default Boost |
|------|--------|--------------|
| 1 | Title matches | 5x |
| 2 | Phrase matches (`"exact phrase"`) | 4x |
| 3 | Exact matches (all terms present) | 3x |
| 4 | Per-term boosts (`term^2`) | Custom multiplier |
| 5 | Base BM25 score | 1x |

You can adjust the boost factors in [Configuration](../get-started/configuration.md).

## Fuzzy Matching

Search Manager automatically finds similar terms using n-gram similarity. If a user searches for "tst", it will find documents containing "test". This works transparently — no special syntax needed.

Configuration options:

```php
'ngramSizes' => '2,3',           // N-gram sizes for comparison
'similarityThreshold' => 0.25,   // Minimum similarity (0.0–1.0)
'maxFuzzyCandidates' => 100,     // Max candidates to evaluate
```

A lower `similarityThreshold` catches more typos but may return less relevant results. The default of `0.25` provides good typo tolerance without too much noise.

## Stop Words

Common words like "the", "a", "is" are automatically filtered from queries to improve relevance. Stop words are supported in five languages:

- English (297 stop words)
- Arabic (122 stop words)
- German (130+ stop words)
- French (140+ stop words)
- Spanish (135+ stop words)

Stop words can be disabled globally or per-index. See [Multi-Language](multi-language.md) for language details.

## Text Normalization

Search Manager normalizes text during both indexing and querying so that equivalent characters always match, regardless of how they were typed or stored. This runs automatically — no configuration needed.

| Normalization | Example | Effect |
|---------------|---------|--------|
| Unicode compatibility (NFKC) | `ﬁ` → `fi`, `Ａ` → `A` | Fullwidth and ligature variants match their standard forms |
| Arabic tatweel removal | `البحـر` → `البحر` | Tatweel (kashida) stretching characters are ignored |
| Universal digit folding | `٢` → `2`, `๒` → `2`, `२` → `2` | Digits from any script (Arabic-Indic, Thai, Devanagari, Bengali, etc.) are folded to ASCII |
| Case folding | `Craft` → `craft` | All text is lowercased |
| Accent folding | `jalapeño` → `jalapeno`, `naïve` → `naive` | Diacritics and accents are stripped |

This means a search for `البحـر` (with tatweel) matches content stored as `البحر` (without), and a search for `٢` matches content containing `2`. All built-in backends (MySQL, PostgreSQL, Redis, File) produce identical results because normalization happens before any storage.

## Localized Boolean Operators

Boolean operators work in all five supported languages:

| Language | AND | OR | NOT |
|----------|-----|-----|-----|
| English | AND | OR | NOT |
| German | UND | ODER | NICHT |
| French | ET | OU | SAUF |
| Spanish | Y | O | NO |
| Arabic | و | أو / او | ليس / لا |

The language is auto-detected from the current site's locale. English operators always work as a fallback regardless of language.

```twig
{# German site #}
{% set results = craft.searchManager.search('products', 'kaffee ODER tee') %}

{# English fallback always works #}
{% set results = craft.searchManager.search('products', 'kaffee OR tee') %}
```

See [Multi-Language](multi-language.md) and [Advanced Operators](../template-guides/advanced-operators.md) for more examples.

## Native Search Replacement

When `replaceNativeSearch` is enabled, Search Manager replaces Craft's built-in search service. This means:

- **CP searches** use your backend (Entries > Search, Assets > Search, etc.)
- **Template searches** use your backend automatically
- **Element queries** use your backend (`Entry::find()->search('query')`)
- **All search operators** work everywhere, including the CP search box

```php
// config/search-manager.php
'replaceNativeSearch' => true,
```

This only works with built-in backends (MySQL, PostgreSQL, Redis, File).
