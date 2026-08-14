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

## Which query words are highlighted?

In a **results list** — the bundled widget's own, or one you render from the matched-term metadata on each hit — Search Manager highlights what each result actually matched, not every word the visitor typed. On the built-in MySQL, PostgreSQL, Redis, and File backends, these rules work together:

- Unquoted adjacent terms use **AND** by default. If the strict intersection has no results, the built-in engine can broaden to related results as described in [Multi-word query returns nothing](../resources/troubleshooting.md#multi-word-query-returns-nothing-or-related-results).
- Hyphens and other punctuation are token boundaries. For example, `built-in` is processed as `built` and `in`.
- When stop-word filtering is enabled, common words such as `in` and `from` are removed from ordinary matching and therefore from hit-driven highlighting.
- A quoted query is a contiguous phrase. Its complete matched phrase can be highlighted, including words that would otherwise be stop words.

For example, the unquoted query `Choose from 7 search backends` can match a heading while `from` remains unhighlighted. The quoted query `"Choose from 7 search backends"` requires that contiguous phrase and may highlight the complete phrase.

Boolean and field operators narrow this further. With `alpha OR beta`, each result highlights only the operand or operands that matched that result. A `NOT` operand is excluded and is not highlighted. Explicit `title:` and `content:` scopes remain restrictive: a title-only term does not paint a snippet, and a content-only term does not paint a normal page title.

Split-section results follow the same per-hit rule. Whether the result is a flat split row or an H2/H3 child in a hierarchical layout, every term that actually matched and occurs in the displayed heading is highlighted. The parent page title and snippets keep their own field-specific highlighting.

These query semantics belong to the built-in backends. Algolia, Meilisearch, and Typesense receive the original query unchanged and apply their native query syntax. See [Advanced operators](../template-guides/advanced-operators.md) for the complete backend boundary and [Multi-language support](multi-language.md#stop-words) for stop-word controls.

### On the destination page

Everything above is about a results list, where the widget knows which terms each hit matched because the search response tells it. A destination page has none of that: a visitor arrives with nothing but a query string in the URL. So destination highlighting applies a deliberately simpler rule.

Search Manager parses the query out of the URL — quoted phrases stay whole, boolean operators and any `NOT` operand are dropped, and wildcards, boost markers, and `title:`/`content:` prefixes are stripped — then marks every remaining term of two or more characters wherever it appears in the scanned content. (Localized operators are recognized from the `lang` attribute on the page's `<html>` element, falling back to English.) Three differences from the results list are worth knowing:

- **Stop words are not filtered.** In the `Choose from 7 search backends` example above, `from` stays unhighlighted in the results list but *is* highlighted on the destination page.
- **Field scopes are not applied.** `title:release` highlights "release" in body text too, because a destination page has no field metadata to scope against.
- **Matching is plain and case-insensitive, not word-aware.** A term also paints inside longer words, so `?smq=test` marks the "test" in "latest" — where the results list would leave it alone.

This is a reading aid, not a second search: it shows visitors roughly where their words appear on the page they landed on. If you need the exact matched-term rules on the destination page, render the highlighting yourself with `craft.searchManager.highlight()` — see [Highlighting text in Twig](#highlighting-text-in-twig) and [Highlighting matches on the destination page](#highlighting-matches-on-the-destination-page).

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

1. A visitor types a search query and clicks a result.
2. The widget appends the query to the destination URL (for example, `/blog/my-post?smq=redis+performance`).
3. On the destination page, a `<search-modal>` element reads the `smq` parameter as it mounts.
4. Matching terms inside the configured content areas are wrapped in `<mark class="sm-highlight sm-page-highlight">`.

> [!IMPORTANT]
> Step 3 is the one that catches people out. Destination highlighting runs from the widget element itself — there's no separate site-wide script doing it in the background. **The page a visitor lands on has to include the widget too.** Put `{% include 'search-manager/_widget/search-modal' %}` in a shared layout rather than only on the pages where people start a search; otherwise you'll search from a page that has the widget, click through to one that doesn't, and get nothing.

The widget on the destination page reads the parameter using **its own** settings. If the two pages use different widget configs, the query parameter name has to match on both, and the content selector has to fit the landing page's markup.

> [!TIP]
> Change the query parameter if `smq` conflicts with an existing one on your site — for example, set it to `q` or `highlight`.

> [!NOTE]
> Highlighting only happens if the search query is actually appended to the destination URL. If that's turned off, the destination page has no way to know what to highlight, even with destination highlighting itself enabled.

These options live on each widget config's **Destination Highlighting** tab, in a config-file widget override, or as Twig parameters per-include. See [Widget Configuration → Destination Highlighting](../widget/configuration.md#destination-highlighting) for the full parameter table, including how the master toggle gates the other three.

When multiple widgets are included on the same page, each one registers independently using a keyed internal registry — highlights from one widget will not be duplicated or overridden by another.

For what gets marked once the parameter is read — and how it differs from the results list — see [On the destination page](#on-the-destination-page).

#### Which parts of the page are scanned

`highlightDestinationContentSelector` decides where the widget looks. Its default, `main, article, [data-search-content]`, covers the two standard HTML5 content elements plus an opt-in hook.

Watch the first one: `main` matches the `<main>` **element**, not `<div id="main">`. If your layout wraps content in a non-semantic container, the selector matches nothing — and the widget stops silently. There's no error, no console warning, and nothing highlighted, which looks identical to "the feature isn't working." Two ways to fix it:

- Add `data-search-content` to the wrapper you want scanned. That attribute is already in the default selector, so nothing needs configuring:

  ```twig
  <div id="main" class="content" data-search-content>
      {{ entry.body }}
  </div>
  ```

- Or point the setting at a selector that matches your markup, such as `#main, .content`.

Everything inside a matched element is scanned except `<script>`, `<style>`, `<noscript>`, and `<textarea>` content, text that already sits inside a `<mark>` or an existing highlight element, and anything inside a nested `<search-modal>`.

#### Styling destination highlights

Destination highlights are styled separately from the widget's own results. A widget style preset — including its **Result Highlighting** colors — sets its custom properties on the `<search-modal>` element itself, so they reach the widget and everything inside it but never the rest of the page. Instead, the first time the widget highlights a page it injects one global rule:

```css
.sm-page-highlight {
    background: var(--sm-highlight-bg, #fef08a);
    color: var(--sm-highlight-color, #854d0e);
    border-radius: 0.15em;
    padding: 0 0.08em;
}
```

To match your branding, define those two custom properties globally in your site CSS:

```css
:root {
    --sm-highlight-bg: #cbe4ff;
    --sm-highlight-color: #0b3d66;
}
```

Custom properties are the reliable hook here: the widget's rule is appended to `<head>` at runtime, so it wins over a same-specificity `.sm-page-highlight` rule in your own stylesheet. To change anything the properties don't cover — padding, radius, font weight — use a more specific selector such as `mark.sm-page-highlight`.

#### Highlighting a destination page without the widget

The turnkey behavior above is widget-only, but you're not stuck if you've built your own search UI on the REST or GraphQL API. Two supported paths give you the same end result with a little more wiring — you read the query parameter and decide what to scan; Search Manager supplies the highlighting.

- **Server-side, in Twig.** Read the parameter and wrap the text as you render it, using the same helper described in [Highlighting text in Twig](#highlighting-text-in-twig):

  ```twig
  {% set highlightQuery = craft.app.request.getParam('smq') %}

  <h1>
      {%- if highlightQuery -%}
          {{ craft.searchManager.highlight(entry.title, highlightQuery, { field: 'title' })|raw }}
      {%- else -%}
          {{ entry.title }}
      {%- endif -%}
  </h1>
  ```

  Guard for the parameter being absent, as above — the helper expects a query, not `null`. And remember it strips HTML from whatever you give it unless you pass `stripTags: false`, so aim it at plain-text values (titles, summaries, plain-text custom fields) rather than at rich-text body content.

- **Client-side, with the standalone highlighter.** `craft.searchManager.registerHighlighter()` loads `window.SearchManagerHighlighter` independently of the widget, so a page with no `<search-modal>` on it can still highlight. Walk the elements you care about and pass their text through `highlight()` — see [Client-side highlighting](../template-guides/highlighting-snippets.md#client-side-highlighting).

Either way, something has to put the parameter on the URL in the first place. If your result links come from the bundled widget, keep both **Enable Destination Highlighting** and **Persist Query in URL** on for it. If they come from your own results UI, append the parameter there.

Both paths hand you the highlighting primitive and leave the URL read and DOM traversal to you. The widget is the only thing that does the whole job — read the parameter, find the content areas, mark the terms, skip what shouldn't be touched, and inject the styles.
