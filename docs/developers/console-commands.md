# Console Commands

Search Manager provides CLI commands for index management, maintenance, and security operations.

## Index Commands

### `search-manager/index/list`

List all configured indices with their status.

```bash
php craft search-manager/index/list
```

```bash
ddev craft search-manager/index/list
```

Shows each index's name, handle, backend, element count, and enabled status.

### `search-manager/index/rebuild`

Rebuild all indices or a specific index. This clears the index data and re-indexes all matching elements.

Rebuild all indices:

```bash
php craft search-manager/index/rebuild
```

Rebuild a specific index:

```bash
php craft search-manager/index/rebuild entries-en
```

```bash
ddev craft search-manager/index/rebuild entries-en
```

| Argument | Type | Description |
|----------|------|-------------|
| `handle` | `string` (optional) | Index handle to rebuild. Omit to rebuild all. |

After rebuilding, cache warming runs automatically if enabled (see [Caching](../feature-tour/caching.md)).

> **Tip:** For large sites, schedule rebuilds during off-hours to avoid queue congestion.

### `search-manager/index/clear`

Clear all indices or a specific index without re-indexing. The index configuration remains — only the data is removed.

Clear all indices:

```bash
php craft search-manager/index/clear
```

Clear a specific index:

```bash
php craft search-manager/index/clear entries-en
```

```bash
ddev craft search-manager/index/clear entries-en
```

| Argument | Type | Description |
|----------|------|-------------|
| `handle` | `string` (optional) | Index handle to clear. Omit to clear all. |

## Maintenance Commands

### `search-manager/maintenance/status`

Show the current state of all backend storage types (database, Redis, and file). Displays document counts, key counts, and file counts for each storage type.

```bash
php craft search-manager/maintenance/status
```

```bash
ddev craft search-manager/maintenance/status
```

This command takes no options — it always shows all storage types.

### `search-manager/maintenance/clear-storage`

Clear backend storage data. Use this for cleanup or troubleshooting. The `--type` option is **required**.

```bash
php craft search-manager/maintenance/clear-storage --type=database
```

```bash
ddev craft search-manager/maintenance/clear-storage --type=redis
```

```bash
ddev craft search-manager/maintenance/clear-storage --type=file
```

| Option | Type | Required | Description |
|--------|------|----------|-------------|
| `--type` | `string` | Yes | Storage type to clear: `database`, `redis`, or `file` |

> **Warning:** This permanently removes stored data. Rebuild your indices afterward to restore search functionality.

## Security Commands

### `search-manager/security/generate-salt`

Generate a cryptographically secure salt for IP hashing and add it to your `.env` file.

```bash
php craft search-manager/security/generate-salt
```

```bash
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

```bash
ddev composer require lindemannrock/craft-search-manager
```

2. Enable it in Craft:

```bash
ddev craft plugin/install search-manager
```

3. Generate the IP hash salt:

```bash
ddev craft search-manager/security/generate-salt
```

4. Build your indices:

```bash
ddev craft search-manager/index/rebuild
```

### Rebuilding After Config Changes

After changing index configuration or transformers, rebuild the affected index:

```bash
ddev craft search-manager/index/rebuild entries-en
```

Or rebuild all indices:

```bash
ddev craft search-manager/index/rebuild
```

### Troubleshooting

1. Check storage status:

```bash
ddev craft search-manager/maintenance/status
```

2. Clear all index data:

```bash
ddev craft search-manager/index/clear
```

3. Rebuild from scratch:

```bash
ddev craft search-manager/index/rebuild
```
