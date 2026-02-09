<?php
/**
 * OIDC (OpenID Connect) authentication helper
 * Supports multiple providers with configurable options
 */

/**
 * Check if OIDC is enabled and properly configured
 */
function isOIDCEnabled() {
    return getSetting('oidc_enabled', '0') === '1'
        && !empty(getSetting('oidc_provider_url'))
        && !empty(getSetting('oidc_client_id'))
        && !empty(getSetting('oidc_client_secret'));
}

/**
 * Get default OIDC scopes
 */
function getDefaultOIDCScopes() {
    return 'openid email profile';
}

/**
 * Get configured OIDC scopes
 */
function getOIDCScopes() {
    $scopes = getSetting('oidc_scopes', '');
    return !empty($scopes) ? $scopes : getDefaultOIDCScopes();
}

/**
 * Get the username claim to use from OIDC response
 */
function getOIDCUsernameClaim() {
    return getSetting('oidc_username_claim', 'preferred_username');
}

/**
 * Check if PKCE is enabled
 */
function isPKCEEnabled() {
    return getSetting('oidc_pkce_enabled', '1') === '1';
}

/**
 * Check if auto-registration is enabled for new OIDC users
 */
function isOIDCAutoRegisterEnabled() {
    return getSetting('oidc_auto_register', '1') === '1';
}

/**
 * Get OIDC configuration from provider's discovery endpoint
 */
function getOIDCConfig() {
    $providerUrl = rtrim(getSetting('oidc_provider_url', ''), '/');

    if (empty($providerUrl)) {
        return null;
    }

    $discoveryUrl = $providerUrl . '/.well-known/openid-configuration';

    $cacheKey = 'oidc_config_cache';
    $cached = getSetting($cacheKey);

    if ($cached) {
        $data = json_decode($cached, true);
        if ($data && isset($data['expires']) && $data['expires'] > time()) {
            return $data['config'];
        }
    }

    // Use cURL for better compatibility
    $ch = curl_init($discoveryUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logError('OIDC discovery failed', [
            'url' => $discoveryUrl,
            'http_code' => $httpCode,
            'error' => $error
        ]);
        return null;
    }

    $config = json_decode($response, true);

    if (!$config || !isset($config['authorization_endpoint'])) {
        logError('OIDC invalid config response', ['response' => substr($response, 0, 500)]);
        return null;
    }

    // Cache for 1 hour
    setSetting($cacheKey, json_encode([
        'config' => $config,
        'expires' => time() + 3600
    ]));

    return $config;
}

/**
 * Clear OIDC config cache
 */
function clearOIDCConfigCache() {
    setSetting('oidc_config_cache', '');
}

/**
 * Generate PKCE code verifier and challenge
 */
function generatePKCE() {
    // Generate a random code verifier (43-128 characters)
    $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    $verifier = substr($verifier, 0, 128);

    // Generate code challenge using S256 method
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [
        'verifier' => $verifier,
        'challenge' => $challenge,
        'method' => 'S256'
    ];
}

/**
 * Generate the authorization URL for OIDC login
 */
function getOIDCAuthUrl($returnUrl = null) {
    $config = getOIDCConfig();

    if (!$config) {
        logError('OIDC auth URL failed: no config available');
        return null;
    }

    // Generate state and nonce for security
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));

    // Store in session for verification
    $_SESSION['oidc_state'] = $state;
    $_SESSION['oidc_nonce'] = $nonce;

    if ($returnUrl) {
        $_SESSION['oidc_return_url'] = $returnUrl;
    }

    $clientId = getSetting('oidc_client_id');
    $redirectUri = getOIDCRedirectUri();
    $scopes = getOIDCScopes();

    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => $scopes,
        'state' => $state,
        'nonce' => $nonce
    ];

    // Add PKCE if enabled
    if (isPKCEEnabled()) {
        $pkce = generatePKCE();
        $_SESSION['oidc_code_verifier'] = $pkce['verifier'];
        $params['code_challenge'] = $pkce['challenge'];
        $params['code_challenge_method'] = $pkce['method'];
    }

    // Add optional parameters
    $prompt = getSetting('oidc_prompt', '');
    if (!empty($prompt)) {
        $params['prompt'] = $prompt;
    }

    // Add acr_values if configured (for MFA requirements)
    $acrValues = getSetting('oidc_acr_values', '');
    if (!empty($acrValues)) {
        $params['acr_values'] = $acrValues;
    }

    return $config['authorization_endpoint'] . '?' . http_build_query($params);
}

