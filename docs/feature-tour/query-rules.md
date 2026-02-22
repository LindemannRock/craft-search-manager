# Query Rules @since(5.10.0)

Query rules modify search behavior when a user's query matches a specific pattern. They support synonyms, boosting, filtering, and redirects.

## What Are Query Rules?

A query rule has two parts:
1. **Trigger** — a pattern that the search query must match
2. **Action** — what happens when the pattern matches

For example: when someone searches for "laptop", also include results for "notebook" and "portable computer" (synonym expansion).

## Creating Query Rules

Go to Search Manager > Query Rules and click "New Query Rule". You can also manage rules through the REST API.

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

### Filter Results

Apply a filter when the query matches:

```text
Name: Filter to In-Stock Only
Match Value: buy
Match Type: Contains
Action: Filter
Field: inStock
Value: true
```

Queries containing "buy" only show in-stock items.

### Redirect

Redirect users to a page instead of showing search results. Four redirect targets are supported:

- **Custom URL** — a path (`/contact`) or full URL
- **Entry** — select any entry via element picker
- **Category** — select any category
- **Asset** — select any asset (e.g., a PDF download)

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
                "actionValue": ["notebook", "computer"]
            }
        ]
    }
}
```

The `actionValue` format varies by action type:
- **synonym**: `["notebook", "computer", "laptop"]`
- **boost_section**: `{"sectionHandle": "products", "multiplier": 2.0}`
- **boost_category**: `{"categoryId": 5, "multiplier": 1.5}`
- **boost_element**: `{"elementId": 123, "multiplier": 2.0}`
- **filter**: `{"field": "status", "value": "featured"}`
- **redirect**: `"/sale-page"`
