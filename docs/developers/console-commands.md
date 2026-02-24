# Console Commands

Search Manager provides CLI commands for index management, maintenance, and security operations.

## Index Commands

### `search-manager/index/list`

List all configured indices with their status.

```bash title="PHP"
php craft search-manager/index/list
```

```bash title="DDEV"
ddev craft search-manager/index/list
```

Shows each index's name, handle, element type, document count, last indexed date, and enabled status.

### `search-manager/index/rebuild`

Rebuild all indices or a specific index. This clears the index data and re-indexes all matching elements.

Rebuild all indices:

```bash title="PHP"
php craft search-manager/index/rebuild
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild
```

Rebuild a specific index:

```bash title="PHP"
php craft search-manager/index/rebuild entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild entries-en
```

| Argument | Type | Description |
|----------|------|-------------|
| `handle` | `string` (optional) | Index handle to rebuild. Omit to rebuild all. |

After rebuilding, cache warming runs automatically if enabled (see [Caching](../feature-tour/caching.md)).

> [!TIP]
> For sites with 10,000+ elements, schedule rebuilds during off-hours to avoid queue congestion. If a rebuild times out, see [Troubleshooting](../resources/troubleshooting.md#rebuild-job-times-out).

### `search-manager/index/clear`

Clear all indices or a specific index without re-indexing. The index configuration remains — only the data is removed.

Clear all indices:

```bash title="PHP"
php craft search-manager/index/clear
```

```bash title="DDEV"
ddev craft search-manager/index/clear
```

Clear a specific index:

```bash title="PHP"
php craft search-manager/index/clear entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/clear entries-en
```

| Argument | Type | Description |
|----------|------|-------------|
| `handle` | `string` (optional) | Index handle to clear. Omit to clear all. |

## Maintenance Commands

### `search-manager/maintenance/status`

Show the current state of all backend storage types (database, Redis, and file). Displays document counts, key counts, and file counts for each storage type.

```bash title="PHP"
php craft search-manager/maintenance/status
```

```bash title="DDEV"
ddev craft search-manager/maintenance/status
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `--verbose` | `bool` | `false` | Show additional detail for each storage type |

### `search-manager/maintenance/clear-storage`

Clear backend storage data. Use this for cleanup or troubleshooting. The `--type` option is **required**.

```bash title="PHP"
php craft search-manager/maintenance/clear-storage --type=database
```

```bash title="DDEV"
ddev craft search-manager/maintenance/clear-storage --type=database
```

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `--type` | `string` | Yes | Storage type to clear: `database`, `redis`, or `file` |

> [!WARNING]
> This permanently removes stored data. Rebuild your indices afterward to restore search functionality.

## Security Commands

### `search-manager/security/generate-salt`

Generate a cryptographically secure salt for IP hashing and add it to your `.env` file.

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

This command:
1. Generates a random 64-character hex string
2. Adds `SEARCH_MANAGER_IP_SALT=...` to your `.env` file
3. Confirms the salt was saved

Run this once after installation. Copy the salt value to your staging and production `.env` files manually. See [Privacy & Security](../feature-tour/privacy-security.md) for details.

## Common Workflows

### Fresh Setup

1. Install the plugin:

```bash title="Composer"
composer require lindemannrock/craft-search-manager
```

```bash title="DDEV"
ddev composer require lindemannrock/craft-search-manager
```

2. Enable it in Craft:

```bash title="PHP"
php craft plugin/install search-manager
```

```bash title="DDEV"
ddev craft plugin/install search-manager
```

3. Generate the IP hash salt:

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

4. Build your indices:

```bash title="PHP"
php craft search-manager/index/rebuild
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild
```

### Rebuilding After Config Changes

After changing index configuration or transformers, rebuild the affected index:

```bash title="PHP"
php craft search-manager/index/rebuild entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild entries-en
```

Or rebuild all indices:

```bash title="PHP"
php craft search-manager/index/rebuild
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild
```

### Troubleshooting

1. Check storage status:

```bash title="PHP"
php craft search-manager/maintenance/status
```

```bash title="DDEV"
ddev craft search-manager/maintenance/status
```

2. Clear all index data:

```bash title="PHP"
php craft search-manager/index/clear
```

```bash title="DDEV"
ddev craft search-manager/index/clear
```

3. Rebuild from scratch:

```bash title="PHP"
php craft search-manager/index/rebuild
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild
```
