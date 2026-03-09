# MeshSilo Official Plugins

Official plugin repository for [MeshSilo](https://github.com/meshsilo/silo) — the web-based Digital Asset Manager for 3D model files.

## Available Plugins

| Plugin | Version | Description |
|--------|---------|-------------|
| [hello-world](plugins/hello-world/) | 1.0.0 | Test plugin demonstrating the plugin system |

## Installing Plugins

### From MeshSilo Admin

1. Go to **Admin > Plugins > Repositories**
2. The official repository is pre-configured
3. Switch to the **Browse** tab to see available plugins
4. Click **Install** on any plugin

### Manual Installation

1. Download the plugin zip from [Releases](https://github.com/meshsilo/silo-plugins/releases)
2. Go to **Admin > Plugins > Installed**
3. Use the **Upload Plugin** form to upload the zip file

## Creating a Plugin

Each plugin lives in its own directory under `plugins/` and requires at minimum:

```
my-plugin/
  plugin.json    # Plugin manifest (required)
  boot.php       # Entry point, runs when plugin is active (required)
  migrations.php # Database migrations (optional)
  assets/        # CSS/JS files served via asset proxy (optional)
  pages/         # Public page templates (optional)
  admin/         # Admin page templates (optional)
  includes/      # PHP classes (optional)
  actions/       # AJAX/form action handlers (optional)
```

### plugin.json

```json
{
    "id": "my-plugin",
    "name": "My Plugin",
    "version": "1.0.0",
    "description": "What this plugin does.",
    "author": "Your Name",
    "url": "https://github.com/you/my-plugin",
    "license": "MIT",
    "min_silo_version": "1.0.0",
    "requires_plugins": [],
    "provides_features": ["my_feature"]
}
```

### boot.php

This file runs on every request when the plugin is active. Available variables:

- `$plugin` — PluginManager instance
- `$pluginDir` — Absolute path to the plugin directory
- `$pluginMeta` — Parsed plugin.json as an array

```php
<?php
// Register routes
$plugin->addRoute('GET', '/my-page', ['file' => $pluginDir . '/pages/my-page.php'], 'plugin.my-page');

// Register admin page
$plugin->addAdminPage('my-plugin', $pluginDir . '/admin/settings.php');
$plugin->addAdminMenuItem('Plugins', 'My Plugin', 'my-plugin', 'admin.plugin.my-plugin');

// Register assets (served from plugins/my-plugin/assets/)
$plugin->addStylesheet('my-plugin', 'style.css');
$plugin->addScript('my-plugin', 'script.js');

// Register filters
$plugin->addFilter('page_title', function($title) {
    return $title . ' - Enhanced';
});

// Listen for events
if (class_exists('Events')) {
    Events::on('model.uploaded', function($data) use ($pluginDir) {
        // React to model uploads
    });
}
```

### migrations.php

Return an array of migrations using the check/apply pattern:

```php
<?php
return [
    [
        'check' => function() {
            $db = getDB();
            // Check if migration is already applied
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='my_table'")->fetchAll();
            return count($tables) > 0;
        },
        'apply' => function() {
            $db = getDB();
            $db->exec('CREATE TABLE my_table (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(200) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )');
        },
    ],
];
```

## Plugin API

### Routes

```php
$plugin->addRoute($method, $pattern, $handler, $name);
// $method: GET, POST, PUT, DELETE
// $pattern: URL pattern with optional params, e.g. '/my-plugin/{id}'
// $handler: ['file' => '/path/to/handler.php'] or callable
// $name: Named route for URL generation
```

### Admin

```php
$plugin->addAdminPage($slug, $filePath);
$plugin->addAdminMenuItem($category, $label, $slug, $routeName);
```

### Assets

```php
$plugin->addStylesheet($pluginId, $relativePath);  // Relative to assets/
$plugin->addScript($pluginId, $relativePath);       // Relative to assets/
```

### Filters

```php
// Register
$plugin->addFilter($hook, $callback, $priority);

// Apply (from any code)
$value = PluginManager::applyFilter('hook_name', $originalValue, ...$args);
```
