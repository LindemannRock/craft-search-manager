# Highlighting & snippets

Wrap matched terms in `<mark>` tags and show short, context snippets around them, so a result reads as "why this matched" instead of just a title and a link. This guide covers the server-side Twig helpers and the standalone JavaScript highlighter used by custom search UIs.

If you're using the bundled [Frontend Widget](../widget/overview.md), highlighting and snippets are already wired up — this guide is for template and JavaScript developers building their own results UI.

## Highlighting text

Use `craft.searchManager.highlight()` to wrap matched terms with an HTML tag:

```twig
{% set results = craft.searchManager.search('entries', query) %}

{% for hit in results.hits %}
    {% set entry = craft.entries.id(hit.elementId).one() %}
    {% if entry %}
        <h3>{{ craft.searchManager.highlight(entry.title, query, { field: 'title' })|raw }}</h3>
        {# "About <mark>craft</mark> <mark>cms</mark> development" #}
    {% endif %}
{% endfor %}
```

The `|raw` filter is required because highlighting inserts HTML tags.

For query strings that use `title:` or `content:`, pass the area being rendered as `field: 'title'` or `field: 'content'`. Unscoped terms remain eligible in both areas, terms scoped to the other area are ignored, and a query with no eligible terms leaves the text unhighlighted. Omitting `field` keeps the legacy scope-blind output.

Painting follows word starts. Exact and typo-corrected matches paint the whole matched word (`jaket` → `<mark>jacket</mark>`), while strict prefix extensions paint only the typed prefix (`test` → `<mark>Test</mark>ing`, `tool` → `<mark>Tool</mark>s`). Mid-word substrings never paint, and a whole-word range wins if it overlaps a shorter prefix range.

### Custom options

```twig
{{ craft.searchManager.highlight(text, query, {
    tag: 'em',                    // Use <em> instead of <mark>
    class: 'search-highlight',    // Add a CSS class
    stripTags: true,              // Strip existing HTML before highlighting
    field: 'content',              // Respect title:/content: query scope
})|raw }}
```

## Generating snippets

Use `craft.searchManager.snippets()` to extract text excerpts around matched terms:

```twig
{% set snippets = craft.searchManager.snippets(entry.body, query, {
    snippetMaxLength: 200,
    maxSnippets: 3,
}) %}

{% for snippet in snippets %}
    <p class="snippet">...{{ snippet|raw }}...</p>
{% endfor %}
```

Each snippet is a string with matched terms already highlighted.

## Search result snippets @since(5.53.0)

When you call `craft.searchManager.search()`, `craft.searchManager.searchMultiple()`, the REST API, or GraphQL, Search Manager returns presented hits with a plain-text `snippet`. Matched headings can also include their own plain-text `snippet`:

```json
{
    "snippet": "A field excerpt with craft in context",
    "headings": [
        {
            "title": "Installation",
            "id": "installation",
            "level": 2,
            "url": "/docs/getting-started#installation",
            "snippet": "Install Craft before configuring search."
        }
    ]
}
```

The top-level `snippet` is the best match-centered excerpt from eligible searchable custom fields in the private snippet source, then from the dedicated indexed clean body. Heading snippets are dynamic excerpts from the matching heading section in the indexed clean body.

Search Manager does not build these result snippets from title, slug, URL, SKU, native identity values, live element fields, or the flattened content bag. If no eligible field or body text contains the query, `snippet` is `null`.

`snippet` and `headings.*.snippet` are plain text. Render them as text and apply highlighting in your frontend when needed.

```twig
{% if hit.snippet %}
    <p class="snippet">{{ hit.snippet }}</p>
{% endif %}
```

The bundled widget highlights titles and snippets client-side with its configured `highlightTag` and `highlightClass`. Direct API consumers should use the same client-side approach:

```text
GET /actions/search-manager/api/search?q=craft
```

Twig templates pass the same snippet options through the search call:

```twig
{% set results = craft.searchManager.search('entries-en', query, {
    snippetMode: 'balanced',
    snippetMaxLength: 180,
    snippetIncludeCodeBlocks: false,
    snippetCleanMarkdown: true,
}) %}
```

## Complete search results template

