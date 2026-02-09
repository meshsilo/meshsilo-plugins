<?php
/**
 * SAML Assertion Consumer Service (ACS)
 *
 * Receives and processes SAML responses from the Identity Provider
 */

// Check if SAML is enabled
if (!isSAMLEnabled()) {
    header('Location: ' . route('login'));
    exit;
}

// Process POST response from IdP
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['SAMLResponse'])) {
    logError('SAML ACS: No SAMLResponse in POST');
    $_SESSION['error'] = 'Invalid SAML response';
    header('Location: ' . route('login'));
    exit;
}

// Verify RelayState if set
if (isset($_SESSION['saml_relay_state']) && isset($_POST['RelayState'])) {
    if ($_SESSION['saml_relay_state'] !== $_POST['RelayState']) {
        logError('SAML ACS: RelayState mismatch');
        $_SESSION['error'] = 'SAML security validation failed';
        header('Location: ' . route('login'));
        exit;
    }
}

// Process the SAML response
$result = processSAMLResponse($_POST['SAMLResponse']);

if (isset($result['error'])) {
    logError('SAML authentication failed', ['error' => $result['error']]);
    $_SESSION['error'] = 'SAML authentication failed: ' . $result['error'];
    header('Location: ' . route('login'));
    exit;
}

// Find or create user
$userResult = findOrCreateSAMLUser($result);

if (isset($userResult['error'])) {
    logError('SAML user creation failed', ['error' => $userResult['error']]);
    $_SESSION['error'] = $userResult['error'];
    header('Location: ' . route('login'));
    exit;
}

$user = $userResult['user'];

// Check if user is active
if (isset($user['is_active']) && !$user['is_active']) {
    $_SESSION['error'] = 'Your account has been disabled';
    header('Location: ' . route('login'));
    exit;
}

// Log the user in
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['is_admin'] = $user['is_admin'] ?? false;
$_SESSION['auth_method'] = 'saml';

// Store SAML session index for SLO
if (isset($result['session_index'])) {
    $_SESSION['saml_session_index'] = $result['session_index'];
}

// Log activity
logActivity($user['id'], 'saml_login', 'user', $user['id']);

// Trigger event
if (class_exists('Events')) {
    Events::emit('user.login', [
        'user_id' => $user['id'],
        'username' => $user['username'],
        'method' => 'saml',
        'is_new' => $userResult['is_new'] ?? false
    ]);
}

// Redirect to return URL or homepage
$returnUrl = $_SESSION['saml_return_url'] ?? null;
unset($_SESSION['saml_return_url']);

if ($returnUrl && strpos($returnUrl, '/') === 0) {
    // Relative URL - safe to redirect
    header('Location: ' . $returnUrl);
} else {
    header('Location: ' . route('home'));
}
exit;
