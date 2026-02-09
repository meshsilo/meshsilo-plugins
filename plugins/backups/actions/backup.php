<?php
/**
 * Backup Actions
 */

require_once __DIR__ . '/../../../includes/config.php';

if (!class_exists('CloudBackup')) {
    require_once __DIR__ . '/../lib/CloudBackup.php';
}

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();

if (!$user['is_admin']) {
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// CSRF validation for state-changing actions
$stateChangingActions = ['create', 'delete', 'restore', 'save_schedule', 'cloud_upload', 'cloud_delete', 'cloud_test', 'save_cloud_settings'];
if (in_array($action, $stateChangingActions)) {
    if (!Csrf::check()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request token']);
        exit;
    }
}

switch ($action) {
    case 'create':
        createBackup();
        break;
    case 'list':
        listBackups();
        break;
    case 'download':
        downloadBackup();
        break;
    case 'delete':
        deleteBackup();
        break;
    case 'restore':
        restoreBackup();
        break;
    case 'save_schedule':
        saveBackupSchedule();
        break;
    case 'get_schedule':
        getBackupSchedule();
        break;
    // Cloud backup actions
    case 'cloud_upload':
        cloudUploadBackup();
        break;
    case 'cloud_list':
        cloudListBackups();
        break;
    case 'cloud_download':
        cloudDownloadBackup();
        break;
    case 'cloud_delete':
        cloudDeleteBackup();
        break;
    case 'cloud_test':
        cloudTestConnection();
        break;
    case 'save_cloud_settings':
        saveCloudSettings();
        break;
    case 'get_cloud_settings':
        getCloudSettings();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function createBackup() {
    $backupDir = __DIR__ . '/../../../storage/db/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = "silo_backup_$timestamp.db";
    $backupPath = $backupDir . '/' . $filename;
    $sourcePath = DB_PATH;

    if (!file_exists($sourcePath)) {
        echo json_encode(['success' => false, 'error' => 'Database not found']);
        return;
    }

    if (!copy($sourcePath, $backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create backup']);
        return;
    }

    // Compress if possible
    if (function_exists('gzopen')) {
        $gzPath = $backupPath . '.gz';
        $fp = fopen($backupPath, 'rb');
        $gz = gzopen($gzPath, 'wb9');
        while (!feof($fp)) {
            gzwrite($gz, fread($fp, 1024 * 1024));
        }
        fclose($fp);
        gzclose($gz);
        unlink($backupPath);
        $filename .= '.gz';
        $backupPath = $gzPath;
    }

    // Clean up old backups (keep last 10)
    cleanOldBackups($backupDir, 10);

    logActivity('backup_created', 'system', null, $filename);

    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'size' => filesize($backupPath),
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

function listBackups() {
    $backupDir = __DIR__ . '/../../../storage/db/backups';
    $backups = [];

    if (is_dir($backupDir)) {
        $files = scandir($backupDir, SCANDIR_SORT_DESCENDING);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (strpos($file, 'silo_backup_') !== 0) continue;

            $path = $backupDir . '/' . $file;
            $backups[] = [
                'filename' => $file,
                'size' => filesize($path),
                'created_at' => date('Y-m-d H:i:s', filemtime($path))
            ];
        }
    }

    echo json_encode(['success' => true, 'backups' => $backups]);
}

function downloadBackup() {
    $filename = basename($_GET['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../../../storage/db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($backupPath));
    readfile($backupPath);
    exit;
}

function deleteBackup() {
    $filename = basename($_POST['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../../../storage/db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    if (!unlink($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Failed to delete backup']);
        return;
    }

    logActivity('backup_deleted', 'system', null, $filename);

    echo json_encode(['success' => true]);
}

function restoreBackup() {
    $filename = basename($_POST['filename'] ?? '');
    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../../../storage/db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    // Create backup of current database first
    $currentBackup = DB_PATH . '.pre-restore.' . date('Y-m-d_H-i-s');
    if (!copy(DB_PATH, $currentBackup)) {
        echo json_encode(['success' => false, 'error' => 'Failed to backup current database']);
        return;
    }

    // Restore
    $restorePath = $backupPath;

    // Decompress if needed
    if (substr($backupPath, -3) === '.gz') {
        $tempPath = sys_get_temp_dir() . '/silo_restore_' . uniqid() . '.db';
        $gz = gzopen($backupPath, 'rb');
        $fp = fopen($tempPath, 'wb');
        while (!gzeof($gz)) {
            fwrite($fp, gzread($gz, 1024 * 1024));
        }
        fclose($fp);
        gzclose($gz);
        $restorePath = $tempPath;
    }

    if (!copy($restorePath, DB_PATH)) {
        // Restore failed, try to restore the pre-restore backup
        copy($currentBackup, DB_PATH);
        echo json_encode(['success' => false, 'error' => 'Failed to restore backup']);
        return;
    }

    // Clean up temp file
    if (isset($tempPath) && file_exists($tempPath)) {
        unlink($tempPath);
    }

    logActivity('backup_restored', 'system', null, $filename);

    echo json_encode(['success' => true]);
}

function saveBackupSchedule() {
    setSetting('backup_enabled', isset($_POST['backup_enabled']) ? '1' : '0');
    setSetting('backup_frequency', $_POST['backup_frequency'] ?? 'daily');
    setSetting('backup_retention', (int)($_POST['backup_retention'] ?? 10));
    setSetting('backup_time', $_POST['backup_time'] ?? '03:00');

    echo json_encode(['success' => true]);
}

function getBackupSchedule() {
    echo json_encode([
        'success' => true,
        'schedule' => [
            'enabled' => getSetting('backup_enabled', '0') === '1',
            'frequency' => getSetting('backup_frequency', 'daily'),
            'retention' => (int)getSetting('backup_retention', 10),
            'time' => getSetting('backup_time', '03:00')
        ]
    ]);
}

function cleanOldBackups($dir, $keep) {
    $files = glob($dir . '/silo_backup_*');
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $toDelete = array_slice($files, $keep);
    foreach ($toDelete as $file) {
        unlink($file);
    }
}

/**
 * Run scheduled backup (called by cron)
 */
function runScheduledBackup() {
    if (getSetting('backup_enabled', '0') !== '1') {
        return;
    }

    $lastBackup = getSetting('last_scheduled_backup', '');
    $frequency = getSetting('backup_frequency', 'daily');

    $shouldBackup = false;
    $now = time();

    if (empty($lastBackup)) {
        $shouldBackup = true;
    } else {
        $lastTime = strtotime($lastBackup);
        switch ($frequency) {
            case 'hourly':
                $shouldBackup = ($now - $lastTime) >= 3600;
                break;
            case 'daily':
                $shouldBackup = ($now - $lastTime) >= 86400;
                break;
            case 'weekly':
                $shouldBackup = ($now - $lastTime) >= 604800;
                break;
        }
    }

    if ($shouldBackup) {
        ob_start();
        createBackup();
        $backupResult = ob_get_clean();

        // Upload to cloud destinations
        $backupData = json_decode($backupResult, true);
        if ($backupData && $backupData['success'] && !empty($backupData['filename'])) {
            $backupPath = __DIR__ . '/../../../storage/db/backups/' . $backupData['filename'];
            if (file_exists($backupPath)) {
                CloudBackup::uploadToAllDestinations($backupPath, $backupData['filename']);
            }
        }

        setSetting('last_scheduled_backup', date('Y-m-d H:i:s'));
    }
}

// =====================
// Cloud Backup Functions
// =====================

function cloudUploadBackup() {
    $filename = basename($_POST['filename'] ?? '');
    $destination = $_POST['destination'] ?? '';

    if (empty($filename) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']);
        return;
    }

    $backupPath = __DIR__ . '/../../../storage/db/backups/' . $filename;
    if (!file_exists($backupPath)) {
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        return;
    }

    try {
        if ($destination) {
            // Upload to specific destination
            $provider = CloudBackup::getProvider($destination);
            if (!$provider) {
                echo json_encode(['success' => false, 'error' => 'Invalid destination']);
                return;
            }
            $result = $provider->upload($backupPath, $filename);
            logActivity('cloud_backup_uploaded', 'system', null, "$filename to $destination");
            echo json_encode(['success' => true, 'destination' => $destination, 'result' => $result]);
        } else {
            // Upload to all enabled destinations
            $results = CloudBackup::uploadToAllDestinations($backupPath, $filename);
            logActivity('cloud_backup_uploaded', 'system', null, "$filename to all destinations");
            echo json_encode(['success' => true, 'results' => $results]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function cloudListBackups() {
    $destination = $_GET['destination'] ?? '';

    try {
        if ($destination) {
            $backups = CloudBackup::listFromDestination($destination);
            echo json_encode(['success' => true, 'backups' => $backups]);
        } else {
            // List from all enabled destinations
            $allBackups = [];
            foreach (CloudBackup::getEnabledDestinations() as $dest) {
                $backups = CloudBackup::listFromDestination($dest);
                $allBackups = array_merge($allBackups, $backups);
            }
            // Sort by date descending
            usort($allBackups, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            echo json_encode(['success' => true, 'backups' => $allBackups]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function cloudDownloadBackup() {
    $filename = basename($_GET['filename'] ?? '');
    $destination = $_GET['destination'] ?? '';

    if (empty($filename) || empty($destination) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        return;
    }

    $localPath = __DIR__ . '/../../../storage/db/backups/' . $filename;

    try {
        $success = CloudBackup::downloadFromDestination($destination, $filename, $localPath);
        if ($success) {
            logActivity('cloud_backup_downloaded', 'system', null, "$filename from $destination");
            echo json_encode(['success' => true, 'filename' => $filename]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Download failed']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function cloudDeleteBackup() {
    $filename = basename($_POST['filename'] ?? '');
    $destination = $_POST['destination'] ?? '';

    if (empty($filename) || empty($destination) || strpos($filename, 'silo_backup_') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        return;
    }

    try {
        $success = CloudBackup::deleteFromDestination($destination, $filename);
        if ($success) {
            logActivity('cloud_backup_deleted', 'system', null, "$filename from $destination");
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Delete failed']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function cloudTestConnection() {
    $destination = $_POST['destination'] ?? '';

    if (empty($destination)) {
        echo json_encode(['success' => false, 'error' => 'No destination specified']);
        return;
    }

    $provider = CloudBackup::getProvider($destination);
    if (!$provider) {
        echo json_encode(['success' => false, 'error' => 'Invalid destination']);
        return;
    }

    $result = $provider->testConnection();
    echo json_encode($result);
}

function saveCloudSettings() {
    $destination = $_POST['destination'] ?? '';
    $settings = $_POST['settings'] ?? [];

    if (empty($destination)) {
        echo json_encode(['success' => false, 'error' => 'No destination specified']);
        return;
    }

    $prefix = 'backup_' . $destination . '_';

    foreach ($settings as $key => $value) {
        // Sanitize key
        $key = preg_replace('/[^a-z_]/', '', $key);
        setSetting($prefix . $key, $value);
    }

    logActivity('cloud_settings_updated', 'system', null, $destination);
    echo json_encode(['success' => true]);
}

function getCloudSettings() {
    $destinations = [
        's3' => [
            'enabled' => getSetting('backup_s3_enabled', '0') === '1',
            'endpoint' => getSetting('backup_s3_endpoint', ''),
            'bucket' => getSetting('backup_s3_bucket', ''),
            'access_key' => getSetting('backup_s3_access_key', '') ? '--------' : '',
            'secret_key' => getSetting('backup_s3_secret_key', '') ? '--------' : '',
            'region' => getSetting('backup_s3_region', 'us-east-1'),
            'folder' => getSetting('backup_s3_folder', 'silo-backups')
        ],
        'dropbox' => [
            'enabled' => getSetting('backup_dropbox_enabled', '0') === '1',
            'token' => getSetting('backup_dropbox_token', '') ? '--------' : '',
            'folder' => getSetting('backup_dropbox_folder', 'Silo Backups')
        ],
        'google_drive' => [
            'enabled' => getSetting('backup_gdrive_enabled', '0') === '1',
            'client_id' => getSetting('backup_gdrive_client_id', ''),
            'client_secret' => getSetting('backup_gdrive_client_secret', '') ? '--------' : '',
            'folder_id' => getSetting('backup_gdrive_folder_id', ''),
            'has_token' => !empty(getSetting('backup_gdrive_refresh_token', ''))
        ],
        'onedrive' => [
            'enabled' => getSetting('backup_onedrive_enabled', '0') === '1',
            'client_id' => getSetting('backup_onedrive_client_id', ''),
            'client_secret' => getSetting('backup_onedrive_client_secret', '') ? '--------' : '',
            'folder' => getSetting('backup_onedrive_folder', 'Silo Backups'),
            'has_token' => !empty(getSetting('backup_onedrive_refresh_token', ''))
        ]
    ];

    echo json_encode(['success' => true, 'destinations' => $destinations]);
}
