# Filtering & Facets

Search Manager provides `parseFilters()` to generate backend-specific filter strings from a simple key-value array. This lets you write one filter definition that works across all backends.

## Using parseFilters()

```twig
{% set filterString = craft.searchManager.parseFilters({
    category: ['Electronics', 'Computers'],
    inStock: true,
    brand: 'Apple',
}) %}
```

The output depends on your active backend:

| Backend | Output |
|---------|--------|
| Algolia | `(category:"Electronics" OR category:"Computers") AND (inStock:"true") AND (brand:"Apple")` |
| Meilisearch | `(category = "Electronics" OR category = "Computers") AND inStock = "true" AND brand = "Apple"` |
| Typesense | `category:=[\`Electronics\`, \`Computers\`] && inStock:=\`true\` && brand:=\`Apple\`` |
| MySQL/PostgreSQL/Redis/File | SQL-like filter |

Backend setup still matters. Search Manager can generate the right filter syntax, but external providers require the filtered fields to be configured in the provider:

- Algolia custom filter fields must be listed in `attributesForFaceting`.
- Meilisearch custom filter fields must be listed in `filterableAttributes`.
- Typesense custom filter fields must exist in the collection schema with filtering support.

For Algolia, Search Manager automatically configures only its built-in filter fields: `siteId`, `elementType`, and `type`. Fields like `brand`, `category`, `price`, or `inStock` must be added in Algolia before those filters can work.

## Filtering Search Results

Combine filters with a search query:

```twig
{% set results = craft.searchManager.search('products', 'laptop', {
    filters: craft.searchManager.parseFilters({category: 'Electronics'}),
}) %}
```

## Building Filters from URL Parameters

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

## Document Type Filtering

The API supports filtering by stable document kind. Use lowercase values:
`entry`, `product`, `variant`, `asset`, `category`, or `user`.

```twig
{# Search API - filter by type #}
{# GET /actions/search-manager/api/search?q=laptop&type=product,category #}
```

```javascript
const response = await fetch(
    `/actions/search-manager/api/search?q=${query}&type=product`
);
```

Entry section metadata is separate from the document kind:

| Field | Meaning |
|-------|---------|
| `type` / `elementType` | Stable document kind, for example `entry` |
| `section` | Human-readable section name |
| `sectionHandle` | Entry section handle |
| `sectionType` | Entry section type: `single`, `channel`, or `structure` |

Commerce metadata is also separate from the document kind:

| Field | Meaning |
|-------|---------|
| `type` / `elementType` | `product` or `variant` |
| `productType` | Human-readable Commerce product type name |
| `productTypeHandle` | Commerce product type handle |

When changing a transformer document type or metadata shape, rebuild the affected index so stored search documents match the current contract.

You can override the document kind in a custom transformer, but keep `type` and `elementType` aligned:

```php
$data['elementType'] = 'custom-type';
$data['type'] = 'custom-type';
```

## Site Filtering

Filter results to a specific site:

```twig
{# Via Twig #}
{% set results = craft.searchManager.search('all-entries', query, {
    siteId: currentSite.id,
}) %}

{# Via API — use per-site index handles instead of siteId #}
{# GET /actions/search-manager/api/search?q=test&indices=entries-en #}
```

## Complete Filtered Search Page

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
