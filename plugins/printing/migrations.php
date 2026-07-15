<?php
/**
 * 3D Printing Tools Plugin - Database Migrations
 *
 * Creates the table owned by this plugin that nothing else provisions:
 *   - print_photos  (uploaded photos of completed prints)
 *
 * The print_queue table is provided by the host core, and the gcode_metadata
 * table plus the models.volume_cm3 / is_manifold / mesh_errors columns are
 * self-provisioned lazily by the plugin libraries, so they are intentionally
 * not created here. The printers table (printer profiles / build volumes) is
 * no longer created by this plugin - an existing installation may still have
 * it on disk, orphaned but harmless, since no down-migration exists in this
 * codebase to drop it automatically.
 *
 * Uses the check/apply pattern (idempotent) and branches on the PDO driver so
 * the same migration works on both SQLite and MySQL.
 */

return [
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
