<?php
/**
 * Webhooks Plugin - Database Migrations
 */
return [
    [
        'description' => 'Webhooks table',
        'check' => function($db) {
            return tableExists($db, 'webhooks');
        },
        'up' => function($db) {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $db->exec('CREATE TABLE webhooks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255),
                    url VARCHAR(2048) NOT NULL,
                    secret VARCHAR(255),
                    events JSON,
                    is_active TINYINT(1) DEFAULT 1,
                    last_triggered_at DATETIME,
                    last_status_code INT,
                    failure_count INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE webhooks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    url TEXT NOT NULL,
                    secret TEXT,
                    events TEXT,
                    is_active INTEGER DEFAULT 1,
                    last_triggered_at DATETIME,
                    last_status_code INTEGER,
                    failure_count INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
            }
        }
    ],
    [
        'description' => 'Webhook deliveries table',
        'check' => function($db) {
            return tableExists($db, 'webhook_deliveries');
        },
        'up' => function($db) {
            if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
                $db->exec('CREATE TABLE webhook_deliveries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    webhook_id INT NOT NULL,
                    event VARCHAR(100) NOT NULL,
                    payload JSON,
                    http_code INT,
                    response TEXT,
                    error TEXT,
                    retries INT DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_webhook_del_created (created_at),
                    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE webhook_deliveries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    webhook_id INTEGER NOT NULL,
                    event TEXT NOT NULL,
                    payload TEXT,
                    http_code INTEGER,
                    response TEXT,
                    error TEXT,
                    retries INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
                )');
                $db->exec('CREATE INDEX idx_webhook_del_created ON webhook_deliveries(created_at)');
            }
        }
    ]
];
