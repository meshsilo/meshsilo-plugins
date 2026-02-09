<?php
/**
 * LDAP/Active Directory Authentication Helper
 *
 * Provides LDAP authentication and group synchronization
 */

/**
 * Check if LDAP is enabled and properly configured
 */
function isLDAPEnabled() {
    return getSetting('ldap_enabled', '0') === '1'
        && !empty(getSetting('ldap_host'))
        && !empty(getSetting('ldap_base_dn'));
}

/**
 * Check if LDAP extension is available
 */
function isLDAPAvailable() {
    return extension_loaded('ldap');
}

/**
 * Get LDAP connection settings
 */
function getLDAPConfig() {
    return [
        'host' => getSetting('ldap_host', ''),
        'port' => (int)getSetting('ldap_port', 389),
        'use_ssl' => getSetting('ldap_use_ssl', '0') === '1',
        'use_tls' => getSetting('ldap_use_tls', '0') === '1',
        'base_dn' => getSetting('ldap_base_dn', ''),
        'bind_dn' => getSetting('ldap_bind_dn', ''),
        'bind_password' => getSetting('ldap_bind_password', ''),
        'user_filter' => getSetting('ldap_user_filter', '(sAMAccountName=%s)'),
        'username_attribute' => getSetting('ldap_username_attribute', 'sAMAccountName'),
        'email_attribute' => getSetting('ldap_email_attribute', 'mail'),
        'display_name_attribute' => getSetting('ldap_display_name_attribute', 'displayName'),
        'groups_attribute' => getSetting('ldap_groups_attribute', 'memberOf'),
        'search_scope' => getSetting('ldap_search_scope', 'subtree'),
        'referrals' => getSetting('ldap_follow_referrals', '0') === '1',
        'timeout' => (int)getSetting('ldap_timeout', 10),
    ];
}

/**
 * Create LDAP connection
 */
function createLDAPConnection() {
    if (!isLDAPAvailable()) {
        logError('LDAP extension not available');
        return null;
    }

    $config = getLDAPConfig();

    // Build LDAP URI
    $protocol = $config['use_ssl'] ? 'ldaps' : 'ldap';
    $uri = $protocol . '://' . $config['host'] . ':' . $config['port'];

    $conn = ldap_connect($uri);
    if (!$conn) {
        logError('LDAP connection failed', ['uri' => $uri]);
        return null;
    }

    // Set LDAP options
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, $config['referrals'] ? 1 : 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $config['timeout']);

    // Start TLS if configured (not with SSL)
    if ($config['use_tls'] && !$config['use_ssl']) {
        if (!@ldap_start_tls($conn)) {
            logError('LDAP TLS failed', ['error' => ldap_error($conn)]);
            ldap_close($conn);
            return null;
        }
    }

    return $conn;
}

/**
 * Bind to LDAP server with service account
 */
function ldapServiceBind($conn) {
    $config = getLDAPConfig();

    if (empty($config['bind_dn'])) {
        // Anonymous bind
        $bound = @ldap_bind($conn);
    } else {
        $bound = @ldap_bind($conn, $config['bind_dn'], $config['bind_password']);
    }

    if (!$bound) {
        logError('LDAP service bind failed', [
            'bind_dn' => $config['bind_dn'],
            'error' => ldap_error($conn)
        ]);
        return false;
    }

    return true;
}

/**
 * Search for user in LDAP
 */
function ldapSearchUser($conn, $username) {
    $config = getLDAPConfig();

    // Build user filter
    $filter = sprintf($config['user_filter'], ldap_escape($username, '', LDAP_ESCAPE_FILTER));

    // Attributes to retrieve
    $attributes = [
        $config['username_attribute'],
        $config['email_attribute'],
        $config['display_name_attribute'],
        $config['groups_attribute'],
        'dn',
        'objectGUID',
        'userPrincipalName'
    ];

    // Search
    $searchFunc = $config['search_scope'] === 'onelevel' ? 'ldap_list' : 'ldap_search';
    $result = @$searchFunc($conn, $config['base_dn'], $filter, $attributes);

    if (!$result) {
        logError('LDAP search failed', [
            'base_dn' => $config['base_dn'],
            'filter' => $filter,
            'error' => ldap_error($conn)
        ]);
        return null;
    }

    $entries = ldap_get_entries($conn, $result);

    if ($entries['count'] === 0) {
        return null;
    }

    if ($entries['count'] > 1) {
        logWarning('LDAP search returned multiple users', ['username' => $username, 'count' => $entries['count']]);
    }

    return $entries[0];
}

