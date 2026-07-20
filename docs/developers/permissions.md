# Permissions

Give an editor read-only access to analytics, let one team manage backends while another only manages promotions, or lock API key creation down to admins — Search Manager's permissions are granular enough to model that. Assign them per user group via **Settings → Users → User Groups → [Group Name] → Search Manager**.

## Permission structure

Permissions are grouped by the Control Panel section they gate. Most groups follow the same shape: a parent permission (bold below) that controls whether the section is visible at all, plus child permissions — indented with `└─` — for the specific write operations within it. A few sections (Cache, Debug, Settings) are single standalone permissions with no children.

## Editions and permissions

Permissions for Pro-only features — view analytics, and the manage groups for query rules, promotions, pending syncs, and Widget Styles — are only registered while the [Pro edition](../pricing/editions.md) is active; on Standard they don't appear in the permission UI. The analytics data controls (`searchManager:exportAnalytics`, `searchManager:clearAnalytics`) are always registered in every edition, because exporting and purging retained analytics data remains available on Standard.

> [!NOTE]
> Craft removes assignments of unregistered permissions when a user or user group is re-saved. If you edit and save a user group while the site is on Standard, any Pro permission assignments that group had are dropped and must be re-granted after upgrading to Pro again.

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

### Pending syncs

| Permission | Description |
|------------|-------------|
| **`searchManager:managePendingSyncs`** | Access the [Pending Syncs](../feature-tour/pending-syncs.md) section (view and access) |
| └─ `searchManager:retryPendingSyncs` | Retry failed or abandoned pending syncs |
| └─ `searchManager:purgePendingSyncs` | Delete pending sync rows and purge abandoned entries |

### Promotions

| Permission | Description |
|------------|-------------|
| **`searchManager:managePromotions`** | Access the promotions section (view and access) |
| └─ `searchManager:createPromotions` | Create new promotions |
| └─ `searchManager:editPromotions` | Edit existing promotions |
| └─ `searchManager:deletePromotions` | Delete promotions |

### Query rules

| Permission | Description |
|------------|-------------|
| **`searchManager:manageQueryRules`** | Access the query rules section (view and access) |
| └─ `searchManager:createQueryRules` | Create new query rules |
| └─ `searchManager:editQueryRules` | Edit existing query rules |
| └─ `searchManager:deleteQueryRules` | Delete query rules |

### API keys

| Permission | Description |
|------------|-------------|
| **`searchManager:manageApiKeys`** | Access the API keys section (view and access) |
| └─ `searchManager:createApiKeys` | Generate new API keys |
| └─ `searchManager:editApiKeys` | Edit existing API keys (name, restrictions, enabled state) |
| └─ `searchManager:revokeApiKeys` | Delete API keys permanently |

`manageApiKeys` is the parent — without it the section is hidden entirely. Grant it on its own for read-only access (view the list and individual key configurations). The three child permissions are independent: a user can have edit without revoke, or revoke without create. See [API Keys](../feature-tour/api-keys.md) for the lifecycle (active / disabled / expired / revoked) and the difference between disabling (pausing) and revoking (deleting).

### Widget configs

| Permission | Description |
|------------|-------------|
| **`searchManager:manageWidgetConfigs`** | Access the widget configs section (view and access) |
| └─ `searchManager:createWidgetConfigs` | Create new widget configs |
| └─ `searchManager:editWidgetConfigs` | Edit existing widget configs |
| └─ `searchManager:deleteWidgetConfigs` | Delete widget configs |

### Widget styles

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

## Checking permissions

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

## Nested permission pattern

Craft's nested permissions are a UI convenience — the parent permission does not automatically grant child permissions at runtime.

- **"Manage" permissions** (e.g., `manageBackends`) are the access/view permission — checking this grants visibility of the section in the CP subnav
- **Write permissions** (e.g., `createBackends`, `editBackends`, `deleteBackends`) are nested under manage and control specific write operations

To give a user read-only access, grant only `manageBackends`. For full access, also grant the specific write permissions needed.
