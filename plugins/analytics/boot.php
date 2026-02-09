<?php
/**
 * Analytics & Reports Plugin
 *
 * Provides dashboard analytics, print statistics, and scheduled report generation.
 */

// Load Analytics library
require_once $pluginDir . '/lib/Analytics.php';

// Register routes
$plugin->addRoute('GET', '/print-analytics', ['file' => $pluginDir . '/pages/print-analytics.php'], 'print-analytics');
$plugin->addRoute('GET', '/actions/print-history', ['file' => $pluginDir . '/actions/print-history.php'], 'actions.print-history');
$plugin->addRoute('POST', '/actions/print-history', ['file' => $pluginDir . '/actions/print-history.php'], 'actions.print-history.post');
$plugin->addRoute('GET', '/admin/analytics', ['file' => $pluginDir . '/admin/analytics.php'], 'admin.analytics');
$plugin->addRoute('POST', '/admin/analytics', ['file' => $pluginDir . '/admin/analytics.php'], 'admin.analytics.save');

// Register admin menu items
$plugin->addAdminMenuItem('System', 'Analytics', 'activity', 'admin.analytics');

// Register features
$plugin->addFilter('available_features', function($features) {
    $features['analytics'] = [
        'name' => 'Analytics Dashboard',
        'description' => 'Usage analytics, download tracking, and system statistics',
        'icon' => 'activity',
        'category' => 'Analytics',
        'default' => true,
    ];
    $features['scheduled_reports'] = [
        'name' => 'Scheduled Reports',
        'description' => 'Automated report generation and email delivery',
        'icon' => 'file-text',
        'category' => 'Analytics',
        'default' => false,
    ];
    return $features;
});

// Register scheduled tasks
$plugin->addFilter('scheduled_tasks', function($tasks) use ($pluginDir) {
    $tasks['stats:calculate'] = [
        'name' => 'stats:calculate',
        'schedule' => '0 1 * * *',
        'callback' => function() {
            if (class_exists('Analytics')) {
                Analytics::calculateDailyStats();
                return 'Daily stats calculated';
            }
            return 'Analytics class not available';
        },
        'enabled' => true,
        'timeout' => 300,
        'overlap' => false,
        'description' => 'Calculate daily analytics statistics',
    ];
    return $tasks;
});
