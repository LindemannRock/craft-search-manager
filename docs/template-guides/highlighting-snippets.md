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

## Code Snippets @since(5.43.0)

By default, code blocks (`<pre>` elements) in your content are included in search results but excluded from snippet descriptions. The `showCodeSnippets` setting controls this behavior.

### How It Works

When content is indexed, the transformer automatically detects `<pre>` blocks and stores a second version of the content (`_contentClean`) with code removed. At display time, the enrichment service chooses which version to use for snippets:

- **`showCodeSnippets: false`** (default) — snippets are generated from `_contentClean`, so code blocks never appear in result descriptions
- **`showCodeSnippets: true`** — snippets are generated from the full content, including code

Code is always fully indexed and searchable regardless of this setting. A search for `querySelector` will find pages containing that term in code blocks — the setting only controls whether code appears in the snippet text shown to the user.

### Configuration

In the widget include:

```twig
{% include 'search-manager/_widget/search-modal' with {
    showCodeSnippets: false,
} %}
```

In the config file:

```php
// config/search-manager.php
'widgets' => [
    'docs-search' => [
        'settings' => [
            'behavior' => [
                'showCodeSnippets' => false,
            ],
        ],
    ],
],
```

Via the API:

```text
GET /actions/search-manager/api/search?q=querySelector&enrich=1&showCodeSnippets=0
```

### When to Enable

Enable `showCodeSnippets` when code is the primary content users are searching for — API references, code snippet libraries, or developer tools where seeing the matching code in the result description is more useful than seeing the surrounding prose.

Keep it disabled (the default) for documentation sites, blogs, and general content where code blocks are supplementary and prose snippets provide better context.

## Client-Side Highlighting

Search Manager provides a standalone JavaScript highlighter for use in custom search UIs — the same highlighter used by the [Search Widget](../widget/overview.md).

### Loading the Highlighter

Register the asset in your template:

```twig
{% do craft.searchManager.registerHighlighter() %}
```

This loads `SearchManagerHighlighter` on `window`.

### API

#### `highlight(text, query, options)`

Highlight matched terms in text. Returns an HTML string with matches wrapped in the specified tag.

```javascript
const html = SearchManagerHighlighter.highlight(
    'Getting Started with Craft CMS',
    'craft cms',
    { tag: 'mark', className: 'search-highlight' }
);
// → 'Getting Started with <mark class="sm-highlight search-highlight">Craft</mark> <mark class="sm-highlight search-highlight">CMS</mark>'
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | `boolean` | `true` | Set to `false` to return escaped text without highlights |
| `tag` | `string` | `'mark'` | HTML tag to wrap matches |
| `className` | `string` | `''` | Additional CSS class (always includes `sm-highlight`) |
| `terms` | `array\|null` | `null` | Explicit terms array (overrides query parsing). Use this for phrase highlighting — pass full phrases as array items (e.g., `['craft cms', 'search']`) |

#### `escapeHtml(text)`

Escape HTML special characters for safe output.

```javascript
SearchManagerHighlighter.escapeHtml('<script>alert("xss")</script>');
// → '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
```

#### `escapeRegex(string)`

Escape regex special characters.

```javascript
SearchManagerHighlighter.escapeRegex('test.*(value)');
// → 'test\\.\\*\\(value\\)'
```

#### `create(options)`

Create a reusable highlighter function with preset options.

```javascript
const hl = SearchManagerHighlighter.create({
    tag: 'span',
    className: 'my-highlight',
});

// Use the preset highlighter
const html = hl('Some text to highlight', 'text');
```

#### `parseQuery(query)` @since(5.43.0)

Parse a search query into an array of highlight-ready terms. Handles quoted phrases, boolean operators, field prefixes, wildcards, and boost markers.

```javascript
SearchManagerHighlighter.parseQuery('"craft cms" OR templates NOT draft');
// → ['craft cms', 'templates']

SearchManagerHighlighter.parseQuery('title:blog test^2 search*');
// → ['blog', 'test', 'search']
```

This is the same parser that `highlight()` uses internally when no explicit `terms` are passed. Useful for inspecting what terms will be highlighted or for building custom highlighting logic.

### Phrase Highlighting

When the search backend returns `matchedPhrases` and `matchedTerms` on each hit, pass them as the `terms` option for precise phrase-aware highlighting:

```javascript
// Backend returns hit.matchedPhrases = ["craft cms"] and hit.matchedTerms = {title: ["craft", "cms"]}
// Combine phrases first (for longest-match priority), then individual terms
const terms = [...(hit.matchedPhrases || []), ...(hit.matchedTerms?.title || [])];

const html = SearchManagerHighlighter.highlight(hit.title, query, { terms });
// "Getting Started with <mark>Craft CMS</mark>" (phrase highlighted as one unit)
```

Without explicit `terms`, the highlighter parses the query automatically — extracting quoted phrases as single terms, stripping operators (AND/OR/NOT), removing field prefixes and wildcards. This works well for standalone use. When backend-provided terms are available (as in the widget), pass them via `terms` for the most accurate results.

### Features

The JavaScript highlighter includes several smart features:

- **CamelCase splitting**: Searching "date" will highlight the "Date" part in "DateRangeHelper"
- **Longest-first matching**: Prevents nested/overlapping tags when longer and shorter terms overlap
- **Range merging**: Adjacent or overlapping matches are merged into a single highlighted span
- **HTML escaping**: All text is escaped before inserting highlight tags, preventing XSS

### Example: Custom Search UI

```twig
{% do craft.searchManager.registerHighlighter() %}

<input type="text" id="search-input" placeholder="Search...">
<div id="results"></div>

<script>
document.getElementById('search-input').addEventListener('input', async function() {
    const query = this.value.trim();
    if (query.length < 2) return;

    const response = await fetch('/actions/search-manager/search/query', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '{{ craft.app.request.csrfToken }}',
        },
        body: JSON.stringify({ query, indexHandle: 'entries-en' }),
    });
    const data = await response.json();

    document.getElementById('results').innerHTML = data.hits.map(hit =>
        `<div class="result">
            <h3>${SearchManagerHighlighter.highlight(hit.title, query)}</h3>
            <p>${SearchManagerHighlighter.highlight(hit.excerpt || '', query)}</p>
        </div>`
    ).join('');
});
</script>
```
