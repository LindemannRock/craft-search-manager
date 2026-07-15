# Query Rules @since(5.10.0)

Query rules modify search behavior when a user's query matches a specific pattern. They support synonyms, boosting, and redirects.

## What Are Query Rules?

A query rule has two parts:
1. **Trigger** — a pattern that the search query must match
2. **Action** — what happens when the pattern matches

For example: when someone searches for "laptop", also include results for "notebook" and "portable computer" (synonym expansion).

## Creating Query Rules

Go to Search Manager > Query Rules and click "New Query Rule". Rules can also be managed programmatically via the PHP service API (see [API Reference](../developers/api-reference.md)).

## Action Types

### Synonyms

Expand searches to include related terms:

```text
Name: Laptop Synonyms
Match Value: laptop
Match Type: Exact
Action: Synonyms
Terms: notebook, portable computer, macbook
```

When someone searches "laptop", the search also finds results containing "notebook", "portable computer", or "macbook". Each synonym triggers a separate search query against the backend, so results are merged and deduplicated.

#### Synonym Limits

- **Per rule:** Maximum **10 terms** per synonym rule.
- **Per search:** Maximum **10 total queries** (original + all synonyms combined) per search request. Duplicate terms across rules are automatically removed before counting.

> [!WARNING]
> Multiple rules can match the same search query — especially when using different match types. For example, a search for "laptop case" could match an exact rule for "laptop case" (3 synonyms), a contains rule for "laptop" (5 synonyms), and a starts-with rule for "laptop" (4 synonyms). After removing duplicates, this produces 13 unique queries — but only the first 10 are executed. The remaining terms are dropped and a warning is logged. To avoid this, keep synonym rules focused and avoid overlapping match patterns.

### Boost Section

Increase relevance for results from a specific section:

```text
Name: Boost News for Current Events
Match Value: election
Match Type: Contains
Action: Boost Section
Section: news
Multiplier: 2.0
```

News articles rank 2x higher when the query contains "election".

Section boosts use the `entrySectionHandle` stored on each indexed hit. If an older document does not have that metadata, the section boost is skipped for that hit and Search Manager logs a warning.

### Boost Category

Increase relevance for results in a specific category:

```text
Name: Boost Electronics for Tech Queries
Match Value: tech
Match Type: Prefix
Action: Boost Category
Category: Electronics
Multiplier: 1.5
```

Category boosts use compact indexed category relation metadata. Auto-indexed documents store related category IDs in `_categoryIds`, and category hits can also match their own element ID. Search Manager does not hydrate result elements or query Craft relations during search. If an older document does not include `_categoryIds`, related-category boosts are skipped for that hit and Search Manager logs a warning.

### Boost Element

Increase relevance for a specific element:

```text
Name: Boost FAQ for Help Queries
Match Value: help
Match Type: Contains
Action: Boost Element
Element ID: 789
Multiplier: 3.0
```

The element picker supports entries, assets, categories, and users by default. When Craft Commerce is installed and enabled, product and variant targets are available as well.

### Redirect

Redirect users to a page instead of showing search results. Redirect targets are:

- **Custom URL** — a path (`/contact`) or full URL
- **Entry** — select any entry via element picker
- **Category** — select any category
- **Asset** — select any asset (e.g., a PDF download)
- **User** — select a user profile target
- **Commerce Product** — select a product when Craft Commerce is available
- **Commerce Variant** — select a variant when Craft Commerce is available

Element-based redirects resolve the target URL from Craft at search time. This is the documented exception to the indexed-only response rule because a redirect may point to valid content outside the searched index and it does not shape public hit fields.

```text
Name: Contact Redirect
Match Value: contact us
Match Type: Exact
Action: Redirect
Redirect To: Custom URL
URL: /contact
```

## Match Types

| Type | Description | Example Pattern | Matches |
|------|-------------|-----------------|---------|
| Exact | Query must match exactly | `laptop` | "laptop" only |
| Contains | Query must contain the pattern | `laptop` | "best laptop deals", "laptop" |
| Prefix | Query must start with the pattern | `lap` | "laptop", "lapel" |
| Regex | Regular expression | `^(buy\|purchase)` | "buy shoes", "purchase online" |

### Multi-Language Patterns

Use commas to match multiple patterns in one rule (Exact, Contains, Prefix):

```text
sale, تخفيض, soldes, angebot
```

This matches "sale" (English), "تخفيض" (Arabic), "soldes" (French), or "angebot" (German).

For Regex, use the `|` operator:

```text
^(sale|تخفيض|soldes|angebot)
```

## Priority System

Rules are applied in priority order — higher priority rules are checked first.

| Priority | Label | Use Case |
|----------|-------|----------|
| 10 | Highest | Specific, high-value rules (e.g., "buy iphone 15 pro max") |
| 5 | High | Important rules (e.g., "buy iphone") |
| 0 | Normal | Standard rules (default) |
| -5 | Low | General rules |
| -10 | Lowest | Catch-all/fallback rules (e.g., "buy") |

Set specific rules to high priority and general rules to low priority so the most relevant rule matches first.

## Scope

Each rule can be scoped to:

- **Index** — apply to all indices (leave blank) or a specific index
- **Site** — apply to all sites (leave blank) or a specific site

## Reindex Requirements

Boost execution depends on metadata already stored in search documents:

- Rebuild affected entry indices so section boosts can read `entrySectionHandle` from existing documents.
- Rebuild indices that should participate in related-category boosts so documents include `_categoryIds`.
- Rebuild after changing category relations if those changes are not captured by normal element sync.
- Redirect rules do not require reindexing because they resolve their URL target directly from Craft.

## Analytics

When query rules are active and analytics is enabled, Search Manager tracks:
- Which rules fire and how often
- Which queries trigger each rule
- Rule effectiveness over time

This data appears in the Analytics > Query Rules tab. See [Analytics](analytics.md) for details.

## API Response

When rules are applied, they appear in the search response metadata:

```json
{
    "hits": [...],
    "meta": {
        "synonymsExpanded": true,
        "expandedQueries": ["laptop", "notebook", "computer"],
        "rulesMatched": [
            {
                "id": 5,
                "name": "Laptop synonyms",
                "actionType": "synonym",
                "actionValue": {"terms": ["notebook", "computer"]}
            }
        ]
    }
}
```

The `actionValue` format varies by action type:
- **synonym**: `{"terms": ["notebook", "computer", "laptop"]}`
- **boost_section**: `{"sectionHandle": "products", "multiplier": 2.0}`
- **boost_category**: `{"categoryId": 5, "multiplier": 1.5}`
- **boost_element**: `{"elementId": 123, "elementType": "craft\\elements\\Entry", "multiplier": 2.0}`
- **redirect**: `{"url": "/sale-page"}` for custom URLs, or `{"elementId": 123, "elementType": "craft\\elements\\Entry"}` for element-based redirects