```twig
{% set query = craft.app.request.getParam('q') %}

{% if query %}
    {% set results = craft.searchManager.search('entries-en', query, {
        snippetMaxLength: 180,
        snippetCleanMarkdown: true,
    }) %}

    {% for hit in results.hits %}
        <article class="search-result">
            <h3>
                <a href="{{ hit.url }}">
                    {{ craft.searchManager.highlight(hit.title, query, { field: 'title' })|raw }}
                </a>
            </h3>

            {% if hit.snippet %}
                <p class="snippet">{{ hit.snippet }}</p>
            {% endif %}

            {% if hit.headings|length %}
                <ul class="matched-headings">
                    {% for heading in hit.headings %}
                        <li>
                            <a href="{{ heading.url ?? hit.url }}">{{ heading.title }}</a>
                            {% if heading.snippet %}
                                <span>{{ heading.snippet }}</span>
                            {% endif %}
                        </li>
                    {% endfor %}
                </ul>
            {% endif %}

            <small>
                <a href="{{ hit.url }}">{{ hit.url }}</a>
            </small>
        </article>
    {% endfor %}
{% endif %}
```

## CSS styling

### Default `<mark>` tag

```css
mark {
    background-color: #ffeb3b;
    padding: 2px 4px;
    border-radius: 2px;
}
```

### Custom class

Configure in your config file:

```php
'highlightClass' => 'search-highlight',
```

Then style the class in your frontend CSS:

```css
.search-highlight {
    background-color: #ff9800;
    color: #fff;
    padding: 1px 3px;
    border-radius: 2px;
}
```

### Snippet styling

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

## Configuration defaults

These settings apply when you don't pass options to the template functions:

```php
// config/search-manager.php
'highlightResultsEnabled' => true,
'highlightTag' => 'mark',
'highlightClass' => null,
'snippetMaxLength' => 200,
'maxSnippets' => 3,
```

Per-call options override these defaults.

## Code snippets @since(5.39.0)

By default, block-level code in your content is included in search results but excluded from result snippets. That includes HTML `<pre>` blocks and fenced Markdown code blocks. The `snippetIncludeCodeBlocks` setting controls this behavior.

### How it works

When custom field content is indexed, Search Manager keeps searchable field text in a private snippet source. The index's `retrievableFields` setting controls which of those values appear under public API/GraphQL `fields`, but snippets can still use searchable field values that are omitted from the public payload. Docs Manager SourceDoc records and AutoTransformer-family section records also store an internal code-included body alongside the normal code-free body after indexing. At display time, Search Manager chooses whether to include block-level code while building snippets from those stored values:

- **`snippetIncludeCodeBlocks: false`** (default) — block-level code is removed before building result snippets
- **`snippetIncludeCodeBlocks: true`** — snippets include block-level code content

Inline code spans are sentence content, so their text is always preserved in snippets. Code can still be searchable when it is present in searchable indexed content. The setting only controls whether block-level code appears in the snippet text shown to the user.

Page-mode docs, rich Entry records, and product records with long rich-text descriptions can be large on external backends; for Algolia-backed long-form content, prefer Split Sections so long documents are stored as smaller section records.

For Markdown-heavy fields, `snippetCleanMarkdown` is a display cleanup option. It strips common Markdown markers such as headings, emphasis, horizontal rules, list markers, and inline-code backticks from the plain-text snippet. It does not render Markdown, modify stored/indexed data, or run against genuine HTML rich text.

### Configuration

In the widget include:

```twig
{% include 'search-manager/_widget/search-modal' with {
    snippetIncludeCodeBlocks: false,
} %}
```

In the config file:

```php
// config/search-manager.php
'widgets' => [
    'docs-search' => [
        'settings' => [
            'behavior' => [
                'snippetIncludeCodeBlocks' => false,
            ],
        ],
    ],
],
```

Via the API:

```text
GET /actions/search-manager/api/search?q=querySelector&snippetIncludeCodeBlocks=0
```

Via Twig:

```twig
{% set results = craft.searchManager.search('docs', query, {
    snippetIncludeCodeBlocks: false,
    snippetCleanMarkdown: true,
}) %}
```

### When to enable

Enable `snippetIncludeCodeBlocks` when code is the primary content users are searching for — API references, code snippet libraries, or developer tools where seeing the matching code in the result snippet is more useful than seeing the surrounding prose.

Keep it disabled (the default) for documentation sites, blogs, and general content where code blocks are supplementary and prose snippets provide better context.

## Client-side highlighting @since(5.39.0)

Search Manager provides a standalone JavaScript highlighter for use in custom search UIs — the same highlighter used by the [Search Widget](../widget/overview.md).

### Loading the highlighter

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
| `terms` | `array\|null` | `null` | Explicit matched terms array. These determine which words are eligible; the query still supplies raw tokens for prefix-extension painting. Use full phrases as array items for phrase highlighting (e.g., `['craft cms', 'search']`) |

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

#### `parseQuery(query, field = null, language = 'en')`

