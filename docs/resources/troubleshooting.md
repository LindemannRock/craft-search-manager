# Troubleshooting

When search isn't behaving the way you expect — no results, the wrong results, or a setting that won't save — start here.

Before changing templates or client code, reproduce the same index and query in [Testing tools](testing-tools.md). **Search Manager → Settings → Test** shows the backend, cache state, snippets, promotions, query rules, and debug metadata, which helps separate indexing problems from frontend integration problems.

## Search returns no results

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

## Dashboard redirects to another section or shows access denied

**Symptom:** A delegated operator opens **Search Manager** or `/admin/search-manager` but lands on another Search Manager section or receives an access-denied response.

**Cause:** The Dashboard appears only when at least one backend is configured and the user can access an applicable Dashboard card. Indices requires `searchManager:manageIndices`; Pro Promotions and Query Rules require their matching manage permission; Pro Analytics requires analytics to be enabled plus `searchManager:viewAnalytics`. A child permission such as `createPromotions` does not grant its parent card or section.

**Fix / checks:**

- Confirm at least one backend is configured and enabled.
- Grant the parent permission for the card the operator needs. Grant create, edit, delete, or other child permissions separately for the actions they need.
- Confirm the site is on Pro for Promotions, Query Rules, or Analytics, and confirm analytics is enabled for the Analytics card.
- A user with only `searchManager:manageBackends` is expected to land directly on **Backends**. If no Dashboard card or other section is accessible, Craft returns its normal access-denied response.

