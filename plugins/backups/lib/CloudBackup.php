<?php
/**
 * Cloud Backup Destinations
 *
 * Supports uploading backups to:
 * - S3 (and S3-compatible services)
 * - Dropbox
 * - Google Drive
 * - OneDrive
 */

class CloudBackup {
    private static $instances = [];

    /**
     * Get a cloud provider instance
     */
    public static function getProvider($type) {
        if (!isset(self::$instances[$type])) {
            switch ($type) {
                case 's3':
                    self::$instances[$type] = new S3BackupProvider();
                    break;
                case 'dropbox':
                    self::$instances[$type] = new DropboxBackupProvider();
                    break;
                case 'google_drive':
                    self::$instances[$type] = new GoogleDriveBackupProvider();
                    break;
                case 'onedrive':
                    self::$instances[$type] = new OneDriveBackupProvider();
                    break;
                default:
                    return null;
            }
        }
        return self::$instances[$type];
    }

    /**
     * Get all enabled cloud destinations
     */
    public static function getEnabledDestinations() {
        $destinations = [];

        if (getSetting('backup_s3_enabled') === '1') {
            $destinations[] = 's3';
        }
        if (getSetting('backup_dropbox_enabled') === '1') {
            $destinations[] = 'dropbox';
        }
        if (getSetting('backup_gdrive_enabled') === '1') {
            $destinations[] = 'google_drive';
        }
        if (getSetting('backup_onedrive_enabled') === '1') {
            $destinations[] = 'onedrive';
        }

        return $destinations;
    }

    /**
     * Upload backup to all enabled destinations
     */
    public static function uploadToAllDestinations($localPath, $filename) {
        $results = [];
        $destinations = self::getEnabledDestinations();

        foreach ($destinations as $dest) {
            $provider = self::getProvider($dest);
            if ($provider) {
                try {
                    $result = $provider->upload($localPath, $filename);
                    $results[$dest] = ['success' => true, 'result' => $result];
                } catch (Exception $e) {
                    $results[$dest] = ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        return $results;
    }

    /**
     * List backups from a specific destination
     */
    public static function listFromDestination($type) {
        $provider = self::getProvider($type);
        if (!$provider) {
            return [];
        }
        return $provider->listBackups();
    }

    /**
     * Delete backup from destination
     */
    public static function deleteFromDestination($type, $filename) {
        $provider = self::getProvider($type);
        if (!$provider) {
            return false;
        }
        return $provider->delete($filename);
    }

    /**
     * Download backup from destination
     */
    public static function downloadFromDestination($type, $filename, $localPath) {
        $provider = self::getProvider($type);
        if (!$provider) {
            return false;
        }
        return $provider->download($filename, $localPath);
    }
}

/**
 * Base interface for backup providers
 */
interface BackupProviderInterface {
    public function upload($localPath, $filename);
    public function download($filename, $localPath);
    public function delete($filename);
    public function listBackups();
    public function testConnection();
}

/**
 * S3 Backup Provider
 */
class S3BackupProvider implements BackupProviderInterface {
    private $endpoint;
    private $bucket;
    private $accessKey;
    private $secretKey;
    private $region;
    private $folder;

    public function __construct() {
        $this->endpoint = getSetting('backup_s3_endpoint', '');
        $this->bucket = getSetting('backup_s3_bucket', '');
        $this->accessKey = getSetting('backup_s3_access_key', '');
        $this->secretKey = getSetting('backup_s3_secret_key', '');
        $this->region = getSetting('backup_s3_region', 'us-east-1');
        $this->folder = trim(getSetting('backup_s3_folder', 'silo-backups'), '/');
    }

    public function upload($localPath, $filename) {
        $key = $this->folder . '/' . $filename;
        $content = file_get_contents($localPath);
        $contentType = 'application/octet-stream';

        $date = gmdate('D, d M Y H:i:s T');
        $stringToSign = "PUT\n\n$contentType\n$date\n/$this->bucket/$key";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $url = rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . $key;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Date: $date",
                "Content-Type: $contentType",
                "Authorization: AWS $this->accessKey:$signature"
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("S3 upload failed: HTTP $httpCode");
        }

        return ['url' => $url, 'key' => $key];
    }

    public function download($filename, $localPath) {
        $key = $this->folder . '/' . $filename;

        $date = gmdate('D, d M Y H:i:s T');
        $stringToSign = "GET\n\n\n$date\n/$this->bucket/$key";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $url = rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . $key;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Date: $date",
                "Authorization: AWS $this->accessKey:$signature"
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("S3 download failed: HTTP $httpCode");
        }

        return file_put_contents($localPath, $content) !== false;
    }

    public function delete($filename) {
        $key = $this->folder . '/' . $filename;

        $date = gmdate('D, d M Y H:i:s T');
        $stringToSign = "DELETE\n\n\n$date\n/$this->bucket/$key";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $url = rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . $key;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Date: $date",
                "Authorization: AWS $this->accessKey:$signature"
            ]
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    public function listBackups() {
        $date = gmdate('D, d M Y H:i:s T');
        $prefix = $this->folder . '/silo_backup_';
        $stringToSign = "GET\n\n\n$date\n/$this->bucket/";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));

