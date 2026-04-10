<?php
/**
 * OIDC login initiator
 * Generates the auth URL with a fresh state and redirects to the provider.
 * This prevents stale-state CSRF errors when users have cached login pages.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('isOIDCEnabled') || !isOIDCEnabled()) {
    http_response_code(404);
    exit('OIDC is not enabled');
}

$returnUrl = $_GET['return'] ?? null;
$authUrl = getOIDCAuthUrl($returnUrl);

if (!$authUrl) {
    http_response_code(500);
    exit('Failed to generate OIDC auth URL. Check server logs.');
}

header('Location: ' . $authUrl);
exit;
