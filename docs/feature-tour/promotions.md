# Promotions @since(5.10.0)

Pin a specific product, entry, or page to a fixed spot in your search results — bypassing normal relevance scoring — so it shows up exactly where you want it when the query, index, site, and result type all match. Promotions are built for search optimization and editorial control, making sure important content can win over whatever the ranking algorithm would otherwise pick.

Promotions require Pro. In Standard, stored promotions remain intact but are not inserted into search results.

## What you'll use it for

- Feature a specific product when users search for a category
- Promote sale items for seasonal keywords
- Ensure FAQ or support pages appear first for help-related queries
- Pin announcements for time-sensitive searches

## Create your first promotion (Pro)

1. Go to **Search Manager → Promotions** and click **New Promotion**.
2. Give it a **Title** (e.g., "Holiday Sale Banner") — just a descriptive name to help you find it later.
3. Pick the **Index** this promotion applies to.
4. Choose a **Match Type** — **Exact Match**, **Contains**, or **Starts With** — and enter the **Query Pattern** to match. Use commas for multiple patterns, e.g. `sale, تخفيض, soldes, angebot`.
5. Under **Type**, pick the kind of element to promote (entry, asset, category, or user — plus Commerce product/variant when Commerce is installed), then select the **Promoted Element** itself.
6. Set the **Position** — 1 for first result, 2 for second, and so on.
7. In the sidebar, confirm **Enabled** is on (and pick a **Site** on multi-site installs), then save.

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

### Multi-language

```text
Query Pattern: "sale, تخفيض, soldes, angebot"
Match Type: Exact
Promoted Element: "Holiday Sale Banner"
Position: 1, Index: All, Site: All

Result: One promotion works across all languages
```

## Indexed document availability

Promotions are applied from the index that is being searched. When a promotion matches a query, Search Manager asks the active backend for the promoted element's indexed document in that index and site:

- If the promoted element has an indexed document, that document is inserted at the configured position.
- If the promoted element is not indexed in the searched index/site, the promotion is skipped.
- A valid promoted document can supply a result even when the backend returns no organic hits.
- A request's supported `type` filter also applies to promoted documents. A promoted document whose indexed `type` is filtered out is not shown.
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

## Overlapping promotions

A global promotion, a site-specific promotion, and several matching query patterns can all select the same element for one search. Search Manager presents that searched-site element only once. The promotion with the earliest configured position wins; when positions are equal, the earlier-created promotion wins. Promotions for distinct elements remain separate and keep deterministic position order.

This precedence applies only to overlapping promotions for the same searched-site element. It does not collapse different promoted elements, even when they use the same position.

## Bulk actions

Select multiple promotions using checkboxes to:
- Enable or disable in bulk
- Delete in bulk
- Filter by status or match type

## API response

In the search widget, each widget chooses how promoted results are marked via its **Promotion Display** setting (widget settings → Results → Promotions): a badge (inline with the title or on its own line), a row tint, or no marker at all. The colors come from the widget style's Promoted section.

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

`meta.promotionsMatched` keeps its existing response name for compatibility, but contains only promotions represented by the final returned hits. Missing indexed targets, overlapping duplicates, and promoted documents removed by the request's type filter are excluded. Cache hits and fresh searches use the same rule.

The top-level `total` remains the backend's organic total; injected promotions do not increase it. A response can therefore contain a promoted hit while reporting `total: 0` when the backend found no organic results.

Promoted hits use the same metadata contract as indexed hits because they are copied from indexed documents. Entries include `entrySection`, `entrySectionHandle`, and `entrySectionType` when those fields were indexed; SourceDoc and custom source-backed hits can include `source` and `docCategory`; Assets include `volume` and `volumeHandle`; Categories include `categoryGroup` and `categoryGroupHandle`; Commerce Products and Variants include `productType` and `productTypeHandle`; Users do not include fake source or Entry section metadata. When the indexed document has hierarchy context, promoted hits can also include `ancestors`, Entry/Category `level`, and public Asset `folderPath`.

## Analytics

When analytics is enabled, Search Manager tracks impressions, positions, and triggering queries only for promotions represented by the final returned hits. Skipped targets, overlapping duplicates, and type-filtered promotions do not count as shown; a presented promotion also prevents that search action from being classified as a content gap. This data appears in the Analytics > Promotions tab. See [Analytics](analytics.md).
