# Basic Search

This guide shows how to add search to your Craft templates using Search Manager.

## Simple Search

The most basic search — query an index and display results:

```twig
{% set query = craft.app.request.getParam('q') %}

{% if query %}
    {% set results = craft.searchManager.search('entries-en', query) %}

    <p>Found {{ results.total }} results for "{{ query }}"</p>

    {% for hit in results.hits %}
        <article>
            <h3><a href="{{ hit.url }}">{{ hit.title }}</a></h3>
            <p>{{ hit.excerpt ?? '' }}</p>
            <small>Score: {{ hit.score|number_format(2) }}</small>
        </article>
    {% endfor %}
{% endif %}
```

## Search Form

A standard HTML form that submits to a search results page:

```twig
<form action="{{ url('search') }}" method="get">
    <input type="search" name="q" value="{{ craft.app.request.getParam('q') }}" placeholder="Search...">
    <button type="submit">Search</button>
</form>
```

## Results Page

A complete search results template:

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
                    {% if hit.excerpt is defined %}
                        <p>{{ hit.excerpt }}</p>
                    {% endif %}
                </article>
            {% endfor %}
        {% else %}
            <p>No results found for "{{ query }}". Try different search terms.</p>
        {% endif %}
    {% endif %}
{% endblock %}
```

## Loading Full Elements

Search results contain the indexed data from your transformer. If you need the full Craft element (for custom fields, relations, etc.), load it by ID:

```twig
{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.objectID).one() %}
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

## Handling No Results

```twig
{% if results.total == 0 %}
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
```

## Using Native Search Replacement

If you've enabled `replaceNativeSearch`, you don't need `craft.searchManager` at all — Craft's standard search works automatically:

```twig
{% set entries = craft.entries.search(query).orderBy('score').all() %}

{% for entry in entries %}
    <h3><a href="{{ entry.url }}">{{ entry.title }}</a></h3>
{% endfor %}
```

All search operators (phrase, NOT, wildcards, etc.) work in this mode too.

## Next Steps

- [Advanced Operators](advanced-operators.md) — phrase search, NOT, wildcards, boosting
- [Highlighting & Snippets](highlighting-snippets.md) — highlight matched terms
- [Autocomplete & Suggestions](autocomplete-suggestions.md) — search-as-you-type