/**
 * Get the OIDC redirect URI
 */
function getOIDCRedirectUri() {
    // Check if a specific redirect URI is configured
    $configuredUri = getSetting('oidc_redirect_uri', '');
    if (!empty($configuredUri)) {
        return $configuredUri;
    }

    // Build from site URL or current request
    $siteUrl = getSetting('site_url', '');

    if (!empty($siteUrl) && getSetting('force_site_url', '0') === '1') {
        return rtrim($siteUrl, '/') . '/oidc-callback.php';
    }

    // Auto-detect from request
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

    // Check for proxy headers
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Check for proxy host
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    }

    // Get base path
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath === '/' || $basePath === '\\') {
        $basePath = '';
    }

    return $protocol . '://' . $host . $basePath . '/oidc-callback.php';
}

/**
 * Exchange authorization code for tokens
 */
function exchangeCodeForTokens($code) {
    $config = getOIDCConfig();

    if (!$config) {
        return null;
    }

    $clientId = getSetting('oidc_client_id');
    $clientSecret = getSetting('oidc_client_secret');
    $redirectUri = getOIDCRedirectUri();

    $postData = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ];

    // Add PKCE code verifier if used
    if (isset($_SESSION['oidc_code_verifier'])) {
        $postData['code_verifier'] = $_SESSION['oidc_code_verifier'];
    }

    $ch = curl_init($config['token_endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logError('OIDC token exchange failed', [
            'endpoint' => $config['token_endpoint'],
            'error' => $error
        ]);
        return null;
    }

    $tokens = json_decode($response, true);

    if (!$tokens || isset($tokens['error'])) {
        logError('OIDC token exchange error', [
            'http_code' => $httpCode,
            'error' => $tokens['error'] ?? 'unknown',
            'error_description' => $tokens['error_description'] ?? ''
        ]);
        return null;
    }

    return $tokens;
}

/**
 * Get user info from OIDC provider
 */
function getOIDCUserInfo($accessToken) {
    $config = getOIDCConfig();

    if (!$config || !isset($config['userinfo_endpoint'])) {
        return null;
    }

    $ch = curl_init($config['userinfo_endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: Silo/1.0'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logError('OIDC userinfo failed', [
            'endpoint' => $config['userinfo_endpoint'],
            'http_code' => $httpCode,
            'error' => $error
        ]);
        return null;
    }

    return json_decode($response, true);
}

/**
 * Decode and validate ID token (basic validation)
 */
function decodeIdToken($idToken) {
    $parts = explode('.', $idToken);

    if (count($parts) !== 3) {
        logWarning('OIDC invalid ID token format');
        return null;
    }

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!$payload) {
        logWarning('OIDC failed to decode ID token payload');
        return null;
    }

    // Verify nonce if present
    if (isset($_SESSION['oidc_nonce']) && isset($payload['nonce'])) {
        if ($payload['nonce'] !== $_SESSION['oidc_nonce']) {
            logWarning('OIDC nonce mismatch', [
                'expected' => substr($_SESSION['oidc_nonce'], 0, 8) . '...',
                'received' => substr($payload['nonce'], 0, 8) . '...'
            ]);
            return null;
        }
    }

    // Verify expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        logWarning('OIDC token expired', [
            'exp' => $payload['exp'],
            'now' => time()
        ]);
        return null;
    }

    // Verify issuer if we have the config
    $config = getOIDCConfig();
    if ($config && isset($config['issuer']) && isset($payload['iss'])) {
        if ($payload['iss'] !== $config['issuer']) {
            logWarning('OIDC issuer mismatch', [
                'expected' => $config['issuer'],
                'received' => $payload['iss']
            ]);
            return null;
        }
    }

    // Verify audience
    $clientId = getSetting('oidc_client_id');
    if (isset($payload['aud'])) {
        $aud = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array($clientId, $aud)) {
            logWarning('OIDC audience mismatch', [
                'expected' => $clientId,
                'received' => $payload['aud']
            ]);
            return null;
        }
    }

    return $payload;
}