        $url = rtrim($this->endpoint, '/') . '/' . $this->bucket . '/?prefix=' . urlencode($prefix);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Date: $date",
                "Authorization: AWS $this->accessKey:$signature"
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $backups = [];
        if (preg_match_all('/<Key>([^<]+)<\/Key>.*?<Size>(\d+)<\/Size>.*?<LastModified>([^<]+)<\/LastModified>/s', $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $backups[] = [
                    'filename' => basename($match[1]),
                    'size' => (int)$match[2],
                    'created_at' => date('Y-m-d H:i:s', strtotime($match[3])),
                    'destination' => 's3'
                ];
            }
        }

        return $backups;
    }

    public function testConnection() {
        try {
            $this->listBackups();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Dropbox Backup Provider
 */
class DropboxBackupProvider implements BackupProviderInterface {
    private $accessToken;
    private $folder;

    public function __construct() {
        $this->accessToken = getSetting('backup_dropbox_token', '');
        $this->folder = '/' . trim(getSetting('backup_dropbox_folder', 'Silo Backups'), '/');
    }

    public function upload($localPath, $filename) {
        $content = file_get_contents($localPath);
        $path = $this->folder . '/' . $filename;

        $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/octet-stream',
                'Dropbox-API-Arg: ' . json_encode([
                    'path' => $path,
                    'mode' => 'overwrite',
                    'autorename' => false
                ])
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Dropbox upload failed: HTTP $httpCode - $response");
        }

        return json_decode($response, true);
    }

    public function download($filename, $localPath) {
        $path = $this->folder . '/' . $filename;

        $ch = curl_init('https://content.dropboxapi.com/2/files/download');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Dropbox-API-Arg: ' . json_encode(['path' => $path])
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Dropbox download failed: HTTP $httpCode");
        }

        return file_put_contents($localPath, $content) !== false;
    }

    public function delete($filename) {
        $path = $this->folder . '/' . $filename;

        $ch = curl_init('https://api.dropboxapi.com/2/files/delete_v2');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['path' => $path]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public function listBackups() {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['path' => $this->folder]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $backups = [];
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            foreach ($data['entries'] ?? [] as $entry) {
                if ($entry['.tag'] === 'file' && strpos($entry['name'], 'silo_backup_') === 0) {
                    $backups[] = [
                        'filename' => $entry['name'],
                        'size' => $entry['size'],
                        'created_at' => date('Y-m-d H:i:s', strtotime($entry['server_modified'])),
                        'destination' => 'dropbox'
                    ];
                }
            }
        }

        return $backups;
    }

    public function testConnection() {
        $ch = curl_init('https://api.dropboxapi.com/2/users/get_current_account');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'account' => $data['email'] ?? 'Connected'];
        }
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }
}

/**
 * Google Drive Backup Provider
 */
class GoogleDriveBackupProvider implements BackupProviderInterface {
    private $accessToken;
    private $refreshToken;
    private $clientId;
    private $clientSecret;
    private $folderId;

    public function __construct() {
        $this->accessToken = getSetting('backup_gdrive_access_token', '');
        $this->refreshToken = getSetting('backup_gdrive_refresh_token', '');
        $this->clientId = getSetting('backup_gdrive_client_id', '');
        $this->clientSecret = getSetting('backup_gdrive_client_secret', '');
        $this->folderId = getSetting('backup_gdrive_folder_id', '');
    }

    private function refreshAccessToken() {
        if (!$this->refreshToken) {
            throw new Exception('No refresh token available');
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token'
            ]),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            setSetting('backup_gdrive_access_token', $this->accessToken);
            return true;
        }

        throw new Exception('Failed to refresh Google token');
    }

    public function upload($localPath, $filename) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $content = file_get_contents($localPath);
        $boundary = 'silo_backup_boundary';

        $metadata = json_encode([
            'name' => $filename,
            'parents' => $this->folderId ? [$this->folderId] : []
        ]);

        $body = "--$boundary\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--$boundary--";

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: multipart/related; boundary=' . $boundary
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Google Drive upload failed: HTTP $httpCode");
        }

        return json_decode($response, true);
    }

    public function download($filename, $localPath) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        // First find the file ID
        $fileId = $this->findFileId($filename);
        if (!$fileId) {
            throw new Exception('File not found on Google Drive');
        }

        $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId?alt=media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Google Drive download failed: HTTP $httpCode");
        }

        return file_put_contents($localPath, $content) !== false;
    }

    private function findFileId($filename) {
        $query = "name='$filename'";
        if ($this->folderId) {
            $query .= " and '$this->folderId' in parents";
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $query]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        return $data['files'][0]['id'] ?? null;
    }

    public function delete($filename) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $fileId = $this->findFileId($filename);
        if (!$fileId) {
            return false;
        }

        $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 204 || $httpCode === 200;
    }

    public function listBackups() {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $query = "name contains 'silo_backup_'";
        if ($this->folderId) {
            $query .= " and '$this->folderId' in parents";
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,name,size,modifiedTime)'
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $backups = [];
        $data = json_decode($response, true);
        foreach ($data['files'] ?? [] as $file) {
            $backups[] = [
                'filename' => $file['name'],
                'size' => (int)($file['size'] ?? 0),
                'created_at' => date('Y-m-d H:i:s', strtotime($file['modifiedTime'])),
                'destination' => 'google_drive',
                'file_id' => $file['id']
            ];
        }

        return $backups;
    }

    public function testConnection() {
        if (!$this->accessToken && $this->refreshToken) {
            try {
                $this->refreshAccessToken();
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'account' => $data['user']['emailAddress'] ?? 'Connected'];
        }
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }
}

