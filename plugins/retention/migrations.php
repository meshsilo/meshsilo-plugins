<?php
/**
 * Retention Policies Plugin - Database Migrations
 * For fresh installs where the plugin is installed before core migrations run.
 */
return [
    [
        'description' => 'Retention policies table',
        'check' => function($db) {
            return tableExists($db, 'retention_policies');
        },
        'up' => function($db) {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $db->exec('CREATE TABLE retention_policies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    entity_type VARCHAR(50) NOT NULL,
                    conditions JSON,
                    action VARCHAR(50) NOT NULL DEFAULT "archive",
                    is_active TINYINT(1) DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE retention_policies (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    entity_type TEXT NOT NULL,
                    conditions TEXT,
                    action TEXT NOT NULL DEFAULT "archive",
                    is_active INTEGER DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
            }
        }
    ],
    [
        'description' => 'Legal holds table',
        'check' => function($db) {
            return tableExists($db, 'legal_holds');
        },
        'up' => function($db) {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $db->exec('CREATE TABLE legal_holds (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    entity_type VARCHAR(50) NOT NULL,
                    entity_id INT NOT NULL,
                    reason TEXT NOT NULL,
                    created_by INT,
                    expires_at DATETIME,
                    released_at DATETIME,
                    released_by INT,
                    release_reason TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_legal_holds_entity (entity_type, entity_id),
                    INDEX idx_legal_holds_active (released_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE legal_holds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entity_type TEXT NOT NULL,
                    entity_id INTEGER NOT NULL,
                    reason TEXT NOT NULL,
                    created_by INTEGER,
                    expires_at DATETIME,
                    released_at DATETIME,
                    released_by INTEGER,
                    release_reason TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
                $db->exec('CREATE INDEX idx_legal_holds_entity ON legal_holds(entity_type, entity_id)');
                $db->exec('CREATE INDEX idx_legal_holds_active ON legal_holds(released_at)');
            }
        }
    ],
    [
        'description' => 'Retention execution log',
        'check' => function($db) {
            return tableExists($db, 'retention_log');
        },
        'up' => function($db) {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $db->exec('CREATE TABLE retention_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    policy_id INT NOT NULL,
                    action_taken VARCHAR(50) NOT NULL,
                    items_processed INT DEFAULT 0,
                    items_affected INT DEFAULT 0,
                    items_skipped INT DEFAULT 0,
                    error_count INT DEFAULT 0,
                    details JSON,
                    started_at DATETIME,
                    completed_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_retention_log_policy (policy_id),
                    FOREIGN KEY (policy_id) REFERENCES retention_policies(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE retention_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    policy_id INTEGER NOT NULL,
                    action_taken TEXT NOT NULL,
                    items_processed INTEGER DEFAULT 0,
                    items_affected INTEGER DEFAULT 0,
                    items_skipped INTEGER DEFAULT 0,
                    error_count INTEGER DEFAULT 0,
                    details TEXT,
                    started_at DATETIME,
                    completed_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (policy_id) REFERENCES retention_policies(id) ON DELETE CASCADE
                )');
                $db->exec('CREATE INDEX idx_retention_log_policy ON retention_log(policy_id)');
            }
        }
    ]
];
