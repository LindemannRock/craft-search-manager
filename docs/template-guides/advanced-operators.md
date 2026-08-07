# Advanced operators

Give your users phrase search, exclusions, wildcards, and per-term boosting — the built-in engines (MySQL, PostgreSQL, Redis, File) parse all of the operators below directly out of the query string, no extra template code needed. This guide shows each operator with practical examples.

These operators are for the **built-in backends' query syntax**. Algolia, Meilisearch, and Typesense receive the query string unchanged — Search Manager doesn't translate these operators for them, so external-provider query syntax (if any) is provider-native. See [Field-specific search](#field-specific-search) below for how this plays out with `title:`/`content:` specifically.

## Phrase search

Wrap terms in double quotes to find exact sequences:

```twig
{% set results = craft.searchManager.search('entries', '"craft cms"') %}
```

Only matches documents where "craft" is immediately followed by "cms". Phrase matches are boosted 4x by default.

The phrase check keeps the complete quoted sequence. For example, `"Choose from 7 search backends"` may highlight that whole phrase even when enabled English stop-word filtering would remove `from` from an ordinary unquoted query.

## NOT operator

Exclude documents containing specific terms:

```twig
{# Exclude a single term #}
{% set results = craft.searchManager.search('entries', 'craft NOT plugin') %}

{# Exclude multiple terms #}
{% set results = craft.searchManager.search('entries', '"craft cms" NOT plugin NOT theme') %}
```

## Field-specific search

Target supported built-in document fields:

```twig
{# Search only in titles #}
{% set results = craft.searchManager.search('entries', 'title:blog') %}

{# Search only in content #}
{% set results = craft.searchManager.search('entries', 'content:tutorial') %}

{# Combine fields #}
{% set results = craft.searchManager.search('entries', 'title:craft content:plugin') %}
```

`title:` and `content:` are the only query field scopes. They are Search Manager pseudo-scopes, not transformer or custom-field handles. On the built-in MySQL, PostgreSQL, Redis, and File backends, `title:` requires exact membership in the indexed title tokens, while `content:` requires exact membership in the non-title document tokens. Combining them requires every scope to pass; the filter itself doesn't fuzzy-expand its value.

Algolia, Meilisearch, and Typesense receive the original query string unchanged. Search Manager doesn't translate these pseudo-scopes into provider-specific field controls, so `title:` and `content:` aren't portable field operators on external backends; any interpretation there is provider-native. Use provider-specific configuration or supported backend options when external field targeting is required.

## Wildcards

Use `*` for prefix matching:

```twig
{# Match test, tests, testing, tested #}
{% set results = craft.searchManager.search('entries', 'test*') %}

{# Multiple wildcards #}
{% set results = craft.searchManager.search('entries', 'test* OR craft*') %}
```

## Per-term boosting

Assign custom weights to individual terms:

```twig
{# "craft" counts 2x more than "cms" #}
{% set results = craft.searchManager.search('entries', 'craft^2 cms') %}

{# Multiple boost levels #}
{% set results = craft.searchManager.search('entries', 'craft^3 plugin^2 tutorial^1.5') %}
```

## Boolean operators

On built-in backends, unquoted adjacent terms use AND by default. Hyphens and other punctuation are token boundaries, so `built-in backends` is processed as the adjacent terms `built`, `in`, and `backends`; enabled stop-word filtering can then remove `in` before matching.

```twig
{# OR: documents with either term #}
{% set results = craft.searchManager.search('entries', 'craft OR cms') %}

{# AND: documents with both terms (this is the default) #}
{% set results = craft.searchManager.search('entries', 'craft AND cms') %}
{% set results = craft.searchManager.search('entries', 'craft cms') %}
```

Highlighting follows each result's effective match metadata: OR results paint only the operands that matched that result, NOT operands are not painted, and explicit `title:`/`content:` scopes remain restrictive. Split H2/H3 result headings paint every matched term that occurs in that displayed heading. See [Which query words are highlighted?](../feature-tour/highlighting.md#which-query-words-are-highlighted) for the canonical examples and stop-word behavior.

## Localized boolean operators

On non-English sites, boolean operators work in the site's language:

| Language | AND | OR | NOT |
|---|---|---|---|
| English (`en`) | `AND` | `OR` | `NOT` |
| German (`de`) | `UND` | `ODER` | `NICHT` |
| French (`fr`) | `ET` | `OU` | `SAUF` |
| Spanish (`es`) | `Y` | `O` | `NO` |
| Dutch (`nl`) | `EN` | `OF` | `NIET` |
| Italian (`it`) | `E` | `O` | `NON` |
| Portuguese (`pt`) | `E` | `OU` | `NÃO` / `NAO` |
| Swedish (`sv`) | `OCH` | `ELLER` | `INTE` |
| Danish (`da`) | `OG` | `ELLER` | `IKKE` |
| Norwegian (`no`) | `OG` | `ELLER` | `IKKE` / `IKKJE` |
| Japanese (`ja`) | `かつ` | `または` / `もしくは` | `でない` / `ではない` |
| Arabic (`ar`) | `و` | `أو` / `او` | `ليس` / `لا` |

All operators are case-insensitive. English operators always work as a fallback on any language site.

```twig
{# German #}
{% set results = craft.searchManager.search('products', 'kaffee ODER tee') %}
{% set results = craft.searchManager.search('products', 'kaffee NICHT entkoffeiniert') %}

{# French #}
{% set results = craft.searchManager.search('products', 'café OU thé') %}
{% set results = craft.searchManager.search('products', 'café SAUF décaféiné') %}

{# Spanish #}
{% set results = craft.searchManager.search('products', 'café O té') %}

{# Swedish #}
{% set results = craft.searchManager.search('products', 'kaffe ELLER te') %}
```

## Combining operators

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

## Practical examples

### Site search with exclusions

```twig
{# Search blog but exclude archived content #}
{% set results = craft.searchManager.search('blog', query ~ ' NOT archived NOT draft') %}
```

### Product search restricted to titles

```twig
{# Only match products whose TITLE contains the query — body-only matches are excluded #}
{% set results = craft.searchManager.search('products', 'title:' ~ query) %}
```

`title:` is a hard filter, not a boost — results that match only in body content are dropped entirely. Title matches are already boosted automatically in relevance ranking (the `titleBoostFactor` setting), so you don't need `title:` to make titles rank higher.

### Multi-language search form

```twig
{# Let users use operators in their language #}
{% set results = craft.searchManager.search('all-entries', query) %}
{# On a German site, "laptop ODER tablet" works automatically #}
```

## Fuzzy matching

Fuzzy matching is automatic — no special syntax needed. If a user searches for "tst", Search Manager finds documents containing "test". Configure sensitivity in [Fuzzy matching](../feature-tour/search-features.md#fuzzy-matching).

## Next steps

- [Basic search](basic-search.md) — the search form and results loop these operators plug into
- [Filtering & facets](filtering-facets.md) — narrow results by type or site instead of query terms
- [API endpoints](api-endpoints.md#search-operators-in-api) — the same operators over REST
