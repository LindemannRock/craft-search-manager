# Postman

Explore Search Manager's public Search, Autocomplete, Track Search, and Track Click endpoints from a customer-first Postman collection. The imported **Start Here** requests need only your Craft site URL and a query that exists in indexed content; Search Manager keeps authentication, index scope, edition behavior, and Analytics settings under server control.

## Download and import

Open **Search Manager → Settings → Test** and choose **Download Postman collection** in the **Search** tab. The same download is available from **Utilities → Search Manager**.

The `search-manager-postman.zip` archive contains:

- `Search-Manager.postman_collection.json`
- `Search-Manager.postman_environment.json`
- `README.md`

Extract the archive, then:

1. Import both JSON files into Postman.
2. Select the imported **Search Manager API** environment.
3. Set `base_url` to your Craft site URL without a trailing slash.
4. Replace `query` with a title or term you know has been indexed.
5. Open **Start Here** and send **Search all allowed indices**.

Leave `public_api_key` empty when **Require API Key** is disabled. If the request returns `401`, create or obtain an active Search Manager **public** API key, add it to `public_api_key`, and retry. Public endpoints do not accept server keys.

## Environment variables

Start with the first two variables; fill the optional values only when you want their targeted examples.

| Variable | Required | Purpose |
|---|---|---|
| `base_url` | Yes | Craft site URL without a trailing slash |
| `query` | Yes | Search text known to exist in indexed content |
| `public_api_key` | Only when enforced | Active public API key; never use a server key |
| `index_handle` | No | One enabled index for singular examples |
| `index_handles` | No | Two or more enabled handles separated by commas, up to five |
| `site_id` | No | Real Craft site ID for site-restricted examples |
| `element_id` | No | Real result element ID for Track Click |

Variables prefixed with `developer_` belong only to **Developer Validation — Optional**. They are not prerequisites for the customer examples.

## How request scope works

The **Start Here** requests omit `indexHandles`. Search Manager therefore selects all enabled indices when API-key enforcement is off, or the authenticated public key's allowed indices when enforcement is on. The collection derives `Referer` from `base_url`.

The remaining folders make scope explicit only where the example needs it:

- **Search Examples** covers one index, multiple comma-separated indices, a site restriction, and the canonical widget-style response.
- **Autocomplete Examples** uses the same omitted, singular, and multiple-index vocabulary, with suggestions-only and results-only variants.
- A successful `200` response with zero hits or suggestions is valid. It proves that the API executed; try a query known to exist if you need visible results.

Exploratory Search requests include `skipAnalytics=1`, so browsing search results through the collection does not add search analytics.

## Analytics responses

The **Analytics** folder follows the running server's edition and settings. You do not select an edition in the environment.

| Response | Meaning |
|---|---|
| Track Search `200`, `success=true`, `tracked=true` | Accepted and recorded |
| Track Search `200`, `success=true`, `tracked=false` | Accepted but not recorded under current Analytics settings |
| Track Click `200`, `success=true` | Accepted; this response alone does not prove persistence |
| Empty `204` | Standard-edition no-op |

Track Click requires both `element_id` and `index_handle`. A missing optional value skips only that request and prints the setup it needs. When API-key enforcement is enabled, Analytics uses the same `public_api_key` as the other customer folders.

## Optional developer validation

**Developer Validation — Optional** contains exact negative and boundary checks for missing or invalid keys, blocked Referer/Origin values, out-of-scope indices, unknown sites, and deterministic rate limiting. It is isolated from **Start Here**, Search, Autocomplete, and Analytics; its prerequisites never control those customer examples.

Use this folder only against a disposable local installation. Every request requires `developer_api_key_enforcement_enabled=yes` plus its own disposable values. The rate-limit check is Runner-only and needs a dedicated public key whose known per-minute cap is lower than the configured Runner iteration count.

## Safety and cleanup

Never run developer validation with production credentials, hosted-provider indices, valuable analytics data, or shared rate-limit keys. Afterward, delete every disposable key, index, backend record, analytics row, queue/pending row, and local file created for the check, and restore changed Search Manager settings exactly.

Ordinary customer exploration needs no special fixture cleanup. Remove any analytics you intentionally created through the Analytics folder when they should not remain.

## Troubleshooting

### A Start Here request returns `401`

Set `public_api_key` to an active Search Manager public key allowed to use the required indices and referrer. Do not use a server key.

### A request returns `403`

Check the public key's allowed indices and referrer patterns. The collection sends a Referer derived from `base_url`; make sure that host is allowed.

### Search returns `200` with no hits

The request is valid, but the query did not match the selected server-owned or explicit index scope. Try a known indexed title, then verify the index and site values in **Search Manager → Settings → Test**.

### An optional request is skipped

Read the Postman console message and fill only the named variable. A skipped targeted or developer request does not invalidate the customer folders.

For the CP-side workflow, see [Testing tools](testing-tools.md). For request parameters and response shapes, see [API endpoints](../template-guides/api-endpoints.md).
