# Widget Styles @since(5.39.0)

Widget Styles are reusable appearance presets for the [Frontend Widget](overview.md). Define colors, spacing, dimensions, and other visual properties once, then share them across multiple widget configurations.

## How It Works

Widget configs control **behavior** (debounce, max results, hotkey, etc.) while widget styles control **appearance** (colors, border radius, padding, etc.). This separation lets you reuse the same visual design across different search widgets without duplicating style settings.

Each widget config can reference a style preset via `styleHandle`. If no style is linked, the widget uses the built-in WCAG 2.1 AA compliant defaults.

## Widget Types

Each style has a `type` that determines how the widget renders:

| Type | Constant | Description |
|------|----------|-------------|
| Modal | `modal` | CMD+K overlay — opens on top of the page with a backdrop |
| Search Page | `page` | Full page — renders inline as a dedicated search page |
| Inline Search | `inline` | Compact search bar embedded directly in the page |

## Creating Styles

### Via Control Panel

Go to **Search Manager > Widgets > Styles** and click "New Style". The editor is organized into six tabs:

- **General** — name and handle
- **Modal** — max width, max height, border radius, padding, backdrop opacity
- **Input** — font size, colors, borders
- **Results** — gap, border radius, padding, active/selected colors
- **Controls** — trigger button styling (border radius, padding, font size, colors), keyboard badge styling (border radius, colors)
- **Highlights** — highlighting background and text colors for light and dark modes

### Via Config File

Define styles in `config/search-manager.php` under the `widgetStyles` key:

```php
'widgetStyles' => [
    'brand-dark' => [
        'name' => 'Brand Dark',
        'type' => 'modal',
        'enabled' => true,
        'styles' => [
            // Modal
            'modalBg' => '#1a1a2e',
            'modalBorderColor' => '#4da6ff',
            'modalBorderRadius' => '16',
            'modalMaxWidth' => '640',
            'modalMaxHeight' => '80',
            'modalPaddingX' => '16',
            'modalPaddingY' => '16',

            // Input
            'inputBg' => '#2a2a2a',
            'inputTextColor' => '#ffffff',
            'inputFontSize' => '16',

            // Results
            'resultActiveBg' => '#333333',
            'resultGap' => '8',
            'resultBorderRadius' => '8',
            'resultPaddingX' => '12',
            'resultPaddingY' => '12',

            // Backdrop
            'backdropOpacity' => '50',

            // Trigger
            'triggerBorderRadius' => '8',
            'triggerFontSize' => '14',
        ],
    ],
],
```

Config-defined styles show a "Config" badge in the CP and cannot be edited there. Database-defined styles show a "Database" badge and are fully editable.

## Linking Styles to Configs

Reference a style preset from a widget config:

```php
'widgets' => [
    'main-search' => [
        'name' => 'Main Search',
        'styleHandle' => 'brand-dark',
        // ...
    ],
],
```

Or pass it as a Twig parameter:

```twig
{% include 'search-manager/_widget/search-modal' with {
    config: 'main-search',
    styleHandle: 'brand-dark',
} %}
```

The style preset is resolved at render time. If the referenced style doesn't exist or is disabled, the widget falls back to the built-in defaults.

## Style Properties

All style properties are optional. Unset properties use the built-in defaults (WCAG 2.1 AA compliant colors).

### Modal

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `modalBg` | `string` | — | `#ffffff` | Modal background color |
| `modalBgDark` | `string` | — | `#1f2937` | Modal background (dark mode) |
| `modalBorderRadius` | `int` | 0-50 | `12` | Border radius in px |
| `modalBorderWidth` | `int` | 0-10 | `1` | Border width in px |
| `modalBorderColor` | `string` | — | `#e5e7eb` | Border color |
| `modalBorderColorDark` | `string` | — | `#374151` | Border color (dark mode) |
| `modalMaxWidth` | `int` | 300-1200 | `640` | Maximum width in px |
| `modalMaxHeight` | `int` | 30-95 | `80` | Maximum height in vh |
| `modalPaddingX` | `int` | 0-64 | `16` | Horizontal padding in px |
| `modalPaddingY` | `int` | 0-64 | `16` | Vertical padding in px |
| `modalShadow` | `string` | — | `0 25px 50px -12px rgba(0, 0, 0, 0.25)` | Box shadow |
| `modalShadowDark` | `string` | — | `0 25px 50px -12px rgba(0, 0, 0, 0.5)` | Box shadow (dark mode) |

### Backdrop

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `backdropOpacity` | `int` | 0-100 | `50` | Backdrop opacity percentage |
| `backdropBlur` | `string` | — | `1` | Backdrop blur (CSS value) |

