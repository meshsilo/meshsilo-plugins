<?php
/**
 * Webhooks API Routes
 *
 * GET    /api/webhooks          - List webhooks
 * GET    /api/webhooks/{id}     - Get single webhook
 * POST   /api/webhooks          - Create webhook
 * PUT    /api/webhooks/{id}     - Update webhook
 * DELETE /api/webhooks/{id}     - Delete webhook
 */

/**
 * Validate webhook URL is not targeting internal/private resources (SSRF prevention)
 */
function validateWebhookUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parsed = parse_url($url);
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'])) {
        return false;
    }
    $host = $parsed['host'] ?? '';
    // Block localhost and common internal hostnames
    $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', 'metadata.google.internal'];
    if (in_array(strtolower($host), $blockedHosts)) {
        return false;
    }
    // Resolve hostname and check for private IPs
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return false; // DNS resolution failed
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return true;
}

function handleWebhooksRoute($method, $id, $apiUser) {
    switch ($method) {
        case 'GET':
            if ($id === null) {
                listWebhooks($apiUser);
            } else {
                getWebhook(validateId($id), $apiUser);
            }
            break;

        case 'POST':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            createWebhookApi($apiUser);
            break;

        case 'PUT':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            updateWebhookApi(validateId($id), $apiUser);
            break;

        case 'DELETE':
            requireApiPermission($apiUser, API_PERM_ADMIN);
            deleteWebhookApi(validateId($id), $apiUser);
            break;

        default:
            apiError('Method not allowed', 405);
    }
}

/**
 * List all webhooks
 */
function listWebhooks($apiUser) {
    requireApiPermission($apiUser, API_PERM_ADMIN);

    $webhooks = getAllWebhooks();
    $result = array_map(function($w) {
        return formatWebhookForApi($w);
    }, $webhooks);

    apiResponse(['data' => $result]);
}

/**
 * Get a single webhook
 */
function getWebhook($id, $apiUser) {
    requireApiPermission($apiUser, API_PERM_ADMIN);

    $webhook = getWebhookById($id);
    if (!$webhook) {
        apiError('Webhook not found', 404);
    }

    apiResponse(['data' => formatWebhookForApi($webhook)]);
}

/**
 * Create a new webhook
 */
function createWebhookApi($apiUser) {
    $data = getJsonBody();
    validateRequired($data, ['url', 'events']);

    // Validate URL (with SSRF prevention)
    if (!validateWebhookUrl($data['url'])) {
        apiError('Invalid webhook URL: must be a public HTTP(S) URL', 400);
    }

    // Validate events
    $validEvents = getWebhookEvents();
    $events = is_array($data['events']) ? $data['events'] : [$data['events']];
    foreach ($events as $event) {
        if (!in_array($event, $validEvents)) {
            apiError("Invalid event: $event. Valid events: " . implode(', ', $validEvents), 400);
        }
    }

    $webhookId = createWebhook(
        $data['url'],
        $events,
        $data['secret'] ?? null,
        $data['name'] ?? null,
        $data['is_active'] ?? true
    );

    if (!$webhookId) {
        apiError('Failed to create webhook', 500);
    }

    logActivity('create', 'webhook', $webhookId, $data['name'] ?? $data['url'], ['via' => 'api']);

    $webhook = getWebhookById($webhookId);
    apiResponse(['data' => formatWebhookForApi($webhook)], 201);
}

/**
 * Update a webhook
 */
function updateWebhookApi($id, $apiUser) {
    $data = getJsonBody();

    $webhook = getWebhookById($id);
    if (!$webhook) {
        apiError('Webhook not found', 404);
    }

    // Validate URL if provided (with SSRF prevention)
    if (isset($data['url']) && !validateWebhookUrl($data['url'])) {
        apiError('Invalid webhook URL: must be a public HTTP(S) URL', 400);
    }

    // Validate events if provided
    if (isset($data['events'])) {
        $validEvents = getWebhookEvents();
        $events = is_array($data['events']) ? $data['events'] : [$data['events']];
        foreach ($events as $event) {
            if (!in_array($event, $validEvents)) {
                apiError("Invalid event: $event. Valid events: " . implode(', ', $validEvents), 400);
            }
        }
    }

    $success = updateWebhook(
        $id,
        $data['url'] ?? $webhook['url'],
        $data['events'] ?? json_decode($webhook['events'], true),
        $data['secret'] ?? $webhook['secret'],
        $data['name'] ?? $webhook['name'],
        $data['is_active'] ?? $webhook['is_active']
    );

    if (!$success) {
        apiError('Failed to update webhook', 500);
    }

    logActivity('edit', 'webhook', $id, $data['name'] ?? $webhook['name'], ['via' => 'api']);

    $webhook = getWebhookById($id);
    apiResponse(['data' => formatWebhookForApi($webhook)]);
}

/**
 * Delete a webhook
 */
function deleteWebhookApi($id, $apiUser) {
    $webhook = getWebhookById($id);
    if (!$webhook) {
        apiError('Webhook not found', 404);
    }

    $success = deleteWebhookById($id);
    if (!$success) {
        apiError('Failed to delete webhook', 500);
    }

    logActivity('delete', 'webhook', $id, $webhook['name'] ?? $webhook['url'], ['via' => 'api']);

    apiResponse(['success' => true, 'message' => 'Webhook deleted']);
}

/**
 * Format webhook for API response
 */
function formatWebhookForApi($webhook) {
    return [
        'id' => (int)$webhook['id'],
        'name' => $webhook['name'],
        'url' => $webhook['url'],
        'events' => json_decode($webhook['events'], true) ?: [],
        'is_active' => (bool)$webhook['is_active'],
        'last_triggered_at' => $webhook['last_triggered_at'],
        'last_status_code' => $webhook['last_status_code'] ? (int)$webhook['last_status_code'] : null,
        'failure_count' => (int)($webhook['failure_count'] ?? 0),
        'created_at' => $webhook['created_at']
    ];
}
