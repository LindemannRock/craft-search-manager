# Console Commands

Search Manager provides CLI commands for index management, maintenance, and security operations.

## Command Help

Use the plugin help command when you need to discover available commands or confirm the correct command group.

```bash title="PHP"
php craft search-manager/help
php craft search-manager/help maintenance/clear-storage
```

```bash title="DDEV"
ddev craft search-manager/help
ddev craft search-manager/help maintenance/clear-storage
```

Craft's native help also works when you already know the exact command:

```bash title="PHP"
php craft help search-manager/maintenance/clear-storage
```

```bash title="DDEV"
ddev craft help search-manager/maintenance/clear-storage
```

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
php craft search-manager/index/rebuild --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild --handle=entries-en
```

| Option | Type | Description |
|--------|------|-------------|
| `--handle` | `string` | Optional index handle to rebuild. Omit to rebuild all. |

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
php craft search-manager/index/clear --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/clear --handle=entries-en
```

| Option | Type | Description |
|--------|------|-------------|
| `--handle` | `string` | Optional index handle to clear. Omit to clear all. |

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

## API Keys

### `search-manager/api-keys/create`

Generate a new API key from the command line. Designed for automated provisioning — fresh installs, CI bootstrap, scripted deploys — where the Control Panel isn't available.

```bash title="PHP"
php craft search-manager/api-keys/create --name="Primary widget key"
```

```bash title="DDEV"
ddev craft search-manager/api-keys/create --name="Primary widget key"
```

Full example with every option:

```bash title="PHP"
php craft search-manager/api-keys/create \
  --name="Primary widget key" \
  --type=public \
  --indices=docs-en,blog-en \
  --referrers=example.com,*.example.com \
  --max-hits=50 \
  --rate-limit=120 \
  --valid-until=2027-12-31 \
  --disabled
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `--name` | `string` | *(required)* | Human-readable label shown in the CP listing. |
| `--type` | `string` | `public` | Key type: `public` (intended for browser embedding, `sm_pub_…` prefix) or `server` (server-side only, `sm_srv_…` prefix). Locked once generated. |
| `--indices` | `string` | *(none)* | Comma-separated index handles, or `*` for all indices (current and future). Empty is only valid with `--disabled`, creating an incomplete draft key that must be widened before it can be enabled. |
| `--referrers` | `string` | *(none — any referrer allowed)* | Comma-separated allowed referrer patterns. Each entry is either an exact host (`example.com`) or a subdomain wildcard (`*.example.com`, matches any subdomain depth). |
| `--max-hits` | `int` | *(none)* | Clamp on the `hitsPerPage` request parameter. |
| `--rate-limit` | `int` | *(none)* | Per-key requests-per-minute cap. Requests beyond the cap are rejected with `429`. Omit for no limit. |
| `--valid-until` | `string` | *(never expires)* | Optional expiry datetime in any format `DateTimeHelper::toDateTime` accepts (`2027-12-31`, `2027-12-31 23:59:59`, ISO 8601, etc.). |
| `--disabled` | `bool` | `false` | Create the key in a paused state. Default is enabled. |

#### Output

On success the command prints the key's metadata followed by the **plaintext value**:

```
✓ API key created.

  Key ID:           42
  Name:             Primary widget key
  Type:             public
  Prefix:           sm_pub_a1b2c3d4
  Allowed indices:  docs-en, blog-en
  Allowed referrers: example.com, *.example.com
  Max hits per page: 50
  Rate limit:       120 RPM
  Valid until:      2027-12-31 00:00
  Enabled:          yes

🔑 Plaintext key — copy this now, it will never be shown again:

    sm_pub_a1b2c3d4e5f67890abcdef1234567890

Search Manager stores only a hash. If you lose this value you will need to create a new key.
```

> [!WARNING]
> **The plaintext is shown once and never again.** Only its HMAC-SHA256 hash and 15-character display prefix are persisted. There is no retrieval path — losing the plaintext means creating a new key and updating every caller.
>
> The plaintext is written to **stdout only**. It is never written to the plugin's log channel. If you redirect command output to a file, treat that file as a secret.

#### Behaviour notes

- **Disabled vs revoked.** `--disabled` creates a paused key (config preserved; with **Require API Key** enabled, a disabled key is rejected). To remove a key permanently use the **Revoke** action in the CP — there is no console-side revoke or list/disable command; manage existing keys from the CP.
- **Validation.** The command exercises the same model validation as the CP, including the referrer-pattern allowlist (regex-looking values are rejected). On validation failure the command prints the field errors and exits with `EXIT_DATAERR`.
- **Enforcement.** Keys gate the public search and autocomplete endpoints when **Require API Key** is enabled (Settings → General → API Access); otherwise those endpoints stay anonymous. A key's `--rate-limit` (if set) caps requests per minute and returns `429` when exceeded.

See [API Keys](../feature-tour/api-keys.md) for the full feature tour and lifecycle (active / disabled / expired / revoked).

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
php craft search-manager/index/rebuild --handle=entries-en
```

```bash title="DDEV"
ddev craft search-manager/index/rebuild --handle=entries-en
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
