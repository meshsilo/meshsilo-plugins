<?php
/**
 * Webhooks Plugin
 *
 * HTTP webhook notifications for application events.
 */

// Load webhook functions and delivery system
require_once $pluginDir . '/lib/WebhookFunctions.php';
require_once $pluginDir . '/lib/WebhookDelivery.php';

// Register routes
$plugin->addRoute('GET', '/admin/webhooks', ['file' => $pluginDir . '/admin/webhooks.php'], 'admin.webhooks');
$plugin->addRoute('POST', '/admin/webhooks', ['file' => $pluginDir . '/admin/webhooks.php'], 'admin.webhooks.save');

// Register admin menu item
$plugin->addAdminMenuItem('Integration', 'Webhooks', 'link', 'admin.webhooks');

// Register feature
$plugin->addFilter('available_features', function($features) {
    $features['webhooks'] = [
        'name' => 'Webhooks',
        'description' => 'HTTP notifications for application events',
        'icon' => 'link',
        'category' => 'Integration',
        'default' => true,
    ];
    return $features;
});

// Register permissions
$plugin->addFilter('all_permissions', function($permissions) {
    $permissions['manage_webhooks'] = 'Manage webhooks';
    return $permissions;
});

$plugin->addFilter('permissions_by_category', function($categories) {
    if (!isset($categories['Integration'])) {
        $categories['Integration'] = [];
    }
    $categories['Integration']['manage_webhooks'] = 'Manage webhooks';
    return $categories;
});

// Define permission constant and helper
if (!defined('PERM_MANAGE_WEBHOOKS')) {
    define('PERM_MANAGE_WEBHOOKS', 'manage_webhooks');
}
if (!function_exists('canManageWebhooks')) {
    function canManageWebhooks() {
        return hasPermission(PERM_MANAGE_WEBHOOKS);
    }
}

// Handle trigger_webhook filter (called from core db.php stub)
$plugin->addFilter('trigger_webhook', function($result, $event, $payload) {
    WebhookDelivery::triggerWebhooks($event, is_array($payload) ? $payload : ['data' => $payload]);
    return true;
});

// Handle event_dispatched filter (called from Events::emit())
$plugin->addFilter('event_dispatched', function($result, $event, $data) {
    if (function_exists('getSetting') && getSetting('webhooks_enabled', '1') === '1') {
        WebhookDelivery::triggerWebhooks($event, $data);
    }
    return $result;
});

// Register scheduled task for webhook retry
$plugin->addFilter('scheduled_tasks', function($tasks) {
    $tasks['webhooks:retry'] = [
        'name' => 'webhooks:retry',
        'schedule' => '*/5 * * * *',
        'callback' => function() {
            // Retry failed webhook deliveries
            if (function_exists('getDB')) {
                $db = getDB();
                $stmt = $db->prepare("SELECT * FROM webhook_deliveries WHERE http_code >= 400 AND retries < 3 AND created_at > datetime('now', '-1 hour')");
                $result = $stmt->execute();
                $retried = 0;
                while ($delivery = $result->fetchArray(PDO::FETCH_ASSOC)) {
                    // Re-deliver
                    $webhook = getWebhookById($delivery['webhook_id']);
                    if ($webhook) {
                        WebhookDelivery::sendWebhook($webhook, json_decode($delivery['payload'], true) ?: []);
                        $retried++;
                    }
                }
                return "Retried {$retried} webhook deliveries";
            }
            return 'Skipped';
        },
        'enabled' => true,
        'timeout' => 120,
        'overlap' => false,
        'description' => 'Retry failed webhook deliveries',
    ];
    return $tasks;
});
