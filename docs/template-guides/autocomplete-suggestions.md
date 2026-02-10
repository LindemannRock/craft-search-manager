# Autocomplete & Suggestions

This guide shows how to add search-as-you-type suggestions to your templates.

## Twig Suggestions

The simplest approach — render suggestions server-side:

```twig
{% set query = craft.app.request.getParam('q') %}

{% if query and query|length >= 2 %}
    {% set suggestions = craft.searchManager.suggest(query, 'entries-en') %}

    {% if suggestions|length %}
        <ul class="suggestions">
            {% for suggestion in suggestions %}
                <li><a href="{{ url('search', {q: suggestion}) }}">{{ suggestion }}</a></li>
            {% endfor %}
        </ul>
    {% endif %}
{% endif %}
```

### With Options

```twig
{% set suggestions = craft.searchManager.suggest(query, 'entries-en', {
    limit: 5,
    minLength: 2,
    fuzzy: true,
    language: 'en',
}) %}
```

## AJAX Autocomplete

For a real-time experience, use the API endpoint with JavaScript:

```html
<div class="search-container">
    <input type="search" id="search-input" placeholder="Search..." autocomplete="off">
    <div id="suggestions" class="suggestions-dropdown" hidden></div>
</div>

<script>
const input = document.getElementById('search-input');
const dropdown = document.getElementById('suggestions');
let debounceTimer;

input.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    const query = e.target.value.trim();

    if (query.length < 2) {
        dropdown.hidden = true;
        return;
    }

    debounceTimer = setTimeout(async () => {
        const response = await fetch(
            `/actions/search-manager/api/autocomplete?q=${encodeURIComponent(query)}&index=entries-en&only=suggestions`
        );
        const suggestions = await response.json();

        if (suggestions.length) {
            dropdown.innerHTML = suggestions.map(s =>
                `<a href="/search?q=${encodeURIComponent(s)}" class="suggestion-item">${s}</a>`
            ).join('');
            dropdown.hidden = false;
        } else {
            dropdown.hidden = true;
        }
    }, 300);
});

// Close on click outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.search-container')) {
        dropdown.hidden = true;
    }
});
</script>
```

## Rich Autocomplete (Suggestions + Results)

The API can return both term suggestions and matching elements:

```html
<input type="search" id="search-input" placeholder="Search...">
<div id="autocomplete-results"></div>

<script>
const input = document.getElementById('search-input');
const results = document.getElementById('autocomplete-results');
let debounceTimer;

input.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    const query = e.target.value.trim();

    if (query.length < 2) {
        results.hidden = true;
        return;
    }

    debounceTimer = setTimeout(async () => {
        const response = await fetch(
            `/actions/search-manager/api/autocomplete?q=${encodeURIComponent(query)}&index=entries-en`
        );
        const data = await response.json();

        let html = '';

        // Term suggestions
        if (data.suggestions?.length) {
            html += '<div class="suggestion-group">';
            html += '<h4>Suggestions</h4>';
            data.suggestions.forEach(s => {
                html += `<a href="/search?q=${encodeURIComponent(s)}" class="suggestion-item">${s}</a>`;
            });
            html += '</div>';
        }

        // Element results
        if (data.results?.length) {
            html += '<div class="result-group">';
            html += '<h4>Results</h4>';
            data.results.forEach(r => {
                html += `<a href="/search?q=${encodeURIComponent(r.text)}" class="result-item">
                    <span class="type">${r.type}</span>
                    <span class="text">${r.text}</span>
                </a>`;
            });
            html += '</div>';
        }

        results.innerHTML = html;
        results.hidden = !html;
    }, 300);
});
</script>
```

## API Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `q` | (required) | Search query |
| `index` | `all-sites` | Index handle |
| `limit` | `10` | Maximum suggestions/results |
| `siteId` | (all sites) | Filter to a specific site |
| `language` | (auto) | Language code |
| `only` | (none) | Return only `suggestions` or `results` |
| `type` | (none) | Filter results by element type |

See [API Endpoints](api-endpoints.md) for full documentation.

## Styling

Basic CSS for the autocomplete dropdown:

```css
.search-container {
    position: relative;
}

.suggestions-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    max-height: 300px;
    overflow-y: auto;
    z-index: 100;
}

.suggestion-item {
    display: block;
    padding: 8px 12px;
    text-decoration: none;
    color: inherit;
}

.suggestion-item:hover {
    background: #f5f5f5;
}
```

## Using the Search Widget Instead

For a complete out-of-the-box solution with autocomplete, keyboard navigation, themes, and analytics, consider using the [Frontend Widget](../feature-tour/frontend-widget.md) instead of building your own.