See [Permissions](../developers/permissions.md#dashboard-access) for the complete card matrix.

## Multi-word query returns nothing (or "related results")

**Symptom:** A multi-word query like `testing tool` returns fewer results than expected, or you want to understand when broader results appear.

**Cause:** Multi-word queries require every word to match the same document (AND logic). Two behaviors keep this from dead-ending:

1. **Fuzzy expansion** — every word is always expanded with its closest indexed variants (`tool` also matches documents containing only `tools`), scored below exact matches. This requires `enableFuzzy` (default: on).
2. **Relaxed matching** — if the AND combination still finds nothing, the engine broadens to match any word over the same expanded terms. The response debug meta then includes `relaxedMatching: true`, which a frontend can use to show a "showing related results" notice.

**Fix / checks:**

- If a variant like singular/plural isn't matching, confirm `enableFuzzy` is on (Settings → Search → Fuzzy Matching) and that `similarityThreshold` hasn't been raised far above the `0.25` default — a config-file override wins over the CP value (the CP shows the effective value in the override warning).
- To see exactly which terms each query word matched, call the search API with `debugEnabled=1` (requires `devMode` or the *View debug meta* permission) and inspect `meta.resolvedTerms`.

## Why isn't every query word highlighted?

**Symptom:** A result matches, but one or more words from the query are not marked in its title, split H2/H3 heading, or snippet. For example, `Choose from 7 search backends` matches while `from` remains unhighlighted.

**Cause:** The bundled widget highlights the effective terms that each result actually matched, not every raw query word.

- Built-in backends use AND for unquoted adjacent terms by default, and hyphens are token boundaries.
- With stop-word filtering enabled, common words such as `in` and `from` are removed from ordinary matching and highlighting. Disable filtering globally under **Settings → Language** or per index if those words must participate.
- Quoted text is a contiguous phrase, so `"Choose from 7 search backends"` may highlight the complete phrase.
- On built-in backends, literal words take priority per query token and displayed area. A result containing both `TEST` and the fuzzy alternative `TEXT` highlights only `TEST` for the query `test`; if that area contains only `TEXT`, the backend-confirmed fuzzy match may still be highlighted. Other query tokens are decided independently, so a genuine correction such as `jaket` → `JACKET` is retained in a mixed query.
- OR paints only the operands matched by that result. NOT operands are excluded and never painted.
- Explicit `title:` and `content:` scopes remain restrictive.
- Split-section H2/H3 rows paint every matched term that occurs in that displayed heading; parent titles and snippets keep their own field scope.

Algolia, Meilisearch, and Typesense receive the original query and apply provider-native syntax, so do not assume the built-in operator rules apply there. In a custom JavaScript UI, prefer `SearchManagerHighlighter.getHitTerms()` with the returned hit before calling `highlight()`; pass the language explicitly to `parseQuery()` when using localized operators without hit metadata.

Destination-page highlighting is deliberately looser than this — it has no hit metadata to work from. See [On the destination page](../feature-tour/highlighting.md#on-the-destination-page) if a word you expected to stay unmarked is highlighted after a click-through.

See [Which query words are highlighted?](../feature-tour/highlighting.md#which-query-words-are-highlighted) for the canonical behavior and [Client-side highlighting](../template-guides/highlighting-snippets.md#client-side-highlighting) for the public JavaScript signatures.

## Nothing is highlighted on the page after clicking a result

**Symptom:** The search widget's results are highlighted correctly, but the page a visitor lands on shows no highlights at all — with or without `?smq=…` in the address bar.

**Cause:** One of three things, in the order worth checking.

**Fix / checks:**

1. **The destination page has no widget on it.** This is the common one. Destination highlighting runs from the `<search-modal>` element as it mounts, so the *landing* page has to include the widget too — not just the pages where people start a search. There is no separate site-wide script that does this. Move `{% include 'search-manager/_widget/search-modal' %}` into a shared layout so every page that can receive a click-through has it.

2. **The content selector matches nothing.** The default `main, article, [data-search-content]` targets the `<main>` and `<article>` *elements*. A layout that wraps content in `<div id="main">` or `<div class="content">` matches none of them, and the widget stops silently — no error, no console warning. Add `data-search-content` to the wrapper you want scanned (that attribute is already in the default selector), or set **Destination Highlighting Content Selector** to a selector that matches your markup.

3. **The query never made it into the URL.** If the address bar has no `?smq=…` after the click, the link was built without it. Check that both **Enable Destination Highlighting** and **Persist Query in URL** are on for the widget that produced the result link — the parameter is written only when both are on. If the two pages use different widget configs, also confirm they use the same **Destination Highlighting Query Parameter**: a widget reading `smq` will ignore a URL carrying `q`.

See [Highlighting matches on the destination page](../feature-tour/highlighting.md#highlighting-matches-on-the-destination-page) for the full flow, and [Widget Configuration → Destination highlighting](../widget/configuration.md#destination-highlighting) for the four settings involved.

## `?smq=` is being added to my URLs

**Symptom:** Clicking a search result appends something like `?smq=redis+performance` to the URL, and analytics starts reporting those as separate page variants.

**Cause:** That's the search query being handed to the destination page so it can highlight the same terms. The parameter name is configurable and defaults to `smq`.

**Fix / checks:**

- To keep it but avoid a clash with an existing parameter, rename it in **Destination Highlighting Query Parameter** on the widget config.
- To stop it entirely, turn **Persist Query in URL** off. Result links stay clean; pages still highlight if a URL arrives carrying the parameter from elsewhere.
- To turn off destination highlighting altogether, switch **Enable Destination Highlighting** off — that stops both the parameter and the highlighting.
- In analytics, the usual treatment is to strip the parameter so the variants collapse back into one page.

## A similar-looking word is not matched

**Symptom:** A similar-looking term does not appear in search results or autocomplete, or a correction stops working after n-gram sizes change.

**Cause:** N-grams are overlapping character chunks used to estimate word similarity. Every selected size contributes to one combined score: 2-grams are more tolerant of short words and common errors, 3-grams provide a balanced default, and 4-grams increase precision but can reduce typo tolerance. More selected sizes are not automatically better. With the recommended `2,3`, `arae` can resolve to `area`; adding 4-grams can lower the combined score enough to reject it.

Similarity only builds the candidate pool; it is not the final match decision. Built-in backends then apply a query-length typo budget: 0 edits for words up to 3 characters, 1 edit for 4–7 characters, and 2 edits for 8 or more characters. Adjacent transpositions count as one, while a first-character difference receives an additional penalty and costs two in total. This is why `jaket` can resolve to `jacket`, but `tst` cannot resolve to `test`. A query such as `tezt` may legitimately resolve to both `test` and `text`; both are plausible candidates, so relevance and ranking determine their order rather than fuzzy matching inferring intent.

Prefix extensions bypass the typo budget because they are completions rather than corrections. `test` can still match and highlight `testing`; the reverse direction isn't a prefix extension.

**Fix / checks:**

- Confirm the intended correction fits the budget for the query word's length.
- Check the first character separately; changing it consumes two typo units.
- Confirm `enableFuzzy` is on and the similarity threshold isn't excluding the candidate before the typo-budget stage.
- Don't lower `similarityThreshold` to force a candidate outside the budget. The budget is a fixed precision rule, not a setting.
- `maxFuzzyCandidates` controls how many already-eligible candidates are examined. Raising it can help in a crowded vocabulary, but cannot override the threshold or typo budget.
- Rebuild affected indices after changing `ngramSizes`, because those chunks are stored during indexing. A successful rebuild already invalidates the affected search and autocomplete caches; no separate cache clear is required.
- Threshold, candidate-limit, and typo-budget changes are query-time controls and do not independently require a rebuild.

External Algolia, Meilisearch, and Typesense backends use their own native typo-tolerance policies instead of this built-in-backend rule.

If a result is found but a different word is painted, remember that exact-first highlighting runs after retrieval. It narrows confirmed highlight terms per displayed area without changing fuzzy candidates, scores, ranking, thresholds, or typo budgets. See [Why isn't every query word highlighted?](#why-isnt-every-query-word-highlighted).

## Backend cannot be deleted

Search Manager blocks backend deletion when an index still references that backend. The error lists each dependency as `Index: Name`.

**Fix:** Edit the listed indices and either choose another backend or leave the backend field empty to use the default backend. Rebuild those indices after changing backend storage so the new backend has current search data. Once no resolved index uses the backend, the backend can be deleted.

## Index cannot be deleted or its handle cannot be changed

Search Manager protects index references instead of silently breaking them. It blocks both index deletion and changes to an existing index handle when the index is still used by any of these:

- A widget's **Search Indices** setting
- A specifically scoped API key
- A query rule scoped to that index
- A promotion scoped to that index

The error identifies each dependency you have permission to view. Dependencies from other permission groups appear as counts instead of names.

Global query rules and promotions do not block an index change because they are not tied to a handle. API keys set to **All indices (current and future)** do not block it either.

**Fix:** Open the listed dependencies and move them deliberately:

- Remove the index from each listed widget.
- Change each listed API key's allowed indices.
- Select another valid index for each listed query rule or promotion, or delete that rule or promotion.

Search Manager does not cascade a handle change, delete dependent records, or convert scoped rules and promotions to global scope. Once no resolved dependency uses the handle, you can delete the index or save its new handle.

If an index was already removed outside this guarded workflow, or its config definition has an Error, Query Rules and Promotions keep the unavailable handle visible in the **Index** column and show **Error** in the **Status** column. Hover the badge for the reason. The edit page keeps that unavailable value selected, shows the same **Error** status in its sidebar, and places an error box below the Index field explaining the recovery choices. You can choose an available index, explicitly select **All Indices**, or delete the record; Search Manager does not make it global automatically.

All index selectors use the same effective catalogue. A config index with an Error is not offered for a new selection, but remains visible when it is already selected so you can correct the record. Warning-only indices remain available. A disabled but otherwise valid index also remains available and is marked **Disabled** because disabled indices are still valid configuration references.

## Index maintenance action is disabled

**Symptom:** Rebuild Index, Clear Index Data, or Sync Count is visible but disabled, or a direct CP/console/PHP request returns a structural reason without doing work.

**Cause:** Search Manager could not prove the complete action target from the authoritative index catalogue. Common causes include a config validation error, an unavailable element type or transformer, a disabled owning plugin, a missing or disabled backend, an invalid default backend, or an invalid full storage identity. A per-index backend override is strict: Search Manager will not redirect maintenance to the default backend when that override is broken.

**Fix:** Use the displayed reason to repair `config/search-manager.php`, re-enable the owning plugin/backend, or select a valid backend. Then reload the page and retry. Search Manager does not probe provider health while deciding whether an action is available, so a temporary provider outage appears only when an otherwise valid operation runs.

**What remains safe:** The index stays visible for review when its model can be resolved. **Clear Index Cache** remains available because it removes only recoverable, handle-scoped caches. A healthy disabled index can still be rebuilt explicitly, cleared, or have its external document count synchronized, but it remains excluded from automatic rebuilds and Rebuild All.

For Rebuild All, structural omissions are warnings and eligible siblings continue. “No eligible indices” is reported before queueing. If an eligible participant fails at runtime, the remaining eligible siblings are still attempted and the aggregate finishes as failed. If a queued participant becomes ineligible before execution, it is skipped before backend clear or other mutation.

## Backend, widget, style, or API-key handle cannot be changed

**Symptom:** Saving a new handle reports that the resource is in use or is the active default.

**Cause:** Handles are stable reference keys. Search Manager blocks a rename when an effective dependency or active default still stores the old handle:

- Indices can reference backend handles.
- The active default can reference a backend or widget handle.
- Widgets can reference widget-style and public API-key handles.

Config-defined resources take precedence over database rows with the same handle, so the dependency shown in the error may come from `config/search-manager.php` even when a same-handle database row exists.

**Fix:**

1. Reassign each listed index or widget to another resource, or select another default.
2. Save those owning changes and confirm they now resolve to the replacement.
3. Rename the resource after it is unused.

Search Manager does not cascade a rename, rewrite consumer references, or disable resources as a workaround. If the resource has no effective references, its handle remains editable. For a config-defined resource or dependency, make the owning change in `config/search-manager.php`.

## Indexing is slow

- **Adjust batch size**: The `batchSize` setting (default: 100) controls how many elements are loaded per batch. Increase to 250–500 for faster indexing on servers with plenty of memory. On shared or memory-constrained hosting, **lower it** to 25–50 to prevent out-of-memory errors — the rebuild takes longer but completes reliably.
- **Keep queue workers running**: Automatic and native-search indexing paths write pending-sync rows that `BatchSyncJob` drains.
- **Check your transformer**: Complex transformers that query relations or perform heavy computation slow down indexing. Pre-fetch related data where possible.
- **Rebuild during off-hours**: For sites with 10,000+ elements, schedule rebuilds during low-traffic periods to avoid queue congestion.

The `lastIndexedDebounceSeconds` setting only affects how often the "Last Indexed" metadata timestamp is written during automatic save/delete syncs. It does not delay or skip indexing work.

Automatic save/delete syncs use a pending buffer and `BatchSyncJob`. For large imports, tune `syncBatchSize` and `batchFlushInterval`: increase `syncBatchSize` to process more pending rows per job, or increase `batchFlushInterval` to coalesce import bursts more aggressively before draining.

In Craft's queue manager, rows named **Updating search indexes** are Craft's native search-index jobs, not Search Manager pending-sync rows. They often display `0%` until each individual job finishes, then the next queued row starts. A long list after a docs sync, Feed Me import, or project-content update can be normal as long as the queue worker keeps reserving and completing jobs.

## Pending syncs are not draining

If saved elements are not appearing in search:

- **Check the queue worker**: Pending syncs drain through `BatchSyncJob`; the queue must be running.
- **Check abandoned rows**: Repeated backend failures leave rows in `searchmanager_pending_syncs` with `status = abandoned` and `lastError` populated.
- **Check backend configuration**: A misconfigured backend causes pending rows to retry until `batchMaxAttempts` is reached.
- **Check `autoIndex`**: When `autoIndex` is disabled, save/delete events do not add pending sync rows.
- **Check `batchFlushInterval`**: A high value intentionally delays draining so bulk imports can coalesce.

For a triage view of the buffer with filters, per-row retry, and a one-click "Failed & Abandoned" preset, open **Search Manager → Pending Syncs**. See [Pending Syncs](../feature-tour/pending-syncs.md) for the operator runbook.

## An automatic field is missing from an indexed document

Automatic field extraction is best-effort. If one searchable attribute, custom field, nested field, relation, or container throws while Search Manager reads it, the failing source is skipped while healthy fields continue and the element is still indexed. This avoids turning one broken field integration into a retry loop for the whole element.

Check the Search Manager log for `Automatic search content extraction skipped`. Its warning identifies safe context — the element ID/type, attribute or field handle/class, extraction boundary, and exception class — without logging field values, rendered/relation content, raw rich text, exception data, or credentials.

Fix the field or provider error, then save/index the element again or rebuild the affected index. A partial best-effort document remains valid until that retry; Search Manager does not fail the whole element solely because one automatic field could not be extracted.

## Scheduled cleanup or status sync does not reappear

Search Manager schedules recurring queue jobs for analytics cleanup and entry status syncs. If the queue is empty after one of those jobs runs, the next occurrence was not scheduled correctly.

Recurring jobs should always push the next occurrence from inside the running job. Duplicate guards belong in the bootstrap path only. Logs such as `Skipping reschedule - cleanup job already exists` or `Skipping reschedule - sync job already exists` after a job runs usually mean the running queue row matched itself and prevented the next run from being queued.

During bootstrap, Search Manager collapses duplicate pending scheduler rows automatically and keeps one row for each recurring scheduler. Analytics cleanup is a fixed daily maintenance job. Status sync is an interval checker and may show a short initial delay before settling into its configured cadence.

Craft stores queue job descriptions when rows are queued, so date/time format changes apply to newly queued rows. Existing delayed rows keep their old label until they run or are requeued. Queue labels stay compact: numeric months render numerically, while short and long month settings both render as short month names.

If the job is still missing:

- Confirm the queue worker is running.
- Visit any CP page to let Search Manager bootstrap initial jobs.
- Check that `analyticsRetention` is greater than `0` for cleanup jobs.
- Check that `statusSyncInterval` is greater than `0` for status sync jobs.

## `autoIndex` is off but rows still appear

Search Manager checks the current `autoIndex` setting each time Craft fires `Elements::EVENT_AFTER_SAVE_ELEMENT` or `Elements::EVENT_AFTER_DELETE_ELEMENT`. Turning `autoIndex` off stops those listeners from adding pending sync rows.

If rows still appear after disabling `autoIndex`:

- Confirm the setting was saved and is not overridden by `config/search-manager.php`.
- Confirm the rows are new by checking `queuedAt` on the Pending Syncs page.
- Check whether another process is queueing rows directly through `SearchManager::$plugin->pendingSyncs->queueForElement()`.
- Check whether the status sync job queued rows for entries that became live or expired without a save event. That job is controlled by `statusSyncInterval`, not `autoIndex`.

`replaceNativeSearch` does not bypass this setting. Its adapter refreshes Craft's native `searchindex` for fallback coverage, while Search Manager content sync remains exclusively owned by the `autoIndex`-gated save/delete listeners.

Rows already in the buffer before `autoIndex` was disabled will still drain normally through `BatchSyncJob`.

## Settings save shows numeric field errors

Numeric settings such as cache duration, autocomplete cache duration, batch size, analytics retention, scoring boosts, and highlighting limits must use values within the range shown in the field instructions.

If a settings save fails, keep the submitted form open and check the inline field errors. Search Manager validates posted values before saving and does not partially save invalid settings.

## Widget save reports an error for a hidden Pro setting

After downgrading to Standard, Pro-only promotion, analytics, and style-preset controls are hidden. Their stored values are retained for a future re-upgrade, but they do not participate in Standard editor validation and cannot block saving a visible widget setting.

If a Standard widget save still reports an error such as `Badge Position must be one of: inline, above, below.`, update Search Manager to a version containing this fix and retry the save. To inspect or correct the preserved value itself, switch back to Pro and use the restored controls.

## Last indexed does not update after every save

Automatic save/delete syncs debounce `lastIndexed` updates for 60 seconds by default. This is expected: the element is still indexed, but the metadata timestamp is only touched once per debounce window to avoid extra database writes during imports or rapid editing.

Set `lastIndexedDebounceSeconds` to `0` if you need the timestamp updated after every successful auto-sync, or lower it to a smaller value such as `5` while testing.

## Document count looks wrong after a bulk import

The Indices page compares different units:

- **Craft** is the number of eligible Craft elements.
- **Indexed** is the comparable number of parent elements represented in the backend.
- **Documents** is the raw backend record count and appears only when the displayed list contains a Split Sections index. One parent element can produce several section documents, so this number can legitimately exceed both other columns.

Automatic save/delete syncs do not probe the backend once per element. Instead, the completed batch refreshes the authoritative backend document count once per affected index. Until that batch finishes, the stored count can briefly lag even though individual writes have already succeeded.

**If a count stays stale after the batch drains:**

- Rebuild the index: `php craft search-manager/index/rebuild --handle=entries-en`
- Use **Sync Count from Backend** on the index detail page when the configured backend supports it

Do not compare a split index's raw **Documents** value directly with **Craft**. Use **Indexed** for the parent-element comparison.

## Out of memory during rebuild

Each batch loads full elements with their relations into memory. If your server runs out of memory during a rebuild:

- **Lower `batchSize`**: Set it to `25` or `50` in your config. The default of 100 works on servers with 256 MB+ PHP memory limit, but shared hosting or entries with many relations (Matrix blocks, categories, assets) may need less.
- **Check your PHP `memory_limit`**: The rebuild respects your server's memory limit. If you can't increase it, lower `batchSize` instead.

## Rebuild job times out

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

## File index reports an unavailable manifest

**Symptom:** File-backed rich suggestions are empty, a metadata-dependent search or autocomplete operation fails closed, or the Search Manager log contains `File index manifest unavailable` followed by `Rebuild the File index`.

**Cause:** The File index is populated by an older Search Manager version and has no manifest; its `manifest.json` is missing, corrupt, incomplete, or uses an unsupported format version; or a document, element, delete, or site-clear mutation was interrupted after Search Manager marked the manifest as updating. Search Manager deliberately keeps an interrupted index non-ready and does not scan old element files or reopen every document file as a fallback, because either behavior could expose partially applied storage as authoritative.

**Fix:** Run one full rebuild for the affected index:

```bash
php craft search-manager/index/rebuild --handle=entries-en
```

The rebuild clears only that configured backend index, creates a ready empty manifest, and repopulates it through normal indexing. Do not copy a manifest from another index or create one by hand. If the error returns after a successful rebuild, confirm the configured File storage path is persistent and writable and that deployments are not replacing or partially synchronizing the directory. For edge, ephemeral, shared-volume, multi-server, or larger environments, move the index to MySQL or Redis and rebuild it there.

## Connection refused (Redis)

```text
connection-failed
```

**In Docker/DDEV:** Use the service hostname, not `127.0.0.1`:

```text
REDIS_HOST=redis
```

`127.0.0.1` refers to localhost inside the container, not your host machine.

Search Manager deliberately reports fixed Redis classifications instead of raw provider exceptions:

| Status | What to check |
|---|---|
| `extension-unavailable` | Install and enable `ext-redis` for the PHP runtime that serves Craft. |
| `not-configured` | Set a Search Manager Redis host or configure Craft's cache with the standard Yii Redis connection. |
| `unsupported-configuration` | Resolve every referenced environment variable; remove a port/password without a host; verify TLS context, Unix-socket transport, ACL credentials, and timeouts are exactly representable. |
| `connection-failed` | Check the hostname, port or socket path, network route, and Redis availability. |
| `authentication-failed` | Verify the password or the derived Craft ACL username/password without printing them. The string `"0"` is a real password, not an empty value. |
| `database-selection-failed` | Verify that the selected non-negative database exists and the account can select it. |
| `ping-failed` | Confirm the connected service accepts PING and is healthy. |

An unresolved environment reference never turns into a default, Craft fallback, or unauthenticated connection. The diagnostics response, console output, and logs omit credentials and raw exception text; correct the configuration at its source and use **Refresh Connection** to retest.

## Redis data lost after cache clear

If your search index disappears when Craft's cache is cleared:

- Your hosting platform may use `FLUSHALL` (clears all Redis databases) instead of `FLUSHDB` (clears one database)
- **Fix**: Set an explicit `database` number in your Redis backend config, or switch to MySQL/File backend

See [Redis Backend](../backends/backend-redis.md#database-selection) for selected-database details and its limits.

## Algolia/Meilisearch/Typesense connection issues

1. **Check API keys**: Verify keys in your `.env` file are correct
2. **Check host URL**: For Meilisearch, ensure the full URL including protocol: `http://localhost:7700`
3. **Check firewall**: Ensure your server can reach the external service
4. **Check logs**: Look for specific error messages in Search Manager > Logs

## Old indices still visible in my Algolia/Meilisearch/Typesense dashboard

Old provider-side indices can remain visible after removing a config-file index, renaming an index handle, or switching between backends before cleanup was available. Search Manager can clear or rebuild configured external indices, but it does not automatically purge external indices that are no longer part of the live configuration.

**Fix:** Delete the old prefixed index in the Algolia, Meilisearch, or Typesense dashboard. Before deleting anything, compare the provider index name against your current live Search Manager index handles and environment prefix so you do not remove an index still used by this project or another application.

> [!WARNING]
> External search backends run on shared provider accounts, and a matching index-name prefix does not prove Search Manager ownership. Search Manager intentionally leaves orphaned external index cleanup manual because deleting a provider index is unrecoverable and the account may also contain indices from other projects.

## Analytics not tracking

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

## Widget searches appear in Recent Searches but not Performance

**Symptom:** A widget intent appears in Recent Searches, but cache hit rate and response-time reports do not include it.

**Cause:** Performance uses only trusted direct server measurements or the bundled widget's verified, one-time cache telemetry. Legacy widget builds and intent requests with missing, invalid, expired, replayed, mismatched, or unavailable telemetry deliberately store a null execution time. The search action remains useful in Recent Searches, but it cannot safely be classified as a cache hit or miss.

**Fix / checks:** Rebuild and deploy the current widget bundle, confirm the page is not stripping the top-level `cacheTelemetry` value from search responses, and confirm Craft has a suitable cross-request application cache for replay protection. Do not copy `meta.cached` or `meta.took` into tracking requests; those legacy fields are not authoritative.

## Analytics report shows a loading error

**Symptom:** One analytics panel shows an error and a **Retry** button while other panels still display data.

**Fix:** Select **Retry** to reload that panel. If it fails again, confirm the browser session is active, check that the user can edit the selected site, and review Search Manager logs for the failed request. Changing the site or date range starts fresh report requests; Search Manager ignores late responses from the previous selection.

## Salt generator cannot update `.env`

The salt command protects an existing `.env` from partial or direct writes. It builds and verifies a temporary file in the same directory, preserves the existing mode when available, and only then atomically replaces `.env`.

If reading, preparing, writing, verifying, setting the mode, or renaming fails, the command exits non-zero and prints the generated `SEARCH_MANAGER_IP_SALT="..."` assignment. The original `.env` bytes remain unchanged and temporary files are removed. Add the printed assignment manually after resolving the file ownership or deployment restriction.

When `.env` does not exist, the command also prints the manual assignment, but that expected setup path exits successfully.

## Analytics reports or cleanup show an unexpected site scope

Interactive analytics reports, exports, row deletion, and the Utilities purge follow the current user's editable Craft sites. Administrators receive all-site access. A user with no editable sites sees no analytics rows, a zero Utility count, and deletes nothing.

If the visible scope is unexpected, check the user's Craft site permissions and sign in again after changing them. The count shown under **Utilities → Search Manager → Analytics Data Management** is the same scope the purge will delete.

Retention is intentionally different: the daily cleanup job and **Settings → Analytics → Clean Up Now** apply the global age policy across all sites. Both remove old primary search, query-rule, and promotion analytics while retaining recent rows.

## Geo-location shows wrong location

**In local development:** Private IPs (127.0.0.1, 192.168.x.x) can't be geolocated. Set defaults:

```php
// config/search-manager.php
'defaultCountry' => 'US',
'defaultCity' => 'New York',
```

**In production:** Check that your geo provider is returning data. The free tier of ip-api.com has rate limits. Consider a paid tier or different provider.

## Cache not working

1. **Is caching enabled?** Check `enableCache` is `true`.
2. **Is "Clear on Save" wiping your cache?** If `clearCacheOnSave` is `true` (default) and content is saved frequently, the cache may be clearing faster than it fills.
3. **Check storage permissions**: For file-based caching, ensure `@storage/runtime/search-manager/cache/` is writable.
4. **If Redis cache storage is enabled, check the logs**: When `cacheStorageMethod` is `redis` but Craft's `cache` component is not Redis-backed, Search Manager logs a cache-component warning and skips Redis-specific cache operations until the component is fixed.

## Widget not appearing

1. **Is the widget included?** Check your template has `{% include 'search-manager/_widget/search-modal' %}`.
2. **Is a widget config set?** If using `configHandle: 'my-config'`, verify the handle exists in the CP or config file.
3. **Is the widget enabled?** Check the widget config is enabled in Search Manager > Widgets.
4. **Check browser console**: Look for JavaScript errors that might prevent the web component from loading.

## Typesense: search misses custom fields

Typesense requires explicit `query_by` to search custom fields. The default searches `title`, `content`, `url`. For additional fields:

```twig
{%
	set results = craft.searchManager.search('products', query, {
	query_by: 'title,content,url,description,category',
	})
%}
```

## Heading children missing snippets

In hierarchical search results, heading children show query-centered snippets from the heading's section in the indexed clean body. If a heading has no snippet:

- **No query match in that section**: The heading can still appear because the page matched. When the heading section has text but no query-term context, Search Manager shows the section opening; `snippet` stays `null` only when the indexed section text is empty.
- **Heading boundary not found in the indexed body**: Heading metadata is matched back to the clean body at request time. Rebuild the index if headings or body content changed.
- **Snippet settings are restrictive**: `snippetMode`, `snippetMaxLength`, and `snippetCleanMarkdown` apply to heading snippets the same way they apply to the main snippet.

Heading snippets are plain text and are highlighted by the frontend when highlighting is enabled.

## Config file overrides CP settings

**Symptom:** You created or edited a backend, index, widget, or style in the CP, but your changes aren't taking effect — the old values keep appearing.

**Cause:** A config file definition (`config/search-manager.php`) with the same handle takes precedence over the CP version. Config-defined items show a **"Config"** badge in the CP and cannot be edited there.

**Fix:** Either rename the CP item to use a different handle, or edit the config file directly. To stop the config override, remove the item from `config/search-manager.php` — the CP version will then take effect.

## Native search replacement not working

> [!WARNING]
> `replaceNativeSearch` only works with built-in backends (MySQL, PostgreSQL, Redis, File). It does not work with Algolia, Meilisearch, or Typesense.

## Search returns 401 / 403 after enabling "Require API Key"

**Symptom:** After turning on **Require API Key** (Settings → General → API Access), the search and autocomplete endpoints return `401` ("API key required" / "Invalid API key") or `403` — including your own site's search widget.

**Symptom (also):** The widget's `track-search` / `track-click` pings return `401`/`403`.

**Cause:** With the setting enabled, the search, autocomplete, **and** tracking endpoints require a valid key in the `X-Search-Manager-Key` header. Any caller that doesn't send a valid, active, in-scope key is rejected. The bundled widget sends its configured key automatically — but only if you've selected one: choose a **public** API key via its **API Key** config field (Search Manager → Widgets → your widget) or pass a render-time `apiKey` override. Without one, the widget's own requests are rejected.

**Fix:**
- **Bundled widget:** select a **public** API key on the widget — the **API Key** field in the widget config, or an inline `apiKey` override on the include tag. Use a public key (referrer-restricted, scoped to the widget's indices), never a server key.
- For headless / mobile / custom callers: send a valid key in the `X-Search-Manager-Key` header. Check the key is enabled, not expired, and that its allowed indices cover the index you're querying. Public keys must also match their allowed referrers. Browser-based headless frontends that post `track-search` / `track-click` from another origin must also list that exact origin in `trackingAllowedOrigins` in `config/search-manager.php`; same-origin tracking does not need to be listed.
- `403` on a `siteId` request means the requested site is outside the selected index's site scope; a `400` means the `siteId` isn't a real site.
- A `429` ("API rate limit exceeded") means the key hit its per-minute `rateLimit`. Raise the key's rate limit, spread requests out, or clear it for no cap. The window resets each minute. (Tracking pings are not rate-limited.)
- If you don't need enforcement, leave **Require API Key** off — all four endpoints stay anonymous and the widget keeps working without a key. See [API Keys](../feature-tour/api-keys.md) and [API Endpoints → Authentication](../template-guides/api-endpoints.md#authentication).

## Getting help

- Check plugin logs: Search Manager > Logs
- Enable debug logging: `'logLevel' => 'debug'` in config
- Check Craft's general logs: `storage/logs/web.log`
- For persistent issues, include your Search Manager version, backend type, and relevant log entries when reporting
