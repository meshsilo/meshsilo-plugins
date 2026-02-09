<?php
/**
 * OAuth2 Provider
 *
 * Allows Silo to act as an OAuth2 authorization server for third-party applications
 */

class OAuth2Provider {
    private static $db;

    private static function init() {
        if (!self::$db) {
            self::$db = getDB();
            self::ensureTables();
        }
    }

    /**
     * Ensure OAuth tables exist
     */
    private static function ensureTables() {
        $isMySQL = self::$db->getType() === 'mysql';

        if ($isMySQL) {
            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_clients (
                    id VARCHAR(64) PRIMARY KEY,
                    secret_hash VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    redirect_uris TEXT NOT NULL,
                    allowed_scopes VARCHAR(255) DEFAULT "profile",
                    is_confidential TINYINT DEFAULT 1,
                    is_active TINYINT DEFAULT 1,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
                    code VARCHAR(128) PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL,
                    user_id INT NOT NULL,
                    redirect_uri TEXT NOT NULL,
                    scopes VARCHAR(255),
                    code_challenge VARCHAR(128),
                    code_challenge_method VARCHAR(16),
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_access_tokens (
                    token_hash VARCHAR(128) PRIMARY KEY,
                    client_id VARCHAR(64) NOT NULL,
                    user_id INT NOT NULL,
                    scopes VARCHAR(255),
                    expires_at DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
                    token_hash VARCHAR(128) PRIMARY KEY,
                    access_token_hash VARCHAR(128) NOT NULL,
                    client_id VARCHAR(64) NOT NULL,
                    user_id INT NOT NULL,
                    expires_at DATETIME,
                    revoked TINYINT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_user_id (user_id),
                    INDEX idx_access_token (access_token_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');
        } else {
            // SQLite
            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_clients (
                    id TEXT PRIMARY KEY,
                    secret_hash TEXT NOT NULL,
                    name TEXT NOT NULL,
                    description TEXT,
                    redirect_uris TEXT NOT NULL,
                    allowed_scopes TEXT DEFAULT "profile",
                    is_confidential INTEGER DEFAULT 1,
                    is_active INTEGER DEFAULT 1,
                    created_by INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
                    code TEXT PRIMARY KEY,
                    client_id TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    redirect_uri TEXT NOT NULL,
                    scopes TEXT,
                    code_challenge TEXT,
                    code_challenge_method TEXT,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES oauth_clients(id),
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_access_tokens (
                    token_hash TEXT PRIMARY KEY,
                    client_id TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    scopes TEXT,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES oauth_clients(id),
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            ');

            self::$db->exec('
                CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
                    token_hash TEXT PRIMARY KEY,
                    access_token_hash TEXT NOT NULL,
                    client_id TEXT NOT NULL,
                    user_id INTEGER NOT NULL,
                    expires_at DATETIME,
                    revoked INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES oauth_clients(id),
                    FOREIGN KEY (user_id) REFERENCES users(id)
                )
            ');
        }
    }

    /**
     * Register a new OAuth client
     */
    public static function createClient($name, $redirectUris, $description = '', $isConfidential = true, $createdBy = null) {
        self::init();

        $clientId = bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        $secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);

        $redirectUrisJson = json_encode(is_array($redirectUris) ? $redirectUris : [$redirectUris]);

        $stmt = self::$db->prepare('
            INSERT INTO oauth_clients (id, secret_hash, name, description, redirect_uris, is_confidential, created_by)
            VALUES (:id, :secret, :name, :desc, :uris, :conf, :created_by)
        ');
        $stmt->bindValue(':id', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':secret', $secretHash, PDO::PARAM_STR);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':desc', $description, PDO::PARAM_STR);
        $stmt->bindValue(':uris', $redirectUrisJson, PDO::PARAM_STR);
        $stmt->bindValue(':conf', $isConfidential ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':created_by', $createdBy, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret, // Only returned once on creation
            'name' => $name
        ];
    }

    /**
     * Get client by ID
     */
    public static function getClient($clientId) {
        self::init();

        $stmt = self::$db->prepare('SELECT * FROM oauth_clients WHERE id = :id AND is_active = 1');
        $stmt->bindValue(':id', $clientId, PDO::PARAM_STR);
        $result = $stmt->execute();
        $client = $result->fetchArray(PDO::FETCH_ASSOC);

        if ($client) {
            $client['redirect_uris'] = json_decode($client['redirect_uris'], true);
        }

        return $client;
    }

    /**
     * Get all clients
     */
    public static function getClients() {
        self::init();

        $clients = [];
        $result = self::$db->query('SELECT * FROM oauth_clients ORDER BY created_at DESC');
        while ($row = $result->fetchArray(PDO::FETCH_ASSOC)) {
            $row['redirect_uris'] = json_decode($row['redirect_uris'], true);
            $clients[] = $row;
        }
        return $clients;
    }

    /**
     * Update client
     */
    public static function updateClient($clientId, $data) {
        self::init();

        $fields = [];
        $values = [':id' => $clientId];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $values[':name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $fields[] = 'description = :desc';
            $values[':desc'] = $data['description'];
        }
        if (isset($data['redirect_uris'])) {
            $fields[] = 'redirect_uris = :uris';
            $values[':uris'] = json_encode($data['redirect_uris']);
        }
        if (isset($data['allowed_scopes'])) {
            $fields[] = 'allowed_scopes = :scopes';
            $values[':scopes'] = $data['allowed_scopes'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = :active';
            $values[':active'] = $data['is_active'] ? 1 : 0;
        }

        if (empty($fields)) return false;

        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        $sql = 'UPDATE oauth_clients SET ' . implode(', ', $fields) . ' WHERE id = :id';

        $stmt = self::$db->prepare($sql);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        return $stmt->execute();
    }

    /**
     * Delete client
     */
    public static function deleteClient($clientId) {
        self::init();

        // Revoke all tokens using parameterized queries to prevent SQL injection
        $stmt = self::$db->prepare('DELETE FROM oauth_access_tokens WHERE client_id = :client_id');
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $stmt = self::$db->prepare('DELETE FROM oauth_refresh_tokens WHERE client_id = :client_id');
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $stmt = self::$db->prepare('DELETE FROM oauth_authorization_codes WHERE client_id = :client_id');
        $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        $stmt = self::$db->prepare('DELETE FROM oauth_clients WHERE id = :id');
        $stmt->bindValue(':id', $clientId, PDO::PARAM_STR);
        return $stmt->execute();
    }

    /**
     * Regenerate client secret
     */
    public static function regenerateSecret($clientId) {
        self::init();

        $newSecret = bin2hex(random_bytes(32));
        $secretHash = password_hash($newSecret, PASSWORD_DEFAULT);

        $stmt = self::$db->prepare('UPDATE oauth_clients SET secret_hash = :secret, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->bindValue(':secret', $secretHash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $clientId, PDO::PARAM_STR);
        $stmt->execute();

        return $newSecret;
    }

    /**
     * Verify client credentials
     */
    public static function verifyClient($clientId, $clientSecret) {
        self::init();

        $client = self::getClient($clientId);
        if (!$client) return false;

        $stmt = self::$db->prepare('SELECT secret_hash FROM oauth_clients WHERE id = :id');
        $stmt->bindValue(':id', $clientId, PDO::PARAM_STR);
        $result = $stmt->execute();
        $row = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$row) return false;

        return password_verify($clientSecret, $row['secret_hash']);
    }

    /**
     * Validate redirect URI
     */
    public static function validateRedirectUri($clientId, $redirectUri) {
        $client = self::getClient($clientId);
        if (!$client) return false;

        foreach ($client['redirect_uris'] as $allowedUri) {
            if ($redirectUri === $allowedUri) return true;
            // Allow localhost variations for development
            if (strpos($allowedUri, 'localhost') !== false || strpos($allowedUri, '127.0.0.1') !== false) {
                $allowedParsed = parse_url($allowedUri);
                $requestedParsed = parse_url($redirectUri);
                if ($allowedParsed['host'] === $requestedParsed['host'] &&
                    ($allowedParsed['path'] ?? '/') === ($requestedParsed['path'] ?? '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create authorization code
     */
    public static function createAuthorizationCode($clientId, $userId, $redirectUri, $scopes = [], $codeChallenge = null, $codeChallengeMethod = null) {
        self::init();

        $code = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $stmt = self::$db->prepare('
            INSERT INTO oauth_authorization_codes
            (code, client_id, user_id, redirect_uri, scopes, code_challenge, code_challenge_method, expires_at)
            VALUES (:code, :client, :user, :uri, :scopes, :challenge, :method, :expires)
        ');
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':uri', $redirectUri, PDO::PARAM_STR);
        $stmt->bindValue(':scopes', implode(' ', $scopes), PDO::PARAM_STR);
        $stmt->bindValue(':challenge', $codeChallenge, PDO::PARAM_STR);
        $stmt->bindValue(':method', $codeChallengeMethod, PDO::PARAM_STR);
        $stmt->bindValue(':expires', $expiresAt, PDO::PARAM_STR);
        $stmt->execute();

        return $code;
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchangeCode($code, $clientId, $redirectUri, $codeVerifier = null) {
        self::init();

        // Get the authorization code
        $now = date('Y-m-d H:i:s');
        $stmt = self::$db->prepare('
            SELECT * FROM oauth_authorization_codes
            WHERE code = :code AND client_id = :client AND redirect_uri = :uri AND expires_at > :now
        ');
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':uri', $redirectUri, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $result = $stmt->execute();
        $authCode = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$authCode) {
            return ['error' => 'invalid_grant', 'error_description' => 'Invalid or expired authorization code'];
        }

        // Verify PKCE if used
        if ($authCode['code_challenge']) {
            if (!$codeVerifier) {
                return ['error' => 'invalid_grant', 'error_description' => 'Code verifier required'];
            }

            $expectedChallenge = $authCode['code_challenge_method'] === 'S256'
                ? rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=')
                : $codeVerifier;

            if ($expectedChallenge !== $authCode['code_challenge']) {
                return ['error' => 'invalid_grant', 'error_description' => 'Invalid code verifier'];
            }
        }

        // Delete the authorization code (single use)
        $stmt = self::$db->prepare('DELETE FROM oauth_authorization_codes WHERE code = :code');
        $stmt->bindValue(':code', $code, PDO::PARAM_STR);
        $stmt->execute();

        // Create access token
        $accessToken = bin2hex(random_bytes(32));
        $accessTokenHash = hash('sha256', $accessToken);
        $accessExpiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        $stmt = self::$db->prepare('
            INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at)
            VALUES (:hash, :client, :user, :scopes, :expires)
        ');
        $stmt->bindValue(':hash', $accessTokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':user', $authCode['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':scopes', $authCode['scopes'], PDO::PARAM_STR);
        $stmt->bindValue(':expires', $accessExpiresAt, PDO::PARAM_STR);
        $stmt->execute();

        // Create refresh token
        $refreshToken = bin2hex(random_bytes(32));
        $refreshTokenHash = hash('sha256', $refreshToken);
        $refreshExpiresAt = date('Y-m-d H:i:s', time() + 30 * 24 * 3600); // 30 days

        $stmt = self::$db->prepare('
            INSERT INTO oauth_refresh_tokens (token_hash, access_token_hash, client_id, user_id, expires_at)
            VALUES (:hash, :access_hash, :client, :user, :expires)
        ');
        $stmt->bindValue(':hash', $refreshTokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':access_hash', $accessTokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':user', $authCode['user_id'], PDO::PARAM_INT);
        $stmt->bindValue(':expires', $refreshExpiresAt, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => $refreshToken,
            'scope' => $authCode['scopes']
        ];
    }

    /**
     * Refresh access token
     */
    public static function refreshToken($refreshToken, $clientId) {
        self::init();

        $tokenHash = hash('sha256', $refreshToken);

        $now = date('Y-m-d H:i:s');
        $stmt = self::$db->prepare('
            SELECT * FROM oauth_refresh_tokens
            WHERE token_hash = :hash AND client_id = :client AND revoked = 0
            AND (expires_at IS NULL OR expires_at > :now)
        ');
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $result = $stmt->execute();
        $refresh = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$refresh) {
            return ['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'];
        }

        // Revoke old access token
        $stmt = self::$db->prepare('DELETE FROM oauth_access_tokens WHERE token_hash = :hash');
        $stmt->bindValue(':hash', $refresh['access_token_hash'], PDO::PARAM_STR);
        $stmt->execute();

        // Create new access token
        $newAccessToken = bin2hex(random_bytes(32));
        $newAccessTokenHash = hash('sha256', $newAccessToken);
        $accessExpiresAt = date('Y-m-d H:i:s', time() + 3600);

        $stmt = self::$db->prepare('
            INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, scopes, expires_at)
            VALUES (:hash, :client, :user, :scopes, :expires)
        ');
        $stmt->bindValue(':hash', $newAccessTokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':client', $clientId, PDO::PARAM_STR);
        $stmt->bindValue(':user', $refresh['user_id'], PDO::PARAM_INT);

        // Get scopes from old access token or use default
        $stmt2 = self::$db->prepare('SELECT scopes FROM oauth_access_tokens WHERE token_hash = :hash');
        $stmt2->bindValue(':hash', $refresh['access_token_hash'], PDO::PARAM_STR);
        $scopeResult = $stmt2->execute();
        $scopeRow = $scopeResult->fetchArray(PDO::FETCH_ASSOC);
        $scopes = $scopeRow['scopes'] ?? 'profile';

        $stmt->bindValue(':scopes', $scopes, PDO::PARAM_STR);
        $stmt->bindValue(':expires', $accessExpiresAt, PDO::PARAM_STR);
        $stmt->execute();

        // Update refresh token to point to new access token
        $stmt = self::$db->prepare('UPDATE oauth_refresh_tokens SET access_token_hash = :new_hash WHERE token_hash = :hash');
        $stmt->bindValue(':new_hash', $newAccessTokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'access_token' => $newAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => $scopes
        ];
    }

    /**
     * Validate access token and get user info
     */
    public static function validateAccessToken($accessToken) {
        self::init();

        $tokenHash = hash('sha256', $accessToken);

        $now = date('Y-m-d H:i:s');
        $stmt = self::$db->prepare('
            SELECT t.*, u.username, u.email, u.display_name, u.is_admin
            FROM oauth_access_tokens t
            JOIN users u ON t.user_id = u.id
            WHERE t.token_hash = :hash AND t.expires_at > :now
        ');
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $result = $stmt->execute();
        $token = $result->fetchArray(PDO::FETCH_ASSOC);

        if (!$token) return null;

        return [
            'user_id' => $token['user_id'],
            'client_id' => $token['client_id'],
            'scopes' => explode(' ', $token['scopes']),
            'user' => [
                'id' => $token['user_id'],
                'username' => $token['username'],
                'email' => $token['email'],
                'name' => $token['display_name'],
                'is_admin' => $token['is_admin']
            ]
        ];
    }

    /**
     * Revoke token
     */
    public static function revokeToken($token, $tokenTypeHint = null) {
        self::init();

        $tokenHash = hash('sha256', $token);

        // Try access token
        $stmt = self::$db->prepare('DELETE FROM oauth_access_tokens WHERE token_hash = :hash');
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->execute();

        // Try refresh token
        $stmt = self::$db->prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE token_hash = :hash');
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->execute();

        return true;
    }

    /**
     * Get available scopes
     */
    public static function getScopes() {
        return [
            'profile' => 'Read user profile (username, email, display name)',
            'models:read' => 'Read access to models',
            'models:write' => 'Write access to models',
            'favorites:read' => 'Read user favorites',
            'favorites:write' => 'Modify user favorites',
            'admin' => 'Administrative access (admin users only)'
        ];
    }

    /**
     * Cleanup expired tokens
     */
    public static function cleanup() {
        self::init();

        $deleted = 0;
        $now = date('Y-m-d H:i:s');

        $stmt = self::$db->prepare('DELETE FROM oauth_authorization_codes WHERE expires_at < :now');
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();
        $deleted += self::$db->changes();

        $stmt = self::$db->prepare('DELETE FROM oauth_access_tokens WHERE expires_at < :now');
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();
        $deleted += self::$db->changes();

        $stmt = self::$db->prepare('DELETE FROM oauth_refresh_tokens WHERE expires_at < :now OR revoked = 1');
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();
        $deleted += self::$db->changes();

        return $deleted;
    }

    /**
     * Get statistics
     */
    public static function getStats() {
        self::init();

        $stats = [
            'total_clients' => 0,
            'active_clients' => 0,
            'active_tokens' => 0,
            'tokens_issued_today' => 0
        ];

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $result = self::$db->query('SELECT COUNT(*) as count FROM oauth_clients');
        $stats['total_clients'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        $result = self::$db->query('SELECT COUNT(*) as count FROM oauth_clients WHERE is_active = 1');
        $stats['active_clients'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        $stmt = self::$db->prepare('SELECT COUNT(*) as count FROM oauth_access_tokens WHERE expires_at > :now');
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $result = $stmt->execute();
        $stats['active_tokens'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        $stmt = self::$db->prepare('SELECT COUNT(*) as count FROM oauth_access_tokens WHERE created_at >= :today');
        $stmt->bindValue(':today', $today, PDO::PARAM_STR);
        $result = $stmt->execute();
        $stats['tokens_issued_today'] = $result->fetchArray(PDO::FETCH_ASSOC)['count'];

        return $stats;
    }
}