/**
 * OneDrive Backup Provider
 */
class OneDriveBackupProvider implements BackupProviderInterface {
    private $accessToken;
    private $refreshToken;
    private $clientId;
    private $clientSecret;
    private $folder;

    public function __construct() {
        $this->accessToken = getSetting('backup_onedrive_access_token', '');
        $this->refreshToken = getSetting('backup_onedrive_refresh_token', '');
        $this->clientId = getSetting('backup_onedrive_client_id', '');
        $this->clientSecret = getSetting('backup_onedrive_client_secret', '');
        $this->folder = trim(getSetting('backup_onedrive_folder', 'Silo Backups'), '/');
    }

    private function refreshAccessToken() {
        if (!$this->refreshToken) {
            throw new Exception('No refresh token available');
        }

        $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => 'Files.ReadWrite.All offline_access'
            ]),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            setSetting('backup_onedrive_access_token', $this->accessToken);
            if (isset($data['refresh_token'])) {
                $this->refreshToken = $data['refresh_token'];
                setSetting('backup_onedrive_refresh_token', $this->refreshToken);
            }
            return true;
        }

        throw new Exception('Failed to refresh OneDrive token');
    }

    public function upload($localPath, $filename) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $content = file_get_contents($localPath);
        $path = $this->folder . '/' . $filename;

        // Use simple upload for files under 4MB, otherwise use upload session
        if (strlen($content) < 4 * 1024 * 1024) {
            $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode($path) . ':/content';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/octet-stream'
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception("OneDrive upload failed: HTTP $httpCode");
            }

            return json_decode($response, true);
        }

        // Large file upload session
        return $this->uploadLargeFile($localPath, $path);
    }

    private function uploadLargeFile($localPath, $remotePath) {
        // Create upload session
        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode($remotePath) . ':/createUploadSession';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $uploadUrl = $data['uploadUrl'] ?? null;

        if (!$uploadUrl) {
            throw new Exception('Failed to create OneDrive upload session');
        }

        // Upload in chunks
        $fileSize = filesize($localPath);
        $chunkSize = 10 * 1024 * 1024; // 10MB chunks
        $handle = fopen($localPath, 'rb');
        $offset = 0;

        while ($offset < $fileSize) {
            $chunk = fread($handle, $chunkSize);
            $chunkLen = strlen($chunk);
            $end = $offset + $chunkLen - 1;

            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $chunk,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Length: ' . $chunkLen,
                    'Content-Range: bytes ' . $offset . '-' . $end . '/' . $fileSize
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 400) {
                fclose($handle);
                throw new Exception("OneDrive chunk upload failed: HTTP $httpCode");
            }

            $offset += $chunkLen;
        }

        fclose($handle);
        return json_decode($response, true);
    }

    public function download($filename, $localPath) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $path = $this->folder . '/' . $filename;
        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode($path) . ':/content';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("OneDrive download failed: HTTP $httpCode");
        }

        return file_put_contents($localPath, $content) !== false;
    }

    public function delete($filename) {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $path = $this->folder . '/' . $filename;
        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode($path);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 204 || $httpCode === 200;
    }

    public function listBackups() {
        if (!$this->accessToken && $this->refreshToken) {
            $this->refreshAccessToken();
        }

        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode($this->folder) . ':/children';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $backups = [];
        $data = json_decode($response, true);
        foreach ($data['value'] ?? [] as $item) {
            if (strpos($item['name'], 'silo_backup_') === 0) {
                $backups[] = [
                    'filename' => $item['name'],
                    'size' => $item['size'] ?? 0,
                    'created_at' => date('Y-m-d H:i:s', strtotime($item['lastModifiedDateTime'])),
                    'destination' => 'onedrive'
                ];
            }
        }

        return $backups;
    }

    public function testConnection() {
        if (!$this->accessToken && $this->refreshToken) {
            try {
                $this->refreshAccessToken();
            } catch (Exception $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        $ch = curl_init('https://graph.microsoft.com/v1.0/me');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return ['success' => true, 'account' => $data['userPrincipalName'] ?? 'Connected'];
        }
        return ['success' => false, 'error' => "HTTP $httpCode"];
    }
}
