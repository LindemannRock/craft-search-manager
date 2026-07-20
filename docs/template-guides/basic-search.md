# Basic search

By the end of this page you'll have a working search results page — a form, a query, and rendered hits — in plain Twig, no JavaScript required. `craft.searchManager.search()` is the Twig variable that does the work: point it at an index and a query string, and it returns matching hits.

This guide covers rendering search server-side in Twig. If you want search-as-you-type instead, see [Autocomplete & suggestions](autocomplete-suggestions.md). If you'd rather not build a results UI at all, the bundled [Frontend Widget](../widget/overview.md) is a drop-in modal with search, autocomplete, and analytics built in. If your frontend is JavaScript or a mobile app instead of Twig, see [API endpoints](api-endpoints.md).

## Simplest example

A search box that submits to itself and renders results:

```twig
{% set query = craft.app.request.getParam('q') %}

<form action="{{ url('search') }}" method="get">
    <input type="search" name="q" value="{{ query }}" placeholder="Search...">
    <button type="submit">Search</button>
</form>

{% if query %}
    {% set results = craft.searchManager.search('entries-en', query) %}

    <p>Found {{ results.total }} results for "{{ query }}"</p>

    {% for hit in results.hits %}
        <article>
            <h3><a href="{{ hit.url }}">{{ hit.title }}</a></h3>
            <p>{{ hit.snippet ?? '' }}</p>
            {% if hit.score is defined and hit.score is not null %}
                <small>Score: {{ hit.score|number_format(2) }}</small>
            {% endif %}
        </article>
    {% endfor %}
{% endif %}
```

`score` is optional. Built-in backends return a BM25 score, while external providers use their own ranking models and may return a different kind of score or no numeric score at all.

## Complete results page

A dedicated `/search` template with a proper no-results state:

```twig
{% extends '_layouts/default' %}

{% set query = craft.app.request.getParam('q') ?? '' %}

{% block content %}
    <h1>Search</h1>

    <form action="{{ url('search') }}" method="get">
        <input type="search" name="q" value="{{ query }}" placeholder="Search..." autofocus>
        <button type="submit">Search</button>
    </form>

    {% if query %}
        {% set results = craft.searchManager.search('entries-en', query) %}

        {% if results.total > 0 %}
            <p>{{ results.total }} result{{ results.total != 1 ? 's' }} for "{{ query }}"</p>

            {% for hit in results.hits %}
                <article>
                    <h3><a href="{{ hit.url }}">{{ hit.title }}</a></h3>
                    {% if hit.snippet %}
                        <p>{{ hit.snippet }}</p>
                    {% endif %}
                </article>
            {% endfor %}
        {% else %}
            <div class="no-results">
                <h2>No results found</h2>
                <p>Try:</p>
                <ul>
                    <li>Using different keywords</li>
                    <li>Removing filters</li>
                    <li>Checking your spelling</li>
                </ul>
            </div>
        {% endif %}
    {% endif %}
{% endblock %}
```

## Loading full Craft elements

`results.hits` gives you presented **indexed documents** — the data Search Manager captured the last time this content was indexed (title, URL, snippet, retrievable custom fields) — not live Craft elements. Two things follow from that.

**Hit data is only as fresh as the last index build.** If you change your content model — add a field, rename one, change what's indexed — existing hits keep returning the old shape until you rebuild:

```bash title="PHP"
php craft search-manager/index/rebuild --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild --handle=entries-en
```

Drop `--handle` to rebuild every index.

**If you need live element data** — custom field objects, relations, assets, anything beyond the flattened indexed strings — fetch the full Craft element yourself using `hit.elementId`:

```twig
{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.elementId).one() %}
    {% if entry %}
        <article>
            <h3><a href="{{ entry.url }}">{{ entry.title }}</a></h3>
            <p>{{ entry.summary }}</p>
            {% if entry.featuredImage|length %}
                {{ entry.featuredImage.one().getImg() }}
            {% endif %}
        </article>
    {% endif %}
{% endfor %}
```

This costs one Craft element query per hit, so it's fine for a page of 10-20 results but not for looping over hundreds. Reach for it only when the indexed `fields` on the hit genuinely aren't enough.

## Using native search replacement

If you've enabled `replaceNativeSearch`, front-end template `.search()` queries can use Search Manager automatically when the element type has a full-coverage index:

```twig
{% set entries = craft.entries.search(query).orderBy('score').all() %}

{% for entry in entries %}
    <h3><a href="{{ entry.url }}">{{ entry.title }}</a></h3>
{% endfor %}
```

Search Manager's query operators work in this mode when Search Manager answers the query. See [Search features](../feature-tour/search-features.md#query-syntax-differences) for the syntax differences from Craft native search.

Control Panel searches always stay on Craft's native search.

## Next steps

- [Advanced operators](advanced-operators.md) — phrase search, NOT, wildcards, boosting
- [Highlighting & snippets](highlighting-snippets.md) — highlight matched terms and render snippets
- [Autocomplete & suggestions](autocomplete-suggestions.md) — search-as-you-type
- [Filtering & facets](filtering-facets.md) — narrow results by type, site, or provider-native filters
- [Multi-index search](multi-index-search.md) — search several indices in one call
