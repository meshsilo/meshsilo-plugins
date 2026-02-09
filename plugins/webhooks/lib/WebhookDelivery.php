<?php
/**
 * Webhook Delivery System
 *
 * Extracted from Events.php as part of the webhooks plugin extraction.
 * Handles finding active webhooks for events and delivering payloads
 * via synchronous or asynchronous HTTP requests.
 */

class WebhookDelivery {

    /**
     * Trigger webhooks for an event
     *
     * Finds all active webhooks that match the given event and delivers
     * the payload to each one.
     *
     * @param string $event Event name (e.g., 'model.created')
     * @param array $data Event data to include in the payload
     * @param bool $async Whether to use async (short-timeout) delivery
     */
    public static function triggerWebhooks(string $event, array $data, bool $async = true): void {
        try {
            if (!function_exists('getDB')) {
                return;
            }

            $db = getDB();

            // Check if webhooks table exists
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $tableCheck = $db->query("SHOW TABLES LIKE 'webhooks'")->fetch();
            } else {
                $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='webhooks'");
            }
            if (!$tableCheck) {
                return;
            }

            // Get webhooks that match this event
            $stmt = $db->prepare('
                SELECT * FROM webhooks
                WHERE is_active = 1
                AND (events LIKE :event_pattern OR events LIKE :wildcard_pattern OR events = "*")
            ');
            $stmt->bindValue(':event_pattern', '%"' . $event . '"%', PDO::PARAM_STR);

            // Also check for wildcard patterns (e.g., "model.*")
            $eventParts = explode('.', $event);
            $wildcardEvent = $eventParts[0] . '.*';
            $stmt->bindValue(':wildcard_pattern', '%"' . $wildcardEvent . '"%', PDO::PARAM_STR);

            $result = $stmt->execute();

            while ($webhook = $result->fetchArray(PDO::FETCH_ASSOC)) {
                $payload = [
                    'event' => $event,
                    'timestamp' => $data['_timestamp'] ?? time(),
                    'data' => array_diff_key($data, array_flip(['_event', '_timestamp', '_user_id']))
                ];

                if ($async) {
                    self::sendWebhookAsync($webhook, $payload);
                } else {
                    self::sendWebhook($webhook, $payload);
                }
            }
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Webhook trigger error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send a webhook request synchronously
     *
     * @param array $webhook Webhook configuration row from the database
     * @param array $payload The payload data to send as JSON
     * @return bool True if the delivery was successful (2xx response)
     */
    public static function sendWebhook(array $webhook, array $payload): bool {
        $url = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Silo-Signature: sha256=' . $signature,
                'X-Silo-Event: ' . ($payload['event'] ?? ''),
                'User-Agent: Silo-Webhook/1.0'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log webhook delivery
        self::logWebhookDelivery($webhook['id'], $payload['event'] ?? '', $httpCode, $error);

        return $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Send webhook asynchronously (non-blocking with short timeout)
     *
     * Uses a very short cURL timeout so the request is fired but the
     * response is not waited for. For true async, a job queue would be needed.
     *
     * @param array $webhook Webhook configuration row from the database
     * @param array $payload The payload data to send as JSON
     */
    public static function sendWebhookAsync(array $webhook, array $payload): void {
        $url = $webhook['url'];
        $secret = $webhook['secret'] ?? '';

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500, // Very short timeout
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Silo-Signature: sha256=' . $signature,
                'X-Silo-Event: ' . ($payload['event'] ?? ''),
                'User-Agent: Silo-Webhook/1.0'
            ]
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Log webhook delivery attempt to the database
     *
     * Records the delivery result and updates the webhook's last_triggered
     * timestamp.
     *
     * @param int $webhookId The webhook ID
     * @param string $event The event name
     * @param int $httpCode The HTTP response code (0 if connection failed)
     * @param string $error Any cURL error message
     */
    public static function logWebhookDelivery(int $webhookId, string $event, int $httpCode, string $error): void {
        try {
            if (!function_exists('getDB')) {
                return;
            }

            $db = getDB();

            // Check if webhook_logs table exists
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $tableCheck = $db->query("SHOW TABLES LIKE 'webhook_logs'")->fetch();
            } else {
                $tableCheck = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='webhook_logs'");
            }
            if (!$tableCheck) {
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO webhook_logs (webhook_id, event, http_code, error, created_at)
                VALUES (:webhook_id, :event, :http_code, :error, CURRENT_TIMESTAMP)
            ');
            $stmt->bindValue(':webhook_id', $webhookId, PDO::PARAM_INT);
            $stmt->bindValue(':event', $event, PDO::PARAM_STR);
            $stmt->bindValue(':http_code', $httpCode, PDO::PARAM_INT);
            $stmt->bindValue(':error', $error ?: null, PDO::PARAM_STR);
            $stmt->execute();

            // Update webhook last_triggered
            $updateStmt = $db->prepare('UPDATE webhooks SET last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id');
            $updateStmt->bindValue(':id', $webhookId, PDO::PARAM_INT);
            $updateStmt->execute();
        } catch (Exception $e) {
            // Silently fail to avoid disrupting the application
        }
    }
}
