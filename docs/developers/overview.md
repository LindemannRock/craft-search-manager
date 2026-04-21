# Developers Overview

This section covers the technical APIs and extension points for integrating Search Manager into your plugins, modules, or custom code.

## Architecture

Search Manager follows a modular architecture:

- **Backends** — Pluggable search engines (MySQL, PostgreSQL, Redis, File, Algolia, Meilisearch, Typesense) behind a unified interface
- **Indices** — Define what content gets indexed and how fields are mapped
- **Transformers** — Convert Craft elements into indexable documents
- **Services** — PHP API for search, indexing, analytics, autocomplete, and widget management
- **Frontend Widget** — Web component (`<search-modal>`) with a [JavaScript API](../widget/javascript-api.md)

## Extension Points

| What | How | Documentation |
|------|-----|---------------|
| Search programmatically | `BackendService::search()` | [API Reference](api-reference.md) |
| Index custom elements | Custom transformer class | [Custom Transformers](custom-transformers.md) |
| React to search events | Event listeners | [Events](events.md) |
| Add Twig functionality | Template variables and globals | [Template Variables](template-variables.md), [Twig Globals](twig-globals.md) |
| Manage from CLI | Console commands | [Console Commands](console-commands.md) |
| Control access | Permissions | [Permissions](permissions.md) |
| Base plugin utilities | Shared features from lindemannrock-base | [Shared Features](shared-features.md) |

## Quick Reference

Access services from PHP:

```php
use lindemannrock\searchmanager\SearchManager;

$plugin = SearchManager::$plugin;

$plugin->backend;          // Search and index operations
$plugin->indexing;         // Element indexing
$plugin->analytics;        // Analytics tracking and queries
$plugin->autocomplete;     // Autocomplete suggestions
$plugin->widgetConfigs;    // Widget configuration CRUD
$plugin->widgetStyles;     // Widget style preset CRUD
$plugin->promotions;       // Search promotions
$plugin->queryRules;       // Query rules management
$plugin->deviceDetection;  // Device detection for analytics
$plugin->enrichment;       // Search result enrichment
$plugin->transformers;     // Document transformer management
```

See [API Reference](api-reference.md) for full method documentation.
