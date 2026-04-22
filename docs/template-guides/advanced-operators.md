# Advanced Operators

Search Manager supports powerful query operators for the built-in backends (MySQL, PostgreSQL, Redis, File). This guide shows how to use each operator with practical examples.

## Phrase Search

Wrap terms in double quotes to find exact sequences:

```twig
{% set results = craft.searchManager.search('entries', '"craft cms"') %}
```

Only matches documents where "craft" is immediately followed by "cms". Phrase matches are boosted 4x by default.

## NOT Operator

Exclude documents containing specific terms:

```twig
{# Exclude a single term #}
{% set results = craft.searchManager.search('entries', 'craft NOT plugin') %}

{# Exclude multiple terms #}
{% set results = craft.searchManager.search('entries', '"craft cms" NOT plugin NOT theme') %}
```

## Field-Specific Search

Target specific fields in your indexed documents:

```twig
{# Search only in titles #}
{% set results = craft.searchManager.search('entries', 'title:blog') %}

{# Search only in content #}
{% set results = craft.searchManager.search('entries', 'content:tutorial') %}

{# Combine fields #}
{% set results = craft.searchManager.search('entries', 'title:craft content:plugin') %}
```

Field names correspond to the keys returned by your transformer.

## Wildcards

Use `*` for prefix matching:

```twig
{# Match test, tests, testing, tested #}
{% set results = craft.searchManager.search('entries', 'test*') %}

{# Multiple wildcards #}
{% set results = craft.searchManager.search('entries', 'test* OR craft*') %}
```

## Per-Term Boosting

Assign custom weights to individual terms:

```twig
{# "craft" counts 2x more than "cms" #}
{% set results = craft.searchManager.search('entries', 'craft^2 cms') %}

{# Multiple boost levels #}
{% set results = craft.searchManager.search('entries', 'craft^3 plugin^2 tutorial^1.5') %}
```

## Boolean Operators

```twig
{# OR: documents with either term #}
{% set results = craft.searchManager.search('entries', 'craft OR cms') %}

{# AND: documents with both terms (this is the default) #}
{% set results = craft.searchManager.search('entries', 'craft AND cms') %}
{% set results = craft.searchManager.search('entries', 'craft cms') %}
```

## Localized Boolean Operators

On non-English sites, boolean operators work in the site's language. Supported languages:

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

## Combining Operators

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

## Practical Examples

### Site Search with Exclusions

```twig
{# Search blog but exclude archived content #}
{% set results = craft.searchManager.search('blog', query ~ ' NOT archived NOT draft') %}
```

### Product Search with Category Focus

```twig
{# Boost "title" matches for product search #}
{% set results = craft.searchManager.search('products', 'title:' ~ query) %}
```

### Multi-Language Search Form

```twig
{# Let users use operators in their language #}
{% set results = craft.searchManager.search('all-entries', query) %}
{# On a German site, "laptop ODER tablet" works automatically #}
```

## Fuzzy Matching

Fuzzy matching is automatic — no special syntax needed. If a user searches for "tst", Search Manager finds documents containing "test". Configure sensitivity in [Fuzzy Matching](../feature-tour/search-features.md#fuzzy-matching).
