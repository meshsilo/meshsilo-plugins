<?php
/**
 * 3D Printing Tools Plugin - Database Migrations
 *
 * Creates the tables owned by this plugin that nothing else provisions:
 *   - printers      (printer profiles / build volumes)
 *   - print_photos  (uploaded photos of completed prints)
 *
 * The print_queue table is provided by the host core, and the gcode_metadata
 * table plus the models.volume_cm3 / is_manifold / mesh_errors columns are
 * self-provisioned lazily by the plugin libraries, so they are intentionally
 * not created here.
 *
 * Uses the check/apply pattern (idempotent) and branches on the PDO driver so
 * the same migration works on both SQLite and MySQL.
 */

return [
    // Printer profiles
    [
        'description' => 'Printers table',
        'check' => function () {
            $db = getDB();
            return tableExists($db, 'printers');
        },
        'apply' => function () {
            $db = getDB();
            if ($db->getType() === 'mysql') {
                $db->exec('CREATE TABLE printers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    name VARCHAR(255) NOT NULL,
                    manufacturer VARCHAR(255),
                    model VARCHAR(255),
                    bed_x DOUBLE,
                    bed_y DOUBLE,
                    bed_z DOUBLE,
                    print_type VARCHAR(20) DEFAULT \'fdm\',
                    notes TEXT,
                    is_default TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_printers_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE printers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    name TEXT NOT NULL,
                    manufacturer TEXT,
                    model TEXT,
                    bed_x REAL,
                    bed_y REAL,
                    bed_z REAL,
                    print_type TEXT DEFAULT \'fdm\',
                    notes TEXT,
                    is_default INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_printers_user ON printers(user_id)');
            }
        },
    ],
    // Print photos
    [
        'description' => 'Print photos table',
        'check' => function () {
            $db = getDB();
            return tableExists($db, 'print_photos');
        },
        'apply' => function () {
            $db = getDB();
            if ($db->getType() === 'mysql') {
                $db->exec('CREATE TABLE print_photos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    model_id INT NOT NULL,
                    user_id INT,
                    filename VARCHAR(255),
                    file_path VARCHAR(1024),
                    caption TEXT,
                    is_primary TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_print_photos_model (model_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            } else {
                $db->exec('CREATE TABLE print_photos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    model_id INTEGER NOT NULL,
                    user_id INTEGER,
                    filename TEXT,
                    file_path TEXT,
                    caption TEXT,
                    is_primary INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )');
                $db->exec('CREATE INDEX IF NOT EXISTS idx_print_photos_model ON print_photos(model_id)');
            }
        },
    ],
];
