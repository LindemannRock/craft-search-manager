# Permissions

Search Manager registers granular permissions that can be assigned to user groups via **Settings → Users → User Groups → [Group Name] → Search Manager**.

## Permission Structure

### Backends

| Permission | Description |
|------------|-------------|
| **`searchManager:manageBackends`** | Access the backends section (view and access) |
| └─ `searchManager:createBackends` | Create new backends |
| └─ `searchManager:editBackends` | Edit existing backends |
| └─ `searchManager:deleteBackends` | Delete backends |

### Indices

| Permission | Description |
|------------|-------------|
| **`searchManager:manageIndices`** | Access the indices section (view and access) |
| └─ `searchManager:createIndices` | Create new indices |
| └─ `searchManager:editIndices` | Edit existing indices |
| └─ `searchManager:deleteIndices` | Delete indices |
| └─ `searchManager:rebuildIndices` | Rebuild indices |
| └─ `searchManager:clearIndices` | Clear index data |

### Promotions

| Permission | Description |
|------------|-------------|
| **`searchManager:managePromotions`** | Access the promotions section (view and access) |
| └─ `searchManager:createPromotions` | Create new promotions |
| └─ `searchManager:editPromotions` | Edit existing promotions |
| └─ `searchManager:deletePromotions` | Delete promotions |

### Query Rules

| Permission | Description |
|------------|-------------|
| **`searchManager:manageQueryRules`** | Access the query rules section (view and access) |
| └─ `searchManager:createQueryRules` | Create new query rules |
| └─ `searchManager:editQueryRules` | Edit existing query rules |
| └─ `searchManager:deleteQueryRules` | Delete query rules |

### Widget Configs

| Permission | Description |
|------------|-------------|
| **`searchManager:manageWidgetConfigs`** | Access the widget configs section (view and access) |
| └─ `searchManager:createWidgetConfigs` | Create new widget configs |
| └─ `searchManager:editWidgetConfigs` | Edit existing widget configs |
| └─ `searchManager:deleteWidgetConfigs` | Delete widget configs |

### Widget Styles

| Permission | Description |
|------------|-------------|
| **`searchManager:manageWidgetStyles`** | Access the widget styles section (view and access) |
| └─ `searchManager:createWidgetStyles` | Create new widget styles |
| └─ `searchManager:editWidgetStyles` | Edit existing widget styles |
| └─ `searchManager:deleteWidgetStyles` | Delete widget styles |

### Analytics

| Permission | Description |
|------------|-------------|
| **`searchManager:viewAnalytics`** | Parent — view the analytics dashboard |
| └─ `searchManager:exportAnalytics` | Export analytics data |
| └─ `searchManager:clearAnalytics` | Clear analytics data |

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
| **`searchManager:viewLogs`** | Parent — view plugin logs |
| └─ `searchManager:viewSystemLogs` | View system-level logs |
|     └─ `searchManager:downloadSystemLogs` | Download system log files |

### Settings

| Permission | Description |
|------------|-------------|
| `searchManager:manageSettings` | Access and modify plugin settings |

## Checking Permissions

In Twig:

```twig
{% if currentUser.can('searchManager:manageBackends') %}
    {# Show backends management UI #}
{% endif %}

{% if currentUser.can('searchManager:viewAnalytics') %}
    <a href="{{ url('search-manager/analytics') }}">View Analytics</a>
{% endif %}
```

In PHP:

```php
if (Craft::$app->getUser()->checkPermission('searchManager:manageBackends')) {
    // User has permission
}

// In a controller
$this->requirePermission('searchManager:manageIndices');
```

## Nested Permission Pattern

Craft's nested permissions are a UI convenience — the parent permission does not automatically grant child permissions at runtime.

- **"Manage" permissions** (e.g., `manageBackends`) are the access/view permission — checking this grants visibility of the section in the CP subnav
- **Write permissions** (e.g., `createBackends`, `editBackends`, `deleteBackends`) are nested under manage and control specific write operations

To give a user read-only access, grant only `manageBackends`. For full access, also grant the specific write permissions needed.
