# Promotions @since(5.10.0)

Promotions let you pin specific elements to fixed positions in search results, bypassing normal relevance scoring. Use them for merchandising, editorial control, or ensuring important content appears first for specific queries.

## Use Cases

- Feature a specific product when users search for a category
- Promote sale items for seasonal keywords
- Ensure FAQ or support pages appear first for help-related queries
- Pin announcements for time-sensitive searches

## Creating Promotions

Go to Search Manager > Promotions and click "New Promotion". Each promotion has:

- **Title** — descriptive name for organization (e.g., "Holiday Sale Banner")
- **Query Pattern** — the search query to match. Use commas for multiple patterns:
  - Single: `sale`
  - Multi-language: `sale, تخفيض, soldes, angebot`
- **Match Type** — how to match the query:
  - **Exact** — query must exactly match one of the patterns
  - **Contains** — query must contain one of the patterns
  - **Prefix** — query must start with one of the patterns
- **Promoted Element** — the Craft element to promote (entry, asset, category)
- **Position** — where to place it (1 = first, 2 = second, etc.)
- **Index** — all indices or a specific index
- **Site** — all sites or a specific site

## Examples

### Exact Match

```text
Query Pattern: "laptop"
Match Type: Exact
Promoted Element: "MacBook Pro 2024" (Entry #123)
Position: 1

Result: Searching exactly "laptop" → MacBook Pro appears first
```

### Contains Match

```text
Query Pattern: "sale"
Match Type: Contains
Promoted Element: "Black Friday Deals" (Entry #456)
Position: 1

Result: Any query containing "sale" (e.g., "laptop sale", "sale items")
→ Black Friday Deals appears first
```

### Multi-Language

```text
Query Pattern: "sale, تخفيض, soldes, angebot"
Match Type: Exact
Promoted Element: "Holiday Sale Banner"
Position: 1, Index: All, Site: All

Result: One promotion works across all languages
```

## Per-Site Element Status

Promotions automatically respect element status on a per-site basis:

- If an element is **disabled** for Site A but **enabled** for Site B, the promotion only appears on Site B
- Elements with **pending** or **expired** post dates are excluded
- Uses Craft's `status('live')` check for all status conditions

```text
Example:
- "Summer Sale" is promoted for query "sale"
- Disabled for English site, enabled for French/Arabic sites
- English search: promotion NOT shown
- French search: promotion shown at position 1
```

## Bulk Actions

Select multiple promotions using checkboxes to:
- Enable or disable in bulk
- Delete in bulk
- Filter by status or match type

## API Response

Promoted items appear in search results with `promoted: true` and `score: null`:

```json
{
    "hits": [
        {
            "objectID": 123,
            "id": 123,
            "promoted": true,
            "position": 1,
            "score": null,
            "type": "product",
            "title": "Featured Product"
        },
        {
            "objectID": 456,
            "id": 456,
            "score": 45.23,
            "type": "product"
        }
    ],
    "total": 150,
    "meta": {
        "promotionsMatched": [
            {
                "id": 1,
                "elementId": 123,
                "position": 1
            }
        ]
    }
}
```

## Analytics

When analytics is enabled, Search Manager tracks promotion impressions, positions, and which queries triggered each promotion. This data appears in the Analytics > Promotions tab. See [Analytics](analytics.md).