/**
 * Extract username from OIDC user info based on configured claim
 */
function extractOIDCUsername($userInfo) {
    $claim = getOIDCUsernameClaim();

    // Try configured claim first
    if (!empty($userInfo[$claim])) {
        return $userInfo[$claim];
    }

    // Fallback chain
    $fallbacks = ['preferred_username', 'name', 'email', 'sub'];
    foreach ($fallbacks as $fallback) {
        if (!empty($userInfo[$fallback])) {
            $value = $userInfo[$fallback];
            // If using email, extract the local part
            if ($fallback === 'email' && strpos($value, '@') !== false) {
                $value = explode('@', $value)[0];
            }
            return $value;
        }
    }

    return 'oidc_user';
}

/**
 * Map OIDC groups/roles to Silo groups
 */
function mapOIDCGroupsToSilo($userInfo, $userId) {
    $groupClaimName = getSetting('oidc_groups_claim', 'groups');
    $groupMappingJson = getSetting('oidc_group_mapping', '');

    if (empty($groupMappingJson)) {
        return; // No mapping configured
    }

    $groupMapping = json_decode($groupMappingJson, true);
    if (!$groupMapping) {
        return;
    }

    // Get groups from user info
    $oidcGroups = $userInfo[$groupClaimName] ?? [];
    if (!is_array($oidcGroups)) {
        $oidcGroups = [$oidcGroups];
    }

    $db = getDB();

    // Get current user groups
    $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $currentGroups = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $currentGroups[] = (int)$row['group_id'];
    }

    // Determine which Silo groups the user should be in based on mapping
    $targetGroups = [];
    foreach ($oidcGroups as $oidcGroup) {
        if (isset($groupMapping[$oidcGroup])) {
            $siloGroupName = $groupMapping[$oidcGroup];
            // Look up Silo group ID
            $stmt = $db->prepare('SELECT id FROM groups WHERE name = :name');
            $stmt->execute([':name' => $siloGroupName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $targetGroups[] = (int)$row['id'];
            }
        }
    }

    // Only modify groups if OIDC should manage them
    if (getSetting('oidc_manage_groups', '0') === '1') {
        // Remove groups not in target
        foreach ($currentGroups as $groupId) {
            if (!in_array($groupId, $targetGroups)) {
                $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :user_id AND group_id = :group_id');
                $stmt->execute([':user_id' => $userId, ':group_id' => $groupId]);
            }
        }

        // Add missing groups
        foreach ($targetGroups as $groupId) {
            if (!in_array($groupId, $currentGroups)) {
                $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                $stmt->execute([':user_id' => $userId, ':group_id' => $groupId]);
            }
        }
    } else {
        // Only add groups, don't remove
        foreach ($targetGroups as $groupId) {
            if (!in_array($groupId, $currentGroups)) {
                try {
                    $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
                    $stmt->execute([':user_id' => $userId, ':group_id' => $groupId]);
                } catch (Exception $e) {
                    // Ignore duplicate key errors
                }
            }
        }
    }
}

/**
 * Find or create user from OIDC data
 */
