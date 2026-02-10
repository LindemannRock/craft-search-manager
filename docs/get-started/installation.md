# Installation & Setup

> **Pre-Release:** Search Manager is in active development and not yet available on the Craft Plugin Store. Install via Composer for now.

## Composer

Add the package to your project using Composer and the command line.

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Then tell Composer to require the plugin, and Craft to install it:

```bash
composer require lindemannrock/craft-search-manager && php craft plugin/install search-manager
```

**Using DDEV:**

```bash
ddev composer require lindemannrock/craft-search-manager && ddev craft plugin/install search-manager
```

## Post-Install: Generate IP Hash Salt

After installation, generate the IP hash salt so analytics can properly track and anonymize visitors:

```bash
php craft search-manager/security/generate-salt
```

Or with DDEV:

```bash
ddev craft search-manager/security/generate-salt
```

This command automatically adds `SEARCH_MANAGER_IP_SALT` to your `.env` file. Copy this value to your staging and production `.env` files manually.

> **Tip:** Skipping this step won't break anything — search works normally without it. Analytics still tracks queries, devices, and referrers, but IP hashing and geo-location won't be available. You can generate the salt later and full tracking resumes immediately.

## Copy Config File (Optional)

For advanced configuration, copy the config file to your project:

```bash
cp vendor/lindemannrock/craft-search-manager/src/config.php config/search-manager.php
```

This gives you full control over backends, indices, widgets, and all plugin settings. See [Configuration](configuration.md) for details.

## Quick Start

Once installed, the fastest way to get searching:

1. **Pick a backend** — MySQL or File require zero setup. Go to Search Manager in the CP, or define one in `config/search-manager.php`. See [Backends](../feature-tour/backends.md).

2. **Create an index** — Define what content to index (entries, assets, categories, doc pages, etc.). See [Indices](../feature-tour/indices.md).

3. **Rebuild** — Run `php craft search-manager/index/rebuild` (or use the CP button).

4. **Search** — Use `craft.searchManager.search()` in your templates. See [Basic Search](../template-guides/basic-search.md).
