# Troubleshooting

Common issues and solutions for Search Manager.

## Search Returns No Results

**Check the basics first:**

1. **Is the index built?** Run `php craft search-manager/index/rebuild` or `ddev craft search-manager/index/rebuild`.

2. **Is the index enabled?** Check Search Manager > Indices — the index should show as enabled.

3. **Does the index have content?** Go to Search Manager > Indices and check the document count. If it's 0, the criteria filter may be too restrictive.

4. **Are you searching the right index?** Verify the index handle in your template matches the configured handle.

5. **Is the backend available?** Go to Search Manager > Backends and check the status. For external backends, verify the connection.

**Debugging tips:**

- Check plugin logs at Search Manager > Logs (or `storage/logs/search-manager.log`)
- Enable debugEnabled logging: set `logLevel` to `'debugEnabled'` in your config
- If using `replaceNativeSearch`, verify it only works with built-in backends (MySQL, PostgreSQL, Redis, File)

## Element Stays in Index After Editor Change

**Symptom:** An editor changes a field that the index's criteria filter depends on (e.g. marks a product `sold`, flips a custom status, sets an expiry date), but the element still appears in search results. Running a full rebuild removes it; the next edit of the same kind brings the problem back.

**Quick checks:**

1. The index is using a `criteria` closure that filters by the field that changed (e.g. `->section(['products'])->status('available')` or a custom query method).
2. The element fires `EVENT_AFTER_SAVE_ELEMENT` normally — other edits to the same element do sync.
3. The Pending Syncs page (`/admin/search-manager/pending-syncs`) shows the element either drained (sync completed) or stuck in `failed` / `abandoned` (sync attempted but couldn't complete).

**Fix:** Upgrade to Search Manager 5.44.0 or later. Earlier versions would silently skip the sync when an element's field change made it no longer match the index criteria — the element would stay in the backend with stale data until a full rebuild. The sync now removes stale documents from any index whose criteria no longer matches, regardless of whether the element is still enabled.

**Why this happened:** The auto-sync previously re-ran the index criteria to decide which indices to touch. When criteria excluded the element, the sync correctly saw "this element doesn't belong in index X" — but then did nothing, rather than removing the old document.

## Indexing Is Slow

- **Adjust batch size**: The `batchSize` setting (default: 100) controls how many elements are loaded per batch. Increase to 250–500 for faster indexing on servers with plenty of memory. On shared or memory-constrained hosting, **lower it** to 25–50 to prevent out-of-memory errors — the rebuild takes longer but completes reliably.
- **Use queue-based indexing**: Ensure `queueEnabled` is `true` (default).
- **Check your transformer**: Complex transformers that query relations or perform heavy computation slow down indexing. Pre-fetch related data where possible.
- **Rebuild during off-hours**: For sites with 10,000+ elements, schedule rebuilds during low-traffic periods to avoid queue congestion.

The `lastIndexedDebounceSeconds` setting only affects how often the "Last Indexed" metadata timestamp is written during automatic save/delete syncs. It does not delay or skip indexing work.

Automatic save/delete syncs use a pending buffer and `BatchSyncJob`. For large imports, tune `syncBatchSize` and `batchFlushInterval`: increase `syncBatchSize` to process more pending rows per job, or increase `batchFlushInterval` to coalesce import bursts more aggressively before draining.

In Craft's queue manager, rows named **Updating search indexes** are Craft's native search-index jobs, not Search Manager pending-sync rows. They often display `0%` until each individual job finishes, then the next queued row starts. A long list after a docs sync, Feed Me import, or project-content update can be normal as long as the queue worker keeps reserving and completing jobs.

## Pending Syncs Are Not Draining

If saved elements are not appearing in search:

- **Check the queue worker**: Pending syncs drain through `BatchSyncJob`; the queue must be running.
- **Check abandoned rows**: Repeated backend failures leave rows in `searchmanager_pending_syncs` with `status = abandoned` and `lastError` populated.
- **Check backend configuration**: A misconfigured backend causes pending rows to retry until `batchMaxAttempts` is reached.
- **Check `autoIndex`**: When `autoIndex` is disabled, save/delete events do not add pending sync rows.
- **Check `batchFlushInterval`**: A high value intentionally delays draining so bulk imports can coalesce.

For a triage view of the buffer with filters, per-row retry, and a one-click "Failed & Abandoned" preset, open **Search Manager → Pending Syncs**. See [Pending Syncs](../feature-tour/pending-syncs.md) for the operator runbook.

## Scheduled Cleanup or Status Sync Does Not Reappear

Search Manager schedules recurring queue jobs for analytics cleanup and entry status syncs. If the queue is empty after one of those jobs runs, the next occurrence was not scheduled correctly.

Recurring jobs should always push the next occurrence from inside the running job. Duplicate guards belong in the bootstrap path only. Logs such as `Skipping reschedule - cleanup job already exists` or `Skipping reschedule - sync job already exists` after a job runs usually mean the running queue row matched itself and prevented the next run from being queued.

During bootstrap, Search Manager collapses duplicate pending scheduler rows automatically and keeps one row for each recurring scheduler. Analytics cleanup is a fixed daily maintenance job. Status sync is an interval checker and may show a short initial delay before settling into its configured cadence.

Craft stores queue job descriptions when rows are queued, so date/time format changes apply to newly queued rows. Existing delayed rows keep their old label until they run or are requeued. Queue labels stay compact: numeric months render numerically, while short and long month settings both render as short month names.

If the job is still missing:

- Confirm the queue worker is running.
- Visit any CP page to let Search Manager bootstrap initial jobs.
- Check that `analyticsRetention` is greater than `0` for cleanup jobs.
- Check that `statusSyncInterval` is greater than `0` for status sync jobs.

## `autoIndex` Is Off but Rows Still Appear

Search Manager checks the current `autoIndex` setting each time Craft fires `Elements::EVENT_AFTER_SAVE_ELEMENT` or `Elements::EVENT_AFTER_DELETE_ELEMENT`. Turning `autoIndex` off stops new save/delete events from adding pending sync rows.

If rows still appear after disabling `autoIndex`:

- Confirm the setting was saved and is not overridden by `config/search-manager.php`.
- Confirm the rows are new by checking `queuedAt` on the Pending Syncs page.
- Check whether another process is queueing rows directly through `SearchManager::$plugin->pendingSyncs->queueForElement()`.
- Check whether the status sync job queued rows for entries that became live or expired without a save event. That job is controlled by `statusSyncInterval`, not `autoIndex`.

Rows already in the buffer before `autoIndex` was disabled will still drain normally through `BatchSyncJob`.

## Settings Save Shows Numeric Field Errors

Numeric settings such as cache duration, autocomplete cache duration, batch size, analytics retention, scoring boosts, and highlighting limits must use values within the range shown in the field instructions.

If a settings save fails, keep the submitted form open and check the inline field errors. Search Manager validates posted values before saving and does not partially save invalid settings.

## Last Indexed Does Not Update After Every Save

Automatic save/delete syncs debounce `lastIndexed` updates for 60 seconds by default. This is expected: the element is still indexed, but the metadata timestamp is only touched once per debounce window to avoid extra database writes during imports or rapid editing.

Set `lastIndexedDebounceSeconds` to `0` if you need the timestamp updated after every successful auto-sync, or lower it to a smaller value such as `5` while testing.

## Document Count Looks Wrong After a Bulk Import

The "Documents" column on the Indices index page is **eventually consistent**. Automatic save/delete syncs don't adjust this counter — doing so would require a backend probe per element, which would undo the API-amplification reduction that batch sync provides.

This is by design, not a bug. Search results themselves are correct (the underlying index is updated as elements sync); only the metadata badge is delayed.

**To force an accurate count:**

- Rebuild the index: `php craft search-manager/index/rebuild --handle=entries-en`
- Use the count refresh action on the index detail page (where exposed)

If you regularly need real-time counts (e.g. for editor-facing dashboards), schedule a periodic rebuild for that index rather than relying on the live counter.

## Out of Memory During Rebuild

Each batch loads full elements with their relations into memory. If your server runs out of memory during a rebuild:

- **Lower `batchSize`**: Set it to `25` or `50` in your config. The default of 100 works on servers with 256 MB+ PHP memory limit, but shared hosting or entries with many relations (Matrix blocks, categories, assets) may need less.
- **Check your PHP `memory_limit`**: The rebuild respects your server's memory limit. If you can't increase it, lower `batchSize` instead.

## Rebuild Job Times Out

```text
The process "'/usr/local/bin/php' './craft' 'queue/exec' '1008994' '300' ..."
exceeded the timeout of 300 seconds.
```

The rebuild job has a 30-minute TTR (time to reserve) by default. If your index is very large and still times out, you can increase the global queue TTR in `config/app.php`:

```php
'components' => [
    'queue' => [
        'ttr' => 3600, // 1 hour
    ],
],
```

Other tips for large rebuilds:

- **Lower `batchSize`** to `25`–`50` — smaller batches mean more progress checkpoints
- **Rebuild individual indices** instead of all at once: `php craft search-manager/index/rebuild --handle=my-index`
- **Check your transformer** — slow transformers (heavy relation queries, API calls) multiply rebuild time

## Duplicate Key Errors During Indexing (MySQL)

```text
Integrity constraint violation: 1062 Duplicate entry 'my-index-البحـر-2-60807'
for key 'searchmanager_search_terms.PRIMARY'
```

This happens when content contains Unicode character variants that MySQL's `utf8mb4_0900_ai_ci` collation treats as equivalent — for example, Arabic text with tatweel (`البحـر` vs `البحر`), Arabic-Indic digits (`٢` vs `2`), or accented Latin characters (`jalapeño` vs `jalapeno`).

**Fix:** Update to the latest version of Search Manager and rebuild your indices. The current version normalizes all text before storage (tatweel removal, digit folding, accent folding) and uses upsert writes on MySQL to handle any remaining collation equivalences gracefully. See [Text Normalization](../feature-tour/search-features.md#text-normalization) for details.

## Connection Refused (Redis)

```text
[ERROR] Redis connection error | {"host":"127.0.0.1","port":6379,"error":"Connection refused"}
```

**In Docker/DDEV:** Use the service hostname, not `127.0.0.1`:

```text
REDIS_HOST=redis
```

`127.0.0.1` refers to localhost inside the container, not your host machine.

## Redis Data Lost After Cache Clear

If your search index disappears when Craft's cache is cleared:

- Your hosting platform may use `FLUSHALL` (clears all Redis databases) instead of `FLUSHDB` (clears one database)
- **Fix**: Set an explicit `database` number in your Redis backend config, or switch to MySQL/File backend

See [Redis Backend](../backends/backend-redis.md) for database isolation details.

## Algolia/Meilisearch/Typesense Connection Issues

1. **Check API keys**: Verify keys in your `.env` file are correct
2. **Check host URL**: For Meilisearch, ensure the full URL including protocol: `http://localhost:7700`
3. **Check firewall**: Ensure your server can reach the external service
4. **Check logs**: Look for specific error messages in Search Manager > Logs

## Analytics Not Tracking

1. **Is analytics enabled?** Check `enableAnalytics` is `true` in settings.
2. **Is analytics enabled for the index?** Per-index analytics can be disabled with `enableAnalytics: false`.
3. **Is the IP hash salt configured?** Open **Search Manager → Setup** if the salt is missing. Search still works, but setup remains incomplete and analytics privacy features are limited until the salt is configured. Set the salt with:

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

4. **Check queue**: Geo-location runs as a queue job. If your queue isn't processing, geo data won't be recorded.

## Geo-Location Shows Wrong Location

**In local development:** Private IPs (127.0.0.1, 192.168.x.x) can't be geolocated. Set defaults:

```php
// config/search-manager.php
'defaultCountry' => 'US',
'defaultCity' => 'New York',
```

**In production:** Check that your geo provider is returning data. The free tier of ip-api.com has rate limits. Consider a paid tier or different provider.

## Cache Not Working

1. **Is caching enabled?** Check `enableCache` is `true`.
2. **Is "Popular Queries Only" enabled?** If `cachePopularQueriesOnly` is `true`, queries must be searched `popularQueryThreshold` times before caching kicks in.
3. **Is "Clear on Save" wiping your cache?** If `clearCacheOnSave` is `true` (default) and content is saved frequently, the cache may be clearing faster than it fills.
4. **Check storage permissions**: For file-based caching, ensure `@storage/runtime/search-manager/cache/` is writable.
5. **If Redis cache storage is enabled, check the logs**: When `cacheStorageMethod` is `redis` but Craft's `cache` component is not Redis-backed, Search Manager logs a cache-component warning and skips Redis-specific cache operations until the component is fixed.

## Widget Not Appearing

1. **Is the widget included?** Check your template has `{% include 'search-manager/_widget/search-modal' %}`.
2. **Is a widget config set?** If using `configHandle: 'my-config'`, verify the handle exists in the CP or config file.
3. **Is the widget enabled?** Check the widget config is enabled in Search Manager > Widgets.
4. **Check browser console**: Look for JavaScript errors that might prevent the web component from loading.

## Typesense: Search Misses Custom Fields

Typesense requires explicit `query_by` to search custom fields. The default searches `title`, `content`, `url`. For additional fields:

```twig
{%
	set results = craft.searchManager.search('products', query, {
	query_by: 'title,content,url,description,category',
	})
%}
```

## Heading Children Missing Snippets

In hierarchical search results, heading children show query-centered snippets from the heading's section in the indexed clean body. If a heading has no snippet:

- **No query match in that section**: The heading can still appear because the page matched. When the heading section has text but no query-term context, Search Manager shows the section opening; `snippet` stays `null` only when the indexed section text is empty.
- **Heading boundary not found in the indexed body**: Heading metadata is matched back to the clean body at request time. Rebuild the index if headings or body content changed.
- **Snippet settings are restrictive**: `snippetMode`, `snippetMaxLength`, and `snippetCleanMarkdown` apply to heading snippets the same way they apply to the main snippet.

Heading snippets are plain text and are highlighted by the frontend when highlighting is enabled.

## Config File Overrides CP Settings

**Symptom:** You created or edited a backend, index, widget, or style in the CP, but your changes aren't taking effect — the old values keep appearing.

**Cause:** A config file definition (`config/search-manager.php`) with the same handle takes precedence over the CP version. Config-defined items show a **"Config"** badge in the CP and cannot be edited there.

**Fix:** Either rename the CP item to use a different handle, or edit the config file directly. To stop the config override, remove the item from `config/search-manager.php` — the CP version will then take effect.

## Native Search Replacement Not Working

> [!WARNING]
> `replaceNativeSearch` only works with built-in backends (MySQL, PostgreSQL, Redis, File). It does not work with Algolia, Meilisearch, or Typesense.

## Search Returns 401 / 403 After Enabling "Require API Key"

**Symptom:** After turning on **Require API Key** (Settings → General → API Access), the search and autocomplete endpoints return `401` ("API key required" / "Invalid API key") or `403` — including your own site's search widget.

**Symptom (also):** The widget's `track-search` / `track-click` pings return `401`/`403`.

**Cause:** With the setting enabled, the search, autocomplete, **and** tracking endpoints require a valid key in the `X-Search-Manager-Key` header. Any caller that doesn't send a valid, active, in-scope key is rejected. The bundled widget sends its configured key automatically — but only if you've selected one: choose a **public** API key via its **API Key** config field (Search Manager → Widgets → your widget) or pass a render-time `apiKey` override. Without one, the widget's own requests are rejected.

**Fix:**
- **Bundled widget:** select a **public** API key on the widget — the **API Key** field in the widget config, or an inline `apiKey` override on the include tag. Use a public key (referrer-restricted, scoped to the widget's indices), never a server key.
- For headless / mobile / custom callers: send a valid key in the `X-Search-Manager-Key` header. Check the key is enabled, not expired, and that its allowed indices cover the index you're querying. Public keys must also match their allowed referrers. Browser-based headless frontends that post `track-search` / `track-click` from another origin must also list that exact origin in `trackingAllowedOrigins` in `config/search-manager.php`; same-origin tracking does not need to be listed.
- `403` on a `siteId` request means the requested site is outside the selected index's site scope; a `400` means the `siteId` isn't a real site.
- A `429` ("API rate limit exceeded") means the key hit its per-minute `rateLimit`. Raise the key's rate limit, spread requests out, or clear it for no cap. The window resets each minute. (Tracking pings are not rate-limited.)
- If you don't need enforcement, leave **Require API Key** off — all four endpoints stay anonymous and the widget keeps working without a key. See [API Keys](../feature-tour/api-keys.md) and [API Endpoints → Authentication](../template-guides/api-endpoints.md#authentication).

## Getting Help

- Check plugin logs: Search Manager > Logs
- Enable debugEnabled logging: `'logLevel' => 'debugEnabled'` in config
- Check Craft's general logs: `storage/logs/web.log`
- For persistent issues, include your Search Manager version, backend type, and relevant log entries when reporting
