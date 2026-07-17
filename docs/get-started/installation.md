# Installation & Setup

> [!NOTE]
> Search Manager is in active development and not yet available on the Craft Plugin Store. Install via Composer for now.

## Composer

Add the package to your project using Composer and the command line.

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Then tell Composer to require the plugin, and Craft to install it:

```bash title="Composer"
composer require lindemannrock/craft-search-manager && php craft plugin/install search-manager
```

```bash title="DDEV"
ddev composer require lindemannrock/craft-search-manager && ddev craft plugin/install search-manager
```

3. **Optional** — Enable [Logging Library](https://github.com/LindemannRock/craft-logging-library) for log viewing:

> [!NOTE]
> Logging Library is required by Composer. Install or activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

## Post-Install Setup

After installing, open **Search Manager → Setup** in the Control Panel before relying on analytics. The setup page checks the required privacy salt.

### Generate an IP hash salt

Generate a secure salt for analytics privacy and unique visitor tracking:

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

This writes `SEARCH_MANAGER_IP_SALT` to your `.env` file. Keep the same salt across all environments — changing it resets unique visitor tracking.

### Review configuration

See [Configuration](configuration.md) for all available settings. Most can be managed from **Search Manager → Settings** without a config file.

## Quick Start

See [Quickstart](quickstart.md) for the fastest path from install to first result.
