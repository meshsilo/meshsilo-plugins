<?php
/**
 * Shared request/response helpers for the printing plugin's AJAX action endpoints.
 *
 * Every /actions/* handler in this plugin speaks JSON and shares the same
 * preamble: enforce a feature flag, require an authenticated user, and (for
 * state-changing actions) validate the CSRF token. These helpers remove that
 * duplicated boilerplate and give the endpoints one consistent response shape
 * and set of HTTP status codes. Each terminating helper calls exit(), matching
 * the "echo JSON then stop" pattern the handlers already relied on.
 */

if (!function_exists('printingJson')) {
    /**
     * Emit a JSON payload with an HTTP status code and stop the request.
     */
    function printingJson(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }
        echo json_encode($payload);
        exit;
    }

    /**
     * Emit a { success: false, error } payload and stop. Defaults to 400.
     */
    function printingFail(string $error, int $status = 400): void {
        printingJson(['success' => false, 'error' => $error], $status);
    }

    /**
     * Emit a { success: true, ... } payload and stop.
     */
    function printingOk(array $extra = []): void {
        printingJson(['success' => true] + $extra);
    }

    /**
     * Stop with 403 unless the named feature is enabled.
     */
    function printingRequireFeature(string $feature, string $label): void {
        if (function_exists('isFeatureEnabled') && !isFeatureEnabled($feature)) {
            printingFail($label . ' feature is disabled', 403);
        }
    }

    /**
     * Require an authenticated user (401 otherwise). Returns the current user.
     */
    function printingRequireLogin(): array {
        if (!function_exists('isLoggedIn') || !isLoggedIn()) {
            printingFail('Not authenticated', 401);
        }
        return getCurrentUser();
    }

    /**
     * Validate the CSRF token (403 otherwise) when $action is state-changing.
     */
    function printingRequireCsrf(string $action, array $stateChanging): void {
        if (in_array($action, $stateChanging, true) && !Csrf::check()) {
            printingFail('Invalid request token', 403);
        }
    }

    /**
     * Fetch a printer the current user may manage (its owner, or an admin),
     * or stop with 403/permission. Returns the printer row.
     */
    function printingRequireOwnedPrinter($db, int $printerId, array $user): array {
        $stmt = $db->prepare('SELECT user_id FROM printers WHERE id = :id');
        $stmt->execute([':id' => $printerId]);
        $printer = $stmt->fetch();
        if (!$printer || ($printer['user_id'] != $user['id'] && !$user['is_admin'])) {
            printingFail('Permission denied', 403);
        }
        return $printer;
    }

    /**
     * Require that the current user may edit $modelId (its owner, an admin, or
     * canEdit()), or stop with 404/403. Returns the model row.
     */
    function printingRequireModelOwner($db, int $modelId, array $user): array {
        $stmt = $db->prepare('SELECT * FROM models WHERE id = :id');
        $stmt->execute([':id' => $modelId]);
        $model = $stmt->fetch();
        if (!$model) {
            printingFail('Model not found', 404);
        }
        $ownerId = $model['user_id'] ?? $model['uploaded_by'] ?? null;
        if ($ownerId && $ownerId != $user['id'] && !$user['is_admin'] && !canEdit()) {
            printingFail('Permission denied - not model owner', 403);
        }
        return $model;
    }
}
