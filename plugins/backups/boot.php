<?php
/**
 * Backups & Cloud Plugin
 *
 * Database backup/restore with cloud replication support.
 */

// Load libraries (on-demand, not at boot - they're heavy)
// BackupManager and CloudBackup are loaded by the admin/action files when needed

// Register routes
$plugin->addRoute('GET', '/admin/backups', ['file' => $pluginDir . '/admin/backups.php'], 'admin.backups');
$plugin->addRoute('POST', '/admin/backups', ['file' => $pluginDir . '/admin/backups.php'], 'admin.backups.action');
$plugin->addRoute('POST', '/actions/backup', ['file' => $pluginDir . '/actions/backup.php'], 'actions.backup');

// Register admin menu item
$plugin->addAdminMenuItem('System', 'Backups', 'archive', 'admin.backups');

// Register permissions
$plugin->addFilter('all_permissions', function($permissions) {
    $permissions['manage_backups'] = 'Manage backups & recovery';
    return $permissions;
});

$plugin->addFilter('permissions_by_category', function($categories) {
    if (!isset($categories['System Operations'])) {
        $categories['System Operations'] = [];
    }
    $categories['System Operations']['manage_backups'] = 'Manage backups & recovery';
    return $categories;
});

// Define permission constant and helper if not already defined
if (!defined('PERM_MANAGE_BACKUPS')) {
    define('PERM_MANAGE_BACKUPS', 'manage_backups');
}
if (!function_exists('canManageBackups')) {
    function canManageBackups() {
        return hasPermission(PERM_MANAGE_BACKUPS);
    }
}

// Register scheduled tasks
$plugin->addFilter('scheduled_tasks', function($tasks) use ($pluginDir) {
    $tasks['backup:database'] = [
        'name' => 'backup:database',
        'schedule' => '0 2 * * *',
        'callback' => function() use ($pluginDir) {
            if (!class_exists('BackupManager')) {
                require_once $pluginDir . '/lib/BackupManager.php';
            }
            $result = BackupManager::createBackup('scheduled');
            return $result['success'] ? "Backup created: {$result['filename']}" : "Backup failed: {$result['error']}";
        },
        'enabled' => function_exists('getSetting') && getSetting('backup_enabled', '0') === '1',
        'timeout' => 600,
        'overlap' => false,
        'description' => 'Automated database backup',
    ];
    return $tasks;
});
