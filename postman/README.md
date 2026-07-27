# Search Manager — Postman Files

Use these files to exercise Search Manager's public Search, Autocomplete, Track Search, and Track Click endpoints without accepting an ambiguous result.

Authentication and edition are independent. The collection reads `api_mode` and `edition_mode` separately, then one collection-level prerequisite authority decides which requests can execute. A wrong axis, incompatible request, missing variable, or invalid rate-runner setup skips with a visible `SKIP:` reason instead of producing a misleading green assertion.

Plugin source: <https://github.com/LindemannRock/craft-search-manager>

## Files

- **`Search-Manager.postman_collection.json`** — executable public REST examples, tracking contracts, and enforcement checks.
- **`Search-Manager.postman_environment.json`** — harmless placeholders for the two independent fixture axes and their request variables.

The collection contains no real credentials or provider targets. Positive authenticated examples use public Search Manager keys only.

## Choose both fixture axes

Set both values explicitly before running:

| Axis | Values | Meaning |
|---|---|---|
| `api_mode` | `anonymous`, `keyed`, `rate-limit` | Selects the public API authentication/enforcement fixture. It never selects an edition. |
| `edition_mode` | `standard`, `pro` | Declares the Search Manager edition that is actually running. It only changes tracking expectations. |

The independent matrix is:

| API mode | Standard | Pro |
|---|---|---|
| `anonymous` | Anonymous Search/Autocomplete exact `200`; Standard Track Search/Click exact `204`. | Anonymous Search/Autocomplete exact `200`; authenticated Pro tracking examples skip. |
| `keyed` | Authenticated Search/Autocomplete and edition-independent enforcement checks; Standard Track Search/Click exact `204`. | The same authenticated API/enforcement checks, plus Pro tracking success, origin-security, and preflight contracts. |
| `rate-limit` | Deterministic rate runner; Standard tracking also retains exact `204` when the full collection runs. | The same deterministic rate runner; keyed Pro tracking examples skip. |

Authenticated Search, Autocomplete, API-key enforcement, Referer/Origin fallback, index/site scope, and rate limiting work the same in Standard and Pro. Only tracking behavior differs by edition.

## Set up the environment

1. Import both JSON files.
2. Duplicate **Search Manager API** once per disposable test fixture.
3. Select the duplicate from Postman's environment menu.
4. Set `api_mode` and `edition_mode`.
5. Replace the placeholders required by that combination.

The variables have one coherent meaning:

| Variable | Contract |
|---|---|
| `api_mode` | `anonymous`, `keyed`, or `rate-limit`. |
| `edition_mode` | `standard` or `pro`; it must match the running plugin edition. |
| `base_url` | Craft site URL without a trailing slash. |
| `api_key` | Active **public** key for keyed requests; scope it to `index_handles`. |
| `rate_limit_api_key` | Fresh dedicated **public** key used only by the rate runner. |
| `invalid_api_key` | Deliberately invalid public-key-shaped placeholder used for the exact `401` check. |
| `referrer` | URL whose host is allowed by `api_key`; use the same origin as `base_url` for the isolated fixture. |
| `blocked_referrer` | URL whose host is absent from the key's allowed-referrer list. |
| `origin` | Origin allowed by the public key. For Pro tracking without `trackingAllowedOrigins`, use the same origin as `base_url`. |
| `blocked_origin` | Host rejected by public-key Origin fallback and, in Pro, by the tracking origin gate. |
| `query` | Harmless query owned by the disposable fixture. |
| `index_handles` | Enabled fixture index allowed by both public keys. |
| `index_handle` | One enabled fixture index for Track Click's singular `index` parameter. |
| `blocked_index_handle` | A second enabled fixture index that exists but is outside `api_key`'s allowlist. |
| `results_limit` | Positive Search/Autocomplete result cap. |
| `site_id` | Real Craft site covered by `index_handles`. |
| `unknown_site_id` | ID that does not resolve to a Craft site. |
| `element_id` | Harmless fixture element ID for Track Click's log-only payload. |
| `results_count` | Result count sent to Track Search. |
| `trigger` | One of `click`, `enter`, `idle`, or `unknown`. |
| `analytics_source` | Short source label for fixture-owned analytics rows. |
| `rate_limit_allowed_requests` | Exact per-minute cap configured on `rate_limit_api_key`. |
| `rate_limit_runner_iterations` | Exact Runner iteration count; it must be greater than the allowed-request cap. |

The shipped axis and credential values are empty so an imported collection cannot silently claim a fixture it has not verified.

## Run anonymous Search and Autocomplete

1. Turn **Require API Key** off.
2. Set `api_mode` to `anonymous`.
3. Set `edition_mode` to the running edition.
4. Run the full collection or the Search and Autocomplete folders.

The two anonymous requests return exact `200` with their public response shapes in either edition. Keyed requests skip. On Standard, both tracking no-ops also return exact `204` when the full collection runs.

## Run authenticated APIs and enforcement

1. Turn **Require API Key** on.
2. Create an active public key:
   - allow only `index_handles`;
   - allow the hosts used by `referrer` and `origin`;
   - choose a `maxHitsPerPage` at or above the requested fixture limit;
   - leave its rate limit empty or high enough for the complete collection run.
3. Create or select a second enabled `blocked_index_handle` that the key does not allow.
4. Use a real `site_id` covered by `index_handles`.
5. Set `api_mode` to `keyed`.
6. Set `edition_mode` to the running edition.
7. Run the full collection.

In both Standard and Pro, this proves:

- public-key Search and Autocomplete return exact `200`;
- Referer and Origin fallback authenticate when allowed;
- missing or invalid keys return exact `401`;
- disallowed public-key Referer and Origin return exact `403`;
- an existing out-of-scope index returns exact `403`;
- an unknown keyed site returns exact `400`.

With `edition_mode=standard`, Track Search and Track Click independently return exact `204` with empty bodies. With `edition_mode=pro`, authenticated Track Search and Track Click return exact `200`, a disallowed tracking Origin returns exact `403`, and same-origin preflight returns exact `204`.

Tracking is not rate-limited. Search and Autocomplete are.

## Run the deterministic rate-limit contract

1. Create a fresh public key used by no other request.
2. Give it a per-minute limit equal to `rate_limit_allowed_requests`.
3. Allow `index_handles` and `referrer`.
4. Clear that key's current rate counter or wait for a fresh minute.
5. Set `api_mode` to `rate-limit`.
6. Set `edition_mode` to the running edition.
7. Select **Enforcement Checks → Rate-limit Runner - deterministic 200 then 429**.
8. Run exactly `rate_limit_runner_iterations` iterations without crossing a minute boundary.

For a cap of `3` and `5` iterations, iterations 1–3 return exact `200`; iterations 4–5 return exact `429`. The behavior is identical in Standard and Pro. A different Runner count produces an explicit prerequisite skip.

## Clean up

After an isolated run:

1. Delete both fixture public keys.
2. Delete the fixture indices and backend storage they own.
3. Remove fixture analytics and pending/queue rows.
4. Restore any temporarily changed Search Manager settings exactly.
5. Confirm the disposable marker no longer appears in API keys, indices, backends, analytics, storage, pending syncs, queue payloads, or users.

Do not run enforcement or rate-limit fixtures against production credentials, hosted providers, private networks, or valuable indices.
