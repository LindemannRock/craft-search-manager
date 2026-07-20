# Highlighting & Snippets

Show visitors exactly where their search terms matched. Search Manager wraps matched words in a tag of your choosing and pulls short excerpts of surrounding text — so results, and the page a visitor lands on after clicking one, show the hit in context.

There are two independent ways to get this: highlight and snippet text yourself in PHP/Twig (or read plain-text snippets off the REST/GraphQL response), or let the frontend search widget do it for you — both in its results list and on the destination page after a click. Pick the section below that matches how you're building your search experience.

## What you'll use it for

- Wrap matched words in `<mark>` (or another tag) inside your own search results template
- Show a short excerpt of surrounding text so visitors see the match in context before they click
- Let the built-in search widget highlight matches in its results list automatically
- Highlight the same search terms on the page a visitor lands on after clicking a result
- Match the highlight styling to your site's branding with a custom CSS class

Both highlighting and snippets work on any text you pass in — they're not limited to indexed fields. You can highlight titles, body content, custom fields, or any string.

## Server-side highlighting

For PHP/Twig templates, and for reading the REST/GraphQL response directly.

### CP settings

Set the defaults in the Control Panel — no code required:

- **Settings → Highlighting** — the **Result Highlighting Enabled** lightswitch turns highlighting on or off by default; **HTML Tag** picks what wraps a match (`<mark>`, `<em>`, `<strong>`, `<span>`, `<b>`, or `<i>`); **CSS Class** adds an optional class. A live preview shows the exact HTML output as you change either field.
- **Settings → Snippets** — **Snippet Length** (50–1000 characters, default 200) and **Max Snippets** (1–10, default 3) control the `craft.searchManager.snippets()` template helper specifically. Widget and API snippets are configured per widget or per request — see [Widget highlighting](#widget-highlighting) below.

Each of these maps to a config-file setting, and locks (with a warning) when a `config/search-manager.php` entry overrides it:

```php
// config/search-manager.php
'highlightResultsEnabled' => true,
'highlightTag' => 'mark',       // HTML tag: mark, em, strong, b, i, span
'highlightClass' => null,       // Optional CSS class
'snippetMaxLength' => 200,         // Characters per snippet
'maxSnippets' => 3,             // Max snippets per result
```

### Highlighting text in Twig

Highlighting wraps matched search terms with an HTML tag (default: `<mark>`). Exact and typo-corrected matches paint the whole matched word, while a prefix extension paints only the part the visitor typed: `test` in "Testing" becomes `<mark>Test</mark>ing`. Matches must begin at a word boundary, so `to` never paints the middle of "stop".

```twig
{% set results = craft.searchManager.search('entries', 'craft cms') %}

{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.elementId).one() %}

    {# Highlight matched terms in the title #}
    <h2>{{ craft.searchManager.highlight(entry.title, query, { field: 'title' })|raw }}</h2>
    {# Output: This is about <mark>craft</mark> <mark>cms</mark> #}
{% endfor %}
```

When the query can contain `title:` or `content:`, pass `field: 'title'` for titles and `field: 'content'` for body text or snippets. Unscoped terms paint both areas; scoped terms paint only their matching area; and no eligible terms means nothing is painted. Leave `field` unset only when the legacy scope-blind behavior is intentional.

### Custom options

```twig
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',
    class: 'search-highlight',
    stripTags: true,
    field: 'content',
})|raw }}
```

### Generating snippets

Snippets extract portions of text around matched terms so users can see the match in context:

```twig
{% set snippets = craft.searchManager.snippets(entry.body, 'craft cms', {
    snippetMaxLength: 200,
    maxSnippets: 3,
}) %}

{% for snippet in snippets %}
    <p>{{ snippet|raw }}</p>
    {# Output: "...tutorial about <mark>craft</mark> <mark>cms</mark> development..." #}
{% endfor %}
```

### Styling

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

Then style that class in your frontend CSS:

```css
.search-highlight {
    background-color: #ff9800;
    color: #fff;
    padding: 1px 3px;
    border-radius: 2px;
}
```

### REST and GraphQL

The REST and GraphQL search endpoints return `snippet` (and `headings[].snippet`) as plain text — Search Manager does not wrap matched terms in these responses. Apply highlighting in your own client code. GraphQL additionally accepts `highlightTag`, `highlightClass`, `highlightResultsEnabled`, and the `highlightDestination*` arguments, but they're reserved for client renderers and are not applied server-side.

See [API Endpoints](../template-guides/api-endpoints.md) and [GraphQL](../developers/graphql.md#search) for the full response shape.

See the [Highlighting & Snippets](../template-guides/highlighting-snippets.md) template guide for complete implementation examples.

## Widget highlighting

The frontend search widget handles highlighting for you — no Twig helpers needed. It has two independent behaviors, configured in two different places.

### Highlighting matches in the results list

The widget's **Result Highlighting** style options wrap matched terms in results the same way `craft.searchManager.highlight()` does — with their own **Result Highlighting Enabled** toggle, **HTML Tag** (or **Use global default**, which falls back to the Settings → Highlighting choice above), **CSS Class**, and light/dark colors. These live in the widget's **Style** editor, not on the widget config's tabs. The widget applies this client-side while rendering; the search response itself always returns plain snippet text, per [REST and GraphQL](#rest-and-graphql) above.

See [Widget Configuration → Result Highlighting](../widget/configuration.md#result-highlighting) for every option.

### Highlighting matches on the destination page

After a visitor clicks a result, the widget can also highlight the same search terms on the page they land on — independent of the in-results highlighting above (`highlightResultsEnabled`), which only affects the results list itself.

1. User types a search query and clicks a result.
2. The widget appends the query to the destination URL (e.g., `/blog/my-post?smq=redis+performance`).
3. The widget script on the destination page reads the `smq` parameter on load.
4. Matching terms in the configured content areas are wrapped in `<mark>` tags.

> [!TIP]
> Change the query parameter if `smq` conflicts with an existing one on your site — for example, set it to `q` or `highlight`.

> [!NOTE]
> Highlighting only happens if the search query is actually appended to the destination URL. If that's turned off, the destination page has no way to know what to highlight, even with destination highlighting itself enabled.

These options live on each widget config's **Destination Highlighting** tab, in a config-file widget override, or as Twig parameters per-include. See [Widget Configuration → Destination Highlighting](../widget/configuration.md#destination-highlighting) for the full parameter table.

When multiple widgets are included on the same page, each one registers independently using a keyed internal registry — highlights from one widget will not be duplicated or overridden by another.
