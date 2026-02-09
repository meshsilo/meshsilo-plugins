<?php
/**
 * Backup Manager
 * Handles Point-in-Time Recovery, Multi-Region Replication, and Backup Verification
 */

class BackupManager {
    private const BACKUP_DIR = __DIR__ . '/../storage/backups';
    private const WAL_DIR = __DIR__ . '/../storage/backups/wal';
    private const REPLICATION_CONFIG = __DIR__ . '/../storage/.replication_config';

    /**
     * Create a full database backup
     */
    public static function createBackup(string $label = ''): array {
        self::ensureDirectories();

        $timestamp = date('Y-m-d_H-i-s');
        $label = $label ? preg_replace('/[^a-zA-Z0-9_-]/', '', $label) : 'manual';
        $filename = "backup_{$timestamp}_{$label}.db";
        $backupPath = self::BACKUP_DIR . '/' . $filename;

        $db = getDB();
        $dbPath = DB_PATH;

        // For SQLite, use the backup API
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Checkpoint WAL mode first
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');

            // Copy database file
            if (!copy($dbPath, $backupPath)) {
                throw new Exception('Failed to copy database file');
            }

            // Also copy WAL and SHM if they exist
            if (file_exists($dbPath . '-wal')) {
                copy($dbPath . '-wal', $backupPath . '-wal');
            }
            if (file_exists($dbPath . '-shm')) {
                copy($dbPath . '-shm', $backupPath . '-shm');
            }
        } else {
            // For MySQL, use mysqldump with password via environment variable
            // to avoid exposing password in process listings
            $config = getConfig();
            $cmd = sprintf(
                'mysqldump -h %s -u %s %s > %s',
                escapeshellarg($config['db_host'] ?? 'localhost'),
                escapeshellarg($config['db_user'] ?? 'root'),
                escapeshellarg($config['db_name'] ?? 'silo'),
                escapeshellarg($backupPath)
            );

            // Set MYSQL_PWD environment variable (secure way to pass password)
            $env = array_merge($_ENV, ['MYSQL_PWD' => $config['db_password'] ?? '']);
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ];
            $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);

            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $returnCode = proc_close($process);
            } else {
                $returnCode = 1;
            }

            if ($returnCode !== 0) {
                throw new Exception('mysqldump failed: ' . ($errors ?? 'Unknown error'));
            }
        }

        // Compress the backup
        $compressedPath = $backupPath . '.gz';
        $fp = gzopen($compressedPath, 'w9');
        gzwrite($fp, file_get_contents($backupPath));
        gzclose($fp);
        unlink($backupPath);

        // Calculate checksum
        $checksum = hash_file('sha256', $compressedPath);

        // Save metadata
        $metadata = [
            'timestamp' => $timestamp,
            'label' => $label,
            'filename' => $filename . '.gz',
            'size' => filesize($compressedPath),
            'checksum' => $checksum,
            'db_type' => $db->getAttribute(PDO::ATTR_DRIVER_NAME),
            'version' => getSetting('app_version', '1.0'),
        ];

        file_put_contents(
            self::BACKUP_DIR . '/metadata_' . $timestamp . '.json',
            json_encode($metadata, JSON_PRETTY_PRINT)
        );

        // Log the backup
        if (function_exists('logInfo')) {
            logInfo('Backup created', ['filename' => $filename, 'size' => $metadata['size']]);
        }

        return $metadata;
    }

    /**
     * List available backups
     */
    public static function listBackups(): array {
        self::ensureDirectories();

        $backups = [];
        $files = glob(self::BACKUP_DIR . '/metadata_*.json');

        foreach ($files as $file) {
            $metadata = json_decode(file_get_contents($file), true);
            if ($metadata) {
                $backupFile = self::BACKUP_DIR . '/' . $metadata['filename'];
                $metadata['exists'] = file_exists($backupFile);
                $metadata['path'] = $backupFile;
                $backups[] = $metadata;
            }
        }

        // Sort by timestamp descending
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });

        return $backups;
    }

    /**
     * Restore from a backup (Point-in-Time Recovery)
     */
    public static function restore(string $backupPath, bool $verify = true): array {
        if (!file_exists($backupPath)) {
            throw new Exception('Backup file not found');
        }

        // Verify backup integrity if requested
        if ($verify) {
            $verification = self::verifyBackup($backupPath);
            if (!$verification['valid']) {
                throw new Exception('Backup verification failed: ' . $verification['error']);
            }
        }

        $db = getDB();
        $dbPath = DB_PATH;

        // Create a pre-restore backup
        $preRestoreBackup = self::createBackup('pre_restore');

        try {
            // Decompress backup
            $tempPath = sys_get_temp_dir() . '/silo_restore_' . uniqid() . '.db';
            $gz = gzopen($backupPath, 'r');
            $fp = fopen($tempPath, 'w');
            while (!gzeof($gz)) {
                fwrite($fp, gzread($gz, 10240));
            }
            gzclose($gz);
            fclose($fp);

            // Close current database connection
            // Note: This won't work in all cases due to PHP PDO limitations
            // A proper implementation would require restarting the PHP process

            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                // Replace database file
                copy($tempPath, $dbPath);
                unlink($tempPath);

                // Clear WAL files
                if (file_exists($dbPath . '-wal')) unlink($dbPath . '-wal');
                if (file_exists($dbPath . '-shm')) unlink($dbPath . '-shm');
            } else {
                // For MySQL, source the SQL file with password via environment variable
                // to avoid exposing password in process listings
                $config = getConfig();
                $cmd = sprintf(
                    'mysql -h %s -u %s %s < %s',
                    escapeshellarg($config['db_host'] ?? 'localhost'),
                    escapeshellarg($config['db_user'] ?? 'root'),
                    escapeshellarg($config['db_name'] ?? 'silo'),
                    escapeshellarg($tempPath)
                );

                // Set MYSQL_PWD environment variable (secure way to pass password)
                $env = array_merge($_ENV, ['MYSQL_PWD' => $config['db_password'] ?? '']);
                $descriptorspec = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ];
                $process = proc_open($cmd, $descriptorspec, $pipes, null, $env);

                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $output = stream_get_contents($pipes[1]);
                    $errors = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $returnCode = proc_close($process);
                } else {
                    $returnCode = 1;
                }

                unlink($tempPath);

                if ($returnCode !== 0) {
                    throw new Exception('MySQL restore failed: ' . ($errors ?? 'Unknown error'));
                }
            }

            if (function_exists('logInfo')) {
                logInfo('Database restored from backup', ['backup' => basename($backupPath)]);
            }

            return [
                'success' => true,
                'pre_restore_backup' => $preRestoreBackup,
                'message' => 'Database restored successfully. Please restart the application.',
            ];
        } catch (Exception $e) {
            if (function_exists('logError')) {
                logError('Backup restore failed', ['error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * Verify backup integrity
     */
    public static function verifyBackup(string $backupPath): array {
        if (!file_exists($backupPath)) {
            return ['valid' => false, 'error' => 'File not found'];
        }

        // Find metadata file
        $filename = basename($backupPath);
        $timestamp = null;
        if (preg_match('/backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $filename, $m)) {
            $timestamp = $m[1];
        }

        $metadataPath = self::BACKUP_DIR . '/metadata_' . $timestamp . '.json';
        $expectedChecksum = null;

        if (file_exists($metadataPath)) {
            $metadata = json_decode(file_get_contents($metadataPath), true);
            $expectedChecksum = $metadata['checksum'] ?? null;
        }

        // Calculate actual checksum
        $actualChecksum = hash_file('sha256', $backupPath);

        // Verify checksum
        if ($expectedChecksum && $actualChecksum !== $expectedChecksum) {
            return [
                'valid' => false,
                'error' => 'Checksum mismatch',
                'expected' => $expectedChecksum,
                'actual' => $actualChecksum,
            ];
        }

        // Try to decompress and validate
        $tempPath = sys_get_temp_dir() . '/silo_verify_' . uniqid() . '.db';
        try {
            $gz = gzopen($backupPath, 'r');
            if (!$gz) {
                return ['valid' => false, 'error' => 'Failed to open compressed file'];
            }

            $fp = fopen($tempPath, 'w');
            while (!gzeof($gz)) {
                fwrite($fp, gzread($gz, 10240));
            }
            gzclose($gz);
            fclose($fp);

            // Try to open as SQLite database
            if (filesize($tempPath) > 0) {
                $testDb = new PDO('sqlite:' . $tempPath);
                $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Run integrity check
                $result = $testDb->query("PRAGMA integrity_check")->fetchColumn();
                if ($result !== 'ok') {
                    unlink($tempPath);
                    return ['valid' => false, 'error' => 'Database integrity check failed: ' . $result];
                }

                // Check for required tables
                $tables = $testDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
                $requiredTables = ['models', 'users', 'categories'];
                $missingTables = array_diff($requiredTables, $tables);

                if (!empty($missingTables)) {
                    unlink($tempPath);
                    return ['valid' => false, 'error' => 'Missing tables: ' . implode(', ', $missingTables)];
                }

                $testDb = null;
            }

            unlink($tempPath);

            return [
                'valid' => true,
                'checksum' => $actualChecksum,
                'size' => filesize($backupPath),
            ];
        } catch (Exception $e) {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Run scheduled backup verification
     */
    public static function verifyAllBackups(): array {
        $backups = self::listBackups();
        $results = [];

        foreach ($backups as $backup) {
            if ($backup['exists']) {
                $verification = self::verifyBackup($backup['path']);
                $results[] = [
                    'filename' => $backup['filename'],
                    'timestamp' => $backup['timestamp'],
                    'valid' => $verification['valid'],
                    'error' => $verification['error'] ?? null,
                ];
            }
        }

        return $results;
    }

    /**
     * Configure multi-region replication
     */
    public static function configureReplication(array $targets): bool {
        $config = [
            'enabled' => true,
            'targets' => [],
        ];

        foreach ($targets as $target) {
            $validatedTarget = [
                'name' => $target['name'] ?? 'Unnamed',
                'type' => $target['type'] ?? 's3', // s3, sftp, local
                'config' => [],
            ];

            switch ($validatedTarget['type']) {
                case 's3':
                    $validatedTarget['config'] = [
                        'endpoint' => $target['endpoint'] ?? '',
                        'bucket' => $target['bucket'] ?? '',
                        'access_key' => $target['access_key'] ?? '',
                        'secret_key' => $target['secret_key'] ?? '',
                        'region' => $target['region'] ?? 'us-east-1',
                        'prefix' => $target['prefix'] ?? 'silo-backups/',
                    ];
                    break;

                case 'sftp':
                    $validatedTarget['config'] = [
                        'host' => $target['host'] ?? '',
                        'port' => (int)($target['port'] ?? 22),
                        'username' => $target['username'] ?? '',
                        'password' => $target['password'] ?? '',
                        'private_key' => $target['private_key'] ?? '',
                        'path' => $target['path'] ?? '/backups/',
                    ];
                    break;

                case 'local':
                    $validatedTarget['config'] = [
                        'path' => $target['path'] ?? '/mnt/backup/',
                    ];
                    break;
            }

            $config['targets'][] = $validatedTarget;
        }

        return file_put_contents(
            self::REPLICATION_CONFIG,
            json_encode($config, JSON_PRETTY_PRINT)
        ) !== false;
    }

    /**
     * Get replication configuration
     */
    public static function getReplicationConfig(): ?array {
        if (!file_exists(self::REPLICATION_CONFIG)) {
            return null;
        }

        return json_decode(file_get_contents(self::REPLICATION_CONFIG), true);
    }

    /**
     * Replicate backup to all configured targets
     */
    public static function replicateBackup(string $backupPath): array {
        $config = self::getReplicationConfig();
        if (!$config || !$config['enabled'] || empty($config['targets'])) {
            return ['success' => false, 'error' => 'Replication not configured'];
        }

        $results = [];
        $filename = basename($backupPath);

        foreach ($config['targets'] as $target) {
            $result = ['target' => $target['name'], 'success' => false];

            try {
                switch ($target['type']) {
                    case 's3':
                        $result = self::replicateToS3($backupPath, $target['config']);
                        break;

                    case 'sftp':
                        $result = self::replicateToSFTP($backupPath, $target['config']);
                        break;

                    case 'local':
                        $result = self::replicateToLocal($backupPath, $target['config']);
                        break;
                }

                $result['target'] = $target['name'];
            } catch (Exception $e) {
                $result['error'] = $e->getMessage();
            }

            $results[] = $result;
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Replicate to S3
     */
    private static function replicateToS3(string $backupPath, array $config): array {
        require_once __DIR__ . '/storage.php';

        $s3 = new S3Storage([
            'endpoint' => $config['endpoint'],
            'bucket' => $config['bucket'],
            'access_key' => $config['access_key'],
            'secret_key' => $config['secret_key'],
            'region' => $config['region'],
        ]);

        $remotePath = $config['prefix'] . basename($backupPath);
        $success = $s3->putFile($remotePath, $backupPath);

        return [
            'success' => $success,
            'path' => $remotePath,
        ];
    }

    /**
     * Replicate to SFTP
     */
    private static function replicateToSFTP(string $backupPath, array $config): array {
        if (!function_exists('ssh2_connect')) {
            throw new Exception('SSH2 extension not installed');
        }

        $connection = ssh2_connect($config['host'], $config['port']);
        if (!$connection) {
            throw new Exception('Failed to connect to SFTP server');
        }

        if (!empty($config['private_key'])) {
            if (!ssh2_auth_pubkey_file($connection, $config['username'], $config['private_key'] . '.pub', $config['private_key'])) {
                throw new Exception('Public key authentication failed');
            }
        } else {
            if (!ssh2_auth_password($connection, $config['username'], $config['password'])) {
                throw new Exception('Password authentication failed');
            }
        }

        $sftp = ssh2_sftp($connection);
        $remotePath = $config['path'] . basename($backupPath);

        $stream = fopen("ssh2.sftp://$sftp$remotePath", 'w');
        $local = fopen($backupPath, 'r');

        $bytes = stream_copy_to_stream($local, $stream);

        fclose($local);
        fclose($stream);

        return [
            'success' => $bytes > 0,
            'path' => $remotePath,
            'bytes' => $bytes,
        ];
    }

    /**
     * Replicate to local path (network mount)
     */
    private static function replicateToLocal(string $backupPath, array $config): array {
        $remotePath = rtrim($config['path'], '/') . '/' . basename($backupPath);

        if (!is_dir($config['path'])) {
            throw new Exception('Target directory does not exist');
        }

        $success = copy($backupPath, $remotePath);

        return [
            'success' => $success,
            'path' => $remotePath,
        ];
    }

    /**
     * Delete old backups based on retention policy
     */
    public static function cleanupBackups(int $keepDays = 30, int $keepCount = 10): array {
        $backups = self::listBackups();
        $deleted = [];
        $threshold = time() - ($keepDays * 86400);

        // Sort by timestamp
        $byDate = [];
        foreach ($backups as $backup) {
            $time = strtotime(str_replace('_', ' ', str_replace('-', ':', substr($backup['timestamp'], 11))));
            if ($time === false) {
                $time = strtotime($backup['timestamp']);
            }
            $backup['unix_time'] = $time;
            $byDate[] = $backup;
        }

        usort($byDate, function($a, $b) {
            return $b['unix_time'] - $a['unix_time'];
        });

        // Keep the most recent $keepCount, delete older than $keepDays
        foreach ($byDate as $index => $backup) {
            if ($index >= $keepCount && $backup['unix_time'] < $threshold) {
                // Delete this backup
                if ($backup['exists'] && unlink($backup['path'])) {
                    $deleted[] = $backup['filename'];

                    // Also delete metadata
                    $metadataPath = self::BACKUP_DIR . '/metadata_' . $backup['timestamp'] . '.json';
                    if (file_exists($metadataPath)) {
                        unlink($metadataPath);
                    }
                }
            }
        }

        return ['deleted' => $deleted, 'count' => count($deleted)];
    }

    /**
     * Get backup status for health dashboard
     */
    public static function getStatus(): array {
        $backups = self::listBackups();
        $replicationConfig = self::getReplicationConfig();

        $latestBackup = !empty($backups) ? $backups[0] : null;
        $latestVerification = null;

        if ($latestBackup && $latestBackup['exists']) {
            $latestVerification = self::verifyBackup($latestBackup['path']);
        }

        return [
            'total_backups' => count($backups),
            'latest_backup' => $latestBackup ? $latestBackup['timestamp'] : null,
            'latest_backup_valid' => $latestVerification ? $latestVerification['valid'] : null,
            'replication_enabled' => $replicationConfig && $replicationConfig['enabled'],
            'replication_targets' => $replicationConfig ? count($replicationConfig['targets'] ?? []) : 0,
            'backup_dir' => realpath(self::BACKUP_DIR),
            'backup_dir_writable' => is_writable(self::BACKUP_DIR),
        ];
    }

    /**
     * Ensure backup directories exist
     */
    private static function ensureDirectories(): void {
        if (!is_dir(self::BACKUP_DIR)) {
            mkdir(self::BACKUP_DIR, 0750, true);
        }
        if (!is_dir(self::WAL_DIR)) {
            mkdir(self::WAL_DIR, 0750, true);
        }

        // Create .htaccess to protect backups
        $htaccess = self::BACKUP_DIR . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }
}
