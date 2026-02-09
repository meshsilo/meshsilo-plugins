<?php
/**
 * SSO Plugin - Boot File
 *
 * Loads all SSO libraries and registers routes, admin pages, and filters.
 */

// Load SSO libraries
require_once $pluginDir . '/lib/oidc.php';
require_once $pluginDir . '/lib/saml.php';
require_once $pluginDir . '/lib/ldap.php';
require_once $pluginDir . '/lib/SingleSignOut.php';
require_once $pluginDir . '/lib/OAuth2Provider.php';

// Register SSO callback/metadata routes
$plugin->addRoute('GET', '/oidc-callback', ['file' => $pluginDir . '/pages/oidc-callback.php'], 'oidc.callback');
$plugin->addRoute('POST', '/saml-acs', ['file' => $pluginDir . '/pages/saml-acs.php'], 'saml.acs');
$plugin->addRoute('GET', '/saml-metadata', ['file' => $pluginDir . '/pages/saml-metadata.php'], 'saml.metadata');

// Register admin SSO routes
$plugin->addRoute('GET', '/admin/sso', ['file' => $pluginDir . '/admin/sso.php'], 'admin.sso');
$plugin->addRoute('POST', '/admin/sso', ['file' => $pluginDir . '/admin/sso.php'], 'admin.sso.save');

// Legacy admin redirects (redirect old URLs to unified SSO page)
$plugin->addRoute('GET', '/admin/ldap', ['file' => $pluginDir . '/admin/sso.php', 'redirect_tab' => 'ldap'], 'admin.ldap');
$plugin->addRoute('GET', '/admin/scim', ['file' => $pluginDir . '/admin/sso.php', 'redirect_tab' => 'scim'], 'admin.scim');
$plugin->addRoute('GET', '/admin/oauth-clients', ['file' => $pluginDir . '/admin/sso.php', 'redirect_tab' => 'oauth'], 'admin.oauth-clients');

// Register admin sidebar item
$plugin->addAdminMenuItem('Users & Auth', 'Single Sign-On', 'sso', 'admin.sso');

// Filter: Add SSO buttons to login page
$plugin->addFilter('login_buttons', function($buttons) {
    // OIDC button
    if (function_exists('isOIDCEnabled') && isOIDCEnabled()) {
        $buttons[] = [
            'url' => getOIDCAuthUrl(),
            'text' => getSetting('oidc_button_text', 'Sign in with SSO'),
            'class' => 'btn-oidc',
        ];
    }
    // SAML button
    if (function_exists('isSAMLEnabled') && isSAMLEnabled()) {
        $buttons[] = [
            'url' => getSAMLAuthUrl(),
            'text' => getSetting('saml_button_text', 'Sign in with SAML'),
            'class' => 'btn-saml',
        ];
    }
    return $buttons;
});

// Filter: Handle OIDC single logout redirect
$plugin->addFilter('logout_redirect', function($redirectUrl, $userId, $preLogoutData) {
    if (!function_exists('isOIDCEnabled') || !isOIDCEnabled()) {
        return $redirectUrl;
    }

    $wasOIDCUser = !empty($preLogoutData['user']['oidc_id']) || !empty($preLogoutData['oidc_id_token']);
    $oidcSingleLogout = getSetting('oidc_single_logout', '1') === '1';

    if ($wasOIDCUser && $oidcSingleLogout) {
        $idToken = $preLogoutData['oidc_id_token'] ?? null;
        $logoutUrl = getOIDCLogoutUrl($idToken);
        if ($logoutUrl) {
            return $logoutUrl;
        }
    }

    return $redirectUrl;
});

// Filter: Register SSO callback routes as public (no auth required)
$plugin->addFilter('public_routes', function($routes) {
    $routes[] = '/oidc-callback';
    $routes[] = '/saml-acs';
    $routes[] = '/saml-metadata';
    return $routes;
});

// Filter: Allow SSO callbacks during maintenance mode
$plugin->addFilter('maintenance_bypass_routes', function($routes) {
    $routes[] = '/oidc-callback';
    $routes[] = '/saml-acs';
    return $routes;
});

// Filter: Register SSO as an available feature
$plugin->addFilter('available_features', function($features) {
    $features['sso'] = [
        'name' => 'Single Sign-On',
        'description' => 'External authentication via OIDC, SAML, LDAP/AD, and SCIM user provisioning',
        'icon' => 'unlock',
        'category' => 'Authentication',
        'default' => true,
    ];
    return $features;
});
