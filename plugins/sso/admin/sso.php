<?php
/**
 * Single Sign-On (SSO) Administration
 *
 * Unified page for managing all SSO methods: OIDC, SAML, LDAP/AD, SCIM, OAuth2 Clients
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/../../../includes/features.php';

// Require SSO feature to be enabled
requireFeature('sso');

// Require admin permission
if (!isLoggedIn() || !isAdmin()) {
    $_SESSION['error'] = 'You do not have permission to manage SSO settings.';
    header('Location: ' . route('admin.health'));
    exit;
}

$pageTitle = 'Single Sign-On';
$activePage = 'admin';
$adminPage = 'sso';

$db = getDB();
$message = '';
$error = '';
$newClientSecret = null;

// Get active tab
$activeTab = $_GET['tab'] ?? 'oidc';
$validTabs = ['oidc', 'saml', 'ldap', 'scim', 'oauth'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'oidc';
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tab = $_POST['tab'] ?? 'oidc';
    $activeTab = $tab;

    switch ($action) {
        // OIDC Settings
        case 'save_oidc':
            setSetting('oidc_enabled', isset($_POST['oidc_enabled']) ? '1' : '0');
            setSetting('oidc_provider_name', trim($_POST['provider_name'] ?? 'SSO'));
            setSetting('oidc_provider_url', trim($_POST['provider_url'] ?? ''));
            setSetting('oidc_client_id', trim($_POST['client_id'] ?? ''));
            if (!empty($_POST['client_secret'])) {
                setSetting('oidc_client_secret', $_POST['client_secret']);
            }
            setSetting('oidc_auth_url', trim($_POST['auth_url'] ?? ''));
            setSetting('oidc_token_url', trim($_POST['token_url'] ?? ''));
            setSetting('oidc_userinfo_url', trim($_POST['userinfo_url'] ?? ''));
            setSetting('oidc_scopes', trim($_POST['scopes'] ?? 'openid profile email'));
            setSetting('oidc_token_auth_method', $_POST['token_auth_method'] ?? 'client_secret_basic');
            setSetting('oidc_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('oidc_default_group', $_POST['default_group'] ?? '');
            $message = 'OIDC settings saved.';
            logActivity('oidc_settings_updated', 'system', null, 'Admin updated OIDC settings');
            break;

        // SAML Settings
        case 'save_saml':
            setSetting('saml_enabled', isset($_POST['saml_enabled']) ? '1' : '0');
            setSetting('saml_idp_entity_id', trim($_POST['idp_entity_id'] ?? ''));
            setSetting('saml_idp_sso_url', trim($_POST['idp_sso_url'] ?? ''));
            setSetting('saml_idp_slo_url', trim($_POST['idp_slo_url'] ?? ''));
            setSetting('saml_idp_x509_cert', trim($_POST['idp_x509_cert'] ?? ''));
            setSetting('saml_sp_entity_id', trim($_POST['sp_entity_id'] ?? ''));
            setSetting('saml_name_id_format', $_POST['name_id_format'] ?? 'emailAddress');
            setSetting('saml_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('saml_default_group', $_POST['default_group'] ?? '');
            $message = 'SAML settings saved.';
            logActivity('saml_settings_updated', 'system', null, 'Admin updated SAML settings');
            break;

        // LDAP Settings
        case 'save_ldap':
            setSetting('ldap_enabled', isset($_POST['ldap_enabled']) ? '1' : '0');
            setSetting('ldap_host', trim($_POST['ldap_host'] ?? ''));
            setSetting('ldap_port', (int)($_POST['ldap_port'] ?? 389));
            setSetting('ldap_use_ssl', isset($_POST['ldap_ssl']) ? '1' : '0');
            setSetting('ldap_use_tls', isset($_POST['ldap_tls']) ? '1' : '0');
            setSetting('ldap_base_dn', trim($_POST['ldap_base_dn'] ?? ''));
            setSetting('ldap_bind_dn', trim($_POST['ldap_bind_dn'] ?? ''));
            if (!empty($_POST['ldap_bind_password'])) {
                setSetting('ldap_bind_password', $_POST['ldap_bind_password']);
            }
            setSetting('ldap_user_filter', trim($_POST['ldap_user_filter'] ?? ''));
            setSetting('ldap_username_attr', trim($_POST['ldap_username_attr'] ?? 'sAMAccountName'));
            setSetting('ldap_email_attr', trim($_POST['ldap_email_attr'] ?? 'mail'));
            setSetting('ldap_name_attr', trim($_POST['ldap_name_attr'] ?? 'displayName'));
            setSetting('ldap_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('ldap_default_group', $_POST['default_group'] ?? '');
            $message = 'LDAP settings saved.';
            logActivity('ldap_settings_updated', 'system', null, 'Admin updated LDAP settings');
            break;

        case 'test_ldap':
            if (function_exists('testLDAPConnection')) {
                $testResult = testLDAPConnection();
                if ($testResult['success']) {
                    $message = 'LDAP connection successful! ' . ($testResult['user_count'] ?? 0) . ' users found.';
                } else {
                    $error = 'LDAP connection failed: ' . $testResult['error'];
                }
            } else {
                $error = 'LDAP testing function not available.';
            }
            break;

        // SCIM Settings
        case 'save_scim':
            setSetting('scim_enabled', isset($_POST['scim_enabled']) ? '1' : '0');
            setSetting('scim_auto_create_users', isset($_POST['auto_create']) ? '1' : '0');
            setSetting('scim_auto_update_users', isset($_POST['auto_update']) ? '1' : '0');
            setSetting('scim_auto_deactivate', isset($_POST['auto_deactivate']) ? '1' : '0');
            setSetting('scim_default_group', $_POST['default_group'] ?? '');
            $message = 'SCIM settings saved.';
            logActivity('scim_settings_updated', 'system', null, 'Admin updated SCIM settings');
            break;

        case 'generate_scim_token':
            $newToken = bin2hex(random_bytes(32));
            setSetting('scim_bearer_token', password_hash($newToken, PASSWORD_DEFAULT));
            setSetting('scim_bearer_token_preview', substr($newToken, 0, 8) . '...');
            $message = "New SCIM Bearer Token: <code>$newToken</code><br><strong>Copy this token now - it won't be shown again!</strong>";
            logActivity('scim_token_generated', 'system', null, 'Admin generated new SCIM token');
            break;

        // OAuth2 Client Management
        case 'create_oauth_client':
            if (class_exists('OAuth2Provider')) {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $redirectUris = array_filter(array_map('trim', explode("\n", $_POST['redirect_uris'] ?? '')));
                $isConfidential = isset($_POST['is_confidential']);

                if (empty($name) || empty($redirectUris)) {
                    $error = 'Name and at least one redirect URI are required.';
                } else {
                    $result = OAuth2Provider::createClient($name, $redirectUris, $description, $isConfidential, getCurrentUser()['id']);
                    $message = "Client created. Client ID: <code>{$result['client_id']}</code>";
                    $newClientSecret = $result['client_secret'];
                    logActivity('oauth_client_created', 'oauth_client', null, $name);
                }
            }
            break;

        case 'delete_oauth_client':
            if (class_exists('OAuth2Provider')) {
                $clientId = $_POST['client_id'] ?? '';
                if (OAuth2Provider::deleteClient($clientId)) {
                    $message = 'Client deleted successfully.';
                    logActivity('oauth_client_deleted', 'oauth_client', null, $clientId);
                } else {
                    $error = 'Failed to delete client.';
                }
            }
            break;
    }
}

// Get current settings
$oidcEnabled = getSetting('oidc_enabled', '0') === '1';
$oidcProviderName = getSetting('oidc_provider_name', 'SSO');
$oidcProviderUrl = getSetting('oidc_provider_url', '');
$oidcClientId = getSetting('oidc_client_id', '');
$oidcAuthUrl = getSetting('oidc_auth_url', '');
$oidcTokenUrl = getSetting('oidc_token_url', '');
$oidcUserinfoUrl = getSetting('oidc_userinfo_url', '');
$oidcScopes = getSetting('oidc_scopes', 'openid profile email');
$oidcTokenAuthMethod = getSetting('oidc_token_auth_method', 'client_secret_basic');
$oidcAutoCreate = getSetting('oidc_auto_create_users', '1') === '1';
$oidcDefaultGroup = getSetting('oidc_default_group', '');

$samlEnabled = getSetting('saml_enabled', '0') === '1';
$samlIdpEntityId = getSetting('saml_idp_entity_id', '');
$samlIdpSsoUrl = getSetting('saml_idp_sso_url', '');
$samlIdpSloUrl = getSetting('saml_idp_slo_url', '');
$samlIdpCert = getSetting('saml_idp_x509_cert', '');
$samlSpEntityId = getSetting('saml_sp_entity_id', url('/'));
$samlNameIdFormat = getSetting('saml_name_id_format', 'emailAddress');
$samlAutoCreate = getSetting('saml_auto_create_users', '1') === '1';
$samlDefaultGroup = getSetting('saml_default_group', '');

$ldapEnabled = getSetting('ldap_enabled', '0') === '1';
$ldapHost = getSetting('ldap_host', '');
$ldapPort = getSetting('ldap_port', '389');
$ldapSsl = getSetting('ldap_use_ssl', '0') === '1';
$ldapTls = getSetting('ldap_use_tls', '0') === '1';
$ldapBaseDn = getSetting('ldap_base_dn', '');
$ldapBindDn = getSetting('ldap_bind_dn', '');
$ldapUserFilter = getSetting('ldap_user_filter', '');
$ldapUsernameAttr = getSetting('ldap_username_attr', 'sAMAccountName');
$ldapEmailAttr = getSetting('ldap_email_attr', 'mail');
$ldapNameAttr = getSetting('ldap_name_attr', 'displayName');
$ldapAutoCreate = getSetting('ldap_auto_create_users', '1') === '1';
$ldapDefaultGroup = getSetting('ldap_default_group', '');

$scimEnabled = getSetting('scim_enabled', '0') === '1';
$scimAutoCreate = getSetting('scim_auto_create_users', '1') === '1';
$scimAutoUpdate = getSetting('scim_auto_update_users', '1') === '1';
$scimAutoDeactivate = getSetting('scim_auto_deactivate', '0') === '1';
$scimDefaultGroup = getSetting('scim_default_group', '');
$scimTokenPreview = getSetting('scim_bearer_token_preview', 'Not generated');

// Get available groups
$groups = [];
$result = $db->query('SELECT id, name FROM groups ORDER BY name');
while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
    $groups[] = $row;
}

// Get OAuth2 clients
$oauthClients = [];
if (class_exists('OAuth2Provider')) {
    $oauthClients = OAuth2Provider::getClients();
}

// Count SSO users (with fallback for missing columns)
$ssoUserCounts = ['oidc' => 0, 'saml' => 0, 'ldap' => 0];
try {
    $ssoUserCounts['oidc'] = (int)$db->querySingle("SELECT COUNT(*) FROM users WHERE oidc_sub IS NOT NULL") ?: 0;
} catch (Exception $e) {}
try {
    $ssoUserCounts['saml'] = (int)$db->querySingle("SELECT COUNT(*) FROM users WHERE saml_name_id IS NOT NULL") ?: 0;
} catch (Exception $e) {}
try {
    $ssoUserCounts['ldap'] = (int)$db->querySingle("SELECT COUNT(*) FROM users WHERE ldap_dn IS NOT NULL") ?: 0;
} catch (Exception $e) {}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="admin-layout">
<?php require_once __DIR__ . '/../../../includes/admin-sidebar.php'; ?>

<div class="admin-content">
    <div class="admin-header">
        <h1>Single Sign-On</h1>
        <p>Configure external authentication methods</p>
    </div>

<?php if ($message): ?>
<div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($newClientSecret): ?>
<div class="alert alert-warning">
    <strong>Client Secret:</strong> <code><?= htmlspecialchars($newClientSecret) ?></code><br>
    <small>Copy this secret now - it won't be shown again!</small>
</div>
<?php endif; ?>

<!-- SSO Overview Cards -->
<div class="sso-overview">
    <div class="sso-card <?= $oidcEnabled ? 'enabled' : 'disabled' ?>">
        <div class="sso-card-header">
            <span class="sso-icon">🔐</span>
            <span class="sso-status"><?= $oidcEnabled ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <h3>OIDC</h3>
        <p><?= $ssoUserCounts['oidc'] ?> users</p>
    </div>
    <div class="sso-card <?= $samlEnabled ? 'enabled' : 'disabled' ?>">
        <div class="sso-card-header">
            <span class="sso-icon">🏢</span>
            <span class="sso-status"><?= $samlEnabled ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <h3>SAML</h3>
        <p><?= $ssoUserCounts['saml'] ?> users</p>
    </div>
    <div class="sso-card <?= $ldapEnabled ? 'enabled' : 'disabled' ?>">
        <div class="sso-card-header">
            <span class="sso-icon">📁</span>
            <span class="sso-status"><?= $ldapEnabled ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <h3>LDAP/AD</h3>
        <p><?= $ssoUserCounts['ldap'] ?> users</p>
    </div>
    <div class="sso-card <?= $scimEnabled ? 'enabled' : 'disabled' ?>">
        <div class="sso-card-header">
            <span class="sso-icon">🔄</span>
            <span class="sso-status"><?= $scimEnabled ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <h3>SCIM</h3>
        <p>User Provisioning</p>
    </div>
</div>

<!-- Tab Navigation -->
<div class="sso-tabs">
    <a href="?tab=oidc" class="sso-tab <?= $activeTab === 'oidc' ? 'active' : '' ?>">OIDC</a>
    <a href="?tab=saml" class="sso-tab <?= $activeTab === 'saml' ? 'active' : '' ?>">SAML</a>
    <a href="?tab=ldap" class="sso-tab <?= $activeTab === 'ldap' ? 'active' : '' ?>">LDAP/AD</a>
    <a href="?tab=scim" class="sso-tab <?= $activeTab === 'scim' ? 'active' : '' ?>">SCIM</a>
    <a href="?tab=oauth" class="sso-tab <?= $activeTab === 'oauth' ? 'active' : '' ?>">OAuth2 Clients</a>
</div>

<!-- Tab Content -->
<div class="sso-tab-content">
    <?php if ($activeTab === 'oidc'): ?>
    <!-- OIDC Settings -->
    <form method="post" class="settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_oidc">
        <input type="hidden" name="tab" value="oidc">

        <div class="form-section">
            <h3>OpenID Connect (OIDC)</h3>
            <p class="section-description">Configure OIDC authentication with providers like Google, Azure AD, Okta, Auth0, etc.</p>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="oidc_enabled" <?= $oidcEnabled ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Enable OIDC Authentication</span>
                </label>
            </div>

            <div class="form-group">
                <label>Provider Name</label>
                <input type="text" name="provider_name" class="form-input" value="<?= htmlspecialchars($oidcProviderName) ?>" placeholder="e.g., Google, Azure AD">
                <small>Displayed on the login button</small>
            </div>

            <div class="form-group">
                <label>Provider URL (Issuer)</label>
                <input type="url" name="provider_url" class="form-input" value="<?= htmlspecialchars($oidcProviderUrl) ?>" placeholder="https://accounts.google.com or https://login.microsoftonline.com/{tenant}/v2.0">
                <small>The OpenID Connect issuer URL. Used for auto-discovery of endpoints via <code>.well-known/openid-configuration</code></small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="client_id" class="form-input" value="<?= htmlspecialchars($oidcClientId) ?>">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" name="client_secret" class="form-input" placeholder="<?= $oidcClientId ? '••••••••' : '' ?>">
                    <small>Leave blank to keep existing</small>
                </div>
            </div>

            <div class="form-group">
                <label>Authorization URL</label>
                <input type="url" name="auth_url" class="form-input" value="<?= htmlspecialchars($oidcAuthUrl) ?>" placeholder="https://provider.com/oauth2/authorize">
            </div>

            <div class="form-group">
                <label>Token URL</label>
                <input type="url" name="token_url" class="form-input" value="<?= htmlspecialchars($oidcTokenUrl) ?>" placeholder="https://provider.com/oauth2/token">
            </div>

            <div class="form-group">
                <label>User Info URL</label>
                <input type="url" name="userinfo_url" class="form-input" value="<?= htmlspecialchars($oidcUserinfoUrl) ?>" placeholder="https://provider.com/oauth2/userinfo">
            </div>

            <div class="form-group">
                <label>Scopes</label>
                <input type="text" name="scopes" class="form-input" value="<?= htmlspecialchars($oidcScopes) ?>">
                <small>Space-separated list of scopes</small>
            </div>

            <div class="form-group">
                <label>Token Authentication Method</label>
                <select name="token_auth_method" class="form-input">
                    <option value="client_secret_basic" <?= $oidcTokenAuthMethod === 'client_secret_basic' ? 'selected' : '' ?>>HTTP Basic Auth (client_secret_basic)</option>
                    <option value="client_secret_post" <?= $oidcTokenAuthMethod === 'client_secret_post' ? 'selected' : '' ?>>POST Body (client_secret_post)</option>
                </select>
                <small>How client credentials are sent to the token endpoint. Most providers use Basic Auth.</small>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_create" <?= $oidcAutoCreate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-create users on first login</span>
                </label>
            </div>

            <div class="form-group">
                <label>Default Group</label>
                <select name="default_group" class="form-input">
                    <option value="">-- None --</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>" <?= $oidcDefaultGroup == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Callback URL</label>
                <input type="text" class="form-input" value="<?= url('/oidc-callback') ?>" readonly onclick="this.select()">
                <small>Configure this URL in your identity provider</small>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save OIDC Settings</button>
        </div>
    </form>

    <?php elseif ($activeTab === 'saml'): ?>
    <!-- SAML Settings -->
    <form method="post" class="settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_saml">
        <input type="hidden" name="tab" value="saml">

        <div class="form-section">
            <h3>SAML 2.0</h3>
            <p class="section-description">Configure SAML authentication for enterprise identity providers.</p>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="saml_enabled" <?= $samlEnabled ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Enable SAML Authentication</span>
                </label>
            </div>

            <h4>Identity Provider (IdP) Settings</h4>

            <div class="form-group">
                <label>IdP Entity ID</label>
                <input type="text" name="idp_entity_id" class="form-input" value="<?= htmlspecialchars($samlIdpEntityId) ?>">
            </div>

            <div class="form-group">
                <label>IdP SSO URL</label>
                <input type="url" name="idp_sso_url" class="form-input" value="<?= htmlspecialchars($samlIdpSsoUrl) ?>">
            </div>

            <div class="form-group">
                <label>IdP SLO URL (optional)</label>
                <input type="url" name="idp_slo_url" class="form-input" value="<?= htmlspecialchars($samlIdpSloUrl) ?>">
            </div>

            <div class="form-group">
                <label>IdP X.509 Certificate</label>
                <textarea name="idp_x509_cert" class="form-input" rows="6" placeholder="-----BEGIN CERTIFICATE-----"><?= htmlspecialchars($samlIdpCert) ?></textarea>
            </div>

            <h4>Service Provider (SP) Settings</h4>

            <div class="form-group">
                <label>SP Entity ID</label>
                <input type="text" name="sp_entity_id" class="form-input" value="<?= htmlspecialchars($samlSpEntityId) ?>">
            </div>

            <div class="form-group">
                <label>Name ID Format</label>
                <select name="name_id_format" class="form-input">
                    <option value="emailAddress" <?= $samlNameIdFormat === 'emailAddress' ? 'selected' : '' ?>>Email Address</option>
                    <option value="persistent" <?= $samlNameIdFormat === 'persistent' ? 'selected' : '' ?>>Persistent</option>
                    <option value="transient" <?= $samlNameIdFormat === 'transient' ? 'selected' : '' ?>>Transient</option>
                    <option value="unspecified" <?= $samlNameIdFormat === 'unspecified' ? 'selected' : '' ?>>Unspecified</option>
                </select>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_create" <?= $samlAutoCreate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-create users on first login</span>
                </label>
            </div>

            <div class="form-group">
                <label>Default Group</label>
                <select name="default_group" class="form-input">
                    <option value="">-- None --</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>" <?= $samlDefaultGroup == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>SP ACS URL</label>
                <input type="text" class="form-input" value="<?= url('/saml/acs') ?>" readonly onclick="this.select()">
                <small>Assertion Consumer Service URL for your IdP</small>
            </div>

            <div class="form-group">
                <label>SP Metadata URL</label>
                <input type="text" class="form-input" value="<?= url('/saml/metadata') ?>" readonly onclick="this.select()">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save SAML Settings</button>
        </div>
    </form>

    <?php elseif ($activeTab === 'ldap'): ?>
    <!-- LDAP Settings -->
    <form method="post" class="settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_ldap">
        <input type="hidden" name="tab" value="ldap">

        <div class="form-section">
            <h3>LDAP / Active Directory</h3>
            <p class="section-description">Authenticate users against your organization's directory.</p>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="ldap_enabled" <?= $ldapEnabled ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Enable LDAP Authentication</span>
                </label>
            </div>

            <h4>Connection Settings</h4>

            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label>LDAP Host</label>
                    <input type="text" name="ldap_host" class="form-input" value="<?= htmlspecialchars($ldapHost) ?>" placeholder="ldap.example.com">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Port</label>
                    <input type="number" name="ldap_port" class="form-input" value="<?= htmlspecialchars($ldapPort) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="ldap_ssl" <?= $ldapSsl ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Use SSL (LDAPS)</span>
                </label>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="ldap_tls" <?= $ldapTls ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Use StartTLS</span>
                </label>
            </div>

            <div class="form-group">
                <label>Base DN</label>
                <input type="text" name="ldap_base_dn" class="form-input" value="<?= htmlspecialchars($ldapBaseDn) ?>" placeholder="dc=example,dc=com">
            </div>

            <div class="form-group">
                <label>Bind DN</label>
                <input type="text" name="ldap_bind_dn" class="form-input" value="<?= htmlspecialchars($ldapBindDn) ?>" placeholder="cn=admin,dc=example,dc=com">
            </div>

            <div class="form-group">
                <label>Bind Password</label>
                <input type="password" name="ldap_bind_password" class="form-input" placeholder="<?= $ldapBindDn ? '••••••••' : '' ?>">
                <small>Leave blank to keep existing</small>
            </div>

            <h4>User Search Settings</h4>

            <div class="form-group">
                <label>User Search Filter</label>
                <input type="text" name="ldap_user_filter" class="form-input" value="<?= htmlspecialchars($ldapUserFilter) ?>" placeholder="(&(objectClass=user)(sAMAccountName={username}))">
                <small>Use {username} as placeholder</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Username Attribute</label>
                    <input type="text" name="ldap_username_attr" class="form-input" value="<?= htmlspecialchars($ldapUsernameAttr) ?>">
                </div>
                <div class="form-group">
                    <label>Email Attribute</label>
                    <input type="text" name="ldap_email_attr" class="form-input" value="<?= htmlspecialchars($ldapEmailAttr) ?>">
                </div>
                <div class="form-group">
                    <label>Display Name Attribute</label>
                    <input type="text" name="ldap_name_attr" class="form-input" value="<?= htmlspecialchars($ldapNameAttr) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_create" <?= $ldapAutoCreate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-create users on first login</span>
                </label>
            </div>

            <div class="form-group">
                <label>Default Group</label>
                <select name="default_group" class="form-input">
                    <option value="">-- None --</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>" <?= $ldapDefaultGroup == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save LDAP Settings</button>
            <button type="submit" name="action" value="test_ldap" class="btn btn-secondary">Test Connection</button>
        </div>
    </form>

    <?php elseif ($activeTab === 'scim'): ?>
    <!-- SCIM Settings -->
    <form method="post" class="settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_scim">
        <input type="hidden" name="tab" value="scim">

        <div class="form-section">
            <h3>SCIM User Provisioning</h3>
            <p class="section-description">Automatically provision and deprovision users from your identity provider.</p>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="scim_enabled" <?= $scimEnabled ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Enable SCIM Provisioning</span>
                </label>
            </div>

            <div class="form-group">
                <label>SCIM Endpoint</label>
                <input type="text" class="form-input" value="<?= url('/api/scim/v2') ?>" readonly onclick="this.select()">
            </div>

            <div class="form-group">
                <label>Bearer Token</label>
                <div class="input-group">
                    <input type="text" class="form-input" value="<?= htmlspecialchars($scimTokenPreview) ?>" readonly>
                    <button type="submit" name="action" value="generate_scim_token" class="btn btn-secondary" onclick="return confirm('Generate new token? The old token will stop working.')">Generate New</button>
                </div>
            </div>

            <h4>Provisioning Options</h4>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_create" <?= $scimAutoCreate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-create users</span>
                </label>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_update" <?= $scimAutoUpdate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-update user attributes</span>
                </label>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="auto_deactivate" <?= $scimAutoDeactivate ? 'checked' : '' ?>>
                    <span class="toggle-switch"></span>
                    <span>Auto-deactivate removed users</span>
                </label>
            </div>

            <div class="form-group">
                <label>Default Group</label>
                <select name="default_group" class="form-input">
                    <option value="">-- None --</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>" <?= $scimDefaultGroup == $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save SCIM Settings</button>
        </div>
    </form>

    <?php elseif ($activeTab === 'oauth'): ?>
    <!-- OAuth2 Clients -->
    <div class="form-section">
        <h3>OAuth2 Clients</h3>
        <p class="section-description">Manage applications that can authenticate against MeshSilo.</p>

        <?php if (!empty($oauthClients)): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Client ID</th>
                    <th>Type</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($oauthClients as $client): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($client['name']) ?></strong>
                        <?php if ($client['description']): ?>
                        <br><small><?= htmlspecialchars($client['description']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($client['client_id']) ?></code></td>
                    <td><?= $client['is_confidential'] ? 'Confidential' : 'Public' ?></td>
                    <td><?= date('M j, Y', strtotime($client['created_at'])) ?></td>
                    <td>
                        <form method="post" style="display: inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_oauth_client">
                            <input type="hidden" name="tab" value="oauth">
                            <input type="hidden" name="client_id" value="<?= htmlspecialchars($client['client_id']) ?>">
                            <button type="submit" class="btn btn-small btn-danger" onclick="return confirm('Delete this client?')">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="empty-state">No OAuth2 clients configured.</p>
        <?php endif; ?>

        <h4 style="margin-top: 2rem;">Create New Client</h4>
        <form method="post" class="settings-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_oauth_client">
            <input type="hidden" name="tab" value="oauth">

            <div class="form-row">
                <div class="form-group">
                    <label>Client Name</label>
                    <input type="text" name="name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label>Redirect URIs (one per line)</label>
                <textarea name="redirect_uris" class="form-input" rows="3" required placeholder="https://app.example.com/callback"></textarea>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="is_confidential" checked>
                    <span class="toggle-switch"></span>
                    <span>Confidential Client (server-side app with secret)</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Create Client</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<style>
.sso-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.sso-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 1rem;
    text-align: center;
}

.sso-card.enabled {
    border-color: var(--color-success);
}

.sso-card.disabled {
    opacity: 0.6;
}

.sso-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.sso-icon {
    font-size: 1.5rem;
}

.sso-status {
    font-size: 0.7rem;
    padding: 0.2rem 0.5rem;
    border-radius: var(--radius);
    background: var(--color-surface-hover);
}

.sso-card.enabled .sso-status {
    background: var(--color-success);
    color: white;
}

.sso-card h3 {
    margin: 0.5rem 0 0.25rem;
    font-size: 1rem;
}

.sso-card p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--color-text-muted);
}

.sso-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 1.5rem;
}

.sso-tab {
    padding: 0.75rem 1.5rem;
    text-decoration: none;
    color: var(--color-text-muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.2s;
}

.sso-tab:hover {
    color: var(--color-text);
    background: var(--color-surface-hover);
}

.sso-tab.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
}

.sso-tab-content {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 1.5rem;
}

.form-section h4 {
    margin: 1.5rem 0 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border);
    font-size: 0.95rem;
}

.form-section h4:first-of-type {
    margin-top: 1rem;
    padding-top: 0;
    border-top: none;
}

.form-row {
    display: flex;
    gap: 1rem;
}

.form-row .form-group {
    flex: 1;
}

.input-group {
    display: flex;
    gap: 0.5rem;
}

.input-group .form-input {
    flex: 1;
}

.section-description {
    color: var(--color-text-muted);
    margin-bottom: 1.5rem;
}


.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--color-text-muted);
}

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
        gap: 0;
    }

    .sso-tabs {
        flex-wrap: wrap;
    }

    .sso-tab {
        padding: 0.5rem 1rem;
        font-size: 0.85rem;
    }
}
</style>

</div><!-- /.admin-content -->
</div><!-- /.admin-layout -->

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
