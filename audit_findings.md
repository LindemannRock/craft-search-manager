# Search Manager — Audit Findings

> **Audit Date:** 2026-02-06
> **Progress:** 28 / 34 resolved, 6 deferred, 0 open

---

## CRITICAL

### 1. ReDoS Vulnerability in QueryRule Regex Matching
- **File:** `src/models/QueryRule.php:262`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** When `matchType` is `regex`, user-supplied patterns are executed directly with `@preg_match()`. A malicious pattern like `(a+)+$` could freeze PHP on every search query. The `@` error suppression hides failures silently.
- **Fix:** Added `validateRegexPattern()` validator on save + lowered `pcre.backtrack_limit` to 10,000 during execution + log warning on failure.

---

## HIGH

### 2. Missing Permission on actionGetStorageStats
- **File:** `src/controllers/UtilitiesController.php:480`
- **Category:** Permission
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Exposes DB table sizes, Redis key counts, and file system sizes with no permission check. The `beforeAction()` switch doesn't cover `get-storage-stats`.
- **Fix:** Added `get-storage-stats` to the `beforeAction()` switch under `searchManager:rebuildIndices`.

### 3. actionDelete Uses Wrong Permission
- **File:** `src/controllers/AnalyticsController.php:120`
- **Category:** Permission
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Uses `searchManager:viewAnalytics` instead of `searchManager:clearAnalytics`. Anyone who can view analytics can delete individual records.
- **Fix:** Changed to `$this->requirePermission('searchManager:clearAnalytics')`.

### 4. actionEdit Too Permissive for Backends
- **File:** `src/controllers/BackendsController.php:140`
- **Category:** Permission
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Editing an existing backend only checks `searchManager:viewBackends` instead of `searchManager:editBackends`.
- **Fix:** Changed to `$this->requirePermission('searchManager:editBackends')` for the existing backend case.

### 5. Unsanitized HTML in Highlighter
- **File:** `src/search/Highlighter.php:80-83`
- **Category:** Security (XSS)
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `$this->tag` and `$this->class` are interpolated directly into HTML output. A compromised admin could inject XSS via settings that renders on every frontend search result.
- **Fix:** Sanitized in constructor: `tag` stripped to alphanumeric only with `preg_replace`, `class` escaped with `htmlspecialchars(ENT_QUOTES)`.

### 6. limit=0 Allows Unlimited Results on Public API
- **File:** `src/controllers/ApiController.php:172-178`
- **Category:** Security (DoS)
- **Status:** ⏳ Deferred → [Search API Keys feature](TODO.md)
- **Description:** The public anonymous search endpoint treats `limit=0` as "no limit", enabling potential DoS via expensive queries.
- **Fix:** Will be addressed by the Search API Keys feature which provides rate limiting, result restrictions, and per-key limits.
- **Note:** All major search engines enforce sensible defaults and hard caps — Algolia defaults to 20 (max 1,000), Meilisearch defaults to 20 (max 1,000), Typesense defaults to 10 (max 250). None allow "no limit". A default of 20 and max of 100 is aligned with industry standard.

### 7. Public Search Accepts Arbitrary siteId — Cross-Site Data Exposure
- **File:** `src/controllers/ApiController.php:62-73`, `src/backends/AbstractSearchEngineBackend.php:333-339`
- **Category:** Security
- **Status:** ⏳ Deferred → [Search API Keys feature](TODO.md)
- **Source:** Previous audit
- **Description:** Public search/autocomplete defaults to "all sites" and accepts arbitrary `siteId`. If you have private or separate sites, public callers can query across all sites or target a specific `siteId`, potentially exposing data across sites and increasing load.
- **Fix:** Default to current site for public endpoints. Only allow "all sites" or explicit `siteId` if a whitelist config enables it. This ties into the future API keys / public search policy feature.

---

## MEDIUM

### 8. CSRF Disabled on Analytics Tracking Endpoints
- **File:** `src/controllers/SearchController.php:47`
- **Category:** Security
- **Status:** ⏳ Deferred → [Search API Keys feature](TODO.md)
- **Description:** CSRF disabled for `track-click` and `track-search` endpoints, allowing cross-origin analytics pollution. An attacker could forge requests to inject fake analytics data.
- **Fix:** Will be addressed by the Search API Keys feature which provides per-key rate limiting and request validation. Current risk is low (analytics pollution only, no data exposure).