function findOrCreateOIDCUser($userInfo, $idToken = null) {
    $db = getDB();

    // Get the subject identifier (unique user ID from provider)
    $sub = $userInfo['sub'] ?? null;

    if (!$sub) {
        logError('OIDC missing sub claim', ['userInfo' => array_keys($userInfo)]);
        return null;
    }

    // Get email and name
    $email = $userInfo['email'] ?? null;
    $name = extractOIDCUsername($userInfo);

    // First, check if we have a user with this OIDC ID
    $stmt = $db->prepare('SELECT * FROM users WHERE oidc_id = :oidc_id');
    $stmt->execute([':oidc_id' => $sub]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Update email if changed and email is verified
        $emailVerified = $userInfo['email_verified'] ?? true;
        if ($email && $emailVerified && $email !== $user['email']) {
            $stmt = $db->prepare('UPDATE users SET email = :email WHERE id = :id');
            $stmt->execute([':email' => $email, ':id' => $user['id']]);
            $user['email'] = $email;
        }

        // Map groups if configured
        mapOIDCGroupsToSilo($userInfo, $user['id']);

        logInfo('OIDC user logged in', ['user_id' => $user['id'], 'username' => $user['username']]);
        return $user;
    }

    // Check if user exists with same email
    if ($email) {
        $stmt = $db->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Check if linking existing accounts is allowed
            if (getSetting('oidc_link_existing', '1') !== '1') {
                logWarning('OIDC account linking disabled', ['email' => $email]);
                return null;
            }

            // Link existing account to OIDC
            $stmt = $db->prepare('UPDATE users SET oidc_id = :oidc_id WHERE id = :id');
            $stmt->execute([':oidc_id' => $sub, ':id' => $user['id']]);
            $user['oidc_id'] = $sub;

            // Map groups if configured
            mapOIDCGroupsToSilo($userInfo, $user['id']);

            logInfo('OIDC linked to existing user', ['user_id' => $user['id'], 'email' => $email]);
            return $user;
        }
    }

    // Check if auto-registration is enabled
    if (!isOIDCAutoRegisterEnabled()) {
        logWarning('OIDC auto-registration disabled, user not found', ['sub' => $sub, 'email' => $email]);
        return null;
    }

    // Create new user
    // Make username unique
    $baseUsername = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    if (empty($baseUsername)) {
        $baseUsername = 'user';
    }
    $username = $baseUsername;
    $counter = 1;

    while (true) {
        $stmt = $db->prepare('SELECT id FROM users WHERE username = :username');
        $stmt->execute([':username' => $username]);

        if (!$stmt->fetch()) {
            break;
        }

        $username = $baseUsername . $counter;
        $counter++;

        if ($counter > 1000) {
            // Prevent infinite loop
            $username = $baseUsername . '_' . bin2hex(random_bytes(4));
            break;
        }
    }

    // Generate a random password (user can't log in with it, OIDC only)
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $stmt = $db->prepare('INSERT INTO users (username, email, password, oidc_id, is_admin) VALUES (:username, :email, :password, :oidc_id, 0)');
    $stmt->execute([
        ':username' => $username,
        ':email' => $email ?? $username . '@oidc.local',
        ':password' => $randomPassword,
        ':oidc_id' => $sub
    ]);

    $userId = $db->lastInsertId();

    // Add to default group if configured
    $defaultGroupName = getSetting('oidc_default_group', 'Users');
    if (!empty($defaultGroupName)) {
        $stmt = $db->prepare('SELECT id FROM groups WHERE name = :name');
        $stmt->execute([':name' => $defaultGroupName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stmt = $db->prepare('INSERT INTO user_groups (user_id, group_id) VALUES (:user_id, :group_id)');
            $stmt->execute([':user_id' => $userId, ':group_id' => $row['id']]);
        }
    }

    // Map groups from OIDC
    mapOIDCGroupsToSilo($userInfo, $userId);

    logInfo('OIDC created new user', ['user_id' => $userId, 'username' => $username, 'email' => $email]);

    // Fetch and return the new user
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get OIDC logout URL (if supported by provider)
 */
function getOIDCLogoutUrl($idToken = null) {
    $config = getOIDCConfig();

    if (!$config || !isset($config['end_session_endpoint'])) {
        return null;
    }

    $params = [];

    // Add post-logout redirect URI
    $postLogoutUri = getSetting('oidc_post_logout_uri', '');
    if (empty($postLogoutUri)) {
        $siteUrl = getSetting('site_url', '');
        if (!empty($siteUrl)) {
            $postLogoutUri = rtrim($siteUrl, '/') . '/login.php';
        }
    }

    if (!empty($postLogoutUri)) {
        $params['post_logout_redirect_uri'] = $postLogoutUri;
    }

    // Add ID token hint if available
    if ($idToken) {
        $params['id_token_hint'] = $idToken;
    }

    // Add client ID
    $params['client_id'] = getSetting('oidc_client_id');

    $url = $config['end_session_endpoint'];
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

/**
 * Test OIDC connection
 */
function testOIDCConnection() {
    $providerUrl = getSetting('oidc_provider_url');

    if (empty($providerUrl)) {
        return ['success' => false, 'message' => 'Provider URL not configured'];
    }

    // Clear cache to force fresh fetch
    clearOIDCConfigCache();

    $config = getOIDCConfig();

    if (!$config) {
        return ['success' => false, 'message' => 'Failed to fetch OIDC configuration from provider. Check the provider URL and ensure it supports OIDC discovery.'];
    }

    $required = ['authorization_endpoint', 'token_endpoint', 'issuer'];
    $missing = [];

    foreach ($required as $key) {
        if (!isset($config[$key])) {
            $missing[] = $key;
        }
    }

    if (!empty($missing)) {
        return ['success' => false, 'message' => 'Provider config missing required fields: ' . implode(', ', $missing)];
    }

    // Check if PKCE is supported
    $pkceSupported = isset($config['code_challenge_methods_supported']) &&
                     in_array('S256', $config['code_challenge_methods_supported']);

    return [
        'success' => true,
        'message' => 'Connection successful',
        'issuer' => $config['issuer'],
        'pkce_supported' => $pkceSupported,
        'endpoints' => [
            'authorization' => $config['authorization_endpoint'],
            'token' => $config['token_endpoint'],
            'userinfo' => $config['userinfo_endpoint'] ?? 'Not available',
            'logout' => $config['end_session_endpoint'] ?? 'Not available'
        ],
        'scopes_supported' => $config['scopes_supported'] ?? [],
        'claims_supported' => $config['claims_supported'] ?? []
    ];
}

/**
 * Get list of common OIDC providers with their discovery URLs
 */
function getOIDCProviderPresets() {
    return [
        'google' => [
            'name' => 'Google',
            'url' => 'https://accounts.google.com',
            'scopes' => 'openid email profile'
        ],
        'microsoft' => [
            'name' => 'Microsoft / Azure AD',
            'url' => 'https://login.microsoftonline.com/{tenant}/v2.0',
            'scopes' => 'openid email profile',
            'note' => 'Replace {tenant} with your Azure AD tenant ID or "common"'
        ],
        'okta' => [
            'name' => 'Okta',
            'url' => 'https://{domain}.okta.com',
            'scopes' => 'openid email profile groups',
            'note' => 'Replace {domain} with your Okta domain'
        ],
        'auth0' => [
            'name' => 'Auth0',
            'url' => 'https://{domain}.auth0.com',
            'scopes' => 'openid email profile',
            'note' => 'Replace {domain} with your Auth0 domain'
        ],
        'keycloak' => [
            'name' => 'Keycloak',
            'url' => 'https://{host}/realms/{realm}',
            'scopes' => 'openid email profile',
            'note' => 'Replace {host} and {realm} with your Keycloak server and realm'
        ],
        'authentik' => [
            'name' => 'Authentik',
            'url' => 'https://{host}/application/o/{slug}',
            'scopes' => 'openid email profile',
            'note' => 'Replace {host} and {slug} with your Authentik server and application slug'
        ],
        'github' => [
            'name' => 'GitHub (OAuth, not OIDC)',
            'url' => '',
            'note' => 'GitHub uses OAuth 2.0, not OIDC. Use a wrapper like Dex for OIDC.'
        ]
    ];
}
