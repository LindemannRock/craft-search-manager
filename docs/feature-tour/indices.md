# Indices

Point Search Manager at your content and it turns it into fast, searchable documents — no manual document-shape wrangling. A search index tells Search Manager what content to include and how to transform it into those documents; you can create one through the Control Panel or define it in your config file.

## What you'll use it for

- Index entries, assets, categories, users, Commerce products/variants, or Docs Manager pages into searchable documents
- Scope an index to one site, several sites, or all sites for multi-language search
- Filter what gets indexed with a criteria callback — by section, entry type, or any other element-query filter
- Route different indices to different backends, or prefix index names per environment for shared Algolia/Meilisearch accounts
- Split long documentation or rich-text pages into per-heading section hits so long pages don't dominate or under-rank in results

## Create your first index

1. Go to **Search Manager > Indices** and click **New Index**.
2. Give it a **Name** (the display name) and a **Handle** (the identifier you'll use in code, e.g. `entries-en`).
3. Choose an **Element Type** — Entries, Assets, Categories, Users, or (when the relevant plugin is installed) Commerce Products/Variants, Docs Manager pages, and other supported types. Craft element types show inline criteria — an Entries index, for example, lets you check which **Sections** to include.
4. Choose which **Sites** to index (leave all unchecked to index every site).
5. Leave **Language** on auto-detect unless you need to override stemming and stop words for a specific site.
6. Click **Save**.

That's it — the index queues its first full rebuild automatically as soon as you save it, so it starts filling right away with no separate rebuild step.

The CP form also exposes the same fine-tuning options described in [Index options](#index-options) below — Heading Levels, Retrievable Fields, Split Sections — plus an Advanced Settings area for a custom Transformer Class or a per-index Search Backend override.

Indices you create this way show a **Database** badge and stay fully editable in the CP. Indices defined in `config/search-manager.php` (see [Config file setup](#config-file-setup) below) show a **Config** badge instead and can't be edited in the CP — edit the config file and redeploy.

Everything from here down is reference material: the full option list, the config-file syntax, and the Docs Manager / Commerce integration details. If you're managing indices entirely through the CP, the walkthrough above is all you need — skip ahead only if you want to define indices in code instead.

## What is an index?

An index is a collection of searchable documents derived from Craft elements. Each index specifies:

- **Which elements** to include (entries, assets, categories, doc pages, etc.)
- **Which sites** to index content from
- **How to transform** elements into searchable documents
- **Which backend** to store the index in (optional — uses default if not specified)

Nested Matrix entries are indexed as part of their owner's document, never as standalone results. The automatic transformer applies the same owner-document flattening to Content Block fields and CKEditor embedded entries.

## Index options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `name` | `string` | Outer handle in config; required in CP | Display name for the index. A missing, null, blank, or whitespace-only config value falls back to the handle in the CP while still producing a Setup warning |
| `elementType` | `string` | `Entry::class` in config; required in CP | Element class to index (`Entry::class`, `Asset::class`, `SourceDoc::class`, Commerce `Product::class` / `Variant::class`, etc.) |
| `siteId` | `int\|array\|null` | `null` | Site(s) to index. `null` = all sites |
| `criteria` | `array\|Closure` | `[]` | Selector list or callback used to filter elements |
| `transformer` | `string` | `null` | Autoloadable zero-argument transformer class for custom document structure |
| `enabled` | `bool` | `true` | Whether the index is active |
| `backend` | `string` | `null` | Handle of a configured backend to use (overrides global default) |
| `language` | `string` | `null` | Language code (`en`, `de`, `fr`, `nl`, `es`, `ar`, `it`, `pt`, `ja`, `sv`, `da`, `no`). `null` = auto-detect from site locale |
| `headingLevels` | `array` | `null` | Heading levels to extract for heading matching (e.g., `[2, 3, 4]`) — see [Heading levels](#heading-levels) below |
| `splitSections` | `bool` | `false` | For SourceDoc/DocsManagerTransformer-family or AutoTransformer-family indices, index intro and heading sections as separate hits when headings are present |
| `retrievableFields` | `array\|string` | `['*']` | Which custom-field values public hits return. `['*']` = all, `[]` = none, a list of handles, or a comma/newline-delimited string — see [Retrievable Fields](#retrievable-fields) |
| `disableStopWords` | `bool` | `false` | Disable stop word filtering for this index |
| `skipEntriesWithoutUrl` | `bool` | `false` | Skip entries that don't have a URL |
| `enableAnalytics` | `bool` | `true` | Whether to track analytics for searches on this index |

## Config file setup

Define indices in `config/search-manager.php`:

```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => 1,
        'criteria' => function($query) {
            return $query->section(['news', 'blog', 'pages']);
        },
        'enabled' => true,
    ],
    'products' => [
        'name' => 'Products',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => null,  // All sites
        'criteria' => function($query) {
            return $query->section('products');
        },
        'enabled' => true,
    ],
],
```

### Config validation and readiness

Search Manager checks config-defined indices without executing them or changing stored data. Open **Search Manager > Setup** to see errors and warnings. A configuration-error summary appears across Search Manager CP pages while blocking errors remain; the separate analytics-privacy setup message appears only when its IP salt is missing. Warnings alone do not make Setup incomplete.

On the Indices list, a config index with an error shows a red **Error** status badge instead of a stale Enabled/Disabled badge; hover it to see the first error. Open that config index to see one colored findings box containing every applicable error and warning in validator order. A warning-only index keeps its normal Enabled/Disabled status and shows a warning-colored findings box. Errors block a targeted rebuild before its backend is cleared. Warnings identify suspicious but still functional configuration, such as an empty display name.

The `indices` section must be an array keyed by valid index handles. Each index must also be an array and may contain only the options in [Index options](#index-options). Values are checked strictly: booleans must be PHP booleans, site IDs must be existing positive IDs, heading levels must be unique integers from 1 through 6, backend handles must identify enabled configured backends, and element and transformer classes must exist and satisfy their required interfaces. Array values that Search Manager persists must also be JSON-encodable. A legacy backend type such as `file` or `mysql` is not a backend handle unless you have configured a backend with that exact handle.

A `criteria` Closure is intentionally not run while rendering Setup. Its query and return type are checked during rebuild preflight, before any existing backend index is cleared. Because Closure bodies cannot be compared reliably, changing only a Closure body does not automatically queue a rebuild; explicitly rebuild that index after deploying the change.

Setting `enabled` to `false` removes an index from normal search, content synchronization, and rebuild-all operations. You can still target that index explicitly from the CP or console when you need to prepare its storage before enabling it.

### Docs Manager integration

> [!NOTE]
> Requires the [Docs Manager](https://lindemannrock.com/plugins/docs-manager) plugin — a separate install. `SourceDoc` only appears as an index element type once Docs Manager is installed and enabled.

If Docs Manager is installed, you can index documentation pages. Create a global index for all docs, or scope to specific sources:

```php
// All documentation
'all-docs' => [
    'name' => 'All Documentation',
    'elementType' => \lindemannrock\docsmanager\elements\SourceDoc::class,
    'enabled' => true,
],

// Documentation for a specific source
'search-manager-docs' => [
    'name' => 'Search Manager Docs',
    'elementType' => \lindemannrock\docsmanager\elements\SourceDoc::class,
    'criteria' => function($query) {
        return $query->sourceHandle('search-manager');
    },
    'enabled' => true,
],
```

When creating a SourceDoc index via the Control Panel, a checkbox group lets you select which sources to include. Leave all unchecked to index all sources.

Long structured SourceDoc and AutoTransformer-family indices can opt into section records:

```php
'search-manager-docs' => [
    'name' => 'Search Manager Docs',
    'elementType' => \lindemannrock\docsmanager\elements\SourceDoc::class,
    'splitSections' => true,
    'headingLevels' => [2, 3, 4],
    'enabled' => true,
],
```

Split mode is available for SourceDoc indices that use the built-in Docs Manager transformer family and for indices whose resolved transformer is `AutoTransformer` or a subclass. That includes normal Entry indices, Craft Commerce Product/Variant indices using the built-in `CommerceTransformer`, and project-specific transformers that extend `AutoTransformer`. Each intro or heading section is indexed as its own backend record with the parent element identity plus section metadata. Public search results stay flat: `total` counts section hits, `backendId` is unique per section, and `elementId` stays equal to the parent element ID. Built-in local backends and external backends support the required document keys; if a custom backend cannot preserve document keys, Split Sections is rejected.

For AutoTransformer-family indices, Search Manager slices each searchable rich-text field by its own heading structure and never carries text across field boundaries. If an element has no headings at the index's selected `headingLevels`, it is indexed as a normal single record. If rich-text headings are present, the intro record carries pre-heading rich-text, non-sliced field text, and title/metadata text; heading section records search only their heading title plus that section's own body. Commerce product identity metadata such as product type, variant SKUs, and price stays on every section record so type filters, widgets, and promotions can work from any section hit. Heading anchors are generated from the heading text and deduped across the element. Search Manager cannot add matching `id` attributes to your front-end templates, so deep links such as `#installation` work only when your site renders matching heading IDs.

New enabled indices queue their first full rebuild as soon as they are saved or first materialized from config, so the index starts filling from its configured criteria without a separate manual step. Existing indices also queue a rebuild after saved or config-synced changes that alter the storage shape, including `elementType`, site scope, `criteria`, transformer class, `headingLevels`, language, stop-word behavior, URL-skipping, Split Sections, or `retrievableFields`. Renaming an index or switching its backend also queues an automatic rebuild under the new identity; CP saves clear the previous storage before the rebuild is queued. Re-enabling a disabled database index also queues a rebuild because content changes are not synced while the index is disabled. Normal content edits do not require a manual rebuild; when an element is saved, Search Manager re-slices the element and removes orphaned section records automatically.

For Algolia-backed documentation, enable Split Sections unless every page is comfortably small. Algolia enforces per-record and average record-size limits, and page-mode docs records can exceed those limits on long installation, API, or reference pages. Section records keep stored snippets, headings, and code-included snippet bodies much smaller while preserving links back to the parent page.

Search Manager stores body text once in the dedicated `_bodyClean` snippet source and does not duplicate it into the general `content` field. Matching still covers title, description, searchable custom field text, keywords, and body text: Algolia and Meilisearch search `title`, then `content`, then `_bodyClean`, then `url`; Typesense searches `title,content,_bodyClean,url` with weights `5,3,1,1`; the local backend adds `_bodyClean` to its BM25 term pool alongside `content`.

### Craft Commerce integration

When Craft Commerce is installed and enabled, Product and Variant element types are available for indices in the Control Panel. Commerce Product Types are configuration records rather than searchable Craft elements, so they are not listed as index element types.

For storefront search, create a **Product** index in most cases. Product documents include the product title, slug, URL, product type name/handle, searchable product fields, and variant SKU/title/option text, so a query for a SKU or option can still return the product result shoppers expect.

Use a **Variant** index when the result itself should be a specific variant, such as SKU-heavy parts catalogs, B2B order forms, or workflows where search results need to resolve directly to variant-level data. Variant documents include the variant SKU/title/options plus parent product title, slug, URL, and product type metadata. When variants do not have their own stable URL, Search Manager uses the parent product URL.

```php
'products' => [
    'name' => 'Products',
    'elementType' => \craft\commerce\elements\Product::class,
    'enabled' => true,
],

'variants' => [
    'name' => 'Variants',
    'elementType' => \craft\commerce\elements\Variant::class,
    'enabled' => true,
],
```

Leave the transformer blank for the recommended automatic path. Search Manager automatically uses its Commerce transformer for Product and Variant indices, including Commerce metadata such as product type, variant SKUs, titles, and option values. Use a custom transformer only when your storefront needs project-specific indexing logic. A minimal custom transformer can intentionally reduce the indexed Commerce metadata, which is useful for narrow search records but may remove SKU or option matches shoppers expect.

## Multi-site indices

You have three options for site handling:

### Single site

Index content from one specific site:

```php
'entries-en' => [
    'siteId' => 1,  // Just site ID 1
    // ...
],
```

### Multiple sites

Index content from specific sites into one index:

```php
'entries-regional' => [
    'siteId' => [1, 3],  // Sites 1 and 3
    // ...
],
```

### All sites

Index content from every site:

```php
'all-entries' => [
    'siteId' => null,  // All sites
    // ...
],
```

When indexing multiple sites, each element is stored with its `siteId`. This allows language filtering and per-site search results. Built-in backends store `siteId` as a field; external backends use composite document IDs (`{elementId}_{siteId}`).

Enabled all-sites indices also stay aligned with Craft's site list automatically. Creating or deleting a site queues a full rebuild through the index's configured backend, so a new site's content is added and documents for a deleted site are removed. Explicitly scoped indices and disabled indices are not rebuilt by those site events.

## Filtering with criteria

For predictable config diffs, use the array form when one of the built-in selectors is enough:

```php
'criteria' => [
    'sections' => ['news', 'blog'],
],
```

The supported selector depends on the exact element type:

| Element type | Selector |
|--------------|----------|
| `craft\elements\Entry` | `sections` |
| `craft\elements\Asset` | `volumes` |
| `craft\elements\Category` | `groups` |
| Docs Manager `SourceDoc` | `sourceHandles` |

Selector values must be nonempty lists of existing handles. Unknown keys and unresolved handles appear as Setup errors. At runtime Craft retains its normal matches-nothing behavior if a referenced selector later becomes unavailable.

For more advanced filtering, a `criteria` Closure receives a Craft ElementQuery and must return it with filters applied:

```php
'criteria' => function($query) {
    return $query
        ->section(['news', 'blog'])
        ->type(['article', 'review']);
},
```

This is equivalent to building an element query in Twig — any method available on the element query works here. Search Manager never invokes the Closure just to display Setup readiness; it validates the Closure at rebuild time before clearing the index.

For SourceDoc elements, the `sourceHandle()` method is available to scope by source:

```php
'criteria' => function($query) {
    return $query->sourceHandle(['search-manager', 'redirect-manager']);
},
```

## Transformers

When the transformer class is blank, Search Manager first uses registered integration transformers where they apply: Docs Manager pages use `DocsManagerTransformer` when Docs Manager is available, and Commerce Product/Variant indices use `CommerceTransformer` when Craft Commerce is available. Everything else falls back to `AutoTransformer`, which handles entries and most other element types generically by indexing searchable attributes, custom fields marked searchable in Craft, relations, rich text, and headings.

For project-specific result data, create a transformer in a module namespace and assign it to the index. See [Custom Transformers](../developers/custom-transformers.md) for details.

```php
'transformer' => \modules\search\transformers\ProductTransformer::class,
```

Custom transformer classes must be autoloadable from your project or module namespace, constructible without required constructor arguments, and implement `TransformerInterface`. Extending `BaseTransformer` is the recommended route for custom document shapes; extending `AutoTransformer` is useful when you want automatic extraction plus project-specific fields. `supports()` is still required by the interface, but Search Manager does not use it to guard an index-specific configured override.

If an index's element type or transformer belongs to another plugin, that plugin must be enabled. Disabling the provider makes the affected index fail closed with an **Error** state; Search Manager leaves the index configuration and backend storage intact. Open the affected index to see which disabled plugin owns the unavailable element type or transformer and the action required before rebuilding. Re-enabling the provider queues full rebuilds only for affected enabled indices, while unrelated and disabled indices remain untouched.

## Per-index settings

### Retrievable fields

`retrievableFields` controls which indexed custom field values are returned under public hit `fields`:

```php
'entries-en' => [
    'retrievableFields' => ['intro', 'summary'],
    // ...
],
```

Use `['*']` to return every searchable custom field value mirrored into `_fields`, including rich-text and body-source fields that also feed snippets, headings, and Split Sections. Use `['*', '-wysiwyg']` to return all fields except `wysiwyg`, `[]` to return none, or an explicit list of field handles. Exclusion entries use the same `-attr` convention as Algolia's `attributesToRetrieve` and are valid only alongside `*`. The default is `['*']` for database indices, new indices, and config indices that omit the key. For production APIs, prefer an explicit narrow list that matches the frontend contract.

REST and GraphQL callers can pass request-time `retrievableFields` to narrow this list for one request. Request values never widen the index allowlist; if an index allows only `intro`, requesting `summary` returns neither field, and if an index excludes `wysiwyg`, requesting `wysiwyg` cannot add it back.

This setting controls what custom field values are stored in the public `fields` area of new records. Snippets and matching still use all searchable field text through private snippet/search sources, so a field omitted from `fields` can still produce matches or snippets. Rebuild the index after changing `retrievableFields`; existing records keep the previous stored field allowlist until they are reindexed.

The concept is similar to Algolia's `attributesToRetrieve`, Meilisearch's displayed attributes, and Typesense's `include_fields`. Search Manager still enforces the public contract after results come back, while provider projection keeps main searches from downloading custom fields that cannot be returned.

### Heading levels

`headingLevels` controls which HTML/Markdown heading levels (H1–H6) Search Manager extracts when indexing rich-text or Markdown content. It drives two things:

- **Heading matching** — the headings shown under a search result, so visitors can jump straight to the matching part of a long page.
- **Split Sections slicing** — when `splitSections` is enabled, it's also where Search Manager cuts a page into separate per-heading records (see [Docs Manager integration](#docs-manager-integration) above).

The default is `[2, 3, 4]` — H2 through H4, the common range for content subheadings, skipping the page-level H1. Change it if your content structure uses different levels: add `5` if your pages nest sections that deep, or narrow to `[2, 3]` if your H4s are decorative rather than structural. Config-defined values must be a nonempty list of unique integers from 1 through 6; invalid values appear in Setup and block rebuild preflight.

### Disable stop words

Some indices may contain technical content where stop words are meaningful:

```php
'api-docs' => [
    'disableStopWords' => true,  // Keep words like "the", "is", "a"
    // ...
],
```

A syntactically valid language code is allowed even when Search Manager does not bundle a matching stop-word file. This supports project-provided stop-word files; without one, Search Manager logs the missing file and continues without stop-word filtering for that language.

### Disable analytics

Internal or admin-facing indices may not need analytics tracking:

```php
'internal-search' => [
    'enableAnalytics' => false,
    // ...
],
```

### Skip entries without URL

If your index includes entries that don't have landing pages, you can exclude them:

```php
'entries-en' => [
    'skipEntriesWithoutUrl' => true,
    // ...
],
```

## Multi-environment index prefix

Use `indexPrefix` to automatically prefix index names per environment. Define indices once and deploy everywhere:

```php
'*' => [
    'indices' => [
        'entries-en' => [ /* ... */ ],
    ],
],
'dev' => [
    'indexPrefix' => 'local_',
],
'production' => [
    'indexPrefix' => 'prod_',
],
```

| Environment | Index Handle | Backend Index Name |
|-------------|--------------|-------------------|
| Dev | `entries-en` | `local_entries-en` |
| Production | `entries-en` | `prod_entries-en` |

This is especially useful when sharing an Algolia or Meilisearch account across environments.

## Building indices

### Via CLI

Rebuild all indices:

```bash title="PHP"
php craft search-manager/index/rebuild
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild
```

Rebuild a specific index:

```bash title="PHP"
php craft search-manager/index/rebuild --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild --handle=entries-en
```

Clear an index:

```bash title="PHP"
php craft search-manager/index/clear --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/clear --handle=entries-en
```

See [Console Commands](../developers/console-commands.md) for the full CLI reference.

### Via Control Panel

Go to Search Manager > Indices and use the rebuild/clear buttons for each index.

### Auto-indexing

When `autoIndex` is enabled (default), elements are automatically queued for indexing when saved and queued for removal when deleted. Search Manager stores those save/delete events in a pending sync buffer and drains them with `BatchSyncJob`, so rapid edits or imports can collapse repeated work into fewer backend calls.

The batch sync worker groups pending rows by index and writes documents through backend batch APIs. This is especially useful for Feed Me, CSV imports, migrations, and other bulk-write workflows where one import can trigger thousands of element save events.

A status sync job periodically checks for entries that became live (postDate passed) or expired without a save event. It queues matching rows into the same pending sync buffer, so scheduled status changes and normal save/delete events drain through one backend-writing path.

Search Manager debounces automatic `lastIndexed` metadata updates with `lastIndexedDebounceSeconds` (default: 60 seconds). This keeps the "Last Indexed" column current enough for operators while avoiding an extra metadata-table write for every save during imports or busy editing sessions. Set the value to `0` if you want the timestamp updated after every successful auto-sync.

When a batch sync drain completes, Search Manager refreshes each affected index once from the backend's authoritative document count, so the Control Panel count reflects the completed writes without a per-element backend probe.

Manual rebuilds, clears, and backend count refreshes still update index stats immediately.

#### How document counts stay current

The **Indexed** column on the Indices page (and the **Documents** count on an index's detail view) reflects the true backend-document total from the latest full rebuild, completed sync batch, or explicit count refresh. Automatic save/delete sync refreshes this counter once per affected index after the batch completes; it does not probe the backend once per queued element.

The adjacent expected/actual comparison uses document counts in page mode, while Split Sections compares eligible Craft elements with distinct parent element IDs represented in the backend; the displayed Indexed/Documents value remains the true backend-document count in both modes. Per-element heading changes are handled by normal content sync rather than treated as stale coverage. This does not change analytics totals: `resultsCount` remains backend-native, so a split-index search counts matching section documents.

During high-volume activity such as a large Feed Me run or bulk import, the displayed count can lag while queued work is still draining. The completed batch refresh brings it back in line with the backend.

To force the count to refresh:

- Run a rebuild: `php craft search-manager/index/rebuild --handle=entries-en`
- Use the refresh action on the index detail page (where exposed)

This keeps counts authoritative at batch boundaries without reintroducing a backend read for every saved element.

See [Console Commands](../developers/console-commands.md) for all CLI options.
