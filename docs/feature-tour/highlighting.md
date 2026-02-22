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

## Destination Page Highlighting

The widget can also highlight search terms on the page a user navigates to after clicking a result. After the user clicks a result, the widget appends the search query to the destination URL, and the widget's script on the destination page reads that parameter and highlights matching terms in the page content.

This feature is independent from in-widget result highlighting (`enableHighlighting`), which wraps matched terms inside the search results list. Destination page highlighting applies to the actual content of the target page after navigation.

### How It Works

1. User types a search query and clicks a result
2. The widget appends the query to the destination URL (e.g., `/blog/my-post?smq=redis+performance`)
3. The widget script on the destination page reads the `smq` parameter on load
4. Matching terms in the configured content areas are wrapped in `<mark>` tags

### Configuration

These parameters control destination page highlighting. They can be set in the CP (Highlights tab), in the config file, or passed as Twig parameters per-include.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `highlightDestinationPage` | `bool` | `true` | Enable destination page highlighting |
| `persistQueryInUrl` | `bool` | `true` | Append the search query to the destination URL |
| `queryParamName` | `string` | `'smq'` | URL parameter name for the persisted query |
| `destinationHighlightSelector` | `string` | `'main, article, [data-search-content]'` | CSS selector for page content areas to scan |

> [!TIP]
> Change `queryParamName` if `smq` conflicts with an existing query parameter in your site. For example, set it to `'q'` or `'highlight'`.

> [!NOTE]
> `persistQueryInUrl` controls whether the query is appended to the URL at all. If disabled, the destination page cannot know what to highlight and no highlighting will occur, even if `highlightDestinationPage` is `true`.

### Multi-Widget Support

When multiple widgets are included on the same page, each widget registers independently using a keyed internal registry. Highlights from one widget will not be duplicated or overridden by another widget on the same page.

### CP Configuration

In the CP, destination page highlighting settings are on the **Highlights** tab of each widget config. The `highlightDestinationPage` toggle reveals or hides the sub-options (`persistQueryInUrl`, `queryParamName`, `destinationHighlightSelector`) when toggled off.
