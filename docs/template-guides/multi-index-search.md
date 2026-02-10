# Multi-Index Search

Search across multiple indices at once and get merged, scored results.

## Basic Multi-Index Search

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], query) %}

<p>Found {{ results.total }} results</p>

{% for hit in results.hits %}
    {% set element = craft.entries.id(hit.objectID).one() %}
    {% if element %}
        <article class="result result--{{ hit._index }}">
            <span class="source">{{ hit._index }}</span>
            <h3><a href="{{ element.url }}">{{ element.title }}</a></h3>
        </article>
    {% endif %}
{% endfor %}
```

## Response Structure

```php
[
    'hits' => [
        ['objectID' => 123, 'score' => 45.2, '_index' => 'products'],
        ['objectID' => 456, 'score' => 38.1, '_index' => 'blog'],
        // Merged and sorted by score
    ],
    'total' => 150,
    'indices' => [
        'products' => 50,
        'blog' => 100,
    ],
]
```

- Results are merged and sorted by relevance score across all indices
- Each hit includes `_index` to identify its source
- `indices` provides per-index result counts

## Per-Index Breakdown

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

## Grouped Display

Group results by their source index:

```twig
{% set results = craft.searchManager.searchMultiple(['products', 'blog', 'pages'], query) %}

{% set grouped = {} %}
{% for hit in results.hits %}
    {% set grouped = grouped|merge({(hit._index): (grouped[hit._index] ?? [])|merge([hit])}) %}
{% endfor %}

{% for indexName, hits in grouped %}
    <section>
        <h2>{{ indexName|capitalize }} ({{ hits|length }})</h2>
        {% for hit in hits %}
            {% set entry = craft.entries.id(hit.objectID).one() %}
            {% if entry %}
                <div>
                    <a href="{{ entry.url }}">{{ entry.title }}</a>
                    <small>Score: {{ hit.score|number_format(2) }}</small>
                </div>
            {% endif %}
        {% endfor %}
    </section>
{% endfor %}
```

## Using a Specific Backend

By default, multi-index search uses the default backend. To query a specific backend:

```twig
{% set algolia = craft.searchManager.withBackend('production-algolia') %}
{% set results = algolia.search('products', query) %}
```

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

## Batch Queries (External Backends)

For Algolia, Meilisearch, and Typesense, `multipleQueries()` sends all queries in a single API call:

```twig
{% set results = craft.searchManager.multipleQueries([
    {indexName: 'products', query: 'laptop'},
    {indexName: 'categories', query: 'electronics'},
    {indexName: 'blog', query: 'review'},
]) %}

{% for result in results.results %}
    <h3>Results from query {{ loop.index }}</h3>
    <p>{{ result.nbHits ?? result.total }} hits</p>
{% endfor %}
```

Built-in backends fall back to sequential queries automatically.
