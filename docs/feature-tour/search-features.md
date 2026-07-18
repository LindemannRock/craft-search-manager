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

Search within the supported built-in document fields:

```twig
{% set results = craft.searchManager.search('entries', 'title:blog') %}
{% set results = craft.searchManager.search('entries', 'content:tutorial') %}
{% set results = craft.searchManager.search('entries', 'title:craft content:plugin') %}
```

`title:` and `content:` are the only query field scopes. They are Search Manager pseudo-scopes, not transformer or custom-field handles. On the built-in MySQL, PostgreSQL, Redis, and File backends, `title:` requires exact membership in the indexed title tokens, while `content:` requires exact membership in the non-title document tokens. Combining them requires every scope to pass; the filter itself doesn't fuzzy-expand its value.

Algolia, Meilisearch, and Typesense receive the original query string unchanged. Search Manager doesn't translate these pseudo-scopes into provider-specific field controls, so `title:` and `content:` aren't portable field operators on external backends; any interpretation there is provider-native. Use provider-specific configuration or supported backend options when external field targeting is required.

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
    'craft* OR plugin title:tutorial NOT beginner getting^2 "started guide"'
) %}
```

This query:
- Matches words starting with "craft" OR containing "plugin"
- Requires "tutorial" in the title field
- Excludes documents containing "beginner"
- Gives a 2x boost to the term "getting"
- Boosts the exact phrase "started guide" with the configured phrase boost

## Ranking Priority

When multiple boost factors apply, they stack. From highest to lowest impact:

| Rank | Factor | Default Boost |
|------|--------|--------------|
| 1 | Title matches | 5x |
| 2 | Phrase matches (`"exact phrase"`) | 4x |
| 3 | Exact matches (ordered contiguous query-term sequence) | 3x |
| 4 | Per-term boosts (`term^2`) | Custom multiplier |
| 5 | Base BM25 score | 1x |

## Fuzzy Matching

Search Manager automatically finds similar terms using n-gram similarity. This works transparently — no special syntax needed — and runs as a **two-tier expander**:

1. **Expansion (always):** every query word is expanded with its closest indexed variants — searching for "tool" also matches documents that only contain "tools". Expanded variants are scored below exact matches, so exact matches always rank first.
2. **Typo recovery (on miss):** a word with no exact match at all gets a much broader fuzzy candidate pass — for example, "javascirpt" can find "javascript".

The same expansion powers autocomplete suggestions, so a suggested completion is always something search can find.

N-gram similarity finds the candidate pool. A typo-budget filter then removes look-alike words that are too different to be useful:

| Query word length | Allowed typos |
|-------------------|---------------|
| 3 characters or fewer | 0 |
| 4–7 characters | 1 |
| 8 characters or more | 2 |

An adjacent transposition counts as one typo. A difference in the first character counts as two because the first keystroke is less likely to be accidental. Prefix extensions are completion matches rather than typos, so they are always accepted: `tool` can match `tools`, and `test` can match `testing`. The rule is directional — the shorter query must be the prefix.

This precision step prevents unrelated look-alikes such as `test` and `best` from matching or appearing in autocomplete while preserving genuine mid-word typos and longer two-typo queries. It applies to the built-in MySQL, PostgreSQL, Redis, and File backends; external backends use their own native typo-tolerance rules.

Configuration options:

```php
'enableFuzzy' => true,           // Engine-wide switch (search + autocomplete)
'ngramSizes' => '2,3',           // N-gram sizes for comparison
'similarityThreshold' => 0.25,   // Minimum similarity (0.0–1.0)
'maxFuzzyCandidates' => 100,     // Max candidates for typo recovery
```

A lower `similarityThreshold` lets more terms enter the candidate pool, but it doesn't bypass the fixed typo budget. The default of `0.25` provides broad candidate recall while the precision filter removes candidates outside the query-length tier. This is query-time behavior, so changing plugin versions doesn't require a reindex for this rule.

## Relaxed Matching

Multi-word queries require every word to match the same document (AND logic). When a multi-word query would return zero results, Search Manager broadens it to match any word (OR logic) over the same expanded terms instead of dead-ending — documents covering more of the query words still rank first. When this happens, the response debug meta includes `relaxedMatching: true`, so a frontend can render a "showing related results" notice.

## Stop Words

Common words like "the", "a", "is" are automatically filtered from queries to improve relevance. Stop words are supported in twelve languages:

- English (297 stop words)
- Portuguese (266 stop words)
- Italian (264 stop words)
- Spanish (260 stop words)
- French (206 stop words)
- Norwegian (194 stop words, Bokmål + Nynorsk)
- Dutch (179 stop words)
- Swedish (176 stop words)
- German (171 stop words)
- Danish (162 stop words)
- Japanese (115 stop words — see [Multi-Language](multi-language.md) for caveats)
- Arabic (112 stop words)

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

Boolean operators work in all 12 supported languages:

| Language | AND | OR | NOT |
|----------|-----|-----|-----|
| English | AND | OR | NOT |
| German | UND | ODER | NICHT |
| French | ET | OU | SAUF |
| Dutch | EN | OF | NIET |
| Spanish | Y | O | NO |
| Arabic | و | أو / او | ليس / لا |
| Italian | E | O | NON |
| Portuguese | E | OU | NÃO / NAO |
| Japanese | かつ | または / もしくは | でない / ではない |
| Swedish | OCH | ELLER | INTE |
| Danish | OG | ELLER | IKKE |
| Norwegian | OG | ELLER | IKKE / IKKJE |

The language is auto-detected from the current site's locale. English operators always work as a fallback regardless of language.

```twig
{# German site #}
{% set results = craft.searchManager.search('products', 'kaffee ODER tee') %}

{# English fallback always works #}
{% set results = craft.searchManager.search('products', 'kaffee OR tee') %}
```

See [Multi-Language](multi-language.md) and [Advanced Operators](../template-guides/advanced-operators.md) for more examples.

## Native Search Replacement

When `replaceNativeSearch` is enabled, Search Manager can answer front-end template `.search()` calls with a Search Manager index. This is for Craft element queries such as `craft.entries.search(query).orderBy('score')` or `Entry::find()->search($query)`.

Use this when you want existing front-end templates to benefit from Search Manager scoring without rewriting them to the full Search Manager response API. Use `craft.searchManager.search()` directly when your template needs snippets, highlighting, redirects, multi-index responses, analytics metadata, or the complete hit payload.

```php
// config/search-manager.php
'replaceNativeSearch' => true,
```

Search Manager uses an index only when it has full coverage for the query: the index must be enabled, match the queried element type, run on a local backend (MySQL, PostgreSQL, Redis, or File), cover the query's site scope, and have no criteria restriction. Element types or site scopes without a matching full-coverage index continue using Craft's native search. Craft's native search index stays fully up to date, so fallback searches remain current and disabling native replacement does not require a content resave.

> [!NOTE]
> Control Panel search is not affected. CP requests always use Craft's native search service, including Craft's native statuses, events, and query syntax.

| Native `.search()` behavior | Carries through? | Notes |
|---|---:|---|
| Relevance ranking, boosts, and synonyms | Yes | Results use Search Manager scoring when a full-coverage index answers the query. |
| Promotion pinning | No | Promoted elements can still appear, but fixed promotion positions are not preserved through Craft's native score map. |
| Redirects, snippets, and highlighting | No | Use `craft.searchManager.search()` when the template needs the full Search Manager response. |

### Scope and limitations

Replace Native Search is intentionally a front-end enhancement, not a transparent replacement for every Craft search surface.

- **Front-end `.search()` only:** it affects template/site element queries that go through Craft's search service. It does not change CP element indexes or CP global search.
- **Coverage-based fallback:** if Search Manager cannot find a full-coverage local index for the query's element type and site scope, the query falls back to Craft native search.
- **Public-content indexing:** Search Manager indexes searchable content only. Entries must be live; users must be active; products and variants must be live where the Commerce element type exposes that status; assets, categories, and other element types must be enabled. Drafts, revisions, disabled content, and disabled-for-site content are not kept in the Search Manager index.
- **Score values differ:** Craft native search returns Craft keyword scores. Search Manager returns backend relevance scores, with the built-in backends using BM25. Both paths hand Craft a descending score map for normal `orderBy('score')` use, but the numeric values are not comparable.

### Query syntax differences

When a full-coverage Search Manager index answers the query, the query is parsed by Search Manager's parser instead of Craft's native search parser.

Search Manager supports:

- double-quoted phrases, such as `"craft cms"`;
- `AND`, `OR`, and `NOT`, including the localized boolean operators listed above;
- trailing `*` prefix wildcards, such as `craft*`;
- per-term boosts, such as `craft^2`;
- `title:` and `content:` field filters.

Craft native search supports some syntax that the Search Manager path does not mirror. Do not rely on Craft's `-term` exclusion shorthand, arbitrary `attribute:value` filters, `attribute::value` exact-attribute filters, `attribute:*` existence filters, leading `*term` wildcards, or single-quoted phrases in front-end `.search()` calls that may be answered by Search Manager.

> [!NOTE]
> Phrase matches still receive the configured phrase boost, but `^` applies to terms, not quoted phrases. Use `craft^2 "craft cms"`, not `"craft cms"^2`.
