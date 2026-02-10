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

## Analytics Tabs

The Analytics dashboard is organized into tabs:

### Overview

Summary statistics and trends:
- Total searches, unique queries, average hits
- Search trends over time
- Intent and source breakdown charts
- Top queries

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
    'disableAnalytics' => true,
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

## Export

Analytics can be exported as CSV or JSON from the Recent Searches tab. Exports include all columns with clean headers (Hits, Synonyms, Rules, Promotions, Redirected).

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
