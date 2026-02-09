<?php
/**
 * Retention Policies Plugin
 *
 * Automated data retention, archiving, and legal holds.
 */

// Load RetentionManager
require_once $pluginDir . '/lib/RetentionManager.php';

// Register routes
$plugin->addRoute('GET', '/admin/retention', ['file' => $pluginDir . '/admin/retention-policies.php'], 'admin.retention');
$plugin->addRoute('POST', '/admin/retention', ['file' => $pluginDir . '/admin/retention-policies.php'], 'admin.retention.save');

// Register admin menu item
$plugin->addAdminMenuItem('Security', 'Data Retention', 'shield', 'admin.retention');

// Register feature
$plugin->addFilter('available_features', function($features) {
    $features['retention_policies'] = [
        'name' => 'Retention Policies',
        'description' => 'Automated data retention, archiving, and legal holds',
        'icon' => 'archive',
        'category' => 'Compliance',
        'default' => false,
    ];
    return $features;
});

// Register permissions
$plugin->addFilter('all_permissions', function($permissions) {
    $permissions['manage_retention'] = 'Manage data retention';
    return $permissions;
});

$plugin->addFilter('permissions_by_category', function($categories) {
    if (!isset($categories['Security & Compliance'])) {
        $categories['Security & Compliance'] = [];
    }
    $categories['Security & Compliance']['manage_retention'] = 'Manage data retention policies';
    return $categories;
});

// Define permission constant and helper if not already defined
if (!defined('PERM_MANAGE_RETENTION')) {
    define('PERM_MANAGE_RETENTION', 'manage_retention');
}
if (!function_exists('canManageRetention')) {
    function canManageRetention() {
        return hasPermission(PERM_MANAGE_RETENTION);
    }
}

// Register scheduled task
$plugin->addFilter('scheduled_tasks', function($tasks) use ($pluginDir) {
    $tasks['retention:apply'] = [
        'name' => 'retention:apply',
        'schedule' => '0 2 * * *',
        'callback' => function() {
            $result = RetentionManager::applyAllPolicies();
            $total = array_sum(array_column($result, 'affected'));
            return "Retention policies applied: {$total} items affected";
        },
        'enabled' => true,
        'timeout' => 600,
        'overlap' => false,
        'description' => 'Apply data retention policies',
    ];
    return $tasks;
});