### 9. IP-Hash Salt Missing but Tracking Still Runs
- **File:** `src/services/AnalyticsService.php`
- **Category:** Bug
- **Status:** ✅ Fixed (2026-02-07)
- **Source:** Previous audit
- **Description:** When IP-hash salt is missing, it logs "tracking disabled" but tracking continues anyway and geo lookup can still run. The missing salt should be an early return that actually prevents tracking and geo lookups.
- **Fix:** Clear `$ipForGeoLookup` in catch block so geo job isn't queued when hash fails. Also fixed in smartlink-manager (`unset($metadata['ip'])`) and shortlink-manager (reordered hash before geo + clear `$ip`). Updated all three READMEs to accurately describe behavior.

### 10. Mass Assignment with setAttributes Unsafe Mode
- **File:** `src/controllers/SettingsController.php:629`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `setAttributes($postedSettings, false)` disables safe-only mode, allowing any model attribute to be set via POST.
- **Fix:** Removed `false` parameter — all properties already have validation rules so are safe by default.

### 11. actionClearTestCache Can Flush Entire Craft Cache
- **File:** `src/controllers/SettingsController.php`
- **Category:** Bug
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** When called without an `indexHandle`, it calls `Craft::$app->getCache()->flush()` which clears ALL Craft cache — not just search-manager's.
- **Fix:** Replaced with `SearchManager::$plugin->backend->clearAllSearchCache()` which only clears search-manager's own cache (Redis tracked keys or file directory).

### 12. Filter Injection in parseFilters()
- **Files:** `src/backends/AlgoliaBackend.php:279`, `MeilisearchBackend.php:399`, `TypesenseBackend.php:347`, `BaseBackend.php:256`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** All `parseFilters()` implementations construct filter strings by directly interpolating user-provided values without escaping delimiter characters (`"`, backtick).
- **Fix:** Escape delimiter characters in filter values before interpolation: `str_replace('"', '\\"', ...)` for Base/Algolia/Meilisearch, `` str_replace('`', '\\`', ...) `` for Typesense.

### 13. Sensitive Backend Settings Logged in Debug Mode
- **File:** `src/backends/BaseBackend.php:144`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** The full config array (including passwords and API keys) is logged at debug level via `'config' => $config`.
- **Fix:** Filter out sensitive keys (`apiKey`, `adminApiKey`, `searchApiKey`, `password`, `secret`) via `array_diff_key()` before logging. Redacted key names are logged separately for debugging visibility. Line 55 was already safe (only logs `settingsKeys`, `host`, `port`).

