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

## Element Type Filtering

The API supports filtering by element type (derived from section handle):

```twig
{# Search API - filter by type #}
{# GET /actions/search-manager/api/search?q=laptop&type=product,category #}
```

```javascript
const response = await fetch(
    `/actions/search-manager/api/search?q=${query}&type=product`
);
```

### Element Type Mapping

| Section Handle | Type Value |
|----------------|------------|
| `products` | `product` |
| `categories` | `category` |
| `stores` | `store` |
| `blog-posts` | `blog-post` |

Non-Entry elements:
- Categories → `category`
- Assets → `asset`
- Users → `user`
- Tags → `tag`

You can override the type in your transformer:

```php
$data['elementType'] = 'custom-type';
```

## Site Filtering

Filter results to a specific site:

```twig
{# Via Twig #}
{% set results = craft.searchManager.search('all-entries', query, {
    siteId: currentSite.id,
}) %}

{# Via API — use per-site index handles instead of siteId #}
{# GET /actions/search-manager/api/search?q=test&index=entries-en #}
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
