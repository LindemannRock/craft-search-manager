# Highlighting & Snippets

This guide shows how to highlight matched search terms and generate context snippets in your templates.

## Highlighting Text

Use `craft.searchManager.highlight()` to wrap matched terms with an HTML tag:

```twig
{% set results = craft.searchManager.search('entries', query) %}

{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.objectID).one() %}
    {% if entry %}
        <h3>{{ craft.searchManager.highlight(entry.title, query)|raw }}</h3>
        {# "About <mark>craft</mark> <mark>cms</mark> development" #}
    {% endif %}
{% endfor %}
```

The `|raw` filter is required because highlighting inserts HTML tags.

### Custom Options

```twig
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',                    // Use <em> instead of <mark>
    class: 'search-highlight',    // Add a CSS class
    stripTags: true,              // Strip existing HTML before highlighting
})|raw }}
```

## Generating Snippets

Use `craft.searchManager.snippets()` to extract text excerpts around matched terms:

```twig
{% set snippets = craft.searchManager.snippets(entry.body, query, {
    snippetLength: 200,
    maxSnippets: 3,
}) %}

{% for snippet in snippets %}
    <p class="snippet">...{{ snippet|raw }}...</p>
{% endfor %}
```

Each snippet is a string with matched terms already highlighted.

## Complete Search Results Template

```twig
{% set query = craft.app.request.getParam('q') %}

{% if query %}
    {% set results = craft.searchManager.search('entries-en', query) %}

    {% for hit in results.hits %}
        {% set entry = craft.entries.id(hit.objectID).one() %}
        {% if entry %}
            <article class="search-result">
                <h3>
                    <a href="{{ entry.url }}">
                        {{ craft.searchManager.highlight(entry.title, query)|raw }}
                    </a>
                </h3>

                {# Show context snippets from the body #}
                {% set snippets = craft.searchManager.snippets(
                    entry.body ?? '',
                    query,
                    {snippetLength: 150, maxSnippets: 2}
                ) %}

                {% if snippets|length %}
                    {% for snippet in snippets %}
                        <p class="snippet">...{{ snippet|raw }}...</p>
                    {% endfor %}
                {% elseif hit.excerpt is defined %}
                    <p>{{ hit.excerpt }}</p>
                {% endif %}

                <small>
                    <a href="{{ entry.url }}">{{ entry.url }}</a>
                </small>
            </article>
        {% endif %}
    {% endfor %}
{% endif %}
```

## CSS Styling

### Default `<mark>` Tag

```css
mark {
    background-color: #ffeb3b;
    padding: 2px 4px;
    border-radius: 2px;
}
```

### Custom Class

Configure in your config file:

```php
'highlightClass' => 'search-highlight',
```

```css
.search-highlight {
    background-color: #ff9800;
    color: #fff;
    padding: 1px 3px;
    border-radius: 2px;
}
```

### Snippet Styling

```css
.snippet {
    color: #666;
    line-height: 1.5;
}

.snippet mark {
    background-color: #fff3cd;
    font-weight: 600;
}
```

## Configuration Defaults

These settings apply when you don't pass options to the template functions:

```php
// config/search-manager.php
'enableHighlighting' => true,
'highlightTag' => 'mark',
'highlightClass' => null,
'snippetLength' => 200,
'maxSnippets' => 3,
```

Per-call options override these defaults.
