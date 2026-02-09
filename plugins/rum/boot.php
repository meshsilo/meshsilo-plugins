<?php
/**
 * Real User Monitoring Plugin
 *
 * Client-side performance monitoring and page load analytics.
 */

// Register routes
$plugin->addRoute('GET', '/admin/rum', ['file' => $pluginDir . '/admin/rum.php'], 'admin.rum');
$plugin->addRoute('POST', '/actions/rum', ['file' => $pluginDir . '/actions/rum.php'], 'actions.rum');

// Register admin menu item
$plugin->addAdminMenuItem('System', 'Real User Monitoring', 'zap', 'admin.rum');

// Register feature
$plugin->addFilter('available_features', function($features) {
    $features['rum'] = [
        'name' => 'Real User Monitoring',
        'description' => 'Client-side performance tracking and page load analytics',
        'icon' => 'zap',
        'category' => 'Analytics',
        'default' => false,
    ];
    return $features;
});
