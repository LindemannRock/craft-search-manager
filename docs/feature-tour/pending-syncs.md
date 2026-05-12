# Pending Syncs

**CP:** Search Manager → Pending Syncs

A visibility + operations page for the L3 pending-sync buffer (`searchmanager_pending_syncs`). When an element is saved or deleted, the plugin queues a row in the buffer; `BatchSyncJob` drains the buffer to your configured search backend in the background.

In a healthy system you rarely visit this page. It exists for when something needs operator attention — failed rows, stuck workers, or simply wanting to see the queue in flight.

## How saves reach the search backend

You don't need to do anything for this. Saving an entry triggers the auto-sync listener (when `autoIndex` is on), which queues rows into the buffer. `BatchSyncJob` then runs as part of Craft's queue and drains the buffer to your backend, claiming rows in `syncBatchSize` chunks and looping until empty (or until its time budget is exhausted on a true mega-import, in which case it schedules a continuation).

Save entry → rows queue → `BatchSyncJob` drains → search backend updated. Sub-10-second round trip.

## What the page shows

By default, every row in the buffer regardless of status. Each row is one pending operation against one (index, element, site) target.

If you save a few entries and refresh, you'll see rows appear briefly then disappear as the queue drains. The page **auto-refreshes every 5 seconds** while there are rows at `pending` or `processing` so you can watch the drain in real time. When the buffer only has `failed`/`abandoned` rows (or is empty), polling stops.

## Statuses

- **`pending`** — queued, waiting for the next `BatchSyncJob` claim.
- **`processing`** — a queue worker has claimed the row and is working on it. Worker-active rows have row actions (Retry / Delete) disabled until the worker either finishes or its claim ages past the stale cutoff.
- **`failed`** — last `batchIndex`/`batchDelete` call to the backend returned an error. The row will retry on its scheduled `next attempt` with exponential backoff.
- **`abandoned`** — the row hit `batchMaxAttempts` failed attempts and stopped retrying. It will sit here forever until you `Retry` it (after fixing the cause) or `Purge` it.

Typical triggers for `failed`/`abandoned`:

- Backend went down (auth, network, rate limit) → multiple rows land at `failed`.
- A bad transformer change caused malformed documents → rows fail with a schema error.
- A backend was decommissioned but indices still target it → rows fail and eventually abandon.

## Columns

| Column | Meaning |
|---|---|
| **Index** | The search index handle the row writes to (or deletes from). |
| **Element** | Link to the Craft element if it's propagated to the row's site. `Type #ID · not on this site` when the element exists but not on this site (routine — the processor flips the row to a backend delete on drain). `Type #ID · deleted` (amber) when the element was deleted everywhere. |
| **Site** | Which site the operation targets. |
| **Op** | `Write` (write the document to the backend) or `Delete` (remove the document). |
| **Status** | One of the four statuses above. Colored badge. |
| **Attempts** | How many times a worker has claimed and tried this row. Resets when you Retry. |
| **Queued / Next attempt** | When the row was queued and when the next retry is scheduled. |
| **Last error** | Truncated error message; hover for the full text. Plain text, never rendered as HTML. |

## Filters

Three independent filter pills in the toolbar:

- **Status** — All statuses · Pending · Processing · Failed · Abandoned · **Failed & Abandoned** (combined preset for triage in one click).
- **Index** — limit to one search index.
- **Site** — multi-site installs.

Plus a free-text search box that matches against element IDs (numeric) and last-error substrings.

## Actions

### Per-row

