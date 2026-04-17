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
- Enable debug logging: set `logLevel` to `'debug'` in your config
- If using `replaceNativeSearch`, verify it only works with built-in backends (MySQL, PostgreSQL, Redis, File)

## Element Stays in Index After Editor Change

**Symptom:** An editor changes a field that the index's criteria filter depends on (e.g. marks a product `sold`, flips a custom status, sets an expiry date), but the element still appears in search results. Running a full rebuild removes it; the next edit of the same kind brings the problem back.

**Quick checks:**

1. The index is using a `criteria` closure that filters by the field that changed (e.g. `->section(['products'])->status('available')` or a custom query method).
2. Logs show a `Sync element state` line for the element after the save, but no `Element removed from index` or `Element indexed successfully` line follows.
3. The element fires `EVENT_AFTER_SAVE_ELEMENT` normally — other edits to the same element do sync.

**Fix:** Upgrade to Search Manager 5.43.2 or later. Earlier versions would silently skip the sync when an element's field change made it no longer match the index criteria — the element would stay in the backend with stale data until a full rebuild. The sync now removes stale documents from any index whose criteria no longer matches, regardless of whether the element is still enabled.

**Why this happened:** The auto-sync previously re-ran the index criteria to decide which indices to touch. When criteria excluded the element, the sync correctly saw "this element doesn't belong in index X" — but then did nothing, rather than removing the old document.

## Indexing Is Slow

- **Adjust batch size**: The `batchSize` setting (default: 100) controls how many elements are loaded per batch. Increase to 250–500 for faster indexing on servers with plenty of memory. On shared or memory-constrained hosting, **lower it** to 25–50 to prevent out-of-memory errors — the rebuild takes longer but completes reliably.
- **Use queue-based indexing**: Ensure `queueEnabled` is `true` (default).
- **Check your transformer**: Complex transformers that query relations or perform heavy computation slow down indexing. Pre-fetch related data where possible.
- **Rebuild during off-hours**: For sites with 10,000+ elements, schedule rebuilds during low-traffic periods to avoid queue congestion.

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
- **Rebuild individual indices** instead of all at once: `php craft search-manager/index/rebuild my-index`
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

See [Redis Backend](backends/backend-redis.md) for database isolation details.

## Algolia/Meilisearch/Typesense Connection Issues

1. **Check API keys**: Verify keys in your `.env` file are correct
2. **Check host URL**: For Meilisearch, ensure the full URL including protocol: `http://localhost:7700`
3. **Check firewall**: Ensure your server can reach the external service
4. **Check logs**: Look for specific error messages in Search Manager > Logs

## Analytics Not Tracking

1. **Is analytics enabled?** Check `enableAnalytics` is `true` in settings.
2. **Is analytics enabled for the index?** Per-index analytics can be disabled with `enableAnalytics: false`.
3. **Is the IP hash salt configured?** Without a salt, IP hashing and geo-location won't work (but basic analytics still tracks). Generate one:

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

## Widget Not Appearing

1. **Is the widget included?** Check your template has `{% include 'search-manager/_widget/search-modal' %}`.
2. **Is a widget config set?** If using `config: 'my-config'`, verify the handle exists in the CP or config file.
3. **Is the widget enabled?** Check the widget config is enabled in Search Manager > Widgets.
4. **Check browser console**: Look for JavaScript errors that might prevent the web component from loading.

## Typesense: Search Misses Custom Fields

Typesense requires explicit `query_by` to search custom fields. The default searches `title`, `content`, `url`. For additional fields:

```twig
{% set results = craft.searchManager.search('products', query, {
    query_by: 'title,content,url,description,category',
}) %}
```

## Heading Children Missing Descriptions

In hierarchical search results, heading children show a description extracted from the paragraph text directly below each heading. If a heading has no description:

- **No text between headings**: If a heading is immediately followed by a sub-heading with no paragraph in between, the description will be empty. Add an introductory sentence below the heading in your content.
- **Content starts with a code block**: The description is extracted from the first `<p>` tag after the heading. If the content starts with `<pre>` or `<code>` instead, the description may show raw code or be empty.
- **Content not re-indexed**: Heading descriptions are extracted at index time. After editing content, rebuild the index for changes to appear.

The heading description is static — it always shows the same text regardless of the search query. The parent result's description is query-aware and centers around the matched term (controlled by `snippetMode` and `snippetLength`).

## Native Search Replacement Not Working

> [!WARNING]
> `replaceNativeSearch` only works with built-in backends (MySQL, PostgreSQL, Redis, File). It does not work with Algolia, Meilisearch, or Typesense.

## Getting Help

- Check plugin logs: Search Manager > Logs
- Enable debug logging: `'logLevel' => 'debug'` in config
- Check Craft's general logs: `storage/logs/web.log`
- For persistent issues, include your Search Manager version, backend type, and relevant log entries when reporting
