# Developers overview

Building something on top of Search Manager — a custom results page, a sync job, a headless frontend, a permission-gated dashboard? Start here. This page maps the plugin's extension points to the reference page that documents each one, so you can go straight to the API you need instead of reading everything.

## Architecture

Search Manager follows a modular architecture:

- **Backends** — Pluggable search engines (MySQL, PostgreSQL, Redis, File, Algolia, Meilisearch, Typesense) behind a unified interface
- **Indices** — Define what content gets indexed and how fields are mapped
- **Transformers** — Convert Craft elements into indexable documents
- **Services** — PHP API for search, indexing, analytics, autocomplete, and widget management
- **Frontend widget** — Web component (`<search-modal>`) with a [JavaScript API](../widget/javascript-api.md)

## Extension points

| What you want to do | How | Documentation |
|------|-----|---------------|
| Search programmatically | `BackendService::search()` | [API reference](api-reference.md) |
| Index custom elements | Custom transformer class | [Custom transformers](custom-transformers.md) |
| React to search events | Event listeners | [Events](events.md) |
| Add Twig functionality | Template variables and globals | [Template variables](template-variables.md), [Twig globals](twig-globals.md) |
| Query search from a SPA or headless frontend | Craft GraphQL queries | [GraphQL](graphql.md) |
| Manage from CLI | Console commands | [Console commands](console-commands.md) |
| Control access | Permissions | [Permissions](permissions.md) |
| Test configured search behavior | Settings → Test | [Testing tools](../resources/testing-tools.md) |
| Understand what comes from the base plugin | Shared features from lindemannrock-base | [Shared features](shared-features.md) |

## Quick reference

Every service is reachable from the plugin instance. Access them from PHP:

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
$plugin->transformers;     // Document transformer management
$plugin->indexedSnippets;  // Snippets and headings from indexed hit data
```

See [API reference](api-reference.md) for full method documentation.