### 14. FileStorage Index Path Not Validated Against Traversal
- **File:** `src/search/storage/FileStorage.php:51`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `FileStorage` constructor accepts `$indexHandle` and constructs a path without validating against `../` traversal characters.
- **Fix:** Added `preg_match('/[\/\\\\]|\.\./', $indexHandle)` check in constructor — throws `InvalidArgumentException` if handle contains `/`, `\`, or `..`.

### 15. Debug Meta Exposed Based on devMode
- **File:** `src/controllers/SearchController.php`
- **Category:** Security (Info Disclosure)
- **Status:** ✅ Already Addressed
- **Description:** Returns debug metadata (backend name, execution time, cache status, query rule details) when `devMode` is enabled, even on public-facing endpoints.
- **Finding:** Code already requires `devMode OR searchManager:viewDebug` permission (lines 116-118). Debug meta is not blindly exposed — there's a permission alternative, and `devMode` is a dev environment where info disclosure is expected. Error message in catch block (line 321) follows standard Craft convention.

### 16. $allowAnonymous = true on Entire ApiController
- **File:** `src/controllers/ApiController.php:27`
- **Category:** Security
- **Status:** ⏳ Deferred → [Search API Keys feature](TODO.md)
- **Description:** All current and future actions in ApiController are automatically anonymous. If a new privileged action is added later, it will be anonymous by default. Currently only has `autocomplete` and `search` — both intentionally public.
- **Fix:** Will be addressed when the API Keys feature reworks the entire ApiController access model. The explicit allowlist is a nice-to-have but the real access control change happens there.

### 27. Missing ExportHelper::isFormatEnabled() on 3 Export Actions
- **Files:** `src/controllers/AnalyticsController.php:981`, `:1038`, `:1184`
- **Category:** Export Validation
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `actionExportPromotionAnalytics`, `actionExportTab`, and `actionExportContentGaps` accept a `format` param but do not call `ExportHelper::isFormatEnabled()`. The main `actionExport` and `actionExportRuleAnalytics` correctly check this, but these three bypass the config.
- **Fix:** Added `ExportHelper::isFormatEnabled($format, SearchManager::$plugin->id)` check at the beginning of each method.

### 28. Utility contentHtml() Has No Permission Check
- **File:** `src/utilities/ClearSearchCache.php:51`
- **Category:** Permission / Data Leak
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Exposes index counts, document counts per backend, default backend name, analytics record count, and cache file counts without any search-manager permission check. Analytics count is not scoped to user's allowed sites.
- **Fix:** Gated index data behind `searchManager:manageIndices` permission. Gated analytics count behind `searchManager:viewAnalytics` permission and scoped to `getEditableSiteIds()`. Same pattern as smartlink-manager and shortlink-manager utilities.

### 29. Analytics Queries Not Scoped to User's Allowed Sites
- **Files:** `src/controllers/AnalyticsController.php:49-51`, `:489-490`, `src/controllers/DashboardController.php:73-76`
- **Category:** Site Scoping
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Analytics controller accepts optional `siteId` without validating against user's editable sites. When no siteId provided, queries return data across ALL sites. Dashboard always passes `null`, querying all sites. Site dropdown shows `getAllSites()` instead of `getEditableSites()`.
- **Fix:** Validated `siteId` against `getEditableSiteIds()` with ForbiddenHttpException. Default to editable site IDs when none specified. Changed to `getEditableSites()` for dropdown. Updated all 8 AnalyticsService methods to accept `int|array|null` for siteId. Also added validation to all 3 export actions.

### 17. Highlight/Snippet XSS Risk with strip_tags
- **File:** `src/search/Highlighter.php:68,108`
- **Category:** Security
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `highlight()` and `generateSnippets()` use `strip_tags()` which can be bypassed with malformed HTML. Output requires `|raw` in templates.
- **Fix:** Added `htmlspecialchars(strip_tags($text), ENT_QUOTES, 'UTF-8')` — `strip_tags` first removes actual HTML, then `htmlspecialchars` escapes anything it missed. Highlight `<mark>` tags are inserted after sanitization so they still render.

---

## LOW

### 18. Copyright Year 2025 Instead of 2026
- **File:** `src/services/AnalyticsService.php:7`
- **Category:** Code Quality
- **Status:** ✅ Not an Issue
- **Description:** Files created in 2025 correctly have `2025` copyright year. Copyright reflects creation year, not last-modified year. No update needed.

### 19. Copy-Paste Error in AnalyticsController Docblock
- **File:** `src/controllers/AnalyticsController.php:2`
- **Category:** Code Quality
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** File header says "Redirect Manager plugin" instead of "Search Manager plugin".
- **Fix:** Updated to "Search Manager plugin for Craft CMS 5.x".

### 20. Redundant Settings Loading in WidgetsController
- **File:** `src/controllers/WidgetsController.php:205,219,232,246`
- **Category:** Code Quality
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** `SearchManager::$plugin->getSettings()` loaded 4 times in `actionSave()`.
- **Fix:** Loaded once before validation and reused throughout the method.

### 21. batchIndex() Always Returns True
- **File:** `src/backends/AbstractSearchEngineBackend.php:216`
- **Category:** Bug
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Returns `true` regardless of how many items failed indexing.
- **Fix:** Changed to `return $successCount > 0`. Also added `total` to log output for visibility.

### 22. glob() Scan on Large Indices Could Be Slow
- **File:** `src/search/storage/FileStorage.php:458`
- **Category:** Performance
- **Status:** ⏳ Deferred (Won't Fix)
- **Description:** `getElementSuggestions()` scans filesystem with `glob()` on every request.
- **Rationale:**
  - FileStorage is a dev/local backend — production sites use proper search engines
  - The `break` at line 498 means it stops after finding `limit` matches (default 10), so it won't scan the entire directory if early matches are found
  - A proper fix (building an in-memory prefix index or caching) would add complexity to a backend that's intentionally simple
  - If this ever becomes a real problem, the answer is "switch to a real backend", not "optimize FileStorage"

### 23. No Rate Limiting on Anonymous Search Endpoint
- **File:** `src/controllers/SearchController.php:40`
- **Category:** Security
- **Status:** ⏳ Deferred → [Search API Keys feature](TODO.md)
- **Description:** Anonymous GET search endpoint has no rate limiting, allowing content enumeration.
- **Fix:** Will be addressed by the Search API Keys feature which provides per-key rate limiting.

### 24. Manual Config File Loading Instead of Helper
- **Files:** `src/controllers/WidgetsController.php`, `src/controllers/BackendsController.php`, `src/backends/BaseBackend.php`, `src/models/BackendSettings.php` (2 locations), `src/console/controllers/MaintenanceController.php`, `src/controllers/UtilitiesController.php`
- **Category:** Code Quality
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** 7 locations used manual `require` with `*`/environment checks instead of Craft's built-in config loader.
- **Fix:** Replaced all with `Craft::$app->getConfig()->getConfigFromFile('search-manager')` which handles `*` and environment merging automatically.

### 25. Element Hydration Doesn't Enforce Live/Visible Status
- **File:** `src/controllers/SearchController.php:221`
- **Category:** Bug
- **Status:** ✅ Fixed (2026-02-07)
- **Source:** Previous audit
- **Description:** Public search returns any element in the index regardless of "live" status or permissions. If stale content was indexed (disabled/expired entries), it will appear in search results.
- **Fix:** Pass `['status' => 'live']` criteria to `getElementById()` for non-CP requests. CP requests still return all statuses so admins can find disabled content.

### 26. Inconsistent Error Response Format in Delete Endpoints
- **File:** `src/controllers/WidgetsController.php:278,284,288`
- **Category:** Code Quality
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Mix of translated and untranslated error messages.
- **Fix:** Wrapped all error messages with `Craft::t('search-manager', ...)`.

### 30. actionGetData() Missing Site Scoping Validation
- **File:** `src/controllers/AnalyticsController.php:504-506`
- **Category:** Site Scoping
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** AJAX analytics endpoint accepts `siteId` without validating against `getEditableSiteIds()`. When null, queries ALL sites. Missed spot from #29 fix.
- **Fix:** Added `$editableSiteIds` validation, `$effectiveSiteId` default, and replaced all 27+ service calls in the method. Also fixed same pattern in `actionExport()` and `actionExportTab()` and `actionExportContentGaps()`.

### 31. actionGetChartData() Missing Site Scoping Validation
- **File:** `src/controllers/AnalyticsController.php:804-808`
- **Category:** Site Scoping
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** AJAX chart endpoint passes unvalidated `siteId` to `getChartData()`. Same issue as #30.
- **Fix:** Added `$editableSiteIds` validation and `$effectiveSiteId` default.

### 32. actionClearAll() Missing Site Scoping Validation
- **File:** `src/controllers/AnalyticsController.php:152-155`
- **Category:** Site Scoping
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Clear analytics accepts arbitrary `siteId`. When null, clears ALL sites regardless of user's editable sites.
- **Fix:** Added `$editableSiteIds` validation and `$effectiveSiteId` scoping for the delete operation.

### 33. Dashboard Widgets Not Scoped to User's Editable Sites
- **Files:** `src/widgets/TopSearchesWidget.php`, `AnalyticsSummaryWidget.php`, `ContentGapsWidget.php`, `TrendingSearchesWidget.php`
- **Category:** Site Scoping
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** All 4 dashboard widgets pass `null` siteId, querying across ALL sites. Widgets bypass controller — rendered via `getBodyHtml()`. `getAnalyticsSummary()` doesn't even accept siteId param.
- **Fix:** Added `$editableSiteIds = Craft::$app->getSites()->getEditableSiteIds()` to each widget's `getBodyHtml()`. Updated `getAnalyticsSummary()` signature to accept `int|array|null $siteId` as first parameter.

### 34. Exception Message Leakage in Analytics AJAX Endpoints
- **File:** `src/controllers/AnalyticsController.php` (3 catch blocks)
- **Category:** Info Disclosure
- **Status:** ✅ Fixed (2026-02-07)
- **Description:** Catch blocks in `actionGetData()`, `actionGetRuleAnalytics()`, and `actionGetPromotionAnalytics()` return `$e->getMessage()` directly, potentially exposing internal details.
- **Fix:** Gated behind `devMode` — in dev, shows actual message; in production, shows generic "An error occurred while loading analytics data."

---

## Positive Findings (No Action Needed)

- Parameterized queries used everywhere — no SQL injection vectors found
- JSON for cache serialization (not PHP serialize) — prevents object injection
- Frontend XSS protection via `textContent` escaping in JS
- 256-character max query length enforced
- Disabled indices blocked from public endpoints
- Granular nested permission system
- Config-sourced items protected from DB modification
- POST required for all mutations
- Atomic document count updates with `GREATEST(0, documentCount + :delta)`
