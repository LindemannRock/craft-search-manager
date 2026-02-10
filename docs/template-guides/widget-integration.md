# Widget Integration

This guide walks through adding the Search Manager frontend widget to your site and customizing its appearance and behavior.

## Quick Start

The simplest integration — one line in your layout template:

```twig
{% include 'search-manager/_widget/search-modal' %}
```

This renders a trigger button and the search modal. Users press CMD+K (or Ctrl+K) or click the button to open search.

## Using a Widget Config

Create a widget configuration in the CP (Search Manager > Widgets) or config file, then reference it:

```twig
{% include 'search-manager/_widget/search-modal' with {
    config: 'main-search',
} %}
```

## Customizing Inline

Override specific settings without creating a config:

```twig
{% include 'search-manager/_widget/search-modal' with {
    indices: ['blog', 'products'],
    placeholder: 'Search articles and products...',
    theme: 'dark',
    maxResults: 12,
    showRecent: true,
    groupResults: true,
} %}
```

## Custom Trigger Button

Replace the built-in trigger with your own design:

```twig
{# Your custom search button #}
<button id="search-trigger" class="my-search-button">
    <svg><!-- search icon --></svg>
    Search
</button>

{# Widget with external trigger #}
{% include 'search-manager/_widget/search-modal' with {
    showTrigger: false,
    triggerSelector: '#search-trigger',
} %}
```

Any element matching the `triggerSelector` will open the modal when clicked.

## Theming

### Light and Dark Mode

```twig
{# Light mode (default) #}
{% include 'search-manager/_widget/search-modal' with {
    theme: 'light',
} %}

{# Dark mode #}
{% include 'search-manager/_widget/search-modal' with {
    theme: 'dark',
} %}
```

### Custom Colors

Override individual style properties:

```twig
{% include 'search-manager/_widget/search-modal' with {
    styles: {
        modalBg: '#1a1a1a',
        modalBorderColor: '#333',
        inputBg: '#2a2a2a',
        inputTextColor: '#fff',
        resultHoverBg: '#333',
        modalBorderRadius: '16',
    },
} %}
```

### Brand Colors via Config

For consistent branding, define styles in your config file:

```php
// config/search-manager.php
'widgets' => [
    'brand-search' => [
        'settings' => [
            'styles' => [
                // Light mode
                'modalBg' => '#ffffff',
                'modalBorderColor' => '#0066cc',
                // Dark mode
                'modalBgDark' => '#1a1a2e',
                'modalBorderColorDark' => '#4da6ff',
            ],
        ],
    ],
],
```

## RTL Support

For right-to-left languages:

```twig
{% include 'search-manager/_widget/search-modal' with {
    dir: 'rtl',
} %}
```

## Analytics Tracking

Track where searches come from by setting a source identifier:

```twig
{# Header search widget #}
{% include 'search-manager/_widget/search-modal' with {
    source: 'header-search',
} %}

{# Mobile navigation search #}
{% include 'search-manager/_widget/search-modal' with {
    source: 'mobile-nav',
} %}
```

The source appears in analytics so you can compare search behavior across placements.

### Idle Timeout

By default, a search is tracked after the user stops typing for 1.5 seconds. Adjust or disable:

```twig
{% include 'search-manager/_widget/search-modal' with {
    idleTimeout: 2000,  // 2 seconds
} %}

{# Disable idle tracking (only track on click/enter) #}
{% include 'search-manager/_widget/search-modal' with {
    idleTimeout: 0,
} %}
```

## Programmatic Control

Access the widget from JavaScript:

```javascript
const widget = document.querySelector('search-widget');

// Open/close/toggle
widget.open();
widget.close();
widget.toggle();
```

## Accessibility

The widget is WCAG 2.1 AA compliant:

- Proper ARIA labels and roles
- Full keyboard navigation (arrow keys, Enter, Escape)
- Focus trapping within the modal
- Respects `prefers-reduced-motion`
- All default colors meet 4.5:1 contrast ratio
- Shadow DOM isolation (won't conflict with your styles)

When overriding colors, check that you maintain sufficient contrast ratios.

## Multiple Widgets

You can include multiple widgets with different configs on the same page:

```twig
{# Header: search everything #}
{% include 'search-manager/_widget/search-modal' with {
    config: 'main-search',
    triggerSelector: '#header-search',
} %}

{# Sidebar: search blog only #}
{% include 'search-manager/_widget/search-modal' with {
    config: 'blog-search',
    indices: ['blog'],
    triggerSelector: '#sidebar-search',
} %}
```

## Feature Reference

For the full list of widget parameters and configuration options, see [Frontend Widget](../feature-tour/frontend-widget.md).
