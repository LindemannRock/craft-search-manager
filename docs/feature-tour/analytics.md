# Analytics

Search Manager tracks every search query and provides detailed analytics to help you understand what your users are searching for, identify content gaps, and optimize performance.

## What Gets Tracked

Every search records:

| Data | Description |
|------|-------------|
| Query | The search terms |
| Hits | Number of results returned |
| Execution time | How long the search took |
| Source | Where the search came from (frontend, CP, API, custom) |
| Device, browser, OS | Parsed from user-agent (via Matomo DeviceDetector) |
| Country, city | Geographic location (when geo-detection enabled) |
| IP hash | Anonymized visitor identifier |
| Synonyms | Whether synonym expansion was used |
| Rules matched | Which query rules fired |
| Promotions matched | Which promotions were shown |
| Referrer | The page that triggered the search |
| Platform, app version | For mobile app tracking |
| API key | The key that made the request, when [API key enforcement](api-keys.md) is enabled (anonymous otherwise) |

## How Searches Are Counted @since(5.46.0)

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

A zero-result *action* is one where **every** row in that action returned no hits, no redirect, and no promotion. A multi-index search that succeeded on at least one of its indices is not a content gap.

### Widget Searches and Cache Stats

The frontend widget skips per-keystroke analytics to avoid spam — instead, it writes a single row on user intent (Enter, click, or idle). That intent row carries cache telemetry forward from the final search response (`cached` and `took` from `meta`), so widget activity contributes to the cache hit rate just like server-side callers do.

Legacy widget builds or callers that don't supply telemetry write rows with `executionTime = NULL`, and those are silently excluded from cache stats (they represent user intent, not a backend execution measurement). After upgrading to 5.46.0 and rebuilding the widget bundle, you'll see widget cache hits appear in the Performance tab.

## Analytics Tabs

The Analytics dashboard is organized into tabs:

### Overview

Summary statistics and trends:
- Total searches, unique queries, average hits
- Search trends over time
- Intent and source breakdown charts
- Top queries
- **API Key Usage** — searches grouped by the API key that made them, with each key's share of traffic. Only shown when there is keyed traffic (see [API key attribution](#api-key-attribution) below)

### Recent Searches

Detailed log of individual searches with columns for:
- Query, hits, execution time
- Synonyms expanded, rules matched, promotions shown
- Source, device, location
- Filterable and exportable

### Query Rules

Only shown when query rules exist:
- Top triggered rules and frequency
- Rules by action type
- Queries that triggered each rule

### Promotions

Only shown when promotions exist:
- Top promoted elements and impression counts
- Impressions by position
- Queries that triggered promotions

### Content Gaps

Identifies searches that returned no results:
- Zero-hit query clusters (grouped by similarity)
- Recent failed queries
- Helps you identify missing content

### Performance

Cache and speed metrics:
- Cache hit rate
- Response time trends
- Fastest and slowest queries

### Traffic & Devices

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

## Per-Index Analytics

Analytics can be enabled or disabled per index. This is useful for excluding internal or admin-facing indices from tracking:

```php
// In index configuration
'internal-search' => [
    'enableAnalytics' => false,
    // ...
],
```

## Source Detection

Search Manager automatically detects where a search came from:

| Source | How It's Detected |
|--------|-------------------|
| `cp` | Craft CP request |
| `frontend` | Referrer from same host |
| `api` | No referrer or external referrer |

You can also pass a custom source for mobile apps or integrations:

```twig
{% set results = craft.searchManager.search('products', 'shoes', {
    source: 'android-app',
    platform: 'Android 14',
    appVersion: '1.5.2',
}) %}
```

Or via the REST API:

```text
GET /actions/search-manager/api/search?q=shoes&source=ios-app&platform=iOS%2017.2&appVersion=2.1.0
```

## API Key Attribution

When [API key enforcement](api-keys.md) is enabled, each search and `track-search` analytics row is attributed to the API key that made the request. Three columns are recorded:

- **API Key** — the key's prefix snapshot (e.g. `sm_pub_a1b2c3d4`)
- **API Key Type** — `public` or `server`
- (an internal key id, for correlation)

The prefix and type are **snapshots**, so historical rows stay readable even after a key is revoked or deleted. Anonymous traffic — when enforcement is off, or no key was sent — records empty attribution and is excluded from the API Key Usage breakdown.

Attribution covers the endpoints that record analytics: `/api/search` and the widget's `track-search` intent ping. Autocomplete records no analytics, and `track-click` is log-only, so neither carries attribution.

The **API Key Usage** table on the Overview tab groups keyed searches by key, with each key's share of traffic, and only appears when keyed traffic exists.

## Export

Analytics can be exported as CSV, JSON, or Excel from the Recent Searches tab. Exports include all columns with clean headers (Hits, Synonyms, Rules, Promotions, Redirected, and — when keyed traffic exists — API Key and API Key Type).

## Retention

Configure how long analytics data is kept:

```php
'analyticsRetention' => 90,  // Days (0 = keep forever)
```

An automatic cleanup job removes old records based on this setting.

## Bot Filtering

Search Manager uses Matomo DeviceDetector to identify bot traffic (GoogleBot, BingBot, etc.). Bot searches are flagged in analytics so you can filter them out.

## Privacy

Analytics is designed with privacy in mind:
- IPs are never stored in plain text — only a salted SHA256 hash
- Optional subnet masking (replace last octet with 0)
- Geo-location is extracted before hashing, then the original IP is discarded
- Async geo-lookup runs via queue job to avoid blocking search responses

See [Privacy & Security](privacy-security.md) for details.
