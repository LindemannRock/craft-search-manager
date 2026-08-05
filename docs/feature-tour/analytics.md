# Analytics

See exactly what your visitors search for, where they come up empty, and how fast results come back. Search Manager tracks every query and turns it into an analytics dashboard — no separate analytics service required.

The eight-tab analytics workspace, analytics collection, and dashboard widgets require Pro. Standard keeps analytics-data export and permanent purge available from the Utilities page so retained data can still be managed after a downgrade.

## What you'll use it for

- See top and trending queries, and how search volume changes over time
- Find zero-result content gaps — searches that came up empty, clustered by similarity
- Track performance: cache hit rate, response times, fastest and slowest queries
- Break down traffic by device, browser, OS, and (optionally) geography
- Export any section as CSV, JSON, or Excel

## View your analytics (Pro)

Go to **Search Manager > Analytics**. Analytics is on by default — toggle it in the CP under Search Manager > Settings > Analytics, or set `enableAnalytics` in config. The dashboard is organized into tabs:

Every report, count, export, and row action is limited to sites the current user can edit. Administrators retain all-site access; a user with no editable sites sees no analytics rows and cannot delete any.

### Overview

Summary statistics and trends:
- Total searches, hits vs. zero-hit split, and success rate
- Search trends over time
- Intent and source breakdown charts
- Top queries
- **API Key Usage** — searches grouped by the API key that made them, with each key's share of traffic. Only shown when there is keyed traffic (see [API key attribution](#api-key-attribution) below)

### Recent searches

Detailed log of individual searches with columns for:
- Date, query, site, hits
- Synonyms expanded, rules matched, promotions shown
- Source, device, location
- Filterable and exportable

### Query rules

Only shown when query rules exist:
- Top triggered rules and frequency
- Rules by action type
- Queries that triggered each rule

### Promotions

Only shown when promotions exist:
- Top promoted elements and impression counts
- Impressions by position
- Queries that triggered promotions

### Content gaps

Identifies searches that returned no results:
- Zero-hit query clusters (grouped by similarity)
- Recent failed queries
- Helps you identify missing content

### Performance

Cache and speed metrics:
- Cache hit rate
- Response time trends
- Fastest and slowest queries

### Traffic & devices

Visitor breakdown:
- Device type (desktop, mobile, tablet)
- Browser distribution
- Operating system distribution
- Peak search hours

### Geographic

Only shown when geo-detection is enabled:
- Country breakdown
- City breakdown
- Regional search patterns

### Recover from a report error

Each analytics panel loads independently. If one request fails, that panel shows an error with **Retry** while panels that loaded successfully remain available. Select **Retry** to reload only the failed panel. Changing the site or date range starts a fresh set of requests, and a slower response from the previous selection cannot overwrite the newer report.

If retrying continues to fail, confirm the browser session is still active, check that the user can edit the selected site, and review the Search Manager logs for the failed analytics request.

## What gets tracked

Every search records:

| Data | Description |
|------|-------------|
| Query | The search terms |
| Hits | Number of results returned |
| Execution time | How long the search took |
| Source | Deterministic entry point (`widget-modal`, `twig`, `rest`, `graphql`, `cp-test`, etc.) or a custom override |
| Device, browser, OS | Parsed from user-agent (via Matomo DeviceDetector) |
| Country, city | Geographic location (when geo-detection enabled) |
| IP hash | Anonymized visitor identifier |
| Synonyms | Whether synonym expansion was used |
| Rules matched | Which query rules fired |
| Promotions matched | Which promotions were shown |
| Referrer | The page that triggered the search |
| Platform, app version | For mobile app tracking |
| API key | The key that made the request, when [API key enforcement](api-keys.md) is enabled (anonymous otherwise) |

## How searches are counted @since(5.46.0)

A single user search may hit one index or several. To preserve per-index detail without inflating totals, Search Manager stores analytics like this:

- A **multi-index search** writes **one row per index**. All those rows share a generated `sessionId` UUID.
- A **single-index search** writes one row with `sessionId` null.

Dashboards count user search actions where that's the right unit, and per-index calls where that's more useful:

| Surface | Unit | Why |
|---------|------|-----|
| **Dashboard totals, charts, breakdowns** (devices, browsers, countries, peak hours, top queries, intent, trending, content gaps, etc.) | **User search actions** | A 3-index search counts as one action — operators see what users did, not how the work was split across backends |
| **Raw analytics log and CSV exports** | **Per-index rows** | Operators can inspect each index's result separately when debugging |
| **Performance (response times, cache hit rate, fastest/slowest queries)** | **Per-index search calls** | Each index has its own execution time and cache state — averaging across them would hide the slow index. Labelled "Index searches" in the UI |
| **Top Agents list** | **Per-index calls** | Operational signal: which bots and system agents are hitting search hardest, including agents that fan out across all indices |

This is why the **Total Searches** card may show a smaller number than the row count in the raw analytics log — the card counts user search actions, the log lists individual per-index rows.

The stored `resultsCount` value is the backend-native `total` for that index response. For split-capable SourceDoc and AutoTransformer-family indices, that means matching section hits, not distinct parent elements.