/**
 * Authenticate user via LDAP
 */
function authenticateLDAP($username, $password) {
    if (!isLDAPEnabled() || !isLDAPAvailable()) {
        return null;
    }

    if (empty($username) || empty($password)) {
        return null;
    }

    $conn = createLDAPConnection();
    if (!$conn) {
        return null;
    }

    try {
        // Bind with service account to search
        if (!ldapServiceBind($conn)) {
            ldap_close($conn);
            return null;
        }

        // Search for user
        $ldapUser = ldapSearchUser($conn, $username);
        if (!$ldapUser) {
            ldap_close($conn);
            return null;
        }

        $userDn = $ldapUser['dn'];

        // Attempt to bind as the user
        $bound = @ldap_bind($conn, $userDn, $password);
        ldap_close($conn);

        if (!$bound) {
            logInfo('LDAP authentication failed', ['username' => $username]);
            return null;
        }

        // Authentication successful
        return normalizeLDAPEntry($ldapUser);

    } catch (Exception $e) {
        logError('LDAP authentication error', ['error' => $e->getMessage()]);
        if ($conn) ldap_close($conn);
        return null;
    }
}

/**
 * Normalize LDAP entry to standard format
 */
function normalizeLDAPEntry($entry) {
    $config = getLDAPConfig();

    $normalized = [
        'dn' => $entry['dn'] ?? null,
        'guid' => null,
        'username' => null,
        'email' => null,
        'display_name' => null,
        'groups' => []
    ];

    // Extract GUID (binary in AD)
    if (isset($entry['objectguid'][0])) {
        $guid = $entry['objectguid'][0];
        // Convert binary GUID to string
        if (strlen($guid) === 16) {
            $normalized['guid'] = bin2hex($guid);
        } else {
            $normalized['guid'] = $guid;
        }
    }

    // Extract username
    $usernameAttr = strtolower($config['username_attribute']);
    if (isset($entry[$usernameAttr][0])) {
        $normalized['username'] = $entry[$usernameAttr][0];
    }

    // Extract email
    $emailAttr = strtolower($config['email_attribute']);
    if (isset($entry[$emailAttr][0])) {
        $normalized['email'] = $entry[$emailAttr][0];
    }

    // Extract display name
    $displayAttr = strtolower($config['display_name_attribute']);
    if (isset($entry[$displayAttr][0])) {
        $normalized['display_name'] = $entry[$displayAttr][0];
    }

    // Extract groups
    $groupsAttr = strtolower($config['groups_attribute']);
    if (isset($entry[$groupsAttr])) {
        for ($i = 0; $i < ($entry[$groupsAttr]['count'] ?? 0); $i++) {
            $groupDn = $entry[$groupsAttr][$i];
            // Extract CN from DN
            if (preg_match('/^CN=([^,]+)/i', $groupDn, $matches)) {
                $normalized['groups'][] = $matches[1];
            } else {
                $normalized['groups'][] = $groupDn;
            }
        }
    }

    return $normalized;
}

/**
 * Find or create user from LDAP data
 */
