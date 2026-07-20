# Twig globals

Search Manager registers one global variable, `searchHelper`, in every Twig template — no `craft.searchManager` call needed to reach it.

## `searchHelper`

*Provided by `lindemannrock/base`*

Reach for `searchHelper` when a template needs to display the plugin's name or locate its cache folder **without hardcoding either one**. Both are configurable: the display name can be renamed via the `pluginName` setting or `config/search-manager.php` (see [Configuration](../get-started/configuration.md)), and the cache path is derived from the plugin handle. Search Manager's own Control Panel templates use `searchHelper` for exactly this — every settings-page breadcrumb and doc title reads `searchHelper.fullName` instead of a literal `"Search Manager"` string, so a renamed installation stays consistent everywhere. The Cache settings page goes a step further and prints the resolved storage path with `searchHelper.cacheBasePath`, rather than a hardcoded folder name.

If you're building a custom Control Panel page, a template override, or a support/debug panel that needs to reference the plugin by its (possibly renamed) display name or its runtime cache folder, use `searchHelper` instead of writing either value out by hand.

| Property | Description |
|----------|-------------|
| `searchHelper.displayName` | Display name (singular, without "Manager") |
| `searchHelper.pluralDisplayName` | Plural display name (without "Manager") |
| `searchHelper.fullName` | Full plugin name (as configured) |
| `searchHelper.lowerDisplayName` | Lowercase display name (singular) |
| `searchHelper.pluralLowerDisplayName` | Lowercase plural display name |
| `searchHelper.cacheBasePath` | Base runtime cache path for the plugin (also `searchHelper.getCachePath(type)` for a per-type subfolder path) |

### Examples

```twig
{# Page title / breadcrumb that follows a renamed plugin automatically #}
<h1>{{ searchHelper.fullName }} settings</h1>

{# Display the resolved cache location in a custom debug panel #}
<code>{{ searchHelper.cacheBasePath }}</code>
<code>{{ searchHelper.getCachePath('search') }}</code>

{{ searchHelper.displayName }}
{{ searchHelper.pluralDisplayName }}
{{ searchHelper.lowerDisplayName }}
{{ searchHelper.pluralLowerDisplayName }}
```

---

