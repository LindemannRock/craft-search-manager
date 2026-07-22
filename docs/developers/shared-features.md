# Shared features

Search Manager builds on shared LindemannRock packages instead of duplicating common plugin infrastructure. This page is for developers working with or extending Search Manager in code — it explains which behavior comes from the base plugin or Logging Library so you know where a setting, helper, or UI pattern originates rather than mistaking it for something Search Manager implements itself.

## `lindemannrock/base`

| Feature | Description |
|---------|-------------|
| `PluginHelper::bootstrap()` | Initializes base module, Twig globals, badge color sets, and logging configuration |
| `PluginHelper::applyPluginNameFromConfig()` | Overrides plugin name from config file |
| `SettingsConfigTrait` | Config file override detection and log level validation |
| `SettingsDisplayNameTrait` | Standardized plugin name helper methods |
| `SettingsPersistenceTrait` | Database persistence for Settings models |
| `ColorHelper` | Color palette utilities for status badge color sets |
| `GeoHelper` | Geographic utilities (country code to name conversion) |

### Details

- `PluginHelper::bootstrap()` registers the `searchHelper` Twig global (see [Twig globals](twig-globals.md)), configures dedicated-file logging for the `searchManager:viewSystemLogs` / `searchManager:downloadSystemLogs` permissions, and registers the badge/filter color sets used throughout the Control Panel (index status, backend type, match type, action type, widget type, pending sync status/operation, native search coverage, API key type).
- `PluginHelper::applyPluginNameFromConfig()` lets `config/search-manager.php` override the Control Panel display name — the value `searchHelper` reads back.
- `SettingsConfigTrait` detects config-file overrides so disabled Control Panel fields show the correct warning.
- `SettingsDisplayNameTrait` provides display-name helpers such as `getDisplayName()`, `getFullName()`, and `getPluralDisplayName()` — the methods `searchHelper` proxies for Twig.
- `SettingsPersistenceTrait` stores settings in the plugin database table with type conversion for boolean, integer, float, and JSON fields.
- `ColorHelper` supplies the palette colors behind those registered badge sets.
- `GeoHelper` provides ISO 3166-1 alpha-2 country code utilities, used to show country names in [Analytics](../feature-tour/analytics.md).

---

## `lindemannrock/logging-library`

| Feature | Description |
|---------|-------------|
| `LoggingTrait` | Convenient logging methods (logInfo, logWarning, logError, logDebug) |
| `LoggingLibrary::addLogsNav()` | Adds "Logs" subnav to plugin CP navigation |

### Details

- `LoggingTrait` provides standardized log methods — `logInfo()`, `logWarning()`, `logError()`, `logDebug()` — used across nearly every service, controller, job, and backend adapter in Search Manager.
- `LoggingLibrary::addLogsNav()` adds the Logs section to the Search Manager Control Panel navigation.

---

