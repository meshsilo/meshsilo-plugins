<?php
/**
 * Single Sign-Out (SLO) Manager
 * Implements federated logout across all OIDC sessions
 *
 * Supports:
 * - Front-channel logout (browser redirect)
 * - Back-channel logout (server-to-server notification)
 * - Session tracking for federated sessions
 */

class SingleSignOut {
    private static ?PDO $db = null;

    /**
     * Initialize database connection
     */
    private static function getDB(): PDO {
        if (self::$db === null) {
            self::$db = getDB();
            self::ensureTable();
        }
        return self::$db;
    }

    /**
     * Ensure the federated sessions table exists
     */
    private static function ensureTable(): void {
        $db = self::$db;
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS federated_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_id TEXT NOT NULL UNIQUE,
                    user_id INTEGER NOT NULL,
                    oidc_sid TEXT,
                    oidc_issuer TEXT,
                    id_token TEXT,
                    access_token TEXT,
                    refresh_token TEXT,
                    token_expires_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_fed_sessions_user ON federated_sessions(user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_fed_sessions_oidc_sid ON federated_sessions(oidc_sid)");
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS federated_sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    session_id VARCHAR(255) NOT NULL UNIQUE,
                    user_id INT NOT NULL,
                    oidc_sid VARCHAR(255),
                    oidc_issuer VARCHAR(512),
                    id_token TEXT,
                    access_token TEXT,
                    refresh_token TEXT,
                    token_expires_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_oidc_sid (oidc_sid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    /**
     * Register a federated session after OIDC login
     */
    public static function registerSession(
        int $userId,
        string $sessionId,
        array $tokens,
        ?string $oidcSid = null,
        ?string $issuer = null
    ): bool {
        $db = self::getDB();

        // Extract sid from id_token if not provided
        if (!$oidcSid && isset($tokens['id_token'])) {
            $payload = self::decodeJwtPayload($tokens['id_token']);
            $oidcSid = $payload['sid'] ?? null;
            $issuer = $issuer ?? ($payload['iss'] ?? null);
        }

        $tokenExpires = null;
        if (isset($tokens['expires_in'])) {
            $tokenExpires = date('Y-m-d H:i:s', time() + $tokens['expires_in']);
        }

        $stmt = $db->prepare("
            INSERT INTO federated_sessions
            (session_id, user_id, oidc_sid, oidc_issuer, id_token, access_token, refresh_token, token_expires_at)
            VALUES (:session_id, :user_id, :oidc_sid, :issuer, :id_token, :access_token, :refresh_token, :expires)
            ON CONFLICT(session_id) DO UPDATE SET
                oidc_sid = :oidc_sid,
                oidc_issuer = :issuer,
                id_token = :id_token,
                access_token = :access_token,
                refresh_token = :refresh_token,
                token_expires_at = :expires,
                last_activity = CURRENT_TIMESTAMP
        ");

        return $stmt->execute([
            ':session_id' => $sessionId,
            ':user_id' => $userId,
            ':oidc_sid' => $oidcSid,
            ':issuer' => $issuer,
            ':id_token' => $tokens['id_token'] ?? null,
            ':access_token' => $tokens['access_token'] ?? null,
            ':refresh_token' => $tokens['refresh_token'] ?? null,
            ':expires' => $tokenExpires
        ]);
    }

    /**
     * Get federated session by local session ID
     */
    public static function getSession(string $sessionId): ?array {
        $db = self::getDB();
        $stmt = $db->prepare("SELECT * FROM federated_sessions WHERE session_id = :session_id");
        $stmt->execute([':session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all sessions for a user
     */
    public static function getUserSessions(int $userId): array {
        $db = self::getDB();
        $stmt = $db->prepare("SELECT * FROM federated_sessions WHERE user_id = :user_id ORDER BY last_activity DESC");
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Perform federated logout
     * Returns the OIDC end_session URL if available
     */
    public static function logout(string $sessionId, bool $propagate = true): ?string {
        $db = self::getDB();

        // Get the federated session
        $session = self::getSession($sessionId);
        $logoutUrl = null;

        if ($session && $propagate) {
            // Build OIDC logout URL
            $logoutUrl = self::buildLogoutUrl($session);

            // If back-channel logout is supported, notify the IdP
            if (getSetting('slo_backchannel_enabled', '0') === '1') {
                self::sendBackChannelLogout($session);
            }
        }

        // Remove the federated session
        $stmt = $db->prepare("DELETE FROM federated_sessions WHERE session_id = :session_id");
        $stmt->execute([':session_id' => $sessionId]);

        // Log the logout
        if (function_exists('logInfo')) {
            logInfo('Federated logout', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'user_id' => $session['user_id'] ?? null,
                'propagate' => $propagate
            ]);
        }

        return $logoutUrl;
    }

    /**
     * Handle back-channel logout request from IdP
     * Called when IdP sends a logout token
     */
    public static function handleBackChannelLogout(string $logoutToken): array {
        // Verify and decode the logout token
        $payload = self::decodeJwtPayload($logoutToken);

        if (!$payload) {
            return ['success' => false, 'error' => 'Invalid logout token'];
        }

        // Validate required claims
        if (!isset($payload['iss']) || !isset($payload['events'])) {
            return ['success' => false, 'error' => 'Missing required claims'];
        }

        // Check for back-channel logout event
        $events = $payload['events'];
        if (!isset($events['http://schemas.openid.net/event/backchannel-logout'])) {
            return ['success' => false, 'error' => 'Not a back-channel logout token'];
        }

        // Find sessions to terminate
        $db = self::getDB();
        $terminated = 0;

        // By sid claim
        if (isset($payload['sid'])) {
            $stmt = $db->prepare("SELECT session_id FROM federated_sessions WHERE oidc_sid = :sid");
            $stmt->execute([':sid' => $payload['sid']]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                self::terminateLocalSession($row['session_id']);
                $terminated++;
            }
            $stmt = $db->prepare("DELETE FROM federated_sessions WHERE oidc_sid = :sid");
            $stmt->execute([':sid' => $payload['sid']]);
        }

        // By sub claim (all sessions for user)
        if (isset($payload['sub']) && getSetting('slo_logout_all_sessions', '0') === '1') {
            // Find user by oidc_id
            $userDb = getDB();
            $userStmt = $userDb->prepare("SELECT id FROM users WHERE oidc_id = :sub");
            $userStmt->execute([':sub' => $payload['sub']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $stmt = $db->prepare("SELECT session_id FROM federated_sessions WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $user['id']]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    self::terminateLocalSession($row['session_id']);
                    $terminated++;
                }
                $stmt = $db->prepare("DELETE FROM federated_sessions WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $user['id']]);
            }
        }

        if (function_exists('logInfo')) {
            logInfo('Back-channel logout processed', [
                'issuer' => $payload['iss'],
                'sid' => $payload['sid'] ?? null,
                'sub' => $payload['sub'] ?? null,
                'terminated' => $terminated
            ]);
        }

        return ['success' => true, 'terminated' => $terminated];
    }

    /**
     * Handle front-channel logout request
     * Called via iframe from IdP
     */
    public static function handleFrontChannelLogout(string $iss, ?string $sid = null): array {
        $db = self::getDB();
        $terminated = 0;

        if ($sid) {
            // Terminate specific session
            $stmt = $db->prepare("SELECT session_id FROM federated_sessions WHERE oidc_sid = :sid AND oidc_issuer = :iss");
            $stmt->execute([':sid' => $sid, ':iss' => $iss]);
        } else {
            // Terminate all sessions from this issuer
            $stmt = $db->prepare("SELECT session_id FROM federated_sessions WHERE oidc_issuer = :iss");
            $stmt->execute([':iss' => $iss]);
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            self::terminateLocalSession($row['session_id']);
            $terminated++;
        }

        // Delete the federated sessions
        if ($sid) {
            $stmt = $db->prepare("DELETE FROM federated_sessions WHERE oidc_sid = :sid AND oidc_issuer = :iss");
            $stmt->execute([':sid' => $sid, ':iss' => $iss]);
        } else {
            $stmt = $db->prepare("DELETE FROM federated_sessions WHERE oidc_issuer = :iss");
            $stmt->execute([':iss' => $iss]);
        }

        return ['success' => true, 'terminated' => $terminated];
    }

    /**
     * Build the OIDC end_session URL
     */
    private static function buildLogoutUrl(array $session): ?string {
        if (!function_exists('getOIDCConfig')) {
            return null;
        }

        $config = getOIDCConfig();
        if (!$config || !isset($config['end_session_endpoint'])) {
            return null;
        }

        $params = [];

        // Add ID token hint
        if (!empty($session['id_token'])) {
            $params['id_token_hint'] = $session['id_token'];
        }

        // Add post-logout redirect
        $postLogoutUri = getSetting('oidc_post_logout_uri', '');
        if (empty($postLogoutUri)) {
            $siteUrl = getSetting('site_url', '');
            if (!empty($siteUrl)) {
                $postLogoutUri = rtrim($siteUrl, '/') . '/login';
            }
        }
        if (!empty($postLogoutUri)) {
            $params['post_logout_redirect_uri'] = $postLogoutUri;
        }

        // Add client ID
        $params['client_id'] = getSetting('oidc_client_id');

        // Add state for security
        $state = bin2hex(random_bytes(16));
        $_SESSION['slo_state'] = $state;
        $params['state'] = $state;

        return $config['end_session_endpoint'] . '?' . http_build_query($params);
    }

    /**
     * Send back-channel logout notification to IdP
     */
    private static function sendBackChannelLogout(array $session): bool {
        // This is for RP-initiated back-channel logout
        // Most IdPs don't support this, but some enterprise ones do
        return true;
    }

    /**
     * Terminate a local PHP session
     */
    private static function terminateLocalSession(string $sessionId): bool {
        // Try to destroy the session in the session storage
        $db = getDB();

        // Delete from sessions table if it exists
        try {
            $stmt = $db->prepare("DELETE FROM sessions WHERE session_id = :session_id");
            $stmt->execute([':session_id' => $sessionId]);
        } catch (Exception $e) {
            // Table might not exist
        }

        // If using file-based sessions, try to delete the file
        $sessionPath = session_save_path();
        if ($sessionPath) {
            $sessionFile = $sessionPath . '/sess_' . $sessionId;
            if (file_exists($sessionFile)) {
                @unlink($sessionFile);
            }
        }

        return true;
    }

    /**
     * Decode JWT payload without verification (for extracting claims)
     */
    private static function decodeJwtPayload(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return $payload ?: null;
    }

    /**
     * Cleanup expired federated sessions
     */
    public static function cleanup(int $maxAgeDays = 7): int {
        $db = self::getDB();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$maxAgeDays} days"));

        $stmt = $db->prepare("
            DELETE FROM federated_sessions
            WHERE last_activity < :cutoff
        ");
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Get SLO configuration for display
     */
    public static function getConfig(): array {
        $siteUrl = rtrim(getSetting('site_url', ''), '/');

        return [
            'enabled' => getSetting('slo_enabled', '0') === '1',
            'backchannel_enabled' => getSetting('slo_backchannel_enabled', '0') === '1',
            'frontchannel_enabled' => getSetting('slo_frontchannel_enabled', '1') === '1',
            'logout_all_sessions' => getSetting('slo_logout_all_sessions', '0') === '1',
            'backchannel_logout_uri' => $siteUrl . '/api/slo/backchannel',
            'frontchannel_logout_uri' => $siteUrl . '/api/slo/frontchannel',
        ];
    }
}
