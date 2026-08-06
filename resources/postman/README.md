# Search Manager — Postman Files

Use these files to explore Search Manager's public Search, Autocomplete, Track Search, and Track Click endpoints from a fresh Postman import.

Start Here needs only your Craft site URL and a query. Search Manager itself decides whether a public API key is required, which indices are available, and whether Analytics tracking is available. You do not need to declare an API mode or edition.

Plugin source: <https://github.com/LindemannRock/craft-search-manager>

## Files

- **`Search-Manager.postman_collection.json`** — customer examples plus one isolated optional developer-validation folder.
- **`Search-Manager.postman_environment.json`** — approachable placeholders with no credentials, fixture handles, or installation-specific IDs.

## Try your first request

1. Import both JSON files.
2. Select the imported **Search Manager API** environment.
3. Set `base_url` to your Craft site URL without a trailing slash.
4. Replace `query` with a term you know exists in indexed content.
5. Open **Start Here** and send **Search all allowed indices**.

Leave `public_api_key` empty when **Require API Key** is disabled. When the response is `401`, create or obtain a Search Manager **public** API key, place it in `public_api_key`, and send the request again. Public endpoints do not accept server keys.

The collection derives the `Referer` header from `base_url`. Both Start Here requests omit `indexHandles`, so the server selects:

- all enabled indices when API-key enforcement is disabled;
- the authenticated public key's allowed indices when enforcement is enabled.

A successful `200` response with zero hits or no autocomplete matches is valid. It means the API executed but the current query did not match indexed content. Try a title or term you know has been indexed. Ordinary exploratory Search requests include `skipAnalytics=1`, so trying the collection does not add search analytics.

## Environment variables

The customer variables come first and are ordered by importance:

| Variable | Required | Purpose |
|---|---:|---|
| `base_url` | Yes | Craft site URL without a trailing slash. |
| `query` | Yes | Search text; replace `test` with a known indexed term. |
| `public_api_key` | Only when enforced | Active Search Manager **public** key. |
| `index_handle` | No | One enabled index for singular examples. |
| `index_handles` | No | Two or more enabled indices separated by commas, up to the public maximum of five. |
| `site_id` | No | Real Craft site ID for site-restricted examples. |
| `element_id` | No | Real result element ID for Track Click. |

Leave installation-specific handles and IDs empty until you want their optional examples. A missing optional value skips only the request that needs it and prints an exact setup instruction.

Variables prefixed with `developer_` belong only to **Developer Validation — Optional**. They are not needed by Start Here, Search Examples, Autocomplete Examples, or Analytics.

## Search examples

Search Manager's public scope vocabulary is consistent across the collection:

- omit `indexHandles` to let the server select enabled or key-scoped indices;
- set `index_handle` to target one index;
- set `index_handles` to target multiple comma-separated indices, up to five;
- set `site_id` to add an optional site restriction.

The Search Examples folder demonstrates one index, multiple indices, one site with server-selected indices, multiple indices within one site, and the canonical widget-style response. Targeted search is not singular-only.

## Autocomplete examples

Autocomplete uses the same omitted, singular, and multiple-index vocabulary as Search. The folder includes one-index, multiple-index, suggestions-only, and results-only requests. Suggestions-only and results-only omit `indexHandles` so the server owns the scope.

## Analytics

The Analytics folder contains **Track search** and **Track click**. Do not enter an edition:

- Track Search `200`, `success=true`, `tracked=true` means the request was accepted and recorded.
- Track Search `200`, `success=true`, `tracked=false` means the request was accepted but not recorded under the current Analytics settings.
- Track Click `200`, `success=true` proves acceptance only; it does not prove persistence.
- `204` with an empty body is the Standard-edition no-op.
- Any other status remains a visible failing assertion rather than being accepted as compatible.

Track Click needs both `element_id` and `index_handle`. If either is empty, only that request is skipped with one instruction naming the missing values. If the running installation requires an API key, Analytics uses the same optional `public_api_key` and server-owned authentication settings as the other customer folders; a `401` tells you to set it and retry.

## Developer Validation — Optional

This folder is deliberately isolated from the customer examples. Use it only against a disposable local Search Manager installation with disposable keys, indices, storage, and analytics data. Never use production credentials, hosted providers, valuable indices, or valuable analytics data.

It contains exact checks for:

- missing public API key (`401`);
- invalid public API key (`401`);
- disallowed Referer (`403`);
- disallowed Origin (`403`);
- out-of-scope index (`403`);
- unknown site (`400`);
- deterministic rate limiting (`200` until the configured cap, then `429`).

Every request first requires confirmation that API-key enforcement is enabled by setting `developer_api_key_enforcement_enabled=yes`, then checks its own prerequisites. Missing confirmation or developer-only values skip only that request and print the exact missing setup. The fixed blocked Referer and Origin use the reserved `.invalid` domain.

### Deterministic rate-limit validation

The rate-limit request is **Runner-only**:

1. Create a dedicated disposable local public key used by no other request.
2. Give it a known per-minute rate limit and allow only disposable indices/referrers.
3. Set `developer_rate_limit_api_key` and the matching `developer_rate_limit_allowed_requests`.
4. Set `developer_rate_limit_runner_iterations` to a value greater than the allowed-request count.
5. Select only **Deterministic rate-limit validation** and run exactly that many iterations without crossing a minute boundary.

For a cap of `3` and `5` iterations, the expected sequence is `200`, `200`, `200`, `429`, `429`. A wrong Runner count skips with a precise instruction instead of claiming a security pass.

## Cleanup after developer validation

Delete the disposable keys, indices, backend storage, analytics rows, pending/queue rows, and local files created for the check. Restore every temporarily changed Search Manager setting exactly, then confirm no owned residue remains. Ordinary customer exploration does not require fixture cleanup beyond removing any test analytics you intentionally created through the Analytics folder.
