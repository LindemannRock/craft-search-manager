# Search Manager — Postman Files

User-facing Postman collection and environment template for the Search Manager API.

Plugin source: <https://github.com/LindemannRock/craft-search-manager>

## Files

- **`Search-Manager.postman_collection.json`** — examples for search, autocomplete, analytics tracking, and API-key enforcement checks.
- **`Search-Manager.postman_environment.json`** — reusable environment template with placeholders only.

## Setup

1. Import both files into Postman.
2. Duplicate **Search Manager API** once per target environment, for example:
   - **Search Manager — DDEV**
   - **Search Manager — Staging**
   - **Search Manager — UAT**
   - **Search Manager — Production**
3. Select the duplicated environment from Postman's environment dropdown.
4. Set:
   - `base_url` → your Craft site URL, no trailing slash.
   - `indices` → one or more enabled Search Manager index handles, comma-separated.
   - `index_handle` → one enabled index handle, used by `track-click`.
   - `query` → a query that should return results on your install.
   - `site_id` → a real Craft site ID, if you want to test site filtering.
   - `element_id` → a real element ID, if you want `track-click` to represent a real result.
5. If **Require API Key** is enabled, also set:
   - `api_key` → a **public** Search Manager key for browser/widget/custom-JS requests.
   - `referrer` → a URL whose host matches that public key's allowed referrers.
   - `server_api_key` → a **server** key only for the server-key example request.

The key values are Postman secret variables in the environment template. The shipped file contains no real keys.

Only `base_url` and the environment values change between DDEV, staging, UAT, and production. The collection requests all use `{{base_url}}` plus explicit Postman path segments, matching Postman's standard import format.

## Which Key Type to Use

Use a **public** key for browser-side callers:

- Search Manager frontend widget
- Custom JavaScript search templates
- Search pages rendered in the browser

Public keys are visible in HTML, JS, and browser network tools. Scope them narrowly: allowed indices, strict referrer patterns, expiry, max hits, and rate limit.

Use a **server** key for backend-to-backend callers:

- Your app backend calling Search Manager
- A mobile app calling your own backend, where your backend then calls Search Manager
- Internal server jobs or integration services

Do not embed server keys in HTML, JavaScript, or mobile app binaries.

## Recommended Test Flows

### 1. Anonymous Mode

With **Require API Key** off:

1. Run **Enforcement Checks → Missing key - 200 when off, 401 when on**.
2. Run **Search API → Search - basic keyed or anonymous** with `api_key` empty.
3. Run **Autocomplete API → Autocomplete - suggestions and results** with `api_key` empty.
4. Run both **Analytics Tracking** requests with `api_key` empty.

Expected: the normal requests return `200`.

### 2. Keyed Mode

With **Require API Key** on:

1. Create a public API key in Search Manager.
2. Scope it to the test index in `indices`.
3. Add an allowed referrer matching `referrer`.
4. Paste the plaintext key into the `api_key` environment variable.
5. Run the **Search API**, **Autocomplete API**, and **Analytics Tracking** folders.

Expected: valid keyed requests return `200`. Missing or invalid key checks return `401`.

### 3. Scope Checks

Use the **Enforcement Checks** folder:

- **Public key bad referrer** should return `403` when the key has allowed referrers and `blocked_referrer` does not match.
- **Out-of-scope index** should return `403` when `blocked_index_handle` is not allowed by the key.
- **Unknown site** should return `400` for keyed requests when `unknown_site_id` is not a real Craft site ID.

Wildcard/all-index keys may make the out-of-scope request return `200`; use a narrowly scoped key to test the `403` path.

### 4. Rate Limit

Set a low per-key rate limit, such as `3` requests per minute, then use Postman Runner on **Enforcement Checks → Rate limit probe - repeat with Runner** with more iterations than the cap.

Expected: the first requests return `200`; later requests return `429` until the next one-minute window. Search and autocomplete count toward this cap. Tracking pings do not.

## Notes

- The collection sends `X-Search-Manager-Key` when the environment variable is set.
- Public-key referrer checks use the request's `Referer` header. The collection sets it from `referrer` so browser behavior can be tested from Postman.
- `track-search` records analytics rows when analytics is enabled. `track-click` is gated but currently log-only.
- The enriched search request mirrors the frontend widget/custom-template request shape.