### Input

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `inputBg` | `string` | — | `#ffffff` | Input background |
| `inputBgDark` | `string` | — | `#1f2937` | Input background (dark mode) |
| `inputTextColor` | `string` | — | `#111827` | Input text color |
| `inputTextColorDark` | `string` | — | `#f9fafb` | Input text color (dark mode) |
| `inputPlaceholderColor` | `string` | — | `#9ca3af` | Placeholder color |
| `inputPlaceholderColorDark` | `string` | — | `#9ca3af` | Placeholder color (dark mode) |
| `inputBorderColor` | `string` | — | `#e5e7eb` | Input border color |
| `inputBorderColorDark` | `string` | — | `#374151` | Input border color (dark mode) |
| `inputFontSize` | `int` | 12-24 | `16` | Font size in px |

### Results

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `resultBg` | `string` | — | `transparent` | Result background |
| `resultBgDark` | `string` | — | `transparent` | Result background (dark mode) |
| `resultTextColor` | `string` | — | `#111827` | Result text color |
| `resultTextColorDark` | `string` | — | `#f9fafb` | Result text color (dark mode) |
| `resultDescColor` | `string` | — | `#4b5563` | Description text color |
| `resultDescColorDark` | `string` | — | `#d1d5db` | Description text color (dark mode) |
| `resultActiveBg` | `string` | — | `#e5e7eb` | Active/selected/hover background |
| `resultActiveBgDark` | `string` | — | `#4b5563` | Active/selected/hover background (dark mode) |
| `resultGap` | `int` | 0-20 | `8` | Gap between results in px |
| `resultBorderRadius` | `int` | 0-20 | `8` | Result border radius in px |
| `resultBorderWidth` | `int` | 0-10 | `0` | Result border width in px |
| `resultPaddingX` | `int` | 0-32 | `12` | Horizontal padding in px |
| `resultPaddingY` | `int` | 0-32 | `12` | Vertical padding in px |

### Trigger

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `triggerBg` | `string` | — | `#ffffff` | Trigger background |
| `triggerBgDark` | `string` | — | `#374151` | Trigger background (dark mode) |
| `triggerTextColor` | `string` | — | `#374151` | Trigger text color |
| `triggerTextColorDark` | `string` | — | `#d1d5db` | Trigger text color (dark mode) |
| `triggerBorderRadius` | `int` | 0-20 | `8` | Border radius in px |
| `triggerBorderWidth` | `int` | 0-5 | `1` | Border width in px |
| `triggerBorderColor` | `string` | — | `#d1d5db` | Border color |
| `triggerBorderColorDark` | `string` | — | `#4b5563` | Border color (dark mode) |
| `triggerPaddingX` | `int` | 0-40 | `12` | Horizontal padding in px |
| `triggerPaddingY` | `int` | 0-40 | `8` | Vertical padding in px |
| `triggerFontSize` | `int` | 10-24 | `14` | Font size in px |

### Keyboard Badge

| Property | Type | Range | Default | Description |
|----------|------|-------|---------|-------------|
| `kbdBg` | `string` | — | `#f3f4f6` | Badge background |
| `kbdBgDark` | `string` | — | `#4b5563` | Badge background (dark mode) |
| `kbdTextColor` | `string` | — | `#4b5563` | Badge text color |
| `kbdTextColorDark` | `string` | — | `#e5e7eb` | Badge text color (dark mode) |
| `kbdBorderRadius` | `int` | 0-20 | `4` | Border radius in px |

### Highlighting

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `highlightBgLight` | `string` | `#fef08a` | Highlight background (light mode) |
| `highlightColorLight` | `string` | `#854d0e` | Highlight text color (light mode) |
| `highlightBgDark` | `string` | `#854d0e` | Highlight background (dark mode) |
| `highlightColorDark` | `string` | `#fef08a` | Highlight text color (dark mode) |

### Promoted Badge

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `promotedBg` | `string` | `#2563eb` | Badge background (light mode) |
| `promotedBgDark` | `string` | `#3b82f6` | Badge background (dark mode) |
| `promotedColor` | `string` | `#ffffff` | Badge text color (light mode) |
| `promotedColorDark` | `string` | `#ffffff` | Badge text color (dark mode) |

## Accessibility

The built-in style defaults are WCAG 2.1 AA compliant — all color combinations meet the 4.5:1 contrast ratio for normal text and 3:1 for large text.

When overriding colors, check your contrast ratios. Key pairs to verify:

- `inputTextColor` against `inputBg`
- `resultTextColor` against `resultBg` and `resultActiveBg`
- `triggerTextColor` against `triggerBg`
- `kbdTextColor` against `kbdBg`
- `promotedColor` against `promotedBg`
