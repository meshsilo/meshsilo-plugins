<?php
/**
 * Real User Monitoring (RUM) Data Collector
 *
 * Receives performance metrics from the browser and stores them for analysis.
 */

require_once __DIR__ . '/../../../includes/config.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Get JSON payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['metrics'])) {
    http_response_code(400);
    exit;
}

$metrics = $data['metrics'];
$errors = $data['errors'] ?? [];
$slowResources = $data['slowResources'] ?? [];

// Store metrics
try {
    $db = getDB();

    // Insert metrics
    $stmt = $db->prepare('
        INSERT INTO rum_metrics (
            url, referrer, user_agent, connection_type,
            lcp, fid, cls, fcp, fp, ttfb,
            dom_content_loaded, page_load, dom_interactive,
            resource_count, js_errors,
            created_at
        ) VALUES (
            :url, :referrer, :user_agent, :connection_type,
            :lcp, :fid, :cls, :fcp, :fp, :ttfb,
            :dom_content_loaded, :page_load, :dom_interactive,
            :resource_count, :js_errors,
            :created_at
        )
    ');

    $stmt->execute([
        ':url' => substr($metrics['url'] ?? '', 0, 255),
        ':referrer' => substr($metrics['referrer'] ?? '', 0, 255),
        ':user_agent' => substr($metrics['userAgent'] ?? '', 0, 255),
        ':connection_type' => $metrics['connection']['effectiveType'] ?? null,
        ':lcp' => $metrics['lcp'] ?? null,
        ':fid' => $metrics['fid'] ?? null,
        ':cls' => $metrics['cls'] ?? null,
        ':fcp' => $metrics['fcp'] ?? null,
        ':fp' => $metrics['fp'] ?? null,
        ':ttfb' => $metrics['timing']['ttfb'] ?? null,
        ':dom_content_loaded' => $metrics['timing']['domContentLoaded'] ?? null,
        ':page_load' => $metrics['timing']['load'] ?? null,
        ':dom_interactive' => $metrics['timing']['domInteractive'] ?? null,
        ':resource_count' => $metrics['resourceCount'] ?? 0,
        ':js_errors' => count($errors),
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    // Store errors if any
    if (!empty($errors)) {
        $errorStmt = $db->prepare('
            INSERT INTO rum_errors (url, message, source, line_number, created_at)
            VALUES (:url, :message, :source, :line, :created_at)
        ');

        foreach ($errors as $error) {
            $errorStmt->execute([
                ':url' => substr($metrics['url'] ?? '', 0, 255),
                ':message' => substr($error['message'] ?? '', 0, 500),
                ':source' => substr($error['source'] ?? '', 0, 255),
                ':line' => $error['line'] ?? null,
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    http_response_code(204); // No content
} catch (Exception $e) {
    logError('RUM data collection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
}

