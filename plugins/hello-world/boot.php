<?php
/**
 * Hello World Plugin - Boot File
 *
 * This file runs when the plugin is active.
 * Available variables: $plugin (PluginManager), $pluginDir (string), $pluginMeta (array)
 */

// Register a route for the plugin page
$plugin->addRoute('GET', '/hello-world', ['file' => $pluginDir . '/pages/hello.php'], 'plugin.hello-world');

// Register an admin page
$plugin->addAdminPage('hello-world', $pluginDir . '/admin/settings.php');

// Register admin menu item
$plugin->addAdminMenuItem('Plugins', 'Hello World', 'hello-world', 'admin.plugin.hello-world');

// Register assets
$plugin->addStylesheet('hello-world', 'hello.css');

// Register a filter example
$plugin->addFilter('page_title', function($title) {
    return $title; // Could modify titles if needed
});

// Listen for events if the Events class is available
if (class_exists('Events')) {
    Events::on('page.loaded', function() {
        // Plugin can react to events
    });
}
