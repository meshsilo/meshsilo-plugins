<?php
/**
 * Hello World Plugin - Migrations
 *
 * Returns an array of migrations using the check/apply pattern.
 * Each migration has:
 *   'check' => callable that returns true if migration is already applied
 *   'apply' => callable that applies the migration
 */

return [
    [
        'check' => function() {
            // This plugin doesn't need any DB tables, but this demonstrates the pattern
            return true; // Already "applied" - nothing to do
        },
        'apply' => function() {
            // Example: would create tables here
            // $db = getDB();
            // $db->exec('CREATE TABLE ...');
        },
    ],
];
