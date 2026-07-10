# Indices

A search index tells Search Manager what content to index and how to transform it into searchable documents. You can create indices through the Control Panel or define them in your config file.

## What Is an Index?

An index is a collection of searchable documents derived from Craft elements. Each index specifies:

- **Which elements** to include (entries, assets, categories, doc pages, etc.)
- **Which sites** to index content from
- **How to transform** elements into searchable documents
- **Which backend** to store the index in (optional — uses default if not specified)

## Creating Indices

### Via Config File

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

#### Docs Manager Integration

If [Docs Manager](https://lindemannrock.com/plugins/docs-manager) is installed, you can index documentation pages. Create a global index for all docs, or scope to specific sources:

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

#### Craft Commerce Integration

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

### Via Control Panel

Go to Search Manager > Indices and click "New Index". The CP provides a form for all the same options.

Config-defined indices show a "Config" badge and cannot be edited in the CP. Database-defined indices show a "Database" badge and are fully editable.

## Index Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `name` | `string` | (required) | Display name for the index |
| `elementType` | `string` | (required) | Element class to index (`Entry::class`, `Asset::class`, `SourceDoc::class`, Commerce `Product::class` / `Variant::class`, etc.) |
| `siteId` | `int\|array\|null` | `null` | Site(s) to index. `null` = all sites |
| `criteria` | `callable` | `null` | Callback to filter elements (receives an ElementQuery) |
| `transformer` | `string` | `null` | Autoloadable zero-argument transformer class for custom document structure |
| `enabled` | `bool` | `true` | Whether the index is active |
| `backend` | `string` | `null` | Handle of a configured backend to use (overrides global default) |
| `language` | `string` | `null` | Language code (`en`, `de`, `fr`, `nl`, `es`, `ar`, `it`, `pt`, `ja`, `sv`, `da`, `no`). `null` = auto-detect from site locale |
| `headingLevels` | `array` | `null` | Heading levels to extract for heading matching (e.g., `[2, 3, 4]`) |
| `disableStopWords` | `bool` | `false` | Disable stop word filtering for this index |
| `skipEntriesWithoutUrl` | `bool` | `false` | Skip entries that don't have a URL |
| `enableAnalytics` | `bool` | `true` | Whether to track analytics for searches on this index |

## Multi-Site Indices

You have three options for site handling:

### Single Site

Index content from one specific site:

```php
'entries-en' => [
    'siteId' => 1,  // Just site ID 1
    // ...
],
```

### Multiple Sites

Index content from specific sites into one index:

```php
'entries-regional' => [
    'siteId' => [1, 3],  // Sites 1 and 3
    // ...
],
```

### All Sites

Index content from every site:

```php
'all-entries' => [
    'siteId' => null,  // All sites
    // ...
],
```

When indexing multiple sites, each element is stored with its `siteId`. This allows language filtering and per-site search results. Built-in backends store `siteId` as a field; external backends use composite document IDs (`{elementId}_{siteId}`).

## Filtering with Criteria

The `criteria` callback receives a Craft ElementQuery and should return it with filters applied:

```php
'criteria' => function($query) {
    return $query
        ->section(['news', 'blog'])
        ->type(['article', 'review']);
},
```

This is equivalent to building an element query in Twig — any method available on the element query works here.

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

## Per-Index Settings

### Disable Stop Words

Some indices may contain technical content where stop words are meaningful:

```php
'api-docs' => [
    'disableStopWords' => true,  // Keep words like "the", "is", "a"
    // ...
],
```

### Disable Analytics

Internal or admin-facing indices may not need analytics tracking:

```php
'internal-search' => [
    'enableAnalytics' => false,
    // ...
],
```

### Skip Entries Without URL

If your index includes entries that don't have landing pages, you can exclude them:

```php
'entries-en' => [
    'skipEntriesWithoutUrl' => true,
    // ...
],
```

## Multi-Environment Index Prefix

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

## Building Indices

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

### Auto-Indexing

When `autoIndex` is enabled (default), elements are automatically queued for indexing when saved and queued for removal when deleted. Search Manager stores those save/delete events in a pending sync buffer and drains them with `BatchSyncJob`, so rapid edits or imports can collapse repeated work into fewer backend calls.

The batch sync worker groups pending rows by index and writes documents through backend batch APIs. This is especially useful for Feed Me, CSV imports, migrations, and other bulk-write workflows where one import can trigger thousands of element save events.

A status sync job periodically checks for entries that became live (postDate passed) or expired without a save event. It queues matching rows into the same pending sync buffer, so scheduled status changes and normal save/delete events drain through one backend-writing path.

Search Manager debounces automatic `lastIndexed` metadata updates with `lastIndexedDebounceSeconds` (default: 60 seconds). This keeps the "Last Indexed" column current enough for operators while avoiding an extra metadata-table write for every save during imports or busy editing sessions. Set the value to `0` if you want the timestamp updated after every successful auto-sync.

When a batch sync drain completes, Search Manager refreshes the affected index counts from the matching Craft element query so the Control Panel count reflects the synced source content without requiring a manual rebuild.

Manual rebuilds, clears, and backend count refreshes still update index stats immediately.

#### Document Count Is Eventually Consistent

The "Documents" column on the Indices index page reflects what the index contained at the last point a count was authoritative — either a full rebuild or an explicit count refresh. Automatic save/delete syncs **do not** update this counter, by design: doing so would require a per-row backend probe for every save, defeating the API-amplification reduction that batch sync provides.

Expect the count to drift slightly during high-volume activity (large Feed Me runs, bulk imports). It does not affect what users see in search results — the underlying index is updated correctly, only the metadata badge is delayed.

To force the count to refresh:

- Run a rebuild: `php craft search-manager/index/rebuild --handle=entries-en`
- Use the refresh action on the index detail page (where exposed)

This is a deliberate trade-off against the API amplification that real-time counting would require; if your workflow depends on real-time document counts, prefer a periodic rebuild over relying on the live counter.

See [Console Commands](../developers/console-commands.md) for all CLI options.
