# Filtering & facets

Narrow a search to a document kind, a site, or — once you're on Algolia, Meilisearch, or Typesense — an arbitrary custom field like `category` or `price`. This guide covers both: what works on every backend, and what's only available once you've moved to an external provider.

Filtering support isn't symmetric across backends. `type` (document kind) and `siteId` work everywhere, including the built-in MySQL, PostgreSQL, Redis, and File engines. Custom-field filtering (`category`, `brand`, `inStock`, and so on) via `parseFilters()` only works on Algolia, Meilisearch, and Typesense — the built-in engines don't have an equivalent today. See [Filtering on external providers](#filtering-on-external-providers) below for what that means in practice.

## Document type filtering

Filter by stable document kind — `entry`, `product`, `variant`, `asset`, `category`, or `user` (lowercase). This works in Twig, the REST API, and JavaScript, on every backend.

```twig
{# Single type #}
{% set results = craft.searchManager.search('products', query, {
    type: 'product',
}) %}

{# Multiple types (comma-separated string or array both work) #}
{% set results = craft.searchManager.search('products', query, {
    type: 'product,category',
}) %}
```

```text
{# Search API #}
GET /actions/search-manager/api/search?q=laptop&type=product,category
```

```javascript
const response = await fetch(
    `/actions/search-manager/api/search?q=${query}&type=product`
);
```

Entry section metadata is separate from the document kind:

| Field | Meaning |
|-------|---------|
| `type` | Stable document kind, for example `entry` |
| `entrySection` | Human-readable section name |
| `entrySectionHandle` | Entry section handle |
| `entrySectionType` | Entry section type: `single`, `channel`, or `structure` |

(The similarly named `sectionType` hit field is unrelated — it appears only on split-section hits and identifies the split-record kind: `intro`, `heading`, or `promoted-page`.)

Commerce metadata is also separate from the document kind:

| Field | Meaning |
|-------|---------|
| `type` | `product` or `variant` |
| `productType` | Human-readable Commerce product type name |
| `productTypeHandle` | Commerce product type handle |

When changing a transformer document type or metadata shape, rebuild the affected index so stored search documents match the current contract.

You can override the document kind in a custom transformer by setting `type`. Filtering and faceting target this same field:

```php
$data['type'] = 'custom-type';
```

## Site filtering

Filter results to a specific site — also works everywhere:

```twig
{# Via Twig #}
{% set results = craft.searchManager.search('all-entries', query, {
    siteId: currentSite.id,
}) %}

{# Via API — use per-site index handles instead of siteId #}
{# GET /actions/search-manager/api/search?q=test&indexHandles=entries-en #}
```

## Filtering on external providers

Algolia, Meilisearch, and Typesense each support real custom-field filtering — `category`, `brand`, `price`, `inStock`, anything in your indexed data. `parseFilters()` generates the right filter syntax for whichever backend is active, so you write one filter definition instead of three.

> [!NOTE]
> The built-in MySQL, PostgreSQL, Redis, and File backends don't consume a `filters` string at all — only `type` and `siteId` (above) filter on those backends. If you need to filter local search by an arbitrary custom field, either post-filter `results.hits` in your template after the search call, or move that index to an external backend.

### Generating a filter string with parseFilters()

```twig
{% set filterString = craft.searchManager.parseFilters({
    category: ['Electronics', 'Computers'],
    inStock: true,
    brand: 'Apple',
}) %}
```

The output syntax depends on your active backend:

| Backend | Output |
|---------|--------|
| Algolia | `(category:"Electronics" OR category:"Computers") AND (inStock:"true") AND (brand:"Apple")` |
| Meilisearch | `(category = "Electronics" OR category = "Computers") AND inStock = "true" AND brand = "Apple"` |
| Typesense | `category:=[\`Electronics\`, \`Computers\`] && inStock:=true && brand:=\`Apple\`` |

Backend setup still matters. Search Manager can generate the right filter syntax, but external providers require the filtered fields to be configured in the provider:

- Algolia custom filter fields must be listed in `attributesForFaceting`.
- Meilisearch custom filter fields must be listed in `filterableAttributes`.
- Typesense custom filter fields must exist in the collection schema with filtering support.

For Algolia, Search Manager automatically configures only its built-in filter fields: `siteId`, `elementId`, and `type`. Fields like `brand`, `category`, `price`, or `inStock` must be added in Algolia before those filters can work.

### Combining a filter with a search query

Pass the generated string as the `filters` option:

```twig
{% set results = craft.searchManager.search('products', 'laptop', {
    filters: craft.searchManager.parseFilters({category: 'Electronics'}),
}) %}
```

### Building filters from URL parameters

```twig
{% set category = craft.app.request.getParam('category') %}
{% set brand = craft.app.request.getParam('brand') %}
{% set query = craft.app.request.getParam('q') %}

{% set filters = {} %}
{% if category %}
    {% set filters = filters|merge({category: category}) %}
{% endif %}
{% if brand %}
    {% set filters = filters|merge({brand: brand}) %}
{% endif %}

{% set results = craft.searchManager.search('products', query, {
    filters: filters|length ? craft.searchManager.parseFilters(filters) : null,
}) %}
```

## Complete filtered search page

Ties document type filtering, site scope, and (for an external backend) a `category` facet together into one page:

```twig
{% set query = craft.app.request.getParam('q') ?? '' %}
{% set category = craft.app.request.getParam('category') ?? '' %}
{% set sort = craft.app.request.getParam('sort') ?? 'relevance' %}

{# Build filter from URL params #}
{% set filters = {} %}
{% if category %}
    {% set filters = filters|merge({category: category}) %}
{% endif %}

{# Search with filters #}
{% set searchOptions = {} %}
{% if filters|length %}
    {% set searchOptions = {filters: craft.searchManager.parseFilters(filters)} %}
{% endif %}

{% set results = craft.searchManager.search('products', query, searchOptions) %}

{# Filter UI #}
<form method="get">
    <input type="search" name="q" value="{{ query }}" placeholder="Search products...">

    <select name="category">
        <option value="">All Categories</option>
        <option value="Electronics" {{ category == 'Electronics' ? 'selected' }}>Electronics</option>
        <option value="Clothing" {{ category == 'Clothing' ? 'selected' }}>Clothing</option>
    </select>

    <button type="submit">Search</button>
</form>

{# Results #}
<p>{{ results.total }} results</p>
{% for hit in results.hits %}
    <div class="product">
        <h3>{{ hit.title }}</h3>
    </div>
{% endfor %}
```

## Next steps

- [Basic search](basic-search.md) — the search form and results loop this guide builds on
- [Multi-index search](multi-index-search.md) — search several indices instead of filtering one
- [Backends](../backends/backends.md) — compare built-in vs. external providers before choosing where to filter
- [API endpoints](api-endpoints.md) — `type` and `retrievableFields` parameters for REST callers
