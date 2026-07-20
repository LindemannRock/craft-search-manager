# Multi-index search

Search several indices — products, blog, pages, whatever you've configured — in a single call and get back one merged result set instead of running (and combining) separate queries yourself.

Reach for this when you're building one search box that spans multiple content types. If you only need one index, [Basic search](basic-search.md) is simpler. If you need to narrow a single index by document kind instead of querying several indices, see [Document type filtering](filtering-facets.md#document-type-filtering).

## Basic multi-index search

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], query) %}

<p>Found {{ results.total }} results</p>

{% for hit in results.hits %}
    {% set element = craft.entries.id(hit.elementId).one() %}
    {% if element %}
        <article class="result result--{{ hit.index }}">
            <span class="source">{{ hit.index }}</span>
            <h3><a href="{{ element.url }}">{{ element.title }}</a></h3>
        </article>
    {% endif %}
{% endfor %}
```

## Response structure

```php
[
    'hits' => [
        ['elementId' => 123, 'backendId' => '123_1', 'score' => 45.2, 'index' => 'products'],
        ['elementId' => 456, 'backendId' => '456_1', 'score' => 38.1, 'index' => 'blog'],
        // Merged using each backend's relevance signal when available
    ],
    'total' => 150,
    'indices' => [
        'products' => 50,
        'blog' => 100,
    ],
]
```

- Results are merged using each backend's relevance signal when available
- Each hit includes `index` to identify its source index
- `indices` provides per-index result counts

Scores are backend-specific. Built-in backends use Search Manager's BM25 score, Meilisearch and Typesense can expose provider ranking values, Algolia may not include a comparable numeric score, and promoted results can use `score: null`. Do not compare scores across different backend types.

## Per-index breakdown

Show result counts per index:

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], query) %}

<div class="facets">
    <p>{{ results.total }} total results</p>
    <ul>
        {% for indexName, count in results.indices %}
            <li>{{ indexName }}: {{ count }} results</li>
        {% endfor %}
    </ul>
</div>
```

## Grouped display

Group results by their source index instead of a flat list:

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], query) %}

{% set grouped = {} %}
{% for hit in results.hits %}
    {% set grouped = grouped|merge({(hit.index): (grouped[hit.index] ?? [])|merge([hit])}) %}
{% endfor %}

{% for indexName, hits in grouped %}
    <section>
        <h2>{{ indexName|capitalize }} ({{ hits|length }})</h2>
        {% for hit in hits %}
            {% set entry = craft.entries.id(hit.elementId).one() %}
            {% if entry %}
                <div>
                    <a href="{{ entry.url }}">{{ entry.title }}</a>
                    {% if hit.score is defined and hit.score is not null %}
                        <small>Score: {{ hit.score|number_format(2) }}</small>
                    {% endif %}
                </div>
            {% endif %}
        {% endfor %}
    </section>
{% endfor %}
```

## Using a specific backend

By default, multi-index search uses the default backend. To query a specific backend:

```twig
{% set algolia = craft.searchManager.withBackend('production-algolia') %}
{% set results = algolia.search('products', query) %}
```

> [!WARNING]
> Unlike `craft.searchManager.search()` and `searchMultiple()`, the `withBackend()` proxy returns **raw backend hits** — they are not run through the public presentation pipeline. Raw hits keep backend-internal keys (`id`, `objectID`, `_index`, underscore-prefixed fields) and do not include presented fields like `snippet`, `headings`, or `index`. Use it for backend-level operations (browse, batch queries, diagnostics), not for rendering public search results.

The `withBackend()` proxy supports all the same methods:

```twig
{% set backend = craft.searchManager.withBackend('my-backend') %}

{# Check backend info #}
<p>Using: {{ backend.getName() }}</p>
<p>Available: {{ backend.isAvailable() ? 'Yes' : 'No' }}</p>

{# Search #}
{% set results = backend.search('products', query) %}

{# Browse all documents (external backends only) #}
{% if backend.supportsBrowse() %}
    {% for doc in backend.browse({index: 'products', query: ''}) %}
        {{ doc.title }}
    {% endfor %}
{% endif %}

{# Batch queries (external backends: native, built-in: sequential fallback) #}
{% set batchResults = backend.multipleQueries([
    {indexName: 'products', query: 'laptop'},
    {indexName: 'categories', query: 'electronics'},
]) %}
```

## Batch queries (external backends)

For Algolia, Meilisearch, and Typesense, `multipleQueries()` sends all queries in a single API call:

```twig
{% set results = craft.searchManager.multipleQueries([
    {indexName: 'products', query: 'laptop'},
    {indexName: 'categories', query: 'electronics'},
    {indexName: 'blog', query: 'review'},
]) %}

{% for result in results.results %}
    <h3>Results from query {{ loop.index }}</h3>
    {# Each provider names its total differently in raw batch results #}
    <p>{{ result.nbHits ?? result.estimatedTotalHits ?? result.found ?? result.total }} hits</p>
{% endfor %}
```

Built-in backends fall back to sequential queries automatically.

> [!NOTE]
> `multipleQueries()` returns each provider's raw response, so the totals field differs per backend: Algolia uses `nbHits`, Meilisearch uses `estimatedTotalHits`, Typesense uses `found`, and the built-in backends use `total`.

## Next steps

- [Basic search](basic-search.md) — single-index search and the indexed-data-vs-live-element tradeoff
- [Filtering & facets](filtering-facets.md) — narrow one index by type or site instead of combining several
- [Backends](../backends/backends.md) — what `withBackend()` targets and how backend selection works
- [API endpoints](api-endpoints.md) — the REST equivalent for JavaScript/headless callers
