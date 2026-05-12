# Sync Failures

**CP:** Search Manager → Sync Failures

A triage view for the L3 pending-sync buffer (`searchmanager_pending_syncs`). When an element is saved or deleted, the plugin queues a row in the buffer; `BatchSyncJob` drains the buffer to your configured search backend in the background.

**This page is empty in a healthy system.** It exists for the rare moment a row fails or stalls — you come here to see what went wrong, retry, or purge.

## How saves reach the search backend

You don't need to do anything for this. Saving an entry triggers the auto-sync listener (when `autoIndex` is on), which queues rows into the buffer. `BatchSyncJob` then runs as part of Craft's queue and drains the buffer to your backend, claiming rows in `syncBatchSize` chunks and looping until empty (or until its time budget is exhausted on a true mega-import, in which case it schedules a continuation).

In normal operation:

- Save entry → rows queue → `BatchSyncJob` drains → search backend updated. Sub-10-second round trip.
- You never visit this page. It's empty.

## When the page has something to show

Sync Failures (the default view) surfaces only rows that need attention:

- **`failed`** — the last `batchIndex` / `batchDelete` call to the backend returned an error. The row will retry on its scheduled `next attempt` with exponential backoff.
- **`abandoned`** — the row hit `batchMaxAttempts` failed attempts and stopped retrying. It will sit here forever until you `Retry` it (after fixing the cause) or `Purge` it (to give up on the queued work).

Typical triggers:

- Backend went down (auth, network, rate limit) → multiple rows land at `failed`.
- A bad transformer change caused malformed documents → rows fail with a schema error.
- A backend was decommissioned but indices still target it → rows fail to write and eventually abandon.

## Columns

| Column | Meaning |
|---|---|
| **Index** | The search index handle the row writes to (or deletes from). |
| **Element** | Link to the Craft element if it's propagated to the row's site. `Type #ID · not on this site` when the element exists but not on this site (routine — the processor flips the row to a backend delete on drain). `Type #ID · deleted` (amber) when the element was deleted everywhere. |
| **Site** | Which site the operation targets. |
| **Op** | `upsert` (write the document) or `delete` (remove the document). |
| **Status** | `failed` or `abandoned` in the default view. `pending` and `processing` show only when "Show all rows" is on. |
| **Attempts** | How many times a worker has claimed and tried this row. Resets when you Retry. |
| **Queued / Next attempt** | When the row was queued and when the next retry is scheduled. |
| **Last error** | Truncated error message; hover for the full text. Plain text, never rendered as HTML. |

## Filters

| Filter | Use |
|---|---|
| **Status** | Narrow to a specific status. |
| **Index** | Limit to one search index. |
| **Op** | `upsert` or `delete`. |
| **Site** | Multi-site installs. |

The free-text search box matches against element IDs (numeric) and last-error substrings.

### "Show all rows" toggle

By default the page shows only `failed` and `abandoned` rows. Click **Show all rows** in the toolbar to see the entire buffer including mid-flight `pending` and `processing` rows. The page title flips between "Sync Failures" and "Pending Syncs" to match.

Use this when you're debugging mid-flight behavior — e.g., confirming a save actually queued, or watching the drain in real time. In day-to-day operation you never need it.

## Actions

### Per-row

- **View element** — opens the element's CP edit page (hidden when the element is gone).
- **Retry now** — resets the row to `pending` with `attemptCount = 0`, clears `claimToken`, `claimedAt`, `lastError`, and `lastProcessedAt`, and forces `nextAttemptAt = now`. The next `BatchSyncJob` claims it. Disabled while a worker has the row actively claimed (within the stale cutoff) to avoid racing.
- **Delete from buffer** — hard-deletes the row. Use when the row is genuinely garbage (e.g., a reference to a deleted index). Same fresh-processing guard as Retry.

### Bulk

Select rows with the checkboxes and use **Retry** or **Delete** at the bottom of the table.

### Toolbar

- **Show all rows / Show failures only** — toggles between the default failures-only view and the full-buffer view.
- **Purge abandoned** — appears when there are `abandoned` rows. Deletes every row at `status = abandoned`. Confirms first; cannot be undone. Use after fixing the cause of repeated failures and deciding you'd rather drop the queued operations than retry them.

## Permissions

Follows the plugin's nested `Manage X` convention — the parent grants access to the page, destructive actions are gated behind nested permissions.

| Permission | Grants |
|---|---|
| `searchManager:manageSyncFailures` | Access the page. **View-only**; no destructive actions. |
| `searchManager:retrySyncFailures` | Retry rows (per-row or bulk). |
| `searchManager:purgeSyncFailures` | Delete rows from the buffer · Purge all abandoned. |

A typical assignment:

- **Operators** — `manageSyncFailures` only. They can see what failed and escalate.
- **Lead engineers / on-call** — `manageSyncFailures` + `retrySyncFailures`. They can retry once the upstream cause is fixed.
- **A small admin group** — also `purgeSyncFailures`. They're the only ones authorized to discard queued work.

## Operator runbooks

### "Saves aren't appearing in search"

1. Open Sync Failures.
2. If you see rows here, hover the **Last error** column — that's usually the cause (auth, network, schema, backend offline).
3. If the page is empty, the buffer is healthy. The issue is elsewhere — see [Troubleshooting → Pending Syncs Are Not Draining](../resources/troubleshooting.md#pending-syncs-are-not-draining).
4. If you're tracking down a count discrepancy, the per-index `Documents` badge is **eventually consistent** — see [Document Count Looks Wrong After a Bulk Import](../resources/troubleshooting.md#document-count-looks-wrong-after-a-bulk-import).

### "Backend was down, want to retry everything that failed"

1. Confirm the backend is back up.
2. Open Sync Failures. The default view shows the failed rows.
3. (Optional) Filter to a specific **Index** if only one backend was affected.
4. Bulk-select with the header checkbox.
5. Click **Retry**. Rows reset to `pending` with `attemptCount = 0`; the next `BatchSyncJob` drains them.

### "Backend was decommissioned, abandon the queued work"

1. Open Sync Failures. Click **Show all rows** to see pending rows too.
2. Filter to **Index = {old-index-handle}**.
3. Bulk-select.
4. Click **Delete**. Rows are removed from the buffer without touching the backend.

### "Rows keep abandoning — what's wrong?"

If new rows reach `abandoned` repeatedly, the underlying cause is structural and not a transient outage. Check:

- Backend credentials and configuration in **Backends**.
- Transformer code — a bad change can produce documents the backend rejects (e.g., wrong field types).
- Index criteria — a recently-changed index could be targeting elements that don't validate.

Fix the upstream cause first. Bulk-retry the abandoned rows. If they keep failing, the error message in **Last error** is your next clue.

## Related

- [Indexing settings](../get-started/configuration.md#indexing) — `batchFlushInterval`, `batchMaxAttempts`, `syncBatchSize`, `pendingMaxAge`.
- [Troubleshooting → Pending Syncs Are Not Draining](../resources/troubleshooting.md#pending-syncs-are-not-draining).
- [Troubleshooting → Changing `autoIndex` Has No Effect](../resources/troubleshooting.md#changing-autoindex-has-no-effect-until-workers-restart).
- [Changelog](https://github.com/LindemannRock/craft-search-manager/blob/main/CHANGELOG.md) — buffer + Sync Failures view shipped in 5.45.0.
