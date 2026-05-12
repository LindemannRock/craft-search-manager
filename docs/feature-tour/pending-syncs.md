# Pending Syncs

**CP:** Search Manager → Pending Syncs

A visibility and operations page for the L3 pending-sync buffer (`searchmanager_pending_syncs`). When an element is saved or deleted, the plugin queues a row here; `BatchSyncJob` drains rows in batches and writes documents to your configured backend. The Pending Syncs page lets operators see what's queued, what's failing, and act on stuck rows — without dropping to MySQL.

## What you see

Each row in the buffer represents one pending operation against one (index, element, site) target:

- **Index** — the search index handle the row will write to (or delete from).
- **Element** — link to the Craft element if it still exists. If the element was deleted between queue and drain, the row shows `Type #ID — missing` and the processor will flip the operation to a backend delete.
- **Site** — which site the operation targets.
- **Op** — `upsert` (write the document) or `delete` (remove the document).
- **Status** —
  - `pending` — waiting for the next batch sync.
  - `processing` — a queue worker has claimed the row and is working on it. Rows tagged `(active)` are claimed by a currently-running worker; rows past the stale-cutoff are available for re-claim.
  - `failed` — last attempt failed; will retry on its scheduled `next attempt`.
  - `abandoned` — exceeded `batchMaxAttempts`. Will not retry automatically. Use **Retry now** to give it another shot once the underlying cause is fixed.
- **Attempts** — how many times a worker has claimed this row. Resets when you retry.
- **Queued / Next attempt** — timestamps that drive worker eligibility.
- **Last error** — the most recent failure message. Plain text, truncated at 80 chars; hover for the full message.

## Filters

Use the filter dropdowns to narrow down quickly:

- **Status** — pending / processing / failed / abandoned.
- **Index** — limit to one search index.
- **Op** — upsert or delete.
- **Site** — multi-site installs.

The free-text search box matches against element IDs (numeric) and last-error substrings.

### "Show stuck only" preset

The **Show stuck only** button at the top of the page filters to rows where action is genuinely overdue:

- `pending` or `failed` rows whose `next attempt` is already in the past (worker should have picked them up but hasn't), or
- `processing` rows whose `claimedAt` is older than the stale cutoff (a worker died holding the lock).

This is the riskiest set when triaging an indexing backlog — start here.

## Actions

### Per-row

- **View element** — opens the element's CP edit page (hidden when the element is gone).
- **Retry now** — resets the row to `pending`, clears `attemptCount`, `claimToken`, `claimedAt`, `lastError`, and `lastProcessedAt`, and forces `nextAttemptAt = now`. The next `BatchSyncJob` will claim it. Disabled while a worker has the row actively claimed (within the stale cutoff) to avoid racing.
- **Delete from buffer** — hard-deletes the row. Use when the row is genuinely garbage (e.g., orphaned reference to a deleted index). Same fresh-processing guard as Retry.

### Bulk

Select rows with the checkboxes and use **Retry** or **Delete** at the bottom of the table.

### Toolbar

- **Drain now** — schedules a `BatchSyncJob` immediately, bypassing the normal `batchFlushInterval` debounce. The job still routes through `scheduleBatchJob()`, so clicking twice in a row does not pile up duplicate jobs.
- **Purge abandoned** — deletes every row at `status = abandoned`. Confirms first; cannot be undone. Use after fixing the cause of repeated failures (e.g., backend credentials, schema drift) and verifying you want to drop those queued operations rather than retry them.

## Permissions

| Permission | Grants |
|---|---|
| `searchManager:managePendingSyncs` | View the page and the table. **View-only**; no destructive actions. |
| `searchManager:retryPendingSyncs` | Retry rows · Drain now |
| `searchManager:purgePendingSyncs` | Delete rows · Purge abandoned |

Operators get the parent permission. Lead engineers / on-call get the retry permission. Only the small group authorised to discard work gets purge.

## Operator runbooks

### "Saves aren't appearing in search"

1. Open Pending Syncs.
2. If the **Failed** or **Abandoned** count is non-zero, hover the **Last error** column — that's usually the cause (auth, network, schema, etc.).
3. Confirm a queue worker is running (`php craft queue/listen`).
4. If the buffer looks healthy but counts are off, the per-index `Documents` badge is **eventually consistent** — see [Document Count Looks Wrong After a Bulk Import](../resources/troubleshooting.md#document-count-looks-wrong-after-a-bulk-import).

### "Workers are stuck"

1. Filter to **Show stuck only**.
2. Rows at `processing` with a stale `claimedAt` mean a worker died holding the lock. The next `BatchSyncJob` will re-claim them automatically (no action needed); if you need them to move *now*, use **Retry now** to bounce them back to `pending` and click **Drain now**.

### "Backend was down, want to retry everything that failed"

1. Filter to **Status = Failed** (or **Abandoned** if attempts ran out).
2. Bulk-select with the header checkbox.
3. Click **Retry**. Rows reset to `pending`; the next `BatchSyncJob` drains them.

### "Backend was decommissioned, abandon the queued work"

1. Filter to **Index = {old-index-handle}**.
2. Bulk-select.
3. Click **Delete**. Rows are removed from the buffer.

## Related

- [Indexing settings](../get-started/configuration.md#indexing) — `batchFlushInterval`, `batchMaxAttempts`, `syncBatchSize`, `pendingMaxAge`.
- [Troubleshooting → Pending Syncs Are Not Draining](../resources/troubleshooting.md#pending-syncs-are-not-draining).
- [Changelog](https://github.com/LindemannRock/craft-search-manager/blob/main/CHANGELOG.md) — feature shipped in 5.45.0.
