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

// Expose GET/POST/PUT/DELETE /api/webhooks[/{id}] through the API dispatcher.
// Without this registration the handler file is never loaded and the
// resource 404s regardless of what api/webhooks.php implements.
$plugin->addFilter('api_routes', function($routes) use ($pluginDir) {
    require_once $pluginDir . '/api/webhooks.php';
    $routes['webhooks'] = 'handleWebhooksRoute';
    return $routes;
});

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

                // Only retry recent failures, up to 3 times each.
                if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                    $sql = "SELECT * FROM webhook_deliveries WHERE http_code >= 400 AND retries < 3 AND created_at > (NOW() - INTERVAL 1 HOUR)";
                } else {
                    $sql = "SELECT * FROM webhook_deliveries WHERE http_code >= 400 AND retries < 3 AND created_at > datetime('now', '-1 hour')";
                }
                $stmt = $db->prepare($sql);
                $stmt->execute();
                // Materialize rows first so re-delivery logging can't affect this cursor.
                $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $retried = 0;
                foreach ($deliveries as $delivery) {
                    $webhook = getWebhookById($delivery['webhook_id']);
                    if ($webhook) {
                        // Re-deliver WITHOUT logging a new row, then advance this
                        // row's retry counter so it is retried at most 3 times.
                        $ok = WebhookDelivery::sendWebhook($webhook, json_decode($delivery['payload'], true) ?: [], false);
                        $newCode = $ok ? 200 : (int)$delivery['http_code'];
                        $upd = $db->prepare('UPDATE webhook_deliveries SET retries = retries + 1, http_code = :code WHERE id = :id');
                        $upd->execute([':code' => $newCode, ':id' => $delivery['id']]);
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
