# Twig Globals

Search Manager provides the following global variables in your Twig templates.

## `searchHelper`

*Provided by `lindemannrock/base`*

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
{{ searchHelper.displayName }}
{{ searchHelper.pluralDisplayName }}
{{ searchHelper.fullName }}
{{ searchHelper.lowerDisplayName }}
{{ searchHelper.pluralLowerDisplayName }}
```

---

