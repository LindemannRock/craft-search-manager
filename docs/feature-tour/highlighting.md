# Highlighting & Snippets

Search Manager can highlight matched terms in your results and generate context snippets — short excerpts that show where the match occurs within the content.

## How It Works

Highlighting wraps matched search terms with an HTML tag (default: `<mark>`). Snippets extract portions of text around the matched terms so users can see the match in context.

Both features work on any text you pass in — they're not limited to indexed fields. You can highlight titles, body content, custom fields, or any string.

## Configuration

These settings control the default behavior. You can override them per-call in your templates.

```php
// config/search-manager.php
'enableHighlighting' => true,
'highlightTag' => 'mark',       // HTML tag: mark, em, strong, span
'highlightClass' => null,       // Optional CSS class
'snippetLength' => 200,         // Characters per snippet
'maxSnippets' => 3,             // Max snippets per result
```

## Usage

### Highlighting Text

```twig
{% set results = craft.searchManager.search('entries', 'craft cms') %}

{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.objectID).one() %}

    {# Highlight matched terms in the title #}
    <h2>{{ craft.searchManager.highlight(entry.title, 'craft cms')|raw }}</h2>
    {# Output: This is about <mark>craft</mark> <mark>cms</mark> #}
{% endfor %}
```

### Custom Options

```twig
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',
    class: 'search-highlight',
    stripTags: true,
})|raw }}
```

### Generating Snippets

Snippets extract portions of text around matched terms:

```twig
{% set snippets = craft.searchManager.snippets(entry.body, 'craft cms', {
    snippetLength: 200,
    maxSnippets: 3,
}) %}

{% for snippet in snippets %}
    <p>{{ snippet|raw }}</p>
    {# Output: "...tutorial about <mark>craft</mark> <mark>cms</mark> development..." #}
{% endfor %}
```

## Styling

The default `<mark>` tag has browser-default styling (yellow background). You can customize it with CSS:

```css
mark {
    background-color: #ffeb3b;
    padding: 2px 4px;
    border-radius: 2px;
}
```

Or use a custom class:

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

See the [Highlighting & Snippets](../template-guides/highlighting-snippets.md) template guide for complete implementation examples.
