<?php
/**
 * Webhook Database Functions
 *
 * These functions were extracted from core db.php as part of the
 * webhooks plugin extraction. They use getDB() from core.
 */

function getWebhookEvents() {
    return [
        'model.created',
        'model.updated',
        'model.deleted',
        'model.downloaded',
        'category.created',
        'category.deleted',
        'tag.created',
        'tag.deleted',
        'collection.created',
        'collection.deleted'
    ];
}

function getAllWebhooks() {
    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM webhooks ORDER BY created_at DESC');
        $webhooks = [];
        while ($row = $result->fetch()) {
            $webhooks[] = $row;
        }
        return $webhooks;
    } catch (Exception $e) {
        return [];
    }
}

function getActiveWebhooksForEvent($event) {
    try {
        $db = getDB();
        $result = $db->query('SELECT * FROM webhooks WHERE is_active = 1');
        $webhooks = [];
        while ($row = $result->fetch()) {
            $events = json_decode($row['events'], true) ?: [];
            if (in_array($event, $events) || in_array('*', $events)) {
                $webhooks[] = $row;
            }
        }
        return $webhooks;
    } catch (Exception $e) {
        return [];
    }
}

function getWebhookById($id) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM webhooks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

function createWebhook($url, $events, $secret = null, $name = null, $isActive = true) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO webhooks (name, url, secret, events, is_active)
            VALUES (:name, :url, :secret, :events, :is_active)
        ');
        $stmt->execute([
            ':name' => $name,
            ':url' => $url,
            ':secret' => $secret,
            ':events' => json_encode($events),
            ':is_active' => $isActive ? 1 : 0
        ]);
        return $db->lastInsertId();
    } catch (Exception $e) {
        if (function_exists('logException')) {
            logException($e, ['action' => 'create_webhook']);
        }
        return false;
    }
}

function updateWebhook($id, $url, $events, $secret, $name, $isActive) {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            UPDATE webhooks SET name = :name, url = :url, secret = :secret,
            events = :events, is_active = :is_active WHERE id = :id
        ');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':url' => $url,
            ':secret' => $secret,
            ':events' => json_encode($events),
            ':is_active' => $isActive ? 1 : 0
        ]);
        return true;
    } catch (Exception $e) {
        if (function_exists('logException')) {
            logException($e, ['action' => 'update_webhook']);
        }
        return false;
    }
}

function deleteWebhookById($id) {
    try {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM webhooks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function deliverWebhook($webhook, $event, $jsonPayload) {
    $startTime = microtime(true);

    $headers = [
        'Content-Type: application/json',
        'User-Agent: Silo-Webhook/1.0',
        'X-Webhook-Event: ' . $event
    ];

    // Add signature if secret is set
    if (!empty($webhook['secret'])) {
        $signature = hash_hmac('sha256', $jsonPayload, $webhook['secret']);
        $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
    }

    $ch = curl_init($webhook['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $success = $statusCode >= 200 && $statusCode < 300;

    // Log delivery
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO webhook_deliveries (webhook_id, event, payload, response_code, response_body, success, duration_ms)
            VALUES (:webhook_id, :event, :payload, :response_code, :response_body, :success, :duration_ms)
        ');
        $stmt->execute([
            ':webhook_id' => $webhook['id'],
            ':event' => $event,
            ':payload' => $jsonPayload,
            ':response_code' => $statusCode,
            ':response_body' => substr($response ?: $error, 0, 10000),
            ':success' => $success ? 1 : 0,
            ':duration_ms' => $durationMs
        ]);

        // Update webhook stats
        $type = $db->getType();
        if ($success) {
            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = NOW(), last_status_code = :code, failure_count = 0 WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = CURRENT_TIMESTAMP, last_status_code = :code, failure_count = 0 WHERE id = :id');
            }
        } else {
            if ($type === 'mysql') {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = NOW(), last_status_code = :code, failure_count = failure_count + 1 WHERE id = :id');
            } else {
                $stmt = $db->prepare('UPDATE webhooks SET last_triggered_at = CURRENT_TIMESTAMP, last_status_code = :code, failure_count = failure_count + 1 WHERE id = :id');
            }
        }
        $stmt->execute([':code' => $statusCode, ':id' => $webhook['id']]);
    } catch (Exception $e) {
        if (function_exists('logException')) {
            logException($e, ['action' => 'log_webhook_delivery']);
        }
    }

    return $success;
}