Parse a search query into an array of highlight-ready terms. It handles quoted phrases, boolean operators, field prefixes, wildcards, and boost markers. Pass `field` as `'title'` or `'content'` to retain only terms eligible for that display area. Pass the result language when localized operators may appear; regional forms such as `nl-NL` are normalized to their base language. English operators are always recognized as a fallback.

```javascript
SearchManagerHighlighter.parseQuery('"craft cms" OR templates NOT draft');
// → ['craft cms', 'templates']

SearchManagerHighlighter.parseQuery('offen NICHT entwurf', null, 'de-DE');
// → ['offen']

SearchManagerHighlighter.parseQuery('title:blog content:tutorial search', 'title');
// → ['blog', 'search']
```

When `highlight()` has no explicit `terms`, it uses this parser with its default English language. For localized operators, parse with the correct language and pass the returned array through `terms`, or use the hit-aware helper below.

#### `getHitTerms(hit, area, query, displayedText = '')` @since(5.53.2)

Resolve the terms that one result actually matched. Use `area` values `'title'`, `'heading'`, or `'snippet'`; pass `displayedText` for a split heading so terms are projected onto that exact H2/H3 label. The helper reads `hit.language`, `hit.matchedTerms`, and `hit.matchedPhrases`, excludes NOT operands, preserves restrictive field scopes, and returns an empty array when nothing is eligible.

For built-in backends, `matchedTerms` is already narrowed independently for the displayed title and snippet area. When a literal query token occurs there, it wins over that token's fuzzy alternatives; when it does not occur, a backend-confirmed correction remains eligible. This is display metadata only and does not alter which hits were retrieved or how they were ranked. Hosted providers retain their safe provider-native metadata because Search Manager does not invent equivalent term provenance.

```javascript
const terms = SearchManagerHighlighter.getHitTerms(
    hit,
    hit.sectionType === 'heading' ? 'heading' : 'title',
    query,
    hit.sectionTitle || hit.title
);

const html = SearchManagerHighlighter.highlight(
    hit.sectionTitle || hit.title,
    query,
    { terms }
);
```

