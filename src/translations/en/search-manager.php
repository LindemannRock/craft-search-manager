<?php

return [
    // Plugin name
    'Search Manager' => 'Search Manager',

    // Navigation
    'Dashboard' => 'Dashboard',
    'Indices' => 'Indices',
    'Settings' => 'Settings',
    'Logs' => 'Logs',

    // Settings sections
    'General' => 'General',
    'Backends' => 'Backends',
    'Indexing' => 'Indexing',
    'Advanced' => 'Advanced',

    // Settings fields
    'Plugin Name' => 'Plugin Name',
    'The name of the plugin as it appears in the Control Panel' => 'The name of the plugin as it appears in the Control Panel',
    'Log Level' => 'Log Level',
    'Auto-Index Elements' => 'Auto-Index Elements',
    'Automatically index elements when they are saved' => 'Automatically index elements when they are saved',
    'Search Backend' => 'Search Backend',
    'Choose which search backend to use' => 'Choose which search backend to use',
    'Queue Enabled' => 'Queue Enabled',
    'Use queue for indexing operations' => 'Use queue for indexing operations',
    'Batch Size' => 'Batch Size',
    'Number of elements to index in each batch' => 'Number of elements to index in each batch',
    'Index Prefix' => 'Index Prefix',
    'Prefix for index names (useful for multi-environment setups)' => 'Prefix for index names (useful for multi-environment setups)',

    // Override warnings
    'This is being overridden by the <code>{setting}</code> setting in <code>config/search-manager.php</code>.' => 'This is being overridden by the <code>{setting}</code> setting in <code>config/search-manager.php</code>.',
    'This is being overridden by config file.' => 'This is being overridden by config file.',

    // Messages
    'Settings saved.' => 'Settings saved.',
    'Could not save settings.' => 'Could not save settings.',
    'Index rebuilt successfully.' => 'Index rebuilt successfully.',
    'Index cleared successfully.' => 'Index cleared successfully.',

    // Permissions
    'View indices' => 'View indices',
    'Manage indices' => 'Manage indices',
    'Create indices' => 'Create indices',
    'Edit indices' => 'Edit indices',
    'Delete indices' => 'Delete indices',
    'Rebuild indices' => 'Rebuild indices',
    'View system logs' => 'View system logs',
    'Manage settings' => 'Manage settings',

    // Job descriptions
    'Indexing element {id}' => 'Indexing element {id}',
    'Indexing {count} elements' => 'Indexing {count} elements',
    'Rebuilding index: {handle}' => 'Rebuilding index: {handle}',
    'Rebuilding all search indices' => 'Rebuilding all search indices',

    // Cache clearing
    'Search Manager indices' => 'Search Manager indices',
];
