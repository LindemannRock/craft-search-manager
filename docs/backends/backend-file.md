# File Backend

Get search running with nothing but PHP: the File backend stores search data as files in Craft's storage directory — no database tables, no external services, no dependencies beyond PHP itself.

## What you'll use it for

- Development and testing environments
- Quick prototyping before choosing a production backend
- Small, persistent, single-node installations with fewer than ~500 indexed elements
- Zero dependencies beyond PHP

## Create your first File backend

1. Go to **Search Manager → Backends** and click **New Backend**.
2. Give it a **Name** (e.g. "Local File Storage") — the **Handle** fills in automatically as you type, or edit it yourself.
3. Set **Backend Type** to **File**.
   Search Manager shows an environment warning while File is selected. The same warning appears when viewing a config-defined File backend.
4. Optionally set **Storage Path** to a custom directory. Leave it blank and Search Manager stores index files under `storage/runtime/search-manager/indices/` — see [Storage location](#storage-location) below. The field supports environment-variable autosuggest — start typing `$` to pick from your defined environment variables.
5. In the sidebar, confirm **Enabled** is on, and turn on **Default** if this should be the backend new indices use automatically.
6. Click **Save**. Search Manager tests the connection and switches to a **Diagnostics** tab showing the result, response time, and whether this backend supports **Browse** and **Multi-Query** (both **No** for File — see [Built-in vs external backends](backends.md#built-in-vs-external-backends)). Use **Refresh Connection** to retest anytime.

For environment-specific setups, define the backend in `config/search-manager.php` instead — see [Configuration](#configuration) below.

## Features

- Full BM25 relevance ranking
- All search operators (phrase, NOT, wildcards, field-specific, boosting, boolean)
- Fuzzy matching with n-gram similarity
- Stop words filtering in 12 languages
- Localized boolean operators in 12 languages
- Native search replacement (front-end `Entry::find()->search()` template queries only — Control Panel search always uses Craft's native search)
- No external dependencies whatsoever

## Storage location

Index data is stored in:

```text
storage/runtime/search-manager/indices/
```

Search and autocomplete result caches are stored in:

```text
storage/runtime/search-manager/cache/search/
storage/runtime/search-manager/cache/autocomplete/
```

These cache folders belong to Search Manager's general result-cache layer (used whenever the `cacheStorageMethod` setting is `file`, the default) — they exist regardless of which search backend you choose, not just with the File backend.

These directories are created automatically. If you remove File index storage manually, run a full rebuild before searching it again. Search Manager does not reconstruct a missing File index manifest from individual document files.

## Manifest and rebuild requirement

Each File index has one authoritative `manifest.json` beside its document files. It contains the rich element-suggestion rows and aggregate document metadata used for language filtering, autocomplete filtering, document lengths, and normal or split-section document keys. Search Manager reads that manifest under a shared lock, while any operation that changes both the manifest and physical File storage holds one exclusive index lock for the whole mutation. Before touching document, element, title, parent-key, or related cleanup files, it atomically marks the manifest as updating; it returns the manifest to ready only after every physical step succeeds. Concurrent File storage instances therefore cannot expose a ready manifest that describes a partially applied mutation.

The manifest has an explicit format, version, and ready state. A new empty File index starts with a ready empty manifest, and a successful full clear or rebuild creates a fresh ready manifest before indexing documents.

After upgrading from a Search Manager version that predates the manifest, rebuild every existing File-backed index once:

```bash
php craft search-manager/index/rebuild --handle=entries-en
```

Until that rebuild succeeds, a populated legacy File index has no authoritative manifest. Suggestions and metadata-dependent operations fail closed and Search Manager logs an instruction to rebuild; it does not fall back to scanning element files or reopening document files. The same recovery applies if `manifest.json` is missing, corrupt, incomplete, left non-ready by an interrupted or failed mutation, or from an unsupported format version.

## Configuration

```php
'backends' => [
    'local-file' => [
        'name' => 'Local File Storage',
        'backendType' => 'file',
        'enabled' => true,
        'settings' => [],
    ],
],
```

No additional settings are needed. By default, index files are stored in `storage/runtime/search-manager/indices/`.

### Custom storage path

You can specify a custom directory for index storage:

```php
'backends' => [
    'local-file' => [
        'name' => 'Local File Storage',
        'backendType' => 'file',
        'enabled' => true,
        'settings' => [
            'storagePath' => '@storage/custom-search-indices',
        ],
    ],
],
```

The path supports Craft aliases (`@storage`, `@root`), absolute paths inside those roots, and environment variables (`$ENV_VAR`) when they resolve inside those roots. Path traversal (`..`) is not allowed, and file indices cannot be stored in `@webroot`.

## Limitations

- Slower than MySQL or Redis for indices above ~500 elements due to file I/O overhead
- Intended only for persistent, single-node storage
- MySQL or Redis is preferred for edge, ephemeral, shared-volume, multi-server, or larger environments
- No `browse()` or native `multipleQueries()` support (sequential fallback is used)

For production sites with more than ~500 indexed elements, switch to MySQL, Redis, or an external backend.