A zero-result *action* is one where **every** row in that action returned no hits, no redirect, and no promotion. A multi-index search that succeeded on at least one of its indices is not a content gap.

### Widget searches and cache stats

The frontend search widget skips per-keystroke analytics to avoid spam — instead, it writes a single row on user intent (Enter, click, or idle). That intent row carries cache telemetry forward from the final search response (`cached` and `took` from `meta`), so widget activity contributes to the cache hit rate just like server-side callers do.

Legacy widget builds or callers that don't supply telemetry write rows with `executionTime = NULL`, and those are silently excluded from cache stats (they represent user intent, not a backend execution measurement). After upgrading to 5.46.0 and rebuilding the widget bundle, you'll see widget cache hits appear in the Performance tab.

## Per-index analytics

Analytics can be enabled or disabled per index. This is useful for excluding internal or admin-facing indices from tracking:

```php
// In index configuration
'internal-search' => [
    'enableAnalytics' => false,
    // ...
],
```

## Source attribution

Search Manager assigns the source at the entry point that started the search. It does not infer source from the request's `Referer` header.

| Entry point | Stored/exported source | Breakdown label |
|-------------|------------------------|-----------------|
| Modal widget | `widget-modal` | Modal Widget |
| Search-page widget | `widget-page` | Page Widget |
| Inline widget | `widget-inline` | Inline Widget |
| Twig `craft.searchManager.search()` / `searchMultiple()` | `twig` | Twig |
| REST search | `rest` | REST |
| GraphQL search | `graphql` | GraphQL |
| Control Panel Test search | `cp-test` | Control Panel Test |
| Direct/internal tracking without an entry-point default | `unknown` | Unknown |

The friendly labels are presentation-only: stored rows and exports keep the raw identifiers. The referrer is still captured as separate analytics metadata when the request supplies one. Existing historical source values such as `frontend`, `cp`, and `api` remain visible as Frontend, Control Panel, and API in breakdowns while retaining their raw values in exports. Custom sources keep their dynamic, title-capitalized fallback label.

You can also pass a custom source for mobile apps or integrations:

```twig
{% set results = craft.searchManager.search('products', 'shoes', {
    analyticsSource: 'android-app',
    platform: 'Android 14',
    appVersion: '1.5.2',
}) %}
```

A non-empty custom source overrides the entry-point default. Search Manager trims and normalizes it to letters, numbers, dashes, and underscores, with a 50-character limit. Missing, empty, or whitespace-only values use the entry-point default.

Or via the REST API:

```text
GET /actions/search-manager/api/search?q=shoes&analyticsSource=ios-app&platform=iOS%2017.2&appVersion=2.1.0
```

## API key attribution

When [API key enforcement](api-keys.md) is enabled, each search and `track-search` analytics row is attributed to the API key that made the request. Three columns are recorded:

- **API Key** — the key's prefix snapshot (e.g. `sm_pub_a1b2c3d4`)
- **API Key Type** — `public` or `server`
- (an internal key id, for correlation)

The prefix and type are **snapshots**, so historical rows stay readable even after a key is revoked or deleted. Anonymous traffic — when enforcement is off, or no key was sent — records empty attribution and is excluded from the API Key Usage breakdown.

Attribution covers the endpoints that record analytics: `/api/search` and the widget's `track-search` intent ping. Autocomplete records no analytics, and `track-click` is log-only, so neither carries attribution.

The **API Key Usage** table on the Overview tab groups keyed searches by key, with each key's share of traffic, and only appears when keyed traffic exists.

## Export

Analytics can be exported as CSV, JSON, or Excel from the Export button in the page toolbar — one export bundles every section (Recent Searches, Trending, Query Rules, Promotions, Performance, Traffic & Devices, Geographic, and Content Gaps). Exports include all columns with clean headers (Hits, Synonyms, Rules, Promotions, Redirected, and — when keyed traffic exists — API Key and API Key Type).

> [!NOTE]
> In Standard, the Analytics workspace is unavailable and new analytics tracking is disabled. Export and permanent purge of retained analytics data remain available under **Search Manager > Utilities > Analytics Data Management**.

## Retention

Configure how long analytics data is kept, in the CP under Search Manager > Settings > Analytics > Data Retention, or in config:

```php
'analyticsRetention' => 90,  // Days (0 = keep forever)
```

An automatic cleanup job removes old records based on this setting. **Clean Up Now** on the same settings screen runs that lifecycle immediately. Both operations apply the global retention policy to primary search, query-rule, and promotion analytics together, while keeping recent rows.

## Bot filtering

Search Manager uses Matomo DeviceDetector to identify bot traffic (GoogleBot, BingBot, etc.). Bot searches are flagged in analytics so you can filter them out.

## Privacy

Analytics is designed with privacy in mind:
- IPs are never stored in plain text — only a salted SHA256 hash
- Optional subnet masking (replace last octet with 0)
- Geo-location is extracted before hashing, then the original IP is discarded
- Async geo-lookup runs via queue job to avoid blocking search responses

See [Privacy & Security](privacy-security.md) for details.