function findOrCreateLDAPUser($ldapData) {
    $db = getDB();

    $dn = $ldapData['dn'];
    $guid = $ldapData['guid'];
    $username = $ldapData['username'];
    $email = $ldapData['email'];
    $displayName = $ldapData['display_name'];

    if (empty($username)) {
        return ['error' => 'No username in LDAP response'];
    }

    // Sanitize username
    $username = preg_replace('/[^a-zA-Z0-9_.-]/', '', $username);
    if (empty($username)) {
        $username = 'ldap_user_' . substr(md5($dn), 0, 8);
    }

    // Try to find existing user by LDAP DN or GUID
    $stmt = $db->prepare('SELECT * FROM users WHERE ldap_dn = :dn OR ldap_guid = :guid');
    $stmt->bindValue(':dn', $dn, PDO::PARAM_STR);
    $stmt->bindValue(':guid', $guid, PDO::PARAM_STR);
    $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // Update user info
        $stmt = $db->prepare('
            UPDATE users
            SET ldap_dn = :dn, ldap_guid = :guid, ldap_groups = :groups,
                ldap_synced_at = :now, last_auth_at = :now, auth_method = :method
            WHERE id = :id
        ');
        $stmt->bindValue(':dn', $dn, PDO::PARAM_STR);
        $stmt->bindValue(':guid', $guid, PDO::PARAM_STR);
        $stmt->bindValue(':groups', json_encode($ldapData['groups']), PDO::PARAM_STR);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':method', 'ldap', PDO::PARAM_STR);
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $stmt->execute();

        // Sync groups if enabled
        if (getSetting('ldap_sync_groups', '0') === '1') {
            syncLDAPGroups($existingUser['id'], $ldapData['groups']);
        }

        // Refresh user data
        $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        return ['success' => true, 'user' => $existingUser, 'is_new' => false];
    }

    // Try to find by username
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // Link existing account to LDAP
        $stmt = $db->prepare('
            UPDATE users
            SET ldap_dn = :dn, ldap_guid = :guid, ldap_groups = :groups,
                ldap_synced_at = :now, last_auth_at = :now, auth_method = :method
            WHERE id = :id
        ');
        $stmt->bindValue(':dn', $dn, PDO::PARAM_STR);
        $stmt->bindValue(':guid', $guid, PDO::PARAM_STR);
        $stmt->bindValue(':groups', json_encode($ldapData['groups']), PDO::PARAM_STR);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':method', 'ldap', PDO::PARAM_STR);
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $stmt->execute();

        if (getSetting('ldap_sync_groups', '0') === '1') {
            syncLDAPGroups($existingUser['id'], $ldapData['groups']);
        }

        $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->bindValue(':id', $existingUser['id'], PDO::PARAM_INT);
        $existingUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

        return ['success' => true, 'user' => $existingUser, 'is_new' => false];
    }

    // Create new user if auto-register is enabled
    if (getSetting('ldap_auto_register', '1') !== '1') {
        return ['error' => 'User not found and auto-registration is disabled'];
    }

    // Get default group
    $defaultGroupId = getSetting('ldap_default_group', getSetting('default_group', 1));

    // Create user
    $stmt = $db->prepare('
        INSERT INTO users (username, email, ldap_dn, ldap_guid, ldap_groups, ldap_synced_at, is_admin, auth_method, created_at)
        VALUES (:username, :email, :dn, :guid, :groups, :now, 0, :method, :now)
    ');
    $stmt->bindValue(':username', $username, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':dn', $dn, PDO::PARAM_STR);
    $stmt->bindValue(':guid', $guid, PDO::PARAM_STR);
    $stmt->bindValue(':groups', json_encode($ldapData['groups']), PDO::PARAM_STR);
    $stmt->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
    $stmt->bindValue(':method', 'ldap', PDO::PARAM_STR);
    $stmt->execute();

    $userId = $db->lastInsertRowID();

    // Assign to default group
    if ($defaultGroupId) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:uid, :gid)');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':gid', $defaultGroupId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Sync groups if enabled
    if (getSetting('ldap_sync_groups', '0') === '1') {
        syncLDAPGroups($userId, $ldapData['groups']);
    }

    // Get the created user
    $stmt = $db->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $newUser = $stmt->execute()->fetchArray(PDO::FETCH_ASSOC);

    logActivity($userId, 'ldap_register', 'user', $userId, 'New LDAP user: ' . $username);

    return ['success' => true, 'user' => $newUser, 'is_new' => true];
}

/**
 * Sync LDAP groups to Silo groups
 */
