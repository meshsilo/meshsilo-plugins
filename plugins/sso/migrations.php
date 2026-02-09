<?php
/**
 * SSO Plugin - Migrations
 *
 * Ensures SSO-related database columns exist on the users table.
 * These are also in core migrations for backward compat, so the
 * check functions handle the case where columns already exist.
 */

return [
    // SAML columns on users table
    [
        'check' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'saml_id'");
                return $stmt->fetch() !== false;
            } else {
                $stmt = $db->query("PRAGMA table_info(users)");
                while ($col = $stmt->fetch()) {
                    if ($col['name'] === 'saml_id') return true;
                }
                return false;
            }
        },
        'apply' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $db->exec("ALTER TABLE users ADD COLUMN saml_id VARCHAR(255) DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN saml_idp VARCHAR(100) DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN saml_attributes TEXT DEFAULT NULL");
                $db->exec("CREATE INDEX idx_users_saml ON users(saml_id)");
            } else {
                $db->exec("ALTER TABLE users ADD COLUMN saml_id TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN saml_idp TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN saml_attributes TEXT DEFAULT NULL");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_users_saml ON users(saml_id)");
            }
        },
    ],
    // LDAP columns on users table
    [
        'check' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'ldap_dn'");
                return $stmt->fetch() !== false;
            } else {
                $stmt = $db->query("PRAGMA table_info(users)");
                while ($col = $stmt->fetch()) {
                    if ($col['name'] === 'ldap_dn') return true;
                }
                return false;
            }
        },
        'apply' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $db->exec("ALTER TABLE users ADD COLUMN ldap_dn VARCHAR(500) DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_guid VARCHAR(64) DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_groups TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_synced_at DATETIME DEFAULT NULL");
                $db->exec("CREATE INDEX idx_users_ldap ON users(ldap_dn)");
            } else {
                $db->exec("ALTER TABLE users ADD COLUMN ldap_dn TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_guid TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_groups TEXT DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN ldap_synced_at DATETIME DEFAULT NULL");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_users_ldap ON users(ldap_dn)");
            }
        },
    ],
    // Auth method tracking columns
    [
        'check' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'auth_method'");
                return $stmt->fetch() !== false;
            } else {
                $stmt = $db->query("PRAGMA table_info(users)");
                while ($col = $stmt->fetch()) {
                    if ($col['name'] === 'auth_method') return true;
                }
                return false;
            }
        },
        'apply' => function() {
            $db = getDB();
            $type = $db->getType();
            if ($type === 'mysql') {
                $db->exec("ALTER TABLE users ADD COLUMN auth_method VARCHAR(20) DEFAULT 'local'");
                $db->exec("ALTER TABLE users ADD COLUMN last_auth_at DATETIME DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN last_auth_ip VARCHAR(45) DEFAULT NULL");
            } else {
                $db->exec("ALTER TABLE users ADD COLUMN auth_method TEXT DEFAULT 'local'");
                $db->exec("ALTER TABLE users ADD COLUMN last_auth_at DATETIME DEFAULT NULL");
                $db->exec("ALTER TABLE users ADD COLUMN last_auth_ip TEXT DEFAULT NULL");
            }
        },
    ],
];
