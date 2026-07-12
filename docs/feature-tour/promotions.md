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
- **Promoted Element** — the Craft element to promote. Entry, asset, category, and user targets are always available; Commerce product and variant targets appear when Craft Commerce is installed and enabled.
- **Position** — where to place it (1 = first, 2 = second, etc.)
- **Index** — all indices or a specific index
- **Site** — all sites or a specific site

## Examples

### Exact Match

```text
Query Pattern: "laptop"
Match Type: Exact
Promoted Element: "MacBook Pro 2024" (Product #123)
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

## Indexed Document Availability

Promotions are applied from the index that is being searched. When a promotion matches a query, Search Manager asks the active backend for the promoted element's indexed document in that index and site:

- If the promoted element has an indexed document, that document is inserted at the configured position.
- If the promoted element is not indexed in the searched index/site, the promotion is skipped.
- Public hit fields such as `title`, `url`, `type`, `snippet`, and metadata come from the indexed document, not from a live Craft element lookup.
- Split-section indices promote the indexed intro or first section document for the target element. If no split document exists, the promotion is skipped.

```text
Example:
- "Summer Sale" is promoted for query "sale"
- The indexed English document still exists after a content change
- English search: promotion shown from the indexed document
- After the element is deleted and sync removes the indexed document
- English search: promotion skipped
```

This keeps promotions aligned with normal search results: both trust the backend index as the source of truth at search time. Rebuild the affected index after changing promotion targets, URL-bearing fields, category/product metadata, or split-section content that should appear in promoted results.

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
            "elementId": 123,
            "siteId": 1,
            "backendId": "123_1",
            "promoted": true,
            "position": 1,
            "score": null,
            "elementType": "product",
            "type": "product",
            "productType": "Clothing",
            "productTypeHandle": "clothing",
            "headings": [],
            "matchedIn": [],
            "matchedTerms": {
                "title": [],
                "content": []
            },
            "matchedPhrases": [],
            "snippet": null,
            "title": "Featured Product"
        },
        {
            "elementId": 456,
            "siteId": 1,
            "backendId": "456_1",
            "score": 45.23,
            "elementType": "entry",
            "type": "entry",
            "entrySection": "Blog",
            "entrySectionHandle": "blog",
            "entrySectionType": "channel",
            "headings": [],
            "matchedIn": ["title"],
            "matchedTerms": {
                "title": ["featured"],
                "content": []
            },
            "matchedPhrases": [],
            "snippet": null
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

Promoted hits use the same metadata contract as indexed hits because they are copied from indexed documents. Entries include `entrySection`, `entrySectionHandle`, and `entrySectionType` when those fields were indexed; SourceDoc and custom source-backed hits can include `source` and `docCategory`; Assets include `volume` and `volumeHandle`; Categories include `categoryGroup` and `categoryGroupHandle`; Commerce Products and Variants include `productType` and `productTypeHandle`; Users do not include fake source or Entry section metadata. When the indexed document has hierarchy context, promoted hits can also include `ancestors`, Entry/Category `level`, and public Asset `folderPath`.

## Analytics

When analytics is enabled, Search Manager tracks promotion impressions, positions, and which queries triggered each promotion. This data appears in the Analytics > Promotions tab. See [Analytics](analytics.md).
