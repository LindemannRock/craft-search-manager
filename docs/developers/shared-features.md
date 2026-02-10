# Shared Features

Search Manager uses the following shared libraries and features.

## `lindemannrock/base`

| Feature | Description |
|---------|-------------|
| `PluginHelper::bootstrap()` | Initializes base module, Twig globals, and logging configuration |
| `PluginHelper::applyPluginNameFromConfig()` | Overrides plugin name from config file |
| `SettingsConfigTrait` | Config file override detection and log level validation |
| `SettingsDisplayNameTrait` | Standardized plugin name helper methods |
| `SettingsPersistenceTrait` | Database persistence for Settings models |
| `GeoHelper` | Geographic utilities (country code to name conversion) |

### Details

**PluginHelper::bootstrap()**

Provides plugin name helpers in Twig templates (see Twig Globals section)

**PluginHelper::applyPluginNameFromConfig()**

Allows customizing the plugin display name via config/{plugin-handle}.php

**SettingsConfigTrait**

Settings can be overridden via config/{plugin-handle}.php. Debug logging requires devMode.

**SettingsDisplayNameTrait**

Provides getDisplayName(), getFullName(), getPluralDisplayName(), etc.

**SettingsPersistenceTrait**

Settings are stored in database with automatic type conversion for boolean, integer, float, and JSON fields.

**GeoHelper**

ISO 3166-1 alpha-2 country code utilities

---

## `lindemannrock/logging-library`

| Feature | Description |
|---------|-------------|
| `LoggingTrait` | Convenient logging methods (logInfo, logWarning, logError, logDebug) |
| `LoggingLibrary::addLogsNav()` | Adds "Logs" subnav to plugin CP navigation |

### Details

**LoggingTrait**

Provides standardized logging to dedicated plugin log files

**LoggingLibrary::addLogsNav()**

View plugin logs directly in the Control Panel

---