function syncLDAPGroups($userId, $ldapGroups) {
    if (empty($ldapGroups)) {
        return;
    }

    // Get group mapping
    $mappingJson = getSetting('ldap_group_mapping', '{}');
    $mapping = json_decode($mappingJson, true) ?: [];

    if (empty($mapping)) {
        return;
    }

    $db = getDB();

    // Get current groups
    $stmt = $db->prepare('SELECT group_id FROM user_groups WHERE user_id = :uid');
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $result = $stmt->execute();
    $currentGroups = [];
    while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
        $currentGroups[] = $row['group_id'];
    }

    // Determine target groups from mapping
    $targetGroups = [];
    foreach ($ldapGroups as $ldapGroup) {
        // Check both exact match and case-insensitive
        $groupLower = strtolower($ldapGroup);
        foreach ($mapping as $mapKey => $mapValue) {
            if ($ldapGroup === $mapKey || $groupLower === strtolower($mapKey)) {
                $targetGroups[] = (int)$mapValue;
            }
        }
    }

    $targetGroups = array_unique($targetGroups);

    // Add missing groups
    foreach ($targetGroups as $groupId) {
        if (!in_array($groupId, $currentGroups)) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (:uid, :gid)');
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':gid', $groupId, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    // Optionally remove groups not in LDAP
    if (getSetting('ldap_remove_unmapped_groups', '0') === '1') {
        foreach ($currentGroups as $groupId) {
            if (!in_array($groupId, $targetGroups)) {
                $stmt = $db->prepare('DELETE FROM user_groups WHERE user_id = :uid AND group_id = :gid');
                $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':gid', $groupId, PDO::PARAM_INT);
                $stmt->execute();
            }
        }
    }
}

/**
 * Test LDAP connection and configuration
 */
function testLDAPConnection() {
    if (!isLDAPAvailable()) {
        return ['success' => false, 'error' => 'LDAP PHP extension not installed'];
    }

    $conn = createLDAPConnection();
    if (!$conn) {
        return ['success' => false, 'error' => 'Could not connect to LDAP server'];
    }

    if (!ldapServiceBind($conn)) {
        ldap_close($conn);
        return ['success' => false, 'error' => 'Could not bind to LDAP server'];
    }

    ldap_close($conn);
    return ['success' => true, 'message' => 'LDAP connection successful'];
}

/**
 * Sync all LDAP users (for scheduled task)
 */
function syncAllLDAPUsers() {
    if (!isLDAPEnabled() || !isLDAPAvailable()) {
        return ['synced' => 0, 'errors' => 0];
    }

    $db = getDB();
    $conn = createLDAPConnection();

    if (!$conn || !ldapServiceBind($conn)) {
        return ['synced' => 0, 'errors' => 1, 'error' => 'Connection failed'];
    }

    $config = getLDAPConfig();
    $synced = 0;
    $errors = 0;

    // Get all LDAP users from database
    $stmt = $db->prepare('SELECT id, username, ldap_dn FROM users WHERE ldap_dn IS NOT NULL');
    $result = $stmt->execute();

    while ($user = $result->fetchArray(PDO::FETCH_ASSOC)) {
        try {
            // Search for user in LDAP
            $ldapUser = ldapSearchUser($conn, $user['username']);

            if ($ldapUser) {
                $normalized = normalizeLDAPEntry($ldapUser);

                // Update user
                $stmt2 = $db->prepare('
                    UPDATE users
                    SET ldap_dn = :dn, ldap_guid = :guid, ldap_groups = :groups, ldap_synced_at = :now
                    WHERE id = :id
                ');
                $stmt2->bindValue(':dn', $normalized['dn'], PDO::PARAM_STR);
                $stmt2->bindValue(':guid', $normalized['guid'], PDO::PARAM_STR);
                $stmt2->bindValue(':groups', json_encode($normalized['groups']), PDO::PARAM_STR);
                $stmt2->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
                $stmt2->bindValue(':id', $user['id'], PDO::PARAM_INT);
                $stmt2->execute();

                // Sync groups
                if (getSetting('ldap_sync_groups', '0') === '1') {
                    syncLDAPGroups($user['id'], $normalized['groups']);
                }

                $synced++;
            } else {
                // User not found in LDAP
                if (getSetting('ldap_disable_missing_users', '0') === '1') {
                    $stmt2 = $db->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
                    $stmt2->bindValue(':id', $user['id'], PDO::PARAM_INT);
                    $stmt2->execute();
                }
                $errors++;
            }
        } catch (Exception $e) {
            logError('LDAP sync error for user', ['user_id' => $user['id'], 'error' => $e->getMessage()]);
            $errors++;
        }
    }

    ldap_close($conn);

    return ['synced' => $synced, 'errors' => $errors];
}