- **View element** — opens the element's CP edit page (hidden when the element is gone).
- **Retry now** — resets the row to `pending` with `attemptCount = 0`, clears `claimToken`, `claimedAt`, `lastError`, `lastProcessedAt`, and forces `nextAttemptAt = now`. The next `BatchSyncJob` claims it. **Enabled only for `failed` and `abandoned` rows** — pending/processing rows are already in the queue, retry would be a no-op.
- **Delete from buffer** — hard-deletes the row. Enabled for everything except rows actively held by a fresh worker (a worker can't have its row yanked out from under it; once the claim ages past the stale cutoff the action becomes available).

### Bulk

Select rows with the checkboxes. Two buttons at the bottom of the table show their respective eligibility counts:

- **Retry (N)** — N = selected rows at `failed` or `abandoned`.
- **Delete (N)** — N = selected rows excluding fresh-processing.

So if you select all 4 statuses and one is fresh-processing, you'll see something like `Retry (2)` and `Delete (3)`.

### Toolbar

**Purge abandoned (N)** — appears only when (a) there are abandoned rows AND (b) the current Status filter is set to a view that includes them (`All statuses`, `Abandoned`, or `Failed & Abandoned`). Deletes every row at `status = abandoned`. Confirms first. Use after fixing the cause of repeated failures and deciding you'd rather discard the queued operations than retry them.

## Permissions

Follows the plugin's nested `Manage X` convention — the parent grants access to the page, destructive actions are gated behind nested permissions.

| Permission | Grants |
|---|---|
| `searchManager:managePendingSyncs` | Access the page. **View-only**; no destructive actions. |
| `searchManager:retryPendingSyncs` | Retry rows (per-row or bulk). |
| `searchManager:purgePendingSyncs` | Delete rows from the buffer · Purge all abandoned. |

Typical assignment:

- **Operators** — `managePendingSyncs` only. Can see what's going on, escalate if something looks wrong.
- **Lead engineers / on-call** — `managePendingSyncs` + `retryPendingSyncs`. Can retry once the upstream cause is fixed.
- **A small admin group** — also `purgePendingSyncs`. Only ones authorized to discard queued work.

## Operator runbooks

### "Saves aren't appearing in search"

1. Open Pending Syncs.
2. If rows show at `failed` or `abandoned`, hover the **Last error** column — that's usually the cause (auth, network, schema, backend offline).
3. If the page is empty, the buffer is healthy. The issue is elsewhere — see [Troubleshooting → Pending Syncs Are Not Draining](../resources/troubleshooting.md#pending-syncs-are-not-draining).
4. If you're tracking down a count discrepancy, the per-index `Documents` badge is **eventually consistent** — see [Document Count Looks Wrong After a Bulk Import](../resources/troubleshooting.md#document-count-looks-wrong-after-a-bulk-import).

### "Backend was down, want to retry everything that failed"

1. Confirm the backend is back up.
2. Open Pending Syncs.
3. Set the Status filter to **Failed & Abandoned** (one-click triage).
4. (Optional) Filter to a specific **Index** if only one backend was affected.
5. Bulk-select with the header checkbox.
6. Click **Retry**. Rows reset to `pending` with `attemptCount = 0`; the next `BatchSyncJob` drains them.

### "Backend was decommissioned, abandon the queued work"

1. Open Pending Syncs.
2. Filter to **Index = {old-index-handle}**.
3. Bulk-select.
4. Click **Delete**. Rows are removed from the buffer without touching the backend.

### "Rows keep abandoning — what's wrong?"

If new rows reach `abandoned` repeatedly, the underlying cause is structural and not a transient outage. Check:

- Backend credentials and configuration in **Backends**.
- Transformer code — a bad change can produce documents the backend rejects.
- Index criteria — a recently-changed index could be targeting elements that don't validate.

Fix the upstream cause first. Bulk-retry the abandoned rows. If they keep failing, the error message in **Last error** is your next clue.

## Related

- [Indexing settings](../get-started/configuration.md#indexing) — `batchFlushInterval`, `batchMaxAttempts`, `syncBatchSize`, `pendingMaxAge`.
- [Troubleshooting → Pending Syncs Are Not Draining](../resources/troubleshooting.md#pending-syncs-are-not-draining).
- [Troubleshooting → Changing `autoIndex` Has No Effect](../resources/troubleshooting.md#changing-autoindex-has-no-effect-until-workers-restart).
- [Changelog](https://github.com/LindemannRock/craft-search-manager/blob/main/CHANGELOG.md) — buffer + Pending Syncs view shipped in 5.45.0.
