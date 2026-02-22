# JavaScript API

The search widget exposes a JavaScript API for programmatic control and event handling. This is useful for custom integrations, triggering search from other components, or responding to search activity.

## Accessing the Widget

The widget is a web component registered as `<search-modal>`. Access it via standard DOM methods:

```javascript
const widget = document.querySelector('search-modal');
```

## Methods

### `open()`

Open the search modal programmatically.

```javascript
widget.open();
```

### `close()`

Close the search modal.

```javascript
widget.close();
```

### `toggle()`

Toggle the modal open or closed.

```javascript
widget.toggle();
```

## Events

The widget dispatches custom events prefixed with `search-`. All events bubble and are composed (cross shadow DOM boundaries).

### `search-open`

Fired when the modal opens.

```javascript
widget.addEventListener('search-open', (e) => {
    console.log('Search opened', e.detail.source);
});
```

**Detail:** `{ source: 'programmatic' | 'hotkey' | 'trigger' }`

### `search-close`

Fired when the modal closes.

```javascript
widget.addEventListener('search-close', () => {
    console.log('Search closed');
});
```

### `search-search`

Fired when a search is executed and results are returned.

```javascript
widget.addEventListener('search-search', (e) => {
    const { query, results, meta } = e.detail;
    console.log(`Found ${results.length} results for "${query}"`);
});
```

**Detail:** `{ query: string, results: array, meta: object }`

### `search-result-click`

Fired when a user clicks a search result.

```javascript
widget.addEventListener('search-result-click', (e) => {
    const { id, title, url, query, isRecent } = e.detail;
    console.log(`Clicked: ${title} (${url})`);
});
```

**Detail:** `{ id: string, title: string, url: string, query: string, isRecent: boolean }`

### `search-error`

Fired when a search request fails.

```javascript
widget.addEventListener('search-error', (e) => {
    console.error('Search failed:', e.detail.error);
});
```

**Detail:** `{ query: string, error: string }`

## Examples

### Custom Analytics Integration

Track search activity with a third-party analytics service:

```javascript
const widget = document.querySelector('search-modal');

widget.addEventListener('search-search', (e) => {
    gtag('event', 'search', {
        search_term: e.detail.query,
        results_count: e.detail.results.length,
    });
});

widget.addEventListener('search-result-click', (e) => {
    gtag('event', 'select_content', {
        content_type: 'search_result',
        content_id: e.detail.id,
    });
});
```

### Open Search from a Custom Button

```javascript
document.getElementById('my-button').addEventListener('click', () => {
    document.querySelector('search-modal').open();
});
```

### Respond to Search State

```javascript
const widget = document.querySelector('search-modal');

widget.addEventListener('search-open', () => {
    document.body.classList.add('search-active');
});

widget.addEventListener('search-close', () => {
    document.body.classList.remove('search-active');
});
```

## Shadow DOM

The widget uses Shadow DOM to encapsulate its styles. This means:

- Widget styles don't leak into your page
- Your page styles don't affect the widget
- You cannot directly style internal elements with external CSS

To customize appearance, use [Widget Styles](styles.md) or the `styles` Twig parameter. The widget exposes CSS custom properties that you can override from outside the shadow DOM.

## Keyboard Shortcuts

The widget registers a global keyboard listener for the configurable hotkey (default: CMD+K / Ctrl+K). This listener is active whenever the widget is in the DOM.

| Key | Action |
|-----|--------|
| CMD+K / Ctrl+K | Toggle modal (configurable via `hotkey`) |
| Arrow Up/Down | Navigate results |
| Enter | Select highlighted result |
| Escape | Close modal |

## Standalone Highlighter

The same highlighting logic used internally by the widget is available as a standalone utility for custom search UIs. See [Client-Side Highlighting](../template-guides/highlighting-snippets.md#client-side-highlighting) for details.

```twig
{% do craft.searchManager.registerHighlighter() %}

<script>
const html = SearchManagerHighlighter.highlight('My page title', 'page');
</script>
```