For OR queries, this highlights only the terms matched by that hit. For split results, it highlights every matched term that occurs in the displayed heading. See [Which query words are highlighted?](../feature-tour/highlighting.md#which-query-words-are-highlighted) for the canonical behavior, including stop words, hyphens, phrases, and field scopes.

#### `highlightFromUrl(options)` @since(5.55.0)

Apply destination-page highlighting without placing a `<search-modal>` on the page. The method reads the query from the current URL, waits for DOM readiness when needed, scans the configured scopes, and returns a Promise with an inspectable result.

```twig
{% do craft.searchManager.registerHighlighter() %}

{% js %}
SearchManagerHighlighter.highlightFromUrl().then((highlightResult) => {
    if (highlightResult.status === 'invalid-selector') {
        console.error('Check the destination highlight selector.', highlightResult.reason);
    }
});
{% endjs %}
```

Loading the standalone asset does not activate destination highlighting. The call is always explicit.

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `param` | `string` | `'smq'` | URL query-parameter name |
| `selector` | `string` | `'main, article, [data-search-content]'` | CSS selector for content scopes |
| `force` | `boolean` | `false` | Re-scan after an identical successful call, for content added by an SPA |

Every result includes `status`, `param`, `selector`, `query`, `language`, `scopeCount`, `markCount`, and `removedMarkCount`. `markCount` is the number added by this call; `removedMarkCount` is the number of prior marks owned by the same channel that were restored to text. `reason` is included when diagnostic detail is available.

| Status | Meaning | Retained in the shared registry? |
|--------|---------|----------------------------------|
| `applied` | Eligible scopes were scanned; `markCount` can be zero when the terms do not occur | Yes, as the channel's current run |
| `duplicate` | An identical pending or completed run already owns the work | N/A — reports the existing claim |
| `superseded` | A newer run replaced this pending run before it could mutate the DOM | No — the newer run owns the channel |
| `no-query` | The URL has no non-empty value for the effective parameter; prior channel-owned marks are removed | No |
| `no-scopes` | The selector matched no elements | No new claim |
| `no-terms` | Parsing left no terms of at least two characters; prior channel-owned marks are removed | No |
| `query-too-long` | The URL query exceeds the 256-character public limit; prior channel-owned marks are removed | No |
| `invalid-selector` | The browser rejected the CSS selector | No |
| `unsupported-environment` | Required browser DOM APIs are unavailable | No |

The window-level registry is shared by the separately built widget and standalone bundles. A **channel** is the effective parameter plus selector; a **run** is that channel plus the trimmed query and normalized `<html lang>` value. Pending and applied calls for the same run are duplicates. If a new query or language arrives on the same channel, it supersedes pending work before that work can paint, removes the exact mark elements owned by the channel's prior run, restores their matched text as normalized text nodes, and then applies only the new terms. If the query disappears, exceeds the limit, or has no eligible terms, cleanup still happens before the corresponding result is returned.

Ownership is exact rather than class-based. Updating one channel never unwraps another channel's marks, and pre-existing author `<mark>`, `.sm-highlight`, or `.sm-page-highlight` elements are never claimed merely because their markup resembles Search Manager output. Failed/no-scope outcomes remain retryable. After a successful run, use `{ force: true }` only when dynamic content has been added inside an already-scanned scope; current-run and author highlight elements are skipped, so a forced retry adds marks only to new eligible text. The API does not watch SPA navigation or DOM mutations automatically.

This API deliberately uses the destination-page contract, not `highlight()`'s word-aware result matching: terms shorter than two characters and `NOT` operands are excluded; localized operators come from the page language; field prefixes are stripped but not enforced; remaining terms use case-insensitive substring matching. Script, style, noscript, textarea, existing mark/highlight, and nested widget content are excluded, while text inside `code` and `pre` remains eligible. Query terms are regex-escaped and new marks receive matched text through `textContent`.

The first applied run injects the `.sm-page-highlight` rule documented under [Styling destination highlights](../feature-tour/highlighting.md#styling-destination-highlights). Sites with a strict CSP that blocks inline styles should ship that rule in an allowed stylesheet; the marks still use `sm-highlight sm-page-highlight`.

### Phrase highlighting

When the search backend returns `matchedPhrases` and `matchedTerms` on each hit, pass them as the `terms` option for precise phrase-aware highlighting:

```javascript
// Backend returns hit.matchedPhrases = ["craft cms"] and hit.matchedTerms = {title: ["craft", "cms"], content: []}
// Combine phrases first (for longest-match priority), then individual terms
const terms = [...(hit.matchedPhrases || []), ...(hit.matchedTerms?.title || [])];

const html = SearchManagerHighlighter.highlight(hit.title, query, { terms });
// "Getting Started with <mark>Craft CMS</mark>" (phrase highlighted as one unit)
```

Without explicit `terms`, the highlighter parses the query automatically — extracting quoted phrases as single terms, removing recognized operators and NOT operands, and stripping field prefixes, wildcards, and boosts. This is convenient for standalone English queries. When backend-provided terms are available, prefer `getHitTerms()` so highlighting follows that result's language, match metadata, phrases, and field scope.

### Features

The JavaScript highlighter includes several smart features:

- **CamelCase splitting**: Searching "date" will highlight the "Date" part in "DateRangeHelper"
- **Word-start matching**: Exact and typo terms paint whole words; prefix extensions paint only the typed prefix; mid-word substrings are ignored
- **Longest-first matching**: Prevents nested/overlapping tags when longer and shorter ranges start together
- **Overlap resolution**: When candidate matches overlap, the earliest match wins and the longer whole-word range wins on the same word
- **HTML escaping**: All text is escaped before inserting highlight tags, preventing XSS

### Example: custom search UI

```twig
{% do craft.searchManager.registerHighlighter() %}

<input type="text" id="search-input" placeholder="Search...">
<div id="results"></div>

{% js %}
document.getElementById('search-input').addEventListener('input', async function() {
    const query = this.value.trim();
    if (query.length < 2) return;

    const response = await fetch(
        `/actions/search-manager/api/search?q=${encodeURIComponent(query)}&indexHandles=entries-en`
    );
    const data = await response.json();

    document.getElementById('results').innerHTML = data.hits.map(hit => {
        const titleTerms = SearchManagerHighlighter.getHitTerms(hit, 'title', query);
        const snippetTerms = SearchManagerHighlighter.getHitTerms(hit, 'snippet', query);

        return `<div class="result">
            <h3>${SearchManagerHighlighter.highlight(hit.title, query, { terms: titleTerms })}</h3>
            <p>${SearchManagerHighlighter.highlight(hit.snippet || '', query, { terms: snippetTerms })}</p>
        </div>`;
    }).join('');
});
{% endjs %}
```

## Next steps

- [Basic search](basic-search.md) — the results loop these helpers highlight and annotate
- [API endpoints](api-endpoints.md) — `snippetMode`, `snippetMaxLength`, and other snippet parameters for REST callers
- [Autocomplete & suggestions](autocomplete-suggestions.md) — pairs well with highlighting in a live dropdown
