# Permissions

Search Manager registers granular permissions for controlling access to each area of the plugin. Permissions use a nested structure where "Manage" permissions serve as the parent (view/access) with write operations nested underneath.

## Permission Structure

### Backends

| Permission | Description |
|------------|-------------|
| `searchManager:manageBackends` | Parent permission for backends section |
| `searchManager:viewBackends` | View backends and access the backends CP section |
| `searchManager:createBackends` | Create new backends |
| `searchManager:editBackends` | Edit existing backends |
| `searchManager:deleteBackends` | Delete backends |

### Indices

| Permission | Description |
|------------|-------------|
| `searchManager:manageIndices` | Parent permission for indices section |
| `searchManager:viewIndices` | View indices and access the indices CP section |
| `searchManager:createIndices` | Create new indices |
| `searchManager:editIndices` | Edit existing indices |
| `searchManager:deleteIndices` | Delete indices |
| `searchManager:rebuildIndices` | Rebuild indices |
| `searchManager:clearIndices` | Clear index data |

### Promotions

| Permission | Description |
|------------|-------------|
| `searchManager:managePromotions` | Parent permission for promotions section |
| `searchManager:viewPromotions` | View promotions and access the promotions CP section |
| `searchManager:createPromotions` | Create new promotions |
| `searchManager:editPromotions` | Edit existing promotions |
| `searchManager:deletePromotions` | Delete promotions |

### Query Rules

| Permission | Description |
|------------|-------------|
| `searchManager:manageQueryRules` | Parent permission for query rules section |
| `searchManager:viewQueryRules` | View query rules and access the query rules CP section |
| `searchManager:createQueryRules` | Create new query rules |
| `searchManager:editQueryRules` | Edit existing query rules |
| `searchManager:deleteQueryRules` | Delete query rules |

### Widget Configs

| Permission | Description |
|------------|-------------|
| `searchManager:manageWidgetConfigs` | Parent permission for widget configs section |
| `searchManager:viewWidgetConfigs` | View widget configs and access the widgets CP section |
| `searchManager:createWidgetConfigs` | Create new widget configs |
| `searchManager:editWidgetConfigs` | Edit existing widget configs |
| `searchManager:deleteWidgetConfigs` | Delete widget configs |

### Analytics

| Permission | Description |
|------------|-------------|
| `searchManager:viewAnalytics` | View the analytics dashboard |
| `searchManager:exportAnalytics` | Export analytics data |
| `searchManager:clearAnalytics` | Clear analytics data |

### Cache

| Permission | Description |
|------------|-------------|
| `searchManager:clearCache` | Clear search caches |

### Debug

| Permission | Description |
|------------|-------------|
| `searchManager:viewDebug` | View debug info in search responses |

### Logs

| Permission | Description |
|------------|-------------|
| `searchManager:viewLogs` | View plugin logs |
| `searchManager:viewSystemLogs` | View system-level logs |
| `searchManager:downloadSystemLogs` | Download system log files |

### Settings

| Permission | Description |
|------------|-------------|
| `searchManager:manageSettings` | Access and modify plugin settings |

## Checking Permissions

### In Twig

```twig
{% if currentUser.can('searchManager:manageBackends') %}
    {# Show backends management UI #}
{% endif %}

{% if currentUser.can('searchManager:viewAnalytics') %}
    <a href="{{ url('search-manager/analytics') }}">View Analytics</a>
{% endif %}
```

### In PHP

```php
if (Craft::$app->getUser()->checkPermission('searchManager:manageBackends')) {
    // User has permission
}

// In a controller
$this->requirePermission('searchManager:manageIndices');
```

## Nested Permission Pattern

Craft's nested permissions are a UI convenience — the parent permission does not automatically grant child permissions. In Search Manager:

- **"Manage" permissions** (e.g., `manageBackends`) are the top-level parent
- **"View" permissions** (e.g., `viewBackends`) control read access and CP subnav visibility
- **Write permissions** (e.g., `createBackends`, `editBackends`, `deleteBackends`) control specific operations

All view and write permissions are nested under the manage parent. To give a user read-only access to backends, grant `manageBackends` + `viewBackends`. For full access, also grant the specific write permissions they need.
