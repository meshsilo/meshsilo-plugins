<?php
/**
 * OIDC callback handler
 * Handles the authorization code flow callback from the OIDC provider
 */

// Start session before anything else
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = null;
$errorCode = null;

// Check for error from provider
if (isset($_GET['error'])) {
    $errorCode = $_GET['error'];
    $errorDescription = $_GET['error_description'] ?? '';

    // Map common error codes to user-friendly messages
    $errorMessages = [
        'access_denied' => 'Access was denied. You may have cancelled the login or do not have permission.',
        'invalid_request' => 'The authentication request was invalid.',
        'unauthorized_client' => 'This application is not authorized to use the identity provider.',
        'unsupported_response_type' => 'The identity provider does not support this authentication method.',
        'invalid_scope' => 'The requested permissions are not valid.',
        'server_error' => 'The identity provider encountered an error. Please try again.',
        'temporarily_unavailable' => 'The identity provider is temporarily unavailable. Please try again later.',
        'login_required' => 'You need to log in to the identity provider.',
        'consent_required' => 'You need to grant consent to this application.',
        'interaction_required' => 'Additional interaction with the identity provider is required.'
    ];

    $error = $errorMessages[$errorCode] ?? ('Authentication failed: ' . ($errorDescription ?: $errorCode));

    logSecurityWarning('OIDC error from provider', [
        'error' => $errorCode,
        'description' => $errorDescription,
        'state' => $_GET['state'] ?? 'none'
    ]);
}

// Verify state parameter (CSRF protection)
if (!$error) {
    $receivedState = $_GET['state'] ?? null;
    $expectedState = $_SESSION['oidc_state'] ?? null;

    if (!$receivedState || !$expectedState || $receivedState !== $expectedState) {
        $error = 'Security validation failed. Please try logging in again.';
        logSecurityWarning('OIDC state mismatch - possible CSRF attempt', [
            'received' => $receivedState ? substr($receivedState, 0, 8) . '...' : 'none',
            'expected' => $expectedState ? substr($expectedState, 0, 8) . '...' : 'none',
            'session_id' => session_id()
        ]);
    }
}

// Check for authorization code
if (!$error && !isset($_GET['code'])) {
    $error = 'No authorization code received from the identity provider.';
    logSecurityWarning('OIDC missing authorization code');
}

// Exchange code for tokens
$tokens = null;
if (!$error) {
    $tokens = exchangeCodeForTokens($_GET['code']);

    if (!$tokens) {
        $error = 'Failed to complete authentication. The identity provider may be temporarily unavailable.';
    } elseif (!isset($tokens['access_token'])) {
        $error = 'Invalid response from identity provider. Please try again.';
        logError('OIDC tokens missing access_token', ['tokens_keys' => array_keys($tokens)]);
    }
}

// Get user info
$userInfo = null;
if (!$error) {
    // Try to get info from ID token first
    if (isset($tokens['id_token'])) {
        $idTokenData = decodeIdToken($tokens['id_token']);
        if ($idTokenData) {
            $userInfo = $idTokenData;
        }
    }

    // Supplement with userinfo endpoint
    $userInfoEndpoint = getOIDCUserInfo($tokens['access_token']);
    if ($userInfoEndpoint) {
        $userInfo = array_merge($userInfo ?? [], $userInfoEndpoint);
    }

    if (!$userInfo) {
        $error = 'Failed to retrieve user information from identity provider.';
    } elseif (!isset($userInfo['sub'])) {
        $error = 'Invalid user information received. Missing required identifier.';
        logError('OIDC userinfo missing sub claim', ['userinfo_keys' => array_keys($userInfo)]);
    }
}

// Find or create user
$user = null;
if (!$error) {
    $user = findOrCreateOIDCUser($userInfo, $tokens['id_token'] ?? null);

    if (!$user) {
        // Check if auto-registration is disabled
        if (!isOIDCAutoRegisterEnabled()) {
            $error = 'Your account was not found. Please contact an administrator to create your account.';
        } else {
            $error = 'Failed to create or find your user account. Please contact an administrator.';
        }
    }
}

// Store ID token for potential logout
if (!$error && isset($tokens['id_token'])) {
    $_SESSION['oidc_id_token'] = $tokens['id_token'];
}

// Clean up OIDC session data
unset($_SESSION['oidc_state']);
unset($_SESSION['oidc_nonce']);
unset($_SESSION['oidc_code_verifier']);

// Get return URL if set
$returnUrl = $_SESSION['oidc_return_url'] ?? null;
unset($_SESSION['oidc_return_url']);

// If we have an error, redirect to login with error
if ($error) {
    $_SESSION['oidc_error'] = $error;
    header('Location: ' . route('login'));
    exit;
}

// Log in the user
$_SESSION['user_id'] = $user['id'];
$_SESSION['user'] = [
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'is_admin' => $user['is_admin'] ?? 0
];

// Log the successful login
logAuthEvent('login', $user['username'], true, [
    'user_id' => $user['id'],
    'method' => 'oidc',
    'provider' => getSetting('oidc_provider_url')
]);

// Log activity if enabled
if (function_exists('logActivity')) {
    logActivity('login', 'user', $user['id'], $user['username'], ['method' => 'oidc']);
}

// Redirect to return URL or home
$redirectUrl = $returnUrl ?: 'index.php';

// Validate redirect URL to prevent open redirect
if ($returnUrl) {
    $parsed = parse_url($returnUrl);
    // Only allow relative URLs or URLs to the same host
    if (isset($parsed['host'])) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($parsed['host'] !== $currentHost) {
            $redirectUrl = 'index.php';
        }
    }
}

header('Location: ' . $redirectUrl);
exit;
