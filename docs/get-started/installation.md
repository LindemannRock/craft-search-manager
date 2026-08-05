# Installation & Setup

Get Search Manager installed and its first setup check cleared — a couple of Composer commands, then one visit to the Control Panel.

## Composer

1. Open your terminal and go to your Craft project:

```bash
cd /path/to/project
```

2. Tell Composer to require the plugin, and Craft to install it:

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

## Post-install setup

After installing, open **Search Manager → Setup** in the Control Panel before relying on analytics — the setup page checks the required privacy salt.

### Generate an IP hash salt

This step needs the terminal. Generate a secure salt for analytics privacy and unique visitor tracking:

```bash title="PHP"
php craft search-manager/security/generate-salt
```

```bash title="DDEV"
ddev craft search-manager/security/generate-salt
```

When `.env` can be safely updated, the command writes and verifies a same-directory temporary file before atomically replacing it. If `.env` is missing, it prints the assignment for you to add manually. If a safe replacement fails, the command leaves the original file untouched, prints the manual assignment, and exits non-zero.

Keep the same salt across all environments — changing it resets unique visitor tracking.

## Next steps

- [Configuration](configuration.md) — review all available settings; most can be managed from **Search Manager → Settings** without a config file
- [Quickstart](quickstart.md) — the fastest path from install to first result
